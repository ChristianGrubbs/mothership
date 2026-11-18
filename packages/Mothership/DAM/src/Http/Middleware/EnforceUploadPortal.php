<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Services\UploadRequests\GuestIntakeService;
use Mothership\DAM\Services\UploadRequests\PortalGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * P5a (D1): the guest's credential on every upload-portal call (`/u/{token}/uploads…`), ahead of the controller.
 *
 * - An unknown, closed or expired link answers 410 with one body for all three, before anything else is looked at.
 * - A call without this link's visit cookie (never issued, expired, or signed before a Reissue or a password change)
 *   answers 401: the visit is what binds a chunk call to the guest who started the upload, and a password-protected
 *   link issues it only after the right password.
 * - The request and the visit's nonce are handed to the controller as request attributes; nothing else of the
 *   guest's reaches it. No response is cached, by the browser or anything between.
 */
class EnforceUploadPortal
{
    public const ATTRIBUTE = 'dam.upload_portal';

    public function __construct(
        protected PortalGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');
        $uploadRequest = is_string($token) ? $this->gate->accepting($token) : null;

        if ($uploadRequest === null) {
            $error = GuestIntakeService::unavailable();

            return self::json(status: $error->status, code: $error->errorCode, message: $error->getMessage());
        }

        $nonce = $this->gate->visit(request: $request, uploadRequest: $uploadRequest);

        if ($nonce === null) {
            return self::json(status: 401, code: 'visit_required', message: trans('dam::app.upload-portal.errors.visit-required'));
        }

        $request->attributes->set(self::ATTRIBUTE, ['request' => $uploadRequest, 'nonce' => $nonce]);

        return self::private($next($request));
    }

    /** The portal's JSON error, in the chunk protocol's envelope. */
    public static function json(int $status, string $code, string $message, bool $retryable = false): JsonResponse
    {
        return self::private(new JsonResponse([
            'success'   => false,
            'code'      => $code,
            'message'   => $message,
            'retryable' => $retryable,
            'upload'    => null,
        ], $status));
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public static function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
