<?php

use App\Http\Middleware\StaffAuth;
use App\Pos\Support\AppError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['staff.auth' => StaffAuth::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* error renders the {ok,data,error} envelope with HTTP 200,
        // matching the frontend ApiClient (back.md §6, App.html:113).
        $exceptions->render(function (Throwable $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($e instanceof AppError) {
                return response()->json(['ok' => false, 'error' => [
                    'code' => $e->errCode, 'message' => $e->getMessage(), 'details' => $e->details,
                ]], 200);
            }
            if ($e instanceof ValidationException) {
                return response()->json(['ok' => false, 'error' => [
                    'code' => 'VALIDATION', 'message' => $e->validator->errors()->first(), 'details' => $e->errors(),
                ]], 200);
            }
            // Rate limit hit: a controlled, expected outcome — do NOT report() it
            // as a server error (that hid the throttle behind SERVER_ERROR).
            if ($e instanceof ThrottleRequestsException) {
                $retry = $e->getHeaders()['Retry-After'] ?? null;

                return response()->json(['ok' => false, 'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'เรียกใช้งานบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่',
                    'details' => $retry !== null ? ['retryAfter' => (int) $retry] : null,
                ]], 200);
            }
            report($e);

            return response()->json(['ok' => false, 'error' => [
                'code' => 'SERVER_ERROR', 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่',
            ]], 200);
        });
    })->create();
