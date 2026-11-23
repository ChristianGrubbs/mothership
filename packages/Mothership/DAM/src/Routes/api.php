<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\API\Asset\AssetController;

// Every `{id}` here is an auto-increment key: a non-numeric one is a 404, not a TypeError 500 (FAULT-11).
Route::group([
    'prefix'     => 'v1/rest',
    'middleware' => [
        'auth:api',
        'api.scope',
        'accept.json',
        'request.locale',
    ],
    'where'      => ['id' => '[0-9]+'],
], function () {
    require 'V1/asset-routes.php';
    require 'V1/organization-routes.php';
    require 'V1/library-tool-routes.php';
});

Route::group([
    'prefix'     => 'v1/rest',
    'middleware' => ['signed'],
    'where'      => ['id' => '[0-9]+'],
], function () {
    Route::get('assets/signUrlDownload/{id}', [AssetController::class, 'signedUrl'])
        ->name('admin.api.dam.assets.private.download');
});
