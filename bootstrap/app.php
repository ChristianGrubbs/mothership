<?php

use App\Exceptions\PostTooLargeResponse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Mothership\Admin\Http\Middleware\ConvertAjaxFormRedirect;
use Mothership\Admin\Http\Middleware\SignalLoginLockout;
use Mothership\Core\Http\Middleware\NoCacheMiddleware;
use Mothership\Core\Http\Middleware\SecureHeaders;
use Mothership\Core\Http\Middleware\UsePublicOrigin;
use Mothership\DAM\Http\Middleware\AssignRequestId;
use Mothership\DAM\Http\Middleware\RecordHomePresence;
use Mothership\DAM\Http\Middleware\SignalDownloadStartCookies;
use Mothership\Installer\Http\Middleware\CanInstall;

$toList = static fn (?string $value): array => array_values(array_unique(array_filter(
    array_map(trim(...), explode(',', (string) $value))
)));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->booting(function () use ($toList): void {
        $proxies = $toList(config('app.trusted_proxies'));

        TrustProxies::at(in_array('*', $proxies, true) ? '*' : $proxies);
    })
    ->withMiddleware(function (Middleware $middleware) use ($toList): void {
        $middleware->trustHosts(at: fn (): array => $toList(config('app.trusted_hosts')));

        // Before everything but AssignRequestId: every later redirect, view and signed URL is built on the
        // allowlisted public origin the request arrived on (APP_PUBLIC_ORIGINS), else on APP_URL.
        $middleware->prepend(UsePublicOrigin::class);
        // First, so every later middleware, log line and queued job already carries the request's id.
        $middleware->prepend(AssignRequestId::class);

        $middleware->encryptCookies(except: ['sidebar_collapsed', 'dark_mode']);
        // The download card's start cookie (dam_dl_<token>) is read by the page's script, so it stays plain.
        $middleware->replaceInGroup('web', EncryptCookies::class, SignalDownloadStartCookies::class);
        $middleware->trimStrings(except: ['current_password', 'password', 'password_confirmation']);
        $middleware->append([
            SecureHeaders::class,
            NoCacheMiddleware::class,
            CanInstall::class,
        ]);

        // The login route's lockout signal must wrap its throttle; the priority sort would otherwise hoist the
        // throttle above it (to before SubstituteBindings) and the 429 would never reach it.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: SignalLoginLockout::class);

        $middleware->web(append: [
            ConvertAjaxFormRedirect::class,
            RecordHomePresence::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->group(function () use ($schedule): void {
                $schedule->command('dam:uploads:expire')->hourly();
                $schedule->command('dam:uploads:reconcile')->everyFiveMinutes();
                $schedule->command('dam:vectorizations:expire')->everyFiveMinutes();
                $schedule->command('dam:optimizations:expire')->everyFiveMinutes();
                // Re-indexes changed/failed files and refreshes search words for the rest (docs/ops/studio-ai.md).
                $schedule->command('dam:ai-index:reconcile')->dailyAt('03:30');
                // Files that failed only because the AI service was down or a worker stopped, once it answers again.
                $schedule->command('dam:ai-index:reconcile --transient')->hourlyAt(45);
                // Detection only: a broken nested set is logged to storage/logs/directory-tree.jsonl and
                // repaired by a person with `dam:directories:repair --force` (docs/ops/directory-tree-integrity.md).
                $schedule->command('dam:directories:check')->hourly();
                // Detection only: locator, blob and original findings are logged to storage/logs/storage-integrity.jsonl
                // and repaired by a person with `dam:storage:repair --force` (docs/ops/storage-integrity.md).
                $schedule->command('dam:storage:check')->hourly();
                // A deleted item's files are removed after its delete commits; an unlink that failed then is
                // retried here, each pending tombstone on its own (docs/ops/storage-integrity.md).
                $schedule->command('dam:storage:reap')->hourly();
                // P2 S4: assets trashed more than 30 days ago are deleted for good, through the same reclaim a
                // delete always used.
                $schedule->command('dam:trash:purge')->dailyAt('03:00');
                // Security-event retention the operator set (Q1): full IP 90 days, rows 1 year. `audits` has no
                // period, so dam:audits:prune is never scheduled.
                $schedule->command('dam:security-events:prune')->dailyAt('03:10');
                // P1b analytics: recompute yesterday and today every hour (so the nightly rollup is always done
                // and today's dashboards lag at most an hour); raw 90 days, counts 25 months, terms 30 days (Q1).
                $schedule->command('dam:analytics:rollup')->hourlyAt(17);
                $schedule->command('dam:analytics:prune')->dailyAt('03:20');
                // P5a (D1): guests' self-reported name and email are cleared after the retention period.
                $schedule->command('dam:upload-requests:prune')->dailyAt('03:20');
            });
        // The exporter owns its bounded cache lock. A second background scheduler mutex could survive an
        // interrupted deployment for the default 24 hours and silently prevent every subsequent delivery.
        $schedule->command('dam:analytics:export')->everyMinute()->onOneServer()->runInBackground();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new PostTooLargeResponse);
    })
    ->create();
