<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/CreativeHubFixtures.php';
require_once __DIR__.'/../Support/HubResourceFixtures.php';

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\EventStore;
use Mothership\DAM\Analytics\PosthogOutbox;
use Mothership\DAM\Jobs\FinalizeChunkUpload;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ChunkUploadService;

/*
 * P1b (PostHog UX review 2026-10-27, finding 2): every upload path records one attempt and one terminal event per
 * file, joined by upload_id and tagged with its path, and a job's events reach the PostHog outbox.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['dam.explorer.upload.chunk_bytes' => 4]);
});

/** The upload rows recorded on `$path`, keyed by upload_id, each a list of [name, props, row]. */
function uploadEventsOn(string $path): array
{
    return DB::table('dam_events')->where('name', 'like', 'upload.file.%')->orderBy('id')->get()
        ->map(fn ($row) => [$row->name, json_decode((string) $row->props, true), $row])
        ->filter(fn (array $event) => ($event[1]['path'] ?? null) === $path)
        ->groupBy(fn (array $event) => $event[1]['upload_id'])
        ->map(fn ($events) => $events->all())
        ->all();
}

/** Exactly one upload on `$path`, settled with `$terminal`; returns its rows. */
function expectOneUpload(string $path, string $terminal): array
{
    $uploads = uploadEventsOn($path);
    expect($uploads)->toHaveCount(1);
    $events = array_values($uploads)[0];
    expect(array_column($events, 0))->toBe(['upload.file.attempted', $terminal]);

    return $events;
}

it('keeps a queue job\'s upload events out of the bot path, so they reach the PostHog outbox', function () {
    config(['dam_events.posthog.enabled' => true, 'dam_events.posthog.key' => 'test-capture-key']);
    $admin = $this->loginAsAdmin();
    auth('admin')->logout();
    // A queue worker's only request is Laravel's console one, whose Symfony default user agent reads as a script.
    request()->headers->set('User-Agent', 'Symfony');

    $actor = app(ActorResolver::class)->forAdminId(adminId: (int) $admin->id);
    DamEvents::emit(event: DamEvent::UploadAttempted, subjectId: 1, props: ['upload_id' => 'job-upload', 'attempt' => 1, 'path' => 'chunked'],
        surface: 'upload', actor: $actor, businessId: 'job-upload', attempt: '1');

    expect($actor->suspectedBot)->toBeFalse()
        ->and(DB::table('dam_events')->where('name', 'upload.file.attempted')->value('suspected_bot'))->toBeFalsy()
        ->and(DB::table(PosthogOutbox::TABLE)->where('name', 'upload.file.attempted')->count())->toBe(1);
});

it('exports a portal upload on an API account\'s request as internal, and a staff request\'s as external', function () {
    config(['dam_events.posthog.enabled' => true, 'dam_events.posthog.key' => 'test-capture-key']);
    Queue::fake();
    $agent = p5aFullAdmin();
    $agent->forceFill(['type' => 'api'])->save();
    [, $agentToken] = p5aRequest(creator: $agent);
    p5aUpload($agentToken, p5aVisit($agentToken), 'headless.txt', 'agent bytes');
    [, $staffToken] = p5aRequest(creator: p5aFullAdmin());
    p5aUpload($staffToken, p5aVisit($staffToken), 'guest.txt', 'guest bytes');

    $internal = DB::table(PosthogOutbox::TABLE)->where('name', 'upload.file.succeeded')->pluck('payload')
        ->map(fn (string $payload) => json_decode($payload, true)['properties'])
        ->mapWithKeys(fn (array $properties) => [$properties['actor_type'].':'.(int) $properties['internal_actor'] => true]);

    expect($internal->keys()->sort()->values()->all())->toBe(['link_visitor:0', 'link_visitor:1'])
        ->and(DB::table('dam_events')->where('name', 'upload.file.succeeded')->where('internal_actor', true)->count())->toBe(1);
});

it('marks visitors of a deployment-listed QA account\'s link internal, and nobody else\'s', function () {
    [$qaRequest] = p5aRequest(creator: $qa = p5aFullAdmin());
    [$staffRequest] = p5aRequest(creator: p5aFullAdmin());
    config(['dam_events.internal_link_owner_ids' => [(int) $qa->id]]);
    $actors = app(ActorResolver::class);
    $browser = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/131.0', 'REMOTE_ADDR' => '203.0.113.9']);

    expect($actors->forLinkVisitor(shareId: -(int) $qaRequest->id, request: $browser)->isInternal())->toBeTrue()
        ->and($actors->forLinkVisitor(shareId: -(int) $staffRequest->id, request: $browser)->isInternal())->toBeFalse()
        ->and($actors->forLinkVisitor(shareId: -(int) $qaRequest->id, request: null)->toAggregate(reason: 'privacy_signal')->isInternal())->toBeTrue();
});

it('records a guest portal upload as the portal visitor\'s, not the request creator\'s', function () {
    Queue::fake();
    [, $token] = p5aRequest(creator: p5aFullAdmin());
    $upload = p5aUpload($token, p5aVisit($token), 'guest.txt', 'guest bytes');

    expect($upload->asset_id)->not->toBeNull();
    $events = expectOneUpload(path: 'portal', terminal: 'upload.file.succeeded');
    expect(collect($events)->pluck(2)->pluck('actor_type')->unique()->all())->toBe(['link_visitor'])
        ->and(uploadEventsOn('chunked'))->toBe([]);
});

it('carries the uploader\'s privacy signal into the finalize job, so its events stay count-only', function () {
    Queue::fake();
    [, $token] = p5aRequest(creator: p5aFullAdmin());
    $visit = p5aVisit($token);
    $uuid = p5aInit($token, $visit, p5aFile('dnt.txt', 'private bytes'))->json('upload.uuid');
    $upload = ChunkUpload::query()->where('uuid', $uuid)->firstOrFail();
    p5aSendChunks($token, $visit, $uuid, 'private bytes', (int) $upload->chunk_bytes);
    p5aCall('POST', route('dam.upload_portal.complete', ['token' => $token, 'uuid' => $uuid]), $visit,
        server: ['HTTP_DNT' => '1', 'HTTP_USER_AGENT' => 'Mozilla/5.0'])->assertStatus(202);

    $job = Queue::pushed(FinalizeChunkUpload::class)->first();
    request()->headers->remove('DNT');
    $job->handle(app(ChunkUploadService::class));
    // A redelivered job finds the upload finished: the count-only path keeps no ids, so it must not count it again.
    unserialize(serialize($job))->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->asset_id)->not->toBeNull()
        ->and(uploadEventsOn('portal'))->toBe([])
        ->and((int) DB::table(EventStore::COUNTS)->where('name', 'upload.file.succeeded')->sum('events'))->toBe(1);
});

it('completes and finalizes a chunked upload when the analytics actor cannot be resolved', function () {
    Queue::fake();
    [, $token] = p5aRequest(creator: p5aFullAdmin());
    $visit = p5aVisit($token);
    $uuid = p5aInit($token, $visit, p5aFile('kept.txt', 'kept bytes'))->json('upload.uuid');
    $upload = ChunkUpload::query()->where('uuid', $uuid)->firstOrFail();
    p5aSendChunks($token, $visit, $uuid, 'kept bytes', (int) $upload->chunk_bytes);
    app()->bind(ActorResolver::class, fn () => throw new RuntimeException('analytics store down'));

    p5aCall('POST', route('dam.upload_portal.complete', ['token' => $token, 'uuid' => $uuid]), $visit)->assertStatus(202);
    Queue::pushed(FinalizeChunkUpload::class)->first()->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_COMPLETED);
});

it('keys a guest\'s upload as the visitor who sent it, joinable with their portal events', function () {
    Queue::fake();
    [$uploadRequest, $token] = p5aRequest(creator: p5aFullAdmin());
    $visit = p5aVisit($token);
    $browser = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Firefox/131.0', 'REMOTE_ADDR' => '203.0.113.9'];
    $uuid = p5aInit($token, $visit, p5aFile('mine.txt', 'my bytes'))->json('upload.uuid');
    $upload = ChunkUpload::query()->where('uuid', $uuid)->firstOrFail();
    p5aSendChunks($token, $visit, $uuid, 'my bytes', (int) $upload->chunk_bytes);
    p5aCall('POST', route('dam.upload_portal.complete', ['token' => $token, 'uuid' => $uuid]), $visit, server: $browser)->assertStatus(202);

    Queue::pushed(FinalizeChunkUpload::class)->first()->handle(app(ChunkUploadService::class));

    $sender = app(ActorResolver::class)->forLinkVisitor(shareId: -(int) $uploadRequest->id, request: Request::create('/', 'GET', server: $browser));
    $anyone = app(ActorResolver::class)->forLinkVisitor(shareId: -(int) $uploadRequest->id, request: null);
    $keys = collect(expectOneUpload(path: 'portal', terminal: 'upload.file.succeeded'))->pluck(2)->pluck('actor_key')->unique()->all();
    expect($keys)->toBe([$sender->key])->and($sender->key)->not->toBe($anyone->key);
});

it('records a direct upload, and a refused one as failed', function () {
    $this->loginAsAdmin();
    $directory = Directory::factory()->create();

    $this->post(route('admin.dam.assets.upload'), [
        'directory_id' => $directory->id,
        'files'        => [UploadedFile::fake()->createWithContent('direct.txt', 'direct')],
    ])->assertCreated();
    expectOneUpload(path: 'direct', terminal: 'upload.file.succeeded');

    DB::table('dam_events')->delete();
    $this->post(route('admin.dam.assets.upload'), [
        'directory_id' => $directory->id,
        'files'        => [UploadedFile::fake()->createWithContent('run.sh', 'echo')],
    ]);
    $events = expectOneUpload(path: 'direct', terminal: 'upload.file.failed');
    expect($events[1][1]['error_code'])->toBe('forbidden_type');
});

it('records each file of a folder upload, a skipped one as failed', function () {
    $this->loginAsAdmin();
    $directory = Directory::factory()->create();

    $this->post(route('admin.dam.assets.upload_folder'), [
        'directory_id'   => $directory->id,
        'files'          => [UploadedFile::fake()->createWithContent('a.txt', 'alpha'), UploadedFile::fake()->createWithContent('run.sh', 'echo')],
        'relative_paths' => ['Shoot/a.txt', 'Shoot/run.sh'],
    ])->assertCreated();

    $uploads = array_values(uploadEventsOn('folder'));
    expect($uploads)->toHaveCount(2)
        ->and(array_column($uploads[0], 0))->toBe(['upload.file.attempted', 'upload.file.failed'])
        ->and($uploads[0][1][1]['error_code'])->toBe('forbidden_type')
        ->and(array_column($uploads[1], 0))->toBe(['upload.file.attempted', 'upload.file.succeeded']);
});

it('records a re-upload', function () {
    $this->loginAsAdmin();
    $directory = Directory::factory()->create();
    $assetId = $this->post(route('admin.dam.assets.upload'), [
        'directory_id' => $directory->id,
        'files'        => [UploadedFile::fake()->createWithContent('first.txt', 'first')],
    ])->assertCreated()->json('files.0.id');

    $this->post(route('admin.dam.assets.re_upload'), [
        'asset_id' => $assetId,
        'file'     => UploadedFile::fake()->createWithContent('second.txt', 'second'),
    ])->assertCreated();

    expectOneUpload(path: 'reupload', terminal: 'upload.file.succeeded');
});

it('records a Hub resource upload, and a refused one as failed with its reason', function () {
    hubEnable();
    $this->actingAs(hubResourceAdmin(), 'admin');

    $this->postJson(route('admin.dam.hub.resources.store'), ['file' => UploadedFile::fake()->createWithContent('DejaVu.ttf', hubResourceDejaVu())])->assertCreated();
    expectOneUpload(path: 'hub', terminal: 'upload.file.succeeded');

    DB::table('dam_events')->delete();
    $this->postJson(route('admin.dam.hub.resources.store'), ['file' => UploadedFile::fake()->createWithContent('notes.ttf', 'not a font')])->assertUnprocessable();
    $events = expectOneUpload(path: 'hub', terminal: 'upload.file.failed');
    expect($events[1][1]['error_code'])->not->toBeEmpty();
});

it('records an API upload and re-upload as the key owner\'s, never as a bot', function () {
    $headers = $this->getAuthenticationHeaders();
    $directory = Directory::factory()->create();

    $assetId = $this->withHeaders($headers + ['User-Agent' => 'python-requests/2.32'])->post(route('admin.api.dam.assets.upload'), [
        'directory_id' => $directory->id,
        'files'        => [UploadedFile::fake()->createWithContent('api.txt', 'api')],
    ])->assertCreated()->json('files.0.id');
    $events = expectOneUpload(path: 'api', terminal: 'upload.file.succeeded');
    expect($events[0][2]->actor_type)->toBe('admin')->and((bool) $events[0][2]->suspected_bot)->toBeFalse();

    DB::table('dam_events')->delete();
    $this->withHeaders($headers)->post(route('admin.api.dam.assets.reUpload'), [
        'asset_id' => $assetId,
        'file'     => UploadedFile::fake()->createWithContent('api-2.txt', 'api again'),
    ])->assertCreated();
    expectOneUpload(path: 'api', terminal: 'upload.file.succeeded');
});

it('keeps an API upload sent with a privacy signal count-only', function (string $header) {
    $headers = $this->getAuthenticationHeaders();

    $this->withHeaders($headers + [$header => '1'])->post(route('admin.api.dam.assets.upload'), [
        'directory_id' => Directory::factory()->create()->id,
        'files'        => [UploadedFile::fake()->createWithContent('quiet.txt', 'quiet')],
    ])->assertCreated();

    expect(uploadEventsOn('api'))->toBe([])
        ->and(DB::table(PosthogOutbox::TABLE)->where('name', 'like', 'upload.file.%')->count())->toBe(0);
})->with(['DNT', 'Sec-GPC']);

it('still uploads through the API when the analytics actor cannot be resolved', function () {
    $headers = $this->getAuthenticationHeaders();
    app()->bind(ActorResolver::class, fn () => throw new RuntimeException('analytics store down'));

    $this->withHeaders($headers)->post(route('admin.api.dam.assets.upload'), [
        'directory_id' => Directory::factory()->create()->id,
        'files'        => [UploadedFile::fake()->createWithContent('kept.txt', 'kept')],
    ])->assertCreated()->assertJsonPath('success', true);
});
