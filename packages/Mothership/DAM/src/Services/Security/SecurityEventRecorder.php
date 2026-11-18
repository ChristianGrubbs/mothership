<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Support\RequestId;
use Mothership\DAM\Support\SecurityEventLog;
use Throwable;

/**
 * Writes dam_security_events (P1a). Minimized at the source (§3.6.7): the login is stored only as an HMAC, the
 * session only as a hash, the user agent only as a browser family, and ctx only under the keys below.
 *
 * Two entry points: record() never reaches the caller with a failure (a login carries on), recordAll() throws so
 * a caller that can still roll back (a move) fails with its events. Both write in their own savepoint, so a
 * failure inside a caller's transaction on PostgreSQL does not abort that transaction with it.
 */
class SecurityEventRecorder
{
    public const TABLE = 'dam_security_events';

    /** The only ctx keys stored; values must be scalars, strings at most 64 characters. */
    public const CTX_KEYS = [
        'method', 'provider', 'reason', 'account_exists', 'remember',
        'subject_id', 'group_id', 'directory_id', 'is_directory', 'users', 'groups', 'members', 'folders', 'files',
        'to_lists', 'forced', 'report', 'range_days', 'moved', 'asset_id', 'rows',
        // P5a guest intake.
        'upload_request_id', 'submission_id',
    ];

    /** Bound values per insert statement: under SQL Server's 2,100-parameter limit, the lowest of the four. */
    private const MAX_BINDINGS = 2000;

    /** Lowercased user-agent substrings of crawlers and link unfurlers ('preview' covers SkypeUriPreview/Teams and BingPreview; facebookexternalhit also iMessage). */
    private const CRAWLER_MARKERS = [
        'bot', 'spider', 'crawl', 'preview', 'facebookexternalhit', 'whatsapp', 'embedly', 'iframely', 'headlesschrome',
        'pagerenderer', 'vkshare', 'slurp', 'microsoft office', 'ms-office',
    ];

    /**
     * Best effort, for callers that must never be blocked by the audit store: a login, logout, password reset or
     * SSO callback, a report view, and an access change FolderAccessLog writes after the change has committed.
     * A failure is logged to security-events.jsonl and swallowed. Anything that can still roll back with its
     * events (a move) calls recordAll() instead.
     */
    public function record(SecurityEvent $event): void
    {
        try {
            $this->recordAll(events: [$event]);
        } catch (Throwable $e) {
            SecurityEventLog::write(level: 'error', event: 'security_event.write_failed', ctx: [
                'event' => $event->name, 'exception' => $e::class,
            ]);
        }
    }

    /**
     * Every event or none, and a failure throws: inside a caller's transaction the caller fails with its events
     * rather than committing without them. One row per event, in inserts sized by bound values (a row binds one
     * value per column) so no statement passes SQL Server's limit, all inside one savepoint: a later batch that
     * fails takes the earlier ones with it and leaves the caller's transaction usable, PostgreSQL's included.
     *
     * @param  list<SecurityEvent>  $events
     */
    public function recordAll(array $events): void
    {
        if ($events === []) {
            return;
        }

        $request = app()->bound('request') ? request() : null;
        $ip = $request?->ip();
        $shared = [
            'occurred_at'       => now(),
            'ip'                => $ip,
            'ip_truncated'      => self::truncateIp(ip: $ip),
            'user_agent_family' => self::userAgentFamily(userAgent: $request?->userAgent()),
            'request_id'        => RequestId::current(),
            'session_hash'      => self::sessionHash(request: $request),
        ];
        $rows = array_map(fn (SecurityEvent $event) => $shared + [
            'event'           => $event->name,
            'guard'           => $event->guard,
            'actor_admin_id'  => $event->actorAdminId,
            'identifier_hash' => self::identifierHash(identifier: $event->identifier),
            'ctx'             => self::encodeCtx(ctx: $event->ctx),
        ], $events);
        $perInsert = max(1, intdiv(self::MAX_BINDINGS, count($rows[0])));

        DB::transaction(function () use ($rows, $perInsert): void {
            foreach (array_chunk($rows, $perInsert) as $batch) {
                DB::table(self::TABLE)->insert($batch);
            }
        });
    }

    /** HMAC-SHA256 of the trimmed, lowercased login, keyed with APP_KEY, so equal logins group without being stored. */
    public static function identifierHash(?string $identifier): ?string
    {
        $normalized = mb_strtolower(trim((string) $identifier));

        return $normalized === '' ? null : hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /** IPv4 to its /24 and IPv6 to its /48; an IPv4-mapped IPv6 address is treated as the IPv4 address. */
    public static function truncateIp(?string $ip): ?string
    {
        $packed = $ip === null ? false : @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $truncated = strlen($packed) === 4
            ? substr($packed, 0, 3)."\0"
            : substr($packed, 0, 6).str_repeat("\0", 10);

        return inet_ntop($truncated) ?: null;
    }

    /** Coarse on purpose: a browser family, never the raw string, which is close to a fingerprint. */
    public static function userAgentFamily(?string $userAgent): ?string
    {
        $ua = strtolower(trim((string) $userAgent));

        return match (true) {
            $ua === ''                                                                                                                                           => null,
            self::isCrawler(ua: $ua)                                                                                                                             => 'Bot',
            str_contains($ua, 'curl') || str_contains($ua, 'wget') || str_contains($ua, 'python') || str_contains($ua, 'guzzle') || str_contains($ua, 'symfony') => 'Script',
            str_contains($ua, 'edg/') || str_contains($ua, 'edge/')                                                                                              => 'Edge',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera')                                                                                              => 'Opera',
            str_contains($ua, 'firefox') || str_contains($ua, 'fxios')                                                                                           => 'Firefox',
            str_contains($ua, 'chrome') || str_contains($ua, 'crios') || str_contains($ua, 'chromium')                                                           => 'Chrome',
            str_contains($ua, 'safari')                                                                                                                          => 'Safari',
            default                                                                                                                                              => 'Other',
        };
    }

    /**
     * Crawlers, link unfurlers and headless scanners. Chrome or Edge newer than 109 on Windows XP through 8.1 is a
     * scanner: Chrome dropped those systems after 109. A rare Chromium fork that still runs there (Supermium) is
     * left out of analytics with it; the visit itself is never refused. $ua is already lowercased.
     */
    private static function isCrawler(string $ua): bool
    {
        foreach (self::CRAWLER_MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return true;
            }
        }

        return ! str_contains($ua, 'firefox')
            && preg_match('/windows nt (?:5\.\d|6\.[0-3])\b/', $ua) === 1
            && preg_match('/(?:chrome|edg)\/(\d+)/', $ua, $match) === 1
            && (int) $match[1] > 109;
    }

    private static function sessionHash(?Request $request): ?string
    {
        if ($request === null || ! $request->hasSession()) {
            return null;
        }

        $id = (string) $request->session()->getId();

        return $id === '' ? null : hash('sha256', $id);
    }

    /** @param array<string, mixed> $ctx */
    private static function encodeCtx(array $ctx): ?string
    {
        $kept = array_filter(
            array_intersect_key($ctx, array_flip(self::CTX_KEYS)),
            fn ($value) => is_int($value) || is_bool($value) || (is_string($value) && mb_strlen($value) <= 64),
        );

        return $kept === [] ? null : json_encode($kept, JSON_THROW_ON_ERROR);
    }
}
