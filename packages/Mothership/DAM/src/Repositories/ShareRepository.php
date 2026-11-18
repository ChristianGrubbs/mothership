<?php

namespace Mothership\DAM\Repositories;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mothership\Core\Eloquent\Repository;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\CollectorConfig;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Models\Share;

class ShareRepository extends Repository
{
    /** Cache key prefix: view token => the link and visitor of a visit whose page has not yet reported back. */
    private const PENDING_VIEW = 'dam:share-view:';

    /** Cache key prefix: link and visitor => a view already counted, held for the visit window so a refresh counts once. */
    private const COUNTED_VIEW = 'dam:share-counted:';

    public function model(): string
    {
        return Share::class;
    }

    public function queryBuilder()
    {
        return $this;
    }

    /** @param  array{password_hash?: ?string, allow_download?: bool, requires_creator_access?: bool}  $access  ShareAccessOptions::columns() */
    public function createForAsset(int $assetId, ?DateTimeInterface $expiresAt, ?int $userId, ?string $name = null, array $access = []): Share
    {
        return $this->created(share: $this->create(Share::tokenColumns($this->generateUniqueToken()) + $this->accessColumns($access) + [
            'name'       => $name,
            'share_type' => Share::TYPE_ASSET,
            'target_id'  => $assetId,
            'created_by' => $userId,
            'expires_at' => $expiresAt ? Carbon::instance($expiresAt) : null,
        ]));
    }

    /** @param  array{password_hash?: ?string, allow_download?: bool, requires_creator_access?: bool}  $access  ShareAccessOptions::columns() */
    public function createForDirectory(int $directoryId, ?DateTimeInterface $expiresAt, ?int $userId, ?string $name = null, array $access = []): Share
    {
        return $this->created(share: $this->create(Share::tokenColumns($this->generateUniqueToken()) + $this->accessColumns($access) + [
            'name'       => $name,
            'share_type' => Share::TYPE_DIRECTORY,
            'target_id'  => $directoryId,
            'created_by' => $userId,
            'expires_at' => $expiresAt ? Carbon::instance($expiresAt) : null,
        ]));
    }

    /**
     * P4 D2: a public collection's link. CollectionService::createLink() is the one caller, after it has checked the
     * audience and that no live link exists.
     *
     * @param  array{password_hash?: ?string, allow_download?: bool, requires_creator_access?: bool}  $access  ShareAccessOptions::columns()
     */
    public function createForCollection(int $collectionId, ?DateTimeInterface $expiresAt, ?int $userId, ?string $name = null, array $access = []): Share
    {
        return $this->created(share: $this->create(Share::tokenColumns($this->generateUniqueToken()) + $this->accessColumns($access) + [
            'name'       => $name,
            'share_type' => Share::TYPE_COLLECTION,
            'target_id'  => $collectionId,
            'created_by' => $userId,
            'expires_at' => $expiresAt ? Carbon::instance($expiresAt) : null,
        ]));
    }

    /** Only the P2 S5b option columns, so a caller can never set a token or target through them. */
    protected function accessColumns(array $access): array
    {
        return array_intersect_key($access, array_flip(['password_hash', 'allow_download', 'requires_creator_access']));
    }

    public function findActiveByToken(string $token): ?Share
    {
        $share = $this->findByToken($token);

        if (! $share || ! $share->isActive()) {
            return null;
        }

        return $share;
    }

    /**
     * The share a URL token opens, by its hash (P2 S5a): the database compares a SHA-256 digest through the unique
     * index, never the token itself. A legacy row (no hash yet) still matches its plaintext and is re-stored hashed
     * on that first use.
     */
    public function findByToken(string $token): ?Share
    {
        $hash = Share::hashToken($token);

        return $this->findByHash($hash)
            ?? $this->upgradeLegacy(token: $token)
            // Another request (or dam:shares:hash-tokens) may have re-stored the row between the two reads above, so
            // its plaintext is gone and only its hash finds it now.
            ?? $this->findByHash($hash);
    }

    protected function findByHash(string $hash): ?Share
    {
        return $this->model->newQuery()->where('token_hash', $hash)->first();
    }

    /**
     * The legacy row the database's own comparison matches to $token. Under a case-insensitive collation
     * (MySQL/MariaDB utf8mb4_unicode_ci) that includes a differently cased token, so upgradeLegacy() still requires
     * an exact match.
     *
     * @return object{id: int, token: string}|null
     */
    protected function legacyMatch(string $token): ?object
    {
        return $this->model->newQuery()->toBase()->whereNull('token_hash')->where('token', $token)->first(['id', 'token']);
    }

    /**
     * Re-stores a legacy plaintext row hashed in one conditional write, only while it still has no hash: two first
     * uses at once write it once, and both then find it by its hash. Only a byte-for-byte match of the stored token
     * counts, and the new columns come from the STORED token, so the row keeps exactly the URL it was issued with
     * and a differently cased request answers like an unknown token.
     */
    protected function upgradeLegacy(string $token): ?Share
    {
        $legacy = $this->legacyMatch($token);

        if ($legacy === null || ! is_string($legacy->token) || ! hash_equals($legacy->token, $token)) {
            return null;
        }

        $this->model->newQuery()->toBase()
            ->where('id', $legacy->id)
            ->whereNull('token_hash')
            ->update(Share::tokenColumns($legacy->token));

        return $this->findByHash(Share::hashToken($legacy->token));
    }

    /**
     * Reissue (P2 S5a): a new token for the same link, in place, so its counts, name and expiry stay. The old URL's
     * hash then matches nothing, and it answers exactly like a token that never existed. One conditional write, only
     * while the link is still active, so it cannot revive a link revoked or expired meanwhile. Null when it was not.
     * P4 D23: the same write retires the link's custom slug, so a reissue cuts off everyone who had the link, by its
     * slug too. The slug stays registered to the link (AddressSlugs), which may set it again on purpose.
     */
    public function reissue(Share $share): ?Share
    {
        $updated = $this->model->newQuery()
            ->where('id', $share->id)
            ->active()
            ->update(Share::tokenColumns($this->generateUniqueToken()) + ['slug' => null]);

        return $updated === 1 ? $share->fresh() : null;
    }

    public function revoke(int $id): bool
    {
        $share = $this->find($id);

        if (! $share || $share->isRevoked()) {
            return false;
        }

        $share->revoked_at = now();
        $share->save();

        DamEvents::emit(event: DamEvent::ShareRevoked, subjectId: (int) $share->target_id, subjectType: $share->share_type, props: ['share_type' => $share->share_type], shareId: (int) $share->id);

        return true;
    }

    public function hardDelete(int $id): bool
    {
        $share = $this->find($id);

        if (! $share) {
            return false;
        }

        return (bool) $share->delete();
    }

    /**
     * $renewExpiry true sets $expiresAt in the same write that clears the revocation, so the link is never
     * live under its old expiry.
     */
    public function reauthorize(int $id, bool $renewExpiry = false, ?DateTimeInterface $expiresAt = null): bool
    {
        $share = $this->find($id);

        if (! $share || ! $share->isRevoked()) {
            return false;
        }

        $share->revoked_at = null;

        if ($renewExpiry) {
            $share->expires_at = $expiresAt ? Carbon::instance($expiresAt) : null;
        }

        $share->save();

        return true;
    }

    public function incrementView(Share $share): void
    {
        $this->model->newQuery()
            ->where('id', $share->id)
            ->increment('view_count', 1, ['last_accessed_at' => now()]);
    }

    public function incrementDownload(Share $share): void
    {
        $this->model->newQuery()
            ->where('id', $share->id)
            ->increment('download_count', 1, ['last_accessed_at' => now()]);
    }

    /**
     * A load of a link's page (P1b, §3.6.4): recorded as share.link.visited, and added to the link's view count unless
     * it repeats the same visitor's visit within 30 minutes, so a refresh counts once. A suspected bot still moves the
     * link's counter, as it always has, but never an analytics figure. A browser sending DNT or GPC has no visitor
     * key, cannot be told from the next one, and counts every load.
     *
     * A page that carries the collector counts only once its route view brings the view token back (confirmVisit()):
     * mail link scanners fetch a link without running it and were counted as views (PostHog UX review 2026-10-30,
     * finding 5). A page without the collector (DNT, GPC, analytics off) counts on load, as before.
     *
     * @return string the page's view token, which the page's collector sends back with its preview event
     */
    public function recordVisit(Share $share, Request $request): string
    {
        $viewToken = (string) Str::uuid();
        $actor = app(ActorResolver::class)->forLinkVisitor(shareId: (int) $share->id, request: $request);
        $result = DamEvents::emit(
            event: DamEvent::ShareVisited,
            subjectId: (int) $share->target_id,
            subjectType: $share->share_type,
            props: ['share_type' => $share->share_type, 'view_token' => $viewToken],
            surface: 'share',
            actor: $actor,
            shareId: (int) $share->id,
        );

        if (CollectorConfig::collecting(request: $request)) {
            // Parked even when the visit repeats an earlier load: that load may never have run, so the repeat is
            // judged against counted views (confirmVisit()), not against loads.
            Cache::put(
                self::PENDING_VIEW.$viewToken,
                ['share' => (int) $share->id, 'visitor' => $actor->key],
                now()->addMinutes((int) config('dam_events.ingest.token_ttl_minutes', 120)),
            );
            $this->markAccessed(share: $share);
        } elseif ($result === DamEvents::REPEAT) {
            $this->markAccessed(share: $share);
        } else {
            $this->incrementView($share);
        }

        return $viewToken;
    }

    /**
     * The page of a visit ran: its view counts, once, for the link it was rendered for, unless the same visitor's
     * view of this link was already counted within the visit window (a refresh). The token is consumed under a lock
     * so two confirmations of one token cannot both count.
     */
    public function confirmVisit(Share $share, string $viewToken): void
    {
        $key = self::PENDING_VIEW.$viewToken;
        $pending = Cache::lock($key.':lock', 10)->get(fn () => Cache::pull($key));

        if (! is_array($pending) || ($pending['share'] ?? null) !== (int) $share->id) {
            return;
        }

        $visitor = $pending['visitor'] ?? null;
        $window = (int) (config('dam_events.events')['share.link.visited']['window'] ?? 30);

        if ($visitor === null || Cache::add(self::COUNTED_VIEW.(int) $share->id.':'.$visitor, true, now()->addMinutes($window))) {
            $this->incrementView($share);
        }
    }

    /**
     * A file served through a link: asset.download.served, and the link's download count under the same rule as
     * recordVisit (a re-download within 10 minutes counts once). $subjectId and $subjectType name the file, or the
     * folder a zip was made from.
     */
    public function recordDownload(Share $share, Request $request, int $subjectId, string $subjectType, string $via, ?int $attachmentId = null): void
    {
        $result = DamEvents::emit(
            event: DamEvent::DownloadServed,
            subjectId: $subjectId,
            subjectType: $subjectType,
            props: ['via' => $via],
            surface: 'share',
            actor: app(ActorResolver::class)->forLinkVisitor(shareId: (int) $share->id, request: $request),
            shareId: (int) $share->id,
            attachmentId: $attachmentId,
        );

        $result === DamEvents::REPEAT ? $this->markAccessed(share: $share) : $this->incrementDownload($share);
    }

    protected function markAccessed(Share $share): void
    {
        $this->model->newQuery()->where('id', $share->id)->update(['last_accessed_at' => now()]);
    }

    protected function created(Share $share): Share
    {
        DamEvents::emit(
            event: DamEvent::ShareCreated,
            subjectId: (int) $share->target_id,
            subjectType: $share->share_type,
            props: ['share_type' => $share->share_type, 'expires' => $share->expires_at !== null],
            shareId: (int) $share->id,
        );

        return $share;
    }

    protected function generateUniqueToken(): string
    {
        do {
            $token = Str::random(40);
        } while ($this->model->newQuery()->where('token_hash', Share::hashToken($token))->orWhere('token', $token)->exists());

        return $token;
    }
}
