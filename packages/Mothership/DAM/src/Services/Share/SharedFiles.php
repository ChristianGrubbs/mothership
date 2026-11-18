<?php

namespace Mothership\DAM\Services\Share;

use Illuminate\Support\Collection;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Merge\MergedSourceLinks;
use Mothership\DAM\Services\Stack\StackMembers;
use Mothership\DAM\Services\WebPageMembers;

/**
 * OD5 (2026-10-19): the files a share link hands out for one asset it reaches. That is the current revision of every
 * stacked slot, main (representative) file first, then slot order. A generated proxy or thumbnail, a superseded revision
 * and another asset's slot are never among them. A merge source's link hands out only the slots that came from it
 * (MergedSourceLinks::files()). Every share route that names a slot asks find(), so nothing outside this set can be
 * named.
 *
 * This is the share-link policy layer. The member query itself (current revision of every slot but
 * Asset::UNSTACKED_ROLES, ordered) is StackMembers, shared with the Creative Hub; the merge-source set stays
 * MergedSourceLinks.
 */
final class SharedFiles
{
    /** @var array<int, int|null> the main slot per asset id, read once per request */
    private array $mainIds = [];

    public function __construct(
        private readonly MergedSourceLinks $mergedSourceLinks,
        private readonly StackMembers $stackMembers,
        private readonly WebPageMembers $webPageMembers,
        private readonly ShareGate $shareGate,
    ) {}

    /** @return Collection<int, AssetFile> */
    public function forAsset(Asset $asset): Collection
    {
        if (($merged = $this->mergedSourceLinks->files($asset)) !== null) {
            return $merged;
        }

        return $this->stackMembers->forAsset(asset: $asset);
    }

    /** The file the link hands out in this slot, or null (the caller answers 404, never the main file in its place). */
    public function find(Asset $asset, int $attachmentId): ?AssetFile
    {
        return $this->forAsset($asset)->first(fn (AssetFile $file) => (int) $file->attachment_id === $attachmentId);
    }

    /**
     * The page's rows for this asset's files (A-contract 1, SharedFileRow). `$memberAssetId` null builds the asset link's
     * routes, an id the container link's member routes. The main file keeps the asset's own thumbnail (its custom one
     * included) and its counted default download. A preview-only link gets no download URL at all.
     *
     * The main row's `preview_kind` and `preview_url` follow the related-file policy (SharedFilePreview::previewKind()),
     * so on a preview-only link a main MP4, MP3 or PDF row reads 'none'/null. The page must still render the MAIN file
     * through the default `dam.share.preview` / `dam.share.asset_preview` route, which keeps playing an inline-safe
     * original on those links (Q5, PD10); never from this row's `preview_url`.
     *
     * `file_id`, `checksum` (the FULL 64 hex) and `page_url` identify the current revision and, for an own web asset's
     * HTML or ZIP member, the sandboxed page of exactly that file (ADR 0007 amendment 2026-10-24); `page_url` is null for
     * anything else, a merge source included, and is not a download route, so a preview-only link carries it too.
     *
     * @return list<array{attachment_id: int, file_id: int, checksum: string, name: string, extension: string, kind: string, size: int|null, is_main: bool, label: string|null, thumb_url: string|null, preview_url: string|null, preview_kind: string, page_url: string|null, download_url: string|null}>
     */
    public function present(Share $share, Asset $asset, string $token, ?int $memberAssetId): array
    {
        $route = fn (string $assetRoute, string $memberRoute, array $parameters = []): string => $memberAssetId === null
            ? route($assetRoute, ['token' => $token] + $parameters)
            : route($memberRoute, ['token' => $token, 'assetId' => $memberAssetId] + $parameters);

        // One grant for the whole list; only a password-protected link needs one.
        $pageGrant = $share->hasPassword() ? $this->shareGate->pageGrant($share) : null;

        return $this->forAsset($asset)->map(function (AssetFile $file) use ($share, $asset, $token, $memberAssetId, $route, $pageGrant): array {
            $isMain = $this->isMain($asset, $file);
            $slot = ['attachment' => (int) $file->attachment_id];
            $previewKind = SharedFilePreview::previewKind($share, $file, $asset);
            $type = $isMain
                ? strtolower((string) ($asset->extension ?: pathinfo((string) $asset->file_name, PATHINFO_EXTENSION)))
                : (string) self::fileType($file, $asset);
            $label = $file->attachment?->label;
            $size = $file->fileObject?->size;

            return [
                'attachment_id' => (int) $file->attachment_id,
                'file_id'       => (int) $file->id,
                'checksum'      => $this->webPageMembers->checksumOf($file),
                'name'          => $this->downloadName($asset, $file),
                'extension'     => strtoupper($type),
                'kind'          => SharedFilePreview::kind($file, $asset),
                'size'          => $size === null ? null : (int) $size,
                'is_main'       => $isMain,
                'label'         => is_string($label) && $label !== '' ? $label : null,
                'thumb_url'     => $isMain
                    ? route('dam.share.thumbnail', ['token' => $token, 'assetId' => $memberAssetId ?? (int) $asset->id])
                    : $route('dam.share.file_thumbnail', 'dam.share.asset_file_thumbnail', $slot),
                'preview_url'   => $previewKind === 'none' ? null : $route('dam.share.file_preview', 'dam.share.asset_file_preview', $slot),
                'preview_kind'  => $previewKind,
                'page_url'      => $this->pageUrl($asset, $file, $token, $pageGrant),
                'download_url'  => ! $share->allowsDownload() ? null : ($isMain
                    ? $route('dam.share.download', 'dam.share.asset_download')
                    : $route('dam.share.file_download', 'dam.share.asset_file_download', $slot)),
            ];
        })->values()->all();
    }

    /** The sandboxed page of this exact file (its pin in the path), or null when it is not an eligible own web member. */
    private function pageUrl(Asset $asset, AssetFile $file, string $token, ?string $pageGrant): ?string
    {
        if (! $this->webPageMembers->eligible($asset, $file)) {
            return null;
        }

        $pin = $asset->id.'.'.$file->attachment_id.'.'.$file->id.'.'.$this->webPageMembers->checksumOf($file);

        return ($pageGrant === null
            ? route('dam.share.page_file', ['token' => $token, 'pin' => $pin])
            : route('dam.share.page_file_granted', ['token' => $token, 'grant' => $pageGrant, 'pin' => $pin])).'/';
    }

    /**
     * A-contract 4: how many files each card's asset hands out (forAsset()->count()), in one grouped query for a page of
     * ordinary assets; a merge source (only in a collection) is counted through MergedSourceLinks.
     *
     * @param  iterable<Asset>  $assets
     * @return array<int, int> asset id => file count
     */
    public function counts(iterable $assets): array
    {
        $assets = collect($assets);
        $plain = $assets->reject(fn (Asset $asset) => $asset->isMerged())->map(fn (Asset $asset) => (int) $asset->id)->values()->all();

        $counts = $plain === [] ? [] : AssetFile::query()
            ->join('dam_attachments', 'dam_attachments.id', '=', 'dam_asset_files.attachment_id')
            ->whereIn('dam_asset_files.dam_asset_id', $plain)
            ->whereColumn('dam_attachments.dam_asset_id', 'dam_asset_files.dam_asset_id')
            ->where('dam_asset_files.is_current', true)
            ->whereNotNull('dam_asset_files.file_object_id')
            ->whereNotIn('dam_asset_files.role', Asset::UNSTACKED_ROLES)
            ->whereNotIn('dam_attachments.role', Asset::UNSTACKED_ROLES)
            ->groupBy('dam_asset_files.dam_asset_id')
            ->selectRaw('dam_asset_files.dam_asset_id as asset_id, count(*) as files')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->asset_id => (int) $row->files])
            ->all();

        foreach ($assets->filter(fn (Asset $asset) => $asset->isMerged()) as $asset) {
            $counts[(int) $asset->id] = $this->forAsset($asset)->count();
        }

        return $counts;
    }

    public function isMain(Asset $asset, AssetFile $file): bool
    {
        return (int) $file->attachment_id === $this->mainAttachmentId($asset);
    }

    /** The name a file downloads under: the main file keeps the asset's own name, as its default download does. */
    public function downloadName(Asset $asset, AssetFile $file): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '-', (string) $asset->file_name));

        return $this->isMain($asset, $file) && $name !== '' ? $name : self::fileName($file, $asset);
    }

    /**
     * The name a shared file downloads under: its slot's label as written when it carries a suffix, since a stored MIME
     * type is sniffed from the bytes and is often generic (a .docx sniffs as a zip, an .ai as a PDF). A label with no
     * suffix gets the file's own extension, so a recipient never saves a file with no type. A file with no label at all
     * is named after the asset without the asset's suffix: logo.png's PDF is logo.pdf.
     */
    public static function fileName(AssetFile $file, Asset $asset): string
    {
        return self::described($file, $asset)['name'];
    }

    /**
     * The type a shared file is listed as: the one its download name carries, or null when none resolved. An unlabelled
     * file of unknown type keeps the asset's stem (logo.v2), which is never read back as a type.
     */
    public static function fileType(AssetFile $file, Asset $asset): ?string
    {
        return self::described($file, $asset)['type'];
    }

    /** @return array{name: string, type: ?string} */
    private static function described(AssetFile $file, Asset $asset): array
    {
        $clean = fn (?string $name): string => trim(str_replace(['/', '\\', "\0"], '-', (string) $name));
        $name = $clean($file->attachment?->label);
        $name = $name === '' ? $clean($file->label) : $name;
        $labelExtension = $name === '' ? null : AssetFile::extensionFromLabel($name);

        if ($labelExtension !== null) {
            return ['name' => $name, 'type' => $labelExtension];
        }

        if ($name === '') {
            $name = $clean($asset->file_name);
            $name = AssetFile::extensionFromLabel($name) !== null ? pathinfo($name, PATHINFO_FILENAME) : $name;
        }

        $extension = $file->extension();
        $name = $extension === '' ? $name : rtrim($name, '.');
        $name = $name === '' ? 'file' : $name;

        return $extension === ''
            ? ['name' => $name, 'type' => null]
            : ['name' => $name.'.'.$extension, 'type' => $extension];
    }

    /** The main slot: a merge source's is the destination slot that came from its representative; null when none is left. */
    private function mainAttachmentId(Asset $asset): ?int
    {
        $key = (int) $asset->id;

        if (! array_key_exists($key, $this->mainIds)) {
            $id = $asset->isMerged()
                ? $this->mergedSourceLinks->mainSlotId($asset)
                : $this->stackMembers->mainAttachmentId(asset: $asset);
            $this->mainIds[$key] = $id === null ? null : (int) $id;
        }

        return $this->mainIds[$key];
    }
}
