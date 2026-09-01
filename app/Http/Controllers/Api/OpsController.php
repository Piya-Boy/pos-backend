<?php

namespace App\Http\Controllers\Api;

use App\Pos\Services\OpsService;
use App\Pos\Services\PaymentService;
use Illuminate\Http\Request;

use function App\Pos\Support\apiOk;

class OpsController
{
    public function __construct(
        private OpsService $ops,
        private PaymentService $payments,
    ) {}

    private function user(Request $r): array
    {
        return (array) $r->attributes->get('authUser');
    }

    public function dashboard(Request $r)
    {
        return response()->json(apiOk($this->ops->dashboard($this->user($r), (string) $r->input('view', 'KITCHEN'))));
    }

    public function orderStatus(Request $r)
    {
        return response()->json(apiOk($this->ops->updateOrderItem($this->user($r), $r->all())));
    }

    public function callStatus(Request $r)
    {
        return response()->json(apiOk($this->ops->updateCall($this->user($r), $r->all())));
    }

    public function closeTable(Request $r)
    {
        return response()->json(apiOk($this->payments->closeTable($this->user($r), $r->all())));
    }
}
