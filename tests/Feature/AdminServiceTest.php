<?php

namespace Tests\Feature;

use App\Pos\Services\AdminService;
use App\Pos\Services\AuthService;
use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetsClient;
use Tests\TestCase;

class AdminServiceTest extends TestCase
{
    private function admin(): array
    {
        $fake = (new FakeSheetsClient)->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        $auth = $this->app->make(AuthService::class);
        $session = $auth->resolve($auth->login('zaq1234', 'ADMIN')['token'], ['ADMIN']);

        return [$this->app->make(AdminService::class), $session];
    }

    public function test_save_menu_entity_upserts(): void
    {
        [$admin, $session] = $this->admin();
        $saved = $admin->saveEntity($session, 'menu', [
            'ItemID' => 'M001', 'CategoryID' => 'CAT_RICE', 'Name' => 'กะเพราหมูสับพิเศษ',
            'Price' => 95, 'Status' => 'ACTIVE',
        ]);
        $this->assertSame('กะเพราหมูสับพิเศษ', $saved['Name']);
    }

    public function test_archive_category_in_use_is_blocked(): void
    {
        [$admin, $session] = $this->admin();
        $this->expectExceptionMessage('ย้ายหรือลบเมนูในหมวดนี้ก่อน');
        $admin->archiveEntity($session, 'category', 'CAT_RICE');
    }

    public function test_get_data_has_summary(): void
    {
        [$admin, $session] = $this->admin();
        $data = $admin->getData($session);
        $this->assertArrayHasKey('summary', $data);
        $this->assertSame(12, $data['summary']['tables']);
        $this->assertSame(8, $data['summary']['menuItems']);
    }
}
