<?php

namespace Tests\Feature;

use App\Pos\Services\AuthService;
use App\Pos\Services\OrderService;
use App\Pos\Services\PaymentService;
use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetsClient;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    private function bindFake(): FakeSheetsClient
    {
        $fake = (new FakeSheetsClient)->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);

        return $fake;
    }

    public function test_close_table_is_idempotent_and_sets_paid(): void
    {
        $fake = $this->bindFake();
        $order = $this->app->make(OrderService::class);
        $auth = $this->app->make(AuthService::class);
        $pay = $this->app->make(PaymentService::class);

        $submit = $order->submit([
            'tableToken' => $fake->firstTableToken(), 'idempotencyKey' => 'o1', 'promoCode' => '',
            'items' => [['itemId' => 'M006', 'qty' => 1, 'optionIds' => [], 'addOnIds' => [], 'note' => '']],
        ]);
        $session = $auth->resolve($auth->login('zaq1234', 'CASHIER')['token'], ['CASHIER']);
        $input = ['sessionId' => $submit['SessionID'], 'method' => 'CASH', 'reference' => '', 'idempotencyKey' => 'p1'];
        $a = $pay->closeTable($session, $input);
        $b = $pay->closeTable($session, $input);
        $this->assertSame($a['payment']['PaymentID'], $b['payment']['PaymentID']);
    }
}
