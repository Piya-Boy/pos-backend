<?php

namespace Tests\Feature;

use App\Pos\Services\CatalogService;
use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetsClient;
use Tests\TestCase;

class CatalogServiceTest extends TestCase
{
    private function bindFake(): FakeSheetsClient
    {
        $fake = (new FakeSheetsClient)->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);

        return $fake;
    }

    public function test_public_catalog_joins_options_and_addons(): void
    {
        $this->bindFake();
        $catalog = $this->app->make(CatalogService::class)->publicCatalog();

        $this->assertCount(5, $catalog['categories']);
        $this->assertCount(8, $catalog['menu']);

        $m001 = collect($catalog['menu'])->firstWhere('ItemID', 'M001');
        $this->assertTrue($m001['available']);
        $this->assertNotEmpty($m001['options']); // ระดับความเผ็ด
        $this->assertTrue(
            collect($m001['addOns'])->contains(fn ($a) => $a['AddOnID'] === 'ADD001'),
            'M001 (CAT_RICE) should include ADD001 by category scope',
        );

        $this->assertSame('WELCOME10', $catalog['promotions'][0]['Code']);
    }

    public function test_customer_data_rejects_unknown_token(): void
    {
        $this->bindFake();
        $this->expectExceptionMessage('QR โต๊ะนี้ไม่พร้อมใช้งาน');
        $this->app->make(CatalogService::class)->customerData('nope');
    }
}
