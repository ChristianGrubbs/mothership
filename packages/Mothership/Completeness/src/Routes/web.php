<?php

use Mothership\Admin\Http\Middleware\EnsurePimEnabled;
use Mothership\Completeness\Http\Controllers\CompletenessController;
use Mothership\Completeness\Http\Controllers\CompletenessSettingsController;

Route::group(['middleware' => ['admin', EnsurePimEnabled::class], 'prefix' => config('app.admin_url')], function (): void {
    Route::controller(CompletenessSettingsController::class)->prefix('completeness-settings')->group(function (): void {
        Route::get('{family_id}/edit', 'edit')->name('admin.catalog.families.completeness.edit')->where(['family_id' => '[0-9]+']);

        Route::post('update', 'update')->name('admin.catalog.families.completeness.update');

        Route::post('mass-update', 'massUpdate')->name('admin.catalog.families.completeness.mass_update');
    });

    Route::controller(CompletenessController::class)->prefix('completeness')->group(function (): void {
        Route::get('dashboard/channel-stats', 'getCompletenessData')->name('admin.dashboard.completeness.data');
    });
});
