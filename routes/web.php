<?php

use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded via the "web" group configured in bootstrap/app.php.
| Mothership's own routes are registered by each Concord package provider.
|
*/

// Public installation metadata and generic connection help; never user or library content.
Route::withoutMiddleware('web')->group(function () {
    Route::get('app.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
    Route::get('pwa/offline', [PwaController::class, 'offline'])->name('pwa.offline');
});
