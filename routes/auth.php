<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorEmailRecoveryController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

// No sign-up page: anyone who could reach it got a cashier account (the role's default). The owner
// adds staff in User Management.
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
                ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // Two-step verification: after the right password, the code from the authenticator app.
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
                ->name('two-factor.login');

    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store']);

    // Getting back in by email, to an address confirmed in Profile (only once email is set up on
    // the server, App\Support\EmailRecovery): a forgotten password, or a lost phone.
    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
                ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
                ->middleware('throttle:6,1,forgot-password')
                ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
                ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
                ->middleware('throttle:6,1,reset-password')
                ->name('password.store');

    Route::post('two-factor-challenge/email', [TwoFactorEmailRecoveryController::class, 'send'])
                ->middleware('throttle:6,1,two-factor-email')
                ->name('two-factor.email');
});

// The emailed lost-phone link. Not guest-only: opened while logged in, it says so instead of
// sending the browser away without a word.
Route::get('two-factor-recover/{user}', [TwoFactorEmailRecoveryController::class, 'recover'])
            ->middleware('throttle:6,1,two-factor-recover')
            ->name('two-factor.recover');

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
                ->name('verification.notice');

    // Signed link, checked by the controller (an expired one says so in Profile).
    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
                ->middleware('throttle:6,1')
                ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
                ->middleware('throttle:6,1')
                ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
                ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
                ->name('logout');
});
