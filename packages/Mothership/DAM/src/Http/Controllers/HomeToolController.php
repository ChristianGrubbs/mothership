<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\HomeTelemetry;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\SphereSeam\Http\Controllers\SphereSeamController;

class HomeToolController extends Controller
{
    public function file(string $widget, string $path, HomeWidgetRegistry $registry)
    {
        $definition = $registry->allowed($widget);
        abort_unless($definition['kind'] === 'tool', 404);
        if ($widget === 'stylepress' && $path === 'index.html') {
            return app(HomeStylePressController::class)->index();
        }
        if ($widget === 'vectorization' && $path === 'index.html') {
            return app(HomeVectorizationController::class)->index();
        }
        if ($widget === 'sphereseam' && $path === 'index.html') {
            return app(SphereSeamController::class)->frame();
        }
        $root = realpath(__DIR__.'/../../Resources/widgets/'.$widget);
        $file = $root ? realpath($root.'/'.$path) : false;
        abort_unless($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);
        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'html'  => 'text/html', 'js', 'mjs', 'jsx' => 'application/javascript', 'css' => 'text/css',
            'json'  => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
            'woff2' => 'font/woff2', 'woff' => 'font/woff', 'otf' => 'font/otf', 'ttf' => 'font/ttf',
            default => null,
        };
        abort_unless($mime, 404);
        if ($mime === 'text/html') {
            $html = file_get_contents($file);
            // A callback, not a replacement string: preg_replace reads `\\` and `$1` in a replacement as its own
            // escapes and would rewrite the regular expressions inside the injected scripts.
            $html = preg_replace_callback('/<head>/i', fn (): string => '<head>'.$this->bootstrap($widget), $html, 1);

            return response($html)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'private, no-store')->header('X-Frame-Options', 'SAMEORIGIN');
        }

        return response()->file($file, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function state(Request $request, string $widget, HomeWidgetRegistry $registry)
    {
        $registry->allowed($widget);
        $adminId = auth('admin')->id();
        if ($request->isMethod('put')) {
            $data = $request->validate(['state' => ['present', 'array', 'max:100'], 'state.*' => ['string', 'max:65536']]);
            abort_if(strlen(json_encode($data['state'])) > 131072, 422, 'Widget preferences are too large.');
            DB::table('dam_home_widget_state')->updateOrInsert(['admin_id' => $adminId, 'widget' => $widget], ['state' => json_encode($data['state'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($request->isMethod('put')) {
            HomeTelemetry::record(event: 'preferences.saved', ctx: ['admin_id' => $adminId, 'widget' => $widget]);
        }

        return response()->json(['state' => $this->preferences($widget)])->header('Cache-Control', 'private, no-store');
    }

    public function bootstrap(string $widget): string
    {
        $settings = [
            'widget' => $widget, 'account' => auth('admin')->id(), 'state' => $this->preferences($widget),
            'url'    => route('admin.home.state', ['widget' => $widget]), 'csrf' => csrf_token(),
        ];
        $json = json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $script = file_get_contents(__DIR__.'/../../Resources/assets/js/home-frame.js');
        // In the app installed to an iPhone Home Screen a tool's save goes to the share sheet, not to a file page.
        $save = file_get_contents(__DIR__.'/../../Resources/assets/js/device-save.js');

        return '<script>window.__MOTHERSHIP_FRAME__='.$json.';</script><script>'.$script.'</script><script>'.$save.'</script>';
    }

    private function preferences(string $widget): array
    {
        $state = DB::table('dam_home_widget_state')->where('admin_id', auth('admin')->id())->where('widget', $widget)->value('state');

        return $state ? json_decode($state, true, flags: JSON_THROW_ON_ERROR) : [];
    }
}
