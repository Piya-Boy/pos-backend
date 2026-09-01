<?php

namespace App\Pos\Services;

use App\Pos\Sheets\SheetRepository;
use App\Pos\Support\AppError;
use App\Pos\Support\IdempotencyManager;
use App\Pos\Support\LockManager;
use App\Pos\Support\Totals;
use Illuminate\Support\Collection;

use function App\Pos\Support\money;
use function App\Pos\Support\normalizeText;
use function App\Pos\Support\nowIso;
use function App\Pos\Support\numberOr;
use function App\Pos\Support\uuidPrefixed;

// / Ports cp-pos order flow (Services.js:147-355): submit (lock + idempotency),
// / status, callStaff, session bundle.
class OrderService
{
    public function __construct(
        private SheetRepository $repo,
        private SettingsService $settings,
        private CatalogService $catalog,
        private LockManager $lock,
    ) {}

    public function submit(array $input): array
    {
        $key = normalizeText($input['idempotencyKey'] ?? '', 120);
        if ($key === '') {
            throw new AppError('IDEMPOTENCY_REQUIRED', 'กรุณาลองส่งออเดอร์ใหม่');
        }
        $items = $input['items'] ?? [];
        if (! is_array($items) || count($items) === 0) {
            throw new AppError('EMPTY_CART', 'ยังไม่มีรายการในตะกร้า');
        }
        if (count($items) > 50) {
            throw new AppError('TOO_MANY_ITEMS', 'รายการในตะกร้ามากเกินไป กรุณาแบ่งการสั่ง');
        }

        return $this->lock->withLock('lock:order', 15000, 'ระบบกำลังรับออเดอร์อื่น กรุณาลองอีกครั้ง', function () use ($input, $key) {
            $idem = new IdempotencyManager($this->repo);
            $cached = $idem->begin('ORDER', $key);
            if ($cached !== null) {
                return $cached;
            }
            try {
                $result = $this->createOrder($input, $key);
                $idem->complete($key, $result['SessionID'], $result);

                return $result;
            } catch (\Throwable $e) {
                $idem->fail($key);
                throw $e;
            }
        });
    }

    private function createOrder(array $input, string $key): array
    {
        $tableToken = normalizeText($input['tableToken'] ?? '', 100);
        $table = $this->repo->find('Tables', 'Token', $tableToken);
        if (! $table || in_array((string) $table['Status'], ['DISABLED', 'PAYMENT_PENDING'], true)) {
            throw new AppError('TABLE_UNAVAILABLE', 'โต๊ะนี้ยังไม่พร้อมรับออเดอร์');
        }

        // recovery by RequestKey
        $recovered = collect($this->repo->all('OrderItems'))
            ->filter(fn ($i) => (string) $i['RequestKey'] === $key);
        if ($recovered->isNotEmpty()) {
            $sid = (string) $recovered->first()['SessionID'];
            $session = $this->repo->find('OrderSessions', 'SessionID', $sid);
            $totals = Totals::calculate($this->repo, $this->settings, $sid, $session ? (string) $session['PromoCode'] : (string) ($input['promoCode'] ?? ''));

            return [
                'SessionID' => $sid,
                'table' => ['TableID' => $table['TableID'], 'Name' => $table['Name']],
                'totals' => $totals,
                'items' => $recovered->map(fn ($i) => $this->publicRow($i))->values()->all(),
                'submittedAt' => (string) $recovered->first()['CreatedAt'],
            ];
        }

        $menu = collect($this->repo->all('MenuItems'))->keyBy(fn ($m) => (string) $m['ItemID']);
        $optionRows = collect($this->repo->all('Options'));
        $optionMap = $optionRows->keyBy(fn ($o) => (string) $o['OptionID']);
        $addOnMap = collect($this->repo->all('AddOns'))->keyBy(fn ($a) => (string) $a['AddOnID']);
        $now = nowIso();

        $sid = normalizeText($table['CurrentSessionID'] ?? '', 100);
        $session = $sid !== '' ? $this->repo->find('OrderSessions', 'SessionID', $sid) : null;
        if (! $session || in_array((string) $session['Status'], ['PAID', 'CLOSED', 'CANCELLED'], true)) {
            $sid = uuidPrefixed('ses_');
            $this->repo->append('OrderSessions', [[
                'SessionID' => $sid, 'TableID' => $table['TableID'], 'OpenTime' => $now, 'CloseTime' => '',
                'Status' => 'OPEN', 'Subtotal' => 0, 'Discount' => 0, 'ServiceCharge' => 0, 'Vat' => 0, 'Total' => 0,
                'PromoCode' => strtoupper(normalizeText($input['promoCode'] ?? '', 40)), 'PaymentMethod' => '',
                'CreatedBy' => 'CUSTOMER', 'IdempotencyKey' => $key, 'UpdatedAt' => $now,
            ]]);
            $session = $this->repo->find('OrderSessions', 'SessionID', $sid);
        }

        $orderRows = [];
        foreach ($input['items'] as $req) {
            $item = $menu[normalizeText($req['itemId'] ?? '', 80)] ?? null;
            if (! $item || (string) $item['Status'] !== 'ACTIVE') {
                throw new AppError('ITEM_UNAVAILABLE', 'มีเมนูที่ไม่พร้อมจำหน่าย กรุณาตรวจตะกร้าใหม่');
            }
            $qty = (int) floor(numberOr($req['qty'] ?? 1, 1));
            if ($qty < 1 || $qty > 20) {
                throw new AppError('INVALID_QTY', 'จำนวนของ '.$item['Name'].' ไม่ถูกต้อง');
            }
            $selOptIds = array_map('strval', $req['optionIds'] ?? []);
            $selAddIds = array_map('strval', $req['addOnIds'] ?? []);

            $itemOptions = $optionRows->filter(fn ($o) => (string) $o['ItemID'] === (string) $item['ItemID'] && (string) $o['Status'] === 'ACTIVE');
            $this->validateRequiredOptions($itemOptions, $selOptIds, (string) $item['Name']);

            $selOptions = [];
            foreach ($selOptIds as $id) {
                $o = $optionMap[$id] ?? null;
                if (! $o || (string) $o['ItemID'] !== (string) $item['ItemID'] || (string) $o['Status'] !== 'ACTIVE') {
                    throw new AppError('INVALID_OPTION', 'ตัวเลือกของ '.$item['Name'].' ไม่ถูกต้อง');
                }
                $selOptions[] = ['id' => $o['OptionID'], 'group' => $o['GroupName'], 'label' => $o['Label'], 'price' => money(numberOr($o['Price']))];
            }
            $selAddOns = [];
            foreach ($selAddIds as $id) {
                $a = $addOnMap[$id] ?? null;
                if (! $a || (string) $a['Status'] !== 'ACTIVE' || ! $this->catalog->addOnAppliesToItem($a, $item)) {
                    throw new AppError('INVALID_ADDON', 'ส่วนเพิ่มของ '.$item['Name'].' ไม่ถูกต้อง');
                }
                $selAddOns[] = ['id' => $a['AddOnID'], 'name' => $a['Name'], 'price' => money(numberOr($a['Price']))];
            }
            $optTotal = array_sum(array_column($selOptions, 'price'));
            $addTotal = array_sum(array_column($selAddOns, 'price'));
            $unitPrice = money(numberOr($item['Price']) + $optTotal + $addTotal);
            $orderRows[] = [
                'OrderItemID' => uuidPrefixed('ord_'), 'SessionID' => $sid, 'RequestKey' => $key,
                'ItemID' => $item['ItemID'], 'ItemName' => $item['Name'], 'Qty' => $qty, 'UnitPrice' => $unitPrice,
                'OptionsJSON' => json_encode($selOptions, JSON_UNESCAPED_UNICODE),
                'AddOnsJSON' => json_encode($selAddOns, JSON_UNESCAPED_UNICODE),
                'Note' => normalizeText($req['note'] ?? '', 300), 'LineTotal' => money($unitPrice * $qty),
                'Status' => 'NEW', 'KitchenNote' => '', 'CreatedAt' => $now, 'UpdatedAt' => $now,
            ];
        }

        $this->repo->append('OrderItems', $orderRows);
        $this->repo->update('Tables', 'TableID', (string) $table['TableID'], [
            'Status' => 'OCCUPIED', 'CurrentSessionID' => $sid, 'UpdatedAt' => $now,
        ]);
        $promoCode = strtoupper(normalizeText($input['promoCode'] ?? $session['PromoCode'] ?? '', 40));
        $totals = Totals::calculate($this->repo, $this->settings, $sid, $promoCode);
        $this->audit('CUSTOMER', 'SUBMIT_ORDER', 'OrderSession', $sid, ['itemCount' => count($orderRows), 'tableId' => $table['TableID']]);

        return [
            'SessionID' => $sid,
            'table' => ['TableID' => $table['TableID'], 'Name' => $table['Name']],
            'totals' => $totals,
            'items' => array_map(fn ($r) => $this->publicRow($r), $orderRows),
            'submittedAt' => $now,
        ];
    }

    private function validateRequiredOptions(Collection $options, array $selectedIds, string $itemName): void
    {
        $requiredGroups = $options->filter(fn ($o) => in_array(strtolower((string) $o['IsRequired']), ['true', '1'], true))
            ->pluck('GroupName')->unique();
        foreach ($requiredGroups as $group) {
            $ok = $options->contains(fn ($o) => (string) $o['GroupName'] === (string) $group && in_array((string) $o['OptionID'], $selectedIds, true));
            if (! $ok) {
                throw new AppError('REQUIRED_OPTION', 'กรุณาเลือก “'.$group.'” สำหรับ '.$itemName);
            }
        }
    }

    public function status(string $tableToken, string $sessionId): array
    {
        $table = $this->repo->find('Tables', 'Token', normalizeText($tableToken, 100));
        $session = $this->repo->find('OrderSessions', 'SessionID', normalizeText($sessionId, 100));
        if (! $table || ! $session || (string) $session['TableID'] !== (string) $table['TableID']) {
            throw new AppError('SESSION_NOT_FOUND', 'ไม่พบรอบการสั่งอาหารนี้');
        }

        return $this->sessionBundle($sessionId);
    }

    public function callStaff(array $input): array
    {
        $type = strtoupper(normalizeText($input['type'] ?? '', 30));
        if (! in_array($type, ['ASSISTANCE', 'BILL'], true)) {
            throw new AppError('INVALID_CALL_TYPE', 'ประเภทการเรียกไม่ถูกต้อง');
        }
        $table = $this->repo->find('Tables', 'Token', normalizeText($input['tableToken'] ?? '', 100));
        if (! $table) {
            throw new AppError('TABLE_NOT_FOUND', 'ไม่พบโต๊ะนี้');
        }
        if ($type === 'BILL' && (string) ($table['CurrentSessionID'] ?? '') === '') {
            throw new AppError('NO_ACTIVE_ORDER', 'ยังไม่มีออเดอร์สำหรับเรียกเก็บเงิน');
        }

        return $this->lock->withLock('lock:call', 5000, 'ระบบกำลังรับงานเรียกอื่น กรุณาลองอีกครั้ง', function () use ($table, $type, $input) {
            $existing = collect($this->repo->all('CallLogs'))->first(
                fn ($c) => (string) $c['TableID'] === (string) $table['TableID']
                    && (string) $c['Type'] === $type
                    && in_array((string) $c['Status'], ['OPEN', 'ASSIGNED'], true),
            );
            if ($existing) {
                if ($type === 'BILL') {
                    $this->markPaymentPending($table);
                }

                return ['call' => $this->publicRow($existing), 'duplicate' => true];
            }
            $call = [
                'LogID' => uuidPrefixed('call_'), 'TableID' => $table['TableID'], 'SessionID' => $table['CurrentSessionID'] ?? '',
                'Type' => $type, 'Status' => 'OPEN', 'AssignedStaffID' => '',
                'IdempotencyKey' => normalizeText($input['idempotencyKey'] ?? '', 120),
                'CreatedAt' => nowIso(), 'AcceptedAt' => '', 'CompletedAt' => '',
            ];
            $this->repo->append('CallLogs', [$call]);
            if ($type === 'BILL') {
                $this->markPaymentPending($table);
            }
            $this->audit('CUSTOMER', 'CALL_'.$type, 'Table', (string) $table['TableID'], []);

            return ['call' => $this->publicRow($call), 'duplicate' => false];
        });
    }

    private function markPaymentPending(array $table): void
    {
        $now = nowIso();
        $this->repo->update('Tables', 'TableID', (string) $table['TableID'], ['Status' => 'PAYMENT_PENDING', 'UpdatedAt' => $now]);
        $sid = (string) ($table['CurrentSessionID'] ?? '');
        if ($sid === '') {
            return;
        }
        $session = $this->repo->find('OrderSessions', 'SessionID', $sid);
        if ($session && (string) $session['Status'] === 'OPEN') {
            $this->repo->update('OrderSessions', 'SessionID', $sid, ['Status' => 'PAYMENT_PENDING', 'UpdatedAt' => $now]);
        }
    }

    public function sessionBundle(string $sessionId): ?array
    {
        $session = $this->repo->find('OrderSessions', 'SessionID', $sessionId);
        if (! $session) {
            return null;
        }
        $items = collect($this->repo->allCached('OrderItems'))->filter(fn ($i) => (string) $i['SessionID'] === $sessionId);
        $calls = collect($this->repo->allCached('CallLogs'))->filter(fn ($c) => (string) $c['SessionID'] === $sessionId);

        $clean = $this->publicRow($session);
        $billPending = $calls->contains(fn ($c) => (string) $c['Type'] === 'BILL' && in_array((string) $c['Status'], ['OPEN', 'ASSIGNED'], true));
        if ((string) $clean['Status'] === 'OPEN' && $billPending) {
            $clean['Status'] = 'PAYMENT_PENDING';
        }

        return [
            'session' => $clean,
            'items' => $items->map(function ($i) {
                $c = $this->publicRow($i);
                $c['options'] = json_decode((string) ($i['OptionsJSON'] ?? '[]'), true) ?: [];
                $c['addOns'] = json_decode((string) ($i['AddOnsJSON'] ?? '[]'), true) ?: [];
                unset($c['OptionsJSON'], $c['AddOnsJSON']);

                return $c;
            })->values()->all(),
            'calls' => $calls->map(fn ($c) => $this->publicRow($c))->values()->all(),
        ];
    }

    private function audit(string $staffId, string $action, string $entityType, string $entityId, array $detail): void
    {
        $this->repo->append('AuditLog', [[
            'Timestamp' => nowIso(), 'StaffID' => $staffId, 'Action' => $action,
            'EntityType' => $entityType, 'EntityID' => $entityId, 'DetailJSON' => json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]]);
    }

    private function publicRow(array $row): array
    {
        unset($row['_row'], $row['PINHash']);

        return $row;
    }
}
