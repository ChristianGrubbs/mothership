<?php

use Illuminate\Support\Facades\Route;
use Mothership\SphereSeam\Http\Controllers\SphereSeamController;

Route::group(['middleware' => ['admin'], 'prefix' => config('app.admin_url')], function (): void {
    Route::prefix('sphere-seam')->name('sphereseam.')->group(function (): void {
        Route::get('/', [SphereSeamController::class, 'index'])->name('index');
        // No file extension: nginx serves *.js paths as static files before PHP sees them.
        Route::get('/script', [SphereSeamController::class, 'script'])->name('script');
        Route::get('/history', [SphereSeamController::class, 'history'])->name('history');
        Route::post('/assets/resolve', [SphereSeamController::class, 'resolveAssets'])->name('assets.resolve');
        Route::get('/assets/{asset}/source', [SphereSeamController::class, 'assetSource'])->name('assets.source');
        Route::post('/estimate', [SphereSeamController::class, 'estimate'])->middleware('throttle:60,1')->name('estimate');
        Route::post('/generate', [SphereSeamController::class, 'generate'])->middleware('throttle:20,1')->name('generate');
        Route::get('/generations/{generation}', [SphereSeamController::class, 'status'])->name('status');
        Route::get('/generations/{generation}/normalized', [SphereSeamController::class, 'normalized'])->name('normalized');
        Route::get('/generations/{generation}/bridge', [SphereSeamController::class, 'bridge'])->name('bridge');
        Route::post('/generations/{generation}/retry', [SphereSeamController::class, 'retry'])->middleware('throttle:20,1')->name('retry');
        Route::post('/generations/{generation}/import', [SphereSeamController::class, 'import'])->middleware('throttle:20,1')->name('import');
    });
});
