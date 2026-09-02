<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// / E1 realtime: one lightweight event fired after any order/call/session write.
// / Sheet-as-DB has no model observers, so services dispatch this by hand.
// / ShouldBroadcastNow => broadcast synchronously (no queue worker required).
//
// / Channels:
// /  - `pos-ops`            : all staff displays (kitchen/cashier/staff) — nudge to refresh.
// /  - `pos-table.{token}`  : the affected table's customer view (optional; set when known).
//
// / Payload is intentionally minimal ({type, tableToken?}); clients receive the
// / event as a signal and re-fetch the authoritative slice over REST. This keeps
// / the socket path free of business data and channel auth simple (public).
class OpsEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $type  e.g. ORDER_SUBMITTED, ITEM_STATUS, CALL, SESSION_CLOSED
     * @param  string  $tableToken  affected table token, or '' if not table-scoped
     */
    public function __construct(
        public string $type,
        public string $tableToken = '',
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $channels = [new Channel('pos-ops')];
        if ($this->tableToken !== '') {
            $channels[] = new Channel('pos-table.'.$this->tableToken);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ops.event';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['type' => $this->type];
    }
}
