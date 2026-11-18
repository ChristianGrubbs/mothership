<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\FinalizeChunkUpload;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\UploadBatch;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\Sha256File;
use Mothership\DAM\Support\UploadRefusal;
use Mothership\DAM\Tests\Support\RemoteDiskFake;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
    config(['dam.explorer.upload.chunk_bytes' => 4]);
    Storage::fake(Directory::getAssetDisk());

    $this->initializeCompletionUpload = function (
        string $contents = 'abcdefghijkl',
        string $originalName = 'payload.txt',
        string $mimeType = 'text/plain',
        ?string $relativePath = null,
        bool $preserveRoot = false,
    ): ChunkUpload {
        $directory = Directory::factory()->create(['name' => 'ChunkFinal']);
        if ($relativePath) {
            Storage::disk(Directory::getAssetDisk())->makeDirectory(Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath());
            $this->postJson(route('admin.dam.directory.create_structure'), [
                'directory_id' => $directory->id,
                'paths'        => ['Shoot', 'Shoot/Clips', 'Clips'],
            ])->assertOk();
        }
        $tracker = UploadTracker::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => auth()->id(),
            'directory_id' => $directory->id,
            'state'        => UploadTracker::STATE_PROCESSING,
        ]);
        $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
            'session_uuid'  => $tracker->uuid,
            'directory_id'  => $directory->id,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'size'          => strlen($contents),
            'last_modified' => 1_788_864_000_000,
            'fingerprint'   => hash('sha256', $contents),
            'relative_path' => $relativePath,
            'preserve_root' => $preserveRoot,
        ])->assertCreated()->json('upload.uuid');

        return ChunkUpload::where('uuid', $uuid)->firstOrFail();
    };

    $this->uploadAllCompletionParts = function (ChunkUpload $upload, string $contents = 'abcdefghijkl'): void {
        foreach (str_split($contents, (int) $upload->chunk_bytes) as $index => $chunk) {
            $start = $index * (int) $upload->chunk_bytes;
            $end = $start + strlen($chunk) - 1;

            $this->call(
                'PUT',
                route('admin.dam.assets.chunk-upload.store', [$upload->uuid, $index]),
                server: [
                    'CONTENT_TYPE'        => 'application/octet-stream',
                    'HTTP_CONTENT_RANGE'  => sprintf('bytes %d-%d/%d', $start, $end, $upload->expected_bytes),
                    'HTTP_X_CHUNK_SHA256' => hash('sha256', $chunk),
                ],
                content: $chunk,
            )->assertOk();
        }
    };

    // Slice 3 (ADR 0009): an upload's bytes live only in its blob; the Library path names no file.
    $this->blobBytes = function (Asset $asset): ?string {
        $object = app(ContentStore::class)->current($asset);

        return Storage::disk($object->disk)->get($object->path);
    };
});

it('retains the initiating replay session through serialized chunk finalization without inventing a worker session', function () {
    config(['dam_events.enabled'    => true, 'dam_events.posthog.enabled' => true, 'dam_events.posthog.key' => 'fixture',
        'dam_events.replay.enabled' => true]);
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $replay = (string) Str::uuid7();
    $this->withHeader('X-Dam-Replay-Session', $replay)->withHeader('User-Agent', 'Mozilla/5.0')
        ->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    $queued = Queue::pushed(FinalizeChunkUpload::class)->first();
    expect($queued)->toBeInstanceOf(FinalizeChunkUpload::class);
    $job = unserialize(serialize($queued));
    auth('admin')->logout();
    request()->headers->remove('X-Dam-Replay-Session');
    $job->handle(app(ChunkUploadService::class));
    expect(DB::table('dam_events')->where('name', 'upload.file.succeeded')->value('replay_session_id'))->toBe($replay);
});

// Sharing slice 2a (ADR 0010, spec D3): the finalizer publishes through AssetUploadPublisher like every
// other upload, so the finalized asset takes its folder's access list. Asserted here, where the chunked
// fixture already lives, rather than rebuilt in Access/InheritOnCreateTest.
it('gives a finalized chunk upload the access list of the folder it lands in', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    $friend = Admin::factory()->create(['status' => 1]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $upload->directory_id, 'admin_id' => $friend->id]);

    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $asset = Asset::findOrFail($upload->fresh()->asset_id);

    expect(DB::table('dam_asset_user_access')->where('asset_id', $asset->id)->pluck('admin_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([$friend->id])
        ->and((int) $asset->created_by)->toBe(auth()->id())
        ->and($asset->needs_sorting_at)->toBeNull();
});

// A queue worker has no session, so Asset::creating cannot take the owner from auth(): the finalizer must
// name the uploader itself (sharing spec, "Inherit on create" item 3). Asset 797 on the Studio was ownerless.
it('makes the uploader the owner of an asset finalized with no signed-in admin', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    auth()->forgetGuards();
    expect(auth('admin')->id())->toBeNull();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect((int) Asset::findOrFail($upload->fresh()->asset_id)->created_by)->toBe((int) $upload->user_id);
});

it('publishes folder uploads into their relative destination', function (bool $preserveRoot, string $suffix) {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)(relativePath: 'Shoot/Clips/payload.txt', preserveRoot: $preserveRoot);
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $asset = Asset::findOrFail($upload->fresh()->asset_id);
    expect($asset->path)->toEndWith($suffix)
        ->and($asset->directories()->firstOrFail()->name)->toBe('Clips')
        ->and(($this->blobBytes)($asset))->toBe('abcdefghijkl')
        ->and(Storage::disk(Directory::getAssetDisk())->exists($asset->path))->toBeFalse();
})->with([[true, '/Shoot/Clips/payload.txt'], [false, '/Clips/payload.txt']]);

// ADR 0017: with the asset disk on an object store, parts and the assembly stay on the local staging disk,
// the bucket receives the finished blob once, and the assembly stays behind as the warm copy for the first jobs.
it('stages a chunked upload locally and sends a remote asset disk only the finished blob', function () {
    Queue::fake();
    config(['dam.asset_disk' => 's3']);
    $remote = RemoteDiskFake::install('s3');
    $staging = Storage::fake(ContentStore::STAGING_DISK);
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);

    expect($staging->files($upload->staged_root.'/parts'))->toHaveCount(3)
        ->and($remote->calls)->toBe([]);

    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $object = app(ContentStore::class)->current(Asset::findOrFail($upload->fresh()->asset_id));

    expect($object->disk)->toBe('s3')
        ->and($remote->calls)->toBe([['writeStream', $object->path]])
        ->and(Storage::disk('s3')->get($object->path))->toBe('abcdefghijkl')
        ->and($staging->get(ContentStore::WARM.'/'.$object->sha256))->toBe('abcdefghijkl')
        ->and($staging->directoryExists($upload->staged_root))->toBeFalse()
        ->and($staging->files(ContentStore::STAGING))->toBe([]);
});

it('queues assembles and publishes a complete upload exactly once', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);

    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))
        ->assertAccepted()
        ->assertJsonPath('success', true)
        ->assertJsonPath('upload.state', ChunkUpload::STATE_QUEUED);
    Queue::assertPushed(FinalizeChunkUpload::class, 1);

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $upload->refresh();
    $asset = Asset::findOrFail($upload->asset_id);

    expect($upload->state)->toBe(ChunkUpload::STATE_COMPLETED)
        ->and(($this->blobBytes)($asset))->toBe('abcdefghijkl')
        ->and((int) $asset->file_size)->toBe(12)
        ->and(Storage::disk(Directory::getAssetDisk())->allFiles(Directory::ASSETS_DIRECTORY.'/ChunkFinal'))->toBe([])
        ->and(Storage::disk(Directory::getAssetDisk())->allFiles(ContentStore::STAGING))->toBe([])
        ->and($upload->parts()->count())->toBe(0)
        ->and(Storage::disk(Directory::getAssetDisk())->directoryMissing($upload->staged_root))->toBeTrue()
        ->and(UploadBatch::where('asset_id', $asset->id)->count())->toBe(1);
    Queue::assertPushed(ProcessAssetUpload::class, 1);
});

$pdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$wavBytes = 'RIFF'.pack('V', 40).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', 4)."\x80\x80\x80\x80";
$mp4Bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free";
$dngBytes = "II*\x00\x08\x00\x00\x00\x00\x00\x00\x00\x00\x00";

it('lists the bytes by what they are, not by the file name or what the browser declared', function (string $name, string $declared, string $bytes, string $mime, string $type) {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)($bytes, $name, $declared);
    ($this->uploadAllCompletionParts)($upload, $bytes);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $asset = Asset::findOrFail($upload->refresh()->asset_id);

    // Both the stored value and what the model reports (the accessor has its own .dng rule).
    expect($asset->getRawOriginal('file_type'))->toBe($type)
        ->and($asset->file_type)->toBe($type);

    if ($mime !== '') {
        expect($asset->mime_type)->toBe($mime);
    }
})->with([
    'PDF named photo.jpg'              => ['photo.jpg', 'image/jpeg', $pdfBytes, 'application/pdf', 'document'],
    'PDF named clip.mp4'               => ['clip.mp4', 'video/mp4', $pdfBytes, 'application/pdf', 'document'],
    'PDF named song.wav'               => ['song.wav', 'audio/wav', $pdfBytes, 'application/pdf', 'document'],
    'PDF named raw.dng'                => ['raw.dng', 'image/x-adobe-dng', $pdfBytes, 'application/pdf', 'document'],
    'PDF named clip.mp4, octet-stream' => ['clip.mp4', 'application/octet-stream', $pdfBytes, 'application/pdf', 'document'],
    'WAV named raw.dng'                => ['raw.dng', 'image/x-adobe-dng', $wavBytes, '', 'audio'],
    'WAV named song.html'              => ['song.html', 'text/html', $wavBytes, '', 'audio'],
    'MP4 named raw.dng'                => ['raw.dng', 'image/x-adobe-dng', $mp4Bytes, '', 'video'],
    'MP4 named page.html'              => ['page.html', 'text/html', $mp4Bytes, '', 'video'],
    'PDF named page.html'              => ['page.html', 'text/html', $pdfBytes, 'application/pdf', 'document'],
    'real MP4'                         => ['clip.mp4', 'video/mp4', $mp4Bytes, '', 'video'],
    'real WAV'                         => ['song.wav', 'audio/wav', $wavBytes, '', 'audio'],
    'real DNG (TIFF bytes)'            => ['raw.dng', 'image/x-adobe-dng', $dngBytes, '', 'image'],
]);

it('lists an Illustrator file with PDF compatibility as Illustrator, keeping its name, extension and PDF type', function (string $name, string $declared, ?string $adobeKey) use ($pdfBytes) {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)($pdfBytes, $name, $declared);
    ($this->uploadAllCompletionParts)($upload, $pdfBytes);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $asset = Asset::findOrFail($upload->refresh()->asset_id);

    // The sniffed type stays what the bytes are, so the PDF renderer and the technical info still see PDF.
    expect($asset->mime_type)->toBe('application/pdf')
        ->and($asset->file_name)->toBe($name)
        ->and($asset->extension)->toBe(pathinfo($name, PATHINFO_EXTENSION))
        ->and(AdobeFormats::present(extension: $asset->extension, mime: $asset->mime_type)['key'] ?? null)->toBe($adobeKey);
})->with([
    'Illustrator, declared as Illustrator' => ['Banner Logo.ai', 'application/illustrator', 'illustrator'],
    'Illustrator, declared generic'        => ['Discvery Lab_Logo_F1.ai', 'application/octet-stream', 'illustrator'],
    'Illustrator template'                 => ['Layout.ait', 'application/octet-stream', 'illustrator'],
    'a plain PDF'                          => ['Brochure.pdf', 'application/pdf', null],
]);

it('rejects incomplete uploads without dispatching finalization', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $upload->parts()->where('chunk_index', 2)->delete();

    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'upload_incomplete');
    Queue::assertNotPushed(FinalizeChunkUpload::class);
});

it('rejects completion after the twelve hour deadline before the hourly sweep', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $upload->update(['expires_at' => now()->subSecond()]);

    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))
        ->assertStatus(409)
        ->assertJsonPath('code', 'upload_expired');
    Queue::assertNotPushed(FinalizeChunkUpload::class);
});

it('does not dispatch duplicate jobs while queued or assembling', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);

    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    $upload->update(['state' => ChunkUpload::STATE_ASSEMBLING]);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    Queue::assertPushed(FinalizeChunkUpload::class, 1);
});

it('stays queued when the completion lock is temporarily unavailable', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    $lock = Cache::lock('dam:chunk-upload:'.$upload->uuid.':complete', 21_600);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(ChunkUploadService::class)->finalize($upload->fresh()))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_QUEUED);
});

it('returns a completed upload without dispatching another job', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));
    Queue::fake();

    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))
        ->assertOk()
        ->assertJsonPath('upload.state', ChunkUpload::STATE_COMPLETED);
    Queue::assertNotPushed(FinalizeChunkUpload::class);
});

it('requeues a failed upload only once', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $upload->update(['state' => ChunkUpload::STATE_FAILED, 'failure_code' => 'storage_failed']);

    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    Queue::assertPushed(FinalizeChunkUpload::class, 1);
    expect($upload->fresh()->failure_code)->toBeNull();
});

it('exposes every finalization state through status', function (string $state) {
    $upload = ($this->initializeCompletionUpload)();
    $upload->update(['state' => $state]);

    $this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))
        ->assertOk()
        ->assertJsonPath('upload.state', $state);
})->with([
    ChunkUpload::STATE_QUEUED,
    ChunkUpload::STATE_ASSEMBLING,
    ChunkUpload::STATE_COMPLETED,
    ChunkUpload::STATE_FAILED,
]);

it('exposes terminal asset and failure metadata for browser polling', function () {
    $completed = ($this->initializeCompletionUpload)();
    $asset = Asset::factory()->create();
    $completed->update(['state' => ChunkUpload::STATE_COMPLETED, 'asset_id' => $asset->id]);
    $failed = ($this->initializeCompletionUpload)('mnopqrstuvwx', 'failed.mp4');
    $failed->update(['state' => ChunkUpload::STATE_FAILED, 'failure_code' => 'chunk_corrupt']);

    $this->getJson(route('admin.dam.assets.chunk-upload.status', $completed->uuid))
        ->assertOk()
        ->assertJsonPath('upload.asset_id', $asset->id)
        ->assertJsonPath('upload.failure_code', null);
    $this->getJson(route('admin.dam.assets.chunk-upload.status', $failed->uuid))
        ->assertOk()
        ->assertJsonPath('upload.asset_id', null)
        ->assertJsonPath('upload.failure_code', 'chunk_corrupt');
});

it('fails corrupt staged data without publishing and retains verified parts', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $part = $upload->parts()->where('chunk_index', 1)->firstOrFail();
    Storage::disk(Directory::getAssetDisk())->put($part->staged_path, 'WXYZ');
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($upload->fresh()->failure_code)->toBe('chunk_corrupt')
        ->and($upload->fresh()->asset_id)->toBeNull()
        ->and($upload->parts()->count())->toBe(3);
});

it('fails an oversized staged part as chunk_corrupt without reading the whole file into memory', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $part = $upload->parts()->where('chunk_index', 1)->firstOrFail();
    $disk = Storage::disk(Directory::getAssetDisk());
    // Still 4 bytes recorded, but the staged file itself now has more: verifyAndAppendInMemory must cap
    // its read at byte_length + 1 rather than loading the whole (potentially huge) file into memory.
    $disk->put($part->staged_path, $disk->get($part->staged_path).'-extra-bytes-beyond-the-recorded-length');
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($upload->fresh()->failure_code)->toBe('chunk_corrupt')
        ->and($upload->fresh()->asset_id)->toBeNull()
        ->and($upload->parts()->count())->toBe(3)
        ->and($disk->allFiles(ContentStore::STAGING))->toBe([]);
});

it('fails a missing staged part as chunk_missing and leaves staging empty', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $part = $upload->parts()->where('chunk_index', 1)->firstOrFail();
    Storage::disk(Directory::getAssetDisk())->delete($part->staged_path);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($upload->fresh()->failure_code)->toBe('chunk_missing')
        ->and($upload->fresh()->asset_id)->toBeNull()
        ->and(Storage::disk(Directory::getAssetDisk())->allFiles(ContentStore::STAGING))->toBe([]);
});

it('rejects forbidden assembled content before publication', function () {
    Queue::fake();
    $contents = '<?php echo 1;';
    $upload = ($this->initializeCompletionUpload)($contents, 'payload.php', 'application/x-php');
    ($this->uploadAllCompletionParts)($upload, $contents);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($upload->fresh()->failure_code)->toBe('forbidden_file')
        ->and($upload->fresh()->asset_id)->toBeNull();
});

it('rejects php bytes disguised as an html page before publication', function () {
    Queue::fake();
    // The client claims markup; only the assembled bytes reveal the script (ADR 0007).
    $contents = '<?php echo 1;';
    $upload = ($this->initializeCompletionUpload)($contents, 'page.html', 'text/html');
    ($this->uploadAllCompletionParts)($upload, $contents);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($upload->fresh()->failure_code)->toBe('forbidden_file')
        ->and($upload->fresh()->asset_id)->toBeNull();
});

it('uses the existing overwrite behavior for a matching destination', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    $directory = Directory::findOrFail($upload->directory_id);
    $path = Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath().'/payload.txt';
    $existing = Asset::factory()->create(['file_name' => 'payload.txt', 'path' => $path]);
    $existing->directories()->attach($directory->id);
    Storage::disk(Directory::getAssetDisk())->put($path, 'old');
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    // The overwrite is a new original for the same asset; the old Library file is never rewritten.
    expect($upload->fresh()->asset_id)->toBe($existing->id)
        ->and(Asset::where('path', $path)->count())->toBe(1)
        ->and(($this->blobBytes)($existing->fresh()))->toBe('abcdefghijkl')
        ->and(Storage::disk(Directory::getAssetDisk())->get($path))->toBe('old');
});

it('never overwrites a same-name file when the upload is create-only', function () {
    Queue::fake();
    $directory = Directory::factory()->create(['name' => 'ChunkCreateOnly']);
    $path = Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath().'/payload.txt';
    $existing = Asset::factory()->create(['file_name' => 'payload.txt', 'path' => $path]);
    $existing->directories()->attach($directory->id);
    $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'directory_id' => $directory->id, 'state' => UploadTracker::STATE_PROCESSING]);
    $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
        'session_uuid' => $tracker->uuid, 'directory_id' => $directory->id, 'original_name' => 'payload.txt', 'mime_type' => 'text/plain',
        'size'         => 12, 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', 'abcdefghijkl'), 'preserve_root' => false, 'create_only' => true,
    ])->assertCreated()->json('upload.uuid');
    $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->asset_id)->not->toBe($existing->id)
        ->and($existing->fresh()->file_name)->toBe('payload.txt')
        ->and($existing->fresh()->path)->toBe($path)
        ->and(Asset::findOrFail($upload->fresh()->asset_id)->file_name)->toBe('payload(1).txt');
});

it('keeps a create-only upload apart from a normal upload of the same file', function () {
    Queue::fake();
    $directory = Directory::factory()->create(['name' => 'ChunkResumeSeparation']);
    $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'directory_id' => $directory->id, 'state' => UploadTracker::STATE_PROCESSING]);
    $identity = [
        'session_uuid' => $tracker->uuid, 'directory_id' => $directory->id, 'original_name' => 'payload.txt', 'mime_type' => 'text/plain',
        'size'         => 12, 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', 'abcdefghijkl'), 'preserve_root' => false,
    ];
    $normal = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $identity)->assertCreated()->json('upload.uuid');

    $createOnly = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $identity + ['create_only' => true])
        ->assertCreated()->json('upload.uuid');

    expect($createOnly)->not->toBe($normal)
        ->and(ChunkUpload::where('uuid', $normal)->firstOrFail()->create_only)->toBeFalse()
        ->and(ChunkUpload::where('uuid', $createOnly)->firstOrFail()->create_only)->toBeTrue();

    $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $identity)->assertOk()
        ->assertJsonPath('upload.uuid', $normal);
});

it('answers a repeated completion of a finished create-only upload with the same asset', function () {
    Queue::fake();
    $directory = Directory::factory()->create(['name' => 'ChunkLostResponse']);
    $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'directory_id' => $directory->id, 'state' => UploadTracker::STATE_PROCESSING]);
    $identity = [
        'session_uuid' => $tracker->uuid, 'directory_id' => $directory->id, 'original_name' => 'payload.txt', 'mime_type' => 'text/plain',
        'size'         => 12, 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', 'abcdefghijkl'), 'preserve_root' => false, 'create_only' => true,
    ];
    $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $identity)->assertCreated()->json('upload.uuid');
    $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();
    ($this->uploadAllCompletionParts)($upload);
    // The server accepts the completion; the workspace never sees this response (the lost-response case).
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));
    $assets = Asset::query()->count();

    // Retry, as S.chunkedSave does it: status first, then complete on the SAME uuid.
    $this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))->assertOk()
        ->assertJsonPath('upload.state', 'completed')->assertJsonPath('upload.asset_id', $upload->fresh()->asset_id);
    $again = $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid));

    expect($again->status())->toBeIn([200, 202])
        ->and($again->json('upload.asset_id'))->toBe($upload->fresh()->asset_id)
        ->and(Asset::query()->count())->toBe($assets);
    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_COMPLETED);   // a completed upload is returned, never re-queued
});

// Review (PR C): the asset exists before the completion record does, so a failure in between used to put the upload back
// to queued and the next finalization published a second asset under the next free name ("never saved twice" broken).
it('publishes a create-only upload once when its completion record fails and finalization is retried', function () {
    Queue::fake();
    $directory = Directory::factory()->create(['name' => 'ChunkCompletionFails']);
    $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'directory_id' => $directory->id, 'state' => UploadTracker::STATE_PROCESSING]);
    $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
        'session_uuid' => $tracker->uuid, 'directory_id' => $directory->id, 'original_name' => 'payload.txt', 'mime_type' => 'text/plain',
        'size'         => 12, 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', 'abcdefghijkl'), 'preserve_root' => false, 'create_only' => true,
    ])->assertCreated()->json('upload.uuid');
    $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    $inFolder = fn (): int => DB::table('dam_asset_directory')->where('directory_id', $directory->id)->count();

    // The completion write is the one that fails: the asset already exists when it does.
    $failures = 1;
    ChunkUpload::updating(function (ChunkUpload $row) use (&$failures): void {
        if ($row->state === ChunkUpload::STATE_COMPLETED && $failures-- > 0) {
            throw new RuntimeException('completion record unavailable');
        }
    });

    expect(fn () => (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class)))->toThrow(RuntimeException::class);
    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_QUEUED)
        ->and($inFolder())->toBe(1);

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $first = DB::table('dam_asset_directory')->where('directory_id', $directory->id)->value('asset_id');
    expect($inFolder())->toBe(1)
        ->and($upload->fresh()->state)->toBe(ChunkUpload::STATE_COMPLETED)
        ->and((int) $upload->fresh()->asset_id)->toBe((int) $first)
        ->and(Asset::findOrFail($first)->file_name)->toBe('payload.txt')
        ->and($upload->fresh()->publish_outcome)->not->toBeNull()
        ->and($upload->fresh()->published_sha256)->toBe(hash('sha256', 'abcdefghijkl'));
});

it('queues processing for the asset a retried create-only upload adopts when the first attempt never queued it', function () {
    Queue::fake();
    $directory = Directory::factory()->create(['name' => 'ChunkAdoptQueues']);
    $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'directory_id' => $directory->id, 'state' => UploadTracker::STATE_PROCESSING]);
    $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
        'session_uuid' => $tracker->uuid, 'directory_id' => $directory->id, 'original_name' => 'payload.txt', 'mime_type' => 'text/plain',
        'size'         => 12, 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', 'abcdefghijkl'), 'preserve_root' => false, 'create_only' => true,
    ])->assertCreated()->json('upload.uuid');
    $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    // Queueing the finalization is the publisher's step after the asset is created: failing it leaves an asset nobody processes.
    $failures = 1;
    UploadBatch::creating(function () use (&$failures): void {
        if ($failures-- > 0) {
            throw new RuntimeException('batch unavailable');
        }
    });

    expect(fn () => (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class)))->toThrow(RuntimeException::class);
    Queue::assertNotPushed(ProcessAssetUpload::class);

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $assetId = (int) $upload->fresh()->asset_id;
    expect(DB::table('dam_asset_directory')->where('directory_id', $directory->id)->count())->toBe(1);
    Queue::assertPushed(ProcessAssetUpload::class, 1);
    expect(UploadBatch::query()->where('asset_id', $assetId)->count())->toBe(1);
});

it('retries the same asset and batch when a queue push fails after batch persistence', function (bool $acceptedBeforeError) {
    $queue = Queue::fake();
    $directory = Directory::factory()->create(['name' => 'ChunkQueueFails']);
    $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'directory_id' => $directory->id, 'state' => UploadTracker::STATE_PROCESSING]);
    $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
        'session_uuid' => $tracker->uuid, 'directory_id' => $directory->id, 'original_name' => 'payload.txt', 'mime_type' => 'text/plain',
        'size'         => 12, 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', 'abcdefghijkl'), 'preserve_root' => false, 'create_only' => true,
    ])->assertCreated()->json('upload.uuid');
    $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    $failures = 1;
    $failPush = function ($job) use (&$failures, $tracker): void {
        if ($job instanceof ProcessAssetUpload && $failures-- > 0) {
            expect(UploadBatch::query()->where('upload_tracker_id', $tracker->id)->count())->toBe(1);
            throw new RuntimeException('queue acknowledgement unavailable');
        }
    };
    $acceptedBeforeError ? $queue->afterPushing($failPush) : $queue->beforePushing($failPush);

    expect(fn () => (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class)))->toThrow(RuntimeException::class);

    $assetId = (int) $upload->fresh()->asset_id;
    $batch = UploadBatch::query()->where('upload_tracker_id', $tracker->id)->sole();
    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_QUEUED)
        ->and($batch->state)->toBe(UploadBatch::STATE_PENDING)
        ->and(Asset::query()->count())->toBe(1);

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_COMPLETED)
        ->and((int) $upload->fresh()->asset_id)->toBe($assetId)
        ->and(Asset::query()->count())->toBe(1)
        ->and(UploadBatch::query()->where('upload_tracker_id', $tracker->id)->count())->toBe(1);
    Queue::assertPushed(ProcessAssetUpload::class, $acceptedBeforeError ? 2 : 1);

    foreach ($queue->pushed(ProcessAssetUpload::class) as $job) {
        $job->handle(app(MetadataExtractionService::class));
    }

    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PROCESSED)
        ->and($tracker->fresh()->processed_files)->toBe(1);
})->with([false, true]);

it('hands a dead upload back to its own session, so only a new session starts again', function () {
    Queue::fake();
    $directory = Directory::factory()->create(['name' => 'ChunkDeadSession']);
    $session = fn (): string => UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'directory_id' => $directory->id, 'state' => UploadTracker::STATE_PROCESSING])->uuid;
    $identity = fn (string $uuid): array => [
        'session_uuid' => $uuid, 'directory_id' => $directory->id, 'original_name' => 'payload.txt', 'mime_type' => 'text/plain',
        'size'         => 12, 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', 'abcdefghijkl'), 'preserve_root' => false, 'create_only' => true,
    ];
    $first = $session();
    $dead = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $identity($first))->assertCreated()->json('upload.uuid');

    foreach ([ChunkUpload::STATE_EXPIRED, ChunkUpload::STATE_CANCELLED] as $state) {
        ChunkUpload::where('uuid', $dead)->update(['state' => $state]);
        // ChunkUploadService::initialize returns ANY row with this identity, dead or not.
        $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $identity($first))->assertOk()
            ->assertJsonPath('upload.uuid', $dead)->assertJsonPath('upload.state', $state);
    }

    $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $identity($session()))->assertCreated()
        ->assertJsonPath('upload.state', ChunkUpload::STATE_RECEIVING);
    expect(ChunkUpload::query()->where('directory_id', $directory->id)->count())->toBe(2);
});

it('preserves media classification when browser MIME metadata is generic', function (string $name, string $type) {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)(originalName: $name, mimeType: 'application/octet-stream');
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect(Asset::findOrFail($upload->fresh()->asset_id)->file_type)->toBe($type);
})->with([['capture.dng', 'image'], ['clip.mp4', 'video'], ['recording.wav', 'audio']]);

it('removes incomplete assembly when publication infrastructure fails', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    $publisher = Mockery::mock(AssetUploadPublisher::class);
    $publisher->shouldReceive('publishStagedFile')->once()->andThrow(new RuntimeException('storage unavailable'));
    app()->instance(AssetUploadPublisher::class, $publisher);

    expect(fn () => (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class)))
        ->toThrow(RuntimeException::class);

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_QUEUED)
        ->and($upload->fresh()->asset_id)->toBeNull()
        ->and(Asset::where('file_name', 'payload.txt')->exists())->toBeFalse()
        ->and(Storage::disk(Directory::getAssetDisk())->allFiles(ContentStore::STAGING))->toBe([]);
});

it('hashes the assembled file exactly once with the new helper before moving it into its blob', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    // adoptStored() hashes its source only when the caller has no checksum for it.
    $this->partialMock(ContentStore::class, fn ($mock) => $mock->shouldNotReceive('checksum'));
    $logPath = storage_path('logs/chunk-uploads.jsonl');
    @unlink($logPath);

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $object = app(ContentStore::class)->current(Asset::findOrFail($upload->fresh()->asset_id));
    // Sha256File::hash() writes exactly one upload.hashed event per finalize; the 12-byte payload stays
    // under both default thresholds, so it takes the PHP path.
    $hashedEvents = collect(file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        ->map(fn (string $line) => json_decode($line, true))
        ->where('event', 'upload.hashed')
        ->values();

    expect($object->sha256)->toBe(hash('sha256', 'abcdefghijkl'))
        ->and(Storage::disk($object->disk)->get($object->path))->toBe('abcdefghijkl')
        ->and($hashedEvents)->toHaveCount(1)
        ->and($hashedEvents->first()['ctx'])->toMatchArray(['bytes' => 12, 'method' => 'php']);
});

it('falls back to the temp-file assembly path when opening local staging fails', function () {
    Queue::fake();
    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    // Whatever the reason openLocalStaging() couldn't be used, assembleAndPublish() falls back to
    // tmpfile() + writeStream() -- the same path the non-local (S3) disk always takes, so this covers it.
    $this->partialMock(ContentStore::class, fn ($mock) => $mock->shouldReceive('openLocalStaging')->once()->andThrow(new RuntimeException('local_staging_open_failed')));

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $asset = Asset::findOrFail($upload->fresh()->asset_id);
    $object = app(ContentStore::class)->current($asset);

    expect($object->sha256)->toBe(hash('sha256', 'abcdefghijkl'))
        ->and(Storage::disk($object->disk)->get($object->path))->toBe('abcdefghijkl')
        ->and(Storage::disk(Directory::getAssetDisk())->allFiles(ContentStore::STAGING))->toBe([]);
});

it('still verifies and publishes through the streaming branch when a part exceeds the in-memory digest limit', function () {
    Queue::fake();
    // chunk_bytes is 4 (see beforeEach); below that forces every part through the streaming branch.
    config(['dam.explorer.upload.part_digest_in_memory_max_bytes' => 2]);

    $upload = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $asset = Asset::findOrFail($upload->fresh()->asset_id);
    expect(($this->blobBytes)($asset))->toBe('abcdefghijkl');

    $corrupted = ($this->initializeCompletionUpload)();
    ($this->uploadAllCompletionParts)($corrupted);
    $part = $corrupted->parts()->where('chunk_index', 1)->firstOrFail();
    Storage::disk(Directory::getAssetDisk())->put($part->staged_path, 'WXYZ');
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $corrupted->uuid))->assertAccepted();

    (new FinalizeChunkUpload($corrupted->id))->handle(app(ChunkUploadService::class));

    expect($corrupted->fresh()->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($corrupted->fresh()->failure_code)->toBe('chunk_corrupt');
});

it('refuses malformed chunked SVG once without retrying or storing an asset or blob', function (bool $fallback) {
    Queue::fake();
    $contents = '<svg xmlns="http://www.w3.org/2000/svg"><g></svg>';
    $upload = ($this->initializeCompletionUpload)($contents, 'broken.svg', 'image/svg+xml');
    ($this->uploadAllCompletionParts)($upload, $contents);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    $assets = Asset::query()->count();
    $objects = FileObject::query()->count();

    if ($fallback) {
        $this->partialMock(ContentStore::class, fn ($mock) => $mock->shouldReceive('openLocalStaging')->once()->andThrow(new RuntimeException('local_staging_open_failed')));
    }

    $job = new FinalizeChunkUpload($upload->id);
    $job->handle(app(ChunkUploadService::class));
    // A duplicate delivery must not assemble the refused input again.
    $job->handle(app(ChunkUploadService::class));

    $this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))->assertOk()
        ->assertJsonPath('upload.state', ChunkUpload::STATE_FAILED)
        ->assertJsonPath('upload.failure_code', 'svg_malformed')
        ->assertJsonPath('upload.failure_reason.code', 'svg_malformed');

    $events = collect(file(storage_path('logs/chunk-uploads.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        ->map(fn (string $line) => json_decode($line, true))
        ->where('run_id', $upload->uuid);
    expect($events->where('event', 'upload.assembling'))->toHaveCount(1)
        ->and($events->where('event', 'upload.finalization_retry'))->toHaveCount(0)
        ->and(Asset::query()->count())->toBe($assets)
        ->and(FileObject::query()->count())->toBe($objects)
        ->and(Storage::disk(Directory::getAssetDisk())->allFiles(ContentStore::STAGING))->toBe([])
        ->and($upload->fresh()->asset_id)->toBeNull();
})->with(['local staging' => false, 'temp-file fallback' => true]);

it('sanitizes a chunked SVG through local staging without a native hash attempt, logging one sanitized event', function () {
    Queue::fake();
    // Forces a native attempt if hashAssembledFile() ran at all, so a regression of the sanitize-before-hash
    // ordering (round 1, finding 5) would trip the process factory below, not slide by under the default
    // 64 MiB threshold.
    config(['dam.explorer.upload.native_sha256_min_bytes' => 1]);
    $contents = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><script>alert(1)</script><rect width="4" height="4"/></svg>';
    $upload = ($this->initializeCompletionUpload)($contents, 'logo.svg', 'application/octet-stream');
    ($this->uploadAllCompletionParts)($upload, $contents);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    $logPath = storage_path('logs/chunk-uploads.jsonl');
    @unlink($logPath);
    // A thrown Throwable here would just be caught and folded into a normal PHP fallback (by design), so
    // the reset the test itself checks is a flag flipped before the throw, never a swallowed test failure.
    $nativeAttempted = false;
    Sha256File::$processFactory = function (array $command) use (&$nativeAttempted): never {
        $nativeAttempted = true;

        throw new RuntimeException('native process should not run for a sanitized SVG');
    };

    try {
        (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));
    } finally {
        Sha256File::$processFactory = null;
    }

    $asset = Asset::findOrFail($upload->fresh()->asset_id);
    $object = app(ContentStore::class)->current($asset);
    $stored = Storage::disk($object->disk)->get($object->path);
    $hashedEvents = collect(file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        ->map(fn (string $line) => json_decode($line, true))
        ->where('event', 'upload.hashed')
        ->values();

    expect($nativeAttempted)->toBeFalse()
        ->and($stored)->not->toContain('<script')->toContain('<rect')
        ->and($object->sha256)->toBe(hash('sha256', $stored))
        ->and((int) $asset->file_size)->toBe(strlen($stored))
        ->and($hashedEvents)->toHaveCount(1)
        ->and($hashedEvents->first()['ctx']['method'])->toBe('sanitized');
});

it('sanitizes a chunked SVG through the forced temp-file fallback without a native hash attempt, logging one sanitized event', function () {
    Queue::fake();
    config(['dam.explorer.upload.native_sha256_min_bytes' => 1]);
    $contents = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><script>alert(1)</script><rect width="4" height="4"/></svg>';
    $upload = ($this->initializeCompletionUpload)($contents, 'logo.svg', 'application/octet-stream');
    ($this->uploadAllCompletionParts)($upload, $contents);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    // Same forced fallback as the tmpfile()+writeStream() coverage above: local staging is unavailable, so
    // assembly and sanitize both run against the temp file instead.
    $this->partialMock(ContentStore::class, fn ($mock) => $mock->shouldReceive('openLocalStaging')->once()->andThrow(new RuntimeException('local_staging_open_failed')));
    $logPath = storage_path('logs/chunk-uploads.jsonl');
    @unlink($logPath);
    $nativeAttempted = false;
    Sha256File::$processFactory = function (array $command) use (&$nativeAttempted): never {
        $nativeAttempted = true;

        throw new RuntimeException('native process should not run for a sanitized SVG');
    };

    try {
        (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));
    } finally {
        Sha256File::$processFactory = null;
    }

    $asset = Asset::findOrFail($upload->fresh()->asset_id);
    $object = app(ContentStore::class)->current($asset);
    $stored = Storage::disk($object->disk)->get($object->path);
    $hashedEvents = collect(file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        ->map(fn (string $line) => json_decode($line, true))
        ->where('event', 'upload.hashed')
        ->values();

    expect($nativeAttempted)->toBeFalse()
        ->and($stored)->not->toContain('<script')->toContain('<rect')
        ->and($object->sha256)->toBe(hash('sha256', $stored))
        ->and((int) $asset->file_size)->toBe(strlen($stored))
        ->and($hashedEvents)->toHaveCount(1)
        ->and($hashedEvents->first()['ctx']['method'])->toBe('sanitized');
});

// The "New user uploads" queue (2026-09-30): a finalized chunk upload is flagged for sorting when a role-User
// made it (the admin case is the fixtures above, which finalize as an admin and assert no flag here).
it('flags a finalized chunk upload made by a role-User for sorting', function () {
    Queue::fake();
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => 'lists']);
    app(AccessModel::class)->flush();

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.upload']]);
    $user = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    $this->actingAs($user, 'admin');

    $upload = ($this->initializeCompletionUpload)();
    DB::table('dam_directory_user_access')->updateOrInsert(['directory_id' => $upload->directory_id, 'admin_id' => $user->id]);
    ($this->uploadAllCompletionParts)($upload);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    $asset = Asset::findOrFail($upload->fresh()->asset_id);

    expect((int) $asset->created_by)->toBe($user->id)
        ->and($asset->needs_sorting_at)->not->toBeNull();
});

// QRAI-06: a web-page bundle refused at finalization used to reach the tray as a generic failure, because only
// failure_code was stored. The structured reason is persisted and the status poll renders it as {code, message}.
it('reports why finalization refused a chunked web-page bundle through the status poll', function (string $case, string $code, string $key, array $params) {
    Queue::fake();
    config(['dam.explorer.upload.chunk_bytes' => 64]);

    $entries = match ($case) {
        'traversal' => ['index.html' => 'x', 'a/../../escape.css' => '1'],
        'forbidden' => ['index.html' => 'x', 'shell.php' => '<?php echo 1;'],
        'count'     => ['index.html' => 'x', 'a.css' => '1', 'b.css' => '2'],
        'size'      => ['index.html' => 'x', 'big.css' => str_repeat('a', 64)],
    };

    $path = tempnam(sys_get_temp_dir(), 'wp').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    $contents = file_get_contents($path);
    @unlink($path);

    // The limits apply when the job runs, as they would on a worker.
    config(['dam.web_pages.max_entries' => $case === 'count' ? 2 : 5000]);
    if ($case === 'size') {
        config(['dam.import_bundle.max_entry_size' => 16, 'dam.web_pages.max_bundle_size' => 536870912]);
    }

    $upload = ($this->initializeCompletionUpload)(contents: $contents, originalName: 'site.zip', mimeType: 'application/zip');
    ($this->uploadAllCompletionParts)($upload, $contents);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    Queue::assertPushed(FinalizeChunkUpload::class);

    try {
        (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));
    } catch (Throwable) {
        // A refusal is terminal; the job reports it through the upload row, which is what the client polls.
    }

    $polled = $this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))->assertOk()->json('upload');

    expect($polled['state'])->toBe(ChunkUpload::STATE_FAILED)
        ->and($polled['failure_code'])->toBe('forbidden_file')
        ->and($polled['failure_reason'])->toBe(['code' => $code, 'message' => trans($key, $params)])
        ->and(json_encode($polled))->not->toContain('Exception')
        ->and($upload->fresh()->failure_reason)->toBe(['code' => $code, 'key' => $key, 'params' => $params]);
})->with([
    'path traversal'  => ['traversal', 'unsafe_path', 'dam::app.admin.dam.web-pages.refused.unsafe-path', []],
    'forbidden entry' => ['forbidden', 'forbidden_entry', 'dam::app.admin.dam.web-pages.forbidden-entry', ['entry' => 'shell.php']],
    'entry count'     => ['count', 'too_many_entries', 'dam::app.admin.dam.web-pages.refused.too-many-entries', ['limit' => 2]],
    'entry size'      => ['size', 'too_large', 'dam::app.admin.dam.web-pages.refused.too-large', []],
]);

it('stores and returns the generic refusal for an unknown or out-of-namespace reason key', function (string $key) {
    $generic = trans(UploadRefusal::GENERIC_KEY);
    $reason = UploadRefusal::make('Not A Code!', $key, ['entry' => 'x']);

    expect($reason)->toBe(['code' => 'refused', 'key' => UploadRefusal::GENERIC_KEY, 'params' => []]);

    $upload = ($this->initializeCompletionUpload)();
    $upload->forceFill(['state' => ChunkUpload::STATE_FAILED, 'failure_code' => 'forbidden_file', 'failure_reason' => $reason])->save();

    expect($upload->fresh()->failure_reason)->toBe($reason)
        ->and($this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))->assertOk()->json('upload.failure_reason'))
        ->toBe(['code' => 'refused', 'message' => $generic]);

    // A row written before this guard (or by hand) is still rendered through it.
    $upload->forceFill(['failure_reason' => ['code' => 'x', 'key' => $key, 'params' => []]])->save();
    expect($this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))->json('upload.failure_reason.message'))->toBe($generic);
})->with([
    'unknown dam key'      => ['dam::app.admin.dam.web-pages.refused.no-such-reason'],
    'out of namespace'     => ['validation.required'],
    'a group, not a string'=> ['dam::app.admin.dam.web-pages.refused'],
]);

it('stores a hostile entry name as plain, single-line, capped text and returns it unchanged as text', function () {
    Queue::fake();
    config(['dam.explorer.upload.chunk_bytes' => 4096]);

    $hostile = '<img src=x onerror=alert(1)>"\'`'."\x01\x07\t\r\n".str_repeat('A', 2000).'.php';
    $path = tempnam(sys_get_temp_dir(), 'wp').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', 'x');
    $zip->addFromString($hostile, '<?php');
    $zip->close();
    $contents = file_get_contents($path);
    @unlink($path);

    $upload = ($this->initializeCompletionUpload)(contents: $contents, originalName: 'site.zip', mimeType: 'application/zip');
    ($this->uploadAllCompletionParts)($upload, $contents);
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

    try {
        (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));
    } catch (Throwable) {
    }

    $stored = $upload->fresh()->failure_reason;
    $entry = $stored['params']['entry'] ?? '';

    expect($stored['code'])->toBe('forbidden_entry')
        ->and(mb_strlen($entry))->toBe(200)
        // The zip layer reads a non-UTF-8 name as CP437, so its control bytes arrive as printable glyphs.
        ->and($entry)->toStartWith('<img src=x onerror=alert(1)>"\'`')
        ->and($entry)->toEndWith('…')
        ->and(preg_match('/[\p{Cc}]/u', $entry))->toBe(0);

    $polled = $this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))->json('upload.failure_reason');

    // JSON carries the text as-is; it is the tray that must render it as text (upload-failure-states.test.mjs).
    expect($polled['message'])->toBe(trans('dam::app.admin.dam.web-pages.forbidden-entry', ['entry' => $entry]))
        ->and($polled['message'])->toContain('<img src=x onerror=alert(1)>');

    // A name that does reach it with raw control characters (another refusal source) is flattened the same way.
    $direct = UploadRefusal::make('forbidden_entry', 'dam::app.admin.dam.web-pages.forbidden-entry', ['entry' => $hostile]);
    expect($direct['params']['entry'])->toStartWith('<img src=x onerror=alert(1)>"\'` AAAA')
        ->and(mb_strlen($direct['params']['entry']))->toBe(200)
        ->and(preg_match('/[\p{Cc}\p{Cf}]/u', $direct['params']['entry']))->toBe(0);
});

it('refuses a reason code with a trailing newline, when made and when read back from a stored row', function () {
    $key = 'dam::app.admin.dam.web-pages.refused.unsafe-path';

    expect(UploadRefusal::make("bad\n", $key)['code'])->toBe('refused')
        ->and(UploadRefusal::make('unsafe_path', $key)['code'])->toBe('unsafe_path');

    $upload = ($this->initializeCompletionUpload)();
    $upload->forceFill([
        'state'          => ChunkUpload::STATE_FAILED,
        'failure_code'   => 'forbidden_file',
        'failure_reason' => ['code' => "bad\n", 'key' => $key, 'params' => []],
    ])->save();

    expect($this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))->assertOk()->json('upload.failure_reason'))
        ->toBe(['code' => 'refused', 'message' => trans($key)]);
});

// LIB-28: a same-name upload replaces the file already there only when its uploader may re-upload that very asset
// (`dam.asset.re_upload` plus edit rights on it); anyone else's upload becomes a new asset under the next free name.
// The finalizer is queued, so the uploader is the upload's `user_id`, never the session: both are checked with no
// signed-in admin, and the new version names its author.
describe('a same-name chunked upload over a file already there', function () {
    beforeEach(function () {
        Queue::fake();
        $this->sameNameUpload = function (array $permissions, bool $folderAccess = true): array {
            $upload = ($this->initializeCompletionUpload)();
            $directory = Directory::findOrFail($upload->directory_id);
            $path = Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath().'/payload.txt';
            // A Library file at the path when the asset is created gives it an original blob to compare against.
            Storage::disk(Directory::getAssetDisk())->put($path, 'old bytes');
            $existing = Asset::factory()->create(['file_name' => 'payload.txt', 'path' => $path]);
            $existing->directories()->attach($directory->id);
            $original = ($this->blobBytes)($existing->fresh());
            ($this->uploadAllCompletionParts)($upload);
            $this->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();

            $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
            $uploader = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
            if ($folderAccess) {
                DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id]);
            }
            $upload->forceFill(['user_id' => $uploader->id])->save();

            auth()->forgetGuards();
            (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));
            $this->actingAs($uploader, 'admin');

            return [$upload->fresh(), $existing, $uploader, $original];
        };
    });

    it('keeps the file and its bytes when the uploader may not re-upload it, and names the new copy', function () {
        [$upload, $existing, $uploader, $original] = ($this->sameNameUpload)(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.upload']);

        $created = Asset::findOrFail($upload->asset_id);

        expect($created->id)->not->toBe($existing->id)
            ->and($created->file_name)->toBe('payload(1).txt')
            ->and((int) $created->created_by)->toBe($uploader->id)
            ->and($upload->publish_outcome)->toBe('created')
            ->and(($this->blobBytes)($existing->fresh()))->toBe($original)
            ->and(DB::table('dam_asset_versions')->where('dam_asset_id', $existing->id)->count())->toBe(0);

        $this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))->assertOk()
            ->assertJsonPath('upload.published_note', trans('dam::app.admin.dam.duplicates.saved-as', ['name' => 'payload(1).txt']))
            ->assertJsonPath('upload.file_name', 'payload(1).txt');
    });

    it('keeps the file when the uploader holds re_upload but may not edit that asset', function () {
        [$upload, $existing] = ($this->sameNameUpload)(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.upload', 'dam.asset.re_upload'], folderAccess: false);

        expect($upload->asset_id)->not->toBe($existing->id)
            ->and($upload->publish_outcome)->toBe('created');
    });

    it('replaces the file with a new version authored by the uploader when they may re-upload it', function () {
        [$upload, $existing, $uploader, $original] = ($this->sameNameUpload)(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.upload', 'dam.asset.re_upload']);

        $version = DB::table('dam_asset_versions')->where('dam_asset_id', $existing->id)->orderByDesc('number')->first();

        expect($upload->asset_id)->toBe($existing->id)
            ->and($upload->publish_outcome)->toBe('versioned')
            ->and(($this->blobBytes)($existing->fresh()))->toBe('abcdefghijkl')
            ->and((int) $version->created_by)->toBe($uploader->id)
            ->and((int) DB::table('dam_asset_files')->where('dam_asset_id', $existing->id)->where('is_current', true)->where('role', 'original')->value('created_by'))->toBe($uploader->id);

        $this->getJson(route('admin.dam.assets.chunk-upload.status', $upload->uuid))->assertOk()
            ->assertJsonPath('upload.published_note', trans('dam::app.admin.dam.duplicates.replaced', ['name' => 'payload.txt']))
            ->assertJsonPath('upload.file_name', 'payload.txt');
    });
});
