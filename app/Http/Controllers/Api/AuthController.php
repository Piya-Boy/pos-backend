<?php

namespace App\Http\Controllers\Api;

use App\Pos\Services\AuthService;
use Illuminate\Http\Request;

use function App\Pos\Support\apiOk;

class AuthController
{
    public function __construct(private AuthService $auth) {}

    public function login(Request $r)
    {
        return response()->json(apiOk($this->auth->login(
            (string) $r->input('pin', ''),
            (string) $r->input('expectedRole', ''),
        )));
    }

    public function logout(Request $r)
    {
        return response()->json(apiOk($this->auth->logout((string) $r->input('token', ''))));
    }

    public function changePin(Request $r)
    {
        $session = $this->auth->resolve((string) $r->input('token', ''));

        return response()->json(apiOk($this->auth->changePin($session, (string) $r->input('newPin', ''))));
    }
}
