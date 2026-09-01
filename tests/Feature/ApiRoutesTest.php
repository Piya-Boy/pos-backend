<?php

namespace Tests\Feature;

use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetsClient;
use Tests\TestCase;

class ApiRoutesTest extends TestCase
{
    private function seedFake(): FakeSheetsClient
    {
        $fake = (new FakeSheetsClient)->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);

        return $fake;
    }

    public function test_bootstrap_returns_envelope(): void
    {
        $this->seedFake();
        $res = $this->postJson('/api/bootstrap', []);
        $res->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertArrayHasKey('app', $res->json('data'));
    }

    public function test_customer_flow_submit(): void
    {
        $fake = $this->seedFake();
        $res = $this->postJson('/api/order/submit', [
            'tableToken' => $fake->firstTableToken(), 'idempotencyKey' => 'k1', 'promoCode' => '',
            'items' => [['itemId' => 'M006', 'qty' => 1, 'optionIds' => [], 'addOnIds' => [], 'note' => '']],
        ]);
        $res->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertNotEmpty($res->json('data.SessionID'));
    }

    public function test_ops_requires_auth(): void
    {
        $this->seedFake();
        $res = $this->postJson('/api/ops/dashboard', ['token' => 'bad', 'view' => 'KITCHEN']);
        $res->assertStatus(200)->assertJson(['ok' => false]);
        $this->assertSame('AUTH_EXPIRED', $res->json('error.code'));
    }

    public function test_login_then_kitchen_dashboard(): void
    {
        $this->seedFake();
        $login = $this->postJson('/api/auth/login', ['pin' => 'zaq1234', 'expectedRole' => 'KITCHEN']);
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);
        $res = $this->postJson('/api/ops/dashboard', ['token' => $token, 'view' => 'KITCHEN']);
        $res->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertArrayHasKey('summary', $res->json('data'));
    }
}
