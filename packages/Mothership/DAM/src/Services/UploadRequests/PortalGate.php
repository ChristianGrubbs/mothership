<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\UploadRequests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Support\GuestIntakeLog;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * P5a (D1): who an upload-request link opens for, decided once for every portal route (`/u/{token}`).
 *
 * - **Resolution.** accepting() is the portal's one way from a URL token to its request. An unknown token, a closed
 *   request, an expired one and one whose creator is gone or disabled all come back null, so every route answers them
 *   identically (410, one body) and the guest learns nothing about why.
 * - **Visit.** A guest works inside one visit, held by a cookie: HttpOnly, SameSite=Lax, scoped to the link's own URL
 *   path, so the browser never sends it to any other route. Its value is a random nonce, an expiry and an HMAC
 *   (APP_KEY) over the request id, the nonce, the expiry and a fingerprint of the token hash and the password hash, so
 *   Reissue or a password change ends every visit begun before it. The nonce's SHA-256 names the visit's submission.
 *   Every chunk call needs the visit, so a guest can only ever read or change uploads their own visit started.
 * - **Password.** A request with a password issues the visit only after the right password, throttled as share links
 *   are (P2 S5b): ATTEMPTS_PER_VISITOR and ATTEMPTS_PER_LINK a minute, then a lockout for the rest of that minute.
 */
class PortalGate
{
    public const COOKIE = 'dam_upload_visit';

    public const ATTEMPTS_PER_VISITOR = 5;

    public const ATTEMPTS_PER_LINK = 30;

    public const DECAY_SECONDS = 60;

    private const MEMO = 'dam.upload_portal.request';

    /** The request a URL token opens while it accepts files, else null: unknown, closed, expired or creator gone. */
    public function accepting(string $token): ?UploadRequest
    {
        $request = request();
        $key = UploadRequest::hashToken($token);
        $memo = $request->attributes->get(self::MEMO, []);

        if (! array_key_exists($key, $memo)) {
            $found = UploadRequest::query()->with(['creator.role'])->where('token_hash', $key)->first();
            $memo[$key] = $found;
            $request->attributes->set(self::MEMO, $memo);
        }

        $found = $memo[$key];

        return $found !== null && $found->isAccepting() ? $found : null;
    }

    /** The request a token names, accepting or not; for logging why a known link refused a file. Null when unknown. */
    public function known(string $token): ?UploadRequest
    {
        $this->accepting($token);

        return request()->attributes->get(self::MEMO, [])[UploadRequest::hashToken($token)] ?? null;
    }

    /**
     * The nonce of this request's visit, when the cookie is present, unexpired and signed for this request as it is
     * now; null otherwise. A cookie that is present but does not verify is recorded as misuse once per request.
     */
    public function visit(Request $request, UploadRequest $uploadRequest): ?string
    {
        $cookie = $request->cookies->get(self::COOKIE);

        if (! is_string($cookie) || $cookie === '') {
            return null;
        }

        [$nonce, $expires, $signature] = array_pad(explode('.', $cookie, 3), 3, '');

        if (preg_match('/\A[A-Za-z0-9]{40}\z/', $nonce) === 1 && ctype_digit($expires) && (int) $expires >= now()->getTimestamp()
            && hash_equals($this->sign(request: $uploadRequest, nonce: $nonce, expires: (int) $expires), $signature)) {
            return $nonce;
        }

        // Expired is ordinary; a bad signature is a cookie forged, or issued before a Reissue or password change.
        if (! ctype_digit($expires) || (int) $expires >= now()->getTimestamp()) {
            $this->misuse(uploadRequest: $uploadRequest, reason: 'visit_invalid');
        }

        return null;
    }

    /** A new visit cookie for this request: this browser, this link's URLs only, for `visit_hours`. */
    public function visitCookie(Request $request, UploadRequest $uploadRequest, string $token, ?string $nonce = null): Cookie
    {
        $nonce ??= Str::random(40);
        $expires = now()->addHours(max(1, (int) config('dam.upload_requests.visit_hours')))->getTimestamp();
        $path = (string) (parse_url(route('dam.upload_portal.show', ['token' => $token]), PHP_URL_PATH) ?: '/');

        return Cookie::create(
            name: self::COOKIE,
            value: $nonce.'.'.$expires.'.'.$this->sign(request: $uploadRequest, nonce: $nonce, expires: $expires),
            expire: 0,
            path: $path,
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /** What a visit's nonce is stored as: the submission's `visit_hash`. */
    public static function visitHash(string $nonce): string
    {
        return hash('sha256', 'dam-upload-visit|'.$nonce);
    }

    /**
     * Checks a password attempt against the throttle, then the hash.
     *
     * @return array{outcome: 'ok'|'wrong'|'locked', retry_after: int}
     */
    public function attempt(Request $request, UploadRequest $uploadRequest, string $password): array
    {
        $visitorKey = 'dam-upload-pass:'.$uploadRequest->id.':'.$request->ip();
        $linkKey = 'dam-upload-pass:'.$uploadRequest->id;

        if (RateLimiter::tooManyAttempts($visitorKey, self::ATTEMPTS_PER_VISITOR) || RateLimiter::tooManyAttempts($linkKey, self::ATTEMPTS_PER_LINK)) {
            GuestIntakeLog::write(event: 'portal.password.lockout', ctx: ['upload_request_id' => (int) $uploadRequest->id], level: 'warning');
            $this->misuse(uploadRequest: $uploadRequest, reason: 'password_lockout');

            return ['outcome' => 'locked', 'retry_after' => max(RateLimiter::availableIn($visitorKey), RateLimiter::availableIn($linkKey), 1)];
        }

        if (Hash::check($password, (string) $uploadRequest->password_hash)) {
            RateLimiter::clear($visitorKey);

            GuestIntakeLog::write(event: 'portal.password.success', ctx: ['upload_request_id' => (int) $uploadRequest->id]);

            return ['outcome' => 'ok', 'retry_after' => 0];
        }

        RateLimiter::hit($visitorKey, self::DECAY_SECONDS);
        RateLimiter::hit($linkKey, self::DECAY_SECONDS);
        GuestIntakeLog::write(event: 'portal.password.failure', ctx: ['upload_request_id' => (int) $uploadRequest->id], level: 'warning');

        return ['outcome' => 'wrong', 'retry_after' => 0];
    }

    /** A security event (P1a) for a token used against its rules; by request id and reason only, once per HTTP request. */
    public function misuse(UploadRequest $uploadRequest, string $reason, ?int $submissionId = null): void
    {
        $key = 'dam.upload_portal.misuse.'.$reason;

        if (request()->attributes->get($key)) {
            return;
        }

        request()->attributes->set($key, true);
        GuestIntakeLog::write(event: 'portal.misuse', ctx: ['upload_request_id' => (int) $uploadRequest->id, 'reason' => $reason], level: 'warning');
        app(SecurityEventRecorder::class)->record(new SecurityEvent(
            name: SecurityEvent::GUEST_TOKEN_MISUSE,
            ctx: ['upload_request_id' => (int) $uploadRequest->id, 'submission_id' => $submissionId, 'reason' => $reason],
        ));
    }

    /** Changes whenever the link is reissued or its password is set, changed or removed. */
    protected function sign(UploadRequest $request, string $nonce, int $expires): string
    {
        $fingerprint = hash('sha256', (string) $request->password_hash.'|'.(string) $request->token_hash);

        return hash_hmac('sha256', "dam-upload-visit|{$request->id}|{$nonce}|{$expires}|{$fingerprint}", (string) config('app.key'));
    }
}
