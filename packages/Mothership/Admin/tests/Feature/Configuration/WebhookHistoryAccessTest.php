<?php

use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Mothership\Webhook\Models\Webhook;
use Mothership\Webhook\Presenters\WebhookPresenter;
use OwenIt\Auditing\Models\Audit;

/**
 * A webhook's custom headers often carry the receiver's credential (an `Authorization` header), and the history
 * routes check only the history ACL. Its history is for those who may edit the webhook, and even they never read a
 * header's value back out of it.
 */
/** An admin holding every ACL key except those under $withoutPrefix (null: every key). */
function webhookHistoryAdmin(?string $withoutPrefix): Admin
{
    $permissions = collect(config('acl'))->pluck('key')->unique()
        ->reject(fn (string $key): bool => $withoutPrefix !== null && str_starts_with($key, $withoutPrefix))
        ->values()->all();

    return Admin::factory()->create(['role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ])->id]);
}

function webhookHistoryTrail(int $webhookId)
{
    return Audit::query()->where('tags', 'webhooks')->where('history_id', $webhookId);
}

/** MySQL and PostgreSQL number versions in an insert trigger; SQLite has none, so number them as it would. */
function numberWebhookVersions(int $webhookId): void
{
    foreach (webhookHistoryTrail($webhookId)->orderBy('id')->pluck('id')->values() as $index => $auditId) {
        Audit::query()->whereKey($auditId)->update(['version_id' => $index + 1]);
    }
}

// Without any webhook permission, and with every one but edit (list, create, logs): edit is the one that counts.
it('answers a webhook\'s history to an admin who may not edit webhooks like history that does not exist', function (string $withoutPrefix) {
    $webhook = Webhook::create([
        'name'    => 'Receiver',
        'url'     => 'https://receiver.example.test/hook',
        'events'  => ['product.updated'],
        'headers' => ['Authorization' => 'Bearer hist-sentinel-1'],
    ]);
    expect(webhookHistoryTrail($webhook->id)->count())->toBeGreaterThan(0);
    numberWebhookVersions($webhook->id);
    config(['app.debug' => false]);

    $admin = webhookHistoryAdmin(withoutPrefix: $withoutPrefix);
    expect($admin->role->permissions)->toContain('history.view');
    $this->actingAs($admin, 'admin');

    $hiddenRead = $this->getJson(route('admin.history.version.view', ['entity' => 'webhooks', 'id' => $webhook->id, 'versionId' => 1]));
    $missingRead = $this->getJson(route('admin.history.version.view', ['entity' => 'webhooks', 'id' => $webhook->id + 1000, 'versionId' => 1]));

    $hiddenRead->assertNotFound();
    expect($hiddenRead->getContent())->not->toContain('hist-sentinel-1')->not->toContain('receiver.example.test')
        ->toBe($missingRead->assertNotFound()->getContent());
    $this->getJson(route('admin.history.index', ['entity' => 'webhooks', 'id' => $webhook->id]), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertNotFound();
})->with(['no webhook permission' => 'configuration.webhook', 'every webhook permission but edit' => 'configuration.webhook.edit']);

it('shows a webhook editor which headers changed but never their values, old rows included', function () {
    $webhook = Webhook::create([
        'name'    => 'Receiver',
        'url'     => 'https://receiver.example.test/hook',
        'events'  => ['product.updated'],
        'headers' => ['Authorization' => 'Bearer hist-sentinel-1', 'X-Team' => 'pim'],
    ]);
    $webhook->update(['headers' => ['Authorization' => 'Bearer hist-sentinel-2', 'X-Team' => 'pim']]);
    numberWebhookVersions($webhook->id);

    // Nothing written from now on stores a header value.
    expect(webhookHistoryTrail($webhook->id)->get()->map(fn (Audit $audit): string => json_encode([$audit->old_values, $audit->new_values]))->implode(''))
        ->not->toContain('hist-sentinel')->not->toContain('pim');

    // A row written before the fix holds the values in the clear: the history view still hides them.
    $legacy = webhookHistoryTrail($webhook->id)->orderBy('id')->first();
    $legacy->forceFill(['new_values' => ['name' => 'Receiver', 'headers' => ['Authorization' => 'Bearer hist-legacy-0']]])->save();

    $this->actingAs(webhookHistoryAdmin(withoutPrefix: null), 'admin');

    // The update names the header whose value changed and leaves out the unchanged one.
    expect(webhookHistoryHeaders($this, $webhook->id, 2))
        ->toBe(['Header: Authorization' => [WebhookPresenter::MASK, WebhookPresenter::MASK]]);

    foreach ([1, 2] as $versionId) {
        $read = $this->getJson(route('admin.history.version.view', ['entity' => 'webhooks', 'id' => $webhook->id, 'versionId' => $versionId]))
            ->assertOk();

        expect($read->getContent())->toContain('Authorization')
            ->not->toContain('hist-sentinel')->not->toContain('hist-legacy')->not->toContain('Bearer');
    }

    // The legacy create still lists the webhook's name, so the read was not simply empty.
    $this->getJson(route('admin.history.version.view', ['entity' => 'webhooks', 'id' => $webhook->id, 'versionId' => 1]))
        ->assertSee('Receiver');
});

it('masks every shape of stored header change: legacy JSON or arrays, additions, removals, reorders and deletion', function () {
    $webhook = Webhook::create([
        'name'    => 'Receiver',
        'url'     => 'https://receiver.example.test/hook',
        'events'  => ['product.updated'],
        'headers' => ['Authorization' => 'Bearer hist-sentinel-1'],
    ]);
    foreach (['Bearer hist-sentinel-2', 'Bearer hist-sentinel-3', 'Bearer hist-sentinel-4'] as $value) {
        $webhook->update(['headers' => ['Authorization' => $value]]);
    }
    $webhook->delete();
    numberWebhookVersions($webhook->id);
    expect(webhookHistoryTrail($webhook->id)->count())->toBe(5);

    // Versions 2 to 4 become rows as written before the fix: raw values, as the JSON column or as arrays.
    $legacy = [
        2 => ['{"Authorization":"Bearer legacy-a","X-Team":"pim"}', '{"Authorization":"Bearer legacy-b","X-Team":"pim","X-New":"legacy-c"}'],
        3 => ['{"A":"legacy-d","B":"legacy-e"}', '{"B":"legacy-e","A":"legacy-d"}'],
        4 => [['Authorization' => 'Bearer legacy-f', 'X-Old' => 'legacy-g'], ['Authorization' => 'Bearer legacy-f']],
    ];
    foreach ($legacy as $versionId => [$old, $new]) {
        webhookHistoryTrail($webhook->id)->where('version_id', $versionId)->first()
            ->forceFill(['old_values' => ['headers' => $old], 'new_values' => ['headers' => $new]])->save();
    }

    $this->actingAs(webhookHistoryAdmin(withoutPrefix: null), 'admin');
    $mask = WebhookPresenter::MASK;

    expect(webhookHistoryHeaders($this, $webhook->id, 2))->toBe(['Header: Authorization' => [$mask, $mask], 'Header: X-New' => [null, $mask]])
        // Same headers in another order: nothing to name, and the raw column must not fall through to the generic display.
        ->and(webhookHistoryHeaders($this, $webhook->id, 3))->toBe([])
        ->and(webhookHistoryHeaders($this, $webhook->id, 4))->toBe(['Header: X-Old' => [$mask, null]])
        ->and(webhookHistoryHeaders($this, $webhook->id, 5))->toBe(['Header: Authorization' => [$mask, null]]);

    foreach (range(1, 5) as $versionId) {
        expect($this->getJson(route('admin.history.version.view', ['entity' => 'webhooks', 'id' => $webhook->id, 'versionId' => $versionId]))->assertOk()->getContent())
            ->not->toContain('legacy-')->not->toContain('hist-sentinel')->not->toContain('Bearer')->not->toContain('pim');
    }
});

/**
 * The header entries of one version as the editor reads them: name => [old, new].
 *
 * @return array<string, array{0: ?string, 1: ?string}>
 */
function webhookHistoryHeaders($test, int $webhookId, int $versionId): array
{
    $history = $test->getJson(route('admin.history.version.view', ['entity' => 'webhooks', 'id' => $webhookId, 'versionId' => $versionId]))
        ->assertOk()->json('versionHistory');

    return collect($history)
        ->filter(fn (array $entry, string $name): bool => str_starts_with($name, 'Header') || $name === 'headers')
        ->map(fn (array $entry): array => [$entry['old'] ?? null, $entry['new'] ?? null])
        ->all();
}
