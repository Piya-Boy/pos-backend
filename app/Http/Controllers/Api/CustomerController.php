<?php

namespace App\Http\Controllers\Api;

use App\Pos\Services\CatalogService;
use App\Pos\Services\OrderService;
use App\Pos\Services\SettingsService;
use Illuminate\Http\Request;

use function App\Pos\Support\apiOk;
use function App\Pos\Support\strInput;

class CustomerController
{
    public function __construct(
        private SettingsService $settings,
        private CatalogService $catalog,
        private OrderService $orders,
    ) {}

    public function bootstrap(Request $r)
    {
        $token = strInput($r->input('tableToken'));
        $out = $this->settings->bootstrap($token);
        if ($token !== '') {
            $out['customer'] = $this->catalog->customerData($token);
        }

        return response()->json(apiOk($out));
    }

    public function customer(Request $r)
    {
        return response()->json(apiOk($this->catalog->customerData(strInput($r->input('tableToken')))));
    }

    public function submit(Request $r)
    {
        return response()->json(apiOk($this->orders->submit($r->all())));
    }

    public function status(Request $r)
    {
        return response()->json(apiOk($this->orders->status(
            strInput($r->input('tableToken')),
            strInput($r->input('sessionId')),
        )));
    }

    public function call(Request $r)
    {
        return response()->json(apiOk($this->orders->callStaff($r->all())));
    }
}
