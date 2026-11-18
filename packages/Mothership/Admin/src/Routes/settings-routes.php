<?php

use Illuminate\Support\Facades\Route;
use Mothership\Admin\Http\Controllers\Settings\ChannelController;
use Mothership\Admin\Http\Controllers\Settings\DataTransfer\ExportController;
use Mothership\Admin\Http\Controllers\Settings\DataTransfer\ExportFilterController;
use Mothership\Admin\Http\Controllers\Settings\DataTransfer\ImportController;
use Mothership\Admin\Http\Controllers\Settings\DataTransfer\TrackerController;
use Mothership\Admin\Http\Controllers\Settings\RoleController;
use Mothership\Admin\Http\Controllers\Settings\UserAccessController;
use Mothership\Admin\Http\Controllers\Settings\UserController;
use Mothership\Admin\Http\Controllers\Settings\UserInviteController;
use Mothership\Admin\Http\Middleware\EnsurePimEnabled;

/**
 * Settings routes.
 */
Route::group(['middleware' => ['admin'], 'prefix' => config('app.admin_url')], function () {
    Route::prefix('settings')->group(function () {
        /**
         * Channels routes.
         */
        Route::controller(ChannelController::class)->prefix('channels')->group(function () {
            Route::get('', 'index')->name('admin.settings.channels.index');

            Route::get('create', 'create')->name('admin.settings.channels.create');

            Route::post('create', 'store')->name('admin.settings.channels.store');

            Route::get('edit/{id}', 'edit')->name('admin.settings.channels.edit');

            Route::put('edit/{id}', 'update')->name('admin.settings.channels.update');

            Route::delete('edit/{id}', 'destroy')->name('admin.settings.channels.delete');
        });

        /**
         * Roles routes.
         */
        Route::controller(RoleController::class)->prefix('roles')->group(function () {
            Route::get('', 'index')->name('admin.settings.roles.index');

            Route::get('create', 'create')->name('admin.settings.roles.create');

            Route::post('create', 'store')->name('admin.settings.roles.store');

            Route::get('edit/{id}', 'edit')->name('admin.settings.roles.edit');

            Route::put('edit/{id}', 'update')->name('admin.settings.roles.update');

            Route::delete('edit/{id}', 'destroy')->name('admin.settings.roles.delete');
        });

        /**
         * Users routes.
         */
        Route::controller(UserController::class)->prefix('users')->group(function () {
            Route::get('', 'index')->name('admin.settings.users.index');

            Route::post('create', 'store')->name('admin.settings.users.store');

            Route::post('invite', [UserInviteController::class, 'invite'])->name('admin.settings.users.invite')->middleware('throttle:admin-invite');

            Route::post('invite/{id}/resend', [UserInviteController::class, 'resend'])->whereNumber('id')->name('admin.settings.users.invite.resend')->middleware('throttle:admin-invite');

            Route::get('edit/{id}', 'edit')->name('admin.settings.users.edit');

            Route::put('edit', 'update')->name('admin.settings.users.update')->middleware('throttle:admin-sudo');

            Route::delete('edit/{id}', 'destroy')->name('admin.settings.users.delete');

            Route::put('confirm', 'destroySelf')->name('admin.settings.users.destroy');

            /**
             * Waiting and preapproved sign-ins. UserAccessController admits only a role with All permissions.
             */
            Route::controller(UserAccessController::class)->prefix('access')->group(function () {
                Route::get('', 'overview')->name('admin.settings.users.access.index');

                Route::middleware('throttle:admin-invite')->group(function () {
                    Route::post('requests/{id}/approve', 'approve')->whereNumber('id')->name('admin.settings.users.access.approve');

                    Route::post('requests/{id}/link', 'link')->whereNumber('id')->name('admin.settings.users.access.link');

                    Route::delete('requests/{id}', 'deny')->whereNumber('id')->name('admin.settings.users.access.deny');

                    Route::post('preapprovals', 'preapprove')->name('admin.settings.users.access.preapprove');

                    Route::delete('preapprovals/{id}', 'removePreapproval')->whereNumber('id')->name('admin.settings.users.access.preapprovals.remove');
                });
            });
        });
    });

    /**
     * Data Transfer routes — top-level (its own sidebar section), not under settings/.
     */
    Route::prefix('data-transfer')->middleware(EnsurePimEnabled::class)->group(function () {

        /**
         * Sync Tracker routes.
         */
        Route::controller(TrackerController::class)->prefix('job-tracker')->group(function () {
            Route::get('', 'index')->name('admin.settings.data_transfer.tracker.index');
            Route::get('track/{batch_id}', 'view')->name('admin.settings.data_transfer.tracker.view');
            Route::get('track/download/{batch_id}', 'download')->name('admin.settings.data_transfer.tracker.download');
            Route::get('track/archive/download/{batch_id}', 'downloadArchive')->name('admin.settings.data_transfer.tracker.archive.download');
            Route::get('track/download-log/{track_id}', 'downloadLogFile')->name('admin.settings.data_transfer.tracker.log.download');
        });

        /**
         * Job control routes. The `imports.pause|resume|cancel` names remain registered for
         * backward compatibility and are dropped in the next major version.
         */
        Route::controller(ImportController::class)->prefix('jobs')->group(function () {
            Route::post('pause/{id}', 'pause')->name('admin.settings.data_transfer.jobs.pause');
            Route::post('resume/{id}', 'resume')->name('admin.settings.data_transfer.jobs.resume');
            Route::post('cancel/{id}', 'cancel')->name('admin.settings.data_transfer.jobs.cancel');
            Route::get('stats/{id}/{state?}', 'stats')->name('admin.settings.data_transfer.jobs.stats');
        });
        /**
         * Import routes.
         */
        Route::controller(ImportController::class)->prefix('imports')->group(function () {
            Route::get('', 'index')->name('admin.settings.data_transfer.imports.index');

            Route::get('create', 'create')->name('admin.settings.data_transfer.imports.create');

            Route::post('create', 'store')->name('admin.settings.data_transfer.imports.store');

            Route::get('edit/{id}', 'edit')->name('admin.settings.data_transfer.imports.edit');

            Route::put('edit/{id}', 'update')->name('admin.settings.data_transfer.imports.update');

            Route::delete('destroy/{id}', 'destroy')->name('admin.settings.data_transfer.imports.delete');

            Route::get('import/{id}', 'importView')->name('admin.settings.data_transfer.imports.import-view');

            Route::get('validate/{id}', 'validateImport')->name('admin.settings.data_transfer.imports.validate');

            Route::put('import-now/{id}', 'importNow')->name('admin.settings.data_transfer.imports.import_now');

            Route::get('start/{id}', 'start')->name('admin.settings.data_transfer.imports.start');

            Route::get('link/{id}', 'link')->name('admin.settings.data_transfer.imports.link');

            Route::get('index/{id}', 'indexData')->name('admin.settings.data_transfer.imports.index_data');

            Route::post('pause/{id}', 'pause')->name('admin.settings.data_transfer.imports.pause');

            Route::post('resume/{id}', 'resume')->name('admin.settings.data_transfer.imports.resume');

            Route::post('cancel/{id}', 'cancel')->name('admin.settings.data_transfer.imports.cancel');

            Route::get('stats/{id}/{state?}', 'stats')->name('admin.settings.data_transfer.imports.stats');

            Route::get('download-sample/{type?}/{key?}', 'downloadSample')->name('admin.settings.data_transfer.imports.download_sample');

            Route::get('download-sample-images-zip/{type?}/{key?}', 'downloadSampleImagesZip')->name('admin.settings.data_transfer.imports.download_sample_zip');

            Route::get('download/{id}', 'download')->name('admin.settings.data_transfer.imports.download');

            Route::get('download-error-report/{id}', 'downloadErrorReport')->name('admin.settings.data_transfer.imports.download_error_report');

            Route::post('upload-images-zip', 'uploadImagesZip')->name('admin.settings.data_transfer.imports.upload_images_zip');
        });

        /**
         * Export routes. admin.settings.data_transfer.exports.export-view
         */
        Route::controller(ExportController::class)->prefix('exports')->group(function () {
            Route::get('', 'index')->name('admin.settings.data_transfer.exports.index');

            Route::get('create', 'create')->name('admin.settings.data_transfer.exports.create');

            Route::post('create', 'store')->name('admin.settings.data_transfer.exports.store');

            Route::get('edit/{id}', 'edit')->name('admin.settings.data_transfer.exports.edit');

            Route::get('system/edit/{id}', 'edit')->name('admin.settings.data_transfer.systems.edit');

            Route::put('edit/{id}', 'update')->name('admin.settings.data_transfer.exports.update');

            Route::delete('destroy/{id}', 'destroy')->name('admin.settings.data_transfer.exports.delete');

            Route::get('export/{id}', 'exportView')->name('admin.settings.data_transfer.exports.export-view');

            Route::get('validate/{id}', 'validateExport')->name('admin.settings.data_transfer.exports.validate');

            Route::put('export-now/{id}', 'exportNow')->name('admin.settings.data_transfer.exports.export_now');

            Route::get('start/{id}', 'start')->name('admin.settings.data_transfer.exports.start');

            Route::get('link/{id}', 'link')->name('admin.settings.data_transfer.exports.link');

            Route::get('index/{id}', 'indexData')->name('admin.settings.data_transfer.exports.index_data');

            Route::get('stats/{id}/{state?}', 'stats')->name('admin.settings.data_transfer.exports.stats');

            Route::get('download-sample/{type?}/{key?}', 'downloadSample')->name('admin.settings.data_transfer.exports.download_sample');

            Route::get('download-sample-images-zip/{type?}/{key?}', 'downloadSampleImagesZip')->name('admin.settings.data_transfer.exports.download_sample_zip');

            Route::get('download/{id}', 'download')->name('admin.settings.data_transfer.exports.download');

            Route::get('download-error-report/{id}', 'downloadErrorReport')->name('admin.settings.data_transfer.exports.download_error_report');
        });

        Route::controller(ExportFilterController::class)->prefix('exports/filters')->group(function () {
            Route::get('channels', 'channels')->name('admin.settings.data_transfer.exports.filters.channels');

            Route::get('locales', 'locales')->name('admin.settings.data_transfer.exports.filters.locales');

            Route::get('currencies', 'currencies')->name('admin.settings.data_transfer.exports.filters.currencies');

            Route::get('attributes', 'getAttributes')->name('admin.settings.data_transfer.exports.filters.attributes');

            Route::get('attribute-families', 'attributeFamilies')->name('admin.settings.data_transfer.exports.filters.attribute_families');

            Route::get('categories', 'categories')->name('admin.settings.data_transfer.exports.filters.categories');
        });
    });
});
