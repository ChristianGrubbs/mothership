<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\PosthogOutbox;
use Mothership\DAM\Http\Controllers\ReplayRelayController;
use Mothership\User\Models\Admin;

// Explicit local integration fixture; never loaded by the application or a production route.
ini_set('display_errors', '0');
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$request = Request::capture();
if ($request->path() === 'runtime/axios.js') {
    header('Content-Type: application/javascript');
    readfile(base_path('packages/Mothership/DAM/node_modules/axios/dist/axios.min.js'));
    exit;
}
if ($request->path() === 'runtime/rrweb.js') {
    header('Content-Type: application/javascript');
    readfile(base_path('packages/Mothership/DAM/node_modules/posthog-js/dist/rrweb.js'));
    exit;
}
if ($request->path() === 'private/fixture.css') {
    header('Content-Type: text/css');
    echo '.private-fixture { content: "Private CSS sentinel"; background-image: url("/Private-css-url-sentinel.png"); }';
    exit;
}
$privateCss = match (true) {
    $request->query('private') === 'Private-css-query-sentinel'            => 'Private CSS query content sentinel',
    $request->path() === 'themes/dam/build/assets/mount-fixture.css'       => 'Private CSS chunk content sentinel',
    $request->path() === 'themes/dam/build/assets/app-lookalike.css/extra' => 'Private CSS lookalike content sentinel',
    default                                                                => null,
};
if ($privateCss !== null) {
    header('Content-Type: text/css');
    echo '.private-fixture { content: '.json_encode($privateCss).'; }';
    exit;
}
if (str_starts_with($request->path(), 'themes/')) {
    $asset = realpath(public_path($request->path()));
    if (! $asset || ! str_starts_with($asset, public_path('themes/'))) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: '.(str_ends_with($asset, '.css') ? 'text/css' : 'application/javascript'));
    readfile($asset);
    exit;
}
// Layout-owned optional scripts are unrelated to this replay probe.
if (str_ends_with($request->path(), '.js')) {
    header('Content-Type: application/javascript');
    exit;
}
$app->instance('request', $request);
$session = app('session')->driver();
$session->start();
$session->put('_token', 'csrf');
$request->setLaravelSession($session);
app('view')->share('errors', new ViewErrorBag);
$admin = Admin::query()->first() ?? Admin::factory()->create(['status' => 1]);
auth('admin')->setUser($admin);
config(['dam_events.enabled'    => true, 'dam_events.posthog.enabled' => true, 'dam_events.posthog.key' => 'srv',
    'dam_events.posthog.host'   => getenv('REPLAY_UPSTREAM'), 'dam_events.posthog.release' => 'browser-fixture',
    'dam_events.replay.enabled' => true, 'dam_events.replay.public_key' => 'pub']);
// TLS trust is relaxed only for this loopback, ephemeral controlled upstream.
Http::globalOptions(['verify' => false]);
if ($request->path() === 'admin/no-replay') {
    config(['dam_events.enabled' => false]);
}
if (str_starts_with($request->path(), 'admin/dam/analytics/replay/')) {
    $response = app(PreventRequestForgery::class)->handle($request,
        fn ($request) => app(ReplayRelayController::class)->relay(
            request: $request, path: substr($request->path(), strlen('admin/dam/analytics/replay/'))));
    $response->send();
    exit;
}
if ($request->path() === 'probe') {
    $previous = DB::table(PosthogOutbox::TABLE)->max('id') ?? 0;
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 0]);
    $payload = json_decode(DB::table(PosthogOutbox::TABLE)->where('id', '>', $previous)->orderByDesc('id')->value('payload') ?? '{}', true);
    header('Content-Type: application/json');
    echo json_encode(['session' => $request->header('X-Dam-Replay-Session'),
        'exported_session'      => $payload['properties']['$session_id'] ?? null,
        'distinct_id'           => $payload['properties']['distinct_id'] ?? null]);
    exit;
}
$title = $request->path() === 'admin/library' ? 'Library' : 'Home';
$manifest = json_decode(file_get_contents(public_path('themes/admin/default/build/manifest.json')), true);
$themeCss = '/themes/admin/default/build/'.$manifest['src/Resources/assets/css/app.css']['file'];
header('Content-Type: text/html');
$encrypter = app('encrypter');
setcookie('XSRF-TOKEN', $encrypter->encrypt(CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).'csrf', false), ['path' => '/', 'samesite' => 'Lax']);
ob_start();
?>
<link rel="stylesheet" href="/private/fixture.css">
<link rel="stylesheet" href="<?= $themeCss ?>?private=Private-css-query-sentinel">
<link rel="stylesheet" href="<?= $themeCss ?>#Private-css-fragment-sentinel">
<link rel="stylesheet" href="/themes/dam/build/assets/mount-fixture.css">
<link rel="stylesheet" href="/themes/dam/build/assets/app-lookalike.css/extra">
<link rel="alternate stylesheet" title="Private CSS alternate sentinel" href="<?= $themeCss ?>">
<nav data-replay-safe><a href="/admin/home">Home</a><a href="/admin/library">Library</a><a href="/admin/search">Search</a><a href="/admin/no-replay">Opted out</a></nav>
<main><h1 data-replay-safe><?= $title ?></h1><p>Private asset sentinel</p>
<input value="Private input sentinel" placeholder="Private attribute sentinel"><input type="hidden" value="Private hidden sentinel">
<a href="/private?q=Private attribute sentinel" title="Private attribute sentinel" aria-label="Private attribute sentinel" data-name="Private attribute sentinel" style="background:url(/Private-attribute-sentinel.png);--private: &quot;Private attribute sentinel&quot;;width:20px">Private asset sentinel</a>
<div contenteditable>Private editable sentinel</div><canvas></canvas><iframe srcdoc="Private iframe sentinel"></iframe><img alt="Private image sentinel">
<button data-replay-safe id="mothership-action">Open library</button><form id="adminLogout"></form></main>
<?php
$slot = ob_get_clean();
// Render the production Admin shell, its real collector event and real bundled navigator.
// In particular, do not invent a CSRF meta tag: Admin uses the encrypted XSRF cookie.
echo Blade::render('<x-admin::layouts :title="$title">{!! $slot !!}</x-admin::layouts>', compact('title', 'slot'));
