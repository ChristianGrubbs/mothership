<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\HomeCalendarController;
use Mothership\DAM\Http\Controllers\HomeController;
use Mothership\DAM\Http\Controllers\HomeFavoriteController;
use Mothership\DAM\Http\Controllers\HomePlaqueController;
use Mothership\DAM\Http\Controllers\HomeServiceController;
use Mothership\DAM\Http\Controllers\HomeStylePressController;
use Mothership\DAM\Http\Controllers\HomeToolController;
use Mothership\DAM\Http\Controllers\HomeVectorizationController;
use Mothership\DAM\Http\Controllers\HomeVideoController;
use Mothership\DAM\Http\Controllers\HomeWidgetAccessController;
use Mothership\DAM\Http\Controllers\VectorizationAllowanceController;
use Mothership\DAM\Http\Middleware\ManageFolderAccess;

Route::get('', [HomeController::class, 'index'])->name('admin.home.index');
Route::get('layout', [HomeController::class, 'layout'])->name('admin.home.layout');
Route::get('data/{widget}', [HomeController::class, 'data'])->name('admin.home.data');
// Superuser-only: the Home right-click menu that toggles which custom roles get a tool on Home.
Route::get('widget-access/{widget}', [HomeWidgetAccessController::class, 'index'])
    ->middleware(ManageFolderAccess::class)->name('admin.home.widget-access.index');
Route::put('widget-access/{widget}', [HomeWidgetAccessController::class, 'update'])
    ->middleware(ManageFolderAccess::class)->name('admin.home.widget-access.update');
Route::match(['GET', 'PUT'], 'vectorization/allowances/{admin}', [VectorizationAllowanceController::class, 'show'])
    ->whereNumber('admin')->name('admin.home.vectorization.allowance');
Route::get('vectorization', [HomeVectorizationController::class, 'index'])->name('admin.home.vectorization.index');
Route::get('vectorization/state', [HomeVectorizationController::class, 'state'])->name('admin.home.vectorization.state');
Route::post('vectorization', [HomeVectorizationController::class, 'store'])->middleware('throttle:10,1')->name('admin.home.vectorization.store');
Route::get('vectorization/results/{id}', [HomeVectorizationController::class, 'show'])->whereUuid('id')->name('admin.home.vectorization.show');
Route::get('vectorization/results/{id}/{format}', [HomeVectorizationController::class, 'file'])->whereUuid('id')->whereIn('format', ['svg', 'pdf'])->name('admin.home.vectorization.file');
// The Home calendar (docs/features/event-calendar.md).
Route::controller(HomeCalendarController::class)->prefix('calendar')->group(function () {
    Route::get('events', 'events')->name('admin.home.calendar.events');
    Route::post('events', 'store')->name('admin.home.calendar.events.store');
    Route::get('events/{event}', 'show')->whereNumber('event')->name('admin.home.calendar.events.show');
    Route::patch('events/{event}', 'update')->whereNumber('event')->name('admin.home.calendar.events.update');
    Route::delete('events/{event}', 'destroy')->whereNumber('event')->name('admin.home.calendar.events.destroy');
    Route::post('events/{event}/tasks', 'storeTask')->whereNumber('event')->name('admin.home.calendar.tasks.store');
    Route::patch('tasks/{task}', 'updateTask')->whereNumber('task')->name('admin.home.calendar.tasks.update');
    Route::delete('tasks/{task}', 'destroyTask')->whereNumber('task')->name('admin.home.calendar.tasks.destroy');
    Route::post('events/{event}/assets', 'linkAssets')->whereNumber('event')->name('admin.home.calendar.assets.link');
    Route::delete('assets/{link}', 'unlinkAsset')->whereNumber('link')->name('admin.home.calendar.assets.unlink');
    Route::get('mine', 'mine')->name('admin.home.calendar.mine');
    Route::get('people', 'people')->name('admin.home.calendar.people');
    Route::get('assets', 'assetSearch')->name('admin.home.calendar.assets.search');
});
// Video Downloader (docs/ops/studio-video.md): each account's own queue on the Studio's native video service.
Route::controller(HomeVideoController::class)->prefix('video')->group(function () {
    Route::post('probe', 'probe')->middleware('throttle:30,1,home-video-probe')->name('admin.home.video.probe');
    Route::get('jobs', 'index')->name('admin.home.video.jobs');
    Route::post('jobs', 'store')->middleware('throttle:60,1,home-video-queue')->name('admin.home.video.jobs.store');
    Route::delete('jobs/{id}', 'destroy')->where('id', '[a-f0-9]{32}')->name('admin.home.video.jobs.destroy');
    Route::post('jobs/{id}/optimize', 'optimize')->where('id', '[a-f0-9]{32}')->middleware('throttle:30,1,home-video-optimize')->name('admin.home.video.jobs.optimize');
    Route::get('jobs/{id}/file', 'file')->where('id', '[a-f0-9]{32}')->name('admin.home.video.jobs.file');
});
Route::get('favorites', [HomeFavoriteController::class, 'index'])->name('admin.home.favorites.index');
Route::get('favorites/status', [HomeFavoriteController::class, 'status'])->name('admin.home.favorites.status');
Route::get('favorites/{asset}', [HomeFavoriteController::class, 'show'])->whereNumber('asset')->name('admin.home.favorites.show');
Route::post('favorites/{asset}', [HomeFavoriteController::class, 'store'])->whereNumber('asset')->name('admin.home.favorites.store');
Route::delete('favorites/{asset}', [HomeFavoriteController::class, 'destroy'])->whereNumber('asset')->name('admin.home.favorites.destroy');
Route::get('tools/{widget}/{path}', [HomeToolController::class, 'file'])->where('path', '.*')->name('admin.home.tool');
Route::match(['GET', 'PUT'], 'state/{widget}', [HomeToolController::class, 'state'])->name('admin.home.state');
Route::match(['GET', 'POST'], 'service/{service}/{path}', [HomeServiceController::class, 'proxy'])->where('path', '.*')->name('admin.home.service');
Route::get('stylepress/catalog', [HomeStylePressController::class, 'catalog'])->name('admin.home.stylepress.catalog');
Route::get('stylepress/balance', [HomeStylePressController::class, 'balance'])->name('admin.home.stylepress.balance');
Route::post('stylepress/estimate', [HomeStylePressController::class, 'estimate'])->middleware('throttle:30,1')->name('admin.home.stylepress.estimate');
Route::post('stylepress/generate', [HomeStylePressController::class, 'generate'])->middleware('throttle:10,1')->name('admin.home.stylepress.generate');

Route::get('stylepress/generation/{id}', [HomeStylePressController::class, 'generation'])->whereUuid('id')->name('admin.home.stylepress.generation');

Route::get('stylepress/assets', [HomeStylePressController::class, 'assets'])->name('admin.home.stylepress.assets');
Route::get('stylepress/folders', [HomeStylePressController::class, 'folders'])->name('admin.home.stylepress.folders');
Route::post('stylepress/references', [HomeStylePressController::class, 'reference'])->middleware('throttle:30,1')->name('admin.home.stylepress.reference');
Route::get('stylepress/references/{id}', [HomeStylePressController::class, 'referenceFile'])->whereUuid('id')->name('admin.home.stylepress.reference-file');
Route::post('stylepress/enhance', [HomeStylePressController::class, 'enhance'])->middleware('throttle:10,1')->name('admin.home.stylepress.enhance');
Route::get('stylepress/output/{id}', [HomeStylePressController::class, 'output'])->whereUuid('id')->name('admin.home.stylepress.output');

Route::get('stylepress/thumbnail/{style}', [HomeStylePressController::class, 'thumbnail'])->name('admin.home.stylepress.thumbnail');
Route::get('stylepress/source/{style}/{attachment}', [HomeStylePressController::class, 'source'])->whereNumber('attachment')->name('admin.home.stylepress.source');

// The Plaque Maker (docs/features/plaque-maker.md): what the account can reach, saved sets, and AI drafts.
Route::get('plaque/context', [HomePlaqueController::class, 'context'])->name('admin.home.plaque.context');
Route::get('plaque/sets', [HomePlaqueController::class, 'sets'])->name('admin.home.plaque.sets');
Route::post('plaque/generate', [HomePlaqueController::class, 'generate'])->middleware('throttle:10,1,home-plaque-generate')->name('admin.home.plaque.generate');
