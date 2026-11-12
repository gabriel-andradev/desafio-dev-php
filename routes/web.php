<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('wallet.dashboard')
        : redirect()->route('login');
});

Route::get('/api/health', HealthController::class)->name('health');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('auth.login');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('auth.register');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::get('/dashboard', [WalletController::class, 'dashboard'])->name('wallet.dashboard');
    Route::middleware('throttle:wallet')->group(function (): void {
        Route::post('/wallet/deposit', [WalletController::class, 'deposit'])->name('wallet.deposit');
        Route::post('/wallet/transfer', [WalletController::class, 'transfer'])->name('wallet.transfer');
        /** A non-numeric id is a 404 instead of a TypeError (500) in the controller. */
        Route::post('/wallet/transactions/{transaction}/reverse', [WalletController::class, 'reverse'])
            ->whereNumber('transaction')
            ->name('wallet.reverse');
    });
});
