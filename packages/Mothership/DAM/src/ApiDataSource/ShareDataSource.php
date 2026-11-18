<?php

namespace Mothership\DAM\ApiDataSource;

use Illuminate\Support\Carbon;
use Mothership\AdminApi\ApiDataSource;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Share\ShareAccessOptions;
use Mothership\DAM\Services\Share\ShareSlugs;

class ShareDataSource extends ApiDataSource
{
    protected $sortColumn = 'dam_shares.id';

    public function __construct(
        protected ShareRepository $shareRepository,
    ) {}

    public function prepareApiQueryBuilder()
    {
        $this->addFilter('share_type', ['='], 'dam_shares');
        $this->addFilter('target_id', ['='], 'dam_shares');
        $this->addFilter('name', ['=', 'LIKE'], 'dam_shares');
        $this->addFilter('created_at', ['=', '>=', '<='], 'dam_shares');
        $this->addFilter('updated_at', ['=', '>=', '<='], 'dam_shares');

        return $this->shareRepository->queryBuilder();
    }

    public function setDefaultFilters($queryBuilder)
    {
        // P4 D2: a collection's link is listed with its collection (`GET collections/{id}`), only to whoever manages
        // it, never here, where the folder and file rules below would not apply to it.
        $queryBuilder->where('share_type', '!=', Share::TYPE_COLLECTION);

        $service = app(DirectoryPermissionService::class);
        $decision = app(AssetAccessDecision::class);
        $context = AccessContext::api(AccessContext::PURPOSE_LIST);
        $bypass = $service->bypass();

        // A folder bypass skips only the folder rule; availability still applies to asset links unless the caller
        // may manage it (P2 S3 review: a custom role reaching every folder is not an editor).
        if ($bypass && $decision->seesEveryUnavailableTo(context: $context)) {
            return $queryBuilder;
        }

        $allowedIds = $bypass ? null : $service->directlyGrantedIds();

        if ($allowedIds === []) {
            $queryBuilder->whereRaw('1 = 0');

            return $queryBuilder;
        }

        // An asset link lists only when the caller may see the asset: the seam's listing rule (P2 S3), which adds
        // availability to the folder rule, so a link to a draft or out-of-window file never names it.
        $visibleAssets = Asset::query()->select('dam_assets.id');
        $decision->scopeListable(query: $visibleAssets, context: $context);

        $queryBuilder->where(function ($query) use ($allowedIds, $visibleAssets) {
            $query->where(function ($directoryShare) use ($allowedIds) {
                $directoryShare->where('share_type', Share::TYPE_DIRECTORY)
                    ->when($allowedIds !== null, fn ($granted) => $granted->whereIn('target_id', $allowedIds));
            })->orWhere(function ($assetShare) use ($visibleAssets) {
                $assetShare->where('share_type', Share::TYPE_ASSET)
                    ->whereIn('target_id', $visibleAssets);
            });
        });

        return $queryBuilder;
    }

    public function formatData(): array
    {
        $paginator = $this->paginator->toArray();

        $rows = $paginator['data'] ?? [];

        // The capability URL is shown only to the link's creator, the target's owner or a full admin.
        $models = Share::query()
            ->with(['asset', 'directory'])
            ->whereIn('id', array_column($rows, 'id'))
            ->get()
            ->keyBy('id');

        $adminId = auth()->guard('api')->id() ?? auth()->id();
        $fullAdmin = app(DirectoryPermissionService::class)->fullAdmin();

        return array_map(function (array $row) use ($models, $adminId, $fullAdmin) {
            $model = $models->get($row['id']);
            $visible = $model !== null
                && $model->tokenVisibleTo(adminId: $adminId === null ? null : (int) $adminId, fullAdmin: $fullAdmin);

            // P2 S5b: the access options from the model, so neither the password hash nor any other hidden column
            // can reach the listing through the raw row.
            $token = $visible ? $model->urlToken() : null;

            // P4 D23: the custom slug and the URL to copy, from the model and only beside the token.
            return $this->normalizeShare($row, $token)
                + ($model !== null ? ShareSlugs::present(share: $model, token: $token) : ['slug' => null, 'slug_url' => null, 'share_url' => null])
                + ($model !== null ? ShareAccessOptions::present($model) : ['has_password' => false, 'allow_download' => true, 'requires_creator_access' => false]);
        }, $rows);
    }

    /** $token is the usable URL token (Share::urlToken()), or null for a caller the #126 rule does not show it to. */
    protected function normalizeShare(array $share, ?string $token): array
    {
        return [
            'id'             => $share['id'],
            'token'          => $token,
            'name'           => $share['name'] ?? null,
            'share_type'     => $share['share_type'] ?? null,
            'target_id'      => isset($share['target_id']) ? (int) $share['target_id'] : null,
            'public_url'     => $token !== null ? route('dam.share.show', ['token' => $token]) : null,
            'expires_at'     => $share['expires_at'] ?? null,
            'revoked_at'     => $share['revoked_at'] ?? null,
            'view_count'     => (int) ($share['view_count'] ?? 0),
            'download_count' => (int) ($share['download_count'] ?? 0),
            'status'         => $this->resolveStatus($share),
            'created_at'     => $share['created_at'] ?? null,
            'updated_at'     => $share['updated_at'] ?? null,
        ];
    }

    protected function resolveStatus(array $share): string
    {
        if (! empty($share['revoked_at'])) {
            return 'revoked';
        }

        if (! empty($share['expires_at']) && Carbon::parse($share['expires_at'])->isPast()) {
            return 'expired';
        }

        return 'active';
    }
}
