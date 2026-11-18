<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\PublicUpload\UploadPortalController;
use Mothership\DAM\Http\Middleware\EnforceUploadPortal;

// P5a (D1): the guest upload portal. Its own prefix, outside every admin, Library, share and REST group: the only
// routes that read an upload-request token or its visit cookie. The page and its password form are throttled per IP
// and per link (`dam-upload-portal`); the chunk calls, behind EnforceUploadPortal, on a larger budget
// (`dam-upload-portal-chunk`). No route here previews or downloads a file.
Route::middleware('web')
    ->prefix('u')
    ->where(['token' => '[A-Za-z0-9]{20,64}'])
    ->group(function () {
        Route::get('{token}', [UploadPortalController::class, 'show'])
            ->name('dam.upload_portal.show')
            ->middleware('throttle:dam-upload-portal');

        Route::post('{token}/unlock', [UploadPortalController::class, 'unlock'])
            ->name('dam.upload_portal.unlock')
            ->middleware('throttle:dam-upload-portal');

        Route::middleware(['throttle:dam-upload-portal-chunk', EnforceUploadPortal::class])->group(function () {
            Route::post('{token}/uploads', [UploadPortalController::class, 'initialize'])
                ->name('dam.upload_portal.initialize');

            Route::get('{token}/uploads/{uuid}', [UploadPortalController::class, 'status'])
                ->name('dam.upload_portal.status')
                ->whereUuid('uuid');

            Route::put('{token}/uploads/{uuid}/chunks/{index}', [UploadPortalController::class, 'store'])
                ->name('dam.upload_portal.store')
                ->whereUuid('uuid')
                ->whereNumber('index');

            Route::post('{token}/uploads/{uuid}/complete', [UploadPortalController::class, 'complete'])
                ->name('dam.upload_portal.complete')
                ->whereUuid('uuid');

            Route::delete('{token}/uploads/{uuid}', [UploadPortalController::class, 'cancel'])
                ->name('dam.upload_portal.cancel')
                ->whereUuid('uuid');
        });
    });
