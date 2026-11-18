<?php

namespace Mothership\DAM\Services\Share;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Access\CollectionGrant;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\ShareLog;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * P2 S5b: who a public link opens for, decided once for every share-scoped route.
 *
 * - **Resolution.** share()/activeShare() are the public controllers' one way from a URL token to its link. A link
 *   whose creator-access option is on and whose creator can no longer see the target comes back revoked (in memory
 *   only, never saved), so every route answers exactly as it does for a revoked link and the visitor learns nothing
 *   about why. Evaluated on every request; the answer is kept only for the rest of that request.
 * - **Collections (P4 D2).** A collection's link (`share_type = collection`) opens only while its collection is
 *   public and live (active, not expired). Otherwise it comes back expired, in memory only, so every route answers
 *   exactly as it does for an expired link. Creator access asks whether the creator still manages the collection.
 * - **Password.** A link with a password opens only with the unlock cookie: HttpOnly, SameSite=Lax, a browser-session
 *   cookie scoped to the link's own URL path, whose value is the share id plus an HMAC (APP_KEY) over the id, the
 *   password's hash and the token's hash. Changing the password or reissuing the link changes that fingerprint, so
 *   every cookie issued before stops opening it. A sandboxed web page (ADR 0007) cannot send a SameSite cookie, so
 *   its frame gets a short-lived page grant in the URL, signed over the same fingerprint.
 * - **Throttle.** Wrong passwords count per link and visitor (IP) and per link: ATTEMPTS_PER_VISITOR and
 *   ATTEMPTS_PER_LINK a minute, then a lockout for the rest of that minute. Every attempt is logged to ShareLog by
 *   share id only.
 */
class ShareGate
{
    public const COOKIE = 'dam_share_pass';

    public const ATTEMPTS_PER_VISITOR = 5;

    public const ATTEMPTS_PER_LINK = 30;

    public const DECAY_SECONDS = 60;

    public const PAGE_GRANT_TTL = 3600;

    /** The landing pages: a locked link shows its password prompt here. Every other share route answers a bare 401. */
    public const PROMPT_ROUTES = ['dam.share.show', 'dam.share.asset_view'];

    /** The routes that hand out an original (the file, any shared file, an item's files zip or a folder zip): 403 on a preview-only link. */
    public const DOWNLOAD_ROUTES = [
        'dam.share.download',
        'dam.share.file_download',
        'dam.share.asset_download',
        'dam.share.asset_file_download',
        'dam.share.download_zip',
        'dam.share.files_zip',
        'dam.share.asset_files_zip',
    ];

    private const MEMO = 'dam.share_gate.links';

    public function __construct(
        protected ShareRepository $shares,
    ) {}

    /**
     * The link a URL token opens, active or not, with the creator-access option applied: closed, it reads as revoked.
     * Null for an unknown token.
     */
    public function share(string $token): ?Share
    {
        $request = request();
        $memo = $request->attributes->get(self::MEMO, []);
        $key = Share::hashToken($token);

        if (! array_key_exists($key, $memo)) {
            $share = $this->shares->findByToken($token);
            $memo[$key] = $share === null ? null : $this->closeIfCreatorLostAccess($this->closeIfCollectionClosed($share));
            $request->attributes->set(self::MEMO, $memo);
        }

        return $memo[$key];
    }

    /** The link, only while it opens: not expired, not revoked, and its creator (when the option is on) still sees it. */
    public function activeShare(string $token): ?Share
    {
        $share = $this->share($token);

        return $share !== null && $share->isActive() ? $share : null;
    }

    /**
     * Whether the link's creator can still see its target (P2 S5b creator access). A deleted or disabled creator
     * cannot. A target the link's own rules already close (missing, trashed, unavailable) is left to those rules, so
     * the decided 404/410 answers stay exactly as they are; this only ever closes a link that would otherwise open.
     */
    public function creatorCanSee(Share $share): bool
    {
        if ($share->share_type === Share::TYPE_COLLECTION) {
            $collection = AssetCollection::query()->where('id', $share->target_id)->first();

            // A missing or closed collection is closeIfCollectionClosed()'s answer, not this one.
            if ($collection === null || ! $collection->isLive()) {
                return true;
            }

            return app(CollectionGrant::class)->manages(collection: $collection, viewer: $this->enabledCreator($share));
        }

        if ($share->share_type === Share::TYPE_ASSET) {
            $asset = Asset::withTrashed()->where('id', $share->target_id)->first();

            if ($asset === null || $asset->trashed() || $asset->unavailableBecause() !== null) {
                return true;
            }

            $creator = $this->enabledCreator($share);

            return $creator !== null
                && app(AssetAccessDecision::class)->for(AccessContext::forAdmin($creator), $asset)->visible;
        }

        if (! Directory::query()->where('id', $share->target_id)->exists()) {
            return true;
        }

        $creator = $this->enabledCreator($share);

        if ($creator === null) {
            return false;
        }

        // The rule the share dialog applies when a folder link is made (ShareController::canAccessDirectory()).
        $permissions = (new DirectoryPermissionService)->forAdmin($creator);

        return $permissions->bypass() || $permissions->canAccess((int) $share->target_id);
    }

    /** Whether this request may open a password-protected link: the unlock cookie, or a page grant on a page route. */
    public function unlocked(Request $request, Share $share): bool
    {
        if (! $share->hasPassword()) {
            return true;
        }

        $cookie = $request->cookies->get(self::COOKIE);

        if (is_string($cookie) && hash_equals($this->cookieValue($share), $cookie)) {
            return true;
        }

        $grant = $request->route('grant');

        return is_string($grant) && $this->pageGrantValid(share: $share, grant: $grant);
    }

    /** The unlock cookie for this link: this browser session only, sent only to the link's own URLs. */
    public function unlockCookie(Request $request, Share $share): Cookie
    {
        $path = (string) (parse_url(route('dam.share.show', ['token' => $share->urlToken()]), PHP_URL_PATH) ?: '/');

        return Cookie::create(
            name: self::COOKIE,
            value: $this->cookieValue($share),
            expire: 0,
            path: $path,
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /**
     * Checks a password attempt against the throttle, then the hash.
     *
     * @return array{outcome: 'ok'|'wrong'|'locked', retry_after: int}
     */
    public function attempt(Request $request, Share $share, string $password): array
    {
        $visitorKey = 'dam-share-pass:'.$share->id.':'.$request->ip();
        $linkKey = 'dam-share-pass:'.$share->id;

        if (RateLimiter::tooManyAttempts($visitorKey, self::ATTEMPTS_PER_VISITOR) || RateLimiter::tooManyAttempts($linkKey, self::ATTEMPTS_PER_LINK)) {
            ShareLog::write('share.password.lockout', ['share_id' => (int) $share->id], level: 'warning');

            return ['outcome' => 'locked', 'retry_after' => max(RateLimiter::availableIn($visitorKey), RateLimiter::availableIn($linkKey), 1)];
        }

        if (Hash::check($password, (string) $share->password_hash)) {
            RateLimiter::clear($visitorKey);
            ShareLog::write('share.password.success', ['share_id' => (int) $share->id]);

            return ['outcome' => 'ok', 'retry_after' => 0];
        }

        RateLimiter::hit($visitorKey, self::DECAY_SECONDS);
        RateLimiter::hit($linkKey, self::DECAY_SECONDS);
        ShareLog::write('share.password.failure', ['share_id' => (int) $share->id], level: 'warning');

        return ['outcome' => 'wrong', 'retry_after' => 0];
    }

    /** A signed, short-lived grant for a password-protected link's web page frame (see the class comment). */
    public function pageGrant(Share $share): string
    {
        $expires = now()->getTimestamp() + self::PAGE_GRANT_TTL;

        return $expires.'.'.$this->sign("page|{$share->id}|{$expires}|".$this->fingerprint($share));
    }

    protected function pageGrantValid(Share $share, string $grant): bool
    {
        [$expires, $signature] = array_pad(explode('.', $grant, 2), 2, '');

        return ctype_digit($expires)
            && (int) $expires >= now()->getTimestamp()
            && hash_equals($this->sign("page|{$share->id}|{$expires}|".$this->fingerprint($share)), $signature);
    }

    protected function cookieValue(Share $share): string
    {
        return $share->id.'.'.$this->sign("unlock|{$share->id}|".$this->fingerprint($share));
    }

    /** Changes whenever the password is set or changed, or the link is reissued. */
    protected function fingerprint(Share $share): string
    {
        return hash('sha256', (string) $share->password_hash.'|'.(string) $share->token_hash);
    }

    protected function sign(string $payload): string
    {
        return hash_hmac('sha256', 'dam-share-gate|'.$payload, (string) config('app.key'));
    }

    protected function enabledCreator(Share $share): ?Admin
    {
        $creator = $share->created_by === null ? null : Admin::query()->where('id', $share->created_by)->first();

        return $creator !== null && (bool) $creator->status ? $creator : null;
    }

    /**
     * P4 D2: a collection's link while its collection is not public and live (missing, archived, expired, or switched
     * to an internal audience) reads as expired, never saved, so it answers exactly like an expired link.
     */
    protected function closeIfCollectionClosed(Share $share): Share
    {
        if ($share->share_type !== Share::TYPE_COLLECTION || ! $share->isActive()) {
            return $share;
        }

        $collection = AssetCollection::query()->where('id', $share->target_id)->first();

        if ($collection !== null && $collection->isPublic() && $collection->isLive()) {
            return $share;
        }

        $share->expires_at = now()->subSecond();
        $share->syncOriginalAttribute('expires_at');

        return $share;
    }

    /** A closed link reads as revoked; never saved, so the stored link keeps its own state. */
    protected function closeIfCreatorLostAccess(Share $share): Share
    {
        if (! $share->requires_creator_access || ! $share->isActive() || $this->creatorCanSee($share)) {
            return $share;
        }

        $share->revoked_at = now();
        $share->syncOriginalAttribute('revoked_at');

        return $share;
    }
}
