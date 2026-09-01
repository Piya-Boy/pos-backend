<?php

namespace App\Pos\Services;

use App\Pos\Sheets\SheetRepository;
use App\Pos\Support\AppError;
use App\Pos\Support\IdempotencyManager;
use App\Pos\Support\LockManager;
use App\Pos\Support\Totals;

use function App\Pos\Support\normalizeText;
use function App\Pos\Support\nowIso;
use function App\Pos\Support\uuidPrefixed;

// / Ports cp-pos closeTable + buildReceipt_ (Admin.js:191-260). Write order:
// / Payment (append, dedup by key) -> Session PAID -> Table reset -> close calls.
class PaymentService
{
    public function __construct(
        private SheetRepository $repo,
        private SettingsService $settings,
        private OrderService $orders,
        private LockManager $lock,
    ) {}

    public function closeTable(array $session, array $input): array
    {
        $key = normalizeText($input['idempotencyKey'] ?? '', 120);
        if ($key === '') {
            throw new AppError('IDEMPOTENCY_REQUIRED', 'กรุณาลองรับชำระใหม่');
        }

        return $this->lock->withLock('lock:payment', 15000, 'ระบบกำลังปิดบิลอื่น กรุณาลองอีกครั้ง', function () use ($session, $input, $key) {
            $idem = new IdempotencyManager($this->repo);
            $cached = $idem->begin('PAYMENT', $key);
            if ($cached !== null) {
                return $cached;
            }
            try {
                $result = $this->close($session, $input, $key);
                $idem->complete($key, $result['payment']['PaymentID'], $result);

                return $result;
            } catch (\Throwable $e) {
                $idem->fail($key);
                throw $e;
            }
        });
    }

    private function close(array $staff, array $input, string $key): array
    {
        $session = $this->repo->find('OrderSessions', 'SessionID', normalizeText($input['sessionId'] ?? '', 100));
        if (! $session || ! in_array((string) $session['Status'], ['OPEN', 'PAYMENT_PENDING'], true)) {
            throw new AppError('SESSION_CLOSED', 'รอบโต๊ะนี้ถูกปิดแล้ว');
        }
        $table = $this->repo->find('Tables', 'TableID', (string) $session['TableID']);
        $totals = Totals::calculate($this->repo, $this->settings, (string) $session['SessionID'], (string) $session['PromoCode']);
        $method = strtoupper(normalizeText($input['method'] ?? '', 30));
        if (! in_array($method, config('pos.payment_methods'), true)) {
            throw new AppError('PAYMENT_METHOD_REQUIRED', 'กรุณาเลือกวิธีชำระเงิน');
        }

        // dedup Payment by idempotency key
        $payment = $this->repo->find('Payments', 'IdempotencyKey', $key);
        if (! $payment) {
            $payment = [
                'PaymentID' => uuidPrefixed('pay_'), 'SessionID' => $session['SessionID'], 'IdempotencyKey' => $key,
                'Amount' => $totals['total'], 'Method' => $method, 'Reference' => normalizeText($input['reference'] ?? '', 100),
                'PaidAt' => nowIso(), 'StaffID' => (string) $staff['staffId'],
            ];
            $this->repo->append('Payments', [$payment]);
        }
        $paidAt = (string) $payment['PaidAt'];

        $this->repo->update('OrderSessions', 'SessionID', (string) $session['SessionID'], [
            'CloseTime' => $paidAt, 'Status' => 'PAID', 'PaymentMethod' => $method, 'UpdatedAt' => $paidAt,
        ]);
        if ($table) {
            $this->repo->update('Tables', 'TableID', (string) $table['TableID'], [
                'Status' => 'AVAILABLE', 'CurrentSessionID' => '', 'UpdatedAt' => $paidAt,
            ]);
        }
        foreach ($this->repo->all('CallLogs') as $call) {
            if ((string) $call['SessionID'] === (string) $session['SessionID'] && in_array((string) $call['Status'], ['OPEN', 'ASSIGNED'], true)) {
                $this->repo->update('CallLogs', 'LogID', (string) $call['LogID'], [
                    'Status' => 'DONE', 'AssignedStaffID' => (string) $staff['staffId'], 'CompletedAt' => $paidAt,
                ]);
            }
        }

        $result = [
            'payment' => $this->publicRow($payment),
            'receipt' => $this->buildReceipt((string) $session['SessionID'], $payment),
            'tableReset' => (bool) $table,
        ];
        $this->audit((string) $staff['staffId'], 'CLOSE_TABLE', 'OrderSession', (string) $session['SessionID'], ['amount' => $totals['total'], 'method' => $method]);

        return $result;
    }

    private function buildReceipt(string $sessionId, array $payment): array
    {
        $bundle = $this->orders->sessionBundle($sessionId);
        if (! $bundle) {
            throw new AppError('SESSION_NOT_FOUND', 'ไม่พบข้อมูลใบเสร็จ');
        }
        $table = $this->repo->find('Tables', 'TableID', (string) $bundle['session']['TableID']);
        $s = $this->settings->map();

        return [
            'restaurantName' => $s['RestaurantName'] ?? 'Phius Order',
            'table' => $table ? $table['Name'] : $bundle['session']['TableID'],
            'session' => $bundle['session'],
            'items' => $bundle['items'],
            'payment' => $this->publicRow($payment),
            'generatedAt' => nowIso(),
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
