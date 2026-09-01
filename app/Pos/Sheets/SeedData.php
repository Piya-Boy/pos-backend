<?php

namespace App\Pos\Sheets;

use function App\Pos\Support\sha256;
use function App\Pos\Support\uuidPrefixed;

// / Seed schema + default data, ported from cp-pos Database.js.
// / Used by FakeSheetsClient::seedDefaults() (tests) + pos:setup (real Sheets).
class SeedData
{
    public const SALT = 'test-fixed-salt-v1';

    public const INITIAL_PIN = 'zaq1234';

    /**
     * @param  string|null  $salt  salt for the initial PIN hash; defaults to the
     *                             fixed test salt so FakeSheetsClient stays deterministic.
     *                             pos:setup passes the real config salt for prod sheets.
     * @return array<string, array{headers: array, rows: array}>
     */
    public static function sheets(?string $salt = null): array
    {
        $salt ??= self::SALT;
        $now = '2026-01-01T00:00:00+07:00';
        $pinHash = sha256($salt.':'.self::INITIAL_PIN);

        return [
            'Tables' => [
                'headers' => ['TableID', 'Name', 'Zone', 'Token', 'Status', 'CurrentSessionID', 'CreatedAt', 'UpdatedAt'],
                'rows' => self::tables($now),
            ],
            'Categories' => [
                'headers' => ['CategoryID', 'Name', 'Icon', 'SortOrder', 'Status', 'CreatedAt', 'UpdatedAt'],
                'rows' => [
                    ['CAT_RICE', 'อาหารจานเดียว', '🍚', '1', 'ACTIVE', $now, $now],
                    ['CAT_SHARED', 'กับข้าว', '🥘', '2', 'ACTIVE', $now, $now],
                    ['CAT_NOODLE', 'เส้นและก๋วยเตี๋ยว', '🍜', '3', 'ACTIVE', $now, $now],
                    ['CAT_DRINK', 'เครื่องดื่ม', '🥤', '4', 'ACTIVE', $now, $now],
                    ['CAT_DESSERT', 'ของหวาน', '🍨', '5', 'ACTIVE', $now, $now],
                ],
            ],
            'MenuItems' => [
                'headers' => ['ItemID', 'CategoryID', 'Name', 'Price', 'Description', 'ImageURL', 'Status', 'SortOrder', 'IsPopular', 'CreatedAt', 'UpdatedAt'],
                'rows' => self::menu($now),
            ],
            'Options' => [
                'headers' => ['OptionID', 'ItemID', 'GroupName', 'Label', 'Price', 'InputType', 'IsRequired', 'SortOrder', 'Status'],
                'rows' => [
                    ['OPT001', 'M001', 'ระดับความเผ็ด', 'ไม่เผ็ด', '0', 'RADIO', 'TRUE', '1', 'ACTIVE'],
                    ['OPT002', 'M001', 'ระดับความเผ็ด', 'เผ็ดปกติ', '0', 'RADIO', 'TRUE', '2', 'ACTIVE'],
                    ['OPT003', 'M001', 'ระดับความเผ็ด', 'เผ็ดมาก', '0', 'RADIO', 'TRUE', '3', 'ACTIVE'],
                    ['OPT004', 'M005', 'เครื่องเคียง', 'ไม่ใส่ถั่ว', '0', 'CHECKBOX', 'FALSE', '1', 'ACTIVE'],
                ],
            ],
            'AddOns' => [
                'headers' => ['AddOnID', 'Name', 'Price', 'LinkedItemID', 'LinkedCategoryID', 'Status', 'SortOrder'],
                'rows' => [
                    ['ADD001', 'ไข่ดาว', '15', '', 'CAT_RICE', 'ACTIVE', '1'],
                    ['ADD002', 'ไข่เจียว', '20', '', 'CAT_RICE', 'ACTIVE', '2'],
                    ['ADD003', 'ข้าวเพิ่ม', '20', '', 'CAT_RICE', 'ACTIVE', '3'],
                    ['ADD004', 'กุ้งเพิ่ม', '45', 'M005', '', 'ACTIVE', '4'],
                ],
            ],
            'Promotions' => [
                'headers' => ['PromoID', 'Code', 'Name', 'Description', 'DiscountType', 'DiscountValue', 'MinSpend', 'StartDate', 'EndDate', 'BannerImage', 'Status'],
                'rows' => [
                    ['PROMO_WELCOME', 'WELCOME10', 'Welcome Special', 'ลด 10% เมื่อสั่งครบ 500 บาท', 'PERCENT', '10', '500', '2025-01-01', '2035-12-31', 'https://images.unsplash.com/photo-1552566626-52f8b828add9?auto=format&fit=crop&w=1200&q=80', 'ACTIVE'],
                ],
            ],
            'OrderSessions' => [
                'headers' => ['SessionID', 'TableID', 'OpenTime', 'CloseTime', 'Status', 'Subtotal', 'Discount', 'ServiceCharge', 'Vat', 'Total', 'PromoCode', 'PaymentMethod', 'CreatedBy', 'IdempotencyKey', 'UpdatedAt'],
                'rows' => [],
            ],
            'OrderItems' => [
                'headers' => ['OrderItemID', 'SessionID', 'RequestKey', 'ItemID', 'ItemName', 'Qty', 'UnitPrice', 'OptionsJSON', 'AddOnsJSON', 'Note', 'LineTotal', 'Status', 'KitchenNote', 'CreatedAt', 'UpdatedAt'],
                'rows' => [],
            ],
            'CallLogs' => [
                'headers' => ['LogID', 'TableID', 'SessionID', 'Type', 'Status', 'AssignedStaffID', 'IdempotencyKey', 'CreatedAt', 'AcceptedAt', 'CompletedAt'],
                'rows' => [],
            ],
            'Payments' => [
                'headers' => ['PaymentID', 'SessionID', 'IdempotencyKey', 'Amount', 'Method', 'Reference', 'PaidAt', 'StaffID'],
                'rows' => [],
            ],
            'Transactions' => [
                'headers' => ['TransactionID', 'Type', 'IdempotencyKey', 'EntityID', 'Status', 'CreatedAt', 'UpdatedAt', 'ResultJSON'],
                'rows' => [],
            ],
            'Staff' => [
                'headers' => ['StaffID', 'Name', 'PINHash', 'Role', 'Status', 'MustChangePin', 'CreatedAt', 'UpdatedAt', 'LastLogin'],
                'rows' => [
                    [uuidPrefixed('stf_'), 'ผู้ดูแลระบบ', $pinHash, 'ADMIN', 'ACTIVE', 'TRUE', $now, $now, ''],
                    [uuidPrefixed('stf_'), 'ครัว', $pinHash, 'KITCHEN', 'ACTIVE', 'TRUE', $now, $now, ''],
                    [uuidPrefixed('stf_'), 'พนักงานเสิร์ฟ', $pinHash, 'STAFF', 'ACTIVE', 'TRUE', $now, $now, ''],
                    [uuidPrefixed('stf_'), 'แคชเชียร์', $pinHash, 'CASHIER', 'ACTIVE', 'TRUE', $now, $now, ''],
                ],
            ],
            'Settings' => [
                'headers' => ['Key', 'Value', 'UpdatedAt'],
                'rows' => self::settings($now),
            ],
            'AuditLog' => [
                'headers' => ['Timestamp', 'StaffID', 'Action', 'EntityType', 'EntityID', 'DetailJSON'],
                'rows' => [],
            ],
        ];
    }

    private static function tables(string $now): array
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $rows[] = [
                'T'.$n, 'โต๊ะ '.$n, $i <= 6 ? 'โซนด้านใน' : 'โซนระเบียง',
                uuidPrefixed('tbl_'), 'AVAILABLE', '', $now, $now,
            ];
        }

        return $rows;
    }

    private static function menu(string $now): array
    {
        $img = 'https://images.unsplash.com/';

        return [
            ['M001', 'CAT_RICE', 'กะเพราหมูสับ', '85', 'กะเพราหอมกระทะ เสิร์ฟพร้อมข้าวหอมมะลิ', $img.'photo-1562565652-a0d8f0c59eb4?auto=format&fit=crop&w=900&q=80', 'ACTIVE', '1', 'TRUE', $now, $now],
            ['M002', 'CAT_RICE', 'ข้าวผัดกุ้ง', '110', 'ข้าวผัดหอมกระทะกับกุ้งสด', $img.'photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=900&q=80', 'ACTIVE', '2', 'TRUE', $now, $now],
            ['M003', 'CAT_SHARED', 'ต้มยำกุ้งน้ำข้น', '220', 'กุ้งสดและสมุนไพรไทย รสเปรี้ยวเผ็ดกลมกล่อม', $img.'photo-1548943487-a2e4e43b4853?auto=format&fit=crop&w=900&q=80', 'ACTIVE', '1', 'TRUE', $now, $now],
            ['M004', 'CAT_SHARED', 'แกงเขียวหวานไก่', '180', 'เครื่องแกงตำสด กะทิหอมและโหระพา', $img.'photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=900&q=80', 'ACTIVE', '2', 'FALSE', $now, $now],
            ['M005', 'CAT_NOODLE', 'ผัดไทยกุ้งสด', '125', 'เส้นเหนียวนุ่ม ซอสมะขามสูตรร้าน', $img.'photo-1559314809-0d155014e29e?auto=format&fit=crop&w=900&q=80', 'ACTIVE', '1', 'TRUE', $now, $now],
            ['M006', 'CAT_DRINK', 'ชาไทยเย็น', '55', 'ชาไทยเข้มข้น หวานมันกำลังดี', $img.'photo-1558857563-b371033873b8?auto=format&fit=crop&w=900&q=80', 'ACTIVE', '1', 'TRUE', $now, $now],
            ['M007', 'CAT_DRINK', 'น้ำมะนาวโซดา', '65', 'มะนาวสดและโซดาซ่า', $img.'photo-1513558161293-cdaf765ed2fd?auto=format&fit=crop&w=900&q=80', 'ACTIVE', '2', 'FALSE', $now, $now],
            ['M008', 'CAT_DESSERT', 'ข้าวเหนียวมะม่วง', '120', 'มะม่วงสุก ข้าวเหนียวมูนและกะทิสด', $img.'photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=900&q=80', 'ACTIVE', '1', 'TRUE', $now, $now],
        ];
    }

    private static function settings(string $now): array
    {
        $defaults = [
            'AppName' => 'Phius Order',
            'RestaurantName' => 'Phius Thai Kitchen',
            'RestaurantTagline' => 'Modern Thai Vitality',
            'BrandLogoText' => 'ผ',
            'BrandLogoURL' => '',
            'HeroKicker' => 'อิ่มอร่อยในแบบของคุณ',
            'HeroTitle' => "เลือกเมนูโปรด\nแล้วส่งตรงถึงครัว",
            'HeroBadgeText' => 'อร่อย',
            'HeroBadgeImageURL' => '',
            'Currency' => 'THB',
            'CurrencySymbol' => '฿',
            'ServiceChargePercent' => '0',
            'VatPercent' => '0',
            'PrimaryColor' => '#B7442B',
            'SuccessColor' => '#2F6B4F',
            'BackgroundColor' => '#FBF7F0',
            'SurfaceColor' => '#FFFFFF',
            'TextColor' => '#211E1B',
            'OrderPollingSeconds' => '8',
            'AllowDriveUploads' => 'TRUE',
        ];
        $rows = [];
        foreach ($defaults as $k => $v) {
            $rows[] = [$k, $v, $now];
        }

        return $rows;
    }
}
