<?php

/**
 * Shared Pest helper functions used by more than one DAM Feature spec file.
 *
 * Pest only `require`s the test file(s) named on its command line — a plain
 * top-level `function` declared inside one spec file is NOT visible to a
 * sibling spec file when that sibling is run in isolation (e.g. via a
 * single-file `verify.py` invocation). Functions shared across spec files
 * must live here, where tests/Pest.php require()s them unconditionally for
 * every run, instead of inside any one *Test.php file.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\ImageEditorDocument;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\DirectoryTreeLock;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

if (! function_exists('tickEveryHomeTool')) {
    /**
     * Paid Home tools (StylePress, SphereSeam, Image Vectorization) are opt-in for a custom role, so a fixture role
     * that exercises one gets an explicit stored list holding every tool, as an admin's ticks would.
     */
    function tickEveryHomeTool(Role $role): Role
    {
        DB::table('dam_role_settings')->updateOrInsert(
            ['role_id' => $role->id],
            ['home_widget_ids' => json_encode(array_keys(config('dam-home.widgets'))), 'created_at' => now(), 'updated_at' => now()]
        );

        return $role;
    }
}

if (! function_exists('actAsPlaybackRestrictedAdmin')) {
    function actAsPlaybackRestrictedAdmin(Directory $grantedDirectory, array $permissions): Admin
    {
        $role = Role::factory()->create([
            'permission_type' => 'custom',
            'permissions'     => $permissions,
        ]);

        DB::table('dam_directory_role')->insert([
            'directory_id' => $grantedDirectory->id,
            'role_id'      => $role->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $admin = Admin::factory()->create(['role_id' => $role->id]);

        test()->actingAs($admin, 'admin');
        app(DirectoryPermissionService::class)->flush();

        return $admin;
    }
}

if (! function_exists('storeFixtureBlob')) {
    /** Writes fixture bytes to the content-addressed blob of `$sha256` (ADR 0009) and returns its locator. */
    function storeFixtureBlob(string $sha256, string $bytes, ?string $extension = null, ?string $disk = null): string
    {
        $path = app(ContentStore::class)->objectPath($sha256, $extension);
        Storage::disk($disk ?? Directory::getAssetDisk())->put($path, $bytes);

        return $path;
    }
}

if (! function_exists('minimalDngWithEmbeddedJpeg')) {
    /** A minimal TIFF/DNG with an embedded JPEG large enough for DngPreviewService to accept. */
    function minimalDngWithEmbeddedJpeg(): string
    {
        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 180, 90, 30));
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $tags = [
            [256, 4, 1, pack('V', 800)], [257, 4, 1, pack('V', 600)],
            [259, 3, 1, pack('v2', 6, 0)], [274, 3, 1, pack('v2', 6, 0)],
            [513, 4, 1, pack('V', 8 + 2 + 7 * 12 + 4)],
            [514, 4, 1, pack('V', strlen($jpeg))], [50706, 1, 4, "\x01\x04\x00\x00"],
        ];
        $dng = 'II'.pack('vVv', 42, 8, count($tags));
        foreach ($tags as [$tag, $type, $count, $value]) {
            $dng .= pack('vvV', $tag, $type, $count).$value;
        }

        return $dng.pack('V', 0).$jpeg;
    }
}

if (! function_exists('fakePngBytes')) {
    /** A small real PNG's bytes. The fake file is held while it is read: its temp file goes when the object does. */
    function fakePngBytes(int $width, int $height): string
    {
        $file = UploadedFile::fake()->image('fixture.png', $width, $height);

        return (string) file_get_contents($file->getRealPath());
    }
}

if (! function_exists('attachPreviewFile')) {
    /**
     * `$path` names the file the fixture stands for; its bytes land in a content-addressed blob (ADR 0009)
     * that keeps the name's extension as a suffix.
     */
    function attachPreviewFile(
        Asset $asset,
        string $path,
        string $mime,
        string $role,
        ?string $label = null,
        int $sort = 0,
        ?array $technical = null,
        ?string $disk = null,
    ): AssetFile {
        $disk ??= Directory::getAssetDisk();
        $sha256 = hash('sha256', $asset->id.'|'.$role.'|'.$path);
        $objectPath = app(ContentStore::class)->objectPath($sha256, pathinfo($path, PATHINFO_EXTENSION) ?: null);
        Storage::disk($disk)->put($objectPath, 'preview-fixture');

        $object = FileObject::factory()->create([
            'sha256'    => $sha256,
            // A read checks the blob holds exactly the recorded size (ContentStore::holdsBlob()).
            'size'      => strlen('preview-fixture'),
            'disk'      => $disk,
            'path'      => $objectPath,
            'mime_type' => $mime,
            'technical' => $technical,
        ]);

        return AssetFile::factory()->create([
            'dam_asset_id'   => $asset->id,
            'file_object_id' => $object->id,
            'role'           => $role,
            'label'          => $label,
            'sort'           => $sort,
        ]);
    }
}

if (! function_exists('giveDeletionArtifacts')) {
    /**
     * Every per-asset artifact AssetDeletionService removes besides the asset's blobs: audio cover art, a
     * browser-captured model thumbnail, a custom thumbnail, a layered-editor document with its resource file,
     * and a share link. Returns the files written on the asset disk; assertDeletionArtifactsGone() checks them.
     *
     * @return list<string>
     */
    function giveDeletionArtifacts(Asset $asset): array
    {
        $storage = Storage::disk(Directory::getAssetDisk());
        $id = $asset->id;
        $resourceId = (string) Str::uuid();
        $files = [
            'cover'   => "covers/{$id}.jpg",
            'capture' => ModelThumbnailService::DIRECTORY.$id.'-'.substr(hash('sha256', "capture-{$id}"), 0, 12).'.jpg',
            'custom'  => "custom-thumbnails/{$id}/".Str::uuid().'.png',
            'editor'  => "assets/.editor/{$id}/{$resourceId}",
        ];

        foreach ($files as $path) {
            $storage->put($path, "artifact-{$id}");
        }

        $asset->meta_data = array_merge($asset->meta_data ?? [], [
            'cover_art_path'   => $files['cover'],
            'thumbnail_path'   => $files['capture'],
            'custom_thumbnail' => ['path' => $files['custom'], 'mime_type' => 'image/png', 'revision' => (string) Str::uuid()],
        ]);
        $asset->saveQuietly();

        ImageEditorResource::create([
            'id'       => $resourceId,
            'asset_id' => $id,
            'admin_id' => 1,
            'kind'     => 'base',
            'status'   => 'ready',
            'path'     => $files['editor'],
            'width'    => 1,
            'height'   => 1,
        ]);
        ImageEditorDocument::create([
            'asset_id'      => $id,
            'revision'      => (string) Str::uuid(),
            'content_path'  => $asset->path,
            'original_base' => $resourceId,
            'document'      => ['base' => $resourceId],
        ]);
        Share::factory()->forAsset($id)->create();

        return array_values($files);
    }
}

if (! function_exists('assertDeletionArtifactsGone')) {
    /** @param  list<string>  $files  what giveDeletionArtifacts() wrote for asset `$assetId` */
    function assertDeletionArtifactsGone(int $assetId, array $files): void
    {
        $storage = Storage::disk(Directory::getAssetDisk());

        foreach ($files as $path) {
            $storage->assertMissing($path);
        }

        // The per-asset directories go once they are empty.
        expect($storage->directoryExists("assets/.editor/{$assetId}"))->toBeFalse()
            ->and($storage->directoryExists("custom-thumbnails/{$assetId}"))->toBeFalse()
            ->and(ImageEditorResource::query()->where('asset_id', $assetId)->count())->toBe(0)
            ->and(ImageEditorDocument::query()->where('asset_id', $assetId)->count())->toBe(0)
            ->and(Share::query()->where('share_type', Share::TYPE_ASSET)->where('target_id', $assetId)->count())->toBe(0);
    }
}

if (! function_exists('putAssetContent')) {
    /**
     * Give an existing asset its bytes the way an upload does: write them to the Library path and let the
     * graph observer ingest them as the asset's current original (ADR 0009). Every read goes through that
     * original, so a fixture whose file is written after the asset row was created needs this.
     */
    function putAssetContent(Asset $asset, string $bytes, ?string $disk = null): Asset
    {
        Storage::disk($disk ?? Directory::getAssetDisk())->put($asset->path, $bytes);
        $asset->markGraphContentChanged()->save();

        return $asset;
    }
}

if (! function_exists('registerServedAssetPath')) {
    /**
     * An asset row for `$path`. When bytes are already at that Library path they become its current
     * original, as an upload's would (ADR 0009); write the file first, or use putAssetContent() after.
     */
    function registerServedAssetPath(string $path): Asset
    {
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create([
            'file_name' => basename($path),
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'path'      => $path,
        ]));

        if (Storage::disk(Directory::getAssetDisk())->exists($path)) {
            $asset->markGraphContentChanged()->save();
        }

        return $asset;
    }
}

/**
 * Shared by PanoramaLoggerTest.php and GeneratePanoramaPreviewTest.php —
 * both read/reset the real bare-JSONL sink at
 * storage/logs/panorama-preview.jsonl (Task 3 + Task 4's PanoramaLogger).
 */
if (! function_exists('panoramaLogPath')) {
    function panoramaLogPath(): string
    {
        return storage_path('logs/panorama-preview.jsonl');
    }
}

if (! function_exists('resetPanoramaLog')) {
    function resetPanoramaLog(): void
    {
        $path = panoramaLogPath();

        if (is_file($path)) {
            unlink($path);
        }
    }
}

if (! function_exists('readPanoramaLogLines')) {
    function readPanoramaLogLines(): array
    {
        $path = panoramaLogPath();

        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', file($path)),
            fn (string $line): bool => $line !== ''
        ));
    }
}

if (! function_exists('renameDirectoryOnFirstTreeLock')) {
    /**
     * Binds a DirectoryTreeLock decorator that renames `$directoryId` to `$newName` — via a raw update
     * that bypasses Eloquent and the lock itself — the first time `Directory::withTreeLock()` is
     * entered, then delegates to the real lock for that call and every one after. Simulates a
     * destination rename committing exactly when a copy/move takes the tree lock (Codex review of PR
     * #63 round 1, findings 2 & 3): code that reads the destination and derives its path BEFORE ever
     * calling withTreeLock() has already captured the old name by the time this fires; code that reads
     * it back out INSIDE the lock sees the rename. Shared by ExplorerCopyAssetTest.php and
     * Unit/JobsTest.php.
     */
    function renameDirectoryOnFirstTreeLock(int $directoryId, string $newName): void
    {
        app()->instance(DirectoryTreeLock::class, new class($directoryId, $newName) extends DirectoryTreeLock
        {
            private bool $renamed = false;

            public function __construct(private readonly int $directoryId, private readonly string $newName)
            {
                parent::__construct(app(AccessModel::class));
            }

            public function run(callable $callback): mixed
            {
                if (! $this->renamed) {
                    $this->renamed = true;
                    DB::table('dam_directories')->where('id', $this->directoryId)->update(['name' => $this->newName]);
                }

                return parent::run($callback);
            }
        });
    }
}

if (! function_exists('replaceAssetContentOnFirstTreeLock')) {
    /**
     * Binds a DirectoryTreeLock decorator that replaces `$assetId`'s content AND format — new bytes,
     * extension, MIME type, size and path — through the same `Asset::withGraphContent()` path an
     * editor save uses (ImageEditorController::save's version mode), the first time
     * `Directory::withTreeLock()` is entered, then delegates to the real lock for that call and every
     * one after. Simulates an editor save (e.g. a JPEG-to-PNG conversion) committing exactly when a
     * copy takes the tree lock (Codex review round 3, finding 3): code that read the source's current
     * FileObject BEFORE ever calling withTreeLock() still holds the pre-edit blob and descriptors by
     * the time this fires; code that reloads the source and resolves its current FileObject from that
     * same locked read INSIDE the lock sees the edit. Sibling of renameDirectoryOnFirstTreeLock above.
     */
    function replaceAssetContentOnFirstTreeLock(int $assetId, string $newBytes, string $newExtension, string $newMime): void
    {
        app()->instance(DirectoryTreeLock::class, new class($assetId, $newBytes, $newExtension, $newMime) extends DirectoryTreeLock
        {
            private bool $replaced = false;

            public function __construct(
                private readonly int $assetId,
                private readonly string $newBytes,
                private readonly string $newExtension,
                private readonly string $newMime,
            ) {
                parent::__construct(app(AccessModel::class));
            }

            public function run(callable $callback): mixed
            {
                if (! $this->replaced) {
                    $this->replaced = true;

                    $temp = tempnam(sys_get_temp_dir(), 'dam_race_edit_');
                    file_put_contents($temp, $this->newBytes);

                    try {
                        $object = app(ContentStore::class)->putLocal(
                            disk: Directory::getAssetDisk(),
                            localPath: $temp,
                            mimeType: $this->newMime,
                            extension: $this->newExtension,
                        );
                    } finally {
                        @unlink($temp);
                    }

                    $asset = Asset::findOrFail($this->assetId);
                    $newFileName = pathinfo($asset->file_name, PATHINFO_FILENAME).'.'.$this->newExtension;
                    $newPath = dirname($asset->path).'/'.$newFileName;

                    $asset->fill([
                        'file_name' => $newFileName,
                        'extension' => $this->newExtension,
                        'mime_type' => $this->newMime,
                        'file_size' => strlen($this->newBytes),
                        'path'      => $newPath,
                    ]);
                    $asset->withGraphContent($object)->save();
                }

                return parent::run($callback);
            }
        });
    }
}

if (! function_exists('reparentDirectoryOnFirstTreeLock')) {
    /**
     * Binds a DirectoryTreeLock decorator that reparents `$directoryId` under `$newParentId` — via a
     * real `appendToNode()->save()` move that commits and releases the lock before returning — the
     * first time `Directory::withTreeLock()` is entered, then delegates to the real lock for that call
     * and every one after. Simulates a directory move (e.g. the destination reparented beneath a mass
     * copy's source) committing exactly when the job takes the tree lock (Codex review round 2, finding
     * 1): code that read a directory's tree position BEFORE ever calling withTreeLock() has already
     * captured the stale interval by the time this fires; code that reads it back out INSIDE the lock
     * sees the move. `save()` re-enters `Directory::withTreeLock()` itself, which is safe here the same
     * way it is for every other tree write (DirectoryTreeLock::run() is re-entrant per connection) —
     * `$moved` is already true by the time that inner call reaches this decorator, so it falls straight
     * through to the real lock instead of moving twice. Sibling of renameDirectoryOnFirstTreeLock above.
     */
    function reparentDirectoryOnFirstTreeLock(int $directoryId, int $newParentId): void
    {
        app()->instance(DirectoryTreeLock::class, new class($directoryId, $newParentId) extends DirectoryTreeLock
        {
            private bool $moved = false;

            public function __construct(private readonly int $directoryId, private readonly int $newParentId)
            {
                parent::__construct(app(AccessModel::class));
            }

            public function run(callable $callback): mixed
            {
                if (! $this->moved) {
                    $this->moved = true;

                    $directory = Directory::findOrFail($this->directoryId);
                    $newParent = Directory::findOrFail($this->newParentId);
                    $directory->appendToNode($newParent)->save();
                }

                return parent::run($callback);
            }
        });
    }
}

if (! function_exists('deleteDirectoryOnFirstTreeLock')) {
    /**
     * Binds a DirectoryTreeLock decorator that deletes `$directoryId` — via a raw delete that bypasses
     * Eloquent (no nested-set cleanup) and the lock itself — the first time `Directory::withTreeLock()`
     * is entered, then delegates to the real lock for that call and every one after. Simulates a
     * directory being removed committing exactly when a copy/move job takes the tree lock (Codex review
     * round 2, finding 1): code that read that directory BEFORE ever calling withTreeLock() still holds
     * the now-gone row's fields by the time this fires; code that reloads it by id INSIDE the lock finds
     * nothing. Sibling of renameDirectoryOnFirstTreeLock above — intended for a leaf directory with no
     * children of its own, since it does not shift `_lft`/`_rgt` for the rest of the tree.
     */
    function deleteDirectoryOnFirstTreeLock(int $directoryId): void
    {
        app()->instance(DirectoryTreeLock::class, new class($directoryId) extends DirectoryTreeLock
        {
            private bool $deleted = false;

            public function __construct(private readonly int $directoryId)
            {
                parent::__construct(app(AccessModel::class));
            }

            public function run(callable $callback): mixed
            {
                if (! $this->deleted) {
                    $this->deleted = true;
                    DB::table('dam_directories')->where('id', $this->directoryId)->delete();
                }

                return parent::run($callback);
            }
        });
    }
}

/**
 * The Laravel test client trims a trailing slash off the URI (MakesHttpRequests::
 * prepareUrlForRequest), which is exactly the character the web page directory and bundle-root
 * branches turn on, so a trailing-slash case is dispatched through the HTTP kernel with the URL
 * left intact. Shared by WebPageAdminRouteTest.php and WebPageShareRouteTest.php.
 */
if (! function_exists('getRawUrl')) {
    function getRawUrl(string $url): TestResponse
    {
        return TestResponse::fromBaseResponse(
            app(Kernel::class)->handle(Request::create($url, 'GET'))
        );
    }
}

if (! function_exists('p7ReviewAsset')) {
    // P7 review comments and notifications (ReviewCommentsTest, ReviewNotificationsTest).
    /** An asset in $folder whose representative slot already has one revision. */
    function p7ReviewAsset(Directory $folder, string $type = 'image', string $bytes = 'first'): Asset
    {
        $extension = ['image' => 'png', 'video' => 'mp4', 'audio' => 'mp3', 'document' => 'txt'][$type];
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create([
            'file_name' => 'review-'.uniqid().'.'.$extension, 'path' => 'assets/Review/'.uniqid().'.'.$extension,
            'file_type' => $type, 'extension' => $extension, 'mime_type' => $type.'/'.$extension, 'file_size' => 1,
        ]));
        $asset->directories()->attach($folder->id);
        putAssetContent($asset->fresh(), $bytes);

        return $asset->fresh();
    }

    /** A member with every comment right whose role reaches $folders; an editor also holds dam.asset.update. */
    function p7ReviewMember(array $folders, bool $editor = false): Admin
    {
        $role = Role::factory()->create([
            'permission_type' => 'custom',
            'permissions'     => collect(config('acl'))->pluck('key')->unique()
                ->reject(fn ($key) => ! $editor && $key === 'dam.asset.update')->values()->all(),
        ]);

        foreach ($folders as $folder) {
            DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        app(DirectoryPermissionService::class)->flush();

        return Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
    }

    function p7CurrentRevision(Asset $asset): AssetFile
    {
        return AssetFile::where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_ORIGINAL)->where('is_current', true)->firstOrFail();
    }
}

/**
 * P1b usage analytics. A desktop Chrome user agent: Symfony's test client sends "Symfony", which the analytics
 * treat as a script (a suspected bot) and never count.
 */
if (! function_exists('analyticsBrowserHeaders')) {
    function analyticsBrowserHeaders(array $extra = []): array
    {
        return ['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36'] + $extra;
    }
}

/** One collector event as the browser sends it; $overrides replace or add envelope keys. */
if (! function_exists('analyticsClientEvent')) {
    function analyticsClientEvent(string $name, array $overrides = []): array
    {
        return $overrides + [
            'id'  => (string) Str::uuid(),
            'n'   => $name,
            'ts'  => (int) now()->getTimestampMs(),
            'sid' => '0b8f1c2e-5a1d-4c3e-9f00-000000000001',
            'seq' => 1,
            'p'   => [],
        ];
    }
}

/** A file link to a real file on the asset disk. */
if (! function_exists('analyticsSharedFile')) {
    function analyticsSharedFile(): Share
    {
        Storage::fake(Directory::getAssetDisk());
        Storage::disk(Directory::getAssetDisk())->put('assets/Analytics/poster.png', 'poster-bytes');
        $asset = Asset::factory()->create(['file_name' => 'poster.png', 'path' => 'assets/Analytics/poster.png', 'file_type' => 'image', 'mime_type' => 'image/png', 'extension' => 'png']);

        return Share::factory()->forAsset($asset->id)->create();
    }
}

/**
 * What a browser does after a link page loads: its collector sends the route view carrying the visit's view token,
 * which is what counts the link's view. Returns false when the page carries no collector (DNT, GPC, analytics off).
 */
if (! function_exists('shareVisitRan')) {
    function shareVisitRan(TestResponse $page, array $headers = []): bool
    {
        if (! preg_match('#<script type="application/json" id="dam-t">(.*?)</script>#s', (string) $page->getContent(), $match)) {
            return false;
        }

        $config = json_decode(html_entity_decode($match[1]), true, flags: JSON_THROW_ON_ERROR);
        test()->withHeaders(analyticsBrowserHeaders($headers))->postJson($config['e'], [
            't' => $config['t'],
            'e' => [analyticsClientEvent('route.viewed', ['sid' => (string) Str::uuid(), 'p' => ['route' => $config['o'], 'navigation' => 'document', 'view_token' => $config['k']]])],
        ])->assertNoContent();

        return true;
    }
}

/**
 * A folder the viewer's role is granted, another it is not, a file in each, and a member holding $permissions. The
 * hidden file is what every analytics access test must never see counted or named.
 *
 * @return array{viewer: Admin, visible: Asset, hidden: Asset, granted: Directory, other: Directory}
 */
if (! function_exists('analyticsAccessFixture')) {
    function analyticsAccessFixture(array $permissions): array
    {
        $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
        $viewer = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
        $granted = Directory::create(['name' => 'Analytics granted', 'parent_id' => null]);
        $other = Directory::create(['name' => 'Analytics elsewhere', 'parent_id' => null]);
        DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $granted->id]);
        $file = function (Directory $folder, string $name): Asset {
            $asset = Asset::factory()->create(['file_name' => $name]);
            DB::table('dam_asset_directory')->where('asset_id', $asset->id)->delete();
            DB::table('dam_asset_directory')->insert(['asset_id' => $asset->id, 'directory_id' => $folder->id]);

            return $asset;
        };

        return [
            'viewer'  => $viewer,
            'visible' => $file($granted, 'visible-analytics-file.jpg'),
            'hidden'  => $file($other, 'secret-merger-plan.pdf'),
            'granted' => $granted,
            'other'   => $other,
        ];
    }
}
