<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\AnalyticsIngestController;
use Mothership\DAM\Http\Controllers\PublicShare\CollectionSlugController;
use Mothership\DAM\Http\Controllers\PublicShare\SharedViewerController;
use Mothership\DAM\Http\Controllers\PublicShare\ShareSlugController;
use Mothership\DAM\Http\Controllers\PublicShare\ShareUnlockController;
use Mothership\DAM\Http\Middleware\EnforceShareGate;
use Mothership\DAM\Http\Middleware\SignalDownloadStart;

// P2 S5b: EnforceShareGate runs on every share-scoped route after its throttle, ahead of the controller: the password
// cookie and the download-off rule. The unlock form is the one route outside it.
Route::middleware('web')
    ->prefix('share')
    ->group(function () {
        // The share pages' collector (P1b, §3.6.6). `t` cannot be a link token (20-64 characters). The signed,
        // link-bound capability in the body stands in for the CSRF token, which a beacon cannot send.
        Route::post('t', [AnalyticsIngestController::class, 'public'])->name('dam.analytics.ingest')
            ->withoutMiddleware(PreventRequestForgery::class)
            ->middleware('throttle:dam-telemetry');

        Route::get('{token}', [SharedViewerController::class, 'show'])
            ->name('dam.share.show')
            ->middleware(['throttle:dam-share-view', EnforceShareGate::class])
            ->where('token', '[A-Za-z0-9]{20,64}');

        Route::get('{token}/download', [SharedViewerController::class, 'download'])
            ->name('dam.share.download')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class, SignalDownloadStart::class])
            ->where('token', '[A-Za-z0-9]{20,64}');

        // One of the asset's shared files by its slot: the default file or an alternate format, never a fallback.
        Route::get('{token}/download/{attachment}', [SharedViewerController::class, 'download'])
            ->name('dam.share.file_download')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class, SignalDownloadStart::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'attachment' => '[0-9]{1,18}']);

        Route::get('{token}/asset/{assetId}', [SharedViewerController::class, 'assetView'])
            ->name('dam.share.asset_view')
            ->middleware(['throttle:dam-share-view', EnforceShareGate::class])
            ->where('token', '[A-Za-z0-9]{20,64}')
            ->where('assetId', '[0-9]+');

        Route::get('{token}/asset/{assetId}/download', [SharedViewerController::class, 'assetDownload'])
            ->name('dam.share.asset_download')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class, SignalDownloadStart::class])
            ->where('token', '[A-Za-z0-9]{20,64}')
            ->where('assetId', '[0-9]+');

        Route::get('{token}/asset/{assetId}/download/{attachment}', [SharedViewerController::class, 'assetDownload'])
            ->name('dam.share.asset_file_download')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class, SignalDownloadStart::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'assetId' => '[0-9]+', 'attachment' => '[0-9]{1,18}']);

        // OD5: one shared file's picture and in-page preview (SharedFilePreview). A still is a derivative; video, audio and
        // PDF are the original inline, refused (403) by the controller while the link is preview-only. Not download
        // routes, so a preview-only link still shows its stills.
        Route::get('{token}/file/{attachment}/thumb', [SharedViewerController::class, 'fileThumbnail'])
            ->name('dam.share.file_thumbnail')
            ->middleware(['throttle:dam-share-thumb', EnforceShareGate::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'attachment' => '[0-9]{1,18}']);

        Route::get('{token}/file/{attachment}/preview', [SharedViewerController::class, 'filePreview'])
            ->name('dam.share.file_preview')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'attachment' => '[0-9]{1,18}']);

        Route::get('{token}/asset/{assetId}/file/{attachment}/thumb', [SharedViewerController::class, 'assetFileThumbnail'])
            ->name('dam.share.asset_file_thumbnail')
            ->middleware(['throttle:dam-share-thumb', EnforceShareGate::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'assetId' => '[0-9]+', 'attachment' => '[0-9]{1,18}']);

        Route::get('{token}/asset/{assetId}/file/{attachment}/preview', [SharedViewerController::class, 'assetFilePreview'])
            ->name('dam.share.asset_file_preview')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'assetId' => '[0-9]+', 'attachment' => '[0-9]{1,18}']);

        // OD5: every file of one item as a zip: a download route (ShareGate::DOWNLOAD_ROUTES), 403 on a preview-only link.
        Route::get('{token}/files-zip', [SharedViewerController::class, 'filesZip'])
            ->name('dam.share.files_zip')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class, SignalDownloadStart::class])
            ->where('token', '[A-Za-z0-9]{20,64}');

        Route::get('{token}/asset/{assetId}/files-zip', [SharedViewerController::class, 'assetFilesZip'])
            ->name('dam.share.asset_files_zip')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class, SignalDownloadStart::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'assetId' => '[0-9]+']);

        Route::get('{token}/thumb/{assetId}', [SharedViewerController::class, 'thumbnail'])
            ->name('dam.share.thumbnail')
            ->middleware(['throttle:dam-share-thumb', EnforceShareGate::class])
            ->where('token', '[A-Za-z0-9]{20,64}')
            ->where('assetId', '[0-9]+');

        Route::get('{token}/download-zip', [SharedViewerController::class, 'downloadZip'])
            ->name('dam.share.download_zip')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class, SignalDownloadStart::class])
            ->where('token', '[A-Za-z0-9]{20,64}');

        Route::get('{token}/assets', [SharedViewerController::class, 'listAssets'])
            ->name('dam.share.list_assets')
            ->middleware(['throttle:dam-share-view', EnforceShareGate::class])
            ->where('token', '[A-Za-z0-9]{20,64}');
        // P2 S5b: the password prompt posts here. Wrong passwords are throttled per link and visitor in ShareGate.
        Route::post('{token}/unlock', [ShareUnlockController::class, 'unlock'])
            ->name('dam.share.unlock')
            ->middleware('throttle:dam-share-view')
            ->where('token', '[A-Za-z0-9]{20,64}');

        // P2 S5b: the original shown inline, kept apart from the download routes so a preview-only link still shows it.
        Route::get('{token}/preview', [SharedViewerController::class, 'preview'])
            ->name('dam.share.preview')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class])
            ->where('token', '[A-Za-z0-9]{20,64}');

        Route::get('{token}/asset/{assetId}/preview', [SharedViewerController::class, 'assetPreview'])
            ->name('dam.share.asset_preview')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'assetId' => '[0-9]+']);

        // The stage's picture: a screen-sized copy of a large still, else preview()'s own answer (SharedViewerController::display()).
        // Not a download route, so a preview-only link still shows it.
        Route::get('{token}/display', [SharedViewerController::class, 'display'])
            ->name('dam.share.display')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class])
            ->where('token', '[A-Za-z0-9]{20,64}');

        Route::get('{token}/asset/{assetId}/display', [SharedViewerController::class, 'assetDisplay'])
            ->name('dam.share.asset_display')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'assetId' => '[0-9]+']);

        // P2 S6: the published video proxy the page's player streams.
        Route::get('{token}/proxy', [SharedViewerController::class, 'proxy'])
            ->name('dam.share.proxy')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class])
            ->where('token', '[A-Za-z0-9]{20,64}');

        Route::get('{token}/asset/{assetId}/proxy', [SharedViewerController::class, 'assetProxy'])
            ->name('dam.share.asset_proxy')
            ->middleware(['throttle:dam-share-download', EnforceShareGate::class])
            ->where(['token' => '[A-Za-z0-9]{20,64}', 'assetId' => '[0-9]+']);
    });

// P4 D23: a collection's human URL. Cosmetic for a public audience (it leads to the collection's link, gated as every
// link is); for any other audience it needs a signed-in audience member. Unknown and unopenable slugs answer alike.
Route::middleware(['web', 'throttle:dam-share-view'])
    ->get('c/{slug}', [CollectionSlugController::class, 'open'])
    ->name('dam.collections.slug')
    ->where('slug', '[A-Za-z0-9-]{1,64}');

// P4 D23: a share link's custom address. An alias that leads to the link's token URL while the link opens, so the
// token path's own checks all apply; an unknown, revoked, expired or retired slug answers as an unknown token does.
Route::middleware(['web', 'throttle:dam-share-view'])
    ->get('s/{slug}', [ShareSlugController::class, 'open'])
    ->name('dam.share.slug')
    ->where('slug', '[A-Za-z0-9-]{1,64}');
