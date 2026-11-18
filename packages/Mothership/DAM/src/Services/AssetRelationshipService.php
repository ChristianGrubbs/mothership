<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\LineageInput;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Models\Template;
use Mothership\DAM\Rules\VisibleAsset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Templates\TemplateService;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\StackFileName;
use Mothership\DAM\Support\StackFileThumbnail;
use Mothership\DAM\Traits\AssetAccessControl;
use Mothership\User\Models\Admin;

class AssetRelationshipService
{
    use AssetAccessControl;

    private const RELATIONSHIPS_PER_PAGE = 25;

    public function relationships(int $assetId): array
    {
        $asset = $this->authorizedAsset($assetId);
        // Reuse this operation's directory grants for both pagination counts and eager-loaded targets.
        $accessible = Asset::query()->select('dam_assets.id');
        app(AssetAccessDecision::class)->scopeListable($accessible, AccessContext::library(AccessContext::PURPOSE_LIST));
        $accessibleIds = $accessible->toBase();
        $visibleTarget = fn ($query) => $query->whereIn('dam_assets.id', clone $accessibleIds);

        $outgoingQuery = $asset->relationshipsFrom()
            ->with(['toAsset' => fn ($query) => $visibleTarget($query)->withDisplayName()])
            ->orderBy('type')
            ->orderBy('id');

        $outgoingQuery->whereHas('toAsset', $visibleTarget);

        $outgoingPaginator = $outgoingQuery->paginate(
            self::RELATIONSHIPS_PER_PAGE,
            ['*'],
            'outgoing_page'
        );
        $outgoing = $outgoingPaginator->getCollection()
            ->filter(fn (AssetRelationship $relationship): bool => $relationship->toAsset !== null)
            ->groupBy('type')
            ->map(fn ($relationships) => $relationships
                ->map(fn (AssetRelationship $relationship) => $this->formatRelationship(
                    $relationship,
                    $relationship->toAsset
                ))
                ->values());

        $incomingQuery = $asset->relationshipsTo()
            ->with(['fromAsset' => fn ($query) => $visibleTarget($query)->withDisplayName()])
            ->orderBy('type')
            ->orderBy('id');

        $incomingQuery->whereHas('fromAsset', $visibleTarget);

        $incomingPaginator = $incomingQuery->paginate(
            self::RELATIONSHIPS_PER_PAGE,
            ['*'],
            'incoming_page'
        );
        $incoming = $incomingPaginator->getCollection()
            ->filter(fn (AssetRelationship $relationship): bool => $relationship->fromAsset !== null)
            ->groupBy('type')
            ->map(fn ($relationships) => $relationships
                ->map(fn (AssetRelationship $relationship) => $this->formatRelationship(
                    $relationship,
                    $relationship->fromAsset
                ))
                ->values());

        return [
            'outgoing'   => $outgoing,
            'incoming'   => $incoming,
            'types'      => AssetRelationship::TYPES,
            'pagination' => [
                'outgoing' => $this->paginationMetadata($outgoingPaginator),
                'incoming' => $this->paginationMetadata($incomingPaginator),
            ],
        ];
    }

    /**
     * How many relationships the Relationships tab lists for this asset: those whose other end the viewer's
     * Library lists, by relationships()' own rule, so the badge never counts a hidden or unavailable target
     * (P2 S3 review). The caller has already authorised $asset.
     */
    public function visibleCount(Asset $asset): int
    {
        $accessible = Asset::query()->select('dam_assets.id');
        app(AssetAccessDecision::class)->scopeListable($accessible, AccessContext::library(AccessContext::PURPOSE_LIST));
        $accessibleIds = $accessible->toBase();
        $visible = fn ($query) => $query->whereIn('dam_assets.id', clone $accessibleIds);

        return $asset->relationshipsFrom()->whereHas('toAsset', $visible)->count()
            + $asset->relationshipsTo()->whereHas('fromAsset', $visible)->count();
    }

    public function create(Request $request, int $assetId): array
    {
        $asset = $this->authorizedAsset($assetId);

        $validated = $request->validate([
            // Not `exists`: a hidden target fails exactly as a missing one does.
            'to_asset_id' => ['required', 'integer', new VisibleAsset],
            'type'        => 'required|string|max:64',
            'metadata'    => 'nullable|array',
        ]);

        $targetId = (int) $validated['to_asset_id'];
        $type = trim($validated['type']);
        // A vocabulary type in any case is that type ("Related-To" is related-to); a custom type keeps its spelling, but
        // a case variant of an existing edge's type is the same edge (UX eval G2-16).
        $type = collect(AssetRelationship::TYPES)->first(fn (string $known) => $known === mb_strtolower($type)) ?? $type;

        if ($targetId === $asset->id) {
            throw ValidationException::withMessages([
                'to_asset_id' => [trans('dam::app.admin.dam.asset.relationships.validation.self-link')],
            ]);
        }

        $this->authorizeTarget($targetId);

        if ($type === '') {
            throw ValidationException::withMessages([
                'type' => [trans('validation.required', ['attribute' => 'type'])],
            ]);
        }

        // The exact-spelling unique index cannot see case variants, so the check and the insert run under the source
        // asset's row lock: a concurrent create of "Cites" and "cites" waits, then finds the first (rev-B 3).
        $relationship = DB::transaction(function () use ($asset, $targetId, $type, $validated) {
            Asset::query()->whereKey($asset->id)->lockedForGraph()->value('id');

            if ($this->relationshipExists($asset->id, $targetId, $type)) {
                throw ValidationException::withMessages([
                    'to_asset_id' => [trans('dam::app.admin.dam.asset.relationships.validation.duplicate')],
                ]);
            }

            try {
                // A savepoint, so a failed insert leaves the transaction usable for the check below on PostgreSQL.
                $relationship = DB::transaction(fn () => AssetRelationship::create([
                    'from_asset_id' => $asset->id,
                    'to_asset_id'   => $targetId,
                    'type'          => $type,
                    'metadata'      => $validated['metadata'] ?? null,
                    'created_by'    => Auth::guard('admin')->id() ?? Auth::guard('api')->id() ?? Auth::id(),
                ]));
            } catch (QueryException $exception) {
                if ($this->relationshipExists($asset->id, $targetId, $type)) {
                    throw ValidationException::withMessages([
                        'to_asset_id' => [trans('dam::app.admin.dam.asset.relationships.validation.duplicate')],
                    ]);
                }

                throw $exception;
            }

            return $relationship;
        });

        $relationship->load('toAsset');

        return $this->formatRelationship($relationship, $relationship->toAsset);
    }

    public function delete(int $assetId, int $relationshipId): void
    {
        $asset = $this->authorizedAsset($assetId);
        $relationship = AssetRelationship::findOrFail($relationshipId);

        abort_unless(
            (int) $relationship->from_asset_id === $asset->id
                || (int) $relationship->to_asset_id === $asset->id,
            404
        );

        $targetId = (int) $relationship->from_asset_id === $asset->id
            ? (int) $relationship->to_asset_id : (int) $relationship->from_asset_id;
        $this->authorizeTarget($targetId);

        // Removing a link changes both assets' pages: a role limited to its own items needs to own both ends.
        $roles = app(RoleBuilder::class);

        foreach ([$asset, Asset::query()->find($targetId)] as $endpoint) {
            if ($endpoint !== null && ($refusal = $roles->refusal($endpoint, 'own-only-delete'))) {
                abort(403, $refusal);
            }
        }

        $relationship->delete();
    }

    public function files(int $assetId): array
    {
        $asset = $this->authorizedAsset($assetId);

        $members = Asset::query()->when($asset->capture_group_id,
            fn ($q) => $q->where('capture_group_id', $asset->capture_group_id),
            fn ($q) => $q->whereKey($asset->id))->orderBy('id')->get();
        $members = $members->filter(fn ($member) => $this->damCanAccessAsset($member->id));
        // Current revisions only: a superseded revision is history, never a sibling file (P3a S2).
        $members->load(['files' => fn ($q) => $q->where('is_current', true)->orderBy('sort')->orderBy('id'), 'files.fileObject', 'files.attachment']);

        $derived = $this->derivedFrom(files: $members->flatMap->files);
        // The pictures of merged-in files are looked up once for the whole payload, not once per file.
        $thumbnails = StackFileThumbnail::context($members->flatMap->files, $asset);

        return $members->flatMap(function ($member) use ($derived, $thumbnails) {
            return $member->files
                ->map(fn ($file) => $this->formatFile($file, $member, $derived[$file->id] ?? null, $thumbnails));
        })->values()->all();
    }

    /**
     * Lineage for the listed files (P3a S6), keyed by file id: an output file names the rendition that made it,
     * and a save-as-new copy's first revision names the entry it was made from. The source entry's name and
     * link appear only to someone who may open it.
     *
     * @param  Collection<int, AssetFile>  $files
     * @return array<int, array<string, mixed>>
     */
    private function derivedFrom(Collection $files): array
    {
        // A copy's first revision is the entry's earliest revision-1 file, whatever role it has since taken (original,
        // alternate, or version once replaced); a file attached (and perhaps promoted) later also starts at revision 1
        // but is not what the copy was made from.
        $firstIds = AssetFile::query()
            ->where('revision_number', 1)
            ->whereIn('dam_asset_id', $files->pluck('dam_asset_id')->unique())
            ->selectRaw('min(id) as id')->groupBy('dam_asset_id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $copies = $files->filter(fn (AssetFile $file) => in_array((int) $file->id, $firstIds, true))
            ->keyBy('dam_asset_id');
        $renditions = Rendition::query()
            ->where('status', Rendition::STATUS_DONE)
            ->where(fn ($q) => $q->whereIn('output_asset_file_id', $files->pluck('id'))->orWhereIn('output_asset_id', $copies->keys()))
            ->with(['sourceAssetFile', 'asset'])
            ->orderBy('id')
            ->get();
        $derived = [];

        foreach ($renditions as $rendition) {
            $fileId = $rendition->output_asset_file_id ?? $copies->get($rendition->output_asset_id)?->id;

            if ($fileId === null) {
                continue;
            }

            // P8′: an asset made from a template is its own lineage row's owner; it names the template version.
            if ($rendition->recipe === TemplateService::RECIPE) {
                $derived[$fileId] = $this->madeFromTemplate(rendition: $rendition);

                continue;
            }

            $foreign = $rendition->output_asset_id !== null;
            $visible = ! $foreign || ($rendition->asset !== null && $this->damCanAccessAsset($rendition->dam_asset_id));
            $recipe = trans()->has('dam::app.admin.dam.asset.files.derived.recipes.'.$rendition->recipe)
                ? trans('dam::app.admin.dam.asset.files.derived.recipes.'.$rendition->recipe)
                : $rendition->recipe;
            $revision = $rendition->sourceAssetFile?->revision_number;
            $summary = match (true) {
                $foreign && ! $visible         => trans('dam::app.admin.dam.asset.files.derived.from-hidden-entry', ['recipe' => $recipe]),
                $foreign && $revision !== null => trans('dam::app.admin.dam.asset.files.derived.from-entry', ['recipe' => $recipe, 'revision' => $revision, 'entry' => $rendition->asset->title ?: $rendition->asset->file_name]),
                $foreign                       => trans('dam::app.admin.dam.asset.files.derived.made-from-entry', ['recipe' => $recipe, 'entry' => $rendition->asset->title ?: $rendition->asset->file_name]),
                $revision !== null             => trans('dam::app.admin.dam.asset.files.derived.from-revision', ['recipe' => $recipe, 'revision' => $revision]),
                default                        => trans('dam::app.admin.dam.asset.files.derived.made-by', ['recipe' => $recipe]),
            };

            $derived[$fileId] = [
                'recipe'            => $rendition->recipe,
                'summary'           => $summary,
                'source_asset_id'   => $visible ? (int) $rendition->dam_asset_id : null,
                'source_file_id'    => $visible ? $rendition->source_asset_file_id : null,
                'source_revision'   => $visible ? $revision : null,
                'source_url'        => $foreign && $visible ? route('admin.dam.assets.edit', $rendition->dam_asset_id) : null,
                'made_at'           => $rendition->finished_at?->toIso8601String(),
            ];
        }

        return $derived;
    }

    /**
     * The lineage line of an asset made from a template (P8′): which template and version made it, how many Library
     * images went in, and, for someone who may use the template now, a link to start a new fill from these values.
     *
     * @return array<string, mixed>
     */
    private function madeFromTemplate(Rendition $rendition): array
    {
        $parameters = $rendition->parameters ?? [];
        $template = Template::query()->find((int) ($parameters['template_id'] ?? 0));
        $version = (int) ($parameters['version'] ?? 0);
        $images = $rendition->inputs()->where('role', LineageInput::ROLE_SLOT_IMAGE)->count();
        $viewer = Auth::guard('admin')->user() ?? Auth::guard('api')->user();
        $reopen = $template !== null && $viewer instanceof Admin && TemplateService::canUse($viewer) && $template->status === Template::STATUS_PUBLISHED;

        return [
            'recipe'          => $rendition->recipe,
            'summary'         => trans('dam::app.admin.dam.asset.files.derived.from-template', ['template' => $template?->name ?? '#'.($parameters['template_id'] ?? '?'), 'version' => $version]),
            'source_asset_id' => null,
            'source_file_id'  => null,
            'source_revision' => null,
            'source_url'      => null,
            'made_at'         => $rendition->finished_at?->toIso8601String(),
            'template'        => [
                'id'          => $template?->id,
                'name'        => $template?->name,
                'version'     => $version,
                'images'      => $images,
                'images_text' => trans_choice('dam::app.admin.dam.asset.files.derived.template-inputs', $images, ['count' => $images]),
                'reopen_url'  => $reopen ? route('admin.dam.templates.use', ['id' => $template->id, 'from' => $rendition->output_asset_id]) : null,
                'reopen_text' => $reopen ? trans('dam::app.admin.dam.asset.files.derived.reopen-template') : null,
            ],
        ];
    }

    /** The asset's stack basis and key, with whether this viewer may change them (the Files tab's Stack panel). */
    public function stack(int $assetId): array
    {
        $asset = $this->authorizedAsset($assetId);

        return ['stack' => AssetStackService::payload($asset), 'can_edit' => $this->damCanEditAsset($asset->id)];
    }

    /** @param  array<string, mixed>|null  $derivedFrom  the file's lineage (P3a S6), when the caller loaded it */
    public function formatFile(AssetFile $file, Asset $asset, ?array $derivedFrom = null, ?array $thumbnailContext = null): array
    {
        return [
            // Each file's own name and type (SETUP-08): the representative is the entry, any other file is its label.
            'id'           => $file->id, 'asset_id' => $asset->id, 'file_name' => StackFileName::fileName($file, $asset),
            'extension'    => $extension = StackFileName::extension($file, $asset),
            'adobe'        => AdobeFormats::present(extension: $extension, mime: $file->fileObject?->mime_type),
            'role'         => $file->role, 'label' => $file->label ?: $asset->file_name,
            // A generated proxy, a thumbnail or a superseded revision is listed (the Files tab manages them) but is not a
            // file of the stack: the strip, the fan and the viewer show members only (feedback #6).
            'stack_member' => $isMember = ! in_array($file->role, Asset::UNSTACKED_ROLES, true),
            // What a video member other than the cover plays (its own proxy, its own file, or "processing").
            'playback'     => $isMember && ! $file->attachment?->is_representative ? $asset->stackMemberPlayback($file) : null,
            'sort'         => $file->sort, 'size' => $file->fileObject?->size,
            // The slot this file is the current revision of (P3a S3): what promote, edit and reorder address.
            'attachment_id'     => $file->attachment_id, 'revision_number' => $file->revision_number,
            'is_representative' => (bool) $file->attachment?->is_representative,
            // The merge that linked this file into the stack while it can still be split back out; null once it is the stack's own.
            'merge_id'          => $file->attachment?->merge_id === null ? null : (int) $file->attachment->merge_id,
            'sha256'            => $file->fileObject?->sha256,
            'mime_type'         => $file->fileObject?->mime_type,
            // Its own pixel size, when the upload recorded one (a stacked file's Info pane shows it, not the cover's).
            'width'             => $this->recordedDimension($file, 'width'),
            'height'            => $this->recordedDimension($file, 'height'),
            'url'               => route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $file->id]),
            // A small cached picture for the stack's tiles; null for a type that has none (the tile shows its badge).
            'thumbnail_url'     => $this->stackThumbnailUrl($file, $asset, $thumbnailContext),
            // What the preview shows of a raster or DNG: a screen-sized copy, never a 50 MB original (PROD-03, PROD-13).
            'preview_url'       => StackFileThumbnail::previewable($file, $asset) ? StackFileThumbnail::previewUrl($asset->id, $file->id) : null,
            'download_url'      => route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $file->id, 'download' => 1]),
            'derived_from'      => $derivedFrom,
        ];
    }

    /**
     * The pixel size the upload recorded, in any of the shapes the stack reads elsewhere (see StoredImageDimensions):
     * `width`/`height`, `dimensions.*` or `image.*`. Width and height always come from the same shape.
     */
    private function recordedDimension(AssetFile $file, string $key): ?int
    {
        $technical = $file->fileObject?->technical;

        if (! is_array($technical)) {
            return null;
        }

        foreach (['', 'dimensions.', 'image.'] as $prefix) {
            $width = data_get($technical, $prefix.'width');
            $height = data_get($technical, $prefix.'height');

            if (is_numeric($width) && is_numeric($height) && (int) $width > 0 && (int) $height > 0) {
                return (int) ($key === 'width' ? $width : $height);
            }
        }

        return null;
    }

    /**
     * The small picture of a stacked file, or null while it has none. A still or DNG always has one (the server makes
     * it on first request); a video, PDF, Blender or Unity file once its poster exists, a 3D file once a browser has
     * captured it, and any file that came from an asset with a custom thumbnail or cover art keeps that picture. Until
     * then a tile keeps its badge and the page never asks for a picture that would answer 404.
     */
    private function stackThumbnailUrl(AssetFile $file, Asset $asset, ?array $context): ?string
    {
        return StackFileThumbnail::urlFor($file, $asset, $context);
    }

    /**
     * One slot's revisions, newest first (P3a S5): who made each, when, its size, checksum and note. A
     * revision written before slots recorded their author falls back to its asset version's author.
     *
     * @return array<int, array<string, mixed>>
     */
    public function revisions(int $assetId, int $attachmentId): array
    {
        $asset = $this->authorizedAsset($assetId);
        $slot = Attachment::where('dam_asset_id', $asset->id)->findOrFail($attachmentId);
        $revisions = AssetFile::where('attachment_id', $slot->id)->with('fileObject')->orderByDesc('revision_number')->get();
        $versionAuthors = AssetVersion::whereIn('primary_asset_file_id', $revisions->pluck('id'))->pluck('created_by', 'primary_asset_file_id');
        $authorIds = $revisions->map(fn (AssetFile $file) => $file->created_by ?? $versionAuthors[$file->id] ?? null)->filter()->unique();
        $names = Admin::whereIn('id', $authorIds)->pluck('name', 'id');

        return $revisions->map(function (AssetFile $file) use ($asset, $versionAuthors, $names): array {
            $author = $file->created_by ?? $versionAuthors[$file->id] ?? null;

            return [
                'id'              => $file->id,
                'attachment_id'   => $file->attachment_id,
                'revision_number' => $file->revision_number,
                'is_current'      => (bool) $file->is_current,
                'created_at'      => $file->created_at?->toIso8601String(),
                'created_by'      => $author,
                'author'          => $author === null ? null : ($names[$author] ?? null),
                'size'            => $file->fileObject?->size,
                'sha256'          => $file->fileObject?->sha256,
                'mime_type'       => $file->fileObject?->mime_type,
                'note'            => $file->note,
                'download_url'    => route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $file->id, 'download' => 1]),
            ];
        })->values()->all();
    }

    protected function authorizeTarget(int $assetId): void
    {
        $query = Asset::query()->whereKey($assetId);
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));
        // A hidden other end answers like a relationship that does not exist.
        abort_unless($query->exists(), 404);
    }

    /**
     * The asset when the viewer may change it, through the seam (P2 S2): missing or hidden answers 404, so an id
     * probe learns nothing; under `grants` a visible asset whose first folder refuses answers 403. Public so a writer
     * elsewhere (attachments) refuses up front rather than after a write it then cannot answer for.
     */
    public function authorizedAsset(int $assetId): Asset
    {
        return app(AssetAccessDecision::class)->for(AccessContext::library(AccessContext::PURPOSE_EDIT), $assetId)->allowedOrFail();
    }

    protected function relationshipExists(int $sourceId, int $targetId, string $type): bool
    {
        return AssetRelationship::query()
            ->where('from_asset_id', $sourceId)
            ->where('to_asset_id', $targetId)
            ->whereRaw('LOWER(type) = ?', [mb_strtolower($type)])
            ->exists();
    }

    protected function paginationMetadata(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page'   => $paginator->currentPage(),
            'last_page'      => $paginator->lastPage(),
            'per_page'       => $paginator->perPage(),
            'total'          => $paginator->total(),
            'has_more_pages' => $paginator->hasMorePages(),
        ];
    }

    protected function formatRelationship(AssetRelationship $relationship, Asset $otherAsset): array
    {
        $thumbnailUrl = route('admin.dam.file.thumbnail', [
            'path' => urlencode($otherAsset->path),
        ]);

        // The related asset's own detail page, so a relationship row is a
        // link the reader can follow rather than a name they have to search for.
        $url = route('admin.dam.assets.edit', $otherAsset->id);

        return [
            'relationship_id' => $relationship->id,
            'id'              => $otherAsset->id,
            'file_name'       => $otherAsset->file_name,
            'title'           => $otherAsset->title,
            'display_name'    => $otherAsset->display_name,
            'thumbnail_url'   => $thumbnailUrl,
            'url'             => $url,
            'type'            => $relationship->type,
            'metadata'        => $relationship->metadata,
            'asset'           => [
                'id'            => $otherAsset->id,
                'file_name'     => $otherAsset->file_name,
                'title'         => $otherAsset->title,
                'display_name'  => $otherAsset->display_name,
                'thumbnail_url' => $thumbnailUrl,
                'url'           => $url,
            ],
        ];
    }
}
