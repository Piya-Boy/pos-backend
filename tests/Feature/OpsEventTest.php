<?php

namespace Tests\Feature;

use App\Events\OpsEvent;
use App\Pos\Services\OrderService;
use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetsClient;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OpsEventTest extends TestCase
{
    public function test_submit_broadcasts_ops_event_with_table_token(): void
    {
        Event::fake([OpsEvent::class]);
        $fake = (new FakeSheetsClient)->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        $token = $fake->firstTableToken();

        $this->app->make(OrderService::class)->submit([
            'tableToken' => $token,
            'idempotencyKey' => 'ev1',
            'promoCode' => '',
            'items' => [['itemId' => 'M006', 'qty' => 1, 'optionIds' => [], 'addOnIds' => [], 'note' => '']],
        ]);

        Event::assertDispatched(OpsEvent::class, fn (OpsEvent $e) => $e->type === 'ORDER_SUBMITTED' && $e->tableToken === $token);
    }

    public function test_event_broadcasts_on_ops_and_table_channels(): void
    {
        $event = new OpsEvent('ORDER_SUBMITTED', 'tbl_abc');
        $names = array_map(fn ($c) => $c->name, $event->broadcastOn());

        $this->assertContains('pos-ops', $names);
        $this->assertContains('pos-table.tbl_abc', $names);
        $this->assertSame('ops.event', $event->broadcastAs());
        $this->assertSame(['type' => 'ORDER_SUBMITTED'], $event->broadcastWith());
    }

    public function test_event_without_token_only_ops_channel(): void
    {
        $event = new OpsEvent('CALL_STATUS');
        $names = array_map(fn ($c) => $c->name, $event->broadcastOn());

        $this->assertSame(['pos-ops'], $names);
    }
}
