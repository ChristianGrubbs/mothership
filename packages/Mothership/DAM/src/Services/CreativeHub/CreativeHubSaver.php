<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Stack\StackMembers;
use Mothership\DAM\Support\StackFileName;
use Mothership\User\Models\Admin;
use Symfony\Component\Mime\MimeTypes;

/**
 * Save-back from a hub app (spec §6). A new asset is the default and never touches the source; replacing the source is a
 * new version of it, asked for explicitly, of the same file type only, and only over the bytes the workspace opened.
 */
final class CreativeHubSaver
{
    /** The role a file saved into a stack from a hub app is attached under: the Library's own default for an added file. */
    public const STACK_ROLE = AssetFile::ROLE_ALTERNATE;

    /** A finfo result that names no format of its own: accepted only where the expected type is itself textual. */
    private const GENERIC_CONTENT = ['text/plain', 'application/octet-stream'];

    /** Extensions whose content is decided by signatureMatches, not by finfo's MIME: kind => zip (with its marker entry prefix) or dxf. */
    private const SIGNATURE_TYPES = [
        'dxf'         => 'dxf',
        'docx'        => 'word/document.xml',
        'xlsx'        => 'xl/workbook.xml',
        'pptx'        => 'ppt/presentation.xml',
        'designcraft' => '',
        'deckcraft'   => '',
        'scraftz'     => '',
    ];

    /** The longest label AttachmentLedger stores for a slot. */
    private const LABEL_MAX = 191;

    /** Test seam (unit tests only): runs after the caller authorized a stack add and before the fenced write. */
    private ?\Closure $beforeStackWrite = null;

    public function __construct(private AssetUploadPublisher $publisher, private Lineage $lineage) {}

    /**
     * Test seam: $hook runs in addToStack() after the controller's checks and before the fenced write, so a test can
     * change access in between. Setting it outside unit tests throws, and it never runs outside them.
     */
    public function beforeStackWrite(?\Closure $hook): void
    {
        if (! app()->runningUnitTests()) {
            throw new \LogicException('CreativeHubSaver::beforeStackWrite() is a test seam and cannot be set outside unit tests.');
        }

        $this->beforeStackWrite = $hook;
    }

    /**
     * The revision of a stacked file the workspace opened: the one in that file's own slot whose bytes have the opened
     * checksum (the newest such, if the bytes came back later), else the slot's current revision when no checksum was
     * sent or none matches. A save made after someone else wrote the slot is then credited to what was opened.
     */
    public static function openedRevision(AssetFile $member, ?string $openedSha256): AssetFile
    {
        if ($openedSha256 === null || $openedSha256 === '' || hash_equals((string) $member->fileObject?->sha256, $openedSha256)) {
            return $member;
        }

        return AssetFile::query()
            ->select('dam_asset_files.*')
            ->join('dam_file_objects as objects', 'objects.id', '=', 'dam_asset_files.file_object_id')
            ->where('dam_asset_files.dam_asset_id', (int) $member->dam_asset_id)
            ->where('dam_asset_files.attachment_id', (int) $member->attachment_id)
            ->where('objects.sha256', $openedSha256)
            ->orderByDesc('dam_asset_files.revision_number')
            ->first() ?? $member;
    }

    /** The capture's own name made safe for a Library file name: no separators or control characters. */
    public static function cleanName(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        if ($name === '') {
            return 'untitled';
        }

        return self::fitName(name: $name, max: 200);
    }

    /** $name in at most $max characters, cutting the base name, never the extension: a name that lost its extension would no longer say what the file is. */
    private static function fitName(string $name, int $max): string
    {
        if (mb_strlen($name) <= $max) {
            return $name;
        }

        $dot = mb_strrpos($name, '.');
        $extension = $dot !== false && $dot > 0 ? mb_substr($name, $dot) : '';

        return mb_strlen($extension) <= 21 ? mb_substr($name, 0, $max - mb_strlen($extension)).$extension : mb_substr($name, 0, $max);
    }

    /**
     * Publishes $file as a new asset in $folder under the first free name. `existing_asset => null` is the create-only
     * switch: the publisher then never looks for a same-name asset to overwrite. The publisher attaches the folder,
     * inherits its access list (ADR 0010), queues thumbnails and flags non-admin uploads.
     *
     * The browser keeps one request id per capture and destination. Its marker is inserted by the publisher's
     * on_created hook in the same transaction as the asset. If queue dispatch or the response fails afterwards,
     * Retry finds that asset and safely re-queues its one finalization batch. A reused id with changed bytes or
     * destination is refused. Lineage and tags are linked after publication; failure there returns the saved asset
     * with `lineage_failed` so the workspace can retry only the link.
     *
     * `$sourceFile` is the stacked file the workspace opened when it was not the stack's main file: the lineage then
     * names that file's revision, not the main file's.
     *
     * @return array{asset: Asset, lineage_failed: bool}
     */
    public function saveNew(UploadedFile $file, string $name, Directory $folder, array $app, ?Asset $source, ?string $openedSha256, string $requestId, int $actorId, ?AssetFile $sourceFile = null): array
    {
        $requestId = strtolower($requestId);
        $fileSha256 = hash_file('sha256', $file->getRealPath());
        abort_if($fileSha256 === false, 422);
        $requestSha256 = hash('sha256', json_encode([
            $app['id'], (int) $folder->id, $name, $source === null ? null : (int) $source->id, $openedSha256, $fileSha256,
            ...($sourceFile === null ? [] : [(int) $sourceFile->attachment_id]),
        ], JSON_THROW_ON_ERROR));
        $finalizationKey = hash('sha256', 'creative-hub:'.$actorId.':'.$requestId);
        $marker = fn () => DB::table('dam_creative_hub_saves')
            ->where('admin_id', $actorId)->where('request_id', $requestId)->first();
        $saved = $marker();

        if ($saved === null) {
            try {
                $asset = $this->publisher->publishUploadedFile([
                    'directory'        => $folder,
                    'original_name'    => $name,
                    'mime_type'        => $file->getMimeType(),
                    'extension'        => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                    'source'           => $file,
                    'disk'             => Directory::getAssetDisk(),
                    'existing_asset'   => null,
                    'finalization_key' => $finalizationKey,
                    'on_created'       => function (Asset $created) use ($actorId, $requestId, $requestSha256): void {
                        DB::table('dam_creative_hub_saves')->insert([
                            'admin_id'       => $actorId,
                            'request_id'     => $requestId,
                            'request_sha256' => $requestSha256,
                            'asset_id'       => (int) $created->id,
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
                    },
                ]);
            } catch (\Throwable $e) {
                // The publisher commits the asset before it dispatches processing. A lost dispatch answer or a
                // concurrent request's unique marker can therefore be recovered without creating another asset.
                $saved = $marker();
                if ($saved === null) {
                    throw $e;
                }
                if (! $e instanceof UniqueConstraintViolationException) {
                    report($e);
                    CreativeHubLog::write(event: 'publish_interrupted', ctx: ['asset_id' => (int) $saved->asset_id, 'app' => $app['id'], 'error_class' => class_basename($e)], level: 'error');
                }
            }
        }

        if ($saved !== null) {
            abort_unless(hash_equals((string) $saved->request_sha256, $requestSha256), 409, trans('dam::app.admin.creative-hub.errors.save-changed'));
            $asset = Asset::query()->find((int) $saved->asset_id);
            abort_if($asset === null, 409, trans('dam::app.admin.creative-hub.errors.saved-unavailable'));
            try {
                $this->publisher->ensureFinalizationQueued(asset: $asset, tracker: null, finalizationKey: $finalizationKey);
            } catch (\Throwable $e) {
                report($e);
                CreativeHubLog::write(event: 'dispatch_failed', ctx: ['asset_id' => (int) $asset->id, 'app' => $app['id'], 'error_class' => class_basename($e)], level: 'error');
                abort(503, trans('dam::app.admin.creative-hub.errors.processing-delayed'));
            }
            CreativeHubLog::write(event: 'save_replayed', ctx: ['asset_id' => (int) $asset->id, 'app' => $app['id']]);
        }

        if ($source === null) {
            return ['asset' => $asset, 'lineage_failed' => false];
        }

        try {
            DB::transaction(function () use ($asset, $source, $app, $openedSha256, $sourceFile): void {
                // Serialize direct save and Retry link for this output; Lineage::record has no unique row key.
                $locked = Asset::query()->whereKey($asset->id)->lockedForGraph()->firstOrFail();
                $rendition = $this->lineage->record(
                    assetId: (int) $source->id,
                    recipe: Rendition::RECIPE_CREATIVE_HUB,
                    source: $sourceFile ?? $this->lineage->revisionWithSha(assetId: (int) $source->id, sha256: $openedSha256) ?? $this->lineage->currentRevision(assetId: (int) $source->id),
                    worker: 'creative-hub:'.$app['id'],
                    outputAssetId: (int) $asset->id,
                    parameters: ['app' => $app['id'], 'app_version' => $app['version'], 'mode' => 'new'],
                );
                if ($rendition->wasRecentlyCreated) {
                    $locked->tags()->syncWithoutDetaching($source->tags()->pluck('dam_tags.id')->all());
                }
            });
        } catch (\Throwable $e) {
            report($e);
            CreativeHubLog::write(
                event: 'lineage_failed',
                ctx: ['asset_id' => (int) $asset->id, 'source_asset_id' => (int) $source->id, 'app' => $app['id'], 'mode' => 'new', 'error_class' => class_basename($e)],
                level: 'error',
            );

            return ['asset' => $asset, 'lineage_failed' => true];
        }

        return ['asset' => $asset, 'lineage_failed' => false];
    }

    /**
     * Saves $file as the next revision of one stacked file that is not the stack's main file (the main file goes
     * through replace()). The same rules as replace(), decided on the row locked under the access-model fence: the
     * actor may still replace the entry, it is not a merge source, the slot is still a member and still not the main
     * file, its current bytes are the ones the workspace opened, and the name and content are the file's own type.
     * AttachmentLedger::appendRevision writes the revision (the old one stays as history, restorable from Files);
     * the lineage row names the opened revision and the one written. Null when the capture is the opened bytes.
     *
     * @return array{asset: Asset, file: AssetFile, version: int, sha256: string}|null
     */
    public function replaceMember(UploadedFile $file, Asset $source, int $attachmentId, array $app, string $openedSha256, int $actorId, string $requestedExtension): ?array
    {
        $unchanged = hash_equals($openedSha256, (string) hash_file('sha256', $file->getRealPath()));
        $mime = (string) MimeTypes::getDefault()->guessMimeType($file->getRealPath());
        $capturePath = (string) $file->getRealPath();
        $loaded = app(StackMembers::class)->find(asset: $source, attachmentId: $attachmentId);
        abort_if($loaded === null, 404);
        $loadedExtension = StackFileName::extension($loaded, $source);

        if (! $unchanged) {
            abort_unless(self::contentMatches(detectedMime: $mime, extension: $loadedExtension, path: $capturePath), 422, trans('dam::app.admin.creative-hub.errors.wrong-content', ['ext' => $loadedExtension]));
        }

        $object = $unchanged ? null : $this->publisher->ingestUploadedFile(file: $file, disk: Directory::getAssetDisk(), mimeType: $mime);

        return app(AccessModel::class)->sharedly(function () use ($source, $attachmentId, $object, $openedSha256, $actorId, $mime, $app, $requestedExtension, $capturePath): ?array {
            $locked = Asset::query()->whereKey($source->id)->lockedForGraph()->first();
            abort_if($locked === null, 404);
            abort_if($locked->isMerged(), 409, trans('dam::app.admin.creative-hub.errors.merged'));
            abort_unless(
                $this->publisher->mayReplace(existing: $locked, actorId: $actorId),
                403,
                app(RoleBuilder::class)->refusal($locked) ?? trans('dam::app.admin.permissions.unauthorized'),
            );
            $member = app(StackMembers::class)->find(asset: $locked, attachmentId: $attachmentId);
            abort_if($member === null, 404);
            // Promoted to the main file since it was opened: a version of it now is a version of the entry, decided by replace().
            abort_if((bool) $member->attachment?->is_representative, 409, trans('dam::app.admin.creative-hub.save.stale'));
            abort_unless(hash_equals((string) $member->fileObject->sha256, $openedSha256), 409, trans('dam::app.admin.creative-hub.save.stale'));
            $extension = StackFileName::extension($member, $locked);
            abort_unless(OpenWithResolver::sameType(first: $requestedExtension, second: $extension), 422, trans('dam::app.admin.creative-hub.save.replace-type'));

            if ($object === null) {
                return null;   // checked, and the capture is the member's current bytes: nothing to version
            }

            abort_unless(self::contentMatches(detectedMime: $mime, extension: $extension, path: $capturePath), 422, trans('dam::app.admin.creative-hub.errors.wrong-content', ['ext' => $extension]));
            $written = app(AttachmentLedger::class)->appendRevision(
                asset: $locked,
                attachmentId: $attachmentId,
                object: $object,
                note: trans('dam::app.admin.creative-hub.stack.revisionNote', ['app' => $app['name']]),
            );
            abort_if($written === null, 500, 'The member save wrote no revision.');
            $this->lineage->record(
                assetId: (int) $locked->id,
                recipe: Rendition::RECIPE_CREATIVE_HUB,
                source: $member,
                worker: 'creative-hub:'.$app['id'],
                outputFileId: (int) $written->id,
                parameters: ['app' => $app['id'], 'app_version' => $app['version'], 'mode' => 'version', 'attachment_id' => $attachmentId],
            );

            return ['asset' => $locked, 'file' => $written->load(['attachment', 'fileObject']), 'version' => (int) $written->revision_number, 'sha256' => (string) $object->sha256];
        });
    }

    /**
     * Adds $file to $stack as a new file of its own (role STACK_ROLE, labelled with the capture's name), through the
     * Library's attach path (AttachmentLedger::attach), so nothing about the stack's storage changes. The entry is
     * locked under the access-model fence first and a merge source is refused. The same bytes already current under
     * that role are the same file, so a retried save adds nothing twice. The lineage row names the file the workspace
     * opened ($sourceFile, a member or the main file's revision), when there was one. The actor's right to add files
     * to this entry is asked again on the locked row, so a role or access change since the request was authorized
     * refuses the write.
     *
     * $onAttached (optional, like saveNew's on_created) runs with the loaded new member inside the same transaction,
     * after the attach: a caller records its request result there, and if that throws the attach rolls back with it.
     * A blob the attach already wrote stays on disk unreferenced; it is content-addressed, so a retry of the same bytes
     * reuses it, but an unretried one stays under ContentStore::ROOT, which current reclaim tooling does not scan.
     *
     * @param  (\Closure(AssetFile): void)|null  $onAttached
     */
    public function addToStack(UploadedFile $file, Asset $stack, string $name, array $app, ?AssetFile $sourceFile, int $actorId, ?\Closure $onAttached = null): AssetFile
    {
        if ($this->beforeStackWrite !== null && app()->runningUnitTests()) {
            ($this->beforeStackWrite)();
        }

        return app(AccessModel::class)->sharedly(function () use ($file, $stack, $name, $app, $sourceFile, $actorId, $onAttached): AssetFile {
            $locked = Asset::query()->whereKey($stack->id)->lockedForGraph()->first();
            abort_if($locked === null, 404);
            abort_unless($this->mayAddFiles(stack: $locked, actorId: $actorId), 403, trans('dam::app.admin.permissions.unauthorized'));
            abort_if($locked->isMerged(), 409, trans('dam::app.admin.creative-hub.errors.merged'));
            $added = app(AttachmentLedger::class)->attach(asset: $locked, upload: $file, role: self::STACK_ROLE, label: self::fitName(name: $name, max: self::LABEL_MAX));

            if ($sourceFile !== null && (int) $sourceFile->dam_asset_id === (int) $locked->id) {
                $this->lineage->record(
                    assetId: (int) $locked->id,
                    recipe: Rendition::RECIPE_CREATIVE_HUB,
                    source: $sourceFile,
                    worker: 'creative-hub:'.$app['id'],
                    outputFileId: (int) $added->id,
                    parameters: ['app' => $app['id'], 'app_version' => $app['version'], 'mode' => 'stack'],
                );
            }

            $added->load(['attachment', 'fileObject']);
            if ($onAttached !== null) {
                $onAttached($added);
            }

            return $added;
        });
    }

    /**
     * The controller's add-files rule (the add-files and edit keys, and edit access to the entry), asked of the actor
     * against the row locked under the access-model fence.
     */
    private function mayAddFiles(Asset $stack, int $actorId): bool
    {
        $actor = Admin::query()->with('role')->find($actorId);

        if ($actor === null || ! (bool) $actor->status || $actor->role === null) {
            return false;
        }

        if ($actor->role->permission_type !== 'all' && ! ($actor->hasPermission('dam.asset.files.store') && $actor->hasPermission('dam.asset.edit'))) {
            return false;
        }

        return app(AssetAccessDecision::class)
            ->for(AccessContext::forAdmin($actor, AccessContext::PURPOSE_EDIT), $stack)
            ->allowed;
    }

    /**
     * Whether bytes whose finfo MIME (as UploadedFile::getMimeType reads it) is $detectedMime are a $extension file.
     * A replace keeps the original's name and type, so a PSD named `x.png` must not become a "PNG" version. It takes
     * the MIME, not the file, so replace() can decide it again against the locked row's extension. An extension with
     * no registered MIME (an app's own project type) has only the name rule to go on.
     */
    public static function contentMatches(string $detectedMime, string $extension, ?string $path = null): bool
    {
        $detected = strtolower($detectedMime);
        $extension = strtolower($extension);
        // Types finfo cannot name (DXF is text or a bare header, OOXML and the apps' own projects are plain zips)
        // are decided by their own signature, strictly: the MIME alone would either refuse them or wave anything in.
        if ($path !== null && (self::SIGNATURE_TYPES[$extension] ?? null) !== null) {
            return (in_array($detected, MimeTypes::getDefault()->getMimeTypes($extension), true)
                    || in_array($detected, [...self::GENERIC_CONTENT, 'application/zip', 'application/x-zip-compressed'], true))
                && self::signatureMatches(extension: $extension, path: $path);
        }

        $mimes = MimeTypes::getDefault();
        $expected = $mimes->getMimeTypes(strtolower($extension));
        if ($expected === []) {
            return true;
        }

        if (in_array($detected, $expected, true)) {
            return true;
        }
        foreach ($mimes->getExtensions($detected) as $candidate) {
            if (OpenWithResolver::sameType(first: $candidate, second: $extension)) {
                return true;   // jpg/jpeg, tif/tiff and other spellings of one type
            }
        }

        $textual = fn (string $mime): bool => str_starts_with($mime, 'text/') || str_ends_with($mime, '+xml') || str_ends_with($mime, '+json')
            || in_array($mime, ['application/json', 'application/xml'], true);

        return in_array($detected, self::GENERIC_CONTENT, true) && array_filter($expected, $textual) !== [];
    }

    /** Bytes of an ASCII DXF read looking for its first group-code/value pair after any 999 comments. */
    private const DXF_SCAN_BYTES = 65536;

    /**
     * DXF: the full binary sentinel, or ASCII line pairs whose first non-comment (code 999) pair is `0` / `SECTION`.
     */
    private static function dxfMatches(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $head = (string) fread($handle, 22);
            if ($head === "AutoCAD Binary DXF\r\n\x1a\x00") {
                return true;
            }
            rewind($handle);
            $read = 0;
            $next = function () use ($handle, &$read): ?string {
                // A whole physical line within what is left of the budget; a line cut short by the budget is no line.
                $left = self::DXF_SCAN_BYTES - $read;
                $line = $left > 0 ? fgets($handle, $left + 1) : false;
                if ($line === false) {
                    return null;
                }
                $read += strlen($line);
                if (! str_ends_with($line, "\n") && ! feof($handle)) {
                    return null;
                }

                return trim($line);
            };
            $first = true;
            while (true) {
                $code = $next();
                $value = $code === null ? null : $next();
                if ($code === null || $value === null) {
                    return false;
                }
                if ($first) {
                    $code = preg_replace('/^\xEF\xBB\xBF/', '', $code);
                    $first = false;
                }
                if ($code === '999') {
                    continue;
                }

                return $code === '0' && $value === 'SECTION';
            }
        } finally {
            fclose($handle);
        }
    }

    /** Whether the file at $path carries the signature of a SIGNATURE_TYPES extension. */
    private static function signatureMatches(string $extension, string $path): bool
    {
        if (self::SIGNATURE_TYPES[$extension] === 'dxf') {
            return self::dxfMatches(path: $path);
        }
        $head = (string) @file_get_contents($path, false, null, 0, 4);
        if (! str_starts_with($head, "PK\x03\x04")) {
            return false;
        }
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            $prefix = self::SIGNATURE_TYPES[$extension];
            if ($prefix === '') {
                return true;   // an app's own zip project: the zip container is the whole check
            }
            if ($zip->locateName('[Content_Types].xml') === false) {
                return false;
            }

            return $zip->locateName($prefix) !== false;   // the family's main part, not just any entry under its folder
        } finally {
            $zip->close();
        }
    }

    /**
     * Replaces $source's original with $file as a new version: the graph observer keeps the old bytes as a restorable
     * revision with an AssetVersion row. Every refusal is decided on the row locked under the access-model fence (as
     * AssetUploadPublisher::overwriteUnlessMerged does) BEFORE "unchanged" is: the actor may still replace it, it is
     * not a merge source, and its bytes are still the ones the workspace opened. The lineage row is written in the
     * same transaction, against the revision this call wrote, so a concurrent replace cannot be recorded in its
     * place; processing is dispatched after the commit. The name and content rules are decided on the locked row
     * too: the same bytes can be renamed to another type between the controller's load and the lock, and the hash
     * check cannot see that. The version reported is the AssetVersion the observer tied to this call's revision,
     * read before the commit, never a max(number) read after it. The checksum is the written revision's own (the
     * ingested object's), not a read of whatever the asset holds after the commit.
     * Null when the capture is the opened bytes: nothing to version.
     *
     * @return array{asset: Asset, version: int, sha256: string}|null
     */
    public function replace(UploadedFile $file, Asset $source, array $app, string $openedSha256, int $actorId, string $requestedExtension): ?array
    {
        $unchanged = hash_equals($openedSha256, (string) hash_file('sha256', $file->getRealPath()));
        // Read from the bytes, as Symfony's File does for a real upload: a test double's getMimeType() goes by the name.
        $mime = (string) MimeTypes::getDefault()->guessMimeType($file->getRealPath());
        $capturePath = (string) $file->getRealPath();
        // Fast path on the loaded row, so content of the wrong type is not streamed to the store; the locked
        // row below decides.
        if (! $unchanged) {
            abort_unless(self::contentMatches(detectedMime: $mime, extension: (string) $source->extension, path: $file->getRealPath()), 422, trans('dam::app.admin.creative-hub.errors.wrong-content', ['ext' => (string) $source->extension]));
        }

        // Ingest outside the lock (it streams the bytes to the store), as the upload path does before
        // overwriteUnlessMerged; an object a refusal leaves unreferenced is reaped like any refused overwrite's.
        $object = $unchanged ? null : $this->publisher->ingestUploadedFile(file: $file, disk: Directory::getAssetDisk(), mimeType: $mime);

        $replaced = app(AccessModel::class)->sharedly(function () use ($source, $object, $openedSha256, $actorId, $mime, $app, $requestedExtension, $capturePath): ?array {
            $locked = Asset::query()->whereKey($source->id)->lockedForGraph()->first();
            abort_if($locked === null, 404);
            abort_if($locked->isMerged(), 409, trans('dam::app.admin.creative-hub.errors.merged'));
            abort_unless(
                $this->publisher->mayReplace(existing: $locked, actorId: $actorId),
                403,
                app(RoleBuilder::class)->refusal($locked) ?? trans('dam::app.admin.permissions.unauthorized'),
            );
            try {
                $currentSha256 = (string) app(ContentStore::class)->current($locked)->sha256;
            } catch (ContentUnavailable) {
                abort(409, trans('dam::app.admin.creative-hub.save.stale'));   // no bytes to compare with: the opened ones are gone
            }
            abort_unless(hash_equals($currentSha256, $openedSha256), 409, trans('dam::app.admin.creative-hub.save.stale'));
            // Same bytes, but the row may have been renamed to another type since the controller loaded it.
            abort_unless(OpenWithResolver::sameType(first: $requestedExtension, second: (string) $locked->extension), 422, trans('dam::app.admin.creative-hub.save.replace-type'));
            if ($object === null) {
                return null;   // checked, and the capture is the current bytes: nothing to version
            }
            abort_unless(self::contentMatches(detectedMime: $mime, extension: (string) $locked->extension, path: $capturePath), 422, trans('dam::app.admin.creative-hub.errors.wrong-content', ['ext' => (string) $locked->extension]));

            $opened = $this->lineage->revisionWithSha(assetId: (int) $locked->id, sha256: $openedSha256);
            $locked->withGraphContent($object, $actorId)->update(
                ['file_size' => (int) $object->size] + ($mime !== '' && $mime !== 'application/octet-stream' ? ['mime_type' => $mime] : []),
            );
            $written = $this->lineage->currentRevision(assetId: (int) $locked->id);
            $this->lineage->record(
                assetId: (int) $locked->id,
                recipe: Rendition::RECIPE_CREATIVE_HUB,
                source: $opened,
                worker: 'creative-hub:'.$app['id'],
                outputFileId: $written?->id,
                parameters: ['app' => $app['id'], 'app_version' => $app['version'], 'mode' => 'version'],
            );
            // The observer stamps the version it creates with this revision, so the number is this call's own,
            // whatever commits after us.
            $version = AssetVersion::query()->where('dam_asset_id', $locked->id)->where('primary_asset_file_id', $written?->id)->value('number');
            abort_if($version === null, 500, 'The replace wrote no version row.');

            return ['asset' => $locked->refresh(), 'version' => (int) $version, 'sha256' => (string) $object->sha256];
        });

        if ($replaced !== null) {
            // The new version is committed: a queue that is down must not turn it into a failed save the user retries.
            // The failure is reported and logged instead.
            try {
                ProcessAssetUpload::dispatch($replaced['asset']->id, null, bytesChanged: true);
            } catch (\Throwable $e) {
                report($e);
                CreativeHubLog::write(event: 'dispatch_failed', ctx: ['asset_id' => (int) $replaced['asset']->id, 'error_class' => class_basename($e)], level: 'error');
            }
        }

        return $replaced;
    }
}
