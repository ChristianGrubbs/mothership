<?php

use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\CompleteStylePressGeneration;
use Mothership\SphereSeam\Jobs\GenerateSphereSeamBridge;
use Mothership\SphereSeam\Models\SphereSeamGeneration;
use Mothership\User\Models\Admin;

/**
 * 2026-09-16 review follow-up: SharedHiggsfieldTest and StylePressWidgetTest proved
 * SharedHiggsfield's scoped-binding + hrtime-anchor deadline mechanism with an explicit,
 * hand-called app()->forgetScopedInstances() standing in for "a new job attempt" or "a new HTTP
 * request". That is exactly the manual reset GenerateSphereSeamBridge was told NOT to perform
 * itself (it would evict every other scoped service mid-job, ahead of queue middleware unwinding
 * and post-job listeners, for no benefit this class needs) — the claim being relied on instead is
 * that Laravel's OWN queue worker already resets scoped bindings between job attempts, including
 * a released-and-redispatched one. This file exercises that claim directly: real jobs, serialized
 * onto the real `database` queue connection GenerateSphereSeamBridge and CompleteStylePressGeneration
 * both actually use in production, processed one at a time through Illuminate\Queue\Worker — the
 * same class `php artisan queue:work` runs — rather than intercepted by Queue::fake().
 */
beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->actingAs($this->admin, 'admin');
    config([
        'stylepress.higgsfield.driver'        => 'subscription',
        'stylepress.higgsfield.account_email' => 'work@example.com',
        'stylepress.higgsfield.workspace_id'  => 'work',
        'sphereseam.enabled'                  => true,
        'queue.default'                       => 'database',
    ]);
    Process::preventStrayProcesses();
    Storage::fake('private');
    // Deliberately no Queue::fake(): this file is specifically about behaviour across REAL
    // worker-processed job attempts, which Queue::fake() would intercept before any of it ever
    // reached the queue connection or the worker.
});

function sphereSeamQueueLifecycleFixture(int $adminId): SphereSeamGeneration
{
    $id = (string) Str::uuid();
    Storage::disk('private')->put("sphereseam/{$id}/bridge.png", 'fake-bridge-bytes');
    Storage::disk('private')->put("sphereseam/{$id}/normalized.png", 'fake-normalized-bytes');

    return SphereSeamGeneration::create([
        'id'                 => $id,
        'admin_id'           => $adminId,
        'model'              => 'gpt_image_2_5',
        'bridge_ratio'       => '1:1',
        'resolution'         => '2k',
        'quality'            => 'high',
        'normalized_path'    => "sphereseam/{$id}/normalized.png",
        'bridge_path'        => "sphereseam/{$id}/bridge.png",
        'storage_disk'       => 'private',
        'width'              => 400,
        'height'             => 200,
        'strip_width'        => 100,
        'repair_width'       => 80,
        'feather_width'      => 16,
        'normalization_mode' => 'squeeze',
        'prompt'             => 'Foundry interior',
        'status'             => 'queued',
    ]);
}

it('gives each real worker-processed job attempt a fresh deadline anchor, shares one anchor within an attempt, and never leaks into a different job class on the same queue', function () {
    $timeouts = ['create' => [], 'get' => [], 'style_get' => []];
    Process::fake(function ($p) use (&$timeouts) {
        $command = $p->command;
        if (in_array('workspace', $command, true)) {
            return Process::result(output: '[{"id":"work","is_selected":true}]');
        }
        if (in_array('status', $command, true)) {
            return Process::result(output: '{"email":"work@example.com","credits":100}');
        }
        if (in_array('create', $command, true)) {
            // Real elapsed time this attempt's own `generate get` (below, same attempt) must
            // share the remaining budget of, rather than restarting a fresh one.
            usleep(1_500_000);
            $timeouts['create'][] = $p->timeout;

            return Process::result(output: '["seam-job"]');
        }
        if (in_array('seam-job', $command, true)) {
            $timeouts['get'][] = $p->timeout;

            return Process::result(output: '{"id":"seam-job","status":"in_progress"}');
        }

        // The StylePress completion job's own `generate get`, for a different provider id.
        $timeouts['style_get'][] = $p->timeout;

        return Process::result(output: '{"id":"style-job","status":"in_progress"}');
    });

    $generation = sphereSeamQueueLifecycleFixture($this->admin->id);
    GenerateSphereSeamBridge::dispatch($generation->id);

    // Not app(Worker::class): auto-wiring Worker's constructor directly leaves its required
    // `callable $isDownForMaintenance` (and, on newer Laravel, `$resetScope`) unresolvable —
    // BindingResolutionException, since a plain callable type-hint has nothing for the container
    // to build. QueueServiceProvider registers the correctly-wired instance under the 'queue.worker'
    // container alias, the same one `php artisan queue:work` itself resolves.
    /** @var Worker $worker */
    $worker = app('queue.worker');
    $options = new WorkerOptions(maxTries: 1, sleep: 0);

    // Attempt 1: no provider_request_id yet, so this submits (spending ~1.5s of real time on
    // `create`) and then immediately polls once in the SAME attempt.
    $worker->runNextJob('database', 'stylepress', $options);
    expect($timeouts['create'])->toHaveCount(1)
        ->and($timeouts['get'])->toHaveCount(1);
    expect($timeouts['get'][0])->toBeLessThan(25, 'generate get must share the anchor create already spent real time against, within ONE attempt');

    // Still `generating`/in_progress: GenerateSphereSeamBridge released itself for another poll
    // 15s later. Advance real test time past that delay so the SAME row is picked back up as a
    // genuinely new worker-processed attempt.
    $this->travel(20)->seconds();
    $worker->runNextJob('database', 'stylepress', $options);
    expect($timeouts['get'])->toHaveCount(2);
    // If the worker did not reset the scoped SharedHiggsfield between attempts, this second
    // `generate get` would inherit attempt one's already-exhausted anchor and come back even
    // narrower. It must instead come back close to the full 25s default: a fresh attempt, a
    // fresh window.
    expect($timeouts['get'][1])->toBeGreaterThan(20, 'a released-and-redispatched attempt must start a fresh anchor, not inherit the previous attempt\'s spent one');

    // A different job class, dispatched to the SAME 'stylepress' queue right after: StylePress's
    // completion job also calls SharedHiggsfield (poll(), inside CompleteStylePressGeneration).
    // It must not inherit anything left over from the SphereSeam attempts above either.
    $styleId = (string) Str::uuid();
    DB::table('dam_home_generations')->insert([
        'id'          => $styleId,
        'admin_id'    => $this->admin->id,
        'request_id'  => 'style-job',
        'status_url'  => 'higgsfield-cli:style-job',
        'status'      => 'queued',
        'inputs'      => json_encode(['settings' => [], 'references' => [], 'style' => 'x', 'original_prompt' => 'x']),
        'save_status' => 'pending',
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
    CompleteStylePressGeneration::dispatch($styleId)->onConnection('database')->onQueue('stylepress');
    $worker->runNextJob('database', 'stylepress', $options);
    expect($timeouts['style_get'])->toHaveCount(1);
    expect($timeouts['style_get'][0])->toBeGreaterThan(20, 'a different job class picked up right after must also start a fresh anchor, not inherit anything the SphereSeam attempts above left behind');
});
