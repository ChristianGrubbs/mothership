<?php

use Illuminate\Process\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mothership\DAM\Services\SharedHiggsfield;
use Mothership\DAM\Support\SharedHiggsfieldError;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->sharedAdmin = Admin::factory()->create();
    $this->actingAs($this->sharedAdmin, 'admin');
    config(['stylepress.import_root' => sys_get_temp_dir().'/stylepress-none-'.Str::uuid()]);
    config([
        'stylepress.higgsfield.driver'          => 'subscription',
        'stylepress.higgsfield.cli_binary'      => '/usr/local/bin/higgsfield',
        'stylepress.higgsfield.cli_config_home' => '/var/lib/mothership',
        'stylepress.higgsfield.workspace_id'    => 'workspace-fixture',
        'stylepress.higgsfield.account_email'   => 'work@example.com',
        'stylepress.higgsfield.api_key_id'      => null,
        'stylepress.higgsfield.api_key_secret'  => null,
    ]);
    Process::preventStrayProcesses();
    Queue::fake();
});

function sharedHiggsfieldFake(?array $job = null, int $exit = 0): void
{
    Process::fake(function ($process) use ($job, $exit) {
        $command = $process->command;
        if (in_array('workspace', $command, true)) {
            return Process::result(output: '[{"id":"workspace-fixture","is_selected":true}]', exitCode: $exit);
        }
        if (in_array('status', $command, true)) {
            return Process::result(output: json_encode(['email' => 'work@example.com', 'credits' => 100, 'subscription_plan_type' => 'scale']), exitCode: $exit);
        }
        if (in_array('cost', $command, true)) {
            return Process::result(output: '{"credits":6.5}', exitCode: $exit);
        }
        if (in_array('create', $command, true)) {
            // CLI 1.1.20 returns a JSON array of ID strings without --wait.
            return Process::result(output: json_encode($job ?? ['provider-job']), exitCode: $exit);
        }

        return Process::result(output: json_encode($job ?? ['id' => 'provider-job', 'status' => 'queued']), errorOutput: 'Provider internal diagnostic', exitCode: $exit);
    });
}

it('connects multiple Mothership accounts to the shared subscription without API keys', function () {
    sharedHiggsfieldFake();
    foreach ([$this->sharedAdmin, Admin::factory()->create()] as $admin) {
        $this->actingAs($admin, 'admin');
        $this->get('/admin/home/tools/stylepress/index.html')->assertOk()->assertSee('data-connected="1"', false);
        $this->getJson('/admin/home/stylepress/balance')->assertOk()->assertJsonPath('balance.credits', 100);
    }
});

it('submits a subscription job and restricts polling to its Mothership owner', function (array $body) {
    sharedHiggsfieldFake($body);
    $catalog = $this->getJson('/admin/home/stylepress/catalog')->json();
    $response = $this->postJson('/admin/home/stylepress/generate', [
        'style_slug'   => $catalog['presets'][0]['slug'],
        'prompt'       => 'A red cube; $(touch /tmp/never-run)',
        'aspect_ratio' => '3:4',
    ])->assertStatus(202)->assertJsonPath('estimate.credits', 6.5)->assertJsonPath('estimate.aspect_ratio', '3:4')->assertJsonPath('aspect_ratio', '3:4');
    Process::assertRan(function ($process) {
        $command = $process->command;

        return is_array($command) && in_array('create', $command, true)
            && str_contains($command[array_search('--prompt', $command, true) + 1], '$(touch /tmp/never-run)')
            && $command[array_search('--aspect_ratio', $command, true) + 1] === '3:4'
            && $process->environment['HOME'] === '/var/lib/mothership';
    });
    $id = $response->json('generation.id');
    $this->assertDatabaseHas('dam_home_generations', ['id' => $id, 'admin_id' => $this->sharedAdmin->id, 'request_id' => 'provider-job']);
    sharedHiggsfieldFake(['id' => 'provider-job', 'status' => 'completed', 'result_url' => 'https://example.com/result.png']);
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertOk()->assertJsonPath('generation.status', 'queued')->assertJsonPath('generation.save_status', 'pending');
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertNotFound();
})->with([
    'CLI ID list'   => [['provider-job']],
    'ID object'     => [['id' => 'provider-job']],
    'job ID object' => [['job_id' => 'provider-job']],
]);

it('keeps provider diagnostics out of the response and allows reconnect without losing queued jobs', function () {
    sharedHiggsfieldFake(exit: 1);
    $this->getJson('/admin/home/stylepress/balance')->assertUnprocessable()->assertDontSee('Provider internal diagnostic');
    $id = (string) Str::uuid();
    DB::table('dam_home_generations')->insert(['id' => $id, 'admin_id' => $this->sharedAdmin->id, 'request_id' => 'provider-job', 'status_url' => 'higgsfield-cli:provider-job', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertStatus(503);
    sharedHiggsfieldFake(['id' => 'provider-job', 'status' => 'completed', 'result_url' => 'https://example.com/reconnected.png']);
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertOk()->assertJsonPath('generation.status', 'completed');
});

it('rejects ambiguous or malformed submission ID lists without saving a job', function (array $body) {
    sharedHiggsfieldFake($body);
    $catalog = $this->getJson('/admin/home/stylepress/catalog')->json();
    $this->postJson('/admin/home/stylepress/generate', [
        'style_slug' => $catalog['presets'][0]['slug'],
        'prompt'     => 'A blue cube',
    ])->assertUnprocessable();
    $this->assertDatabaseCount('dam_home_generations', 0);
})->with([
    'empty list'    => [[]],
    'multiple IDs'  => [['first-job', 'second-job']],
    'nested object' => [[['id' => 'nested-job']]],
    'invalid ID'    => [['invalid/path']],
]);

it('blocks subscription access for roles without asset permission', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->getJson('/admin/home/stylepress/balance')->assertForbidden();
    Process::assertNothingRan();
});

it('refuses a switched work account or billing workspace', function () {
    sharedHiggsfieldFake();
    config(['stylepress.higgsfield.account_email' => 'different@example.com']);
    $this->getJson('/admin/home/stylepress/balance')->assertUnprocessable();
    config(['stylepress.higgsfield.account_email' => 'work@example.com', 'stylepress.higgsfield.workspace_id' => 'different-workspace']);
    $this->getJson('/admin/home/stylepress/balance')->assertUnprocessable();
});

it('reports unknown states and missing completed images as retryable instead of silently queueing forever', function (array $job) {
    sharedHiggsfieldFake($job);
    $id = (string) Str::uuid();
    DB::table('dam_home_generations')->insert(['id' => $id, 'admin_id' => $this->sharedAdmin->id, 'request_id' => 'provider-job', 'status_url' => 'higgsfield-cli:provider-job', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertStatus(503);
    $this->assertDatabaseHas('dam_home_generations', ['id' => $id, 'status' => 'queued']);
})->with([
    'unknown'         => [['status' => 'unexpected-state']],
    'missing image'   => [['status' => 'completed']],
    'non-https image' => [['status' => 'completed', 'result_url' => 'http://example.com/result.png']],
]);

it('reports the effective provider aspect ratio', function (string $ratio, string $expected) {
    sharedHiggsfieldFake();
    $catalog = $this->getJson('/admin/home/stylepress/catalog')->json();
    $this->postJson('/admin/home/stylepress/estimate', ['style_slug' => $catalog['presets'][0]['slug'], 'prompt' => 'A red cube', 'aspect_ratio' => $ratio])
        ->assertOk()->assertJsonPath('estimate.aspect_ratio', $expected);
})->with([['auto', 'auto'], ['16:9', '16:9'], ['3:4', '3:4']]);

it('shares one deadline anchor across account, workspace and create calls instead of resetting the budget on every subprocess', function () {
    // 2026-09-16 regression (SphereSeam lost-generation bug): the deadline used to live in
    // request()->attributes and was force-reset on EVERY subprocess call whenever
    // app()->runningInConsole() was true — which is the whole life of a queue worker, so this
    // ran on every single CLI call a job ever made. Each of `account status`, `workspace list`
    // and `generate create` then got its own fresh nominal budget instead of sharing one window,
    // so three calls that each finish comfortably inside THEIR OWN budget could together run long
    // enough in real time to blow past the job's own timeout — a SIGKILL mid `generate create`
    // with started_at set and no provider_request_id ever written: a real, unrecoverable charge.
    // Each sleep below is trivial against the 25s nominal budget account status/workspace list
    // actually run with; their SUM is not trivial against the 1s budget `create` is given here,
    // which only a genuinely shared, monotonic deadline would enforce.
    Process::fake(function ($process) {
        $command = $process->command;
        if (in_array('workspace', $command, true)) {
            usleep(700_000);

            return Process::result(output: '[{"id":"workspace-fixture","is_selected":true}]');
        }
        if (in_array('status', $command, true)) {
            usleep(700_000);

            return Process::result(output: json_encode(['email' => 'work@example.com', 'credits' => 100]));
        }

        // generate create — reached only after both calls above, ~1.4s of real elapsed time in.
        return Process::result(output: '["provider-job"]');
    });

    $shared = app(SharedHiggsfield::class);

    try {
        $shared->submit(prompt: 'A red cube', aspectRatio: '1:1', references: [], timeoutSeconds: 1);
        $this->fail('Expected the shared 1s deadline to have been exhausted by the two prior calls.');
    } catch (SharedHiggsfieldError $error) {
        expect($error->reason)->toBe('cli_budget_exhausted')
            ->and($error->operation)->toBe('generate.create');
    }
    // A genuinely fresh instance getting its own anchor (what a new job attempt or HTTP request
    // actually experiences, via Laravel's own scoped-binding reset between them) is covered by
    // real-worker-lifecycle coverage in SharedHiggsfieldQueueLifecycleTest, not simulated here by
    // hand — this class is not itself responsible for deciding when that boundary occurs.
});

it('carries the remaining shared budget into `generate get`, not a fresh one — the poll half of the same job-level chain', function () {
    // Companion to the test above, covering the call the previous one never reached: exactly like
    // GenerateSphereSeamBridge::handle(), which calls submit() then poll() on the SAME
    // SharedHiggsfield instance within one job attempt (SphereSeamHiggsfield holds one $shared for
    // its whole lifetime). `poll()` never lets a caller override its budget — it always asks
    // run() for the PLAIN_BUDGET_SECONDS (25s) default — so the only way its ACTUAL timeout
    // ceiling can come in under 25 is if it is drawing down the SAME anchor `create` already
    // spent real time against, not starting a fresh window of its own.
    $getTimeout = null;
    Process::fake(function ($process) use (&$getTimeout) {
        $command = $process->command;
        if (in_array('workspace', $command, true)) {
            return Process::result(output: '[{"id":"workspace-fixture","is_selected":true}]');
        }
        if (in_array('status', $command, true)) {
            return Process::result(output: json_encode(['email' => 'work@example.com', 'credits' => 100]));
        }
        if (in_array('create', $command, true)) {
            // Well over 1s so that even after ceil() rounding, what generate get computes as
            // remaining must be strictly below the fresh 25s default it would otherwise ask for.
            usleep(2_000_000);

            return Process::result(output: '["provider-job"]');
        }

        // generate get.
        $getTimeout = $process->timeout;

        return Process::result(output: '{"status":"in_progress"}');
    });

    $shared = app(SharedHiggsfield::class);
    // A generous submission budget: this test is about what `get` receives afterward, not about
    // exhausting `create`.
    $requestId = $shared->submit(prompt: 'A red cube', aspectRatio: '1:1', references: [], timeoutSeconds: 90);
    $shared->poll($requestId);

    expect($getTimeout)->not->toBeNull()
        ->and($getTimeout)->toBeLessThan(SharedHiggsfield::PLAIN_BUDGET_SECONDS);
});

it('persists terminal subscription states without polling them again', function (string $status) {
    sharedHiggsfieldFake(['status' => $status]);
    $id = (string) Str::uuid();
    DB::table('dam_home_generations')->insert(['id' => $id, 'admin_id' => $this->sharedAdmin->id, 'request_id' => 'provider-job', 'status_url' => 'higgsfield-cli:provider-job', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertOk()->assertJsonPath('generation.status', $status);
    Process::swap(new Factory);
    Process::preventStrayProcesses();
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertOk()->assertJsonPath('generation.status', $status);
    Process::assertNothingRan();
})->with(['failed', 'nsfw', 'canceled']);
