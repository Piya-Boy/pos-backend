<?php

namespace App\Http\Middleware;

use App\Pos\Services\AuthService;
use Closure;
use Illuminate\Http\Request;

// / Resolves the body `token` to a staff session, enforcing route-declared roles.
// / Usage: ->middleware('staff.auth:KITCHEN,STAFF'). Sets request attribute authUser.
class StaffAuth
{
    public function __construct(private AuthService $auth) {}

    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $token = (string) $request->input('token', '');
        $session = $this->auth->resolve($token, $roles); // throws AppError -> envelope
        $request->attributes->set('authUser', $session);

        return $next($request);
    }
}
