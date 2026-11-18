<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Support\AnalyticsLog;
use Mothership\User\Models\Admin;

/**
 * Validates a collector batch and stores what survives (§3.6.2, §3.6.5, §3.6.6).
 *
 * The client may assert only an event name from the client allow-list, its props, a subject id, a timestamp, a
 * sequence number, a surface, a random session id and the coarse device context the registry declares. Everything else is derived here: the actor from the admin
 * session or the share link the capability is bound to, the outcome from the name, the request id from the request.
 *
 * Dropped, and counted under ingest.dropped by reason: an unregistered or server-only name, an undeclared prop or
 * one of the wrong type, a malformed envelope, a subject the derived actor cannot reach through this container, and
 * a session id first seen with another actor. A replayed event id stores nothing. A constant number of queries per
 * batch, whatever its size.
 */
final class Ingest
{
    public const DROPPED = 'ingest.dropped';

    public function __construct(
        private readonly ActorResolver $actors,
        private readonly EventStore $store,
        private readonly SearchTerms $searchTerms,
    ) {}

    /**
     * @param  array<string, mixed>  $batch  the decoded body: {t: token, e: [events]}
     * @param  ?Admin  $admin  the session's admin on the admin endpoint; null on the public one
     */
    public function handle(array $batch, Request $request, ?Admin $admin): IngestResult
    {
        if (! config('dam_events.enabled')) {
            return IngestResult::accepted(stored: 0);
        }

        $claims = IngestToken::verify($batch['t'] ?? null);

        if (is_string($claims)) {
            return $this->refuse(reason: 'token_'.$claims);
        }

        // The capability must be for this endpoint and for exactly what the server knows: the signed-in admin, or a
        // share link that is still live. A token minted for someone else is a forged actor.
        $share = null;

        if ($admin !== null) {
            if ($claims['scope'] !== EventRegistry::SCOPE_ADMIN || $claims['bound'] !== (int) $admin->id) {
                return $this->refuse(reason: 'forged_actor');
            }

            $actor = $this->actors->forAdmin(admin: $admin, request: $request);
            $scope = EventRegistry::SCOPE_ADMIN;
        } else {
            $share = $claims['scope'] === EventRegistry::SCOPE_SHARE ? Share::query()->find($claims['bound']) : null;

            if ($share === null || ! $share->isActive()) {
                return $this->refuse(reason: 'forged_scope');
            }

            $actor = $this->actors->forLinkVisitor(shareId: (int) $share->id, request: $request);
            $scope = EventRegistry::SCOPE_SHARE;
        }

        $events = is_array($batch['e'] ?? null) ? array_values($batch['e']) : [];
        $max = (int) config('dam_events.ingest.max_events', 25);
        $dropped = [];

        if (count($events) > $max) {
            $dropped['over_batch'] = count($events) - $max;
            $events = array_slice($events, 0, $max);
        }

        $received = now();
        $catalogue = EventRegistry::clientCatalogue($scope);
        $valid = [];

        foreach ($events as $event) {
            $parsed = is_array($event) ? $this->parse(event: $event, catalogue: $catalogue, received: $received) : 'malformed';

            if (is_string($parsed)) {
                $dropped[$parsed] = ($dropped[$parsed] ?? 0) + 1;

                continue;
            }

            $valid[] = $parsed;
        }

        // Access before anything is stored: a subject this actor cannot reach through this container is dropped
        // whole, never stored without its subject (§3.6.2).
        $reachable = $this->reachableSubjects(ids: array_values(array_unique(array_filter(array_column($valid, 'subject_id')))), admin: $admin, share: $share);
        $valid = array_values(array_filter($valid, function (array $event) use ($reachable, &$dropped): bool {
            if ($event['subject_id'] === null || isset($reachable[$event['subject_id']])) {
                return true;
            }

            $dropped['restricted_subject'] = ($dropped['restricted_subject'] ?? 0) + 1;

            return false;
        }));

        $stored = $actor->aggregateOnly || $actor->key === null
            ? $this->countOnly(events: $valid, actor: $actor, scope: $scope, share: $share)
            : $this->storeRaw(events: $valid, actor: $actor, admin: $admin, share: $share, dropped: $dropped);

        $this->countDrops(dropped: $dropped, actorType: $actor->type);

        // A link page that ran: its route view returns the view token its load was parked under.
        if ($share !== null) {
            foreach ($valid as $event) {
                if ($event['name'] === DamEvent::RouteViewed->value && is_string($event['props']['view_token'] ?? null)) {
                    app(ShareRepository::class)->confirmVisit(share: $share, viewToken: $event['props']['view_token']);
                }
            }
        }

        AnalyticsLog::write(level: 'debug', event: 'analytics.ingested', ctx: [
            'scope'          => $scope, 'received' => count($events), 'stored' => $stored, 'dropped' => array_sum($dropped),
            'aggregate_only' => $actor->aggregateOnly,
        ]);

        return IngestResult::accepted(stored: $stored, renewal: $this->renewal(claims: $claims, admin: $admin));
    }

    /**
     * One client event, checked against the registry and the envelope rules, or the reason it is dropped.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, int>  $catalogue
     * @return array<string, mixed>|string
     */
    private function parse(array $event, array $catalogue, Carbon $received): array|string
    {
        $name = $event['n'] ?? null;

        if (! is_string($name) || ! EventRegistry::exists($name)) {
            return 'unknown_event';
        }

        if (! isset($catalogue[$name])) {
            return EventRegistry::definition($name)['emitter'] === 'server' ? 'server_only_event' : 'out_of_scope_event';
        }

        $allowed = ['id', 'n', 'ts', 'sid', 'seq', 's', 'sf', 'p', 'd', 'rs', 'x'];

        if (array_diff(array_keys($event), $allowed) !== []) {
            return 'unknown_field';
        }

        $id = $event['id'] ?? null;
        $sessionId = $event['sid'] ?? null;
        $seq = $event['seq'] ?? null;
        $ts = $event['ts'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id) || ! is_string($sessionId) || ! Str::isUuid($sessionId)
            || ! is_int($seq) || $seq < 0 || $seq > 1_000_000_000 || ! is_int($ts)) {
            return 'malformed';
        }

        $props = $event['p'] ?? [];
        $validated = is_array($props) && (array_is_list($props) ? $props === [] : true)
            ? EventRegistry::validateProps(name: $name, props: $props)
            : null;

        $context = $this->context(value: $event['x'] ?? null);

        if ($validated === null || $context === null) {
            return 'unknown_field';
        }

        $definition = EventRegistry::definition($name);
        $subject = $event['s'] ?? null;

        if ($definition['subject'] === null ? $subject !== null : (! is_int($subject) || $subject < 1)) {
            return 'malformed';
        }

        $surface = $event['sf'] ?? null;

        if ($surface !== null && ! in_array($surface, (array) config('dam_events.surfaces', []), true)) {
            return 'unknown_field';
        }

        $duration = $event['d'] ?? null;

        if ($duration !== null && (! is_int($duration) || $duration < 0 || $duration > 86_400_000)) {
            return 'malformed';
        }

        // The client clock, clamped to [received - 24 h, received + 5 min] (§3.6.1): late events are accepted, a
        // future or ancient one cannot move itself out of the window it was sent in.
        $at = Carbon::createFromTimestampMs($ts)->setTimezone($received->getTimezone());
        $earliest = $received->copy()->subHours((int) config('dam_events.ingest.clock_past_hours', 24));
        $latest = $received->copy()->addMinutes((int) config('dam_events.ingest.clock_future_minutes', 5));
        $at = $at->lt($earliest) ? $earliest : ($at->gt($latest) ? $latest : $at);

        return [
            'event_id'          => strtolower($id),
            'name'              => $name,
            'at'                => $at,
            'session_id'        => strtolower($sessionId),
            'replay_session_id' => ReplaySession::validate(value: $event['rs'] ?? null),
            'seq'               => $seq,
            'subject_type'      => $definition['subject'],
            'subject_id'        => $subject,
            'surface'           => $surface,
            'props'             => $validated[0] + $context,
            'term'              => $validated[1],
            'duration_ms'       => $duration,
        ];
    }

    /**
     * The envelope's device context as stored props, [] when absent, or null when any key or value is undeclared.
     *
     * @return ?array<string, string>
     */
    private function context(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }

        $declared = (array) config('dam_events.client_context', []);
        $keys = ['dc' => 'device_class', 'vw' => 'viewport_width'];

        if (! is_array($value) || array_is_list($value) && $value !== [] || array_diff(array_keys($value), array_keys($keys)) !== []) {
            return null;
        }

        $context = [];

        foreach ($value as $short => $item) {
            $prop = $keys[$short];

            if (! is_string($item) || ! in_array($item, (array) ($declared[$prop] ?? []), true)) {
                return null;
            }

            $context[$prop] = $item;
        }

        return $context;
    }

    /**
     * The asset ids among these that the actor may reach through this container: for an admin, what their Library
     * lists; for a share, the linked asset or the files out for use in the linked folder. One query.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function reachableSubjects(array $ids, ?Admin $admin, ?Share $share): array
    {
        if ($ids === []) {
            return [];
        }

        $query = Asset::query()->whereIn('dam_assets.id', $ids)->select('dam_assets.id');

        if ($admin !== null) {
            app(AssetAccessDecision::class)->scopeListable($query, AccessContext::forAdmin(admin: $admin, purpose: AccessContext::PURPOSE_LIST));
        } elseif ($share !== null && $share->share_type === Share::TYPE_ASSET) {
            $query->where('dam_assets.id', (int) $share->target_id)->availableNow();
        } elseif ($share !== null && ($directory = $share->directory) !== null) {
            $query->whereHas('directories', fn ($q) => $q->whereBetween('_lft', [$directory->_lft, $directory->_rgt]))->availableNow();
        } else {
            return [];
        }

        return array_fill_keys($query->pluck('dam_assets.id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, int>  $dropped
     */
    private function storeRaw(array $events, AnalyticsActor $actor, ?Admin $admin, ?Share $share, array &$dropped): int
    {
        $owners = $this->store->sessionOwners(array_values(array_unique(array_column($events, 'session_id'))));
        $replayOwners = $this->store->replaySessionOwners(array_values(array_unique(array_filter(array_column($events, 'replay_session_id')))));
        $rows = [];
        $searches = [];

        foreach ($events as $event) {
            $owner = $owners[$event['session_id']] ?? null;

            if ($owner !== null && $owner !== $actor->key) {
                $dropped['session_reuse'] = ($dropped['session_reuse'] ?? 0) + 1;

                continue;
            }

            $rows[] = DamEvents::row(name: $event['name'], actor: $actor, at: $event['at'], fields: [
                'event_id'          => $event['event_id'],
                'session_id'        => $event['session_id'],
                'replay_session_id' => ($replayOwners[$event['replay_session_id']] ?? $actor->key) === $actor->key
                    ? ReplaySession::forActor(actor: $actor, value: $event['replay_session_id']) : null,
                'seq'           => $event['seq'],
                'surface'       => $share !== null ? 'share' : $event['surface'],
                'subject_type'  => $event['subject_type'],
                'subject_id'    => $event['subject_id'],
                'share_id'      => $share?->id,
                'outcome'       => DamEvent::from($event['name'])->outcome(),
                'props'         => $event['props'],
                'duration_ms'   => $event['duration_ms'],
                'emitter'       => 'client',
            ]);

            if ($event['term'] !== null && $admin !== null) {
                $searches[$event['event_id']] = ['term' => $event['term'], 'result_count' => (int) ($event['props']['result_count'] ?? 0), 'at' => $event['at']];
            }
        }

        $inserted = $this->store->insertRaw(rows: $rows);

        // A term is kept once per event: a resent search.submitted adds no second row, because its event id was
        // already stored and nothing was inserted for it this time.
        $fresh = array_intersect_key($searches, array_flip($inserted));

        if ($fresh !== [] && $admin !== null) {
            $this->searchTerms->keep(admin: $admin, searches: array_values($fresh));
        }

        return count($inserted);
    }

    /**
     * The events of an aggregate-only actor (§3.6.7): counted per slice, with no row, key, session, seq, request id
     * or term. Identical events in a batch collapse into one increment.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function countOnly(array $events, AnalyticsActor $actor, string $scope, ?Share $share): int
    {
        $slices = [];

        foreach ($events as $event) {
            $slice = [
                'day'          => $event['at']->toDateString(),
                'name'         => $event['name'],
                'outcome'      => DamEvent::from($event['name'])->outcome(),
                'actor_type'   => $actor->type,
                'surface'      => $scope === EventRegistry::SCOPE_SHARE ? 'share' : (string) $event['surface'],
                'subject_type' => (string) $event['subject_type'],
                'subject_id'   => (int) $event['subject_id'],
                'share_id'     => (int) $share?->id,
                'detail'       => DamEvents::detail(props: $event['props']),
            ];
            $key = implode('|', $slice);
            $slices[$key] = ['slice' => $slice, 'events' => ($slices[$key]['events'] ?? 0) + 1];
        }

        foreach ($slices as $entry) {
            $this->store->increment(slice: $entry['slice'], events: $entry['events'], bot: $actor->suspectedBot);
        }

        return count($events);
    }

    /** @param array<string, int> $dropped */
    private function countDrops(array $dropped, string $actorType): void
    {
        foreach ($dropped as $reason => $count) {
            $this->store->increment(slice: [
                'day'        => now()->toDateString(), 'name' => self::DROPPED, 'outcome' => EventRegistry::OUTCOME_FAILURE,
                'actor_type' => $actorType, 'detail' => $reason,
            ], events: $count);
        }
    }

    /** A new admin token when this one is close to expiring; the page keeps sending without a reload. */
    private function renewal(array $claims, ?Admin $admin): ?string
    {
        if ($admin === null || 60 * (int) config('dam_events.ingest.token_renew_minutes', 30) < $claims['expires'] - now()->getTimestamp()) {
            return null;
        }

        return IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id);
    }

    private function refuse(string $reason): IngestResult
    {
        AnalyticsLog::write(level: 'warning', event: 'analytics.batch_refused', ctx: ['reason' => $reason]);

        return IngestResult::refused(reason: $reason);
    }
}
