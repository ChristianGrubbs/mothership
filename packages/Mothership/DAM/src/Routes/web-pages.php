<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\PublicShare\SharedViewerController;
use Mothership\DAM\Http\Controllers\WebPageController;
use Mothership\DAM\Http\Middleware\EnforceShareGate;
use Mothership\DAM\Http\Middleware\WebPageHeaders;

// Session-free by design (ADR 0007 amendment): an opaque-origin page cannot send the session cookie.
Route::middleware(['throttle:dam-page-file', WebPageHeaders::class.':admin'])
    ->prefix(config('app.admin_url').'/dam/pages')
    ->group(function () {
        Route::get('{id}/g/{grant}/{path?}', [WebPageController::class, 'file'])
            // {grant} is deliberately permissive: a malformed grant must reach the controller
            // and 404 through WebPageHeaders rather than miss the route and answer without the
            // page policy. PageGrant::verify() is the shape check.
            ->where(['id' => '[0-9]+', 'grant' => '[^/]+', 'path' => '.*'])
            ->name('admin.dam.pages.file');
    });

// The public share page family. Session-free for the same reason as the admin family, and
// deliberately outside the `web` group: the share token in the path is the whole capability,
// so no session, no CSRF token and no Set-Cookie may ride along with a page's own requests.
Route::middleware(['throttle:dam-share-page', WebPageHeaders::class.':public'])
    ->prefix('share')
    ->group(function () {
        Route::get('{token}/page/{path?}', [SharedViewerController::class, 'page'])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'path' => '.*'])
            ->middleware(EnforceShareGate::class)
            ->name('dam.share.page');

        // P2 S5b: a password-protected link's page frame. The sandboxed frame cannot send the unlock cookie, so a
        // signed, short-lived grant in the path stands in for it (ShareGate::pageGrant()).
        Route::get('{token}/pg/{grant}/{path?}', [SharedViewerController::class, 'pageGranted'])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'grant' => '[0-9]{1,12}\.[0-9a-f]{64}', 'path' => '.*'])
            ->middleware(EnforceShareGate::class)
            ->name('dam.share.page_granted');

        // One stack member's page. The pin keeps the whole identity in the path, so every relative request of the
        // sandboxed page carries it: {asset}.{attachment}.{file}.{FULL 64 hex checksum}. Distinct prefixes (`pf`, `pgf`),
        // never an alias of `page`/`pg`; neither is a download route, so a preview-only link renders it too.
        Route::get('{token}/pf/{pin}/{path?}', [SharedViewerController::class, 'memberPage'])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'pin' => '[0-9]{1,18}\.[0-9]{1,18}\.[0-9]{1,18}\.[0-9a-f]{64}', 'path' => '.*'])
            ->middleware(EnforceShareGate::class)
            ->name('dam.share.page_file');

        Route::get('{token}/pgf/{grant}/{pin}/{path?}', [SharedViewerController::class, 'memberPageGranted'])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'grant' => '[0-9]{1,12}\.[0-9a-f]{64}', 'pin' => '[0-9]{1,18}\.[0-9]{1,18}\.[0-9]{1,18}\.[0-9a-f]{64}', 'path' => '.*'])
            ->middleware(EnforceShareGate::class)
            ->name('dam.share.page_file_granted');
    });
