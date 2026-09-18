<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::post('wallets/{wallet}/deposit-address', [WalletController::class, 'address'])
    ->whereNumber('wallet')->middleware(['auth:sanctum', 'throttle:wallets']);
