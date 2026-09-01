<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OpsController;
use Illuminate\Support\Facades\Route;

// Public (customer) — no auth, throttled per IP.
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/bootstrap', [CustomerController::class, 'bootstrap']);
    Route::post('/customer', [CustomerController::class, 'customer']);
    Route::post('/order/status', [CustomerController::class, 'status']);
    Route::post('/order/submit', [CustomerController::class, 'submit'])->middleware('throttle:30,1');
    Route::post('/call', [CustomerController::class, 'call'])->middleware('throttle:30,1');
});

// Auth
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/auth/change-pin', [AuthController::class, 'changePin']);

// Ops (role-gated via StaffAuth reading body token)
Route::post('/ops/dashboard', [OpsController::class, 'dashboard'])->middleware('staff.auth:KITCHEN,STAFF,CASHIER,ADMIN');
Route::post('/ops/order-status', [OpsController::class, 'orderStatus'])->middleware('staff.auth:KITCHEN,STAFF');
Route::post('/ops/call-status', [OpsController::class, 'callStatus'])->middleware('staff.auth:STAFF,CASHIER');
Route::post('/ops/close-table', [OpsController::class, 'closeTable'])->middleware('staff.auth:CASHIER');

// Admin (ADMIN only)
Route::middleware('staff.auth:ADMIN')->group(function () {
    Route::post('/admin/data', [AdminController::class, 'data']);
    Route::post('/admin/settings', [AdminController::class, 'settings']);
    Route::post('/admin/entity', [AdminController::class, 'entity']);
    Route::post('/admin/entity/archive', [AdminController::class, 'entityArchive']);
    Route::post('/admin/table/rotate-token', [AdminController::class, 'rotateToken']);
});
