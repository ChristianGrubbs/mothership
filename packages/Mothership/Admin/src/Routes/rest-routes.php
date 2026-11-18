<?php

use Illuminate\Support\Facades\Route;
use Mothership\Admin\Http\Controllers\DashboardController;
use Mothership\Admin\Http\Controllers\DataGridController;
use Mothership\Admin\Http\Controllers\DocumentationController;
use Mothership\Admin\Http\Controllers\HelpController;
use Mothership\Admin\Http\Controllers\MagicAI\MagicAIController;
use Mothership\Admin\Http\Controllers\MagicAI\MagicAIPlatformController;
use Mothership\Admin\Http\Controllers\MagicAI\MagicAISystemPromptController;
use Mothership\Admin\Http\Controllers\ManageColumnController;
use Mothership\Admin\Http\Controllers\MediaController;
use Mothership\Admin\Http\Controllers\TinyMCEController;
use Mothership\Admin\Http\Controllers\User\AccountController;
use Mothership\Admin\Http\Controllers\User\AclVersionController;
use Mothership\Admin\Http\Controllers\User\SessionController;
use Mothership\Admin\Http\Controllers\VueJsSelect\SelectOptionsController;
use Mothership\Admin\Http\Middleware\EnsurePimEnabled;
use Mothership\HistoryControl\Http\Controllers\HistoryController;

/**
 * Extra routes.
 */
Route::group(['middleware' => ['admin'], 'prefix' => config('app.admin_url')], function () {
    Route::get('acl-version', [AclVersionController::class, 'show'])->name('admin.acl.version');

    Route::get('media/download', [MediaController::class, 'download'])->name('admin.media.download');

    /**
     * Dashboard routes.
     */
    Route::controller(DashboardController::class)->prefix('dashboard')->group(function () {
        Route::get('', 'index')->name('admin.dashboard.index');

        Route::get('stats', 'stats')->middleware(EnsurePimEnabled::class)->name('admin.dashboard.stats');
    });

    /**
     * Help routes.
     */
    Route::controller(HelpController::class)->prefix('help')->group(function () {
        Route::get('', 'index')->name('admin.help.index');

        Route::post('promo/dismiss', 'dismissPromo')->name('admin.help.promo.dismiss');
    });

    Route::get('help/api', [DocumentationController::class, 'api'])->name('admin.help.api');
    Route::get('help/api/{file}', [DocumentationController::class, 'download'])
        ->where('file', 'openapi\.json|routes\.json|coverage\.json')->name('admin.help.api.download');

    Route::get('help/docs/{slug}', [DocumentationController::class, 'show'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('admin.help.docs.show');

    /**
     * Datagrid routes.
     */
    Route::get('datagrid/look-up', [DataGridController::class, 'lookUp'])->name('admin.datagrid.look_up');

    /**
     * Available Columns routes.
     */
    Route::get('datagrid/available-columns', [ManageColumnController::class, 'availableColumns'])->name('admin.datagrid.available_columns');

    /**
     * Tinymce file upload handler.
     */
    Route::post('tinymce/upload', [TinyMCEController::class, 'upload'])
        ->middleware('throttle:30,1')
        ->name('admin.tinymce.upload');

    /**
     * AI Routes
     */
    Route::middleware(EnsurePimEnabled::class)->group(function () {
        Route::controller(MagicAIController::class)->prefix('magic-ai')->group(function () {
            Route::get('model', 'model')->name('admin.magic_ai.model');

            Route::get('validate-credential', 'validateCredential')->name('admin.magic_ai.validate_credential');

            Route::get('available-model', 'availableModel')->name('admin.magic_ai.available_model');

            Route::get('suggestion-values', 'suggestionValues')->name('admin.magic_ai.suggestion_values');

            Route::post('content', 'content')->name('admin.magic_ai.content');

            Route::post('image', 'image')->middleware('throttle:10,1')->name('admin.magic_ai.image');

            Route::get('default-prompt', 'defaultPrompt')->name('admin.magic_ai.default_prompt');

            Route::get('prompts', 'index')->name('admin.magic_ai.prompt.index');

            Route::post('create-prompt', 'store')->name('admin.magic_ai.prompt.store');

            Route::delete('delete/{id}', 'destroy')->name('admin.magic_ai.prompt.delete');

            Route::get('edit/{id}', 'edit')->name('admin.magic_ai.prompt.edit');

            Route::put('edit', 'update')->name('admin.magic_ai.prompt.update');

            Route::post('translate', 'translateToManyLocale')->name('admin.magic_ai.translate');

            Route::post('check/isTranslatable', 'isTranslatable')->name('admin.magic_ai.check.is_translatable');

            Route::post('save/translatedData', 'saveTranslatedData')->name('admin.magic_ai.store.translated');

            Route::post('check/is-all-attribute-translatable', 'isAllAttributeTranslatable')->name('admin.magic_ai.check.is_all_attribute_translatable');

            Route::post('translate/all/attribute', 'translateAllAttribute')->name('admin.magic_ai.translate.all.attribute');

            Route::post('save/translated-attributes', 'saveAllTranslatedAttributes')->name('admin.magic_ai.store.translated.all_attribute');

            Route::get('platform-options', 'platforms')->name('admin.magic_ai.platforms');
        });

        /**
         * Platform Routes
         */
        Route::controller(MagicAIPlatformController::class)->prefix('magic-ai/platforms')->group(function () {
            Route::get('', 'index')->name('admin.magic_ai.platform.index');
            Route::post('', 'store')->name('admin.magic_ai.platform.store');
            Route::post('test-connection', 'testConnection')->name('admin.magic_ai.platform.test');
            Route::post('fetch-models', 'fetchModels')->name('admin.magic_ai.platform.fetch_models');
            Route::get('{id}', 'edit')->name('admin.magic_ai.platform.edit');
            Route::put('{id}', 'update')->name('admin.magic_ai.platform.update');
            Route::delete('{id}', 'destroy')->name('admin.magic_ai.platform.delete');
            Route::post('{id}/default', 'setDefault')->name('admin.magic_ai.platform.set_default');
        });

        /**
         * System Prompt Routes
         */
        Route::controller(MagicAISystemPromptController::class)->prefix('magic-ai/system-prompts')->group(function () {
            Route::get('', 'index')->name('admin.magic_ai.system_prompt.index');

            Route::post('create-system-prompt', 'store')->name('admin.magic_ai.system_prompt.store');

            Route::delete('delete/{id}', 'destroy')->name('admin.magic_ai.system_prompt.delete');

            Route::get('edit/{id}', 'edit')->name('admin.magic_ai.system_prompt.edit');

            Route::put('edit', 'update')->name('admin.magic_ai.system_prompt.update');
        });
    });

    /**
     * Admin profile routes.
     */
    Route::controller(AccountController::class)->prefix('account')->group(function () {
        Route::get('', 'edit')->name('admin.account.edit');

        Route::put('', 'update')->name('admin.account.update');
    });

    /**
     * History routes.
     */
    Route::controller(HistoryController::class)->prefix('history')->group(function () {
        Route::get('view/{entity}/{id}', 'get')->name('admin.history.index');

        Route::get('view/{entity}/{id}/{historyId}', 'getHistoryView')->name('admin.history.view');

        Route::get('view-version/{entity}/{id}/{versionId}', 'getVersionHistoryView')->name('admin.history.version.view');

        Route::post('view-version/{entity}/{id}/{versionId}', 'restoreHistory')->name('admin.history.version.restore');

        Route::delete('view-version/{entity}/{id}/{versionId}', 'deleteHistory')->name('admin.history.version.delete');
    });

    Route::delete('logout', [SessionController::class, 'destroy'])->name('admin.session.destroy');

    // A typed or reopened /logout URL lands somewhere useful instead of a bare 405; it never logs out.
    Route::get('logout', [SessionController::class, 'showLogout'])->name('admin.session.logout.show');

    // The session notice's "am I signed in?" probe; it also hands a page rendered before re-signing-in its new CSRF token.
    Route::get('session/token', [SessionController::class, 'token'])->name('admin.session.token');

    /**
     * Select options routes.
     */
    Route::get('vue-js-select/select-options', [SelectOptionsController::class, 'getOptions'])->name('admin.vue_js_select.select.options');
});
