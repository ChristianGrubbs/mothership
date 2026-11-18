<?php

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\CompleteStylePressGeneration;
use Mothership\DAM\Services\GenerationPackageImporter;
use Mothership\DAM\Services\GenerationTitler;
use Mothership\DAM\Services\SharedHiggsfield;
use Mothership\DAM\Services\StylePressCatalog;
use Mothership\MagicAI\Models\MagicAIPlatform;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    config(['stylepress.higgsfield.driver' => 'subscription', 'stylepress.higgsfield.account_email' => 'work@example.com', 'stylepress.higgsfield.workspace_id' => 'work']);
    config(['stylepress.import_root' => sys_get_temp_dir().'/stylepress-none-'.Str::uuid()]);
    Queue::fake();
    Process::fake(function ($p) {
        if (in_array('workspace', $p->command)) {
            return Process::result(output: '[{"id":"work","is_selected":true}]');
        }
        if (in_array('status', $p->command)) {
            return Process::result(output: '{"email":"work@example.com","credits":100}');
        }
        if (in_array('cost', $p->command)) {
            return Process::result(output: '{"credits":5}');
        }
        if (in_array('product-photoshoot', $p->command)) {
            return Process::result(output: '{"prompts":[{"prompt":"An illustrated red cube","aspect_ratio":"1:1"}]}');
        }

        return Process::result(output: '["job-fixture"]');
    });
});

it('passes exact output controls and actual reference paths to the provider', function () {
    app(SharedHiggsfield::class)->estimate(prompt: 'A cube', aspectRatio: 'auto', quality: 'low', resolution: '4k', references: ['/tmp/source.png']);
    Process::assertRan(fn ($p) => in_array('cost', $p->command) && in_array('auto', $p->command) && in_array('low', $p->command) && in_array('4k', $p->command) && in_array('/tmp/source.png', $p->command));
});

it('rejects unsupported output ratios without coercion', function () {
    expect(fn () => app(SharedHiggsfield::class)->estimate(prompt: 'A cube', aspectRatio: '4:5'))->toThrow(RuntimeException::class);
});

it('enhances images and text without creating a generation', function () {
    expect(app(SharedHiggsfield::class)->enhance(prompt: 'An illustration', references: ['/tmp/source.png']))->toBe('An illustrated red cube');
    Process::assertRan(fn ($p) => in_array('product-photoshoot', $p->command) && in_array('--enhance-only', $p->command) && in_array('/tmp/source.png', $p->command));
    Process::assertNotRan(fn ($p) => in_array('generate', $p->command));
    Process::assertRan(fn ($p) => in_array('product-photoshoot', $p->command) && $p->timeout > 25 && $p->timeout <= 90);
});

it('allows image upload time while keeping account checks and polling short', function () {
    app(SharedHiggsfield::class)->submit(prompt: 'A cube', aspectRatio: '1:1', references: ['/tmp/source.png']);
    Process::assertRan(fn ($p) => in_array('create', $p->command) && $p->timeout > 25 && $p->timeout <= 90);
    Process::assertRan(fn ($p) => in_array('account', $p->command) && $p->timeout <= 25);
    Process::assertRan(fn ($p) => in_array('workspace', $p->command) && $p->timeout <= 25);
    app(SharedHiggsfield::class)->estimate(prompt: 'A cube', aspectRatio: '1:1', references: ['/tmp/source.png']);
    Process::assertRan(fn ($p) => in_array('cost', $p->command) && $p->timeout > 25 && $p->timeout <= 90);
    app(SharedHiggsfield::class)->estimate(prompt: 'A cube', aspectRatio: '1:1');
    Process::assertRan(fn ($p) => in_array('cost', $p->command) && $p->timeout <= 25);
});

it('consumes one HTTP window across identity checks and repeated enhancements', function () {
    // 2026-09-16 (SphereSeam review follow-up): SharedHiggsfield's shared CLI deadline moved from
    // request()->attributes (keyed by class, force-reset on every call in console mode — the
    // SphereSeam lost-generation bug) to a private instance property anchored on hrtime(true).
    // That only behaves like "one HTTP window" if HomeStylePressController's several separate
    // app(SharedHiggsfield::class) call sites (enabled(), estimate(), submit(), poll(),
    // enhance()...) resolve the SAME instance within one request — which is why SharedHiggsfield
    // is now bound scoped() in DAMServiceProvider. This test proves that binding, then proves the
    // window it protects is real: real elapsed time from the first identity check + enhancement
    // measurably narrows a second, separately-resolved enhancement's budget.
    expect(app(SharedHiggsfield::class))->toBe(app(SharedHiggsfield::class));

    $timeouts = [];
    Process::fake(function ($p) use (&$timeouts) {
        if (in_array('status', $p->command)) {
            return Process::result(output: '{"email":"work@example.com","credits":100}');
        }
        if (in_array('workspace', $p->command)) {
            // Stands in for time an HTTP request has genuinely spent by the time it reaches this
            // identity check — enhance() re-verifies the account on every call, and (like the
            // real product) tests run in console mode, where account()'s own cache is never
            // trusted, so both enhance() calls below pay this cost against the SAME anchor.
            usleep(1_500_000);

            return Process::result(output: '[{"id":"work","is_selected":true}]');
        }
        // product-photoshoot --enhance-only.
        $timeouts[] = $p->timeout;

        return Process::result(output: '{"prompts":[{"prompt":"A pixel console"}]}');
    });

    app(SharedHiggsfield::class)->enhance(prompt: 'A console');
    app(SharedHiggsfield::class)->enhance(prompt: 'Another console');

    expect($timeouts)->toHaveCount(2);
    // Both comfortably inside enhance()'s own 90s nominal budget (verified independently in
    // 'enhances images and text without creating a generation' above)...
    expect($timeouts[0])->toBeLessThan(90)->and($timeouts[1])->toBeLessThan(90);
    // ...but the second, separately-resolved call's ceiling is strictly narrower than the
    // first's: real time already spent keeps drawing down the SAME shared anchor. A fresh (not
    // scoped) instance per resolution — the bug this test guards against — would give both calls
    // an identical ~90s ceiling instead.
    expect($timeouts[1])->toBeLessThan($timeouts[0]);
});

it('gives a second, separate HTTP request its own full deadline window, not the first request\'s leftover budget', function () {
    // Companion to the test above, covering the other half of the scoped() binding's contract:
    // SharedHiggsfield::$deadlineAnchor must span ONE HTTP request (proven above), but must never
    // survive into the next one. In production every real HTTP request is its own PHP process, so
    // this can't leak there at all; the one place it genuinely could is a test (or an Octane
    // worker) that reuses the same $this->app across more than one simulated/real request.
    // Octane resets scoped bindings between requests itself; here that same request boundary is
    // made explicit so this test does not depend on either app teardown or Octane being present.
    $catalog = $this->getJson('/admin/home/stylepress/catalog')->json();
    $slug = $catalog['presets'][0]['slug'];

    $timeouts = [];
    Process::fake(function ($p) use (&$timeouts) {
        if (in_array('status', $p->command)) {
            return Process::result(output: '{"email":"work@example.com","credits":100}');
        }
        if (in_array('workspace', $p->command)) {
            usleep(1_500_000);

            return Process::result(output: '[{"id":"work","is_selected":true}]');
        }
        $timeouts[] = $p->timeout;

        return Process::result(output: '{"prompts":[{"prompt":"A pixel console"}]}');
    });

    $this->postJson('/admin/home/stylepress/enhance', ['style_slug' => $slug, 'prompt' => 'A console'])->assertOk();

    // The request boundary: what a fresh PHP process (every real request) or Octane's own reset
    // hook (a long-lived one) provides for free, made explicit for this single-process test.
    app()->forgetScopedInstances();

    $this->postJson('/admin/home/stylepress/enhance', ['style_slug' => $slug, 'prompt' => 'Another console'])->assertOk();

    expect($timeouts)->toHaveCount(2);
    // The second request's ceiling must not be narrower than the first's (within ceil() rounding
    // tolerance): if the scoped instance had leaked across the boundary, it would inherit the
    // first request's already-spent ~1.5s on top of its own, coming back measurably lower.
    expect($timeouts[1])->toBeGreaterThanOrEqual($timeouts[0] - 1);
    expect($timeouts[0])->toBeGreaterThan(80)->and($timeouts[1])->toBeGreaterThan(80);
});

it('persists generation settings before handing completion to a background worker', function () {
    $catalog = $this->getJson('/admin/home/stylepress/catalog')->json();
    $r = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $catalog['presets'][0]['slug'], 'prompt' => 'A cube', 'aspect_ratio' => 'auto', 'quality' => 'low', 'resolution' => '4k'])->assertAccepted();
    $row = DB::table('dam_home_generations')->where('id', $r->json('generation.id'))->first();
    expect(json_decode($row->inputs, true)['settings'])->toMatchArray(['aspect_ratio' => 'auto', 'quality' => 'low', 'resolution' => '4k']);
    Queue::assertPushed(CompleteStylePressGeneration::class);
    $this->getJson('/admin/home/stylepress/generation/'.$row->id)->assertOk()->assertJsonPath('generation.save_status', 'pending');
});

it('copies uploaded reference bytes, keeps them owned, and snapshots them on submission', function () {
    Storage::fake('local');
    $image = UploadedFile::fake()->image('source.png', 12, 12);
    $reference = $this->post('/admin/home/stylepress/references', ['image' => $image])->assertCreated()->json();
    $this->get($reference['url'])->assertOk();
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $job = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1', 'mode' => 'restyle', 'references' => [$reference['id']]])->assertAccepted();
    $inputs = json_decode(DB::table('dam_home_generations')->where('id', $job->json('generation.id'))->value('inputs'), true);
    expect($inputs['references'][0]['sha256'])->toBe(hash_file('sha256', $image->getRealPath()));
    Process::assertRan(fn ($p) => in_array('create', $p->command) && in_array($inputs['references'][0]['absolute_path'], $p->command));
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->get($reference['url'])->assertNotFound();
    $this->postJson('/admin/home/stylepress/estimate', ['style_slug' => $style, 'prompt' => 'A cube', 'references' => [$reference['id']]])->assertUnprocessable();
});

it('keeps reference-only styles visible but prevents their generation', function () {
    $path = tempnam(sys_get_temp_dir(), 'widget-catalog');
    file_put_contents($path, json_encode(['presets' => [['slug' => 'workflow', 'title' => 'Workflow', 'generatable' => false]], 'categories' => []]));
    config(['stylepress.catalog_path' => $path]);
    try {
        $this->getJson('/admin/home/stylepress/catalog')->assertOk()->assertJsonPath('presets.0.generatable', false);
        $this->postJson('/admin/home/stylepress/generate', ['style_slug' => 'workflow', 'prompt' => 'A cube'])->assertUnprocessable();
        Queue::assertNothingPushed();
        Process::assertNothingRan();
    } finally {
        unlink($path);
    }
});

it('saves a completed provider result without a browser and retries the same package without rendering', function () {
    Storage::fake('local');
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $response = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1'])->assertAccepted();
    $id = $response->json('generation.id');
    DB::table('dam_home_generations')->where('id', $id)->update(['status' => 'completed', 'result_url' => 'https://example.com/output.png']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    $image = UploadedFile::fake()->image('output.png', 8, 8);
    $bytes = file_get_contents($image->getRealPath());
    Http::fake(function ($request, $options) use ($bytes) {
        file_put_contents($options['sink'], $bytes);

        return Http::response($bytes, 200, ['Content-Type' => 'image/png']);
    });
    $importer = Mockery::mock(GenerationPackageImporter::class);
    $importer->shouldReceive('importManifest')->once()->andThrow(new RuntimeException('temporary import outage'));
    $importer->shouldReceive('importManifest')->once()->andReturn(['ok' => true, 'asset_id' => 987, 'action' => 'created']);
    app()->instance(GenerationPackageImporter::class, $importer);
    try {
        (new CompleteStylePressGeneration($id))->handle();
        expect(is_file($root.'/generation.json'))->toBeTrue();
        expect(DB::table('dam_home_generations')->where('id', $id)->value('save_error'))->toContain('RuntimeException: temporary import outage');
        $manifest = json_decode(file_get_contents($root.'/generation.json'), true);
        expect($manifest['output']['sha256'])->toBe(hash('sha256', $bytes));
        expect($manifest['settings']['actual_aspect_ratio'])->toBe('1:1');
        expect($manifest['output'])->toMatchArray(['width' => 8, 'height' => 8]);
        expect(json_decode(file_get_contents($root.'/archive-receipt.json'), true)['status'])->toBe('pending');
        (new CompleteStylePressGeneration($id))->handle();
        (new CompleteStylePressGeneration($id))->handle();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('dam_home_generations', ['id' => $id, 'save_status' => 'saved', 'asset_id' => 987]);
        $this->getJson('/admin/home/stylepress/generation/'.$id)->assertOk()->assertJsonPath('generation.status', 'completed');
        $this->get('/admin/home/stylepress/output/'.$id)->assertOk();
        $outputPath = $root.'/'.$manifest['output']['path'];
        file_put_contents($outputPath, 'changed output');
        $this->get('/admin/home/stylepress/output/'.$id)->assertNotFound();
        $manifest['output']['path'] = '../outside.png';
        file_put_contents($root.'/generation.json', json_encode($manifest));
        $this->get('/admin/home/stylepress/output/'.$id)->assertNotFound();
        file_put_contents($root.'/generation.json', '{invalid');
        $this->get('/admin/home/stylepress/output/'.$id)->assertNotFound();
        unlink($root.'/generation.json');
        $this->get('/admin/home/stylepress/output/'.$id)->assertNotFound();
    } finally {
        File::deleteDirectory($root);
    }
});

it('compiles an accepted enhanced request inside the complete style template', function () {
    $catalog = $this->getJson('/admin/home/stylepress/catalog')->json();
    $style = $catalog['presets'][0];
    $response = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style['slug'], 'prompt' => 'Original brief', 'enhanced_prompt' => 'Edited enhanced brief', 'aspect_ratio' => 'auto', 'quality' => 'low', 'resolution' => '4k'])->assertAccepted();
    expect($response->json('compiled_prompt'))->toContain($style['template'])->toContain('Edited enhanced brief');
    $inputs = json_decode(DB::table('dam_home_generations')->where('id', $response->json('generation.id'))->value('inputs'), true);
    expect($inputs['original_prompt'])->toBe('Original brief');
    expect($inputs['enhanced_prompt'])->toBe('Edited enhanced brief');
});

it('enhances on Groq when configured and falls back to the shared Higgsfield enhancer when Groq fails', function () {
    config(['stylepress.enhance.driver' => 'groq', 'stylepress.groq.api_key' => 'gsk-test']);
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => 'A fast rewritten brief.']]]])
        ->push(['error' => ['message' => 'over capacity']], 503)]);
    $fast = $this->postJson('/admin/home/stylepress/enhance', ['style_slug' => $style, 'prompt' => 'A cube']);
    expect($fast->status())->toBe(200, $fast->getContent());
    $fast->assertJsonPath('enhanced_prompt', 'A fast rewritten brief.')->assertJsonPath('driver', 'groq');
    Process::assertNotRan(fn ($process) => in_array('--enhance-only', $process->command));
    $slow = $this->postJson('/admin/home/stylepress/enhance', ['style_slug' => $style, 'prompt' => 'A cube']);
    expect($slow->status())->toBe(200, $slow->getContent());
    $slow->assertJsonPath('enhanced_prompt', 'An illustrated red cube')->assertJsonPath('driver', 'higgsfield');
    $preview = app(StylePressCatalog::class)->thumbnailPath(slug: $style);
    expect($preview)->not->toBeNull();
    // The Higgsfield enhancer still receives the style preview as an image, exactly as before the Groq path existed.
    Process::assertRan(fn ($process) => in_array('--enhance-only', $process->command) && in_array($preview, $process->command));
    Http::assertSentCount(2);
});

it('routes Groq by the user references only, never by the style preview', function () {
    config(['stylepress.enhance.driver' => 'groq', 'stylepress.groq.api_key' => 'gsk-test']);
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0');
    expect($style['image'] ?? null)->not->toBeNull('the first preset must carry a preview image for this fixture');
    Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'A rewritten brief.']]]])]);
    $this->postJson('/admin/home/stylepress/enhance', ['style_slug' => $style['slug'], 'prompt' => 'A cube'])->assertOk()->assertJsonPath('driver', 'groq');
    Http::assertSent(fn ($request) => $request->data()['model'] === 'openai/gpt-oss-120b' && count($request->data()['messages'][1]['content']) === 1);
    $image = UploadedFile::fake()->image('ref.png', 8, 8);
    $reference = $this->post('/admin/home/stylepress/references', ['image' => $image])->assertCreated()->json('id');
    $this->postJson('/admin/home/stylepress/enhance', ['style_slug' => $style['slug'], 'prompt' => 'A cube', 'references' => [$reference]])->assertOk()->assertJsonPath('driver', 'groq');
    Http::assertSent(fn ($request) => $request->data()['model'] === 'qwen/qwen3.8-27b' && count($request->data()['messages'][1]['content']) === 2);
    Http::assertSentCount(2);
});

it('keeps the shared Higgsfield enhancer when the Groq driver is not configured', function () {
    config(['stylepress.enhance.driver' => 'higgsfield', 'stylepress.groq.api_key' => 'gsk-test']);
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    Http::fake();
    $response = $this->postJson('/admin/home/stylepress/enhance', ['style_slug' => $style, 'prompt' => 'A cube']);
    expect($response->status())->toBe(200, $response->getContent());
    $response->assertJsonPath('enhanced_prompt', 'An illustrated red cube')->assertJsonPath('driver', 'higgsfield');
    Http::assertNothingSent();
});

it('reports the enhancer user input limit without preventing ordinary generation', function () {
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $this->postJson('/admin/home/stylepress/enhance', ['style_slug' => $style, 'prompt' => str_repeat('x', 4001)])->assertUnprocessable()->assertJsonPath('message', 'Enhancement supports requests up to 4000 characters. Shorten the request or generate without enhancement.');
    $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => str_repeat('x', 4001), 'aspect_ratio' => '1:1'])->assertAccepted();
});

it('requires an explicit supported override for a legacy unsupported style default', function () {
    $path = tempnam(sys_get_temp_dir(), 'widget-legacy');
    file_put_contents($path, json_encode(['presets'=>[['slug'=>'legacy', 'title'=>'Legacy', 'template'=>'A paper cutout', 'aspectRatio'=>'4:5']], 'categories'=>[]]));
    config(['stylepress.catalog_path'=>$path]);
    try {
        $this->postJson('/admin/home/stylepress/generate', ['style_slug'=>'legacy', 'prompt'=>'A cube'])->assertUnprocessable();
        Process::assertNothingRan();
        $this->postJson('/admin/home/stylepress/generate', ['style_slug'=>'legacy', 'prompt'=>'A cube', 'aspect_ratio'=>'auto'])->assertAccepted();
    } finally {
        unlink($path);
    }
});

it('backs off while another worker owns the save lock and resumes after release', function () {
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $id = $this->postJson('/admin/home/stylepress/generate', ['style_slug'=>$style, 'prompt'=>'A cube', 'aspect_ratio'=>'1:1'])->assertAccepted()->json('generation.id');
    DB::table('dam_home_generations')->where('id', $id)->update(['status'=>'completed']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    mkdir($root, 0770, true);
    file_put_contents($root.'/generation.json', '{}');
    $lock = fopen($root.'/.save.lock', 'c');
    flock($lock, LOCK_EX);
    $importer = Mockery::mock(GenerationPackageImporter::class);
    $importer->shouldReceive('importManifest')->once()->andReturn(['ok'=>true, 'asset_id'=>987, 'action'=>'created']);
    app()->instance(GenerationPackageImporter::class, $importer);
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(30);
    $worker = new CompleteStylePressGeneration($id);
    $worker->setJob($queued);
    try {
        $worker->handle();
        $this->assertDatabaseHas('dam_home_generations', ['id'=>$id, 'save_status'=>'pending']);
        flock($lock, LOCK_UN);
        $worker->handle();
        $this->assertDatabaseHas('dam_home_generations', ['id'=>$id, 'save_status'=>'saved']);
    } finally {
        fclose($lock);
        File::deleteDirectory($root);
    }
});

it('passes the chosen GPT Image 2.5 variant through and keeps GPT Image 2 the default', function () {
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $job = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1', 'model' => 'gpt_image_2_5', 'variant' => 'sunburst'])->assertAccepted();
    Process::assertRan(fn ($p) => in_array('create', $p->command, true) && in_array('gpt_image_2_5', $p->command, true) && in_array('--variant', $p->command, true) && in_array('sunburst', $p->command, true));
    expect(json_decode(DB::table('dam_home_generations')->where('id', $job->json('generation.id'))->value('inputs'), true)['settings'])->toMatchArray(['model' => 'gpt_image_2_5', 'variant' => 'sunburst', 'resolution' => '2k']);
    $this->postJson('/admin/home/stylepress/estimate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1', 'model' => 'gpt_image_2_5'])->assertOk();
    Process::assertRan(fn ($p) => in_array('cost', $p->command, true) && in_array('gpt_image_2_5', $p->command, true) && in_array('flare', $p->command, true));
    $this->postJson('/admin/home/stylepress/estimate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1'])->assertOk();
    Process::assertRan(fn ($p) => in_array('cost', $p->command, true) && in_array('gpt_image_2', $p->command, true) && ! in_array('--variant', $p->command, true));
    $this->postJson('/admin/home/stylepress/estimate', ['style_slug' => $style, 'prompt' => 'A cube', 'model' => 'nano_banana'])->assertUnprocessable();
    $this->postJson('/admin/home/stylepress/estimate', ['style_slug' => $style, 'prompt' => 'A cube', 'model' => 'gpt_image_2_5', 'variant' => 'nova'])->assertUnprocessable();
    // A variant is only meaningful with GPT Image 2.5; with the standard or omitted model it is rejected, not silently dropped.
    $this->postJson('/admin/home/stylepress/estimate', ['style_slug' => $style, 'prompt' => 'A cube', 'model' => 'gpt_image_2', 'variant' => 'flare'])->assertUnprocessable()->assertJsonValidationErrors(['variant']);
    $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'variant' => 'sunburst'])->assertUnprocessable()->assertJsonValidationErrors(['variant']);
});

it('rebuilds an invalid stored manifest from the existing output instead of retrying it forever', function () {
    Storage::fake('local');
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $id = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1'])->assertAccepted()->json('generation.id');
    DB::table('dam_home_generations')->where('id', $id)->update(['status' => 'completed', 'result_url' => 'https://example.com/output.png']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    File::makeDirectory($root, 0770, true, true);
    $image = UploadedFile::fake()->image('output.png', 6, 6); // keep the handle alive until copied
    copy($image->getRealPath(), $root.'/output.png');
    // The live defect: a production-shaped manifest whose output checksum was recorded as `false`.
    $inputs = json_decode(DB::table('dam_home_generations')->where('id', $id)->value('inputs'), true);
    file_put_contents($root.'/generation.json', json_encode(['schema_version' => 1, 'generation_id' => 'mothership-'.$id, 'title' => 'Broken', 'kind' => 'image', 'provider' => 'higgsfield-generate', 'model' => 'gpt_image_2', 'prompt' => $inputs['prompt'], 'original_prompt' => 'A cube', 'enhanced_prompt' => null, 'style' => $inputs['style'], 'settings' => $inputs['settings'], 'source' => ['note_path' => null, 'job_id' => 'job-fixture', 'url' => 'https://example.com/output.png', 'generated_at' => now()->toIso8601String()], 'output' => ['path' => 'output.png', 'sha256' => false, 'mime' => 'image/png'], 'references' => []]));
    Http::fake(function () {
        throw new RuntimeException('A rebuild must not download the output again.');
    });
    $realImporter = app()->make(GenerationPackageImporter::class);
    try {
        (new CompleteStylePressGeneration($id))->handle(); // the real importer rejects the manifest: removed, error recorded, retry scheduled
        expect(is_file($root.'/generation.json'))->toBeFalse();
        expect(DB::table('dam_home_generations')->where('id', $id)->value('save_error'))->toContain('manifest was invalid')->toContain('output.sha256');
        $importer = Mockery::mock(GenerationPackageImporter::class);
        $importer->shouldReceive('importManifest')->once()->andReturn(['ok' => true, 'asset_id' => 990, 'action' => 'created']);
        app()->instance(GenerationPackageImporter::class, $importer);
        (new CompleteStylePressGeneration($id))->handle(); // rebuilt from the existing output, then imported
        $manifest = json_decode(file_get_contents($root.'/generation.json'), true);
        expect($manifest['output']['sha256'])->toBe(hash_file('sha256', $root.'/output.png'));
        expect($realImporter->importManifest(manifestPath: $root.'/generation.json', dryRun: true))->toMatchArray(['ok' => true, 'action' => 'planned']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('dam_home_generations', ['id' => $id, 'save_status' => 'saved', 'asset_id' => 990, 'save_error' => null]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('records only a safe failure reason, never the provider URL, and ignores a mislabeled cached output', function () {
    Storage::fake('local');
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $id = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1'])->assertAccepted()->json('generation.id');
    DB::table('dam_home_generations')->where('id', $id)->update(['status' => 'completed', 'result_url' => 'https://cdn.example.com/signed/output.png?token=secret-value']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    File::makeDirectory($root, 0770, true, true);
    $gif = UploadedFile::fake()->image('cached.gif', 6, 6); // wrong type under the PNG name: must not be reused
    copy($gif->getRealPath(), $root.'/output.png');
    Http::fake(['*' => Http::response('upstream failure', 503)]);
    try {
        (new CompleteStylePressGeneration($id))->handle();
        $error = DB::table('dam_home_generations')->where('id', $id)->value('save_error');
        expect($error)->toContain('RequestException')->not->toContain('https://')->not->toContain('secret-value');
        Http::assertSentCount(1); // the mislabeled cached file was not accepted, so a download was attempted
        expect(is_file($root.'/generation.json'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

it('reuses an already downloaded output on retry instead of downloading again', function () {
    Storage::fake('local');
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $id = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1'])->assertAccepted()->json('generation.id');
    DB::table('dam_home_generations')->where('id', $id)->update(['status' => 'completed', 'result_url' => 'https://example.com/output.png']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    File::makeDirectory($root, 0770, true, true);
    $image = UploadedFile::fake()->image('output.png', 6, 6);
    copy($image->getRealPath(), $root.'/output.png');
    Http::fake(function () {
        throw new RuntimeException('A retry must not download the output again.');
    });
    $importer = Mockery::mock(GenerationPackageImporter::class);
    $importer->shouldReceive('importManifest')->once()->andReturn(['ok' => true, 'asset_id' => 989, 'action' => 'created']);
    app()->instance(GenerationPackageImporter::class, $importer);
    try {
        (new CompleteStylePressGeneration($id))->handle();
        $manifest = json_decode(file_get_contents($root.'/generation.json'), true);
        expect($manifest['output']['path'])->toBe('output.png')
            ->and($manifest['output']['sha256'])->toBe(hash_file('sha256', $root.'/output.png'));
        Http::assertNothingSent();
        $this->assertDatabaseHas('dam_home_generations', ['id' => $id, 'save_status' => 'saved', 'asset_id' => 989, 'save_error' => null]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('closes the download handle before renaming the output so the saved file is visible to the worker', function () {
    Storage::fake('local');
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $id = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1'])->assertAccepted()->json('generation.id');
    DB::table('dam_home_generations')->where('id', $id)->update(['status' => 'completed', 'result_url' => 'https://example.com/output.png']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    $image = UploadedFile::fake()->image('output.png', 8, 8); // keep the handle alive until the bytes are read
    $bytes = file_get_contents($image->getRealPath());
    Http::fake(function ($request, $options) use ($bytes) {
        // Like the real transport, the response body owns the open sink handle; the job must close it.
        $sink = fopen($options['sink'], 'w+');
        fwrite($sink, $bytes);
        rewind($sink);

        return Create::promiseFor(new Response(200, ['Content-Type' => 'image/png'], Utils::streamFor($sink)));
    });
    $importer = Mockery::mock(GenerationPackageImporter::class);
    $importer->shouldReceive('importManifest')->once()->andReturn(['ok' => true, 'asset_id' => 987, 'action' => 'created']);
    app()->instance(GenerationPackageImporter::class, $importer);
    try {
        (new CompleteStylePressGeneration($id))->handle();
        $body = Http::recorded()->first()[1]->toPsrResponse()->getBody();
        expect($body->isReadable())->toBeFalse()->and($body->isWritable())->toBeFalse();
        expect(is_file($root.'/output.download'))->toBeFalse()->and(is_file($root.'/output.png'))->toBeTrue();
        $this->assertDatabaseHas('dam_home_generations', ['id' => $id, 'save_status' => 'saved', 'asset_id' => 987]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('closes the download handle when the provider answers with an error and leaves no manifest behind', function () {
    Storage::fake('local');
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $id = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1'])->assertAccepted()->json('generation.id');
    DB::table('dam_home_generations')->where('id', $id)->update(['status' => 'completed', 'result_url' => 'https://example.com/output.png']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    Http::fake(function ($request, $options) {
        $sink = fopen($options['sink'], 'w+');
        fwrite($sink, 'upstream failure');
        rewind($sink);

        return Create::promiseFor(new Response(503, [], Utils::streamFor($sink)));
    });
    try {
        (new CompleteStylePressGeneration($id))->handle();
        $body = Http::recorded()->first()[1]->toPsrResponse()->getBody();
        expect($body->isReadable())->toBeFalse()->and($body->isWritable())->toBeFalse();
        expect(is_file($root.'/generation.json'))->toBeFalse();
        expect(DB::table('dam_home_generations')->where('id', $id)->value('save_error'))->toContain('RequestException');
    } finally {
        File::deleteDirectory($root);
    }
});

it('titles the package with the AI title and records its source in the manifest', function () {
    Storage::fake('local');
    app()->instance(GenerationTitler::class, new GenerationTitler(fn () => 'Neon Cube On Campus Quad'));
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $id = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => "A cube\nwith pasted text", 'aspect_ratio' => '1:1'])->assertAccepted()->json('generation.id');
    DB::table('dam_home_generations')->where('id', $id)->update(['status' => 'completed', 'result_url' => 'https://example.com/output.png']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    $image = UploadedFile::fake()->image('output.png', 8, 8); // keep the handle alive until the bytes are read
    $bytes = file_get_contents($image->getRealPath());
    Http::fake(function ($request, $options) use ($bytes) {
        file_put_contents($options['sink'], $bytes);

        return Http::response($bytes, 200, ['Content-Type' => 'image/png']);
    });
    $importer = Mockery::mock(GenerationPackageImporter::class);
    $importer->shouldReceive('importManifest')->once()->andReturn(['ok' => true, 'asset_id' => 986, 'action' => 'created']);
    app()->instance(GenerationPackageImporter::class, $importer);
    try {
        MagicAIPlatform::create(['label' => 'OpenAI', 'provider' => 'openai', 'api_url' => null, 'api_key' => 'sk-test', 'models' => 'gpt-5-mini', 'is_default' => true, 'status' => true]);
        (new CompleteStylePressGeneration($id))->handle();
        $manifest = json_decode(file_get_contents($root.'/generation.json'), true);
        expect($manifest['title'])->toBe('Neon Cube On Campus Quad')
            ->and($manifest['settings']['title_source'])->toBe('ai')
            ->and($manifest['original_prompt'])->toBe("A cube\nwith pasted text");
        // Without a usable platform the manifest carries the one-line fallback and says so.
        MagicAIPlatform::query()->delete();
        app()->forgetInstance(GenerationTitler::class);
        @unlink($root.'/generation.json');
        $importer->shouldReceive('importManifest')->once()->andReturn(['ok' => true, 'asset_id' => 986, 'action' => 'unchanged']);
        DB::table('dam_home_generations')->where('id', $id)->update(['save_status' => 'pending']);
        (new CompleteStylePressGeneration($id))->handle();
        $manifest = json_decode(file_get_contents($root.'/generation.json'), true);
        expect($manifest['title'])->toBe($this->getJson('/admin/home/stylepress/catalog')->json('presets.0.title').' — A cube with pasted text')
            ->and($manifest['settings']['title_source'])->toBe('fallback');
    } finally {
        File::deleteDirectory($root);
        MagicAIPlatform::query()->delete();
    }
});

it('carries the library source asset id into the manifest references', function () {
    Storage::fake('local');
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $image = UploadedFile::fake()->image('ref.png', 8, 8);
    $referenceId = (string) Str::uuid();
    Storage::disk('local')->put('stylepress/references/'.$referenceId.'.png', file_get_contents($image->getRealPath()));
    DB::table('dam_stylepress_references')->insert(['id' => $referenceId, 'admin_id' => auth('admin')->id(), 'path' => 'stylepress/references/'.$referenceId.'.png', 'name' => 'ref.png', 'sha256' => hash_file('sha256', $image->getRealPath()), 'asset_id' => 4242, 'created_at' => now(), 'updated_at' => now()]);
    $id = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1', 'references' => [$referenceId]])->assertAccepted()->json('generation.id');
    $inputs = json_decode(DB::table('dam_home_generations')->where('id', $id)->value('inputs'), true);
    expect($inputs['references'][0]['asset_id'])->toBe(4242);
    DB::table('dam_home_generations')->where('id', $id)->update(['status' => 'completed', 'result_url' => 'https://example.com/output.png']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    $output = UploadedFile::fake()->image('output.png', 8, 8);
    $bytes = file_get_contents($output->getRealPath());
    Http::fake(function ($request, $options) use ($bytes) {
        file_put_contents($options['sink'], $bytes);

        return Http::response($bytes, 200, ['Content-Type' => 'image/png']);
    });
    $importer = Mockery::mock(GenerationPackageImporter::class);
    $importer->shouldReceive('importManifest')->once()->andReturn(['ok' => true, 'asset_id' => 985, 'action' => 'created']);
    app()->instance(GenerationPackageImporter::class, $importer);
    try {
        (new CompleteStylePressGeneration($id))->handle();
        $manifest = json_decode(file_get_contents($root.'/generation.json'), true);
        expect($manifest['references'][0]['asset_id'])->toBe(4242)->and($manifest['references'][0]['name'])->toBe('ref.png');
    } finally {
        File::deleteDirectory($root);
    }
});

it('packages a completed GPT Image 2.5 job with the plain model id and the variant in settings', function () {
    Storage::fake('local');
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $id = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $style, 'prompt' => 'A cube', 'aspect_ratio' => '1:1', 'model' => 'gpt_image_2_5', 'variant' => 'sunburst'])->assertAccepted()->json('generation.id');
    DB::table('dam_home_generations')->where('id', $id)->update(['status' => 'completed', 'result_url' => 'https://example.com/output.png']);
    $root = storage_path('app/private/stylepress/outbox/'.$id);
    $image = UploadedFile::fake()->image('output.png', 8, 8); // keep the handle alive until the bytes are read
    $bytes = file_get_contents($image->getRealPath());
    Http::fake(function ($request, $options) use ($bytes) {
        file_put_contents($options['sink'], $bytes);

        return Http::response($bytes, 200, ['Content-Type' => 'image/png']);
    });
    $importer = Mockery::mock(GenerationPackageImporter::class);
    $importer->shouldReceive('importManifest')->once()->andReturn(['ok' => true, 'asset_id' => 988, 'action' => 'created']);
    app()->instance(GenerationPackageImporter::class, $importer);
    try {
        (new CompleteStylePressGeneration($id))->handle();
        $manifest = json_decode(file_get_contents($root.'/generation.json'), true);
        expect($manifest['model'])->toBe('gpt_image_2_5')
            ->and($manifest['settings'])->toMatchArray(['model' => 'gpt_image_2_5', 'variant' => 'sunburst', 'resolution' => '2k']);
    } finally {
        File::deleteDirectory($root);
    }
});

it('refuses every StylePress route to a role whose widget allowlist lacks stylepress', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.assets']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["recent","favorites"]']);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    // Every registered StylePress route, so a route added later without the gate fails here.
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.home.stylepress.'))
        ->values();

    expect($routes->count())->toBeGreaterThanOrEqual(13);

    foreach ($routes as $route) {
        $parameters = [];
        foreach ($route->parameterNames() as $parameter) {
            $parameters[$parameter] = in_array($parameter, ['id'], true) ? '00000000-0000-4000-8000-000000000000' : '1';
        }
        $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));

        $this->json($method, route($route->getName(), $parameters, false), ['style_slug' => 'x', 'prompt' => 'A cube'])
            ->assertForbidden("{$method} {$route->uri()} must be gated by the StylePress widget allowlist");
    }

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('keeps StylePress open to a role whose allowlist includes it', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.assets']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["recent","stylepress"]']);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $this->getJson('/admin/home/stylepress/catalog')->assertOk();
});

it('keeps StylePress closed to a custom role with no stored allowlist, since paid tools are opt-in', function (?string $widgets) {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.assets']]);
    if ($widgets !== 'no-row') {
        DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => $widgets]);
    }
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $this->getJson('/admin/home/stylepress/catalog')->assertForbidden();
})->with([[null], ['no-row']]);

it('lets a full admin use StylePress regardless of widget allowlists', function () {
    $this->getJson('/admin/home/stylepress/catalog')->assertOk();
});
