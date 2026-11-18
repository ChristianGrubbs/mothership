<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\FinalizeChunkUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Models\UploadSubmission;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Services\UploadRequests\PortalGate;
use Mothership\DAM\Services\UploadRequests\UploadRequestService;
use Mothership\User\Models\Admin;

/*
 * P5a (D1): the guest upload portal at /u/{token}: one answer for unknown, closed and expired links, the visit, the
 * password, the limits at chunk init and at finalize, server placement, the rate limit and the token rules.
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake(Directory::getAssetDisk());
    config(['dam.explorer.upload.chunk_bytes' => 4]);
});

it('opens the portal with a write-only page and a visit cookie scoped to the link', function () {
    [$request, $token] = p5aRequest(options: ['instructions' => 'Send the RAW files, please.']);
    $existing = Asset::factory()->create(['file_name' => 'already-in-folder.jpg']);
    $existing->directories()->attach($request->directory_id);

    $response = test()->call('GET', route('dam.upload_portal.show', ['token' => $token]));
    $cookie = $response->getCookie(PortalGate::COOKIE, decrypt: false);

    $response->assertOk()
        ->assertSee('Event photos')
        ->assertSee('Send the RAW files, please.')
        ->assertSee('data-dam-portal-privacy', false)
        ->assertDontSee('already-in-folder.jpg')
        ->assertDontSee($request->directory->name)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    expect($cookie)->not->toBeNull()
        ->and($cookie->getPath())->toBe('/u/'.$token)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers an unknown, a closed and an expired link with the same status and body, page and JSON alike', function () {
    [$closed, $closedToken] = p5aRequest();
    [$expired, $expiredToken] = p5aRequest();
    $closedVisit = p5aVisit($closedToken);
    $expiredVisit = p5aVisit($expiredToken);
    $closed->forceFill(['status' => UploadRequest::STATUS_CLOSED, 'closed_at' => now()])->save();
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    $unknownToken = str_repeat('Z', 40);

    $pages = collect([$unknownToken, $closedToken, $expiredToken])
        ->map(fn (string $token) => test()->call('GET', route('dam.upload_portal.show', ['token' => $token])));
    $inits = collect([[$unknownToken, $closedVisit], [$closedToken, $closedVisit], [$expiredToken, $expiredVisit]])
        ->map(fn (array $pair) => p5aInit($pair[0], $pair[1], p5aFile('a.txt', 'abcd')));

    foreach ($pages as $page) {
        expect($page->getStatusCode())->toBe(410)
            ->and($page->getContent())->toBe($pages[0]->getContent())
            ->and($page->getCookie(PortalGate::COOKIE))->toBeNull();
    }

    foreach ($inits as $init) {
        expect($init->getStatusCode())->toBe(410)
            ->and($init->getContent())->toBe($inits[0]->getContent())
            ->and($init->json('code'))->toBe('request_unavailable');
    }

    expect(ChunkUpload::query()->whereNotNull('upload_submission_id')->count())->toBe(0);
});

it('sends a file into the intake folder, pending and private, owned by the request creator and marked as a guest upload', function () {
    $creator = p5aFullAdmin();
    [$request, $token] = p5aRequest(creator: $creator);
    $visit = p5aVisit($token);

    $upload = p5aUpload($token, $visit, 'notes.txt', 'abcdefghij', 'text/plain');
    $asset = Asset::query()->findOrFail($upload->asset_id);
    $status = p5aCall('GET', route('dam.upload_portal.status', ['token' => $token, 'uuid' => $upload->uuid]), $visit)->assertOk();

    expect($upload->state)->toBe(ChunkUpload::STATE_COMPLETED)
        ->and($asset->availability)->toBe(Asset::AVAILABILITY_PENDING_REVIEW)
        ->and((int) $asset->created_by)->toBe((int) $creator->id)
        ->and((int) $asset->upload_submission_id)->toBe((int) UploadSubmission::query()->where('upload_request_id', $request->id)->value('id'))
        ->and($asset->directories()->pluck('dam_directories.id')->all())->toBe([(int) $request->directory_id])
        ->and($asset->needs_sorting_at)->toBeNull()
        ->and(UploadSubmission::query()->where('upload_request_id', $request->id)->value('file_count'))->toBe(1)
        // The guest learns progress only: never the asset id, where it landed or a duplicate notice.
        ->and(array_keys($status->json('upload')))->not->toContain('asset_id', 'asset_file_id', 'duplicate', 'directory_id')
        ->and(DB::table(SecurityEventRecorder::TABLE)->where('event', SecurityEvent::GUEST_SUBMISSION_CREATED)->count())->toBe(1);

    // The page lists this visit's names, and a new visit (no cookie) starts with none.
    test()->call('GET', route('dam.upload_portal.show', ['token' => $token]), [], [PortalGate::COOKIE => p5aBrowserCookie($visit)])
        ->assertOk()->assertSee('notes.txt');
    test()->call('GET', route('dam.upload_portal.show', ['token' => $token]))->assertOk()->assertDontSee('notes.txt');
});

it('refuses a client-chosen folder, path or existing asset, and records the attempt', function (string $field, mixed $value) {
    [$request, $token] = p5aRequest();
    $visit = p5aVisit($token);
    $elsewhere = Directory::create(['name' => 'Elsewhere', 'parent_id' => null]);
    $existing = Asset::factory()->create();
    $value = match ($value) {
        'folder' => $elsewhere->id, 'asset' => $existing->id, default => $value
    };

    p5aInit($token, $visit, p5aFile('a.txt', 'abcd', extra: [$field => $value]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'placement_not_allowed');

    expect(ChunkUpload::query()->whereNotNull('upload_submission_id')->count())->toBe(0)
        ->and(DB::table(SecurityEventRecorder::TABLE)->where('event', SecurityEvent::GUEST_TOKEN_MISUSE)->count())->toBe(1);
})->with([
    'directory_id'      => ['directory_id', 'folder'],
    'relative_path'     => ['relative_path', 'Shoot/Clips/a.txt'],
    'existing_asset_id' => ['existing_asset_id', 'asset'],
    'existing_asset'    => ['existing_asset', 'asset'],
    'asset_id'          => ['asset_id', 'asset'],
]);

it('enforces the count, size and type limits at chunk init', function () {
    [$request, $token] = p5aRequest(options: ['max_files' => 1, 'max_bytes_per_file' => 8, 'allowed_types' => ['txt', 'image/*']]);
    $visit = p5aVisit($token);

    p5aInit($token, $visit, p5aFile('big.txt', str_repeat('x', 9)))->assertStatus(413)->assertJsonPath('code', 'file_too_large');
    p5aInit($token, $visit, p5aFile('run.exe', 'abcd', 'application/x-msdownload'))->assertStatus(415)->assertJsonPath('code', 'type_not_allowed');
    p5aInit($token, $visit, p5aFile('photo.heic', 'abcd', 'image/heic'))->assertCreated();
    p5aInit($token, p5aVisit($token), p5aFile('second.txt', 'efgh'))->assertStatus(409)->assertJsonPath('code', 'request_full');
});

it('enforces the limits again at finalize, against the request as it is then and the type read from the bytes', function () {
    // Declared text, but the bytes are a PNG: the sniffed type is not allowed.
    [$typed, $typedToken] = p5aRequest(options: ['allowed_types' => ['text/plain']]);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $sniffed = p5aUpload($typedToken, p5aVisit($typedToken), 'notes.txt', $png, 'text/plain');

    // Within the size limit at init, then the limit is lowered before the file finishes.
    [$sized, $sizedToken] = p5aRequest(options: ['max_bytes_per_file' => 64]);
    $visit = p5aVisit($sizedToken);
    $uuid = p5aInit($sizedToken, $visit, p5aFile('a.txt', 'abcdefgh'))->assertCreated()->json('upload.uuid');
    p5aSendChunks($sizedToken, $visit, $uuid, 'abcdefgh', 4);
    $sized->forceFill(['max_bytes_per_file' => 4])->save();
    p5aCall('POST', route('dam.upload_portal.complete', ['token' => $sizedToken, 'uuid' => $uuid]), $visit)->assertStatus(202);
    (new FinalizeChunkUpload(ChunkUpload::where('uuid', $uuid)->value('id')))->handle(app(ChunkUploadService::class));

    // Closed while the file was on its way.
    [$closing, $closingToken] = p5aRequest();
    $closingVisit = p5aVisit($closingToken);
    $closingUuid = p5aInit($closingToken, $closingVisit, p5aFile('b.txt', 'abcd'))->assertCreated()->json('upload.uuid');
    p5aSendChunks($closingToken, $closingVisit, $closingUuid, 'abcd', 4);
    p5aCall('POST', route('dam.upload_portal.complete', ['token' => $closingToken, 'uuid' => $closingUuid]), $closingVisit)->assertStatus(202);
    $closing->forceFill(['status' => UploadRequest::STATUS_CLOSED])->save();
    (new FinalizeChunkUpload(ChunkUpload::where('uuid', $closingUuid)->value('id')))->handle(app(ChunkUploadService::class));

    expect($sniffed->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($sniffed->failure_code)->toBe('type_not_allowed')
        ->and(ChunkUpload::where('uuid', $uuid)->first())->state->toBe(ChunkUpload::STATE_FAILED)->failure_code->toBe('file_too_large')
        ->and(ChunkUpload::where('uuid', $closingUuid)->first())->state->toBe(ChunkUpload::STATE_FAILED)->failure_code->toBe('request_unavailable')
        ->and(Asset::query()->whereNotNull('upload_submission_id')->count())->toBe(0);
});

it('counts max_files across every visit and lets only the last free place be taken at finalize', function () {
    [$request, $token] = p5aRequest(options: ['max_files' => 2]);
    $first = p5aVisit($token);
    $second = p5aVisit($token);

    $a = p5aInit($token, $first, p5aFile('a.txt', 'abcd'))->assertCreated()->json('upload.uuid');
    $b = p5aInit($token, $second, p5aFile('b.txt', 'efgh'))->assertCreated()->json('upload.uuid');
    p5aInit($token, $second, p5aFile('c.txt', 'ijkl'))->assertStatus(409);

    // The first is cancelled, its place freed, and a third file takes it.
    p5aCall('DELETE', route('dam.upload_portal.cancel', ['token' => $token, 'uuid' => $a]), $first)->assertOk();
    p5aInit($token, $first, p5aFile('c.txt', 'ijkl'))->assertCreated();

    expect(ChunkUpload::where('uuid', $b)->value('state'))->toBe(ChunkUpload::STATE_RECEIVING);
});

it('binds every chunk call to the visit that started the upload', function () {
    [$request, $token] = p5aRequest();
    [$other, $otherToken] = p5aRequest();
    $mine = p5aVisit($token);
    $theirs = p5aVisit($token);
    $uuid = p5aInit($token, $mine, p5aFile('a.txt', 'abcdefgh'))->assertCreated()->json('upload.uuid');
    $missing = (string) Str::uuid();

    $statusAsOther = p5aCall('GET', route('dam.upload_portal.status', ['token' => $token, 'uuid' => $uuid]), $theirs);
    $statusMissing = p5aCall('GET', route('dam.upload_portal.status', ['token' => $token, 'uuid' => $missing]), $theirs);

    expect($statusAsOther->getStatusCode())->toBe(404)
        ->and($statusAsOther->getContent())->toBe($statusMissing->getContent());

    p5aCall('DELETE', route('dam.upload_portal.cancel', ['token' => $token, 'uuid' => $uuid]), $theirs)->assertNotFound();
    p5aCall('POST', route('dam.upload_portal.complete', ['token' => $token, 'uuid' => $uuid]), $theirs)->assertNotFound();
    // No visit at all, or this link's visit on another link: no upload is reached.
    p5aCall('GET', route('dam.upload_portal.status', ['token' => $token, 'uuid' => $uuid]), null)->assertStatus(401)->assertJsonPath('code', 'visit_required');
    p5aCall('GET', route('dam.upload_portal.status', ['token' => $otherToken, 'uuid' => $uuid]), $mine)->assertStatus(401);
    p5aCall('GET', route('dam.upload_portal.status', ['token' => $otherToken, 'uuid' => $uuid]), p5aVisit($otherToken))->assertNotFound();
    // The staff chunk routes never answer for a guest upload, even to a full admin.
    $this->loginAsAdmin();
    $this->getJson(route('admin.dam.assets.chunk-upload.status', $uuid))->assertNotFound();

    expect(ChunkUpload::where('uuid', $uuid)->value('state'))->toBe(ChunkUpload::STATE_RECEIVING);
});

it('asks for the password before it issues a visit, and locks out after repeated wrong guesses', function () {
    [$request, $token] = p5aRequest(options: ['password' => p5aPassword()]);

    $prompt = test()->call('GET', route('dam.upload_portal.show', ['token' => $token]));
    $prompt->assertStatus(401)->assertDontSee('Event photos')->assertSee('data-dam-portal-password', false);
    expect($prompt->getCookie(PortalGate::COOKIE))->toBeNull();

    // Without a visit, the chunk routes take nothing.
    p5aInit($token, 'forged.'.now()->addHour()->getTimestamp().'.sig', p5aFile('a.txt', 'abcd'))->assertStatus(401);

    $wrong = test()->call('POST', route('dam.upload_portal.unlock', ['token' => $token]), ['password' => 'not-'.p5aPassword()]);
    $wrong->assertStatus(401);
    expect($wrong->getCookie(PortalGate::COOKIE))->toBeNull();

    $right = test()->call('POST', route('dam.upload_portal.unlock', ['token' => $token]), ['password' => p5aPassword()]);
    $right->assertRedirect(route('dam.upload_portal.show', ['token' => $token]));
    $visit = $right->getCookie(PortalGate::COOKIE)->getValue();
    p5aInit($token, $visit, p5aFile('a.txt', 'abcd'))->assertCreated();

    foreach (range(1, PortalGate::ATTEMPTS_PER_VISITOR) as $attempt) {
        test()->call('POST', route('dam.upload_portal.unlock', ['token' => $token]), ['password' => 'wrong-'.$attempt]);
    }

    test()->call('POST', route('dam.upload_portal.unlock', ['token' => $token]), ['password' => p5aPassword()])
        ->assertStatus(429)->assertSee('data-dam-portal-password-error="locked"', false);
});

it('ends every visit begun before a password change', function () {
    [$request, $token] = p5aRequest();
    $visit = p5aVisit($token);
    p5aInit($token, $visit, p5aFile('a.txt', 'abcd'))->assertCreated();

    $request->forceFill(['password_hash' => Hash::make(p5aPassword())])->save();

    p5aInit($token, $visit, p5aFile('b.txt', 'efgh'))->assertStatus(401);
});

it('throttles the portal per IP and per link', function () {
    config(['dam.upload_requests.rate.portal_per_ip' => 3, 'dam.upload_requests.rate.chunk_per_ip' => 2]);
    [$request, $token] = p5aRequest();
    $visit = p5aVisit($token);

    foreach (range(1, 2) as $i) {
        test()->call('GET', route('dam.upload_portal.show', ['token' => $token]))->assertOk();
    }

    test()->call('GET', route('dam.upload_portal.show', ['token' => $token]))->assertStatus(429);

    foreach (range(1, 2) as $i) {
        p5aCall('GET', route('dam.upload_portal.status', ['token' => $token, 'uuid' => (string) Str::uuid()]), $visit)->assertNotFound();
    }

    p5aCall('GET', route('dam.upload_portal.status', ['token' => $token, 'uuid' => (string) Str::uuid()]), $visit)
        ->assertStatus(429)->assertJsonPath('code', 'rate_limited');

    // Per link: another IP still counts against the same link's budget.
    config(['dam.upload_requests.rate.portal_per_ip' => 100, 'dam.upload_requests.rate.portal_per_request' => 3]);
    test()->call('GET', route('dam.upload_portal.show', ['token' => $token]), [], [], [], ['REMOTE_ADDR' => '203.0.113.9'])->assertStatus(429);
});

it('stores the token only hashed and encrypted, and Reissue ends the old link and its visits', function () {
    [$request, $token] = p5aRequest();
    $visit = p5aVisit($token);
    $row = (array) DB::table('dam_upload_requests')->where('id', $request->id)->first();

    expect(collect($row)->filter(fn ($value) => is_string($value) && str_contains($value, $token))->all())->toBe([])
        ->and($row['token_hash'])->toBe(hash('sha256', $token))
        ->and($request->fresh()->urlToken())->toBe($token);

    [, $newToken] = app(UploadRequestService::class)->reissue(actor: Admin::query()->findOrFail($request->created_by), request: $request);

    $old = test()->call('GET', route('dam.upload_portal.show', ['token' => $token]));
    $unknown = test()->call('GET', route('dam.upload_portal.show', ['token' => str_repeat('Q', 40)]));

    expect($old->getStatusCode())->toBe(410)
        ->and($old->getContent())->toBe($unknown->getContent())
        ->and($newToken)->not->toBe($token);

    // A visit signed under the old token does not open the new link either.
    p5aInit($newToken, $visit, p5aFile('a.txt', 'abcd'))->assertStatus(401);
    test()->call('GET', route('dam.upload_portal.show', ['token' => $newToken]))->assertOk();
});

it('shows the link URL only to its creator, the intake folder owner and full admins', function () {
    $folder = Directory::create(['name' => 'Owned intake', 'parent_id' => null]);
    $folderOwner = p7ReviewMember(folders: [$folder], editor: true);
    DB::table('dam_directories')->where('id', $folder->id)->update(['created_by' => $folderOwner->id]);
    $creator = p7ReviewMember(folders: [$folder], editor: true);
    $bystander = p7ReviewMember(folders: [$folder], editor: true);
    [$request, $token] = p5aRequest(folder: $folder->fresh(), creator: $creator);

    $urlFor = function (Admin $admin) {
        $this->actingAs($admin, 'admin');

        return $this->getJson(route('admin.dam.upload-requests.list'))->assertOk()->json('requests.0.url');
    };
    $expected = route('dam.upload_portal.show', ['token' => $token]);

    expect($urlFor($creator))->toBe($expected)
        ->and($urlFor($folderOwner))->toBe($expected)
        ->and($urlFor($bystander))->toBeNull()
        ->and($urlFor(p5aFullAdmin()))->toBe($expected);
});

it('records the funnel events by ids only, and clears guest names and emails after the retention period', function () {
    $log = storage_path('logs/dam-guest-intake.jsonl');
    @unlink($log);
    [$request, $token] = p5aRequest();
    $visit = p5aVisit($token);
    p5aInit($token, $visit, p5aFile('a.txt', 'abcd', extra: ['guest_name' => 'Ada Guest', 'guest_email' => 'ada@example.test']))->assertCreated();

    $events = collect(file($log, FILE_IGNORE_NEW_LINES))->map(fn ($line) => json_decode($line, true));

    expect($events->pluck('event')->all())->toContain('guest.portal.opened', 'guest.submission.created')
        ->and(file_get_contents($log))->not->toContain('Ada Guest')->not->toContain('ada@example.test')->not->toContain($token);

    $submission = UploadSubmission::query()->where('upload_request_id', $request->id)->firstOrFail();
    expect($submission->guest_name)->toBe('Ada Guest');

    $this->artisan('dam:upload-requests:prune')->assertSuccessful();
    expect($submission->fresh()->guest_name)->toBe('Ada Guest');

    $this->travel((int) config('dam.upload_requests.identity_retention_days') + 1)->days();
    $this->artisan('dam:upload-requests:prune')->assertSuccessful();

    expect($submission->fresh())->guest_name->toBeNull()->guest_email->toBeNull()->identity_pruned_at->not->toBeNull()
        ->and($submission->fresh()->file_count)->toBe(0);
});
