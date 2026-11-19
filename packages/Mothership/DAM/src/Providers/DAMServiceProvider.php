<?php

namespace Mothership\DAM\Providers;

use Aws\Handler\Guzzle\GuzzleHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mothership\Admin\DataGrids\Catalog\ProductDataGrid;
use Mothership\Admin\Http\Requests\ProductForm;
use Mothership\Attribute\Models\Attribute;
use Mothership\Attribute\Models\AttributeTranslation;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\AiIndex\NamingContext;
use Mothership\DAM\AiIndex\NullNamingContext;
use Mothership\DAM\Analytics\OptOuts;
use Mothership\DAM\Analytics\Retention;
use Mothership\DAM\Console\Commands\AccessRebuild;
use Mothership\DAM\Console\Commands\AccessReport;
use Mothership\DAM\Console\Commands\AccessSwitch;
use Mothership\DAM\Console\Commands\AiIndexBackfill;
use Mothership\DAM\Console\Commands\AiIndexReconcile;
use Mothership\DAM\Console\Commands\AnalyticsForget;
use Mothership\DAM\Console\Commands\AnalyticsPrune;
use Mothership\DAM\Console\Commands\AnalyticsRollup;
use Mothership\DAM\Console\Commands\AttachmentsMigrate;
use Mothership\DAM\Console\Commands\BackfillThumbnails;
use Mothership\DAM\Console\Commands\BackupStorage;
use Mothership\DAM\Console\Commands\CheckDirectoryTree;
use Mothership\DAM\Console\Commands\CheckStorageIntegrity;
use Mothership\DAM\Console\Commands\CheckVectorization;
use Mothership\DAM\Console\Commands\DamInstaller;
use Mothership\DAM\Console\Commands\DamUpdate;
use Mothership\DAM\Console\Commands\DamUpdateRestore;
use Mothership\DAM\Console\Commands\DamVersion;
use Mothership\DAM\Console\Commands\ExpireChunkUploads;
use Mothership\DAM\Console\Commands\ExpireOptimizations;
use Mothership\DAM\Console\Commands\ExpireVectorizations;
use Mothership\DAM\Console\Commands\FieldsAdopt;
use Mothership\DAM\Console\Commands\GenerateScaleData;
use Mothership\DAM\Console\Commands\GenerateVideoPreviews;
use Mothership\DAM\Console\Commands\HashShareTokens;
use Mothership\DAM\Console\Commands\ImportGeneration;
use Mothership\DAM\Console\Commands\MoveDamAssetsToS3;
use Mothership\DAM\Console\Commands\NameStemsMigrate;
use Mothership\DAM\Console\Commands\OrientationBackfill;
use Mothership\DAM\Console\Commands\PhashBackfill;
use Mothership\DAM\Console\Commands\PosthogExport;
use Mothership\DAM\Console\Commands\PruneAudits;
use Mothership\DAM\Console\Commands\PruneSecurityEvents;
use Mothership\DAM\Console\Commands\PruneUploadRequests;
use Mothership\DAM\Console\Commands\PurgeTrash;
use Mothership\DAM\Console\Commands\ReapFileDeletions;
use Mothership\DAM\Console\Commands\ReclaimLibraryOrphans;
use Mothership\DAM\Console\Commands\ReclaimStorage;
use Mothership\DAM\Console\Commands\ReconcileUploadBatches;
use Mothership\DAM\Console\Commands\RepairDirectoryTree;
use Mothership\DAM\Console\Commands\RepairStorageIntegrity;
use Mothership\DAM\Console\Commands\ReviewShareExpiry;
use Mothership\DAM\Console\Commands\SeedDamDemoData;
use Mothership\DAM\Console\Commands\SyncStyles;
use Mothership\DAM\Console\Commands\TagsBackfillSource;
use Mothership\DAM\Console\Commands\TagsMerge;
use Mothership\DAM\Events\AssetValuesChanged;
use Mothership\DAM\Helpers\Normalizers\ProductValuesNormalizer;
use Mothership\DAM\Http\Middleware\DAM;
use Mothership\DAM\Http\Middleware\EnforceUploadPortal;
use Mothership\DAM\Http\Middleware\ReadOnlyAwareStartSession;
use Mothership\DAM\Media\CliPanoramaWorker;
use Mothership\DAM\Media\PanoramaWorker;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Observers\AiIndexObserver;
use Mothership\DAM\Observers\AssetPropertyObserver;
use Mothership\DAM\Observers\CompoundAssetObserver;
use Mothership\DAM\Repositories\DirectoryRolePermissionRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessFenceLedger;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Automation\AutomationTriggers;
use Mothership\DAM\Services\Automation\CollectionsTarget;
use Mothership\DAM\Services\Automation\CollectionTarget;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\DirectoryTreeLock;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Share\ShareGate;
use Mothership\DAM\Services\SharedHiggsfield;
use Mothership\DAM\Services\Webhooks\DamWebhookEvents;
use Mothership\DAM\Services\WebPagePolicy;
use Mothership\DAM\Support\ChunkUploadLogger;
use Mothership\DAM\Support\LanFirstS3Handler;
use Mothership\DataTransfer\Helpers\Exporters\Product\Exporter;
use Mothership\DataTransfer\Helpers\Import;
use Mothership\DataTransfer\Helpers\Importers\Product\Importer;
use Mothership\DataTransfer\Validators\JobInstances\Import\CategoryJobValidator;
use Mothership\DataTransfer\Validators\JobInstances\Import\ProductJobValidator;
use Mothership\HistoryControl\Services\HistoryEntityAccess;
use Mothership\Product\Normalizer\ProductAttributeValuesNormalizer;
use Mothership\User\Models\Role;
use Mothership\Webhook\Registry\EventRegistry;

class DAMServiceProvider extends ServiceProvider
{
    /**
     * Whole-file transfers of a public share: the attachment download, the
     * directory zip, and any inline request that asks for the entire file.
     * This is the share abuse budget and is deliberately unchanged.
     */
    public const SHARE_DOWNLOAD_LIMIT_PER_MINUTE = 20;

    /**
     * Ranged inline playback of a shared asset. A media element issues one
     * request per seek, per resume and per buffer refill, so a viewer who
     * scrubs steadily produces a couple of requests a second; this matches the
     * budget already granted to the share viewer's other interactive traffic
     * (dam-share-view) rather than to whole-file downloads.
     */
    public const SHARE_MEDIA_RANGE_LIMIT_PER_MINUTE = 120;

    /** P6 D26: how many preview-proxy retries one person may ask for on one asset in a minute. */
    public const VIDEO_PROXY_RETRY_LIMIT_PER_MINUTE = 5;

    /**
     * The only public share routes that stream a single asset a viewer can
     * play inline, and so the only ones the media allowance covers.
     *
     * dam.share.download_zip is deliberately absent. It shares this limiter,
     * but downloadZip always builds a complete streamed archive and ignores
     * both the disposition and the Range header, so a ranged inline request
     * for a zip is still a whole-file transfer and stays in the shared
     * per-address download budget.
     */
    public const SHARE_MEDIA_RANGE_ROUTES = [
        'dam.share.download',
        'dam.share.asset_download',
    ];

    /**
     * P2 S5b/S6: the share routes that only ever stream inline (the original as a preview, and the video proxy), so
     * a ranged request to them is media playback without needing ?disposition=inline.
     */
    public const SHARE_INLINE_MEDIA_ROUTES = [
        'dam.share.preview',
        'dam.share.asset_preview',
        'dam.share.proxy',
        'dam.share.asset_proxy',
    ];

    public $bindings = [
        ProductAttributeValuesNormalizer::class                             => ProductValuesNormalizer::class,
        \Mothership\DataTransfer\Helpers\Exporters\Category\Exporter::class => \Mothership\DAM\Helpers\Exporters\Category\Exporter::class,
        Importer::class                                                     => \Mothership\DAM\Helpers\Importers\Product\Importer::class,
        \Mothership\DataTransfer\Helpers\Importers\Category\Importer::class => \Mothership\DAM\Helpers\Importers\Category\Importer::class,
        Import::class                                                       => \Mothership\DAM\Helpers\Import::class,
        ProductJobValidator::class                                          => \Mothership\DAM\Validators\JobInstances\Import\ProductJobValidator::class,
        CategoryJobValidator::class                                         => \Mothership\DAM\Validators\JobInstances\Import\CategoryJobValidator::class,
        ProductForm::class                                                  => \Mothership\DAM\Http\Requests\ProductForm::class,
        Attribute::class                                                    => \Mothership\DAM\Models\Attribute::class,
        AttributeTranslation::class                                         => \Mothership\DAM\Models\AttributeTranslation::class,
        ProductDataGrid::class                                              => \Mothership\DAM\DataGrids\Catalog\ProductDataGrid::class,
    ];

    /** P5a (D1): a throttled portal chunk call, in the chunk protocol's envelope, so the uploader can back off. */
    protected static function uploadPortalThrottled(array $headers): JsonResponse
    {
        $response = EnforceUploadPortal::json(status: 429, code: 'rate_limited', message: trans('dam::app.upload-portal.errors.rate-limited'), retryable: true);
        $response->headers->add($headers);

        return $response;
    }

    /**
     * A public-viewer media range request: one of the two asset-streaming
     * routes, asked for with the inline disposition the share viewer emits,
     * carrying the Range header a media element sends when it seeks, resumes
     * or refills its buffer. All three are required. The route check keeps the
     * allowance away from the zip route, and the other two keep a plain
     * whole-file fetch from buying the larger budget by appending
     * ?disposition=inline.
     */
    protected static function isSharedMediaRangeRequest(Request $request): bool
    {
        if (! $request->hasHeader('Range')) {
            return false;
        }

        return $request->routeIs(...self::SHARE_INLINE_MEDIA_ROUTES)
            || ($request->routeIs(...self::SHARE_MEDIA_RANGE_ROUTES) && strtolower((string) $request->query('disposition')) === 'inline');
    }

    /**
     * A driver override rather than a config value: a handler object in config would break `config:cache`.
     */
    private function routeS3DiskOverHomeNetwork(): void
    {
        $lanEndpoint = config('dam.asset_disk_lan_endpoint');

        if (! is_string($lanEndpoint) || $lanEndpoint === '') {
            return;
        }

        $this->app->make('filesystem')->extend('s3', function ($app, array $config) use ($lanEndpoint) {
            if (LanFirstS3Handler::appliesTo(diskEndpoint: $config['endpoint'] ?? null, assetEndpoint: config('filesystems.disks.s3.endpoint'))) {
                $config['http_handler'] = new LanFirstS3Handler(
                    transport: new GuzzleHandler,
                    lanEndpoint: $lanEndpoint,
                    cache: $app->make('cache')->store(),
                    logger: $app->make(ChunkUploadLogger::class),
                );
            }

            return (new FilesystemManager($app))->createS3Driver($config);
        });
    }

    public function boot(Router $router)
    {
        $this->routeS3DiskOverHomeNetwork();

        if (! config('dam.transfers.enabled')) {
            config(['menu.admin' => array_filter(config('menu.admin', []), fn (mixed $item): bool => ! is_array($item) || ($item['key'] ?? null) !== 'dam_transfers')]);
        }

        // Feedback triage (Settings → Feedback) stays out of the menu until the feature is switched on.
        if (! config('dam.feedback.enabled')) {
            config(['menu.admin' => array_filter(config('menu.admin', []), fn (mixed $item): bool => ! is_array($item) || ($item['key'] ?? null) !== 'dam.feedback')]);
        }

        Asset::observe(CompoundAssetObserver::class);
        Asset::observe(AiIndexObserver::class);

        // P1b (§3.6.7): a purged asset's raw events lose their subject; its daily counts stay as a deleted asset's.
        Asset::forceDeleted(fn (Asset $asset) => app(Retention::class)->forgetAsset(assetId: (int) $asset->id));
        AssetProperty::observe(AssetPropertyObserver::class);

        // TagController dispatches ['old_values' => …, 'new_values' => …, 'model' => …]; Laravel spreads that
        // array into positional listener arguments, so the asset arrives third.
        Event::listen('core.model.proxy.sync.tag', function ($oldValues = null, $newValues = null, $model = null) {
            if ($model instanceof Asset) {
                AiIndexDispatcher::lexical([(int) $model->id]);
            }
        });

        // P5b (D19, roadmap §3.4): a field, tag or label change feeds the rule triggers (which ignore automation's own
        // writes: the loop guard) and the `dam.asset.updated` webhook. Asset lifecycle events feed the other DAM
        // webhooks; a webhook delivery is never a trigger.
        Event::listen(AssetValuesChanged::class, function (AssetValuesChanged $change): void {
            app(AutomationTriggers::class)->valuesChanged($change);
            app(DamWebhookEvents::class)->assetUpdated($change);
        });
        Asset::created(fn (Asset $asset) => app(DamWebhookEvents::class)->assetCreated((int) $asset->id));
        Asset::softDeleted(fn (Asset $asset) => app(DamWebhookEvents::class)->assetTrashed((int) $asset->id));
        Asset::restored(fn (Asset $asset) => app(DamWebhookEvents::class)->assetRestored((int) $asset->id));

        if (class_exists(EventRegistry::class)) {
            app(EventRegistry::class)->register('dam', DamWebhookEvents::EVENTS);
        }

        // Every folder create, wherever it comes from, gives the new folder its parent's access list
        // (ADR 0010, spec D3). One listener rather than a call per creator: Directory::save() is the one
        // entry point every nested-set insert goes through, and it already runs inside the directory tree
        // lock, so this write is fenced and serialised with a cascade like the create itself. The two raw
        // inserts that bypass the model — DirectoryTableSeeder's root and dam:generate-scale-data — start
        // with an empty list by design.
        Directory::created(fn (Directory $directory) => app(AccessListWriter::class)->inheritFolder($directory->id));

        // The history routes check only the history ACL, and an asset's history carries its comments, properties
        // and linked resources: it is exactly as hidden as the asset (history_id is the asset id for all of them).
        // It goes through the access seam, so a trashed or unavailable asset's history is hidden as the asset is.
        // for() answers a missing id as not visible for everyone: the history controller never looks the asset up,
        // so a missing id would otherwise read as an empty 200 next to a trashed asset's 404.
        app(HistoryEntityAccess::class)->register('asset', function (int $assetId): bool {
            try {
                return app(AssetAccessDecision::class)->for(context: AccessContext::library(), asset: $assetId)->visible;
            } catch (\Throwable) {
                return false;
            }
        });

        // A share's history carries its token, a bearer link: only those who may see the token may read it
        // (Share::tokenVisibleTo). A deleted share's history is left to full admins.
        app(HistoryEntityAccess::class)->register('Share', function (int $shareId): bool {
            try {
                $permissions = app(DirectoryPermissionService::class);
                $share = Share::query()->find($shareId);
                $adminId = auth()->guard('admin')->id();

                return $share === null
                    ? $permissions->fullAdmin()
                    : $share->tokenVisibleTo(adminId: $adminId === null ? null : (int) $adminId, fullAdmin: $permissions->fullAdmin());
            } catch (\Throwable) {
                return false;
            }
        });

        // ADR 0010's fence has to know which transaction the application opened outermost, and which one a
        // hold belongs to. Counting the connection's own events is the only reading that answers the same way
        // in a test as in production: Laravel's DatabaseTransactions wrapper opens and rolls back with the
        // dispatcher unset, so it is invisible here, while every transaction() the code under test opens is
        // not.
        // The connection itself goes to the ledger, not a counted depth: it reads its own physical level, and
        // that is the only reading a multi-level rollback and an ending that fires no event both leave correct.
        Event::listen(TransactionBeginning::class, fn (TransactionBeginning $event) => app(AccessFenceLedger::class)->began($event->connection));
        Event::listen(TransactionCommitted::class, fn (TransactionCommitted $event) => app(AccessFenceLedger::class)->committed($event->connection));
        Event::listen(TransactionRolledBack::class, fn (TransactionRolledBack $event) => app(AccessFenceLedger::class)->rolledBack($event->connection));

        $this->app->booted(function (): void {
            $this->app->bind(Exporter::class, \Mothership\DAM\Helpers\Exporters\Product\Exporter::class);
        });

        $router->aliasMiddleware('dam', DAM::class);

        Route::middleware(['web', 'admin', 'dam'])->prefix(config('app.admin_url').'/home')
            ->group(__DIR__.'/../Routes/home-routes.php');

        // The Tools page and the tool frame. Not under /home: the rail marks a row by address, and a page under
        // Home's address would light Home as well.
        Route::middleware(['web', 'admin', 'dam'])->prefix(config('app.admin_url').'/tools')
            ->group(__DIR__.'/../Routes/tools-routes.php');

        RateLimiter::for('dam-share-thumb', function ($request) {
            return Limit::perMinute(1200)->by('thumb|'.$request->ip());
        });

        // The collector's ingest (P1b, §3.6.6): per admin on staff pages, per address on share pages. A refused
        // batch is dropped by the collector, never retried.
        RateLimiter::for('dam-telemetry', function ($request) {
            $admin = auth('admin')->id();

            return Limit::perMinute((int) config('dam_events.ingest.per_minute', 20))
                ->by('telemetry|'.($admin !== null ? 'admin:'.$admin : 'ip:'.$request->ip()));
        });

        RateLimiter::for('dam-share-view', function ($request) {
            return Limit::perMinute(120)->by('view|'.$request->ip());
        });

        RateLimiter::for('dam-page-file', function ($request) {
            return Limit::perMinute(1200)
                ->by('page|'.substr((string) $request->route('grant'), 0, 32).'|'.$request->ip())
                ->response(fn ($request, array $headers) => WebPagePolicy::throttledResponse(public: false, headers: $headers));
        });

        RateLimiter::for('dam-share-page', function ($request) {
            return Limit::perMinute(1200)
                ->by('page|'.$request->route('token').'|'.$request->ip())
                ->response(fn ($request, array $headers) => WebPagePolicy::throttledResponse(public: true, headers: $headers));
        });

        /*
         * Inline media playback comes down this same route: the public viewer
         * points <video>, <audio> and the PDF frame at ?disposition=inline, and
         * response()->file() answers each browser Range header with its own 206.
         * Ordinary seeking is therefore dozens of requests a minute, which the
         * whole-file budget cut off after the first few seeks -- on 2026-09-19 a
         * single share produced 234 HTTP 429 inside one minute alongside 31
         * partial responses, and playback never recovered.
         *
         * Ranged inline playback gets its own bounded allowance, keyed per share
         * token so one link cannot drain another's. Everything that transfers a
         * whole file -- attachment, zip, or an inline request with no Range
         * header -- stays on the unchanged per-IP download limit.
         */
        RateLimiter::for('dam-share-download', function ($request) {
            if (self::isSharedMediaRangeRequest($request)) {
                return Limit::perMinute(self::SHARE_MEDIA_RANGE_LIMIT_PER_MINUTE)
                    ->by('media|'.$request->route('token').'|'.$request->ip());
            }

            return Limit::perMinute(self::SHARE_DOWNLOAD_LIMIT_PER_MINUTE)->by('dl|'.$request->ip());
        });

        // P6 D26: an embed page load, and the range requests its player makes while it plays and seeks. Media is keyed
        // per token so one embed cannot drain another's allowance.
        RateLimiter::for('dam-embed-view', function ($request) {
            return Limit::perMinute(120)->by('embed|'.$request->ip());
        });

        RateLimiter::for('dam-embed-media', function ($request) {
            return Limit::perMinute(600)->by('embed-media|'.substr((string) $request->route('token'), 0, 64).'|'.$request->ip());
        });

        // P6 D26: re-queueing a preview proxy, per person and asset. A retry while one is queued or running changes
        // nothing, so this only bounds how often anyone can ask.
        RateLimiter::for('dam-video-proxy-retry', function ($request) {
            // The route's own guard: the admin session on the web route, the bearer on REST (auth:api makes it the default).
            $who = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(self::VIDEO_PROXY_RETRY_LIMIT_PER_MINUTE)->by('proxy-retry|'.$who.'|'.$request->route('asset'));
        });

        Route::middleware(['web', 'admin', 'dam'])
            ->prefix(config('app.admin_url').'/dam')
            ->group(__DIR__.'/../Routes/explorer-routes.php');

        // P4: Library organization (labels, boards, saved searches, sections).
        Route::middleware(['web', 'admin', 'dam'])
            ->prefix(config('app.admin_url').'/dam')
            ->group(__DIR__.'/../Routes/organization-routes.php');

        // Feedback & bug reports.
        Route::middleware(['web', 'admin', 'dam'])
            ->prefix(config('app.admin_url').'/dam')
            ->group(__DIR__.'/../Routes/feedback-routes.php');

        // Per person: a burst of 10 reports in 10 minutes, and 50 a day.
        RateLimiter::for('dam-feedback', fn ($request) => [
            Limit::perMinutes(10, max(1, (int) config('dam.feedback.per_10_minutes')))->by('feedback|10m|'.($request->user('admin')?->getAuthIdentifier() ?? $request->ip())),
            Limit::perDay(max(1, (int) config('dam.feedback.per_day')))->by('feedback|day|'.($request->user('admin')?->getAuthIdentifier() ?? $request->ip())),
        ]);

        Route::middleware('web')->group(__DIR__.'/../Routes/web.php');

        // P2 S5b: the share unlock cookie carries its own HMAC and must read the same on the session-free page routes,
        // which never run EncryptCookies, as on the `web` share routes, which do.
        EncryptCookies::except(ShareGate::COOKIE);

        Route::group([], __DIR__.'/../Routes/share-public.php');

        Route::group([], __DIR__.'/../Routes/web-pages.php');

        Route::group([], __DIR__.'/../Routes/embed.php');

        // P5a (D1): the guest upload portal, per IP and per link; the budgets are read when a request is counted.
        RateLimiter::for('dam-upload-portal', fn ($request) => [
            Limit::perMinute(max(1, (int) config('dam.upload_requests.rate.portal_per_ip')))->by('upload-portal|ip|'.$request->ip()),
            Limit::perMinute(max(1, (int) config('dam.upload_requests.rate.portal_per_request')))
                ->by('upload-portal|link|'.UploadRequest::hashToken((string) $request->route('token'))),
        ]);

        RateLimiter::for('dam-upload-portal-chunk', fn ($request) => [
            Limit::perMinute(max(1, (int) config('dam.upload_requests.rate.chunk_per_ip')))->by('upload-chunk|ip|'.$request->ip())
                ->response(fn ($request, array $headers) => self::uploadPortalThrottled($headers)),
            Limit::perMinute(max(1, (int) config('dam.upload_requests.rate.chunk_per_request')))
                ->by('upload-chunk|link|'.UploadRequest::hashToken((string) $request->route('token')))
                ->response(fn ($request, array $headers) => self::uploadPortalThrottled($headers)),
        ]);

        Route::group([], __DIR__.'/../Routes/upload-portal.php');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'dam');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dam');

        $this->app->register(EventServiceProvider::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                DamInstaller::class,
                BackfillThumbnails::class,
                SeedDamDemoData::class,
                GenerateScaleData::class,
                GenerateVideoPreviews::class,
                DamVersion::class,
                ExpireChunkUploads::class,
                ReconcileUploadBatches::class,
                ExpireVectorizations::class,
                CheckVectorization::class,
                ExpireOptimizations::class,
                AiIndexBackfill::class,
                AiIndexReconcile::class,
                AttachmentsMigrate::class,
                NameStemsMigrate::class,
                FieldsAdopt::class,
                TagsBackfillSource::class,
                TagsMerge::class,
                OrientationBackfill::class,
                PhashBackfill::class,
                DamUpdate::class,
                DamUpdateRestore::class,
                ImportGeneration::class,
                SyncStyles::class,
                CheckDirectoryTree::class,
                RepairDirectoryTree::class,
                CheckStorageIntegrity::class,
                RepairStorageIntegrity::class,
                ReclaimLibraryOrphans::class,
                // Operator-run only (ADR 0009 slice 4); never scheduled.
                BackupStorage::class,
                ReclaimStorage::class,
                // P1a retention: security events on the daily schedule (Q1); audits operator-run only.
                PruneSecurityEvents::class,
                PruneUploadRequests::class,
                PruneAudits::class,
                // P1b analytics: rollup hourly and prune daily (bootstrap/app.php); forget operator-run.
                AnalyticsRollup::class,
                AnalyticsPrune::class,
                AnalyticsForget::class,
                PosthogExport::class,
                ReapFileDeletions::class,
                PurgeTrash::class,
                AccessRebuild::class,
                AccessReport::class,
                AccessSwitch::class,
                // P2 S5a, operator-run only; never scheduled.
                HashShareTokens::class,
                ReviewShareExpiry::class,
            ]);
        }

        Blade::anonymousComponentPath(__DIR__.'/../Resources/views/components', 'dam');

        view()->composer('dam::admin.roles.dam-permissions-tab', function ($view) {
            $roleId = request()->route('id');
            $role = $roleId ? Role::find($roleId) : null;

            $settings = $role
                ? DB::table('dam_role_settings')->where('role_id', $role->id)->first()
                : null;

            $grantedIds = $role
                ? app(DirectoryRolePermissionRepository::class)->getAllGrantedIds($role->id)
                : [];
            $inheritChildren = (bool) ($settings?->inherit_children ?? false);
            $rolePermissions = collect($role?->permissions ?? []);
            $selectedCapabilities = collect(RoleBuilder::CAPABILITIES)
                ->filter(fn (array $permissions) => collect($permissions)->every(
                    fn (string $permission) => $rolePermissions->contains($permission)
                ))
                ->keys()
                ->all();
            $selectedWidgetIds = $settings?->home_widget_ids === null
                ? HomeWidgetRegistry::defaultWidgetIds()
                : array_values((array) json_decode($settings->home_widget_ids, true));

            $damEnabled = true;

            $view->with([
                'role'                       => $role,
                'grantedIds'                 => $grantedIds,
                'allDirectories'             => (bool) ($settings?->all_directories ?? false),
                'inheritChildren'            => $inheritChildren,
                'selectedCapabilities'       => $role ? $selectedCapabilities : [],
                'widgets'                    => config('dam-home.widgets', []),
                'selectedWidgetIds'          => $selectedWidgetIds,
                'damEnabled'                 => $damEnabled,
                // Every custom role already holds the share keys under lists (sharing slice 5, D7), so the
                // Sandbox panel stops offering a capability that no longer means anything to toggle.
                'shareCapabilityManaged'     => app(AccessModel::class)->lists(),
                'routeDirectory'             => route('admin.dam.directory.index'),
                'routeDirectoryPaths'        => route('admin.dam.directory.paths'),
                'routeDirectoryChildren'     => route('admin.dam.directory.children', ['id' => '__ID__']),
                'routeDirectoryDescendants'  => route('admin.dam.directory.descendants', ['id' => '__ID__']),
            ]);
        });

        $this->publishes([
            __DIR__.'/../Resources/assets/images' => storage_path('app/public/dam'),
        ], 'dam-defaults');

        $this->publishes([
            __DIR__.'/../../publishable' => public_path('themes'),
        ], 'dam-config');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/home.php', 'dam-home');
        $this->mergeConfigFrom(__DIR__.'/../Config/stylepress.php', 'stylepress');
        $this->mergeConfigFrom(__DIR__.'/../Config/vectorization.php', 'vectorization');
        $this->mergeConfigFrom(__DIR__.'/../Config/optimization.php', 'optimization');
        $this->mergeConfigFrom(__DIR__.'/../Config/ai_index.php', 'ai-index');
        $this->mergeConfigFrom(__DIR__.'/../Config/ai_vocabulary.php', 'ai-vocabulary');
        $this->mergeConfigFrom(__DIR__.'/../Config/tags.php', 'dam-tags');
        $this->mergeConfigFrom(__DIR__.'/../Config/related.php', 'dam-related');
        $this->mergeConfigFrom(__DIR__.'/../Config/dam_events.php', 'dam_events');
        $helpers = __DIR__.'/../Http/helpers.php';
        if (file_exists($helpers)) {
            require_once $helpers;
        }

        // Beacon routes read the session without saving it (ReadOnlySession), in StartSession's own place in the stack.
        $this->app->singleton(StartSession::class, fn ($app) => new ReadOnlyAwareStartSession(
            $app->make(SessionManager::class), fn () => $app->make(CacheFactory::class),
        ));

        $this->app->scoped(DirectoryPermissionService::class);

        // One opt-out read per admin per request or job, however many events it emits (P1b).
        $this->app->scoped(OptOuts::class);

        // One read of the DAM_ACCESS_MODEL switch per request or queue job (ADR 0010).
        $this->app->scoped(AccessModel::class);

        // The fence's bookkeeping belongs to the connection, not to the request: a singleton, so a scoped
        // AccessModel rebuilt between jobs inherits it and every instance in the process reads the same one.
        $this->app->singleton(AccessFenceLedger::class);

        // P5b: rules' "add to collection" goes through the P4c collection service.
        $this->app->bind(CollectionTarget::class, CollectionsTarget::class);

        // Resolved once per folder create and once per attach, move or copy batch (sharing slice 2a).
        $this->app->scoped(AccessListWriter::class);

        // DirectoryTreeLock's re-entrancy lives in its per-instance $depth: every Directory::save() inside
        // an explicit Directory::withTreeLock() must resolve the SAME instance to join the outer hold
        // instead of opening a savepoint and taking the lock a second time (ADR 0004).
        $this->app->scoped(DirectoryTreeLock::class);

        // HomeStylePressController resolves this fresh with `app(SharedHiggsfield::class)` at
        // several separate call sites (enabled(), estimate(), submit(), poll(), enhance()...),
        // sometimes more than once inside a single HTTP request — estimate() then submit() in one
        // generate() action, for instance. Its shared CLI deadline (SharedHiggsfield::$deadlineAnchor)
        // is meant to cover one HTTP window across ALL of those calls, not restart for each
        // resolution: a plain (unscoped) instance would silently hand every call its own fresh
        // budget instead, exactly the SphereSeam bug this class was fixed for on 2026-09-16, just
        // from the opposite direction. `scoped()` makes every app(SharedHiggsfield::class) call
        // within one request (or one queue job attempt) return the SAME instance, and Laravel
        // clears it automatically between requests/jobs — GenerateSphereSeamBridge additionally
        // forgets it explicitly in a finally block so a released-and-redispatched attempt never
        // inherits a prior attempt's already-spent budget even if that automatic reset ever changes.
        $this->app->scoped(SharedHiggsfield::class);

        $this->app->bind(PanoramaWorker::class, CliPanoramaWorker::class);

        // Rename with AI asks NamingContext what was on at a capture time and where it was. Nothing ships that
        // answers it; bind your own implementation over this one to plug a calendar or a gazetteer back in.
        $this->app->bind(NamingContext::class, NullNamingContext::class);

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/acl.php', 'acl');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/api-acl.php', 'api-acl');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/dam.php', 'dam');

        $this->mergeConfigFrom(
            __DIR__.'/../Config/mothership-vite.php',
            'mothership-vite.viters'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../Config/attribute_types.php',
            'attribute_types'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../Config/category_field_types.php',
            'category_field_types'
        );

        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');

        $this->commands([
            MoveDamAssetsToS3::class,
        ]);
    }
}
