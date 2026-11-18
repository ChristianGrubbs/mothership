<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\VectorizeImage;
use Mothership\DAM\Vectorization\Allowance;
use Mothership\DAM\Vectorization\Requests;
use Mothership\DAM\Vectorization\Vectorizer;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('private');
    Queue::fake();
    $this->person = Admin::factory()->create();
    $this->actingAs($this->person, 'admin');
    config([
        'vectorization.enabled'          => true,
        'vectorization.owner_admin_id'   => 999999,
        'vectorization.api_id'           => 'x',
        'vectorization.api_secret'       => 'y',
        'dam-home.widgets.vectorization' => [
            'title'      => 'Image Vectorization',
            'kind'       => 'tool',
            'entry'      => 'index.html',
            'permission' => 'dam.assets',
            'sizes'      => ['small', 'wide'],
            'size'       => 'small',
            'settings'   => [],
        ],
    ]);
});

it('reserves once, rejects excess work, and refunds once', function () {
    $allowance = app(Allowance::class);
    $requests = app(Requests::class);
    $allowance->set(adminId: $this->person->id, remaining: 1, revision: 0);
    $requestId = (string) Str::uuid();

    $one = $requests->submit(
        adminId: $this->person->id,
        requestId: $requestId,
        inputPath: 'input/a',
        sha: hash('sha256', 'a'),
    );
    $two = $requests->submit(
        adminId: $this->person->id,
        requestId: $requestId,
        inputPath: 'input/b',
        sha: hash('sha256', 'a'),
    );

    expect($one->id)->toBe($two->id)
        ->and($allowance->read(adminId: $this->person->id))->toMatchArray(['remaining' => 0, 'reserved' => 1]);
    Queue::assertPushed(VectorizeImage::class, 1);
    expect(fn () => $requests->submit(
        adminId: $this->person->id,
        requestId: (string) Str::uuid(),
        inputPath: 'input/c',
        sha: hash('sha256', 'c'),
    ))->toThrow(HttpException::class);

    $requests->finish(id: $one->id, success: false, code: 'provider_unavailable');
    $requests->finish(id: $one->id, success: false, code: 'provider_unavailable');
    expect($allowance->read(adminId: $this->person->id))->toMatchArray(['remaining' => 1, 'reserved' => 0]);
});

it('stores both outputs, serves only the owner, and consumes only once', function () {
    app(Allowance::class)->set(adminId: $this->person->id, remaining: 1, revision: 0);
    $image = UploadedFile::fake()->image('mark.png', 40, 40);
    $response = $this->post(route('admin.home.vectorization.store'), [
        'request_id' => (string) Str::uuid(),
        'image'      => $image,
    ], ['Accept' => 'application/json'])->assertStatus(202);
    $id = $response->json('result.id');

    $client = Mockery::mock(Vectorizer::class);
    $client->shouldReceive('convert')->once()->andReturn([
        'svg' => '<svg viewBox="0 0 1 1"><path d="M0 0H1V1Z"/></svg>',
        'pdf' => "%PDF-1.7\n%%EOF",
    ]);
    app()->instance(Vectorizer::class, $client);

    $job = new VectorizeImage(id: $id);
    $job->handle();
    $job->handle();

    $this->getJson(route('admin.home.vectorization.show', ['id' => $id]))
        ->assertOk()
        ->assertJsonPath('result.status', 'completed');
    foreach (['svg', 'pdf'] as $format) {
        $this->get(route('admin.home.vectorization.file', ['id' => $id, 'format' => $format]))->assertOk();
        $this->get(route('admin.home.vectorization.file', ['id' => $id, 'format' => $format]))->assertOk();
    }
    expect(app(Allowance::class)->read(adminId: $this->person->id))->toMatchArray(['remaining' => 0, 'reserved' => 0]);

    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->getJson(route('admin.home.vectorization.show', ['id' => $id]))->assertNotFound();
    $this->get(route('admin.home.vectorization.file', ['id' => $id, 'format' => 'svg']))->assertNotFound();
});

it('recovers a killed job and prevents late completion from charging', function () {
    $allowance = app(Allowance::class);
    $requests = app(Requests::class);
    $allowance->set(adminId: $this->person->id, remaining: 1, revision: 0);
    $row = $requests->submit(
        adminId: $this->person->id,
        requestId: (string) Str::uuid(),
        inputPath: 'input/a',
        sha: hash('sha256', 'a'),
    );
    DB::table('dam_vectorizations')->where('id', $row->id)->update([
        'status'     => 'processing',
        'updated_at' => now()->subMinutes(20),
    ]);

    $this->artisan('dam:vectorizations:expire')->assertSuccessful();
    $requests->finish(id: $row->id, success: true);

    expect($allowance->read(adminId: $this->person->id)['remaining'])->toBe(1);
    expect(DB::table('dam_vectorizations')->where('id', $row->id)->value('status'))->toBe('failed');
});

it('enqueues in the same database transaction as the allowance', function () {
    Queue::swap(Queue::getFacadeRoot()->queue);
    app(Allowance::class)->set(adminId: $this->person->id, remaining: 1, revision: 0);
    $before = DB::table('jobs')->where('queue', 'vectorization')->count();

    DB::beginTransaction();
    try {
        app(Requests::class)->submit(
            adminId: $this->person->id,
            requestId: (string) Str::uuid(),
            inputPath: 'input/a',
            sha: hash('sha256', 'a'),
        );
        expect(DB::table('jobs')->where('queue', 'vectorization')->count())->toBe($before + 1);
    } finally {
        DB::rollBack();
    }

    expect(DB::table('jobs')->where('queue', 'vectorization')->count())->toBe($before);
    expect(app(Allowance::class)->read(adminId: $this->person->id)['remaining'])->toBe(1);
});

it('rejects request identity reuse with different image bytes', function () {
    app(Allowance::class)->set(adminId: $this->person->id, remaining: 1, revision: 0);
    $requestId = (string) Str::uuid();
    $requests = app(Requests::class);
    $requests->submit(
        adminId: $this->person->id,
        requestId: $requestId,
        inputPath: 'input/a',
        sha: hash('sha256', 'a'),
    );

    try {
        $requests->submit(
            adminId: $this->person->id,
            requestId: $requestId,
            inputPath: 'input/b',
            sha: hash('sha256', 'b'),
        );
        $this->fail('collision accepted');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }

    Queue::assertPushed(VectorizeImage::class, 1);
});

it('refunds a result-storage failure and returns no download URL', function () {
    $allowance = app(Allowance::class);
    $allowance->set(adminId: $this->person->id, remaining: 1, revision: 0);
    $row = app(Requests::class)->submit(
        adminId: $this->person->id,
        requestId: (string) Str::uuid(),
        inputPath: 'input/a',
        sha: hash('sha256', 'a'),
    );
    $client = Mockery::mock(Vectorizer::class);
    $client->shouldReceive('convert')->once()->andReturn(['svg' => 'svg', 'pdf' => 'pdf']);
    app()->instance(Vectorizer::class, $client);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('path')->andReturn('/fixture/input');
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldReceive('deleteDirectory')->andReturn(true);
    $disk->shouldReceive('delete')->andReturn(true);
    Storage::shouldReceive('disk')->with('private')->andReturn($disk);

    (new VectorizeImage(id: $row->id))->handle();

    expect($allowance->read(adminId: $this->person->id)['remaining'])->toBe(1);
    $stored = DB::table('dam_vectorizations')->where('id', $row->id)->first();
    expect(app(Requests::class)->publicResult(row: $stored))->toMatchArray([
        'status'  => 'failed',
        'svg_url' => null,
        'pdf_url' => null,
    ]);
});

it('allows only one competing request to spend the last allowance', function () {
    // tools/testing/vectorization-race.php commits real rows from two processes, so it refuses
    // (exit 2) anything but a PostgreSQL database named mothership_test_*, the disposable name
    // pest_tests.yml gives its PostgreSQL service database. The MySQL and SQLite legs and a
    // local SQLite file do not qualify, and that gate is not to be loosened from here.
    $disposable = str_starts_with(DB::connection()->getDatabaseName(), 'mothership_test_');

    // On CI's PostgreSQL leg a wrong name is workflow drift, not an unsupported environment:
    // skipping there would put back a green gate that never runs the race.
    if (DB::getDriverName() === 'pgsql' && getenv('CI') && ! $disposable) {
        $this->fail('The CI PostgreSQL database must be named mothership_test_* (pest_tests.yml matrix `database`) for the race to run.');
    }

    if (DB::getDriverName() !== 'pgsql' || ! $disposable) {
        $this->markTestSkipped('Needs the disposable PostgreSQL mothership_test_* database the race script insists on.');
    }

    $result = Process::timeout(40)
        ->run(['php', base_path('tools/testing/vectorization-race.php')]);

    expect($result->exitCode())->toBe(0, $result->errorOutput());
    expect($result->output())->toContain('race passed');
});
