<?php

namespace App\Pos\Services;

use App\Pos\Sheets\SeedData;
use App\Pos\Sheets\SheetRepository;
use App\Pos\Support\AppError;

use function App\Pos\Support\boolish;
use function App\Pos\Support\normalizeText;
use function App\Pos\Support\nowIso;
use function App\Pos\Support\numberOr;
use function App\Pos\Support\uuidPrefixed;

// / Ports cp-pos admin CRUD (Admin.js:262-441).
class AdminService
{
    /** ADMIN_ENTITIES (Admin.js:1-10). */
    private const ENTITIES = [
        'table' => ['sheet' => 'Tables', 'key' => 'TableID', 'prefix' => 'T', 'fields' => ['TableID', 'Name', 'Zone', 'Status']],
        'category' => ['sheet' => 'Categories', 'key' => 'CategoryID', 'prefix' => 'cat_', 'fields' => ['CategoryID', 'Name', 'Icon', 'SortOrder', 'Status']],
        'menu' => ['sheet' => 'MenuItems', 'key' => 'ItemID', 'prefix' => 'menu_', 'fields' => ['ItemID', 'CategoryID', 'Name', 'Price', 'Description', 'ImageURL', 'Status', 'SortOrder', 'IsPopular']],
        'option' => ['sheet' => 'Options', 'key' => 'OptionID', 'prefix' => 'opt_', 'fields' => ['OptionID', 'ItemID', 'GroupName', 'Label', 'Price', 'InputType', 'IsRequired', 'SortOrder', 'Status']],
        'addon' => ['sheet' => 'AddOns', 'key' => 'AddOnID', 'prefix' => 'add_', 'fields' => ['AddOnID', 'Name', 'Price', 'LinkedItemID', 'LinkedCategoryID', 'Status', 'SortOrder']],
        'promotion' => ['sheet' => 'Promotions', 'key' => 'PromoID', 'prefix' => 'promo_', 'fields' => ['PromoID', 'Code', 'Name', 'Description', 'DiscountType', 'DiscountValue', 'MinSpend', 'StartDate', 'EndDate', 'BannerImage', 'Status']],
        'staff' => ['sheet' => 'Staff', 'key' => 'StaffID', 'prefix' => 'stf_', 'fields' => ['StaffID', 'Name', 'Role', 'Status']],
    ];

    public function __construct(
        private SheetRepository $repo,
        private SettingsService $settings,
        private CatalogService $catalog,
    ) {}

    /** Ports adminGetData (Admin.js:262). */
    public function getData(array $session): array
    {
        $base = (string) config('pos.order_base_url', '');
        $data = ['user' => $session, 'settings' => $this->settings->map()];
        foreach (self::ENTITIES as $cfg) {
            $data[$cfg['sheet']] = array_map(fn ($r) => $this->publicRow($r), $this->repo->all($cfg['sheet']));
        }
        $data['Tables'] = array_map(function ($t) use ($base) {
            $t['orderUrl'] = $base !== '' ? $base.'?page=order&table='.rawurlencode((string) $t['Token']) : '';

            return $t;
        }, $data['Tables']);
        $sessions = $this->repo->all('OrderSessions');
        $payments = $this->repo->all('Payments');
        $today = now('Asia/Bangkok')->format('Y-m-d');
        $data['summary'] = [
            'tables' => count($data['Tables']),
            'menuItems' => count(array_filter($data['MenuItems'], fn ($r) => (string) $r['Status'] !== 'ARCHIVED')),
            'activeSessions' => count(array_filter($sessions, fn ($r) => in_array((string) $r['Status'], ['OPEN', 'PAYMENT_PENDING'], true))),
            'todaySales' => array_sum(array_map(
                fn ($r) => substr((string) $r['PaidAt'], 0, 10) === $today ? numberOr($r['Amount']) : 0,
                $payments,
            )),
        ];

        return $data;
    }

    /** Ports adminSaveEntity (Admin.js:351). */
    public function saveEntity(array $session, string $entity, array $source): array
    {
        $entity = strtolower(normalizeText($entity, 30));
        $cfg = self::ENTITIES[$entity] ?? null;
        if (! $cfg) {
            throw new AppError('INVALID_ENTITY', 'ประเภทข้อมูลไม่ถูกต้อง');
        }
        $object = [];
        foreach ($cfg['fields'] as $field) {
            if (array_key_exists($field, $source)) {
                $object[$field] = $source[$field];
            }
        }
        if (empty($object[$cfg['key']])) {
            $object[$cfg['key']] = $cfg['prefix'] !== '' ? uuidPrefixed($cfg['prefix']) : '';
        }
        if (empty($object[$cfg['key']])) {
            throw new AppError('KEY_REQUIRED', 'กรุณาระบุรหัสข้อมูล');
        }
        $existing = $this->repo->find($cfg['sheet'], $cfg['key'], (string) $object[$cfg['key']]);
        if ($entity === 'table' && $existing && (string) ($existing['CurrentSessionID'] ?? '') !== ''
            && isset($object['Status']) && (string) $object['Status'] !== (string) $existing['Status']) {
            throw new AppError('TABLE_IN_USE', 'ไม่สามารถเปลี่ยนสถานะโต๊ะขณะมีรอบการสั่งอาหาร');
        }
        $this->validateEntity($entity, $object);
        $now = nowIso();
        $object['UpdatedAt'] = $now;
        if (! $existing) {
            $object['CreatedAt'] = $now;
        }
        if ($entity === 'table' && ! $existing) {
            $object['Token'] = uuidPrefixed('tbl_');
            $object['CurrentSessionID'] = '';
        }
        if ($entity === 'staff' && ! empty($source['PIN'])) {
            $pin = normalizeText($source['PIN'], 12);
            if (! preg_match('/^[A-Za-z0-9]{6,12}$/', $pin)) {
                throw new AppError('INVALID_PIN', 'PIN ต้องเป็นตัวอักษรภาษาอังกฤษหรือตัวเลข 6–12 ตัว');
            }
            $object['PINHash'] = \App\Pos\Support\sha256(SeedData::SALT.':'.$pin);
            $object['MustChangePin'] = 'TRUE';
        }
        if ($entity === 'staff' && ! $existing && empty($source['PIN'])) {
            throw new AppError('PIN_REQUIRED', 'พนักงานใหม่ต้องมี PIN 6–12 ตัว');
        }
        $saved = $this->repo->upsert($cfg['sheet'], $cfg['key'], $object);
        if (in_array($entity, ['category', 'menu', 'option', 'addon', 'promotion', 'table'], true)) {
            $this->catalog->clearCatalogCache();
        }
        $this->audit((string) $session['staffId'], 'SAVE_'.strtoupper($entity), $cfg['sheet'], (string) $object[$cfg['key']]);

        return $this->publicRow($saved);
    }

    /** Ports adminArchiveEntity (Admin.js:392). */
    public function archiveEntity(array $session, string $entity, string $id): array
    {
        $entity = strtolower(normalizeText($entity, 30));
        $cfg = self::ENTITIES[$entity] ?? null;
        if (! $cfg || $entity === 'setting') {
            throw new AppError('INVALID_ENTITY', 'ไม่สามารถปิดข้อมูลประเภทนี้');
        }
        $existing = $this->repo->find($cfg['sheet'], $cfg['key'], $id);
        if (! $existing) {
            throw new AppError('NOT_FOUND', 'ไม่พบข้อมูล');
        }
        if ($entity === 'staff' && (string) $existing['StaffID'] === (string) $session['staffId']) {
            throw new AppError('SELF_ARCHIVE_DENIED', 'ไม่สามารถปิดบัญชีที่กำลังใช้งาน');
        }
        if ($entity === 'category') {
            $inUse = collect($this->repo->all('MenuItems'))
                ->contains(fn ($r) => (string) $r['CategoryID'] === $id && (string) $r['Status'] !== 'ARCHIVED');
            if ($inUse) {
                throw new AppError('CATEGORY_IN_USE', 'ย้ายหรือลบเมนูในหมวดนี้ก่อน แล้วจึงลบหมวดหมู่');
            }
            $addOnInUse = collect($this->repo->all('AddOns'))
                ->contains(fn ($r) => (string) $r['LinkedCategoryID'] === $id && (string) $r['Status'] !== 'ARCHIVED');
            if ($addOnInUse) {
                throw new AppError('CATEGORY_ADDON_IN_USE', 'ย้ายหรือลบ Add-on ที่ผูกกับหมวดนี้ก่อน แล้วจึงลบหมวดหมู่');
            }
        }
        $updated = $this->repo->update($cfg['sheet'], $cfg['key'], $id, ['Status' => 'ARCHIVED', 'UpdatedAt' => nowIso()]);
        if (in_array($entity, ['category', 'menu', 'option', 'addon', 'promotion', 'table'], true)) {
            $this->catalog->clearCatalogCache();
        }
        $this->audit((string) $session['staffId'], 'ARCHIVE_'.strtoupper($entity), $cfg['sheet'], $id);

        return $this->publicRow($updated);
    }

    /** Ports rotateTableToken (Admin.js:420). */
    public function rotateToken(array $session, string $tableId): array
    {
        $table = $this->repo->find('Tables', 'TableID', $tableId);
        if (! $table) {
            throw new AppError('TABLE_NOT_FOUND', 'ไม่พบโต๊ะนี้');
        }
        if ((string) ($table['CurrentSessionID'] ?? '') !== '') {
            throw new AppError('TABLE_IN_USE', 'ไม่สามารถเปลี่ยน QR ขณะโต๊ะกำลังใช้งาน');
        }
        $updated = $this->repo->update('Tables', 'TableID', $tableId, ['Token' => uuidPrefixed('tbl_'), 'UpdatedAt' => nowIso()]);
        $this->catalog->clearCatalogCache(); // drops the old token from the tables cache
        $this->audit((string) $session['staffId'], 'ROTATE_TABLE_TOKEN', 'Table', $tableId);

        return $this->publicRow($updated);
    }

    private function validateEntity(string $entity, array &$object): void
    {
        $allowed = ['ACTIVE', 'INACTIVE', 'SOLD_OUT', 'AVAILABLE', 'DISABLED', 'OCCUPIED', 'PAYMENT_PENDING'];
        if (! empty($object['Status'])) {
            $status = strtoupper((string) $object['Status']);
            if (! in_array($status, $allowed, true)) {
                throw new AppError('INVALID_STATUS', 'สถานะข้อมูลไม่ถูกต้อง');
            }
            $object['Status'] = $status;
        }
        if ($entity === 'menu') {
            if (normalizeText($object['Name'] ?? '', 120) === '') {
                throw new AppError('NAME_REQUIRED', 'กรุณาระบุชื่อเมนู');
            }
            $object['Name'] = normalizeText($object['Name'], 120);
            $object['Description'] = normalizeText($object['Description'] ?? '', 500);
        }
        if (isset($object['IsPopular'])) {
            $object['IsPopular'] = boolish($object['IsPopular']) ? 'TRUE' : 'FALSE';
        }
        if (isset($object['IsRequired'])) {
            $object['IsRequired'] = boolish($object['IsRequired']) ? 'TRUE' : 'FALSE';
        }
    }

    private function audit(string $staffId, string $action, string $entityType, string $entityId): void
    {
        $this->repo->append('AuditLog', [[
            'Timestamp' => nowIso(), 'StaffID' => $staffId, 'Action' => $action,
            'EntityType' => $entityType, 'EntityID' => $entityId, 'DetailJSON' => '{}',
        ]]);
    }

    private function publicRow(array $row): array
    {
        unset($row['_row'], $row['PINHash']);

        return $row;
    }
}
