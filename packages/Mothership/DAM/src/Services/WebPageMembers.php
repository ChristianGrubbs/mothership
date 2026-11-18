<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Jobs\BuildWebMemberBundle;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\Share\SharedFiles;
use Mothership\DAM\Services\Stack\StackMembers;

/**
 * The pages of a Web Pages asset's stack members. A stack can hold several current file slots (a representative ZIP,
 * more ZIPs, an HTML file); each is previewed from its own bytes, never by promoting it and never from another asset's
 * cache. A member is pinned by its owning asset, attachment slot, current AssetFile row and the FULL 64-hex checksum of
 * its FileObject; a revised, moved, re-roled or removed slot no longer matches, so its old links go stale instead of
 * following the slot.
 *
 * Cache: bundles/{asset}/m/{kind}-{checksum}/manifest.json (never served) beside .../root (served), plus a
 * bundles/{asset}/m/{kind}-{checksum}.building marker while a queued build runs. Nothing here writes
 * Asset.meta_data.web_bundle, the asset row, the original's bytes or any capture/thumbnail data, and the whole-asset
 * purge (WebPageBundler::purge) removes the namespace with the rest of the asset's bundles.
 */
class WebPageMembers
{
    public const SCHEMA = 1;

    /** The materializer's machine messages that refuse the content itself (see isContentRefusal()). */
    protected const CONTENT_REFUSALS = [
        'too_large', 'entry_missing', 'unsafe_path', 'symlink_entry', 'entry_too_large', 'too_many_entries',
        'zip-too-many-entries', 'zip-contents-too-large', 'zip-compression-suspicious', 'invalid-zip', 'extraction_incomplete',
    ];

    /** The extensions a build is attempted for. An extension is only a candidate: the inspector decides whether the bytes are a page. */
    public const KINDS = ['html' => 'html', 'htm' => 'html', 'zip' => 'zip'];

    public function __construct(
        protected StackMembers $stackMembers,
        protected WebPageBundler $bundler,
        protected WebPageResolver $resolver,
        protected ContentStore $contentStore,
    ) {}

    public static function isChecksum(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    public function checksumOf(AssetFile $file): string
    {
        return strtolower((string) $file->fileObject?->sha256);
    }

    /** 'html' or 'zip' when the file's extension makes it a page candidate, else null. */
    public function kindOf(AssetFile $file): ?string
    {
        return self::KINDS[strtolower($file->extension())] ?? null;
    }

    /** The current stack member for this AssetFile row of a visible, non-merged web asset, or null. */
    public function current(Asset $asset, int $fileId): ?AssetFile
    {
        return $asset->file_type === 'web' ? $this->stackMembers->findFile(asset: $asset, fileId: $fileId) : null;
    }

    /** Whether this member of this asset is previewed as a page: own web asset, not a merge source, page-candidate bytes. */
    public function eligible(Asset $asset, AssetFile $file): bool
    {
        return config('dam.web_pages.enabled', true)
            && $asset->file_type === 'web'
            && ! $asset->isMerged()
            && (int) $file->dam_asset_id === (int) $asset->id
            && $this->kindOf($file) !== null;
    }

    /**
     * The identity a queued build is bound to. An array, not positional ids, so an asset/attachment/file swap cannot
     * compile.
     *
     * @return array{asset: int, attachment: int, file: int, checksum: string, kind: string}
     */
    public function pin(Asset $asset, AssetFile $file): array
    {
        return [
            'asset'      => (int) $asset->id,
            'attachment' => (int) $file->attachment_id,
            'file'       => (int) $file->id,
            'checksum'   => $this->checksumOf($file),
            'kind'       => (string) $this->kindOf($file),
        ];
    }

    /**
     * The members of the asset's stack in canonical order with their cache state. Observational: it builds, queues and
     * writes nothing. A merged or non-web asset has none.
     *
     * @return list<array<string, mixed>>
     */
    public function listing(Asset $asset): array
    {
        if ($asset->file_type !== 'web') {
            return [];
        }

        $main = $this->stackMembers->mainAttachmentId(asset: $asset);

        return $this->stackMembers->forAsset(asset: $asset)->map(function (AssetFile $file) use ($asset, $main): array {
            $kind = $this->kindOf($file);
            $checksum = $this->checksumOf($file);
            $status = $kind === null ? ['state' => 'unsupported'] : $this->inspect(asset: $asset, kind: $kind, checksum: $checksum);
            $isMain = (int) $file->attachment_id === $main;
            $label = $file->attachment?->label;

            return [
                'attachment_id' => (int) $file->attachment_id,
                'file_id'       => (int) $file->id,
                'checksum'      => $checksum,
                'extension'     => strtolower($file->extension()),
                'candidate'     => $kind !== null,
                'name'          => $this->nameOf(asset: $asset, file: $file, main: $main),
                'label'         => is_string($label) && $label !== '' ? $label : null,
                'role'          => (string) $file->attachment?->role,
                'size'          => $file->fileObject?->size === null ? null : (int) $file->fileObject->size,
                'is_main'       => $isMain,
                'state'         => $status['state'] === 'invalid' ? 'failed' : $status['state'],
                'error'         => $status['state'] === 'invalid' ? 'cache_invalid' : ($status['error'] ?? null),
            ];
        })->values()->all();
    }

    /** The name a member shows everywhere (listing, heading, frame title): the stack's own name for its main file, else the file's. */
    public function nameOf(Asset $asset, AssetFile $file, ?int $main = null): string
    {
        $main ??= $this->stackMembers->mainAttachmentId(asset: $asset);
        $own = trim(str_replace(['/', '\\', "\0"], '-', (string) $asset->file_name));

        return (int) $file->attachment_id === $main && $own !== '' ? $own : SharedFiles::fileName($file, $asset);
    }

    /**
     * What the isolated cache of this kind and checksum holds, read-only: 'ready' (with the storage-relative `root`
     * and the canonical `exact` root the resolver must land on), 'failed' (with `error`), 'preparing' (a marker younger
     * than the job's own timeout), 'none', or 'invalid' for a cache that is a link or not at its canonical place.
     *
     * @return array{state: string, error?: string, root?: string, exact?: string}
     */
    public function inspect(Asset $asset, string $kind, string $checksum): array
    {
        $disk = Directory::getAssetDisk();

        if ($disk === Directory::ASSETS_DISK_AWS) {
            return ['state' => 'failed', 'error' => 'disk_unsupported'];
        }

        $storage = Storage::disk($disk);
        $paths = $this->paths(assetId: (int) $asset->id, kind: $kind, checksum: $checksum);
        $canonical = $this->canonicalNamespace(assetId: (int) $asset->id);

        if ($canonical === false) {
            return ['state' => 'invalid'];
        }

        $dir = $storage->path($paths['dir']);

        if (file_exists($dir) || is_link($dir)) {
            $expected = $canonical.'/'.$paths['key'];

            if (is_link($dir) || realpath($dir) !== $expected || is_link($dir.'/manifest.json')) {
                return ['state' => 'invalid'];
            }

            $manifest = is_file($dir.'/manifest.json') ? json_decode((string) file_get_contents($dir.'/manifest.json'), true) : null;

            if (is_array($manifest)) {
                if (($manifest['schema'] ?? null) !== self::SCHEMA
                    || ($manifest['asset_id'] ?? null) !== (int) $asset->id
                    || ($manifest['sha256'] ?? null) !== $checksum
                    || ($manifest['kind'] ?? null) !== $kind) {
                    return ['state' => 'invalid'];
                }

                if (is_string($manifest['error'] ?? null) && $manifest['error'] !== '') {
                    return ['state' => 'failed', 'error' => $manifest['error']];
                }

                if (($manifest['root'] ?? null) !== $paths['root'] || is_link($dir.'/root') || realpath($dir.'/root') !== $expected.'/root') {
                    return ['state' => 'invalid'];
                }

                return ['state' => 'ready', 'root' => $paths['root'], 'exact' => $expected.'/root'];
            }
        }

        $marker = $storage->path($paths['marker']);

        return is_file($marker) && time() - (int) @filemtime($marker) < BuildWebMemberBundle::TIMEOUT
            ? ['state' => 'preparing']
            : ['state' => 'none'];
    }

    /** inspect() for a stack member; a file that is not a page candidate is 'unsupported'. Read-only. */
    public function state(Asset $asset, AssetFile $file): array
    {
        $kind = $this->kindOf($file);

        return $kind === null ? ['state' => 'unsupported'] : $this->inspect(asset: $asset, kind: $kind, checksum: $this->checksumOf($file));
    }

    /**
     * Makes sure this member's page is, or is being, built. Called only after every access gate and a current-identity
     * check has passed, so a denied request never reaches here: nothing is queued and no cache or marker is created
     * for it. One atomic marker per content key means concurrent selections queue exactly one job; a marker stranded
     * by a killed worker is recovered once the job's own timeout has passed.
     *
     * @return array{state: string, error?: string, root?: string, exact?: string}
     */
    public function ensure(Asset $asset, AssetFile $file): array
    {
        $kind = $this->kindOf($file);

        if ($kind === null) {
            return ['state' => 'unsupported'];
        }

        $checksum = $this->checksumOf($file);
        $status = $this->inspect(asset: $asset, kind: $kind, checksum: $checksum);

        if ($status['state'] !== 'none') {
            return $status;
        }

        if (! $this->claim(asset: $asset, kind: $kind, checksum: $checksum)) {
            return ['state' => 'preparing'];
        }

        try {
            BuildWebMemberBundle::dispatch(pin: $this->pin($asset, $file));
        } catch (\Throwable $e) {
            report($e);
            $this->release(assetId: (int) $asset->id, kind: $kind, checksum: $checksum);
            WebPageTelemetry::record('web_pages.member_failed', ['asset_id' => $asset->id, 'file_id' => $file->id, 'kind' => $kind, 'reason' => 'queue_unavailable']);

            return ['state' => 'failed', 'error' => 'queue_unavailable'];
        }

        WebPageTelemetry::record('web_pages.member_queued', ['asset_id' => $asset->id, 'attachment_id' => $file->attachment_id, 'file_id' => $file->id, 'kind' => $kind]);

        // A synchronous queue has built it already.
        $after = $this->inspect(asset: $asset, kind: $kind, checksum: $checksum);

        return $after['state'] === 'none' ? ['state' => 'preparing'] : $after;
    }

    /**
     * The resolver answer for one request path inside the member's cache: 200 and friends from the resolver when it is
     * ready, 409 while it is being prepared, 422 when it could not be built, 404 for anything else.
     *
     * @return array{status: int, absolute?: string, relative?: string, mime?: string, attachment?: bool, is_entry?: bool, is_capture_entry?: bool, location?: string}
     */
    public function resolve(Asset $asset, AssetFile $file, string $path): array
    {
        $status = $this->ensure($asset, $file);

        return match ($status['state']) {
            'ready'     => $this->resolver->resolveWithin(asset: $asset, root: $status['root'], path: $path, exactRoot: $status['exact']),
            'preparing' => ['status' => 409],
            'failed'    => ['status' => 422],
            default     => ['status' => 404],
        };
    }

    /**
     * The queued job: builds the pinned member into a private staging directory with the bundler's own materializer
     * (inspector, hardened extractor, forbidden-entry and size limits) and publishes it atomically. The identity is
     * checked again before any work and again, under the publish lock, before anything becomes visible: a slot that was
     * revised, moved, re-roled or removed meanwhile publishes nothing.
     *
     * @param  array{asset: int, attachment: int, file: int, checksum: string, kind: string}  $pin
     * @return string 'ready', 'failed' (a terminal refusal of this very content), 'stale', 'busy' or 'error' (an infrastructure fault: nothing is cached, the next authorized selection builds again)
     */
    public function build(array $pin): string
    {
        $staging = null;
        $source = null;

        try {
            $asset = Asset::query()->find($pin['asset']);

            if (! $this->stillCurrent(asset: $asset, pin: $pin)) {
                return $this->stale($pin);
            }

            $file = $this->current($asset, $pin['file']);
            $disk = Directory::getAssetDisk();

            if ($disk === Directory::ASSETS_DISK_AWS) {
                return $this->commit(pin: $pin, staging: null, manifest: ['error' => 'disk_unsupported']);
            }

            $namespace = $this->prepareNamespace(assetId: $pin['asset']);

            if ($namespace === null) {
                WebPageTelemetry::record('web_pages.member_failed', ['asset_id' => $pin['asset'], 'file_id' => $pin['file'], 'kind' => $pin['kind'], 'reason' => 'cache_invalid']);

                return 'failed';
            }

            $staging = $namespace.'/'.self::stagingPrefix(pin: $pin).bin2hex(random_bytes(6));
            mkdir($staging, 0755);

            try {
                $object = $file->fileObject;

                if (! $object instanceof FileObject) {
                    throw new ContentUnavailable(reason: ContentUnavailable::NO_ORIGINAL, assetId: (int) $pin['asset']);
                }

                $source = $this->contentStore->localPath($object, $file->extension());
            } catch (ContentUnavailable $e) {
                // Only a file with no object at all is a fact about this content. A blob that is missing or unreadable is
                // read from storage that can be briefly unmounted or unreachable, so it caches nothing and is retried.
                if ($e->reason !== ContentUnavailable::NO_ORIGINAL) {
                    throw $e;
                }

                return $this->commit(pin: $pin, staging: null, manifest: ['error' => 'original_missing']);
            }

            try {
                $files = $this->bundler->materialize(source: $source->path, extension: $pin['kind'], stagingDir: $staging);
            } catch (\RuntimeException $e) {
                // Only a refusal of this very content is cached for it (see isContentRefusal()); any other message is a
                // fault of this run, not of the bytes, so it must not poison the checksum.
                if (! self::isContentRefusal($e->getMessage())) {
                    throw $e;
                }

                return $this->commit(pin: $pin, staging: null, manifest: ['error' => mb_substr($e->getMessage(), 0, 200)]);
            }

            $totalBytes = 0;
            foreach ($files as $relative) {
                $totalBytes += (int) filesize($staging.'/'.$relative);
            }

            return $this->commit(pin: $pin, staging: $staging, manifest: ['file_count' => count($files), 'total_bytes' => $totalBytes]);
        } catch (\Throwable $e) {
            report($e);
            WebPageTelemetry::record('web_pages.member_failed', ['asset_id' => $pin['asset'], 'attachment_id' => $pin['attachment'], 'file_id' => $pin['file'], 'kind' => $pin['kind'], 'reason' => 'build_error']);

            // No manifest: the `finally` frees the marker and the staging, the key reads as 'none' again.
            return 'error';
        } finally {
            $source?->release();

            if ($staging !== null) {
                $this->bundler->removeTree($staging);
                $this->bundler->removeTree($staging.'.unpack');
            }

            $this->release(assetId: $pin['asset'], kind: $pin['kind'], checksum: $pin['checksum']);
        }
    }

    /**
     * A job that died (timeout, killed worker): free the marker and any staging it left, and cache nothing. The cause
     * is unknown, so the content key stays buildable by the next authorized selection.
     *
     * @param  array{asset: int, attachment: int, file: int, checksum: string, kind: string}  $pin
     */
    public function abandon(array $pin): void
    {
        $namespace = Storage::disk(Directory::getAssetDisk())->path("bundles/{$pin['asset']}/m");

        if (is_dir($namespace) && ! is_link($namespace)) {
            foreach (glob($namespace.'/'.self::stagingPrefix(pin: $pin).'*') ?: [] as $leftover) {
                $this->bundler->removeTree($leftover);
            }
        }

        $this->release(assetId: $pin['asset'], kind: $pin['kind'], checksum: $pin['checksum']);
        WebPageTelemetry::record('web_pages.member_failed', ['asset_id' => $pin['asset'], 'attachment_id' => $pin['attachment'], 'file_id' => $pin['file'], 'kind' => $pin['kind'], 'reason' => 'abandoned']);
    }

    /**
     * Whether a materializer message refuses the content itself, so every later attempt would refuse it the same way.
     * The inspector refuses unsafe names, symlinks, oversized or suspicious archives and too many entries from the headers
     * before extraction, and the extractor repeats its own limits. 'extraction_incomplete' is the extractor silently
     * dropping an entry it could not use: one that cannot be opened as a stream (encrypted or corrupt), whose real bytes
     * exceed the entry or total cap despite a header that said otherwise, or whose path cannot exist beside another
     * entry. Those are properties of the bytes the header preflight cannot see, so they are terminal. A real filesystem
     * fault (a full disk, a permission error, a failed rename) is raised by PHP as an ErrorException, never as one of
     * these keys, so it stays unclassified and is retried, as is 'invalid_zip' (a failed second open of an archive the
     * inspector had just read) and every other message.
     */
    protected static function isContentRefusal(string $message): bool
    {
        return in_array($message, self::CONTENT_REFUSALS, true) || str_starts_with($message, 'forbidden_entry:');
    }

    /** The prefix of every staging directory of one content key, so a dead job's leftovers can be found again. */
    protected static function stagingPrefix(array $pin): string
    {
        return '.staging-'.$pin['kind'].'-'.$pin['checksum'].'-';
    }

    /**
     * Publishes the staged root (or a terminal failure) under a per-key lock after one more current-identity check.
     * An already published ready or failed cache of the same content key is kept, never overwritten.
     *
     * @param  array{asset: int, attachment: int, file: int, checksum: string, kind: string}  $pin
     * @param  array<string, mixed>  $manifest  `error`, or `file_count` and `total_bytes`
     */
    protected function commit(array $pin, ?string $staging, array $manifest): string
    {
        $lock = Cache::lock('dam.web_member.'.$pin['asset'].'.'.$pin['kind'].'-'.$pin['checksum'], 60);

        try {
            return $lock->block(10, fn (): string => $this->publish(pin: $pin, staging: $staging, manifest: $manifest));
        } catch (LockTimeoutException) {
            return 'busy';
        }
    }

    protected function publish(array $pin, ?string $staging, array $manifest): string
    {
        $asset = Asset::query()->find($pin['asset']);

        if (! $this->stillCurrent(asset: $asset, pin: $pin)) {
            return $this->stale($pin);
        }

        $namespace = $this->prepareNamespace(assetId: $pin['asset']);

        if ($namespace === null) {
            return 'failed';
        }

        $existing = $this->inspect(asset: $asset, kind: $pin['kind'], checksum: $pin['checksum']);

        if (in_array($existing['state'], ['ready', 'failed'], true)) {
            return $existing['state'];
        }

        if ($existing['state'] === 'invalid') {
            return 'failed';
        }

        $key = $pin['kind'].'-'.$pin['checksum'];
        $dir = $namespace.'/'.$key;

        // Nothing but an unfinished publication of a crashed build can sit here without a manifest.
        $this->bundler->removeTree($dir);
        mkdir($dir, 0755);

        $record = ['schema' => self::SCHEMA, 'asset_id' => $pin['asset'], 'sha256' => $pin['checksum'], 'kind' => $pin['kind'], 'built_at' => now()->toIso8601String()];

        if ($staging !== null) {
            rename($staging, $dir.'/root');
            $record += ['root' => "bundles/{$pin['asset']}/m/{$key}/root", 'entry' => WebPageBundler::ENTRY] + $manifest;
        } else {
            $record += $manifest;
        }

        // The manifest appears last and whole: until it does, the cache reads as still being prepared.
        file_put_contents($dir.'/manifest.json.tmp', json_encode($record, JSON_THROW_ON_ERROR));
        rename($dir.'/manifest.json.tmp', $dir.'/manifest.json');

        if ($staging !== null) {
            WebPageTelemetry::record('web_pages.member_ready', ['asset_id' => $pin['asset'], 'attachment_id' => $pin['attachment'], 'file_id' => $pin['file'], 'kind' => $pin['kind'], 'file_count' => $manifest['file_count'] ?? 0, 'total_bytes' => $manifest['total_bytes'] ?? 0]);

            return 'ready';
        }

        // The reason is a machine key; a ZIP entry name that rode along after ':' never reaches the log.
        WebPageTelemetry::record('web_pages.member_failed', ['asset_id' => $pin['asset'], 'attachment_id' => $pin['attachment'], 'file_id' => $pin['file'], 'kind' => $pin['kind'], 'reason' => (string) strtok((string) ($manifest['error'] ?? ''), ':')]);

        return 'failed';
    }

    /** Whether the pinned file is still a current member of the asset with this very checksum, slot and kind. */
    protected function stillCurrent(?Asset $asset, array $pin): bool
    {
        $file = $asset === null ? null : $this->current($asset, $pin['file']);

        return $file !== null
            && (int) $file->attachment_id === $pin['attachment']
            && $this->kindOf($file) === $pin['kind']
            && hash_equals($this->checksumOf($file), $pin['checksum']);
    }

    protected function stale(array $pin): string
    {
        WebPageTelemetry::record('web_pages.member_stale', ['asset_id' => $pin['asset'], 'attachment_id' => $pin['attachment'], 'file_id' => $pin['file'], 'kind' => $pin['kind']]);

        return 'stale';
    }

    /** @return array{key: string, dir: string, root: string, marker: string, namespace: string} */
    protected function paths(int $assetId, string $kind, string $checksum): array
    {
        $key = $kind.'-'.$checksum;
        $namespace = "bundles/{$assetId}/m";

        return ['key' => $key, 'namespace' => $namespace, 'dir' => "{$namespace}/{$key}", 'root' => "{$namespace}/{$key}/root", 'marker' => "{$namespace}/{$key}.building"];
    }

    /** The canonical absolute namespace directory, or false when it exists as (or under) a link elsewhere. */
    protected function canonicalNamespace(int $assetId): string|false
    {
        $storage = Storage::disk(Directory::getAssetDisk());
        $bundles = realpath($storage->path('bundles'));
        $namespace = $storage->path("bundles/{$assetId}/m");

        if ($bundles === false || ! file_exists($namespace)) {
            // Nothing there yet: a missing bundles directory or namespace holds no cache and can alias nothing.
            return $bundles === false ? $storage->path("bundles/{$assetId}/m") : $bundles.'/'.$assetId.'/m';
        }

        $expected = $bundles.'/'.$assetId.'/m';

        return is_link($namespace) || realpath($namespace) !== $expected ? false : $expected;
    }

    /** The absolute namespace directory, created if needed; null when it is a link or elsewhere. */
    protected function prepareNamespace(int $assetId): ?string
    {
        $storage = Storage::disk(Directory::getAssetDisk());
        $namespace = $storage->path("bundles/{$assetId}/m");

        if (! is_dir($namespace) && ! is_link($namespace)) {
            mkdir($namespace, 0755, true);
        }

        return $this->canonicalNamespace(assetId: $assetId) === false ? null : realpath($namespace);
    }

    protected function claim(Asset $asset, string $kind, string $checksum): bool
    {
        $namespace = $this->prepareNamespace(assetId: (int) $asset->id);

        if ($namespace === null) {
            return false;
        }

        $marker = $namespace.'/'.$kind.'-'.$checksum.'.building';
        $handle = @fopen($marker, 'x');

        if ($handle === false && is_file($marker) && ! is_link($marker) && time() - (int) @filemtime($marker) >= BuildWebMemberBundle::TIMEOUT) {
            @unlink($marker);
            $handle = @fopen($marker, 'x');
        }

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    protected function release(int $assetId, string $kind, string $checksum): void
    {
        $marker = Storage::disk(Directory::getAssetDisk())->path($this->paths(assetId: $assetId, kind: $kind, checksum: $checksum)['marker']);

        if (is_file($marker) || is_link($marker)) {
            @unlink($marker);
        }
    }
}
