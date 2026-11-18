<?php

require_once __DIR__.'/../Support/AutomationFixtures.php';

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\Jobs\SendDamWebhook;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\Automation\AutomationEngine;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\Tags\AssetTagWriter;
use Mothership\Webhook\Models\Webhook;
use Mothership\Webhook\Models\WebhookLog;
use Mothership\Webhook\Repositories\WebhookRepository;
use Mothership\Webhook\Services\WebhookService;

/*
 * P5b (D19): DAM webhook events. One signed delivery per change, through the Webhook package's signing and delivery
 * log; ids and changed keys only; delivered only when the webhook's owner may see the asset (the owner rule #138 set
 * for webhook history), so a payload never names an asset its owner cannot see.
 */

const DAM_EVENTS = ['dam.asset.created', 'dam.asset.updated', 'dam.asset.trashed', 'dam.asset.restored', 'dam.automation.run.completed'];

beforeEach(function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    WebhookLog::query()->delete();
    Webhook::query()->delete();
    $this->admin = $this->loginAsAdmin();
    $this->folder = Directory::factory()->create(['name' => 'Shoots']);
    $this->secret = bin2hex(random_bytes(16));
});

function damHook(object $test, ?int $ownerId): Webhook
{
    return Webhook::query()->create(['name' => 'DAM', 'url' => 'https://8.8.8.8/hook', 'is_active' => true, 'events' => DAM_EVENTS, 'secret' => $test->secret, 'owner_id' => $ownerId]);
}

/** @return list<array{event: string, data: array}> the DAM deliveries sent so far */
function damDeliveries(): array
{
    return Http::recorded()->map(fn ($pair) => $pair[0]->data())->filter(fn ($body) => str_starts_with($body['event'] ?? '', 'dam.'))
        ->map(fn ($body) => ['event' => $body['event'], 'data' => $body['data']])->values()->all();
}

it('delivers each DAM event once per change, with ids and changed keys only, signed as other webhooks are', function () {
    damHook($this, $this->admin->id);
    $status = automationField('status');

    $asset = automationAsset($this->folder, 'beach');
    app(FieldValues::class)->write(asset: $asset, values: ['status' => ['field' => $status, 'value' => 'draft']], source: 'web');
    app(AssetTagWriter::class)->addManual(asset: $asset, tag: app(AssetTagWriter::class)->resolve('Sunset')->first(), actorId: $this->admin->id);
    app(FieldValues::class)->write(asset: $asset, values: ['status' => ['field' => $status, 'value' => 'draft']], source: 'web');
    app(AssetTrashService::class)->trash(asset: $asset);
    app(AssetTrashService::class)->restore(asset: $asset);

    expect(damDeliveries())->toBe([
        ['event' => 'dam.asset.created', 'data' => ['asset_id' => $asset->id]],
        ['event' => 'dam.asset.updated', 'data' => ['asset_id' => $asset->id, 'changed' => ['fields.status'], 'source' => 'manual', 'origin' => 'user']],
        ['event' => 'dam.asset.updated', 'data' => ['asset_id' => $asset->id, 'changed' => ['tags'], 'source' => 'manual', 'origin' => 'user']],
        ['event' => 'dam.asset.trashed', 'data' => ['asset_id' => $asset->id]],
        ['event' => 'dam.asset.restored', 'data' => ['asset_id' => $asset->id]],
    ]);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('X-Mothership-Signature', 'sha256='.hash_hmac('sha256', $request->body(), $this->secret))
            && $request->hasHeader('X-Mothership-Event', $request->data()['event']);
    });
    expect(WebhookLog::query()->where('sku', 'asset:'.$asset->id)->pluck('event')->all())->toBe(['dam.asset.created', 'dam.asset.updated', 'dam.asset.updated', 'dam.asset.trashed', 'dam.asset.restored'])
        ->and(Http::recorded()->every(fn ($pair) => ! str_contains($pair[0]->body(), 'beach')))->toBeTrue();
});

it('reports a finished run, and marks the writes a rule made as automation', function () {
    damHook($this, $this->admin->id);
    $asset = automationAsset($this->folder, 'beach');
    $rule = automationRule(['actions' => [['type' => 'add_tag', 'value' => 'Auto']]]);
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $run = app(AutomationEngine::class)->startManual(rule: $rule, assetIds: [$asset->id], directoryId: null, requesterId: $this->admin->id);

    expect(damDeliveries())->toBe([
        ['event' => 'dam.asset.updated', 'data' => ['asset_id' => $asset->id, 'changed' => ['tags'], 'source' => 'rule:'.$rule->id.'@v1', 'origin' => 'automation']],
        ['event' => 'dam.automation.run.completed', 'data' => ['run_id' => $run->id, 'rule_id' => $rule->id, 'rule_version' => 1, 'trigger' => 'manual', 'status' => 'completed',
            'counts'                                                    => ['matched' => 1, 'written' => 1, 'skipped' => 0, 'refused' => 0]]],
    ]);
});

it('never names an asset to a webhook whose owner cannot see it', function () {
    $closed = Directory::factory()->create(['name' => 'Closed']);
    $member = automationMember(['dam', 'dam.asset', 'dam.asset.view', 'configuration', 'configuration.webhook', 'configuration.webhook.edit'], $this->folder);
    damHook($this, $member->id);

    $visible = automationAsset($this->folder, 'open');
    $hidden = automationAsset($closed, 'secret');
    app(AssetTrashService::class)->trash(asset: $hidden);

    expect(damDeliveries())->toBe([['event' => 'dam.asset.created', 'data' => ['asset_id' => $visible->id]]])
        ->and(WebhookLog::query()->where('sku', 'asset:'.$hidden->id)->exists())->toBeFalse();
});

it('waits for a new upload to be filed before deciding who may hear it was created', function () {
    $member = automationMember(['dam', 'dam.asset', 'dam.asset.view', 'configuration', 'configuration.webhook', 'configuration.webhook.edit'], $this->folder);
    damHook($this, $member->id);
    $asset = Asset::factory()->create();
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $job = (new SendDamWebhook(event: 'dam.asset.created', data: ['asset_id' => $asset->id], assetId: $asset->id, occurredAt: now()->toIso8601String()))->withFakeQueueInteractions();

    $job->handle(app(WebhookRepository::class), app(WebhookService::class), app(AssetAccessDecision::class));
    $job->assertReleased(delay: 10);
    expect(damDeliveries())->toBe([]);

    $this->folder->assets()->attach($asset->id);
    $job = (new SendDamWebhook(event: 'dam.asset.created', data: ['asset_id' => $asset->id], assetId: $asset->id, occurredAt: now()->toIso8601String()))->withFakeQueueInteractions();
    $job->handle(app(WebhookRepository::class), app(WebhookService::class), app(AssetAccessDecision::class));
    $job->assertNotReleased();
    expect(damDeliveries())->toBe([['event' => 'dam.asset.created', 'data' => ['asset_id' => $asset->id]]]);
});

it('delivers nothing to a webhook without an owner, or whose owner lost the webhook permission or was deactivated', function () {
    damHook($this, null);
    $member = automationMember(['dam', 'dam.asset', 'dam.asset.view'], $this->folder);
    damHook($this, $member->id);
    $inactive = automationMember(['dam', 'dam.asset', 'dam.asset.view', 'configuration', 'configuration.webhook', 'configuration.webhook.edit'], $this->folder);
    $inactive->update(['status' => 0]);
    damHook($this, $inactive->id);

    automationAsset($this->folder, 'beach');

    expect(damDeliveries())->toBe([]);
});

it('records the creating admin as owner, and lets an edit claim an ownerless webhook', function () {
    $this->postJson(route('webhook.store'), ['name' => 'Mine', 'url' => 'https://8.8.8.8/hook', 'events' => ['dam.asset.created'], 'is_active' => 1])->assertSuccessful();
    $legacy = Webhook::query()->create(['name' => 'Old', 'url' => 'https://8.8.8.8/old', 'is_active' => true, 'events' => ['dam.asset.created']]);

    $this->put(route('webhook.update', $legacy->id), ['name' => 'Old', 'url' => 'https://8.8.8.8/old', 'events' => ['dam.asset.created'], 'is_active' => 1])
        ->assertSessionHasNoErrors()->assertRedirect(route('webhook.edit', $legacy->id));

    expect(Webhook::query()->orderBy('id')->pluck('owner_id')->all())->toBe([$this->admin->id, $this->admin->id]);
});
