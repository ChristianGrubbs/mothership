<?php

namespace Mothership\Theme\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Mothership\Theme\Themes;
use Mothership\Theme\ThemeViewFinder;

class ThemeServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        include __DIR__.'/../Http/helpers.php';

        Blade::directive('mothershipVite', fn ($expression): string => "<?php echo themes()->setmothershipVite({$expression})->toHtml(); ?>");
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->scoped('themes', fn (): Themes => new Themes);

        $this->app->singleton('view.finder', fn ($app): ThemeViewFinder => new ThemeViewFinder(
            $app['files'],
            $app['config']['view.paths']
        ));
    }
}
