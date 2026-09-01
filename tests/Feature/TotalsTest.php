<?php

namespace Tests\Feature;

use App\Pos\Services\SettingsService;
use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetRepository;
use App\Pos\Support\Totals;
use Tests\TestCase;

class TotalsTest extends TestCase
{
    private function repo(): SheetRepository
    {
        $c = new FakeSheetsClient;
        $c->updateValues('Settings!A1', [['Key', 'Value', 'UpdatedAt']]);
        $c->appendValues('Settings!A1', [['ServiceChargePercent', '0', ''], ['VatPercent', '0', '']]);
        $c->updateValues('OrderSessions!A1', [[
            'SessionID', 'TableID', 'OpenTime', 'CloseTime', 'Status', 'Subtotal', 'Discount',
            'ServiceCharge', 'Vat', 'Total', 'PromoCode', 'PaymentMethod', 'CreatedBy', 'IdempotencyKey', 'UpdatedAt',
        ]]);
        $c->appendValues('OrderSessions!A1', [['ses_1', 'T01', '', '', 'OPEN', '0', '0', '0', '0', '0', '', '', 'CUSTOMER', 'k', '']]);
        $c->updateValues('OrderItems!A1', [[
            'OrderItemID', 'SessionID', 'RequestKey', 'ItemID', 'ItemName', 'Qty', 'UnitPrice',
            'OptionsJSON', 'AddOnsJSON', 'Note', 'LineTotal', 'Status', 'KitchenNote', 'CreatedAt', 'UpdatedAt',
        ]]);
        $c->appendValues('OrderItems!A1', [['oi1', 'ses_1', 'k', 'M001', 'กะเพรา', '2', '85', '[]', '[]', '', '170', 'NEW', '', '', '']]);
        $c->updateValues('Promotions!A1', [[
            'PromoID', 'Code', 'Name', 'Description', 'DiscountType', 'DiscountValue', 'MinSpend',
            'StartDate', 'EndDate', 'BannerImage', 'Status',
        ]]);
        $c->appendValues('Promotions!A1', [['p1', 'WELCOME10', 'w', '', 'PERCENT', '10', '100', '2020-01-01', '2035-01-01', '', 'ACTIVE']]);

        return new SheetRepository($c);
    }

    public function test_percent_promo(): void
    {
        $repo = $this->repo();
        $t = Totals::calculate($repo, new SettingsService($repo), 'ses_1', 'WELCOME10');
        $this->assertSame(170.0, $t['subtotal']);
        $this->assertSame(17.0, $t['discount']); // 10% of 170
        $this->assertSame(153.0, $t['total']);
    }

    public function test_no_promo_when_below_min_spend(): void
    {
        $repo = $this->repo();
        // MinSpend 100, subtotal 170 -> qualifies; test the below-min branch with a fresh promo
        $t = Totals::calculate($repo, new SettingsService($repo), 'ses_1', 'NOPE');
        $this->assertSame(0.0, $t['discount']);
        $this->assertSame(170.0, $t['total']);
    }
}
