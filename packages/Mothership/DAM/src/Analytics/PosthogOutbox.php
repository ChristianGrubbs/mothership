<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Snapshots only validated, stored events in their transaction. Network delivery never runs in a user request. */
final class PosthogOutbox
{
    public const TABLE = 'dam_posthog_outbox';

    private const NAMESPACE = 'c7fb9536-4765-52d9-b359-7bcf4fe6e71e';

    /** @param list<array<string, mixed>> $rows */
    public function stage(array $rows): void
    {
        if (! config('dam_events.posthog.enabled')) {
            return;
        }

        $existing = DB::table(self::TABLE)->whereIn('event_id', array_column($rows, 'event_id'))
            ->get(['event_id', 'name'])->mapWithKeys(fn ($entry) => [$entry->event_id.'|'.$entry->name => true])->all();
        foreach ($rows as $row) {
            if ($row['suspected_bot'] || $row['actor_type'] === AnalyticsActor::SYSTEM || $row['actor_key'] === '') {
                continue;
            }

            // Success may replace failure locally. Export one immutable transition per outcome, correlated by
            // operation_id; retries of the same outcome reuse its UUID and first timestamp/payload.
            $key = ['event_id' => $row['event_id'], 'name' => $row['name']];
            if (isset($existing[$row['event_id'].'|'.$row['name']])) {
                continue;
            }

            try {
                DB::transaction(fn () => DB::table(self::TABLE)->insert($key + [
                    'payload'      => json_encode($this->payload(row: $row), JSON_THROW_ON_ERROR),
                    'available_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                // Concurrent same-event retry: the first immutable snapshot remains authoritative.
            }
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function payload(array $row): array
    {
        $properties = json_decode((string) ($row['props'] ?? '{}'), true) ?: [];
        // Link view capabilities are local-only. Search text was already separated by the registry.
        unset($properties['view_token']);
        $properties += [
            'distinct_id'              => PosthogIdentity::distinctId(actor: new AnalyticsActor(type: $row['actor_type'], key: $row['actor_key'])),
            '$process_person_profile'  => false,
            '$geoip_disable'           => true,
            'source'                   => 'mothership',
            'environment'              => app()->environment(),
            'schema_version'           => (int) $row['v'],
            'surface'                  => $row['surface'],
            'outcome'                  => $row['outcome'],
            'actor_type'               => $row['actor_type'],
            // Deployment-configured operator/agent/QA accounts, API accounts and visitors of links they created, for
            // the project's test-account filter.
            'internal_actor'           => (bool) ($row['internal_actor'] ?? false) || ($row['actor_type'] === AnalyticsActor::ADMIN
                && in_array((int) $row['actor_key'], (array) config('dam_events.internal_admin_ids', []), true)),
            'operation_id'             => $row['event_id'],
        ];
        if ($row['session_id'] !== null) {
            $properties['journey_id'] = $this->pseudonym(value: 'session:'.$row['actor_key'].':'.$row['session_id']);
        }
        if ($row['duration_ms'] !== null) {
            $properties['duration_ms'] = (int) $row['duration_ms'];
        }
        if (($replaySession = ReplaySession::validate(value: $row['replay_session_id'] ?? null)) !== null) {
            $properties['$session_id'] = $replaySession;
        }
        // Deployment metadata only: never derive this from request input, a URL or a branch name.
        $release = config('dam_events.posthog.release');
        if (is_string($release) && preg_match('/\A[a-zA-Z0-9._-]{1,64}\z/', $release)) {
            $properties['release'] = $release;
        }

        return [
            'uuid'       => Uuid::uuid5(self::NAMESPACE, $row['event_id'].'|'.$row['name'])->toString(),
            'event'      => $row['name'],
            'timestamp'  => Carbon::parse($row['occurred_at'])->utc()->toISOString(),
            'properties' => array_filter($properties, fn ($value) => $value !== null),
        ];
    }

    private function pseudonym(string $value): string
    {
        return hash_hmac('sha256', 'posthog:'.$value, (string) config('app.key'));
    }
}
