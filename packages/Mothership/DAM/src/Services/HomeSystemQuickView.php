<?php

namespace Mothership\DAM\Services;

use Carbon\CarbonImmutable;
use Mothership\User\Models\Admin;
use Throwable;

class HomeSystemQuickView
{
    private const POLL_AFTER_SECONDS = 10;

    public function __construct(
        private SystemMetricsSnapshot $snapshots,
        private HomePresence $presence,
    ) {}

    /**
     * Isolates the metrics and presence domains from each other: a failure in one never hides
     * a valid reading from the other.
     *
     * @return array{server_time:string,poll_after_seconds:int,metrics:array,presence:array}
     */
    public function payload(Admin $viewer, CarbonImmutable $now): array
    {
        try {
            $metrics = $this->snapshots->read(
                path: config('dam-home.system_metrics_path', storage_path('app/private/system-quickview.json')),
                now: $now,
            );
        } catch (Throwable $error) {
            $metrics = $this->snapshots->unavailable();
            HomeTelemetry::record(event: 'system_metrics.read_failed', ctx: ['exception' => $error::class]);
        }

        try {
            $presence = $this->presence->activeUsers(viewer: $viewer, now: $now);

            // HomePresence swallows Redis/connection failures internally and returns
            // available:false without exposing the caught Throwable, so the dominant
            // outage path is only observable here via the flag, not via a catch.
            if ($presence['available'] === false) {
                HomeTelemetry::record(event: 'presence.read_failed', ctx: ['exception' => Throwable::class]);
            }
        } catch (Throwable $error) {
            $presence = ['available' => false, 'online_count' => 0, 'users' => []];
            HomeTelemetry::record(event: 'presence.read_failed', ctx: ['exception' => $error::class]);
        }

        return [
            'server_time'        => $now->toIso8601String(),
            'poll_after_seconds' => self::POLL_AFTER_SECONDS,
            'metrics'            => $metrics,
            'presence'           => $presence,
        ];
    }
}
