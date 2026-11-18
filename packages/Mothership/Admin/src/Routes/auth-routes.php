<?php

use Illuminate\Support\Facades\Route;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Admin\Http\Controllers\User\AvatarController;
use Mothership\Admin\Http\Controllers\User\ForgetPasswordController;
use Mothership\Admin\Http\Controllers\User\InviteAcceptController;
use Mothership\Admin\Http\Controllers\User\ResetPasswordController;
use Mothership\Admin\Http\Controllers\User\SessionController;
use Mothership\Admin\Http\Controllers\User\SsoController;
use Mothership\Admin\Http\Middleware\EnsurePasswordLogin;
use Mothership\Admin\Http\Middleware\SignalLoginLockout;

Route::get('/', [Controller::class, 'redirectToLogin']);

/**
 * Auth routes.
 */
Route::group(['prefix' => config('app.admin_url')], function () {
    /**
     * Public avatar proxy route (no auth middleware).
     */
    Route::get('avatar/u/{hash}', [AvatarController::class, 'gravatar'])
        ->where('hash', '[a-f0-9]{32}')
        ->name('admin.avatar.public')
        ->middleware('throttle:120,1');

    /**
     * Redirect route.
     */
    Route::get('/', [Controller::class, 'redirectToLogin']);

    Route::prefix('login')->group(function () {
        Route::controller(SessionController::class)->group(function () {
            /**
             * Login routes.
             */
            Route::get('', 'create')->name('admin.session.create');

            /**
             * Login post route to admin auth controller.
             */
            Route::post('', 'store')->name('admin.session.store')->middleware([EnsurePasswordLogin::class, SignalLoginLockout::class, 'throttle:admin-login']);
        });

        Route::controller(SsoController::class)->middleware('throttle:admin-sso')->group(function () {
            /**
             * SSO routes for registered drivers.
             */
            Route::get('pending', 'pending')->name('admin.session.sso.pending');

            Route::get('sso/{provider}', 'redirect')
                ->where('provider', '[a-z0-9_-]+')
                ->name('admin.session.sso.redirect');

            Route::get('sso/{provider}/callback', 'callback')
                ->where('provider', '[a-z0-9_-]+')
                ->name('admin.session.sso.callback');
        });
    });

    /**
     * Forget password routes.
     */
    Route::controller(ForgetPasswordController::class)->middleware(EnsurePasswordLogin::class)->prefix('forget-password')->group(function () {
        Route::get('', 'create')->name('admin.forget_password.create');

        Route::post('', 'store')->name('admin.forget_password.store')->middleware('throttle:admin-forgot-password');
    });

    /**
     * Accept an invitation (set a first password).
     */
    Route::controller(InviteAcceptController::class)->middleware(EnsurePasswordLogin::class)->prefix('invite')->group(function () {
        Route::get('', 'create')->name('admin.invite.accept.create');

        Route::get('{token}', 'legacyLink');

        Route::post('', 'store')->name('admin.invite.accept.store')->middleware('throttle:admin-invite-accept');
    });

    /**
     * Reset password routes.
     */
    Route::controller(ResetPasswordController::class)->middleware(EnsurePasswordLogin::class)->prefix('reset-password')->group(function () {
        Route::get('', 'create')->name('admin.reset_password.create');

        Route::get('{token}', 'legacyLink');

        Route::post('', 'store')->name('admin.reset_password.store')->middleware('throttle:admin-reset-password');
    });
});
