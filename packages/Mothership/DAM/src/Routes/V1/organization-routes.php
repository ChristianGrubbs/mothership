<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\API\Asset\BoardController;
use Mothership\DAM\Http\Controllers\API\Asset\LabelController;
use Mothership\DAM\Http\Controllers\API\Asset\LibraryController;
use Mothership\DAM\Http\Controllers\API\Asset\SavedSearchController;
use Mothership\DAM\Http\Controllers\API\Asset\SectionController;

/*
 * P4 (REST parity): Library organization. Every route needs its api-acl.php entry (api.scope refuses one without).
 */
Route::group(['middleware' => ['auth:api']], function (): void {
    Route::get('library/assets', [LibraryController::class, 'assets'])->name('admin.api.dam.library.assets');

    foreach (['labels' => LabelController::class, 'boards' => BoardController::class] as $prefix => $controller) {
        Route::prefix($prefix)->controller($controller)->group(function () use ($prefix): void {
            Route::get('', 'index')->name("admin.api.dam.{$prefix}.index");
            Route::post('', 'store')->name("admin.api.dam.{$prefix}.store");
            Route::put('{label}', 'update')->whereNumber('label')->name("admin.api.dam.{$prefix}.update");
            Route::delete('{label}', 'destroy')->whereNumber('label')->name("admin.api.dam.{$prefix}.destroy");
            Route::get('{label}/assets', 'assets')->whereNumber('label')->name("admin.api.dam.{$prefix}.assets.index");
            Route::post('{label}/assets', 'addAssets')->whereNumber('label')->name("admin.api.dam.{$prefix}.assets.store");
            Route::delete('{label}/assets', 'removeAssets')->whereNumber('label')->name("admin.api.dam.{$prefix}.assets.destroy");
            Route::put('{label}/assets/{asset}', 'moveAsset')->whereNumber(['label', 'asset'])->name("admin.api.dam.{$prefix}.assets.update");
        });
    }

    Route::prefix('saved-searches')->controller(SavedSearchController::class)->group(function (): void {
        Route::get('', 'index')->name('admin.api.dam.saved-searches.index');
        Route::post('', 'store')->name('admin.api.dam.saved-searches.store');
        Route::put('{search}', 'update')->whereNumber('search')->name('admin.api.dam.saved-searches.update');
        Route::delete('{search}', 'destroy')->whereNumber('search')->name('admin.api.dam.saved-searches.destroy');
        Route::get('{search}/assets', 'assets')->whereNumber('search')->name('admin.api.dam.saved-searches.assets');
    });

    Route::prefix('sections')->controller(SectionController::class)->group(function (): void {
        Route::get('', 'index')->name('admin.api.dam.sections.index');
        Route::post('', 'store')->name('admin.api.dam.sections.store');
        Route::put('{section}', 'update')->whereNumber('section')->name('admin.api.dam.sections.update');
        Route::delete('{section}', 'destroy')->whereNumber('section')->name('admin.api.dam.sections.destroy');
        Route::get('{section}/assets', 'assets')->whereNumber('section')->name('admin.api.dam.sections.assets');
        Route::post('{section}/pins', 'pin')->whereNumber('section')->name('admin.api.dam.sections.pins.store');
        Route::delete('{section}/pins', 'unpin')->whereNumber('section')->name('admin.api.dam.sections.pins.destroy');
        Route::put('{section}/pins/{asset}', 'movePin')->whereNumber(['section', 'asset'])->name('admin.api.dam.sections.pins.update');
    });
});
