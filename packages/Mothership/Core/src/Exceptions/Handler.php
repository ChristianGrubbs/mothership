<?php

namespace Mothership\Core\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    private const array RENDERABLE_STATUS_CODES = [
        JsonResponse::HTTP_UNAUTHORIZED,
        JsonResponse::HTTP_FORBIDDEN,
        JsonResponse::HTTP_NOT_FOUND,
        JsonResponse::HTTP_METHOD_NOT_ALLOWED,
        JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE,
        419,
        JsonResponse::HTTP_TOO_MANY_REQUESTS,
        JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
        JsonResponse::HTTP_SERVICE_UNAVAILABLE,
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        if (config('app.debug')) {
            // A trace only helps with a server error. A 403 or 404 is a page people meet in normal use, so it
            // keeps the branded page with its way back even while debugging (VIS-05, FAULT-20).
            $this->handleClientErrorPages();

            return;
        }

        $this->handleAuthenticationException();

        $this->handleHttpException();

        $this->handleValidationException();

        $this->handleServerException();
    }

    /**
     * Framework hook invoked before renderable callbacks. Overridden so an
     * unauthenticated API request always gets a JSON 401 (regardless of
     * APP_DEBUG or the Accept header) instead of the framework default, which
     * redirects to the nonexistent `login` route and 500s.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->wantsJson() || $this->isApiRequest($request)) {
            return response()->json([
                'error' => trans('admin::app.errors.401.message'),
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return redirect()->guest(route('admin.session.create'));
    }

    /**
     * Handle the authentication exception.
     */
    private function handleAuthenticationException(): void
    {
        $this->renderable(function (AuthenticationException $exception, Request $request) {
            if ($request->wantsJson() || $this->isApiRequest($request)) {
                return response()->json([
                    'error' => trans('admin::app.errors.401.message'),
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }

            return redirect()->guest(route('admin.session.create'));
        });
    }

    /**
     * Handle the http exceptions.
     */
    private function handleHttpException(): void
    {
        $this->renderable(function (HttpException $exception, Request $request) {
            $status = $exception->getStatusCode();

            /**
             * Only the listed codes have error-page copy; anything else borrows the
             * generic 500 wording. The response still carries the thrown status, so
             * an `abort(422, ...)` stays a client error instead of reading as a crash.
             */
            $errorCode = in_array($status, self::RENDERABLE_STATUS_CODES, true)
                ? $status
                : JsonResponse::HTTP_INTERNAL_SERVER_ERROR;

            $headers = $exception->getHeaders();

            if ($request->wantsJson() || $this->isApiRequest($request)) {
                $message = $exception->getMessage();

                return response()->json([
                    'error'       => trans("admin::app.errors.{$errorCode}.title"),
                    // Only 422 aborts carry a translated, user-facing reason; other statuses keep the generic copy.
                    'description' => $status === JsonResponse::HTTP_UNPROCESSABLE_ENTITY && $message !== ''
                        ? $message
                        : trans("admin::app.errors.{$errorCode}.description"),
                ], $status, $headers);
            }

            return response()->view('admin::errors.index', [
                'errorCode'   => $errorCode,
                'description' => $this->ownReason($exception),
            ], $status, $headers);
        });
    }

    /**
     * Debug mode only: the branded page for a client error a browser navigated to. JSON, API and 5xx
     * responses keep the framework's debug output.
     */
    private function handleClientErrorPages(): void
    {
        $this->renderable(function (HttpException $exception, Request $request) {
            $status = $exception->getStatusCode();

            if ($status >= JsonResponse::HTTP_INTERNAL_SERVER_ERROR
                || ! in_array($status, self::RENDERABLE_STATUS_CODES, true)
                || $request->wantsJson()
                || $this->isApiRequest($request)) {
                return null;
            }

            return response()->view('admin::errors.index', [
                'errorCode'   => $status,
                'description' => $this->ownReason($exception),
            ], $status, $exception->getHeaders());
        });
    }

    /**
     * Handle the server exceptions.
     */
    private function handleServerException(): void
    {
        $this->renderable(function (Throwable $throwable, Request $request) {
            // A response thrown on purpose (a rate limiter's own response, a FormRequest's failedAuthorization
            // override) is already the answer; it is not a crash.
            if ($throwable instanceof HttpResponseException) {
                return $throwable->getResponse();
            }

            $errorCode = JsonResponse::HTTP_INTERNAL_SERVER_ERROR;

            if ($request->wantsJson() || $this->isApiRequest($request)) {
                return response()->json([
                    'error'       => trans("admin::app.errors.{$errorCode}.title"),
                    'description' => trans("admin::app.errors.{$errorCode}.description"),
                ], $errorCode);
            }

            return response()->view('admin::errors.index', ['errorCode' => $errorCode], $errorCode);
        });
    }

    /**
     * Handle validation exceptions.
     */
    private function handleValidationException(): void
    {
        $this->renderable(fn (ValidationException $exception, Request $request) => parent::convertValidationExceptionToResponse($exception, $request));
    }

    /**
     * A 403 thrown with its own wording (`abort(403, trans(...))`) keeps it on the page; framework defaults and
     * other statuses fall back to the page's generic copy. A 404's message names routes and models, so it never shows.
     */
    private function ownReason(HttpException $exception): ?string
    {
        $message = trim($exception->getMessage());

        if ($exception->getStatusCode() !== JsonResponse::HTTP_FORBIDDEN || $message === '' || $message === 'This action is unauthorized.') {
            return null;
        }

        return $message;
    }

    /**
     * Determine if the request is an API request.
     */
    private function isApiRequest(Request $request): bool
    {
        if ($request->is('api/*')) {
            return true;
        }

        return $request->is('*/api/*');
    }
}
