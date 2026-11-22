<?php

namespace Mothership\DAM\Mcp;

use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Repositories\DirectoryRolePermissionRepository;
use Mothership\DAM\Rules\TagNameRule;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\CreativeHub\CreativeHubSaver;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\FolderAccessService;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Services\Stack\StackMembers;
use Mothership\DAM\Services\Tags\AssetTagWriter;
use Mothership\DAM\Support\ReservedDirectoryNames;
use Mothership\DAM\Support\StackFileName;
use Mothership\DAM\Vectorization\AgentVectorizations;
use Mothership\User\Models\Admin;

/**
 * The `mothership` library tools (docs/api/mothership-mcp.md). Every call acts as the one admin account named by
 * MOTHERSHIP_MCP_USER_ID, with that account's own permissions and folder access: the stdio server has no sign-in of
 * its own. Bytes go through ContentStore and access through AssetAccessDecision, as in the Library. A file is only
 * ever handed out as a verified copy outside The Mothership's storage, never as a path into it.
 */
class LibraryOperations
{
    public const SEARCH_PAGE = 50;

    public const SEARCH_MAX = 100;

    public const FOLDER_PAGE = 500;

    public const STACK_PAGE = 50;

    /** How far library_fetch counts name-2, name-3 ... before refusing a crowded folder. */
    private const MAX_NAME_TRIES = 1000;

    public function __construct(
        private ContentStore $content,
        private AssetAccessDecision $access,
        private DirectoryPermissionService $directories,
        private CreativeHubSaver $saver,
        private AccessModel $accessModel,
        private StackMembers $members,
        private DirectoryRepository $directoryRepository,
        private AssetTagWriter $tags,
    ) {}

    public function execute(string $operation, array $arguments): array
    {
        $actor = $this->actor();

        return match ($operation) {
            'search'        => $this->search(actor: $actor, arguments: $arguments),
            'list_folders'  => $this->listFolders(actor: $actor, arguments: $arguments),
            'create_folder' => $this->createFolder(actor: $actor, arguments: $arguments),
            'get_asset'     => $this->getAsset(actor: $actor, arguments: $arguments),
            'list_stack'    => $this->listStack(actor: $actor, arguments: $arguments),
            'fetch'         => $this->fetch(actor: $actor, arguments: $arguments),
            'save'          => $this->save(actor: $actor, arguments: $arguments),
            'update'        => $this->update(actor: $actor, arguments: $arguments),
            'vectorize'     => $this->vectorize(actor: $actor, arguments: $arguments),
            'vectorization' => $this->vectorization(actor: $actor, arguments: $arguments),
            default         => throw new LibraryFailure('Unknown library operation.'),
        };
    }

    /**
     * The configured owner, also made the admin guard's user: publication (created_by, the needs-sorting flag) and
     * folder grants read the signed-in admin, and a stdio process has no session to take one from.
     */
    public function actor(): Admin
    {
        $id = (int) config('mothership-mcp.user_id', 0);
        if ($id <= 0) {
            throw new LibraryFailure('The library server has no owner account. Set MOTHERSHIP_MCP_USER_ID to the id of an active admin account in The Mothership\'s .env, then restart the server.');
        }
        $actor = Admin::query()->with('role')->find($id);
        if (! $actor instanceof Admin || ! $actor->status) {
            throw new LibraryFailure('MOTHERSHIP_MCP_USER_ID does not name an active admin account. Fix it in The Mothership\'s .env, then restart the server.');
        }
        auth('admin')->setUser($actor);

        return $actor;
    }

    private function search(Admin $actor, array $arguments): array
    {
        abort_unless($this->can(actor: $actor, permission: 'dam.asset'), 403);
        $query = Asset::query();
        $this->access->scopeListable(query: $query, context: AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_LIST));
        $folderId = isset($arguments['folder_id']) ? (int) $arguments['folder_id'] : null;
        if ($folderId !== null) {
            // A hidden folder answers exactly like a missing one; scopeListable still hides assets inside a visible one.
            abort_unless(Directory::query()->whereKey($folderId)->exists() && $this->directories->forAdmin(admin: $actor)->canView(directoryId: $folderId), 404);
            $query->whereExists(fn ($q) => $q->from('dam_asset_directory')->whereColumn('dam_asset_directory.asset_id', 'dam_assets.id')->where('dam_asset_directory.directory_id', $folderId));
        }
        if (($arguments['query'] ?? '') !== '') {
            // File name, title and tag names, either case: PostgreSQL's LIKE is case-sensitive.
            $needle = '%'.addcslashes(mb_strtolower($arguments['query']), '%_\\').'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(file_name) LIKE ?', [$needle])->orWhereRaw('LOWER(title) LIKE ?', [$needle])
                ->orWhereExists(fn ($tags) => $tags->from('dam_asset_tag')->join('dam_tags', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
                    ->whereColumn('dam_asset_tag.asset_id', 'dam_assets.id')->whereRaw('LOWER(dam_tags.name) LIKE ?', [$needle])));
        }
        if (($arguments['tag'] ?? '') !== '') {
            $tag = mb_strtolower(TagNameRule::normalize((string) $arguments['tag']));
            $query->whereExists(fn ($tags) => $tags->from('dam_asset_tag')->join('dam_tags', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
                ->whereColumn('dam_asset_tag.asset_id', 'dam_assets.id')->whereRaw('LOWER(dam_tags.name) = ?', [$tag]));
        }
        if (($arguments['extension'] ?? '') !== '') {
            $query->whereRaw('LOWER(extension) = ?', [mb_strtolower(ltrim($arguments['extension'], '.'))]);
        }
        if (($arguments['kind'] ?? '') !== '') {
            $query->whereRaw('LOWER(file_type) = ?', [mb_strtolower($arguments['kind'])]);
        }
        if (($arguments['producer'] ?? '') !== '') {
            $query->whereExists(fn ($saves) => $saves->from('dam_creative_hub_saves')->whereColumn('dam_creative_hub_saves.asset_id', 'dam_assets.id')
                ->whereNull('dam_creative_hub_saves.asset_file_id')->where('dam_creative_hub_saves.evidence->producer', mb_strtolower($arguments['producer'])));
        }
        $excluded = array_map('intval', (array) config('mothership-mcp.search_excluded_folders', []));
        if ($folderId === null && $excluded !== []) {
            // QA and test output folders stay out of open searches; folder_id still lists them.
            $query->whereNotExists(fn ($q) => $q->from('dam_asset_directory')->whereColumn('dam_asset_directory.asset_id', 'dam_assets.id')->whereIn('dam_asset_directory.directory_id', $excluded));
        }
        $limit = min(self::SEARCH_MAX, (int) ($arguments['limit'] ?? self::SEARCH_PAGE));
        $rows = $query->where('id', '>', $arguments['after_id'] ?? 0)->orderBy('id')->limit($limit + 1)
            ->with(['tags' => fn ($q) => $q->select('dam_tags.id', 'dam_tags.name')])
            ->get(['id', 'file_name', 'title', 'file_type', 'extension', 'file_size', 'created_at']);
        $page = $rows->take($limit);
        $producers = $this->producers(assetIds: $page->pluck('id')->all());
        $assets = [];
        foreach ($page as $asset) {
            $assets[] = [
                'asset_id'   => (int) $asset->id,
                'title'      => $asset->title,
                'file_name'  => $asset->file_name,
                'extension'  => $asset->extension,
                'kind'       => $asset->file_type,
                'size'       => (int) $asset->file_size,
                'tags'       => $asset->tags->pluck('name')->values()->all(),
                'producer'   => $producers[(int) $asset->id] ?? null,
                'created_at' => $asset->created_at?->toIso8601String(),
            ];
        }

        return [
            'assets'        => $assets,
            'next_after_id' => $rows->count() > $limit ? (int) $page->last()->id : null,
            ...($folderId !== null ? ['folder_id' => $folderId] : []),
            'matches'       => 'query matches file name, title and tag names, case-insensitive; descriptions and subjects are not searched',
        ];
    }

    private function listFolders(Admin $actor, array $arguments): array
    {
        $permissions = $this->directories->forAdmin(admin: $actor);
        $query = Directory::query();
        if (! $permissions->bypass()) {
            $query->whereIntegerInRaw('id', $permissions->directlyGrantedIds());
        }
        if (isset($arguments['parent_id'])) {
            $query->where('parent_id', (int) $arguments['parent_id']);
        }
        if (($arguments['name'] ?? '') !== '') {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.addcslashes(mb_strtolower($arguments['name']), '%_\\').'%']);
        }
        $rows = $query->where('id', '>', $arguments['after_id'] ?? 0)->orderBy('id')->limit(self::FOLDER_PAGE + 1)->get(['id', 'name', 'parent_id']);
        $folders = [];
        foreach ($rows->take(self::FOLDER_PAGE) as $folder) {
            $folders[] = ['folder_id' => (int) $folder->id, 'name' => $folder->name, 'parent_id' => $folder->parent_id === null ? null : (int) $folder->parent_id];
        }

        return [
            'folders'       => $folders,
            'limit'         => self::FOLDER_PAGE,
            'next_after_id' => $rows->count() > self::FOLDER_PAGE ? (int) $rows[self::FOLDER_PAGE - 1]->id : null,
        ];
    }

    /**
     * A subfolder, as the Library's New folder makes it. An existing visible folder of that name (either case) under
     * the same parent is returned instead of a second one, so a retried call creates nothing twice.
     */
    private function createFolder(Admin $actor, array $arguments): array
    {
        abort_unless($this->can(actor: $actor, permission: 'dam.directory.store'), 403);
        $parentId = (int) $arguments['parent_id'];
        $permissions = $this->directories->forAdmin(admin: $actor);
        abort_unless(Directory::query()->whereKey($parentId)->exists() && $permissions->canAccess(directoryId: $parentId), 404);
        $name = trim((string) $arguments['name']);
        $existing = Directory::query()->where('parent_id', $parentId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();
        if ($existing instanceof Directory && $permissions->canView(directoryId: (int) $existing->id)) {
            return ['folder_id' => (int) $existing->id, 'name' => $existing->name, 'parent_id' => $parentId, 'created' => false];
        }
        if (ReservedDirectoryNames::isReserved($name)) {
            abort(422, 'That folder name is reserved in The Mothership. Choose another name.');
        }
        $folder = $this->directoryRepository->create(['name' => $name, 'parent_id' => $parentId]);
        $this->grantToCreator(actor: $actor, directoryId: (int) $folder->id);
        MothershipLog::decision(event: 'create_folder', loc: 'LibraryOperations::createFolder', ctx: ['outcome' => 'created']);

        return ['folder_id' => (int) $folder->id, 'name' => $folder->name, 'parent_id' => $parentId, 'created' => true];
    }

    /** DirectoryController::autoGrantToCreator for this actor: under `lists` the owner rule already covers the creator. */
    private function grantToCreator(Admin $actor, int $directoryId): void
    {
        if ($this->accessModel->lists()) {
            return;
        }
        $role = $actor->role;
        if (! $role || $role->permission_type !== 'custom'
            || DB::table('dam_role_settings')->where('role_id', $role->id)->where('all_directories', true)->exists()) {
            return;
        }
        // Personal or group access grants the creator; otherwise the legacy role grant applies.
        if (! app(FolderAccessService::class)->grantToCreator(adminId: (int) $actor->id, directoryId: $directoryId)) {
            app(DirectoryRolePermissionRepository::class)->addDirectoryToRole($role->id, $directoryId);
        }
        $this->directories->flush();
    }

    private function getAsset(Admin $actor, array $arguments): array
    {
        abort_unless($this->can(actor: $actor, permission: 'dam.asset'), 403);
        $asset = $this->access->for(context: AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_LIST), asset: (int) $arguments['asset_id'])->allowedOrFail();
        $object = $this->content->current(asset: $asset);
        [$width, $height] = self::pixels(object: $object);
        $permissions = $this->directories->forAdmin(admin: $actor);
        $folders = [];
        foreach (Directory::query()->whereIn('id', DB::table('dam_asset_directory')->where('asset_id', (int) $asset->id)->select('directory_id'))->orderBy('id')->get(['id', 'name']) as $folder) {
            if ($permissions->canView(directoryId: (int) $folder->id)) {
                $folders[] = ['folder_id' => (int) $folder->id, 'name' => $folder->name];
            }
        }
        $record = $this->libraryRecord(assetId: (int) $asset->id);

        return [
            'asset_id'    => (int) $asset->id,
            'title'       => $asset->title,
            'file_name'   => $asset->file_name,
            'extension'   => $asset->extension,
            'kind'        => $asset->file_type,
            'size'        => (int) $object->size,
            'sha256'      => (string) $object->sha256,
            'width'       => $width,
            'height'      => $height,
            'has_proxy'   => $asset->publishedWebProxy() !== null,
            'stack_files' => $this->members->forAsset(asset: $asset)->count(),
            'merged'      => $asset->isMerged(),
            'folders'     => $folders,
            'tags'        => $asset->tags()->orderBy('dam_tags.name')->pluck('dam_tags.name')->values()->all(),
            'producer'    => $record['producer'] ?? null,
            ...(isset($record['producer_app']) ? ['producer_app' => $record['producer_app']] : []),
            ...(isset($record['producer_version']) ? ['producer_version' => $record['producer_version']] : []),
            ...(isset($record['provenance']) ? ['provenance' => $record['provenance']] : []),
            ...(isset($record['notes']) ? ['notes' => $record['notes']] : []),
            'created_at'  => $asset->created_at?->toIso8601String(),
            'updated_at'  => $asset->updated_at?->toIso8601String(),
            'url'         => route('admin.dam.assets.edit', $asset->id),
        ];
    }

    private function listStack(Admin $actor, array $arguments): array
    {
        abort_unless($this->can(actor: $actor, permission: 'dam.asset.download'), 403);
        $asset = $this->access->for(context: AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_DOWNLOAD), asset: (int) $arguments['asset_id'])->allowedOrFail();
        $after = (int) ($arguments['after_id'] ?? 0);
        // Filters narrow the listing before it is paged, so next_after_id stays right for the filtered list.
        $extension = isset($arguments['extension']) ? strtolower(ltrim((string) $arguments['extension'], '.')) : null;
        $name = isset($arguments['name']) ? mb_strtolower((string) $arguments['name']) : null;
        $members = $this->members->forAsset(asset: $asset)
            ->filter(fn (AssetFile $member): bool => (int) $member->id > $after
                && ($extension === null || strtolower(StackFileName::extension(file: $member, asset: $asset)) === $extension)
                && ($name === null || str_contains(mb_strtolower(StackFileName::fileName(file: $member, asset: $asset)), $name)))
            ->sortBy(fn (AssetFile $member): int => (int) $member->id)
            ->values();
        $page = $members->take(self::STACK_PAGE);
        $producers = Rendition::query()->where('recipe', Rendition::RECIPE_CREATIVE_HUB)
            ->whereIn('output_asset_file_id', $page->pluck('id')->all())
            ->pluck('worker', 'output_asset_file_id');

        return [
            'asset_id'      => (int) $asset->id,
            'merged'        => $asset->isMerged(),
            'files'         => $page->map(function (AssetFile $member) use ($asset, $producers): array {
                $worker = (string) ($producers[$member->id] ?? '');

                return [
                    'file_id'    => (int) $member->id,
                    'label'      => StackFileName::fileName(file: $member, asset: $asset),
                    'extension'  => StackFileName::extension(file: $member, asset: $asset),
                    'role'       => (string) $member->role,
                    'size'       => (int) $member->fileObject->size,
                    'sha256'     => (string) $member->fileObject->sha256,
                    'is_cover'   => (bool) $member->attachment?->is_representative,
                    'created_at' => $member->created_at?->toIso8601String(),
                    'producer'   => str_starts_with($worker, 'creative-hub:') ? substr($worker, 13) : null,
                ];
            })->all(),
            'limit'         => self::STACK_PAGE,
            'next_after_id' => $members->count() > self::STACK_PAGE ? (int) $page->last()->id : null,
            'next'          => 'To work on one of these files, copy it out with library_fetch asset_id and file_id. producer is null for files that were not saved by a tool.',
        ];
    }

    /**
     * Copies one file of an asset to a local folder outside The Mothership's storage, checking the bytes against
     * their recorded sha256 as they stream. A file already there with the same bytes is reused; any other file of
     * that name is never overwritten: the copy takes the next free name-N.
     */
    private function fetch(Admin $actor, array $arguments): array
    {
        abort_unless($this->can(actor: $actor, permission: 'dam.asset.download'), 403);
        $asset = $this->access->for(context: AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_DOWNLOAD), asset: (int) $arguments['asset_id'])->allowedOrFail();
        $proxy = ($arguments['proxy'] ?? false) === true;
        if ($proxy && isset($arguments['file_id'])) {
            throw new LibraryFailure('Pass proxy or file_id, not both: the proxy is made from the asset\'s main file.');
        }
        $name = (string) $asset->file_name;
        if ($proxy) {
            // The 1080p H.264 web proxy already made from the main video (ADR 0003).
            $object = $asset->publishedWebProxy()?->fileObject;
            if (! $object instanceof FileObject) {
                throw new LibraryFailure('This asset has no 1080p web proxy (it is not a video, or its proxy is not made yet). Fetch the original instead.');
            }
            $name = pathinfo($name, PATHINFO_FILENAME).'-proxy.mp4';
        } elseif (isset($arguments['file_id'])) {
            // Only a current stack member of this very asset; another asset's file answers like a missing one.
            $member = $this->members->findFile(asset: $asset, fileId: (int) $arguments['file_id']);
            $object = $member?->fileObject;
            abort_unless($member instanceof AssetFile && $object instanceof FileObject, 404);
            $name = StackFileName::fileName(file: $member, asset: $asset);
        } else {
            $object = $this->content->current(asset: $asset);
        }
        $directory = $this->fetchDirectory(requested: $arguments['dest_dir'] ?? null);
        [$path, $reused] = $this->copyOut(open: fn () => $this->content->open(object: $object), sha256: (string) $object->sha256, size: (int) $object->size, directory: $directory, name: CreativeHubSaver::cleanName(name: $name));
        [$width, $height] = self::pixels(object: $object);
        MothershipLog::decision(event: 'fetch', loc: 'LibraryOperations::fetch', ctx: ['outcome' => 'copied', 'size' => (int) $object->size, 'reused' => $reused]);

        return [
            'path'     => $path,
            'name'     => basename($path),
            'sha256'   => (string) $object->sha256,
            'size'     => (int) $object->size,
            'asset_id' => (int) $asset->id,
            ...(isset($arguments['file_id']) ? ['file_id' => (int) $arguments['file_id']] : []),
            ...($proxy ? ['proxy' => true] : []),
            ...($width !== null ? ['width' => $width, 'height' => $height] : []),
            'reused'   => $reused,
            'note'     => 'This is your own copy: changing it never changes the library. Save results back with library_save.',
        ];
    }

    /** The folder library_fetch writes into: absolute, created when missing, and never inside The Mothership's storage. */
    private function fetchDirectory(?string $requested): string
    {
        $configured = (string) config('mothership-mcp.fetch_dir', '');
        $directory = $requested ?? ($configured !== '' ? $configured : sys_get_temp_dir().'/mothership-library');
        if (! str_starts_with($directory, '/')) {
            throw new LibraryFailure('dest_dir must be an absolute path on this machine.');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new LibraryFailure('Cannot create dest_dir. Pass a folder this server can write to.');
        }
        $real = realpath($directory);
        $storage = realpath(storage_path());
        if ($real === false || ! is_writable($real)) {
            throw new LibraryFailure('Cannot write to dest_dir. Pass a folder this server can write to.');
        }
        if ($storage !== false && ($real === $storage || str_starts_with($real.'/', $storage.'/'))) {
            throw new LibraryFailure('dest_dir is inside The Mothership\'s own storage. Choose a folder outside it.');
        }

        return $real;
    }

    /**
     * @param  callable(): resource  $open  the bytes to copy, which must hash to $sha256
     * @return array{0: string, 1: bool} the copy's path, and whether an identical file already there was reused
     */
    private function copyOut(callable $open, string $sha256, int $size, string $directory, string $name): array
    {
        $dot = strrpos($name, '.');
        [$base, $extension] = $dot === false || $dot === 0 ? [$name, ''] : [substr($name, 0, $dot), substr($name, $dot)];
        // An identical copy already at one of the names this fetch would use is reused without streaming again.
        for ($try = 1; $try <= self::MAX_NAME_TRIES; $try++) {
            $candidate = $directory.'/'.($try === 1 ? $name : $base.'-'.$try.$extension);
            if (! file_exists($candidate) && ! is_link($candidate)) {
                break;
            }
            if (self::identical(path: $candidate, size: $size, sha256: $sha256)) {
                return [$candidate, true];
            }
        }
        $part = $directory.'/.'.bin2hex(random_bytes(8)).'.mothership-part';
        $in = $open();
        $out = @fopen($part, 'xb');
        if ($out === false) {
            fclose($in);

            throw new LibraryFailure('Cannot write to dest_dir. Pass a folder this server can write to.');
        }
        $hash = hash_init('sha256');
        $written = 0;
        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw new LibraryFailure('Reading the stored file failed part way. Nothing was written; try again.');
                }
                hash_update($hash, $chunk);
                $written += strlen($chunk);
                if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new LibraryFailure('Writing the copy failed part way (is the disk full?). Nothing was written.');
                }
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($part);

            throw $e;
        } finally {
            fclose($in);
        }
        fclose($out);
        if ($written !== $size || ! hash_equals($sha256, hash_final($hash))) {
            @unlink($part);
            MothershipLog::decision(event: 'fetch', loc: 'LibraryOperations::copyOut', ctx: ['outcome' => 'refused', 'reason' => 'sha256_mismatch'], level: 'error');

            throw new LibraryFailure('The stored bytes did not match their recorded sha256, so nothing was written. Report this asset to the operator.');
        }
        // The name is reserved with an exclusive create, which fails if anything already holds it, and only then
        // replaced by the verified copy: two fetches choosing the same name never overwrite each other's copy.
        $target = $directory.'/'.$name;
        for ($try = 2; ; $try++) {
            if (self::identical(path: $target, size: $size, sha256: $sha256)) {
                @unlink($part);

                return [$target, true];
            }
            $reserved = @fopen($target, 'xb');
            if ($reserved !== false) {
                fclose($reserved);
                if (! rename($part, $target)) {
                    @unlink($part);
                    @unlink($target);

                    throw new LibraryFailure('Cannot write to dest_dir. Pass a folder this server can write to.');
                }

                return [$target, false];
            }
            if ((! file_exists($target) && ! is_link($target)) || $try > self::MAX_NAME_TRIES) {
                @unlink($part);

                throw new LibraryFailure(file_exists($target) || is_link($target)
                    ? 'dest_dir already holds too many files named like '.$name.'. Pass another dest_dir.'
                    : 'Cannot write to dest_dir. Pass a folder this server can write to.');
            }
            $target = $directory.'/'.$base.'-'.$try.$extension;
        }
    }

    /**
     * Queues a conversion to SVG and PDF through the vectorizer.ai integration: filed onto asset_id in the chosen
     * format, or a one-off of local_path kept as this account's own. The same jobs, limits and replay as the Library.
     */
    private function vectorize(Admin $actor, array $arguments): array
    {
        $agent = app(AgentVectorizations::class);
        if (isset($arguments['asset_id'])) {
            $row = $agent->fromAsset(actor: $actor, via: AccessContext::VIA_LIBRARY, assetId: (int) $arguments['asset_id'], format: (string) $arguments['format'], requestId: (string) $arguments['request_id']);
        } else {
            [$source] = $this->localSource(path: (string) $arguments['local_path']);
            $row = $agent->fromFile(actor: $actor, path: $source, requestId: (string) $arguments['request_id']);
        }
        MothershipLog::decision(event: 'vectorize', loc: 'LibraryOperations::vectorize', ctx: ['outcome' => (string) $row->status, 'kind' => isset($arguments['asset_id']) ? 'asset' : 'one_off']);

        return $agent->present(row: $row) + ['note' => 'Poll library_vectorization with this id until status is completed or failed.'];
    }

    /** A conversion's status and, given format, a verified local copy of its completed result. */
    private function vectorization(Admin $actor, array $arguments): array
    {
        $agent = app(AgentVectorizations::class);
        $row = $agent->find(actor: $actor, via: AccessContext::VIA_LIBRARY, id: (string) $arguments['id']);
        $result = $agent->present(row: $row);
        if (! isset($arguments['format'])) {
            return $result;
        }
        if ($row->status !== 'completed') {
            throw new LibraryFailure('This conversion is '.$row->status.', so there is no result to copy'.($row->status === 'failed' ? '.' : ' yet. Poll again shortly.'));
        }
        $format = (string) $arguments['format'];
        $source = $agent->resultPath(row: $row, format: $format);
        $base = 'vectorized-'.substr((string) $row->id, 0, 8);
        if ($row->source_asset_id !== null) {
            $base = pathinfo((string) Asset::withTrashed()->whereKey($row->source_asset_id)->value('file_name'), PATHINFO_FILENAME).'-vectorized';
        }
        $sha256 = (string) hash_file('sha256', $source);
        $size = (int) filesize($source);
        $directory = $this->fetchDirectory(requested: $arguments['dest_dir'] ?? null);
        [$path, $reused] = $this->copyOut(open: function () use ($source) {
            $stream = @fopen($source, 'rb');
            if ($stream === false) {
                throw new LibraryFailure('The conversion result cannot be read right now. Try again later.');
            }

            return $stream;
        }, sha256: $sha256, size: $size, directory: $directory, name: CreativeHubSaver::cleanName(name: $base.'.'.$format));

        return $result + ['path' => $path, 'name' => basename($path), 'sha256' => $sha256, 'size' => $size, 'reused' => $reused];
    }

    private static function identical(string $path, int $size, string $sha256): bool
    {
        return is_file($path) && ! is_link($path) && filesize($path) === $size && hash_equals($sha256, (string) hash_file('sha256', $path));
    }

    /**
     * Publishes a local file: with folder_id as a new asset, with stack_asset_id as a new member of that asset's
     * stack (cover unchanged). The file is staged as a private copy first, so a tool still writing the original
     * cannot change what is published. request_id makes a retry return the first result instead of a second asset.
     */
    private function save(Admin $actor, array $arguments): array
    {
        [$source, $size] = $this->localSource(path: (string) $arguments['local_path']);
        $producer = $arguments['producer'];
        $app = ['id' => $producer['tool'].(isset($producer['app']) ? ':'.$producer['app'] : ''), 'version' => (string) ($producer['version'] ?? '')];
        $record = array_filter([
            'producer'         => $producer['tool'],
            'producer_app'     => $producer['app'] ?? null,
            'producer_version' => $producer['version'] ?? null,
            'provenance'       => $arguments['provenance'] ?? null,
            'notes'            => $arguments['notes'] ?? null,
            'evidence'         => $arguments['evidence'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        if (($arguments['tags'] ?? []) !== []) {
            abort_unless($this->can(actor: $actor, permission: 'dam.asset.tag'), 403);
        }
        $references = $this->references(actor: $actor, ids: $arguments['provenance']['reference_asset_ids'] ?? [], stackAssetId: isset($arguments['stack_asset_id']) ? (int) $arguments['stack_asset_id'] : null);
        if (isset($arguments['stack_asset_id'])) {
            // The Library's add-files rule, asked again by CreativeHubSaver::addToStack on the locked row.
            abort_unless($this->can(actor: $actor, permission: 'dam.asset.files.store') && $this->can(actor: $actor, permission: 'dam.asset.edit'), 403);
            $stack = $this->access->for(context: AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_EDIT), asset: (int) $arguments['stack_asset_id'])->allowedOrFail();
            abort_if($stack->isMerged(), 409);
            abort_if(($arguments['title'] ?? '') !== '', 422, 'title names a new asset. To rename the stack asset, use library_update.');

            return $this->withStagedFile(source: $source, size: $size, name: $arguments['name'] ?? null, use: fn (UploadedFile $file, string $name, string $sha256) => $this->saveStackFile(actor: $actor, stack: $stack, arguments: $arguments, file: $file, name: $name, sha256: $sha256, size: $size, app: $app, record: $record, references: $references));
        }
        abort_unless($this->can(actor: $actor, permission: 'dam.asset.upload'), 403);
        $folder = Directory::query()->findOrFail((int) $arguments['folder_id']);
        abort_unless($this->directories->forAdmin(admin: $actor)->canAccess(directoryId: (int) $folder->id), 404);

        return $this->withStagedFile(source: $source, size: $size, name: $arguments['name'] ?? null, use: fn (UploadedFile $file, string $name, string $sha256) => $this->saveNewFile(actor: $actor, arguments: $arguments, file: $file, name: $name, sha256: $sha256, size: $size, app: $app, record: $record, references: $references));
    }

    /**
     * The assets a save names in provenance.reference_asset_ids, checked before anything is saved: each must be one
     * the account's Library lists (a missing, hidden, trashed or merged asset fails alike, so an id probe learns
     * nothing), never the stack the file joins, and the account must be allowed to add relationships.
     *
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    private function references(Admin $actor, array $ids, ?int $stackAssetId): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        if ($ids === []) {
            return [];
        }
        abort_unless($this->can(actor: $actor, permission: 'dam.asset.relationships.store'), 403);
        $context = AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_LIST);
        foreach ($ids as $id) {
            if ($id === $stackAssetId) {
                throw new LibraryFailure('reference_asset_ids names asset '.$id.', the asset this file joins; an asset cannot be derived from itself. Nothing was saved.');
            }
            if (! $this->access->for(context: $context, asset: $id)->allowed) {
                throw new LibraryFailure('Reference asset '.$id.' is not in the library or is not open to this account. Nothing was saved; check reference_asset_ids.');
            }
        }

        return $ids;
    }

    /**
     * Writes a derived-from relationship (ADR 0002) from $assetId to each reference that lacks one, so the made work
     * links back to what it was made from. Runs inside the caller's transaction: the links land with the save's record
     * or not at all, and a save of the same file again finds them and adds none.
     *
     * @param  list<int>  $references
     * @return list<int> the references $assetId is now derived from
     */
    private function linkReferences(Admin $actor, int $assetId, array $references, string $producer): array
    {
        if ($references === []) {
            return [];
        }
        // The Relationships tab's own lock, so a concurrent link of the same pair waits and then finds this one.
        Asset::query()->whereKey($assetId)->lockedForGraph()->value('id');
        $existing = fn (): array => AssetRelationship::query()->where('from_asset_id', $assetId)->where('type', AssetRelationship::TYPE_DERIVED_FROM)
            ->whereIn('to_asset_id', $references)->pluck('to_asset_id')->map(fn (mixed $id): int => (int) $id)->all();
        $linked = $existing();
        foreach (array_diff($references, $linked) as $reference) {
            try {
                // A savepoint: a concurrent insert's unique violation must not poison the save's transaction.
                DB::transaction(fn () => AssetRelationship::query()->create([
                    'from_asset_id' => $assetId, 'to_asset_id' => $reference, 'type' => AssetRelationship::TYPE_DERIVED_FROM,
                    'metadata'      => ['source' => 'library_save', 'producer' => $producer], 'created_by' => (int) $actor->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Already there: the link is what the save asked for.
            }
        }
        $linked = $existing();
        MothershipLog::decision(event: 'save_links', loc: 'LibraryOperations::linkReferences', ctx: ['asset_id' => $assetId, 'requested' => count($references), 'linked' => count($linked)]);
        sort($linked);

        return $linked;
    }

    /** @return array{0: string, 1: int} the file's real path and size */
    private function localSource(string $path): array
    {
        if (! str_starts_with($path, '/')) {
            throw new LibraryFailure('local_path must be an absolute path on this machine.');
        }
        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            throw new LibraryFailure('There is no file at local_path.');
        }
        if (! is_readable($real)) {
            throw new LibraryFailure('The server cannot read the file at local_path.');
        }
        $size = (int) filesize($real);
        if ($size === 0) {
            throw new LibraryFailure('The file at local_path is empty, so nothing was saved.');
        }
        $limit = (int) config('mothership-mcp.max_file_bytes');
        if ($size > $limit) {
            throw new LibraryFailure(sprintf('The file is %s; the library limit is %s (MOTHERSHIP_MCP_MAX_FILE_BYTES), so nothing was saved.', self::megabytes(bytes: $size), self::megabytes(bytes: $limit)));
        }

        return [$real, $size];
    }

    /**
     * Copies $source to a private 0600 staging file, checks it is what its name says, and hands it to $use; the
     * staged copy is always removed.
     *
     * @param  callable(UploadedFile, string, string): array  $use  (file, name, sha256)
     */
    private function withStagedFile(string $source, int $size, ?string $name, callable $use): array
    {
        $name = CreativeHubSaver::cleanName(name: $name ?? basename($source));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new LibraryFailure('The file name has no extension, so its type is unknown. Pass name with the right extension, such as render.png.');
        }
        $staged = tempnam(sys_get_temp_dir(), 'mothership-save-');
        if ($staged === false) {
            throw new LibraryFailure('Cannot stage the file for saving.');
        }
        try {
            chmod($staged, 0600);
            if (! @copy($source, $staged)) {
                throw new LibraryFailure('Cannot read the file at local_path.');
            }
            clearstatcache(true, $staged);
            if (filesize($staged) !== $size) {
                throw new LibraryFailure('The file at local_path changed while it was being read. Save it again once it is finished.');
            }
            $sha256 = (string) hash_file('sha256', $staged);
            $file = new UploadedFile(path: $staged, originalName: $name, test: true);
            if (! CreativeHubSaver::contentMatches(detectedMime: (string) $file->getMimeType(), extension: $extension, path: $staged)) {
                throw new LibraryFailure('The bytes of '.$name.' are not a .'.$extension.' file (they read as '.$file->getMimeType().'), so nothing was saved. Save it under the extension of what it is.');
            }
            $unplayable = self::unplayable(extension: $extension, path: $staged, length: $size);
            if ($unplayable !== null) {
                throw new LibraryFailure($name.' cannot be played: '.$unplayable.' Nothing was saved. Render it again.');
            }

            return $use($file, $name, $sha256);
        } finally {
            if (is_file($staged)) {
                unlink($staged);
            }
        }
    }

    /** @param  list<int>  $references */
    private function saveNewFile(Admin $actor, array $arguments, UploadedFile $file, string $name, string $sha256, int $size, array $app, array $record, array $references): array
    {
        $requestId = strtolower((string) $arguments['request_id']);
        $replayed = DB::table('dam_creative_hub_saves')->where('admin_id', (int) $actor->id)->where('request_id', $requestId)->exists();
        $saved = $this->accessModel->sharedly(function () use ($actor, $arguments, $file, $name, $app, $requestId): array {
            $folder = Directory::query()->findOrFail((int) $arguments['folder_id']);
            abort_unless($this->directories->forAdmin(admin: $actor)->canAccess(directoryId: (int) $folder->id), 404);

            return $this->saver->saveNew(file: $file, name: $name, folder: $folder, app: $app, source: null, openedSha256: null, requestId: $requestId, actorId: (int) $actor->id);
        });
        $asset = $saved['asset'];
        if ($replayed) {
            // A retry answers only while the account can still see the asset (it may have moved since).
            $asset = $this->access->for(context: AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_LIST), asset: (int) $asset->id)->allowedOrFail();
        }
        // The library record (producer, provenance, notes) rides on the request's own claim row. It is written in one
        // transaction with the title, tags and derived-from links: a failure leaves them all for the retry, a
        // concurrent retry waits on the row and then finds it done, and a finished save is never redone, so later
        // edits stay.
        [$tags, $derivedFrom] = DB::transaction(function () use ($actor, $arguments, $asset, $requestId, $record, $references): array {
            $finalizing = DB::table('dam_creative_hub_saves')->where('admin_id', (int) $actor->id)->where('request_id', $requestId)->whereNull('evidence')
                ->update(['evidence' => json_encode($record, JSON_THROW_ON_ERROR), 'updated_at' => now()]) === 1;
            if ($finalizing && ($arguments['title'] ?? '') !== '' && $asset->title !== $arguments['title']) {
                $asset->forceFill(['title' => $arguments['title']])->save();
            }

            return [
                $this->addTags(asset: $asset, names: $finalizing ? ($arguments['tags'] ?? []) : [], actor: $actor),
                $finalizing ? $this->linkReferences(actor: $actor, assetId: (int) $asset->id, references: $references, producer: (string) $record['producer']) : $this->derivedFrom(assetId: (int) $asset->id, references: $references),
            ];
        });
        // Publication may normalize SVG bytes: sha256 is what is stored, source_sha256 what was read.
        $object = $this->content->current(asset: $asset);
        MothershipLog::decision(event: 'save', loc: 'LibraryOperations::saveNewFile', ctx: ['mode' => 'new', 'outcome' => 'published', 'size' => (int) $object->size, 'replayed' => $replayed]);
        $renamed = $asset->file_name !== $name ? ['renamed_from' => $name, 'note' => 'The folder already had a file named '.$name.', so this one is '.$asset->file_name.'; nothing was replaced.'] : [];

        return [
            'asset_id'      => (int) $asset->id,
            'name'          => $asset->file_name,
            ...$renamed,
            'title'         => $asset->title,
            'folder_id'     => (int) $arguments['folder_id'],
            'sha256'        => (string) $object->sha256,
            'size'          => (int) $object->size,
            'source_sha256' => $sha256,
            'source_size'   => $size,
            'tags'          => $tags,
            'derived_from'  => $derivedFrom,
            'replayed'      => $replayed,
            'url'           => route('admin.dam.assets.edit', $asset->id),
            'next'          => 'To keep the editable source file and other exports with this asset, save each with library_save and stack_asset_id='.(int) $asset->id.'.',
        ];
    }

    /**
     * Stack mode: the file joins an existing asset as a new member through CreativeHubSaver::addToStack. The request
     * is claimed first in dam_creative_hub_saves, bound to (stack, name, sha256, producer) under the account's
     * request_id; changed bytes or destination under the same id answer 409. The claim records the member and the
     * receipt inside the attach's own transaction, so a retry answers that receipt without attaching again.
     */
    /** @param  list<int>  $references */
    private function saveStackFile(Admin $actor, Asset $stack, array $arguments, UploadedFile $file, string $name, string $sha256, int $size, array $app, array $record, array $references): array
    {
        $requestId = strtolower((string) $arguments['request_id']);
        $requestSha256 = hash('sha256', json_encode(['library-stack', $app['id'], (int) $stack->id, $name, $sha256], JSON_THROW_ON_ERROR));
        $marker = fn () => DB::table('dam_creative_hub_saves')->where('admin_id', (int) $actor->id)->where('request_id', $requestId)->first();
        $saved = $marker();
        if ($saved === null) {
            try {
                // Its own (save)point: a concurrent claim's unique violation must not poison an enclosing transaction.
                DB::transaction(fn () => DB::table('dam_creative_hub_saves')->insert([
                    'admin_id'   => (int) $actor->id, 'request_id' => $requestId, 'request_sha256' => $requestSha256,
                    'asset_id'   => (int) $stack->id, 'created_at' => now(), 'updated_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                $saved = $marker();
            }
        }
        abort_if($saved !== null && ! hash_equals((string) $saved->request_sha256, $requestSha256), 409, 'This request_id was already used for a different file or destination. Use a new request_id for a new save.');
        if ($saved !== null && $saved->asset_file_id !== null) {
            $recorded = json_decode((string) $saved->receipt, true);
            if ($this->members->findFile(asset: $stack, fileId: (int) $saved->asset_file_id) === null || ! is_array($recorded)) {
                MothershipLog::decision(event: 'save', loc: 'LibraryOperations::saveStackFile', ctx: ['mode' => 'stack', 'outcome' => 'refused', 'reason' => 'recorded_member_gone'], level: 'warning');
                abort(409, 'This request_id already added file '.(int) $saved->asset_file_id.' to this stack, but that file has since been replaced or removed. Nothing was added; use a new request_id to add it again.');
            }
            MothershipLog::decision(event: 'save', loc: 'LibraryOperations::saveStackFile', ctx: ['mode' => 'stack', 'outcome' => 'attached', 'replayed' => true]);

            return ['replayed' => true] + $recorded;
        }
        $label = $this->stackLabel(stack: $stack, name: $name, sha256: $sha256);
        // Bytes the library already held were brought in (footage, a logo), not made by the producer.
        $imported = FileObject::query()->where('sha256', $sha256)->exists();
        $receipt = null;
        $this->saver->addToStack(file: $file, stack: $stack, name: $label, app: $app, sourceFile: null, actorId: (int) $actor->id, onAttached: function (AssetFile $added) use ($actor, $arguments, $stack, $app, $requestId, $sha256, $size, $label, $name, $imported, $record, $references, &$receipt): void {
            $level = DB::transactionLevel();
            try {
                // Names the producer for library_list_stack; best effort, in its own savepoint so an ordinary failure
                // cannot poison the attach transaction. A file the library already held gets no producer.
                if (! $imported) {
                    DB::transaction(fn () => app(Lineage::class)->record(assetId: (int) $stack->id, recipe: Rendition::RECIPE_CREATIVE_HUB, source: null, worker: 'creative-hub:'.$app['id'], outputFileId: (int) $added->id, parameters: ['app' => $app['id'], 'app_version' => $app['version'], 'mode' => 'library-stack']));
                }
            } catch (\Throwable $e) {
                // A deadlock or lost connection may have rolled back the whole attach; recording a result after that
                // would autocommit a receipt for a member that no longer exists, so those failures abort the save.
                if ($e instanceof DeadlockException || DB::transactionLevel() !== $level || app(LostConnectionDetector::class)->causedByLostConnection($e)) {
                    throw $e;
                }
                report($e);
            }
            // Tags describe the stack asset; applied inside the attach, so they land with the member or not at all.
            $tags = $this->addTags(asset: $stack, names: $arguments['tags'] ?? [], actor: $actor);
            // Links, like tags, describe the stack asset, and land with the member or not at all.
            $derivedFrom = $this->linkReferences(actor: $actor, assetId: (int) $stack->id, references: $references, producer: (string) $record['producer']);
            $object = $added->fileObject;
            $receipt = [
                'asset_id'      => (int) $stack->id, 'file_id' => (int) $added->id, 'name' => StackFileName::fileName(file: $added, asset: $stack),
                'sha256'        => (string) $object->sha256, 'size' => (int) $object->size, 'source_sha256' => $sha256, 'source_size' => $size,
                'stack'         => true, 'cover_changed' => false, 'tags' => $tags, 'derived_from' => $derivedFrom, 'url' => route('admin.dam.assets.edit', $stack->id),
                ...($label !== $name ? ['renamed_from' => $name] : []),
                'note'          => 'Added to the stack; cover unchanged. Changing the cover is a Library action in The Mothership.'.($label !== $name ? ' The stack already had a different file named '.$name.', so this one is '.$label.'.' : ''),
            ];
            $recordedRows = DB::table('dam_creative_hub_saves')->where('admin_id', (int) $actor->id)->where('request_id', $requestId)->whereNull('asset_file_id')
                ->update(['asset_file_id' => (int) $added->id, 'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'evidence' => json_encode($record, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            if ($recordedRows !== 1) {
                throw new LibraryFailure('The request could not be recorded, so nothing was added to the stack. Try again with the same request_id.');
            }
        });
        if (! is_array($receipt)) {
            throw new LibraryFailure('The stack save did not report its result.');
        }
        MothershipLog::decision(event: 'save', loc: 'LibraryOperations::saveStackFile', ctx: ['mode' => 'stack', 'outcome' => 'attached', 'size' => (int) $receipt['size'], 'replayed' => false]);

        return ['replayed' => false] + $receipt;
    }

    /** The member label: the file's own name, or name-vN.ext when another member already uses that name for other bytes. */
    private function stackLabel(Asset $stack, string $name, string $sha256): string
    {
        $members = $this->members->forAsset(asset: $stack);
        if ($members->contains(fn (AssetFile $member): bool => hash_equals((string) $member->fileObject?->sha256, $sha256))) {
            return $name;
        }
        $taken = $members->map(fn (AssetFile $member): string => mb_strtolower(StackFileName::fileName(file: $member, asset: $stack)))->all();
        if (! in_array(mb_strtolower($name), $taken, true)) {
            return $name;
        }
        $dot = strrpos($name, '.');
        $base = $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
        $extension = $dot === false || $dot === 0 ? '' : substr($name, $dot);
        $revision = 2;
        if (preg_match('/^(.*)-v(\d{1,4})$/', $base, $match) === 1) {
            [$base, $revision] = [$match[1], (int) $match[2] + 1];
        }
        for (; $revision < 10000; $revision++) {
            $candidate = $base.'-v'.$revision.$extension;
            if (! in_array(mb_strtolower($candidate), $taken, true)) {
                return $candidate;
            }
        }

        return $name;
    }

    private function update(Admin $actor, array $arguments): array
    {
        $title = $arguments['title'] ?? null;
        $add = $arguments['tags_add'] ?? [];
        $remove = $arguments['tags_remove'] ?? [];
        if ($title === null && $add === [] && $remove === []) {
            throw new LibraryFailure('Nothing to change: pass title, tags_add or tags_remove.');
        }
        abort_unless($title === null || $this->can(actor: $actor, permission: 'dam.asset.update'), 403);
        abort_unless(($add === [] && $remove === []) || $this->can(actor: $actor, permission: 'dam.asset.tag'), 403);
        $asset = $this->access->for(context: AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_EDIT), asset: (int) $arguments['asset_id'])->allowedOrFail();
        abort_if($asset->isMerged(), 409);
        if ($title !== null && $asset->title !== $title) {
            $asset->forceFill(['title' => $title])->save();
        }
        $this->addTags(asset: $asset, names: $add, actor: $actor);
        foreach ($remove as $name) {
            $tag = Tag::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(TagNameRule::normalize((string) $name))])->first();
            if ($tag instanceof Tag) {
                // Recorded as a removal, so no re-index, AI accept, rule or bulk add puts it back (P4 D8).
                $this->tags->remove(asset: $asset, tag: $tag, actorId: (int) $actor->id);
            }
        }
        MothershipLog::decision(event: 'update', loc: 'LibraryOperations::update', ctx: ['outcome' => 'updated', 'count' => count($add) + count($remove)]);

        return [
            'asset_id' => (int) $asset->id,
            'title'    => $asset->title,
            'tags'     => $asset->tags()->orderBy('dam_tags.name')->pluck('dam_tags.name')->values()->all(),
        ];
    }

    /**
     * Adds tags as one person tagging by hand (clearing that person's earlier removal of them).
     *
     * @param  list<string>  $names
     * @return list<string> the asset's tags afterwards
     */
    private function addTags(Asset $asset, array $names, Admin $actor): array
    {
        if ($names !== []) {
            foreach ($this->tags->resolve(names: $names, attribute: 'tags') as $tag) {
                $this->tags->addManual(asset: $asset, tag: $tag, actorId: (int) $actor->id);
            }
        }

        return $asset->tags()->orderBy('dam_tags.name')->pluck('dam_tags.name')->values()->all();
    }

    /**
     * Producer per asset, from the library record of the save that created it.
     *
     * @param  list<int>  $assetIds
     * @return array<int, string>
     */
    private function producers(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }
        $producers = [];
        DB::table('dam_creative_hub_saves')->whereIn('asset_id', $assetIds)->whereNull('asset_file_id')->whereNotNull('evidence')->orderBy('id')
            ->get(['asset_id', 'evidence'])->each(function (object $row) use (&$producers): void {
                $record = json_decode((string) $row->evidence, true);
                if (is_array($record) && is_string($record['producer'] ?? null)) {
                    $producers[(int) $row->asset_id] ??= $record['producer'];
                }
            });

        return $producers;
    }

    /**
     * For a replayed save's answer: which of the references this save names (already checked by references()) the
     * asset has a derived-from link to. Never the asset's other links, whose other end this account may not see.
     *
     * @param  list<int>  $references
     * @return list<int>
     */
    private function derivedFrom(int $assetId, array $references): array
    {
        if ($references === []) {
            return [];
        }

        return AssetRelationship::query()->where('from_asset_id', $assetId)->where('type', AssetRelationship::TYPE_DERIVED_FROM)
            ->whereIn('to_asset_id', $references)->orderBy('to_asset_id')->pluck('to_asset_id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    /** The library record (producer, provenance, notes) of the save that created this asset, or [] for any other asset. */
    private function libraryRecord(int $assetId): array
    {
        $rows = DB::table('dam_creative_hub_saves')->where('asset_id', $assetId)->whereNull('asset_file_id')->whereNotNull('evidence')->orderBy('id')->pluck('evidence');
        foreach ($rows as $evidence) {
            $record = json_decode((string) $evidence, true);
            if (is_array($record) && is_string($record['producer'] ?? null)) {
                return $record;
            }
        }

        return [];
    }

    private static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }

    /**
     * Stored pixel size of an image or video file, as StoredImageDimensions measured it at ingest; [null, null] when
     * it was never measured. Nothing is decoded here.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function pixels(FileObject $object): array
    {
        $technical = is_array($object->technical) ? $object->technical : [];
        $width = $technical['width'] ?? null;
        $height = $technical['height'] ?? null;

        return is_int($width) && is_int($height) && $width > 0 && $height > 0 ? [$width, $height] : [null, null];
    }

    /**
     * Why an MP4 or MOV cannot play, or null: its top-level boxes must cover the whole file and include `moov`
     * (the index a render writes last; a render killed part way leaves `mdat` without it). Other types are not
     * checked here.
     */
    private static function unplayable(string $extension, string $path, int $length): ?string
    {
        if (! in_array($extension, ['mp4', 'mov', 'm4v'], true)) {
            return null;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new LibraryFailure('Cannot stage the file for saving.');
        }
        try {
            $offset = 0;
            $moov = false;
            while ($offset + 8 <= $length) {
                fseek($handle, $offset);
                $header = (string) fread($handle, 16);
                $size = unpack('N', substr($header, 0, 4))[1];
                $type = substr($header, 4, 4);
                $headerLength = 8;
                if ($size === 1) {
                    $headerLength = 16;
                    $size = $offset + 16 <= $length ? unpack('J', substr($header, 8, 8))[1] : 0;
                } elseif ($size === 0) {
                    $size = $length - $offset;
                }
                if ($size < $headerLength || $offset + $size > $length) {
                    return 'its '.preg_replace('/[^\x20-\x7E]/', '?', $type).' box runs past the end of the file (it is cut short).';
                }
                $moov = $moov || $type === 'moov';
                $offset += $size;
            }
        } finally {
            fclose($handle);
        }
        if ($offset !== $length) {
            return 'it ends with '.($length - $offset).' bytes that are not a whole box (it is cut short).';
        }

        return $moov ? null : 'it has no moov box, the index a finished render writes last.';
    }

    private function can(Admin $actor, string $permission): bool
    {
        return $actor->role?->permission_type === 'all' || $actor->hasPermission($permission);
    }
}
