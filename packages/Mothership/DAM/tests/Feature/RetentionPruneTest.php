<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\Security\SecurityEventRecorder;

/** P1a retention: security events on the operator's Q1 schedule; audits only on an explicit, approved --apply. */
function securityEventAged(int $days, ?string $ip, ?string $truncated): int
{
    return DB::table(SecurityEventRecorder::TABLE)->insertGetId([
        'occurred_at' => now()->subDays($days), 'event' => 'auth.login.failed.v1', 'ip' => $ip, 'ip_truncated' => $truncated,
    ]);
}

function auditAged(int $days, string $type): void
{
    DB::table('audits')->insert([
        'event'      => 'updated', 'auditable_type' => $type, 'auditable_id' => 1, 'tags' => 'p1a-prune', 'history_id' => 1,
        'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
    ]);
}

it('drops the full IP after 90 days and the row after a year, and a second run changes nothing', function () {
    $recent = securityEventAged(days: 30, ip: '203.0.113.7', truncated: '203.0.113.0');
    $old = securityEventAged(days: 100, ip: '2001:db8:abcd:12::1', truncated: '2001:db8:abcd::');
    $untruncated = securityEventAged(days: 100, ip: '198.51.100.9', truncated: null);
    $expired = securityEventAged(days: 400, ip: null, truncated: '192.0.2.0');

    $this->artisan('dam:security-events:prune')->assertSuccessful();

    $rows = DB::table(SecurityEventRecorder::TABLE)->whereIn('id', [$recent, $old, $untruncated, $expired])->get()->keyBy('id');

    expect($rows[$recent]->ip)->toBe('203.0.113.7')
        ->and($rows[$old]->ip)->toBeNull()->and($rows[$old]->ip_truncated)->toBe('2001:db8:abcd::')
        ->and($rows[$untruncated]->ip)->toBeNull()->and($rows[$untruncated]->ip_truncated)->toBe('198.51.100.0')
        ->and($rows->has($expired))->toBeFalse();

    $before = DB::table(SecurityEventRecorder::TABLE)->get();
    $this->artisan('dam:security-events:prune')->expectsOutputToContain('Truncated 0 IP address(es)')->assertSuccessful();
    expect(DB::table(SecurityEventRecorder::TABLE)->get())->toEqual($before);
});

it('refuses to prune audits without --older-than', function () {
    $this->artisan('dam:audits:prune')->assertExitCode(2);
});

it('reports audits per type on a dry run and deletes only older rows with --apply', function () {
    auditAged(days: 400, type: 'Mothership\DAM\Models\Asset');
    auditAged(days: 400, type: 'Mothership\DAM\Models\Asset');
    auditAged(days: 400, type: 'Mothership\Product\Models\Product');
    auditAged(days: 10, type: 'Mothership\DAM\Models\Asset');
    $tagged = fn () => DB::table('audits')->where('tags', 'p1a-prune');

    $this->artisan('dam:audits:prune', ['--older-than' => 365])
        ->expectsTable(['auditable_type', 'rows before cutoff'], [['Mothership\DAM\Models\Asset', 2], ['Mothership\Product\Models\Product', 1]])
        ->expectsOutputToContain('Dry run: nothing deleted')
        ->assertSuccessful();
    expect($tagged()->count())->toBe(4);

    $this->artisan('dam:audits:prune', ['--older-than' => 365, '--apply' => true, '--chunk' => 2])
        ->expectsOutputToContain('Deleted 3 audit row(s)')
        ->assertSuccessful();
    expect($tagged()->pluck('created_at')->map(fn ($at) => now()->diffInDays($at, absolute: true) < 30)->all())->toBe([true]);
});
