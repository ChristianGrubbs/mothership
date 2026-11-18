<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FeedbackReport;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\Feedback\FeedbackAccess;
use Mothership\DAM\Services\Feedback\FeedbackRedactor;
use Mothership\DAM\Services\Feedback\FeedbackStore;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Feedback & bug reports: who may send, read and triage, what a report keeps
 * (and never keeps), and the screenshot's storage.
 */

function feedbackPerson(array $permissions = ['dam', 'dam.asset.view'], string $type = 'user', string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['permission_type' => $permissionType, 'permissions' => $permissions]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1, 'type' => $type]);
}

function feedbackPayload(array $overrides = []): array
{
    return $overrides + [
        'type'        => 'bug',
        'severity'    => 'annoying',
        'title'       => 'Group by stack hides the New button',
        'description' => 'At phone width the New button is pushed off screen.',
        'page_url'    => '/admin/dam?scope=all',
        'route_name'  => 'admin.dam.assets.index',
    ];
}

function feedbackPng(int $width = 40, int $height = 30): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent('shot.png', $bytes);
}

beforeEach(function () {
    config(['dam.feedback.enabled' => true]);
    Storage::fake(Directory::getAssetDisk());
    RateLimiter::clear('feedback');
});

it('lets a normal user send a bug with a screenshot and read it back as their own', function () {
    $person = feedbackPerson();
    $this->actingAs($person, 'admin');

    $response = $this->post(route('admin.dam.feedback.store'), feedbackPayload() + ['screenshot' => feedbackPng()], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('report.status', 'new')
        ->assertJsonPath('report.has_screenshot', true);

    $report = FeedbackReport::query()->findOrFail($response->json('report.id'));
    expect($report->admin_id)->toBe($person->id)
        ->and($report->severity)->toBe('annoying')
        ->and($report->screenshot_file_object_id)->not->toBeNull();

    $this->getJson(route('admin.dam.feedback.mine'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $report->id);
    $this->getJson(route('admin.dam.feedback.show', $report->id))->assertOk()->assertJsonMissingPath('report.reporter');
    $this->get(route('admin.dam.feedback.screenshot', $report->id))->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('keeps severity only on bugs', function () {
    $this->actingAs(feedbackPerson(), 'admin');

    $id = $this->postJson(route('admin.dam.feedback.store'), feedbackPayload(['type' => 'idea', 'severity' => 'blocking']))->assertCreated()->json('report.id');

    expect(FeedbackReport::query()->findOrFail($id)->severity)->toBeNull();
});

it('hides another person\'s report and screenshot as if they did not exist', function () {
    $owner = feedbackPerson();
    $this->actingAs($owner, 'admin');
    $id = $this->post(route('admin.dam.feedback.store'), feedbackPayload() + ['screenshot' => feedbackPng()], ['Accept' => 'application/json'])->json('report.id');

    $this->actingAs(feedbackPerson(), 'admin');
    $this->getJson(route('admin.dam.feedback.show', $id))->assertNotFound();
    $this->get(route('admin.dam.feedback.screenshot', $id))->assertNotFound();
    $this->getJson(route('admin.dam.feedback.mine'))->assertOk()->assertJsonCount(0, 'data');
});

it('lets only triagers change status or add notes, and shows the reporter only visible notes', function () {
    $reporter = feedbackPerson();
    $report = FeedbackReport::query()->create(feedbackPayload() + ['admin_id' => $reporter->id, 'status' => 'new']);

    $this->actingAs($reporter, 'admin');
    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['status' => 'fixed'])->assertForbidden();
    $this->postJson(route('admin.dam.feedback.notes.store', $report->id), ['body' => 'mine'])->assertForbidden();

    $triager = feedbackPerson(['dam', 'dam.feedback.manage']);
    $this->actingAs($triager, 'admin');
    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['status' => 'fixed', 'github_issue_url' => 'https://github.com/example-org/mothership/issues/12'])
        ->assertOk()->assertJsonPath('report.status', 'fixed')->assertJsonPath('report.reporter.id', $reporter->id);
    $this->postJson(route('admin.dam.feedback.notes.store', $report->id), ['body' => 'Internal repro notes'])->assertCreated();
    $this->postJson(route('admin.dam.feedback.notes.store', $report->id), ['body' => 'Fixed in the next release', 'visible_to_reporter' => true])->assertCreated();
    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['github_issue_url' => 'https://evil.example/issues/1'])->assertUnprocessable();

    expect($report->refresh()->resolved_at)->not->toBeNull();

    $this->actingAs($reporter, 'admin');
    $notes = $this->getJson(route('admin.dam.feedback.show', $report->id))->assertOk()->json('report.notes');
    expect(collect($notes)->pluck('body')->filter()->values()->all())->toBe(['Fixed in the next release'])
        ->and(collect($notes)->firstWhere('kind', 'status_change')['to_status'])->toBe('fixed');
});

it('treats an all-permission role as a triager and refuses API accounts everywhere', function () {
    $access = app(FeedbackAccess::class);
    $full = feedbackPerson(permissions: [], permissionType: 'all');
    $robot = feedbackPerson(permissions: [], type: 'api', permissionType: 'all');

    expect($access->canManage($full))->toBeTrue()
        ->and($access->canSubmit($robot))->toBeFalse()
        ->and($access->canManage($robot))->toBeFalse()
        ->and($access->canManage(feedbackPerson()))->toBeFalse();

    $this->actingAs($robot, 'admin');
    $this->postJson(route('admin.dam.feedback.store'), feedbackPayload())->assertForbidden();
});

it('answers 404 while the feature is off', function () {
    config(['dam.feedback.enabled' => false]);
    $this->actingAs(feedbackPerson(), 'admin');

    $this->postJson(route('admin.dam.feedback.store'), feedbackPayload())->assertNotFound();
    $this->getJson(route('admin.dam.feedback.mine'))->assertNotFound();
});

it('refuses a screenshot that is not a PNG, JPEG or WebP image', function () {
    $this->actingAs(feedbackPerson(), 'admin');

    $this->post(route('admin.dam.feedback.store'), feedbackPayload() + ['screenshot' => UploadedFile::fake()->createWithContent('shot.png', '<svg xmlns="http://www.w3.org/2000/svg"/>')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('screenshot');

    expect(FeedbackReport::query()->count())->toBe(0);
});

it('strips secrets from the page URL and console lines and keeps only allowlisted context', function () {
    $this->actingAs(feedbackPerson(), 'admin');
    $context = [
        'browser'  => 'Chrome 141',
        'viewport' => '1440x900',
        'cookie'   => 'laravel_session=abc',
        'console'  => [
            ['ts' => '2026-10-20T10:00:00Z', 'kind' => 'error', 'message' => 'GET https://mothership.test/api?token=short1&page=2 failed', 'source' => '/build/app.js?sig=zz', 'line' => 12],
            ['kind' => 'console', 'message' => 'login failed password=hunter2 Authorization: Bearer abc.def.ghi'],
            ['kind' => 'console', 'message' => ['config' => ['headers' => 'x']]],
        ],
    ];

    $id = $this->postJson(route('admin.dam.feedback.store'), feedbackPayload(['page_url' => 'https://mothership.test/admin/dam/share/x?token=abc&scope=all#frag']) + ['context' => json_encode($context)])
        ->assertCreated()->json('report.id');
    $report = FeedbackReport::query()->findOrFail($id);
    $stored = json_encode($report->context);

    expect($report->page_url)->toBe('/admin/dam/share/x?token=[redacted]&scope=all')
        ->and($report->context)->not->toHaveKey('cookie')
        ->and($report->context['browser'])->toBe('Chrome 141')
        ->and($report->context['console'])->toHaveCount(2)
        ->and($stored)->not->toContain('short1')->not->toContain('hunter2')->not->toContain('abc.def.ghi')->not->toContain('sig=zz')
        ->and($report->context['console'][0]['message'])->toContain('page=2')
        ->and($report->context)->toHaveKey('build');
});

it('redacts long opaque tokens and JWTs in free text', function () {
    $redactor = new FeedbackRedactor;

    expect($redactor->console('share abcdefghijklmnopqrstuvwxyz0123456789 done'))->toBe('share [redacted] done')
        ->and($redactor->console('jwt eyJhbGciOi.eyJzdWIiOi.sig here'))->toBe('jwt [redacted] here')
        ->and($redactor->url('/admin/dam?search=cats&api_key=1'))->toBe('/admin/dam?search=cats&api_key=[redacted]');
});

it('masks quoted secrets whole, relative-URL queries, URL credentials and fragments', function () {
    $redactor = new FeedbackRedactor;

    expect($redactor->console('{"password":"two words","token":"abc"}'))->toBe('{"password":"[redacted]","token":"[redacted]"}')
        ->and($redactor->console('{"secret":"a \\"b\\" c, d; e","x":1}'))->toBe('{"secret":"[redacted]","x":1}')
        ->and($redactor->console('GET /callback?code=shortcode&state=shortstate&page=2 failed'))->toBe('GET /callback?code=[redacted]&state=[redacted]&page=2 failed')
        ->and($redactor->console('fetch https://alice:shortpass@example.test/path?x=1#frag failed'))->toBe('fetch https://[redacted]@example.test/path?x=1 failed')
        ->and($redactor->console('GET /api?%74oken=enc&q=ok'))->toBe('GET /api?%74oken=[redacted]&q=ok')
        ->and($redactor->console('see /admin/dam#token=x'))->toBe('see /admin/dam')
        ->and($redactor->console('GET ./callback?code=shortcode#frag failed'))->toBe('GET ./callback?code=[redacted] failed')
        ->and($redactor->console('callback?code=a&state=b'))->toBe('callback?code=[redacted]&state=[redacted]')
        ->and($redactor->console('?code=shortcode'))->toBe('?code=[redacted]')
        ->and($redactor->console('fetch //alice:shortpass@example.test/p?code=c#f failed'))->toBe('fetch //[redacted]@example.test/p?code=[redacted] failed')
        ->and($redactor->console('what? 3/4 done'))->toBe('what? 3/4 done')
        ->and($redactor->console('callback#code=shortsecret'))->toBe('callback')
        ->and($redactor->console('see issue #12'))->toBe('see issue #12');
});

it('masks every pair of a cookie header and encoded secrets under ordinary query keys', function () {
    $redactor = new FeedbackRedactor;
    $opaque = str_repeat('a1', 20);

    expect($redactor->console('Cookie: theme=light; preference=privatevalue'))->toBe('Cookie: [redacted]')
        ->and($redactor->console("set-cookie: a=1; b=2\nnext line"))->toBe("set-cookie: [redacted]\nnext line")
        ->and($redactor->console('GET /admin?q=token%3Dshort-secret&page=2 failed'))->toBe('GET /admin?q=[redacted]&page=2 failed')
        ->and($redactor->console('GET /admin?q=cats%20and%20dogs&page=2'))->toBe('GET /admin?q=cats%20and%20dogs&page=2')
        ->and($redactor->url('/admin?q='.$opaque.'&page=2'))->toBe('/admin?q=[redacted]&page=2')
        ->and($redactor->url('/admin?q=token%3Dshort-secret'))->toBe('/admin?q=[redacted]')
        ->and($redactor->url('/admin?search=cats%20and%20dogs'))->toBe('/admin?search=cats%20and%20dogs');
});

it('stores a console time only when it is a timestamp', function () {
    $context = app(FeedbackStore::class)->context(context: ['console' => [
        ['ts' => 'token=shortsecret', 'kind' => 'error', 'message' => 'error'],
        ['ts' => '2026-10-20T10:00:00.123Z', 'kind' => 'error', 'message' => 'error'],
    ]]);

    expect($context['console'][0])->not->toHaveKey('ts')
        ->and($context['console'][1]['ts'])->toBe('2026-10-20T10:00:00.123Z')
        ->and(json_encode($context))->not->toContain('shortsecret');
});

it('counts the context limit in bytes and caps the whole request', function () {
    $this->actingAs(feedbackPerson(), 'admin');
    // 11,000 three-byte characters: under the limit in characters, over it in bytes.
    $context = json_encode(['browser' => str_repeat('€', 11000)], JSON_UNESCAPED_UNICODE);
    expect(mb_strlen($context))->toBeLessThan(32 * 1024)->and(strlen($context))->toBeGreaterThan(32 * 1024);

    $this->postJson(route('admin.dam.feedback.store'), feedbackPayload() + ['context' => $context])
        ->assertUnprocessable()->assertJsonValidationErrors('context');

    config(['dam.feedback.request_max_bytes' => 1024]);
    $this->withServerVariables(['CONTENT_LENGTH' => '4096'])
        ->post(route('admin.dam.feedback.store'), feedbackPayload() + ['screenshot' => feedbackPng()], ['Accept' => 'application/json'])
        ->assertStatus(413);

    expect(FeedbackReport::query()->count())->toBe(0);
});

it('keeps a screenshot readable when an asset holding the same bytes is deleted for good', function () {
    $this->actingAs(feedbackPerson(), 'admin');
    $id = $this->post(route('admin.dam.feedback.store'), feedbackPayload() + ['screenshot' => feedbackPng()], ['Accept' => 'application/json'])->json('report.id');
    $object = FileObject::query()->findOrFail(FeedbackReport::query()->findOrFail($id)->screenshot_file_object_id);
    $disk = Storage::disk($object->disk);
    $disk->put('assets/Root/shared.bin', 'feedback-shared-asset');
    $asset = Asset::factory()->create(['path' => 'assets/Root/shared.bin', 'file_name' => 'shared.bin']);
    AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => 'sidecar']);

    app(AssetDeletionService::class)->delete(asset: $asset, force: true);
    app(AssetDeletionService::class)->processPendingFileDeletions();

    expect($object->isReferenced())->toBeTrue()
        ->and(FileObject::query()->whereKey($object->id)->exists())->toBeTrue();
    $disk->assertExists($object->path);
    $this->get(route('admin.dam.feedback.screenshot', $id))->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('releases a lone screenshot when the report insert fails, and keeps one another report shares', function () {
    $person = feedbackPerson();
    $store = app(FeedbackStore::class);
    $failing = false;
    Event::listen('eloquent.creating: '.FeedbackReport::class, function () use (&$failing): void {
        if ($failing) {
            throw new RuntimeException('injected insert failure');
        }
    });

    $failing = true;
    expect(fn () => $store->create(reporter: $person, input: feedbackPayload(), screenshot: feedbackPng()))->toThrow(RuntimeException::class, 'injected insert failure')
        ->and(FileObject::query()->count())->toBe(0)
        ->and(Storage::disk(Directory::getAssetDisk())->allFiles())->toBe([]);

    $failing = false;
    $kept = $store->create(reporter: $person, input: feedbackPayload(), screenshot: feedbackPng());
    $object = FileObject::query()->findOrFail($kept->screenshot_file_object_id);

    $failing = true;
    expect(fn () => $store->create(reporter: $person, input: feedbackPayload(), screenshot: feedbackPng()))->toThrow(RuntimeException::class);
    expect(FileObject::query()->whereKey($object->id)->exists())->toBeTrue()
        ->and(Storage::disk($object->disk)->exists($object->path))->toBeTrue();
});

it('keeps the bytes when the caller rolls back after a failed attach released them', function () {
    $person = feedbackPerson();
    $store = app(FeedbackStore::class);
    $kept = $store->create(reporter: $person, input: feedbackPayload(), screenshot: feedbackPng());
    $object = FileObject::query()->findOrFail($kept->screenshot_file_object_id);
    Event::listen('eloquent.creating: '.FeedbackReport::class, fn () => throw new RuntimeException('injected insert failure'));

    try {
        DB::transaction(function () use ($store, $person, $kept): void {
            // The only reference goes, so the failed attach below releases the object, then the caller rolls back.
            FeedbackReport::query()->whereKey($kept->id)->delete();
            $store->create(reporter: $person, input: feedbackPayload(), screenshot: feedbackPng());
        });
    } catch (RuntimeException) {
        // Expected: the injected failure rolls the outer transaction back.
    }

    expect(FeedbackReport::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(FileObject::query()->whereKey($object->id)->exists())->toBeTrue()
        ->and(Storage::disk($object->disk)->exists($object->path))->toBeTrue();
});

it('keeps a deleted reporter\'s reports without a reporter', function () {
    $reporter = feedbackPerson();
    $report = FeedbackReport::query()->create(feedbackPayload() + ['admin_id' => $reporter->id, 'status' => 'new']);

    $reporter->delete();

    expect($report->refresh()->admin_id)->toBeNull();
});

it('rate limits a burst of reports', function () {
    config(['dam.feedback.per_10_minutes' => 2]);
    $this->actingAs(feedbackPerson(), 'admin');

    $this->postJson(route('admin.dam.feedback.store'), feedbackPayload())->assertCreated();
    $this->postJson(route('admin.dam.feedback.store'), feedbackPayload())->assertCreated();
    $this->postJson(route('admin.dam.feedback.store'), feedbackPayload())->assertTooManyRequests();
});
