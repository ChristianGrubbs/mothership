<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\Support\AnalyticsLog;

/** Drains the committed outbox with bounded retries. Capture UUIDs make an interrupted delivery retry idempotent. */
final class PosthogExporter
{
    /** Count-only operational snapshot. Reading health never emits an event or changes delivery state.
     * @return array{pending: int, failed: int, oldest_pending_age_seconds: ?int, last_delivered_at: ?string}
     */
    public function health(): array
    {
        $pending = DB::table(PosthogOutbox::TABLE)->where('state', 'pending');
        $oldest = (clone $pending)->join(EventStore::TABLE, EventStore::TABLE.'.event_id', '=', PosthogOutbox::TABLE.'.event_id')
            ->min(EventStore::TABLE.'.received_at');
        $last = DB::table(PosthogOutbox::TABLE)->max('delivered_at');

        return [
            'pending' => $pending->count(),
            'failed'  => DB::table(PosthogOutbox::TABLE)->where('state', 'failed')->count(),
            // received_at survives rescheduling: backoff must not make an old backlog appear fresh.
            'oldest_pending_age_seconds' => $oldest === null ? null : max(0, (int) now()->diffInSeconds(Carbon::parse($oldest), false) * -1),
            'last_delivered_at'          => $last === null ? null : (string) $last,
        ];
    }

    /** @return array{sent: int, failed: int, discarded: int} */
    public function drain(int $limit = 100): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'discarded' => 0];
        if (! config('dam_events.posthog.enabled')) {
            return $counts;
        }
        $host = rtrim((string) config('dam_events.posthog.host'), '/');
        $key = (string) config('dam_events.posthog.key');
        if ($key === '' || ! str_starts_with($host, 'https://')) {
            AnalyticsLog::write(level: 'error', event: 'posthog.configuration_missing', ctx: []);
            throw new \RuntimeException('PostHog capture configuration is incomplete.');
        }

        // A single exporter avoids unnecessary duplicate HTTP calls; UUID dedup still covers process death.
        $lock = Cache::lock('dam.posthog.export', 2400);
        if (! $lock->get()) {
            return $counts;
        }
        try {
            $pending = DB::table(PosthogOutbox::TABLE)->where('state', 'pending')->where('available_at', '<=', now())
                ->orderBy('id')->limit(max(1, min(200, $limit)))->get();
            foreach ($pending as $entry) {
                $source = DB::table(EventStore::TABLE)->where('event_id', $entry->event_id)->first();
                // An opt-out after collection but before delivery also stops the pending external export.
                $optedOut = $source !== null && $source->actor_type === AnalyticsActor::ADMIN
                    && DB::table(OptOuts::TABLE)->where('admin_id', $source->actor_key)->exists();
                if ($source === null || $optedOut) {
                    DB::table(PosthogOutbox::TABLE)->where('id', $entry->id)->update(['state' => 'discarded', 'payload' => null]);
                    $counts['discarded']++;

                    continue;
                }
                $payload = json_decode($entry->payload, true, flags: JSON_THROW_ON_ERROR);
                $error = null;
                $permanent = false;
                $unavailable = false;
                try {
                    $response = Http::connectTimeout(3)->timeout(10)->acceptJson()->post($host.'/capture/', ['api_key' => $key] + $payload);
                    if (! $response->successful()) {
                        $error = 'http_'.$response->status();
                        $permanent = in_array($response->status(), [400, 413, 422], true);
                        $unavailable = ! $permanent;
                    }
                } catch (ConnectionException) {
                    $error = 'connection';
                    $unavailable = true;
                }
                if ($error === null) {
                    DB::table(PosthogOutbox::TABLE)->where('id', $entry->id)->update([
                        'state' => 'sent', 'payload' => null, 'delivered_at' => now(), 'last_error' => null,
                    ]);
                    $counts['sent']++;
                } else {
                    $attempts = min(65535, (int) $entry->attempts + 1);
                    DB::table(PosthogOutbox::TABLE)->where('id', $entry->id)->update([
                        'attempts'     => $attempts, 'last_error' => $error,
                        'state'        => $permanent || $attempts >= 12 ? 'failed' : 'pending',
                        'payload'      => $permanent ? null : $entry->payload,
                        'available_at' => now()->addSeconds(min(3600, 30 * (2 ** min($attempts, 7)))),
                    ]);
                    $counts['failed']++;
                    AnalyticsLog::write(level: 'error', event: 'posthog.delivery_failed', ctx: ['reason' => $error, 'attempts' => $attempts]);
                    if ($unavailable) {
                        break;
                    }
                }
            }
        } finally {
            $lock->release();
        }
        AnalyticsLog::write(level: 'debug', event: 'posthog.exported', ctx: $counts);

        return $counts;
    }
}
