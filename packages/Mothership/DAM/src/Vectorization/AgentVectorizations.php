<?php

namespace Mothership\DAM\Vectorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\User\Models\Admin;
use stdClass;

/**
 * Image Vectorization for agents: the REST API (/api/v1/rest/vectorizations, /assets/{asset}/vectorize) and the
 * `mothership` MCP server (library_vectorize, library_vectorization). The same jobs, allowance, replay and limits as
 * the Library's Convert menu and the Home tool (Intake, Requests); this class adds the caller's account, which is not
 * the session's, and one result shape. Contract: docs/ops/image-vectorization.md.
 */
class AgentVectorizations
{
    public function __construct(
        private Intake $intake,
        private Vectorizer $provider,
        private AssetAccessDecision $access,
        private HomeWidgetRegistry $widgets,
    ) {}

    /** Filed: convert the asset's original; the chosen format is published onto the asset as an output file. */
    public function fromAsset(Admin $actor, string $via, int $assetId, string $format, ?string $requestId): stdClass
    {
        $this->open(actor: $actor);
        $asset = $this->access->for(context: AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_EDIT, via: $via), asset: $assetId)->allowedOrFail();
        // The Convert menu's own rule: edit rights on the asset and the Files permission.
        abort_unless(self::can(actor: $actor, permission: 'dam.asset.edit') && self::can(actor: $actor, permission: 'dam.asset.files'), 403);

        return $this->intake->fromAsset(adminId: (int) $actor->id, asset: $asset, format: $format, requestId: $requestId ?? (string) Str::uuid());
    }

    /** One-off: convert a file the caller supplies; both SVG and PDF are made and kept as the caller's own. */
    public function fromFile(Admin $actor, string $path, ?string $requestId): stdClass
    {
        $this->open(actor: $actor);

        return $this->intake->fromFile(adminId: (int) $actor->id, path: $path, requestId: $requestId ?? (string) Str::uuid());
    }

    /**
     * The caller's own conversion; another account's, or one made from an asset it can no longer open, is 404.
     * Reading needs the paid tool as submitting does (an account taken off it loses its results too), but not the
     * provider: stored results stay readable while the provider is down.
     */
    public function find(Admin $actor, string $via, string $id): stdClass
    {
        $this->entitled(actor: $actor);
        abort_unless(Str::isUuid($id), 404);
        $row = DB::table('dam_vectorizations')->where('admin_id', $actor->id)->where('id', $id)->first();
        abort_unless($row && $this->access->sourceOpen(context: AccessContext::forAdmin(admin: $actor, via: $via), sourceAssetId: $row->source_asset_id === null ? null : (int) $row->source_asset_id), 404);

        return $row;
    }

    /** Absolute path of a completed result's local file. */
    public function resultPath(stdClass $row, string $format): string
    {
        abort_unless($row->status === 'completed' && in_array($format, ['svg', 'pdf'], true), 404);
        $path = 'vectorizations/results/'.$row->id.'/result.'.$format;
        $disk = Storage::disk('private');
        abort_unless($disk->exists($path), 404);

        return $disk->path($path);
    }

    /** @return array<string, mixed> */
    public function present(stdClass $row): array
    {
        $complete = $row->status === 'completed';
        $filed = $row->source_asset_id !== null;
        $output = null;
        if ($complete && $filed) {
            $fileId = Rendition::query()->where('dam_asset_id', $row->source_asset_id)->where('recipe', Rendition::RECIPE_VECTORIZE)
                ->where('parameters->vectorization_id', $row->id)->value('output_asset_file_id');
            $output = $fileId === null ? null : ['asset_id' => (int) $row->source_asset_id, 'file_id' => (int) $fileId];
        }

        return [
            'id'              => (string) $row->id,
            'status'          => (string) $row->status,
            'kind'            => $filed ? 'asset' : 'one_off',
            'format'          => $row->output_format ?? null,
            'source_asset_id' => $filed ? (int) $row->source_asset_id : null,
            'charged'         => (bool) $row->charged,
            'error_code'      => $row->status === 'failed' ? ($row->error_code ?? 'failed') : null,
            'message'         => $row->status === 'failed' ? 'Vectorization failed. Any allowance used was returned.' : null,
            'formats'         => $complete ? ['svg', 'pdf'] : [],
            'output'          => $output,
            'created_at'      => (string) $row->created_at,
            'updated_at'      => (string) $row->updated_at,
        ];
    }

    /** The provider is configured and this account may use the tool (the paid Home tool's role gate). */
    private function open(Admin $actor): void
    {
        abort_unless($this->provider->ready(), 503, 'Image Vectorization is not connected.');
        $this->entitled(actor: $actor);
    }

    private function entitled(Admin $actor): void
    {
        abort_unless($this->widgets->permitsAdmin(admin: $actor, id: 'vectorization'), 403, 'This account cannot use Image Vectorization.');
    }

    private static function can(Admin $actor, string $permission): bool
    {
        return $actor->role?->permission_type === 'all' || $actor->hasPermission($permission);
    }
}
