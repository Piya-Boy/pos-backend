<?php

namespace Tests\Feature;

use App\Pos\Services\OrderService;
use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetsClient;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    private function bindFake(): FakeSheetsClient
    {
        $fake = (new FakeSheetsClient)->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);

        return $fake;
    }

    public function test_submit_is_idempotent(): void
    {
        $fake = $this->bindFake();
        $svc = $this->app->make(OrderService::class);
        $input = [
            'tableToken' => $fake->firstTableToken(),
            'idempotencyKey' => 'k1',
            'promoCode' => '',
            'items' => [['itemId' => 'M006', 'qty' => 1, 'optionIds' => [], 'addOnIds' => [], 'note' => '']],
        ];
        $a = $svc->submit($input);
        $b = $svc->submit($input);
        $this->assertSame($a['SessionID'], $b['SessionID']);
        $this->assertSame(55.0, $a['totals']['total']); // ชาไทยเย็น 55
    }

    public function test_submit_requires_option(): void
    {
        $fake = $this->bindFake();
        $svc = $this->app->make(OrderService::class);
        // M001 has required RADIO group ระดับความเผ็ด; sending none must fail
        $this->expectExceptionMessage('กรุณาเลือก');
        $svc->submit([
            'tableToken' => $fake->firstTableToken(),
            'idempotencyKey' => 'k2',
            'promoCode' => '',
            'items' => [['itemId' => 'M001', 'qty' => 1, 'optionIds' => [], 'addOnIds' => [], 'note' => '']],
        ]);
    }

    public function test_call_staff_bill_without_session_fails(): void
    {
        $fake = $this->bindFake();
        $svc = $this->app->make(OrderService::class);
        $this->expectExceptionMessage('ยังไม่มีออเดอร์');
        $svc->callStaff([
            'tableToken' => $fake->firstTableToken(),
            'type' => 'BILL',
            'idempotencyKey' => 'c1',
        ]);
    }
}
