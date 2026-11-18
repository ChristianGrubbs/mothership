<?php

namespace Mothership\DocumentationExample;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class DocumentationExampleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $root = dirname(__DIR__);
        $this->mergeConfigFrom($root.'/config/acl.php', 'acl');
        $this->mergeConfigFrom($root.'/config/menu.php', 'menu.admin');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__);
        $this->loadViewsFrom($root.'/resources/views', 'documentation-example');
        $this->loadTranslationsFrom($root.'/resources/lang', 'documentation-example');
        Route::middleware(['web', 'admin'])->prefix(config('app.admin_url').'/documentation-example')->group(function () {
            Route::get('', [ExampleController::class, 'index'])->name('admin.documentation-example.index');
            Route::get('data', [ExampleController::class, 'data'])->name('admin.documentation-example.data');
        });
    }
}
