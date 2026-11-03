<?php

use App\Http\Controllers\Hr\DashboardController;
use App\Http\Controllers\Hr\HrUserController;
use App\Http\Controllers\Hr\TwoFactorSetupController;
use App\Http\Middleware\EnsureHrUserIsActive;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

/*
|--------------------------------------------------------------------------
| HR area
|--------------------------------------------------------------------------
| Fortify registers /hr/login, /hr/two-factor-challenge, /hr/forgot-password,
| /hr/reset-password, /hr/user/confirm-password and the /hr/user/two-factor-*
| endpoints (see config/fortify.php).
*/

Route::prefix('hr')->name('hr.')->middleware(['auth', EnsureHrUserIsActive::class])->group(function () {
    // Reachable before 2FA is enabled, so new users can enrol.
    Route::get('security/two-factor', TwoFactorSetupController::class)
        ->middleware('password.confirm')
        ->name('security.two-factor');

    Route::middleware(EnsureTwoFactorEnabled::class)->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('users', [HrUserController::class, 'index'])->name('users.index');
        Route::get('users/create', [HrUserController::class, 'create'])->name('users.create');
        Route::post('users', [HrUserController::class, 'store'])
            ->middleware('password.confirm')
            ->name('users.store');
        Route::post('users/{user}/disable', [HrUserController::class, 'disable'])
            ->middleware('password.confirm')
            ->name('users.disable');
        Route::post('users/{user}/enable', [HrUserController::class, 'enable'])
            ->middleware('password.confirm')
            ->name('users.enable');
    });
});
