<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Redis;
use Mothership\DAM\Services\HomePresence;
use Mothership\User\Models\Admin;

/**
 * In-memory double for the predis connection HomePresence talks to, so these
 * tests never require a live Redis server. It mirrors only the NX/EX SET,
 * ZADD, ZREMRANGEBYSCORE, and ZREVRANGEBYSCORE WITHSCORES semantics
 * HomePresence relies on, using real elapsed time for the throttle key's
 * EX expiry the same way a real Redis server would.
 */
class FakeHomePresenceRedis
{
    private array $throttleExpiresAt = [];

    private array $zset = [];

    public array $zaddCallsByMember = [];

    public function set(string $key, string $value, string $nx, string $ex, int $ttlSeconds): ?string
    {
        $now = microtime(true);

        if (isset($this->throttleExpiresAt[$key]) && $this->throttleExpiresAt[$key] > $now) {
            return null;
        }

        $this->throttleExpiresAt[$key] = $now + $ttlSeconds;

        return 'OK';
    }

    public function zadd(string $key, int $score, string $member): int
    {
        $isNew = ! array_key_exists($member, $this->zset);

        $this->zset[$member] = $score;
        $this->zaddCallsByMember[$member] = ($this->zaddCallsByMember[$member] ?? 0) + 1;

        return $isNew ? 1 : 0;
    }

    public function expire(string $key, int $seconds): int
    {
        return 1;
    }

    public function zremrangebyscore(string $key, string $min, int $max): int
    {
        $removed = 0;

        foreach ($this->zset as $member => $score) {
            if ($score <= $max) {
                unset($this->zset[$member]);
                $removed++;
            }
        }

        return $removed;
    }

    public function zrevrangebyscore(string $key, string $max, string $min, string $withScores): array
    {
        $scores = $this->zset;
        arsort($scores);

        return $scores;
    }

    public function seed(int $adminId, int $score): void
    {
        $this->zset[(string) $adminId] = $score;
    }

    public function zaddCallCountFor(int $adminId): int
    {
        return $this->zaddCallsByMember[(string) $adminId] ?? 0;
    }
}

function fakeHomePresenceRedis(): FakeHomePresenceRedis
{
    $fake = new FakeHomePresenceRedis;

    Redis::shouldReceive('connection')
        ->with(config('dam-home.presence_connection'))
        ->andReturn($fake);

    return $fake;
}

function seedPresence(FakeHomePresenceRedis $fake, array $adminIds): void
{
    $baseScore = time();

    foreach ($adminIds as $index => $adminId) {
        $fake->seed((int) $adminId, $baseScore - $index);
    }
}

function systemQuickViewSnapshotPath(): string
{
    return storage_path('framework/testing/system-quickview-test.json');
}

function writeRawMetricsSnapshot(string $contents): void
{
    $path = systemQuickViewSnapshotPath();

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, $contents);
}

function writeMetricsSnapshot(array $document): void
{
    writeRawMetricsSnapshot(json_encode($document, JSON_THROW_ON_ERROR));
}

/** Matches the exact Z-suffixed RFC 3339 shape SystemMetricsSnapshot requires. */
function rfc3339Zulu(CarbonImmutable $moment): string
{
    return $moment->utc()->format('Y-m-d\TH:i:s\Z');
}

function validMetricsSignals(): array
{
    return [
        'cpu'    => ['available' => true, 'percent' => 42.0],
        'memory' => ['available' => true, 'used_bytes' => 8_000_000_000, 'total_bytes' => 16_000_000_000, 'percent' => 50.0],
        'gpu'    => ['available' => true, 'percent' => 10.0],
    ];
}

beforeEach(function () {
    $this->homeAdmin = Admin::factory()->create();
    $this->actingAs($this->homeAdmin, 'admin');
});

beforeEach(function () {
    config(['dam-home.system_metrics_path' => systemQuickViewSnapshotPath()]);

    if (is_file(systemQuickViewSnapshotPath())) {
        unlink(systemQuickViewSnapshotPath());
    }
});

afterEach(function () {
    if (is_file(systemQuickViewSnapshotPath())) {
        unlink(systemQuickViewSnapshotPath());
    }
});

it('records a human at most once per thirty seconds and expires at the exact five minute boundary', function () {
    $fake = fakeHomePresenceRedis();
    $clock = CarbonImmutable::parse('2026-09-20T12:00:00Z');
    $presence = app(HomePresence::class);

    $presence->record(admin: $this->homeAdmin, now: $clock);
    $presence->record(admin: $this->homeAdmin, now: $clock->addSeconds(29));

    expect($fake->zaddCallCountFor($this->homeAdmin->id))->toBe(1);

    expect($presence->activeUsers(viewer: $this->homeAdmin, now: $clock->addSeconds(299))['online_count'])->toBe(1);
    expect($presence->activeUsers(viewer: $this->homeAdmin, now: $clock->addSeconds(300))['online_count'])->toBe(0);
});

it('filters ineligible admins before counting active users', function () {
    $fake = fakeHomePresenceRedis();
    $disabled = Admin::factory()->create(['status' => 0]);
    $api = Admin::factory()->create(['type' => 'api']);
    $deleted = Admin::factory()->create();

    seedPresence($fake, [$this->homeAdmin->id, $disabled->id, $api->id, $deleted->id]);

    $deleted->delete();

    $result = app(HomePresence::class)->activeUsers(viewer: $this->homeAdmin, now: now()->toImmutable());

    expect($result['online_count'])->toBe(1)
        ->and($result['users'][0]['id'])->toBe($this->homeAdmin->id)
        ->and($result['users'][0]['is_current'])->toBeTrue();
});

it('shapes each active user with id, name, avatar_url, and is_current', function () {
    $fake = fakeHomePresenceRedis();
    seedPresence($fake, [$this->homeAdmin->id]);

    $result = app(HomePresence::class)->activeUsers(viewer: $this->homeAdmin, now: now()->toImmutable());

    expect($result['users'][0])->toBe([
        'id'         => $this->homeAdmin->id,
        'name'       => $this->homeAdmin->name,
        'avatar_url' => $this->homeAdmin->avatar_url,
        'is_current' => true,
    ]);
});

it('sorts the current viewer first ahead of higher-scored peers', function () {
    $fake = fakeHomePresenceRedis();
    $charlie = Admin::factory()->create(['name' => 'Charlie']);

    // Charlie is seeded first, so seedPresence gives Charlie the higher score.
    seedPresence($fake, [$charlie->id, $this->homeAdmin->id]);

    $result = app(HomePresence::class)->activeUsers(viewer: $this->homeAdmin, now: now()->toImmutable());

    expect(array_column($result['users'], 'id'))->toBe([$this->homeAdmin->id, $charlie->id]);
});

it('breaks a score tie by name ascending', function () {
    $fake = fakeHomePresenceRedis();
    $bravo = Admin::factory()->create(['name' => 'Bravo']);
    $alpha = Admin::factory()->create(['name' => 'Alpha']);

    // An identical, current score: anything older than the five minute window is pruned before sorting.
    $score = now()->getTimestamp();
    $fake->seed($bravo->id, $score);
    $fake->seed($alpha->id, $score);

    $result = app(HomePresence::class)->activeUsers(viewer: $this->homeAdmin, now: now()->toImmutable());

    expect(array_column($result['users'], 'id'))->toBe([$alpha->id, $bravo->id]);
});

it('returns available false when a presence read fails against Redis', function () {
    Redis::shouldReceive('connection')
        ->with(config('dam-home.presence_connection'))
        ->andThrow(new RuntimeException('redis unavailable'));

    $result = app(HomePresence::class)->activeUsers(viewer: $this->homeAdmin, now: now()->toImmutable());

    expect($result)->toBe(['available' => false, 'online_count' => 0, 'users' => []]);
});

it('keeps the authenticated page working and logs presence.record_failed when Redis fails during middleware recording', function () {
    config(['dam-home.log_enabled' => true]);

    $logPath = storage_path('logs/home.jsonl');

    if (is_file($logPath)) {
        unlink($logPath);
    }

    Redis::shouldReceive('connection')
        ->with(config('dam-home.presence_connection'))
        ->andThrow(new RuntimeException('redis unavailable'));

    $this->get('/admin/home')->assertOk();

    $record = json_decode(trim((string) file_get_contents($logPath)), true, flags: JSON_THROW_ON_ERROR);

    expect($record['event'])->toBe('presence.record_failed')
        ->and($record['ctx']['admin_id'])->toBe($this->homeAdmin->id)
        ->and($record['ctx']['exception'])->toBe(RuntimeException::class)
        ->and($record['ctx'])->not->toHaveKeys(['message', 'route']);

    if (is_file($logPath)) {
        unlink($logPath);
    }
});

it('does not record presence for an API admin request', function () {
    $fake = fakeHomePresenceRedis();
    $api = Admin::factory()->create(['type' => 'api']);
    $this->actingAs($api, 'admin');

    $this->get('/admin/home')->assertOk();

    expect($fake->zaddCallCountFor($api->id))->toBe(0);
});

it('returns validated no-store QuickView data with a ten second poll interval', function () {
    fakeHomePresenceRedis();
    writeMetricsSnapshot([
        'schema_version' => 1,
        'collected_at'   => rfc3339Zulu(now()->subSeconds(5)->toImmutable()),
        'signals'        => [
            'cpu'    => ['available' => true, 'percent' => 125],
            'memory' => ['available' => true, 'used_bytes' => 12, 'total_bytes' => 10, 'percent' => 120],
            'gpu'    => ['available' => true, 'percent' => -4],
        ],
    ]);

    $response = $this->getJson('/admin/home/data/system-quickview')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $response->assertJsonPath('poll_after_seconds', 10)
        ->assertJsonPath('metrics.available', true)
        ->assertJsonPath('metrics.stale', false)
        ->assertJsonPath('metrics.cpu.percent', 100)
        ->assertJsonPath('metrics.memory.available', false)
        ->assertJsonPath('metrics.gpu.percent', 0);
});

it('keeps presence available when metrics are stale or invalid', function (string $contents) {
    fakeHomePresenceRedis();
    writeRawMetricsSnapshot($contents);

    $response = $this->getJson('/admin/home/data/system-quickview')->assertOk();

    $response->assertJsonPath('metrics.available', false)
        ->assertJsonPath('presence.available', true);
})->with([
    'invalid json'  => ['{'],
    'wrong schema'  => ['{"schema_version":99}'],
]);

it('keeps valid metrics available when the presence read fails', function () {
    writeMetricsSnapshot([
        'schema_version' => 1,
        'collected_at'   => rfc3339Zulu(now()->toImmutable()),
        'signals'        => validMetricsSignals(),
    ]);

    Redis::shouldReceive('connection')
        ->with(config('dam-home.presence_connection'))
        ->andThrow(new RuntimeException('redis unavailable'));

    $response = $this->getJson('/admin/home/data/system-quickview')->assertOk();

    $response->assertJsonPath('metrics.available', true)
        ->assertJsonPath('metrics.cpu.percent', 42)
        ->assertJsonPath('presence.available', false)
        ->assertJsonPath('presence.online_count', 0);
});

it('logs presence.read_failed with a redacted exception class when Redis is unavailable, while metrics stay valid', function () {
    config(['dam-home.log_enabled' => true]);

    $logPath = storage_path('logs/home.jsonl');

    if (is_file($logPath)) {
        unlink($logPath);
    }

    writeMetricsSnapshot([
        'schema_version' => 1,
        'collected_at'   => rfc3339Zulu(now()->toImmutable()),
        'signals'        => validMetricsSignals(),
    ]);

    Redis::shouldReceive('connection')
        ->with(config('dam-home.presence_connection'))
        ->andThrow(new RuntimeException('redis unavailable'));

    $response = $this->getJson('/admin/home/data/system-quickview')->assertOk();

    $response->assertJsonPath('metrics.available', true)
        ->assertJsonPath('metrics.cpu.percent', 42)
        ->assertJsonPath('presence.available', false)
        ->assertJsonPath('presence.online_count', 0);

    $records = array_filter(
        array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_filter(explode(PHP_EOL, trim((string) file_get_contents($logPath)))),
        ),
        fn (array $record): bool => $record['event'] === 'presence.read_failed',
    );

    expect($records)->toHaveCount(1);

    $record = array_values($records)[0];

    expect(Arr::except($record['ctx'], 'request_id'))->toBe(['exception' => Throwable::class])
        ->and($record['ctx'])->not->toHaveKeys(['message', 'route']);

    if (is_file($logPath)) {
        unlink($logPath);
    }
});

it('reports metrics unavailable when no snapshot exists (non-Studio host)', function () {
    fakeHomePresenceRedis();

    $response = $this->getJson('/admin/home/data/system-quickview')->assertOk();

    $response->assertJsonPath('metrics.available', false)
        ->assertJsonPath('metrics.cpu.available', false)
        ->assertJsonPath('metrics.memory.available', false)
        ->assertJsonPath('metrics.gpu.available', false)
        ->assertJsonPath('presence.available', true);
});

it('treats telemetry exactly fifteen seconds old as fresh and older as stale', function (int $ageSeconds, bool $expectedStale) {
    fakeHomePresenceRedis();
    // Freeze the clock so the write and the request share one instant; otherwise elapsed
    // wall-clock time between the two could tip an exact-boundary case across the second mark.
    $this->travelTo(CarbonImmutable::parse('2026-09-20T12:00:00Z'));
    writeMetricsSnapshot([
        'schema_version' => 1,
        'collected_at'   => rfc3339Zulu(now()->subSeconds($ageSeconds)->toImmutable()),
        'signals'        => validMetricsSignals(),
    ]);

    $this->getJson('/admin/home/data/system-quickview')->assertOk()
        ->assertJsonPath('metrics.stale', $expectedStale);
})->with([
    'exactly fifteen seconds old is fresh' => [15, false],
    'sixteen seconds old is stale'         => [16, true],
]);

it('rejects negative memory bytes as one unavailable memory signal while other signals stay independent', function () {
    fakeHomePresenceRedis();
    writeMetricsSnapshot([
        'schema_version' => 1,
        'collected_at'   => rfc3339Zulu(now()->toImmutable()),
        'signals'        => [
            'cpu'    => ['available' => true, 'percent' => 50],
            'memory' => ['available' => true, 'used_bytes' => -1, 'total_bytes' => 16, 'percent' => 10],
            'gpu'    => ['available' => true, 'percent' => 20],
        ],
    ]);

    $this->getJson('/admin/home/data/system-quickview')->assertOk()
        ->assertJsonPath('metrics.available', true)
        ->assertJsonPath('metrics.memory.available', false)
        ->assertJsonPath('metrics.cpu.available', true)
        ->assertJsonPath('metrics.gpu.available', true);
});

it('rejects memory with used bytes greater than total bytes as unavailable rather than clamping', function () {
    fakeHomePresenceRedis();
    writeMetricsSnapshot([
        'schema_version' => 1,
        'collected_at'   => rfc3339Zulu(now()->toImmutable()),
        'signals'        => [
            'cpu'    => ['available' => true, 'percent' => 50],
            'memory' => ['available' => true, 'used_bytes' => 32, 'total_bytes' => 16, 'percent' => 200],
            'gpu'    => ['available' => true, 'percent' => 20],
        ],
    ]);

    $this->getJson('/admin/home/data/system-quickview')->assertOk()
        ->assertJsonPath('metrics.memory.available', false)
        ->assertJsonPath('metrics.memory.used_bytes', null)
        ->assertJsonPath('metrics.memory.total_bytes', null);
});

it('treats a signal with missing required keys as unavailable independently of the other signals', function () {
    fakeHomePresenceRedis();
    writeMetricsSnapshot([
        'schema_version' => 1,
        'collected_at'   => rfc3339Zulu(now()->toImmutable()),
        'signals'        => [
            'cpu'    => ['available' => true],
            'memory' => ['available' => true, 'used_bytes' => 4, 'total_bytes' => 8, 'percent' => 50],
            'gpu'    => ['available' => true, 'percent' => 20],
        ],
    ]);

    $this->getJson('/admin/home/data/system-quickview')->assertOk()
        ->assertJsonPath('metrics.cpu.available', false)
        ->assertJsonPath('metrics.memory.available', true)
        ->assertJsonPath('metrics.gpu.available', true);
});

it('denies the QuickView data route to unauthenticated requests', function () {
    auth('admin')->logout();

    $this->get('/admin/home/data/system-quickview')->assertRedirect(route('admin.session.create'));
});
