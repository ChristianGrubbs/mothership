<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Exceptions\AssetDeletionException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\WebCollection;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\PageGrant;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\WebCollectionService;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\DAM\Services\WebPageMembers;
use Mothership\DAM\Services\WebPagePolicy;
use Mothership\DAM\Services\WebPageResolver;
use Mothership\DAM\Services\WebPageTelemetry;
use Mothership\DAM\Traits\AssetAccessControl;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WebPageController extends Controller
{
    use AssetAccessControl;

    /** Marks an injected capture script so tests and the injector itself can detect it without re-parsing the script body. */
    public const CAPTURE_MARKER = '<!--mothership-capture-->';

    /**
     * The Web Pages sidebar area: a server-rendered card grid of `file_type = 'web'` assets for one
     * collection level (`?collection=`; root = unfiled pages), built by WebCollectionService::listing().
     */
    public function index(Request $request, DirectoryPermissionService $permissions, WebCollectionService $collections, RoleBuilder $roles)
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);

        [$collection, $missing] = $this->requestedCollection($request);
        $filters = $this->listingFilters($request);
        $listing = $collections->listing($collection, $filters, $permissions);
        $pages = $listing['pages'];
        $tokens = $this->activeShareTokens($pages->getCollection(), $permissions);
        $deletableIds = bouncer()->hasPermission('dam.asset.destroy') ? $roles->ownedAssetIds(array_map(fn (Asset $page) => $page->id, $pages->items())) : [];

        $can = [
            'organize' => bouncer()->hasPermission('dam.pages.organize'),
            'share'    => bouncer()->hasPermission('dam.asset.share'),
            // 'web_pages' only grants dam.pages, dam.pages.organize, dam.asset.upload, dam.asset.view
            // (per the ADR-set capability list), which reaches this page but not the classic Library
            // where uploads and downloads actually happen -- gate the visible controls on the
            // permissions that action needs, not the capability that got the role here.
            'upload'   => bouncer()->hasPermission('dam.assets') && bouncer()->hasPermission('dam.asset.upload'),
            'download' => bouncer()->hasPermission('dam.asset.download'),
        ];

        return view('dam::pages.index', [
            'listing' => [
                'collection'         => $collection?->id,
                'missing_collection' => $missing,
                'breadcrumb'         => $listing['breadcrumb'],
                'children'           => $listing['children'],
                'search_mode'        => $listing['search_mode'],
                'pages'              => [
                    'data'         => $pages->getCollection()->map(fn (Asset $page) => $this->presentPage(page: $page, canDelete: in_array($page->id, $deletableIds, true), token: $tokens[$page->id] ?? null))->values(),
                    'current_page' => $pages->currentPage(),
                    'last_page'    => $pages->lastPage(),
                    'total'        => $pages->total(),
                ],
                'filters' => ['owner' => $filters['owner'], 'q' => $filters['q'], 'sort' => $filters['sort']],
            ],
            'routes' => [
                // update/destroy/show/grant/download/customThumbnail are base URLs: the client
                // appends '/{id}' (and, for show/grant, '/open' or '/grant') itself, the same
                // pattern the frame-script.blade.php grant fetch already uses.
                'listing'          => route('admin.dam.pages.listing'),
                'store'            => route('admin.dam.pages.collections.store'),
                'update'           => url(config('app.admin_url').'/dam/pages/collections'),
                'destroy'          => url(config('app.admin_url').'/dam/pages/collections'),
                'move'             => route('admin.dam.pages.move'),
                'show'             => url(config('app.admin_url').'/dam/pages'),
                'grant'            => url(config('app.admin_url').'/dam/pages'),
                'download'         => url(config('app.admin_url').'/dam/assets/download'),
                'upload'           => route('admin.dam.assets.upload'),
                'sharedLinksStore' => route('admin.dam.shared-links.store'),
                // Share pages in folder: the client appends '/{collection}/candidates'.
                'shareCandidates'  => url(config('app.admin_url').'/dam/shared-links/web-collections'),
                'customThumbnail'  => url(config('app.admin_url').'/dam/assets'),
                'trashPage'        => url(config('app.admin_url').'/dam/pages'),
            ],
            'can'               => $can,
            'labels'            => $this->browserLabels(),
            'notice'            => $missing ? trans('dam::app.admin.dam.web-pages.browser.missing-collection') : null,
            'uploadDirectories' => $this->uploadDirectories($permissions, canUpload: $can['upload']),
        ]);
    }

    /**
     * Where Upload here files a page in the Library. Web pages get one dedicated root folder so they
     * never mix into content folders; a bypass role creates it on first visit. A role without a
     * grant on it keeps the old picker of its directly granted directories.
     */
    protected function uploadDirectories(DirectoryPermissionService $permissions, bool $canUpload): Collection
    {
        $name = (string) config('dam.web_pages.upload_directory', 'Web Pages');
        $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first();
        $find = fn () => $root ? Directory::query()->where('parent_id', $root->id)->where('name', $name)->orderBy('id')->first(['id', 'name']) : null;
        $dedicated = $find();

        if (! $dedicated && $root && $canUpload && $permissions->roleBypass()) {
            try {
                // Re-check under the (re-entrant) tree lock so two first visits cannot both create it.
                $dedicated = Directory::withTreeLock(function () use ($find, $name, $root) {
                    if ($existing = $find()) {
                        return $existing;
                    }

                    $created = app(DirectoryRepository::class)->create(['name' => $name, 'parent_id' => $root->id]);
                    WebPageTelemetry::record('web_pages.upload_directory_created', ['directory_id' => $created->id]);

                    return $created;
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($dedicated && in_array((int) $dedicated->id, $permissions->directlyGrantedIds(), true)) {
            return collect([['id' => (int) $dedicated->id, 'name' => $dedicated->name]]);
        }

        // viewableIds() includes breadcrumb-only ancestors of a granted directory (so navigation
        // can walk down to it) that are not themselves upload targets. The picker offers only the
        // directories the role is actually granted -- directlyGrantedIds() (already every
        // directory when the role bypasses) -- not everything the role can merely see through.
        return Directory::query()
            ->whereIn('id', $permissions->directlyGrantedIds())
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn (Directory $directory) => ['id' => (int) $directory->id, 'name' => $directory->name]);
    }

    /** Every lang string the Vue browser renders, keyed to what the template calls it. */
    protected function browserLabels(): array
    {
        return trans('dam::app.admin.dam.web-pages');
    }

    /** JSON twin of index() for the Vue browser (PR B); `tree=1` nests every descendant for Move-to. */
    public function listing(Request $request, DirectoryPermissionService $permissions, WebCollectionService $collections, RoleBuilder $roles): JsonResponse
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);

        [$collection, $missing] = $this->requestedCollection($request);
        $filters = $this->listingFilters($request);
        $listing = $collections->listing($collection, $filters, $permissions);
        $pages = $listing['pages'];
        $tokens = $this->activeShareTokens($pages->getCollection(), $permissions);
        $deletableIds = bouncer()->hasPermission('dam.asset.destroy') ? $roles->ownedAssetIds(array_map(fn (Asset $page) => $page->id, $pages->items())) : [];

        return response()->json([
            'collection'         => $collection?->id,
            'missing_collection' => $missing,
            'breadcrumb'         => $listing['breadcrumb'],
            'children'           => $listing['children'],
            'search_mode'        => $listing['search_mode'],
            'pages'              => [
                'data'         => $pages->getCollection()->map(fn (Asset $page) => $this->presentPage(page: $page, canDelete: in_array($page->id, $deletableIds, true), token: $tokens[$page->id] ?? null))->values(),
                'current_page' => $pages->currentPage(),
                'last_page'    => $pages->lastPage(),
                'total'        => $pages->total(),
            ],
            'filters' => ['owner' => $filters['owner'], 'q' => $filters['q'], 'sort' => $filters['sort']],
            'can'     => [
                'organize' => bouncer()->hasPermission('dam.pages.organize'),
                'share'    => bouncer()->hasPermission('dam.asset.share'),
                'upload'   => bouncer()->hasPermission('dam.assets') && bouncer()->hasPermission('dam.asset.upload'),
                'download' => bouncer()->hasPermission('dam.asset.download'),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /** @return array{0: ?WebCollection, 1: bool} the requested collection and whether it was asked for but is gone */
    protected function requestedCollection(Request $request): array
    {
        $id = (int) $request->input('collection');
        $collection = $id > 0 ? WebCollection::query()->find($id) : null;

        return [$collection, $id > 0 && $collection === null];
    }

    protected function listingFilters(Request $request): array
    {
        $directoryId = (int) $request->input('directory_id');
        $sort = (string) $request->input('sort', 'newest');

        return [
            'owner'        => $request->input('owner', 'everyone') === 'me' ? 'me' : 'everyone',
            'admin_id'     => auth('admin')->id(),
            'directory_id' => $directoryId ?: null,
            'q'            => trim((string) $request->input('q')),
            'sort'         => in_array($sort, WebCollectionService::SORTS, true) ? $sort : 'newest',
            'tree'         => $request->boolean('tree'),
        ];
    }

    protected function thumbnailUrl(Asset $asset): string
    {
        return $asset->path ? route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]) : '';
    }

    /**
     * The capability URL token of each page's active link, only for the viewer who may use it (creator, page
     * owner or full admin); everyone else still sees that a link exists, as a null token.
     *
     * @return array<int, string|null> page id => token or null
     */
    protected function activeShareTokens(Collection $pages, DirectoryPermissionService $permissions): array
    {
        $shareIds = $pages->pluck('active_share_id')->filter()->all();
        $shares = $shareIds === [] ? collect() : Share::query()->whereIn('id', $shareIds)->get()->keyBy('id');
        $adminId = auth()->guard('admin')->id();

        return $pages->mapWithKeys(function (Asset $page) use ($shares, $adminId, $permissions) {
            $share = $shares->get($page->active_share_id);
            $share?->setRelation('asset', $page);
            $visible = $share?->tokenVisibleTo(adminId: $adminId === null ? null : (int) $adminId, fullAdmin: $permissions->fullAdmin());

            return [$page->id => $visible ? $share->urlToken() : null];
        })->all();
    }

    protected function presentPage(Asset $page, bool $canDelete, ?string $token = null): array
    {
        $manifest = $page->meta_data['web_bundle'] ?? null;

        return [
            'id'                 => $page->id,
            'can_delete'         => $canDelete,
            'display_name'       => $page->display_name,
            'file_name'          => $page->file_name,
            'updated_at'         => $page->updated_at?->toIso8601String(),
            'creator'            => $page->creator?->name,
            'versions_count'     => (int) $page->versions_count + 1,
            'active_share_token' => $token,
            'has_active_share'   => $page->active_share_id !== null,
            'collection_id'      => $page->web_collection_id !== null ? (int) $page->web_collection_id : null,
            'collection_path'    => $page->collection_path,
            'library_directory'  => $page->library_directory,
            'state'              => ! $manifest ? 'preparing' : (! empty($manifest['error']) ? 'failed' : 'ready'),
            'error'              => $manifest['error'] ?? null,
            'thumbnail_url'      => $this->thumbnailUrl($page),
        ];
    }

    /** Move a visible web asset to the existing restorable trash without reclaiming its bytes. */
    public function destroy(int $id, AssetTrashService $trash, RoleBuilder $roles): JsonResponse
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);
        $asset = $this->damVisibleAssetOrFail($id);
        abort_unless($asset->file_type === 'web', 404);
        abort_unless($roles->canDelete($asset), 403);

        if ($asset->resources()->exists()) {
            return response()->json(['message' => trans('dam::app.admin.dam.asset.delete-failed-due-to-attached-resources')], 422);
        }

        try {
            $trash->trash(asset: $asset);
        } catch (AssetDeletionException $exception) {
            return response()->json(['message' => trans('dam::app.admin.dam.web-pages.trash-page-blocked')], 422);
        }

        return response()->json(['message' => trans('dam::app.admin.dam.web-pages.page-trashed')]);
    }

    /**
     * The stack members of a web page, in canonical order, each with its pin (file id and FULL checksum) and the state
     * of its isolated cache. Observational: it never builds, queues or writes. Gated by the same `dam.asset.view`
     * capability as the preview itself (Config/acl.php), not by any relationship-editing permission.
     */
    public function members(int $id, WebPageMembers $members): JsonResponse
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);
        $asset = $this->damVisibleAssetOrFail($id);
        abort_unless($asset->file_type === 'web', 404);

        return response()->json(['members' => $members->listing($asset)])->header('Cache-Control', 'no-store');
    }

    /**
     * Session-authenticated: hands the preview a grant URL. Without file context it is the current bundle version of the
     * asset's own page (unchanged). With `file` and `checksum` (both, the FULL 64 hex checksum) it is that one current
     * stack member's page: a missing, foreign, hidden, merged or no-longer-current file is 404, a checksum that is not
     * the current file's is an explicit 409 `stale`, and nothing is built unless every check passed.
     */
    public function grant(Request $request, int $id, WebPageMembers $members): JsonResponse
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);
        $asset = $this->damVisibleAssetOrFail($id);
        $context = $this->memberContext($request);
        abort_if($context === false, 404);

        if ($context !== null) {
            return $this->grantMember(asset: $asset, context: $context, members: $members);
        }

        if (WebPageBundler::rootFor($asset) === null) {
            $error = $asset->meta_data['web_bundle']['error'] ?? null;

            return response()->json(['state' => $error ? 'failed' : 'preparing', 'error' => $error], 409)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json(['url' => PageGrant::baseUrl($asset, (int) auth('admin')->id())])->header('Cache-Control', 'no-store');
    }

    /** The full-screen admin view; the frame inside it fetches its own grant. A member pin must name a current member. */
    public function show(Request $request, int $id, WebPageMembers $members)
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);
        $asset = $this->damVisibleAssetOrFail($id);
        $context = $this->memberContext($request);
        $file = $context === null || $context === false ? null : $members->current($asset, $context['file']);
        abort_if($context === false || ($context !== null && $file === null), 404);

        // A pinned page names the pinned file in its heading and frame title, never the stack's representative.
        return view('dam::pages.show', [
            'asset'      => $asset,
            'member'     => $context,
            'memberName' => $file === null ? null : $members->nameOf(asset: $asset, file: $file),
        ]);
    }

    /**
     * The explicit file context of a request: null when none was sent, false when it is malformed (a lone half, a
     * non-numeric id, anything but a lowercase 64 hex checksum), else the pair. Both halves are always required.
     *
     * @return array{file: int, checksum: string}|false|null
     */
    protected function memberContext(Request $request): array|false|null
    {
        if (! $request->query->has('file') && ! $request->query->has('checksum')) {
            return null;
        }

        $file = $request->query->all()['file'] ?? null;
        $checksum = $request->query->all()['checksum'] ?? null;

        if (! is_string($file) || ! ctype_digit($file) || strlen($file) > 18 || (int) $file < 1 || ! WebPageMembers::isChecksum($checksum)) {
            return false;
        }

        return ['file' => (int) $file, 'checksum' => $checksum];
    }

    /** @param  array{file: int, checksum: string}  $context */
    protected function grantMember(Asset $asset, array $context, WebPageMembers $members): JsonResponse
    {
        $file = $members->current($asset, $context['file']);
        abort_if($file === null, 404);

        if (! hash_equals($members->checksumOf($file), $context['checksum'])) {
            WebPageTelemetry::record('web_pages.member_stale', ['asset_id' => $asset->id, 'attachment_id' => $file->attachment_id, 'file_id' => $file->id, 'state' => 'checksum']);

            return response()->json(['state' => 'stale'], 409)->header('Cache-Control', 'no-store');
        }

        $status = $members->ensure($asset, $file);
        $headers = ['Cache-Control' => 'no-store'];

        return match ($status['state']) {
            'ready' => response()->json([
                'url'           => PageGrant::memberBaseUrl(asset: $asset, file: $file, checksum: $context['checksum'], adminId: (int) auth('admin')->id()),
                'file_id'       => (int) $file->id,
                'attachment_id' => (int) $file->attachment_id,
                'checksum'      => $context['checksum'],
            ], 200, $headers),
            'failed'      => response()->json(['state' => 'failed', 'error' => $status['error'] ?? null], 409, $headers),
            'invalid'     => response()->json(['state' => 'failed', 'error' => 'cache_invalid'], 409, $headers),
            'unsupported' => response()->json(['state' => 'unsupported'], 422, $headers),
            default       => response()->json(['state' => 'preparing'], 409, $headers),
        };
    }

    /** Session-free: authorised by the grant in the path (a bundle-version grant, or a member grant). */
    public function file(WebPageResolver $resolver, WebPageMembers $members, int $id, string $grant, string $path = '')
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);
        $asset = Asset::query()->find($id);
        $claims = $asset ? PageGrant::verify($grant, $asset->id) : null;

        if ($claims === null && $asset !== null && ($memberClaims = PageGrant::verifyMember($grant, $asset->id)) !== null) {
            return $this->serveMember(asset: $asset, claims: $memberClaims, path: $path, resolver: $resolver, members: $members);
        }

        abort_if($claims === null || $claims['version'] !== WebPageBundler::versionNumber($asset), 404);

        // The grant is a bearer capability for up to an hour; the entry document re-checks that the
        // admin it names can still reach the asset, so a revoked directory grant closes the page on
        // the next load instead of at expiry (audit A2). Siblings stay grant-only (cheap, cacheable).
        $path = $this->requestPath($path);
        $resolved = $resolver->resolve($asset, $path);

        if ($resolved['status'] === 200 && $resolved['is_entry'] && ! DirectoryPermissionService::adminCanAccessAsset(adminId: $claims['admin'], assetId: $asset->id)) {
            WebPageTelemetry::record('web_pages.grant_rejected', ['asset_id' => $asset->id, 'admin_id' => $claims['admin'], 'reason' => 'access_revoked']);
            abort(404);
        }

        return $this->serve($asset, $path, $resolver, public: false, resolved: $resolved);
    }

    /**
     * A member grant names one current file. Every request, the entry and each sibling, finds that file a current member
     * of the asset with the same slot and FULL checksum, or answers 404: a revision, move, re-role or removal ends the
     * grant at once. The named admin must still reach the asset before anything is built, and for the entry always.
     *
     * @param  array{asset: int, attachment: int, file: int, checksum: string, admin: int, exp: int}  $claims
     */
    protected function serveMember(Asset $asset, array $claims, string $path, WebPageResolver $resolver, WebPageMembers $members)
    {
        $file = $members->current($asset, $claims['file']);

        if ($file === null || (int) $file->attachment_id !== $claims['attachment'] || ! hash_equals($members->checksumOf($file), $claims['checksum'])) {
            WebPageTelemetry::record('web_pages.grant_rejected', ['asset_id' => $asset->id, 'file_id' => $claims['file'], 'admin_id' => $claims['admin'], 'reason' => 'member_stale']);
            abort(404);
        }

        $revoked = fn (): bool => ! DirectoryPermissionService::adminCanAccessAsset(adminId: $claims['admin'], assetId: $asset->id);
        $checked = false;

        // A cache that has to be (re)built is only started for an admin who still reaches the asset.
        if ($members->state($asset, $file)['state'] !== 'ready') {
            $checked = true;

            if ($revoked()) {
                WebPageTelemetry::record('web_pages.grant_rejected', ['asset_id' => $asset->id, 'admin_id' => $claims['admin'], 'reason' => 'access_revoked']);
                abort(404);
            }
        }

        $path = $this->requestPath($path);
        $resolved = $members->resolve($asset, $file, $path);

        if ($resolved['status'] === 200 && $resolved['is_entry'] && ! $checked && $revoked()) {
            WebPageTelemetry::record('web_pages.grant_rejected', ['asset_id' => $asset->id, 'admin_id' => $claims['admin'], 'reason' => 'access_revoked']);
            abort(404);
        }

        return $this->serve($asset, $path, $resolver, public: false, resolved: $resolved);
    }

    /**
     * Laravel matches a route on the trimmed path, so the {path} parameter is `docs` for both
     * `.../docs` and `.../docs/`. The trailing slash decides whether a directory is served or
     * redirected, so it is read back off the real request URI instead of the route parameter.
     */
    public function requestPath(string $path): string
    {
        return ($path !== '' && str_ends_with(request()->getPathInfo(), '/')) ? $path.'/' : $path;
    }

    /**
     * @param  array|null  $resolved  a resolver result for the already request-normalised $path; when
     *                                given, $path is not normalised or resolved again.
     */
    public function serve(Asset $asset, string $path, WebPageResolver $resolver, bool $public, ?array $resolved = null)
    {
        $request = request();
        $endsWithSlash = str_ends_with($request->getPathInfo(), '/');

        if ($resolved === null) {
            $path = $this->requestPath($path);
            $resolved = $resolver->resolve($asset, $path);
        }

        if ($resolved['status'] === 302) {
            return $this->trailingSlashRedirect($request);
        }

        if ($resolved['status'] !== 200) {
            abort($resolved['status'], match ($resolved['status']) {
                409     => 'The page bundle is still being prepared.',
                422     => 'The page bundle could not be prepared.',
                default => 'Not found.',
            });
        }

        // The bundle root is a directory like any other, but an empty path never reaches the
        // resolver as one -- it resolves straight to the entry -- so a slashless root URL would
        // serve index.html while the browser re-based its relative resources on the *parent*
        // segment, outside the bundle. The share families make this reachable
        // (`/share/{token}/page` and, for a directory share, `/share/{token}/page/{assetId}`).
        // Deliberately after the status checks above, so a missing or still-building bundle keeps
        // answering 404/409 instead of redirecting to a URL that would answer the same.
        if ($path === '' && ! $endsWithSlash) {
            return $this->trailingSlashRedirect($request);
        }

        // Only the admin route captures, and only in the capture entry: the entry itself, or the
        // page a launcher entry refreshes to, since the launcher navigates away before it fires.
        $captures = ! $public && $resolved['is_capture_entry'];

        $headers = [
            'Content-Type'  => $resolved['mime'],
            // The capture entry's bytes change once a thumbnail exists, so it is never cached either.
            'Cache-Control' => WebPagePolicy::cacheControl($public, $resolved['is_entry'] || $captures),
        ];

        if ($resolved['attachment']) {
            $headers['Content-Disposition'] = 'attachment; filename="'.str_replace(['"', "\r", "\n", "\0"], '', basename($resolved['absolute'])).'"';
        }

        if ($resolved['mime'] === 'text/html') {
            $html = file_get_contents($resolved['absolute']);

            // Only while no thumbnail has been captured or manually set yet -- once
            // meta_data['custom_thumbnail'] is populated the page is served byte-for-byte again.
            if ($captures && empty($asset->meta_data['custom_thumbnail'])) {
                $script = self::CAPTURE_MARKER.'<script>'.file_get_contents(__DIR__.'/../../Resources/assets/js/web-page-capture.js').'</script>';
                $html = $this->injectIntoHead($html, $script);
            }

            // Left-hand side wins in a union: the charset must override the resolver's bare mime.
            return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
        }

        // Not response()->file(): that constructs a BinaryFileResponse with $public = true, which
        // calls setPublic() and overwrites the private page cache policy with `public`.
        // Never X-Accel-Redirect: nginx keeps only a few upstream headers across that internal
        // redirect and drops the page policy, so a bundle SVG opened directly would run script in
        // the app origin without the CSP sandbox, and CORS-mode siblings (fonts, module scripts)
        // would lose Access-Control-Allow-Origin in the opaque-origin frame.
        return new BinaryFileResponse($resolved['absolute'], 200, $headers, public: false);
    }

    /**
     * Inserts $script right after the entry's real `<head ...>` start tag. A single scan walks
     * the document in order, one complete token at a time, so a `<head>`-looking substring is
     * never mistaken for the real tag when it appears: inside an HTML comment
     * (`<!-- <head> -->`), inside a raw-text element's content (`<script>const s = "<head>"`,
     * where nothing between `<script>` and its own `</script>` is markup at all), or inside
     * another tag's quoted attribute value (`<html data-x="<head>">`, `<head data-x=">">`). Each
     * alternative below consumes its whole token -- comment, raw-text element with its closing
     * tag, the head tag itself, the html tag itself, or any other complete tag -- so the
     * generic-tag catch-all never lets the scan land mid-token. Falls back to right after
     * `<html ...>`, then right after a leading `<!doctype ...>`, then prepends.
     */
    protected function injectIntoHead(string $html, string $script): string
    {
        $pattern = '/<!--.*?-->'
            .'|<(script|style|textarea|title)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?<\/\1\s*>'
            .'|(<head\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)'
            .'|(<html\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)'
            .'|<[a-zA-Z!\/](?:[^>"\']|"[^"]*"|\'[^\']*\')*>'
            .'/is';

        $headOffset = null;
        $htmlOffset = null;

        if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($headOffset === null && isset($match[2]) && $match[2][1] !== -1) {
                    $headOffset = $match[2][1] + strlen($match[2][0]);
                }
                if ($htmlOffset === null && isset($match[3]) && $match[3][1] !== -1) {
                    $htmlOffset = $match[3][1] + strlen($match[3][0]);
                }
            }
        }

        if ($headOffset !== null) {
            return substr_replace($html, $script, $headOffset, 0);
        }

        if ($htmlOffset !== null) {
            return substr_replace($html, $script, $htmlOffset, 0);
        }

        if (preg_match('/^\s*<!doctype[^>]*>/i', $html, $doctype)) {
            return substr_replace($html, $script, strlen($doctype[0]), 0);
        }

        return $script.$html;
    }

    /**
     * Redirects to the request's own URL with a trailing slash, so the browser re-bases the
     * page's relative URLs inside the directory. Built from the request rather than the route
     * name because the same method serves the admin and the share URL families. Not
     * redirect()->to(): UrlGenerator trims the trailing slash that is the whole point.
     */
    protected function trailingSlashRedirect(Request $request): RedirectResponse
    {
        $target = $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().'/';
        $query = $request->getQueryString();

        return new RedirectResponse($query === null ? $target : $target.'?'.$query, 302);
    }
}
