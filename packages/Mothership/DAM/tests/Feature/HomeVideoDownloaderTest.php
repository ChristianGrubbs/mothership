<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The Home Video Downloader. The Studio's
 * native video service does the downloading and keeps each person's queue; these tests cover what The Mothership owns:
 * who gets the tool, that the queue owner is always the signed-in account, and what is passed through.
 */

const VIDEO_JOB = 'a3f1c2d4e5f60718293a4b5c6d7e8f90';

beforeEach(function () {
    config(['dam-home.video_api' => 'http://video.test']);
    // A request no test faked must fail here, never leave the machine.
    Http::preventStrayRequests();
    $this->videoAdmin = Admin::factory()->create(['name' => 'Marcus Staff']);
    $this->actingAs($this->videoAdmin, 'admin');
});

it('leaves the tool off Home until the Studio video service is configured', function () {
    config(['dam-home.video_api' => null]);
    Http::fake();

    expect(array_column($this->getJson('/admin/home/layout')->assertOk()->json('catalog'), 'id'))->not->toContain('video-downloader');
    $this->get('/admin/home/tools/video-downloader/index.html')->assertForbidden();
    $this->getJson('/admin/home/video/jobs')->assertForbidden();
    Http::assertNothingSent();
});

it('gives the tool to every signed-in account and lets a role list take it away', function () {
    Http::fake(['video.test/*' => Http::response(['jobs' => []])]);

    $catalog = collect($this->getJson('/admin/home/layout')->assertOk()->json('catalog'))->keyBy('id');
    expect($catalog['video-downloader']['title'])->toBe('Video Downloader')
        ->and($catalog['video-downloader'])->not->toHaveKey('requires_config');
    $this->get('/admin/home/tools/video-downloader/index.html')->assertOk()
        ->assertSee('__MOTHERSHIP_FRAME__', false)->assertSee('Find video');

    // A narrow custom role with no stored list gets the default tools, this one included.
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->getJson('/admin/home/video/jobs')->assertOk();

    // A stored list without it (the full-access right-click menu on Home) removes it for that role.
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["rss"]']);
    $this->getJson('/admin/home/video/jobs')->assertForbidden();
    $this->get('/admin/home/tools/video-downloader/index.html')->assertForbidden();
});

it('requires a signed-in account', function () {
    auth('admin')->logout();
    Http::fake();

    $this->get('/admin/home/video/jobs')->assertRedirect(route('admin.session.create'));
    Http::assertNothingSent();
});

it('looks a link up and refuses anything that is not a web link', function () {
    Http::fake(['video.test/probe' => Http::response(['title' => 'Homecoming highlights', 'choices' => [['id' => 'v720', 'label' => '720p video (MP4)']]])]);

    $this->postJson('/admin/home/video/probe', ['url' => 'https://example.org/watch?v=1'])->assertOk()
        ->assertJsonPath('title', 'Homecoming highlights')->assertJsonPath('choices.0.id', 'v720');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://video.test/probe' && $request['url'] === 'https://example.org/watch?v=1');

    foreach (['file:///etc/passwd', 'example.org/watch', 'https://example.org/a b', ''] as $bad) {
        $this->postJson('/admin/home/video/probe', ['url' => $bad])->assertStatus(422)
            ->assertJsonPath('errors.url.0', 'Paste the full link to a video, starting with http:// or https://.');
    }
    Http::assertSentCount(1);
});

it('queues, lists and removes downloads as the signed-in account and nobody else', function () {
    Http::fake([
        'video.test/jobs/*' => Http::response(['ok' => true]),
        'video.test/jobs*'  => Http::response(['job' => ['id' => VIDEO_JOB, 'status' => 'queued', 'position' => 3], 'jobs' => []], 201),
    ]);
    $owner = (string) $this->videoAdmin->id;

    // An owner sent by the browser is ignored: the queue belongs to whoever is signed in.
    $this->postJson('/admin/home/video/jobs', ['url' => 'https://example.org/v', 'choice' => 'v720', 'title' => 'Homecoming', 'optimize' => true, 'owner' => 999999])
        ->assertStatus(201)->assertJsonPath('job.position', 3);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'http://video.test/jobs'
        && $request['owner'] === $owner && $request['choice'] === 'v720' && $request['title'] === 'Homecoming' && $request['optimize'] === true);

    $this->getJson('/admin/home/video/jobs?owner=999999')->assertStatus(201);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'http://video.test/jobs?owner='.$owner);

    $this->deleteJson('/admin/home/video/jobs/'.VIDEO_JOB)->assertOk();
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'http://video.test/jobs/'.VIDEO_JOB.'?owner='.$owner);

    // Making a finished video smaller is asked of the service for the same owner.
    $this->postJson('/admin/home/video/jobs/'.VIDEO_JOB.'/optimize?owner=999999')->assertOk();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'http://video.test/jobs/'.VIDEO_JOB.'/optimize?owner='.$owner);

    foreach ([['choice' => '--exec'], ['choice' => 'v720; x'], ['url' => 'ftp://example.org/v'], ['optimize' => 'please']] as $bad) {
        $this->postJson('/admin/home/video/jobs', $bad + ['url' => 'https://example.org/v', 'choice' => 'best'])->assertStatus(422);
    }
    $this->deleteJson('/admin/home/video/jobs/not-a-job')->assertNotFound();
    Http::assertSentCount(4);
});

it('passes the service\'s own answers through, including a full backlog and a job that is not yours', function () {
    Http::fake([
        'video.test/jobs/*'  => Http::response(['message' => 'That download is not in your list.'], 404),
        'video.test/files/*' => Http::response(['message' => 'That download is not in your list.'], 404),
        'video.test/jobs*'   => Http::response(['message' => 'You already have 25 downloads waiting. Let some finish, then add more.'], 429),
    ]);

    $this->postJson('/admin/home/video/jobs', ['url' => 'https://example.org/v', 'choice' => 'mp3'])->assertStatus(429)
        ->assertJsonPath('message', 'You already have 25 downloads waiting. Let some finish, then add more.');
    $this->deleteJson('/admin/home/video/jobs/'.VIDEO_JOB)->assertNotFound()->assertJsonPath('message', 'That download is not in your list.');
    $this->getJson(route('admin.home.video.jobs.file', VIDEO_JOB))->assertNotFound()->assertJsonPath('message', 'That download is not in your list.');
});

it('streams a finished file to the browser as an attachment and passes a Range through', function () {
    Http::fake(['video.test/files/*' => Http::response('0123456789', 206, [
        'Content-Type'        => 'video/mp4', 'Content-Length' => '10', 'Content-Range' => 'bytes 0-9/100', 'Accept-Ranges' => 'bytes',
        'Content-Disposition' => 'attachment; filename="Homecoming.mp4"', 'Server' => 'MothershipVideo/1',
    ])]);

    $response = $this->get('/admin/home/video/jobs/'.VIDEO_JOB.'/file', ['Range' => 'bytes=0-9'])->assertStatus(206)
        ->assertHeader('Content-Type', 'video/mp4')->assertHeader('Content-Range', 'bytes 0-9/100')
        ->assertHeader('Content-Disposition', 'attachment; filename="Homecoming.mp4"')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Server');
    expect($response->streamedContent())->toBe('0123456789');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://video.test/files/'.VIDEO_JOB.'?owner='.$this->videoAdmin->id
        && $request->header('Range') === ['bytes=0-9']);
});

it('says the downloader is not answering when the Studio service is down, without losing the queue', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    foreach ([['getJson', '/admin/home/video/jobs', []], ['postJson', '/admin/home/video/probe', ['url' => 'https://example.org/v']], ['getJson', '/admin/home/video/jobs/'.VIDEO_JOB.'/file', []]] as [$method, $path, $body]) {
        $this->{$method}($path, $body)->assertStatus(503)
            ->assertJsonPath('message', 'The video downloader is not answering right now. Your queued downloads are kept; try again in a minute.');
    }
});

it('adds the tool to role lists saved before it existed and leaves other values alone', function () {
    $migration = require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_11_10_180000_add_video_downloader_to_role_home_widgets.php');

    expect($migration::withWidget('["rss","looper"]'))->toBe(['rss', 'looper', 'video-downloader'])
        ->and($migration::withWidget('[]'))->toBe(['video-downloader'])
        ->and($migration::withWidget('["video-downloader","rss"]'))->toBeNull()
        ->and($migration::withWidget('{"a":"rss"}'))->toBeNull()
        ->and($migration::withWidget('{not json'))->toBeNull()
        ->and($migration::withWidget('"rss"'))->toBeNull();

    $saved = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings']]);
    $unset = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings']]);
    DB::table('dam_role_settings')->insert(['role_id' => $saved->id, 'home_widget_ids' => '["rss"]']);
    DB::table('dam_role_settings')->insert(['role_id' => $unset->id, 'home_widget_ids' => null]);

    $migration->up();
    $migration->up();

    expect(json_decode(DB::table('dam_role_settings')->where('role_id', $saved->id)->value('home_widget_ids'), true))->toBe(['rss', 'video-downloader'])
        ->and(DB::table('dam_role_settings')->where('role_id', $unset->id)->value('home_widget_ids'))->toBeNull();
});
