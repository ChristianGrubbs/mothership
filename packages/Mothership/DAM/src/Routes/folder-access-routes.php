<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\FolderAccessController;
use Mothership\DAM\Http\Controllers\GroupController;
use Mothership\DAM\Http\Controllers\NewUploadsController;
use Mothership\DAM\Http\Middleware\ManageFolderAccess;

Route::group(['middleware' => ['admin', ManageFolderAccess::class], 'prefix' => config('app.admin_url')], function () {
    Route::get('dam/directory/{id}/access', [FolderAccessController::class, 'show'])->whereNumber('id')->name('admin.dam.directory.access.show');
    Route::put('dam/directory/{id}/access', [FolderAccessController::class, 'update'])->whereNumber('id')->name('admin.dam.directory.access.update');
    Route::prefix('dam/new-uploads')->name('admin.dam.new-uploads.')->controller(NewUploadsController::class)->group(function () {
        Route::get('', 'index')->name('index');
        Route::get('list', 'list')->name('list');
        Route::post('move', 'move')->name('move');
        Route::post('mark-sorted', 'markSorted')->name('mark-sorted');
    });
    Route::prefix('settings/groups')->name('admin.settings.groups.')->controller(GroupController::class)->group(function () {
        Route::get('', 'index')->name('index');
        Route::get('list', 'list')->name('list');
        Route::post('', 'save')->name('store');
        Route::put('{id}', 'save')->whereNumber('id')->name('update');
        Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
    });
});
