<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\Access\AccessController;

/*
 * The Share dialog's access lists (ADR 0010, sharing slice 3). Deliberately absent from the ACL route map
 * in Config/acl.php: every user shares their own items and the picker is open to every signed-in admin, so
 * the owner-or-full-admin check lives in AccessListService instead. Every action answers 404 unless the
 * access model is exactly `lists`.
 */
Route::group(['middleware' => ['admin', 'dam'], 'prefix' => config('app.admin_url')], function () {
    Route::get('dam/access/principals', [AccessController::class, 'principals'])->name('admin.dam.access.principals');
    // Registered ahead of the {type}/{id} show route below: "move-preview" would otherwise match its
    // {type} segment and 404 for a missing numeric {id}.
    Route::get('dam/access/move-preview', [AccessController::class, 'movePreview'])->name('admin.dam.access.move_preview');
    Route::get('dam/access/{type}/{id}', [AccessController::class, 'show'])->whereIn('type', ['asset', 'directory'])->whereNumber('id')->name('admin.dam.access.show');
    Route::put('dam/access/{type}/{id}', [AccessController::class, 'update'])->whereIn('type', ['asset', 'directory'])->whereNumber('id')->name('admin.dam.access.update');
    Route::post('dam/access/{type}/{id}/revoke-public-links', [AccessController::class, 'revokePublicLinks'])->whereIn('type', ['asset', 'directory'])->whereNumber('id')->name('admin.dam.access.revoke_public_links');
});
