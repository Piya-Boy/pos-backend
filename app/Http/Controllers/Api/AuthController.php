<?php

namespace App\Http\Controllers\Api;

use App\Pos\Services\AuthService;
use Illuminate\Http\Request;

use function App\Pos\Support\apiOk;
use function App\Pos\Support\strInput;

class AuthController
{
    public function __construct(private AuthService $auth) {}

    public function login(Request $r)
    {
        return response()->json(apiOk($this->auth->login(
            strInput($r->input('pin')),
            strInput($r->input('expectedRole')),
        )));
    }

    public function logout(Request $r)
    {
        return response()->json(apiOk($this->auth->logout(strInput($r->input('token')))));
    }

    public function changePin(Request $r)
    {
        $session = $this->auth->resolve(strInput($r->input('token')));

        return response()->json(apiOk($this->auth->changePin($session, strInput($r->input('newPin')))));
    }
}
