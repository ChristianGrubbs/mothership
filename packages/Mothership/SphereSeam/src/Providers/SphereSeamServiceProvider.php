<?php

namespace Mothership\SphereSeam\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SphereSeamServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(__DIR__.'/../../Routes/sphereseam-routes.php');
        $this->loadViewsFrom(__DIR__.'/../../Resources/views', 'sphere-seam');
        $this->loadTranslationsFrom(__DIR__.'/../../Resources/lang', 'sphere-seam');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        // The browser module is served by the `sphereseam.script` route, not vendor:publish:
        // on the deployed runtime FPM serves code from its image while nginx serves the checkout.
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../Config/sphereseam.php', 'sphereseam');
        $this->mergeConfigFrom(__DIR__.'/../../Config/acl.php', 'acl');
    }
}
