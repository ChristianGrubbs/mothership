<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\Organization\LibraryBoardController;
use Mothership\DAM\Http\Controllers\Organization\LibraryLabelController;
use Mothership\DAM\Http\Controllers\Organization\LibraryOrganizeController;
use Mothership\DAM\Http\Controllers\Organization\LibrarySavedSearchController;
use Mothership\DAM\Http\Controllers\Organization\LibrarySectionController;

/*
 * P4: Library organization (labels, boards, saved searches, sections, the saved sort). Browsing a label, board or
 * section is the explorer listing (admin.dam.explorer.index) with label_id or section_id.
 */
Route::get('organize', [LibraryOrganizeController::class, 'index'])->name('admin.dam.organize.index');
Route::put('library/preferences', [LibraryOrganizeController::class, 'updatePreferences'])->name('admin.dam.library.preferences.update');

foreach (['labels' => LibraryLabelController::class, 'boards' => LibraryBoardController::class] as $prefix => $controller) {
    Route::prefix($prefix)->controller($controller)->group(function () use ($prefix): void {
        Route::post('', 'store')->name("admin.dam.{$prefix}.store");
        Route::put('{label}', 'update')->whereNumber('label')->name("admin.dam.{$prefix}.update");
        Route::delete('{label}', 'destroy')->whereNumber('label')->name("admin.dam.{$prefix}.destroy");
        Route::post('{label}/assets', 'addAssets')->whereNumber('label')->name("admin.dam.{$prefix}.assets.store");
        Route::delete('{label}/assets', 'removeAssets')->whereNumber('label')->name("admin.dam.{$prefix}.assets.destroy");
        Route::put('{label}/assets/{asset}', 'moveAsset')->whereNumber(['label', 'asset'])->name("admin.dam.{$prefix}.assets.update");
    });
}

Route::prefix('saved-searches')->controller(LibrarySavedSearchController::class)->group(function (): void {
    Route::post('', 'store')->name('admin.dam.saved-searches.store');
    Route::put('{search}', 'update')->whereNumber('search')->name('admin.dam.saved-searches.update');
    Route::delete('{search}', 'destroy')->whereNumber('search')->name('admin.dam.saved-searches.destroy');
});

Route::prefix('sections')->controller(LibrarySectionController::class)->group(function (): void {
    Route::post('', 'store')->name('admin.dam.sections.store');
    Route::put('{section}', 'update')->whereNumber('section')->name('admin.dam.sections.update');
    Route::delete('{section}', 'destroy')->whereNumber('section')->name('admin.dam.sections.destroy');
    Route::post('{section}/pins', 'pin')->whereNumber('section')->name('admin.dam.sections.pins.store');
    Route::delete('{section}/pins', 'unpin')->whereNumber('section')->name('admin.dam.sections.pins.destroy');
    Route::put('{section}/pins/{asset}', 'movePin')->whereNumber(['section', 'asset'])->name('admin.dam.sections.pins.update');
});
