<?php

namespace Mothership\DAM\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Embed;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetEmbeds;

/**
 * Listing, making and revoking an asset's embeds (P6 D26), shared by the Library dialog and the REST API so the two
 * cannot drift. The asset is asked through the access seam first: a hidden, unavailable or trashed one answers like a
 * missing one, before any permission is checked. Then the embed permission (403), then a file type an embed can play
 * (422). Revoking takes the embed's creator or someone who may change the asset.
 */
trait ManagesEmbeds
{
    abstract protected function embedContext(string $purpose): AccessContext;

    abstract protected function mayManageEmbeds(): bool;

    abstract protected function actorId(): ?int;

    /** Whether the actor's role may change assets at all; the seam then decides for this asset. */
    abstract protected function mayEditAssets(): bool;

    protected function listEmbeds(int $assetId): JsonResponse
    {
        $asset = $this->embeddableAssetOrFail($assetId);
        $embeds = app(AssetEmbeds::class);

        return response()->json([
            'success' => true,
            'data'    => $embeds->forAsset($asset)
                ->map(fn (Embed $embed) => $embeds->present(embed: $embed, canRevoke: $this->mayRevokeEmbed(asset: $asset, embed: $embed)))
                ->values(),
        ]);
    }

    protected function createEmbed(Request $request, int $assetId): JsonResponse
    {
        $asset = $this->embeddableAssetOrFail($assetId);

        $data = $request->validate([
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'expires_at'  => ['nullable', 'date', 'after:now'],
        ]);

        $origins = AssetEmbeds::normalizeOrigins($request->input('allowed_origins'));

        if (count($origins) > AssetEmbeds::MAX_ORIGINS) {
            throw ValidationException::withMessages(['allowed_origins' => trans('dam::app.admin.dam.asset.embed.too-many-origins', ['max' => AssetEmbeds::MAX_ORIGINS])]);
        }

        foreach ($origins as $origin) {
            if (! is_string($origin) || preg_match(AssetEmbeds::ORIGIN_PATTERN, $origin) !== 1) {
                throw ValidationException::withMessages(['allowed_origins' => trans('dam::app.admin.dam.asset.embed.invalid-origin')]);
            }
        }

        $expiresAt = match (true) {
            ! empty($data['expires_at'])  => Carbon::parse($data['expires_at'])->setTimezone(config('app.timezone')),
            ! empty($data['expiry_days']) => now()->addDays((int) $data['expiry_days']),
            default                       => null,
        };

        $embeds = app(AssetEmbeds::class);
        $made = $embeds->create(asset: $asset, createdBy: $this->actorId(), expiresAt: $expiresAt, allowedOrigins: $origins);

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.embed.created'),
            'data'    => [
                'embed' => $embeds->present(embed: $made['embed']->fresh('creator'), canRevoke: true),
                ...$embeds->urls(token: $made['token'], asset: $asset),
            ],
        ], 201);
    }

    protected function revokeEmbed(int $assetId, int $embedId): JsonResponse
    {
        $asset = $this->embeddableAssetOrFail($assetId);
        $embed = Embed::query()->where('id', $embedId)->where('asset_id', $asset->id)->first();

        abort_if($embed === null, 404);
        abort_unless($this->mayRevokeEmbed(asset: $asset, embed: $embed), 403, trans('dam::app.admin.permissions.unauthorized'));

        $revoked = app(AssetEmbeds::class)->revoke(embed: $embed, revokedBy: $this->actorId());

        return response()->json([
            'success' => $revoked,
            'message' => trans($revoked ? 'dam::app.admin.dam.asset.embed.revoked' : 'dam::app.admin.dam.asset.embed.already-revoked'),
            'data'    => app(AssetEmbeds::class)->present(embed: $embed->fresh('creator'), canRevoke: false),
        ]);
    }

    /** The asset when the actor may see it (else 404, as if missing), may manage embeds (else 403) and it plays. */
    protected function embeddableAssetOrFail(int $assetId): Asset
    {
        $asset = app(AssetAccessDecision::class)->for($this->embedContext(AccessContext::PURPOSE_SHARE), $assetId)->visibleOrFail();

        abort_unless($this->mayManageEmbeds(), 403, trans('dam::app.admin.permissions.unauthorized'));

        if (! AssetEmbeds::embeddable($asset)) {
            throw ValidationException::withMessages(['asset' => trans('dam::app.admin.dam.asset.embed.not-embeddable')]);
        }

        return $asset;
    }

    protected function mayRevokeEmbed(Asset $asset, Embed $embed): bool
    {
        $actorId = $this->actorId();

        if ($actorId !== null && (int) $embed->created_by === $actorId) {
            return true;
        }

        return $this->mayEditAssets()
            && app(AssetAccessDecision::class)->for($this->embedContext(AccessContext::PURPOSE_EDIT), $asset)->allowed;
    }
}
