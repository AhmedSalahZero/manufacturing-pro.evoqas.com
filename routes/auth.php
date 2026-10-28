<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Sign-in routes
//  Location: routes/auth.php
//
//  There is no public registration: accounts are created by an administrator.
// ══════════════════════════════════════════════════════════════════

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:60,1,login');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1,password-email')->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:10,1,password-reset')->name('password.store');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
