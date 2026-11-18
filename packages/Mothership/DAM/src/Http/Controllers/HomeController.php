<?php

namespace Mothership\DAM\Http\Controllers;

use App\Documentation\Manual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Home\HomeStorageReading;
use Mothership\DAM\Services\Home\HomeSummary;
use Mothership\DAM\Services\Home\HomeVisits;
use Mothership\DAM\Services\HomeLayoutService;
use Mothership\DAM\Services\HomeSystemQuickView;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\DAM\Services\Transfers\TransferSession;
use Mothership\DAM\Support\PreviewKinds;

class HomeController extends Controller
{
    public function index(Request $request, TransferSession $transfers, HomeVisits $visits)
    {
        if ($this->isBrowserVisit($request)) {
            // Home must still open if the visit cannot be written.
            rescue(fn () => $visits->record(adminId: (int) auth('admin')->id(), now: now()->toImmutable()));
        }

        $transferConfig = $transfers->clientConfig(request: $request);

        return view('dam::home.index', [
            'transfers' => $transferConfig,
            'actions'   => $this->startActions(transfers: $transferConfig !== null),
            'phoneHost' => $request->getHost(),
        ]);
    }

    /** A person opening the page: not a data request, and not the browser fetching it ahead of a click. */
    private function isBrowserVisit(Request $request): bool
    {
        $purpose = strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose')));

        return $request->isMethod('GET') && ! $request->expectsJson() && ! str_contains($purpose, 'prefetch');
    }

    /** What the centre of Home shows for this person. */
    public function summary(HomeSummary $summary)
    {
        return response()->json($summary->payload(admin: auth('admin')->user(), now: now()->toImmutable()))
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * The "Start something" buttons: the everyday tasks, in the order people do them, and only the ones this
     * person's role can finish. `start` buttons open a dialog on Home, `open` buttons open a Home tool, the rest link.
     *
     * @return list<array{key: string, label: string, help: string, icon: string, url?: string, start?: string, open?: string, url_fallback?: string}>
     */
    private function startActions(bool $transfers): array
    {
        $can = fn (string $key): bool => bouncer()->hasPermission($key);
        $actions = [];
        if ($can('dam.asset.upload') && $can('dam.directory.index')) {
            $actions[] = ['key' => 'upload', 'label' => 'Upload files', 'help' => 'Add photos, videos or documents from this computer.', 'icon' => 'M12 16V4 M7 9l5-5 5 5 M4 16v4h16v-4', 'start' => 'upload'];
        }
        if ($transfers) {
            $actions[] = ['key' => 'send', 'label' => 'Send files to a person', 'help' => 'Straight to a colleague who has The Mothership open.', 'icon' => 'M21 3L10 14 M21 3l-7 18-4-7-7-4z', 'start' => 'send', 'url_fallback' => route('admin.dam.transfers.index')];
        }
        if ($can('dam.assets')) {
            $actions[] = ['key' => 'browse', 'label' => 'Browse all files', 'help' => 'Open the Library to view, download or share a file.', 'icon' => 'M3 6h7l2 3h9v10H3z', 'url' => route('admin.dam.index')];
        }
        if ($can('dam.upload_requests') && Route::has('admin.dam.upload-requests.index')) {
            $actions[] = ['key' => 'requests', 'label' => 'Ask someone for files', 'help' => 'Send a link so a person outside The Mothership can upload to you.', 'icon' => 'M4 6h16v12H4z M4 7l8 6 8-6', 'url' => route('admin.dam.upload-requests.index')];
        }

        return array_slice($actions, 0, 5);
    }

    public function layout(HomeLayoutService $layouts)
    {
        return response()->json($layouts->read())->header('Cache-Control', 'private, no-store');
    }

    public function data(Request $request, string $widget, HomeWidgetRegistry $registry, DirectoryPermissionService $permissions, HomeSystemQuickView $quickView, HomeStorageReading $storage)
    {
        $registry->allowed($widget);
        if ($widget === 'system-quickview') {
            return response()->json($quickView->payload(viewer: auth('admin')->user(), now: now()->toImmutable()))
                ->header('Cache-Control', 'private, no-store');
        }
        if ($widget === 'docs-count') {
            return response()->json(['articles' => count(app(Manual::class)->entries())])
                ->header('Cache-Control', 'private, no-store');
        }
        abort_unless(in_array($widget, ['recent', 'totals', 'storage'], true), 404);
        if ($widget === 'storage') {
            return response()->json($storage->read())->header('Cache-Control', 'private, no-store');
        }
        $query = Asset::query()->visibleCaptures();
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));
        if ($widget === 'totals') {
            $totals = $query->selectRaw('COUNT(*) AS count, COALESCE(SUM(file_size), 0) AS bytes')->first();
            $directories = $permissions->bypass() ? DB::table('dam_directories')->count() : count($permissions->directlyGrantedIds());

            return response()->json(['files' => (int) $totals->count, 'bytes' => (int) $totals->bytes, 'folders' => $directories])->header('Cache-Control', 'private, no-store');
        }
        $limit = max(3, min(24, $request->integer('limit', 6)));
        $assets = $query->withDisplayName()->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(fn ($asset) => [
            'id'         => $asset->id, 'name' => $asset->display_name, 'type' => $asset->file_type, 'type_label' => self::typeLabel($asset),
            'created_at' => $asset->created_at?->toIso8601String(),
            'url'        => route('admin.dam.assets.edit', $asset->id),
            'thumbnail'  => route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]),
        ]);

        return response()->json(['assets' => $assets])->header('Cache-Control', 'private, no-store');
    }

    /**
     * The Recent files type word. 3D models are stored as file_type "document", so the extension decides.
     */
    public static function typeLabel(Asset $asset): string
    {
        $key = in_array(strtolower((string) $asset->extension), PreviewKinds::MODEL_3D, true)
            ? 'model-3d'
            : (string) $asset->file_type;

        $label = trans("dam::app.admin.file-types.{$key}");

        return str_starts_with($label, 'dam::') ? ucfirst($key) : $label;
    }
}
