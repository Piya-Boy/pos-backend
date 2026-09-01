<?php

namespace Tests\Feature;

use App\Pos\Services\AuthService;
use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetsClient;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    private function bindFake(): FakeSheetsClient
    {
        $fake = (new FakeSheetsClient)->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);

        return $fake;
    }

    public function test_login_with_seeded_pin_and_role(): void
    {
        $this->bindFake();
        $auth = $this->app->make(AuthService::class);
        $res = $auth->login('zaq1234', 'KITCHEN');
        $this->assertNotEmpty($res['token']);
        $this->assertSame('KITCHEN', $res['user']['role']);
        $back = $auth->resolve($res['token'], ['KITCHEN']);
        $this->assertSame('KITCHEN', $back['role']);
    }

    public function test_resolve_rejects_wrong_role_unless_admin(): void
    {
        $this->bindFake();
        $auth = $this->app->make(AuthService::class);
        $res = $auth->login('zaq1234', 'KITCHEN');
        $this->expectExceptionMessage('คุณไม่มีสิทธิ์ทำรายการนี้');
        $auth->resolve($res['token'], ['CASHIER']);
    }

    public function test_admin_overrides_any_role(): void
    {
        $this->bindFake();
        $auth = $this->app->make(AuthService::class);
        $res = $auth->login('zaq1234', 'ADMIN');
        $back = $auth->resolve($res['token'], ['CASHIER']); // admin passes any gate
        $this->assertSame('ADMIN', $back['role']);
    }
}
