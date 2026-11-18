<?php

namespace Mothership\AdminApi\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\ScopeRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Passport;
use Mothership\AdminApi\Console\McpClientCommand;
use Mothership\AdminApi\Http\Controllers\McpDiscoveryController;
use Mothership\AdminApi\Http\Middleware\McpAuthenticate;
use Mothership\AdminApi\Http\Middleware\McpAuthorization;
use Mothership\AdminApi\Http\Middleware\McpTokenRequest;
use Mothership\AdminApi\Http\Middleware\McpTokenThrottle;
use Mothership\AdminApi\Repositories\McpScopeRepository;
use Mothership\AdminApi\Services\McpAccessToken;
use Mothership\AdminApi\Services\McpAuthorizationFailure;
use Mothership\AdminApi\Services\McpOAuth;
use Mothership\AdminApi\Services\McpTokenExchanged;

class McpOAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ScopeRepository::class, McpScopeRepository::class);
        $this->app->when(AuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn ($app) => $app['auth']->guard('admin'));
    }

    public function boot(): void
    {
        $policy = $this->app->make(McpOAuth::class);
        $this->app->afterResolving(ExceptionHandler::class, function ($exceptions) use ($policy): void {
            if (! $exceptions instanceof Handler) {
                return;
            }
            $exceptions->map(function (TokenMismatchException $exception) use ($policy): \Throwable {
                $request = $this->app->make('request');
                if (! $policy->enabled() || ! $request->is('oauth/mcp/authorize')
                    || ! in_array($request->method(), ['POST', 'DELETE'], true)) {
                    return $exception;
                }
                // Laravel maps during both reporting and rendering; count one rejection per request.
                if (! $request->attributes->get('_mcpCsrfAudited', false)) {
                    $request->attributes->set('_mcpCsrfAudited', true);
                    $policy->audit(event: 'oauth.authorization_rejected', status: 419, reason: McpAuthorizationFailure::CsrfExpired);
                }
                if ($request->expectsJson()) {
                    return $exception;
                }

                // CSRF still rejects the POST; do not read, replay, or approve any pending request.
                return new HttpResponseException(response()->view('admin_api::oauth.mcp-restart', ['restart' => null], 419));
            });
        });
        // Passport names the account only while issuing; keep it on the request for the token middleware's event.
        Event::listen(AccessTokenCreated::class, function (AccessTokenCreated $created) use ($policy): void {
            if ($created->userId !== null && $this->app->bound('request') && $policy->configuredClient($created->clientId)) {
                $this->app->make('request')->attributes->set(McpTokenExchanged::USER_ATTRIBUTE, $created->userId);
            }
        });
        $this->commands([McpClientCommand::class]);
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'admin_api');
        $this->app['router']->aliasMiddleware('mcp.auth', McpAuthenticate::class);
        Passport::tokensCan(array_merge(Passport::scopes()->pluck('description', 'id')->all(), [McpOAuth::SCOPE => 'Use MCP tools with your Mothership permissions']));
        Passport::useAccessTokenEntity(McpAccessToken::class);
        Passport::authorizationView('admin_api::oauth.mcp-consent');
        $this->registerRateLimiters();

        // Keep the existing token endpoint and password-grant contract; restrict only MCP clients/scopes.
        $this->app->booted(function (): void {
            $route = Route::getRoutes()->getByName('passport.token');
            if ($route === null) {
                return;
            }
            // Replace only the two exact token-route throttles. Leaving either in the chain
            // would still aggregate hosted clients behind a common egress IP. Cached routes
            // are handled too; this transformation is idempotent on subsequent boots.
            $action = $route->getAction();
            $action['middleware'] = array_values(array_unique([
                ...array_diff($action['middleware'] ?? [], ['throttle', 'throttle:oauth-token', McpTokenThrottle::class, McpTokenRequest::class]),
                McpTokenThrottle::class, McpTokenRequest::class,
            ]));
            $route->setAction($action);
            $route->computedMiddleware = null;
        });

        if ($this->app->routesAreCached()) {
            return;
        }

        Route::get('/.well-known/oauth-protected-resource/mcp', [McpDiscoveryController::class, 'resource']);
        Route::get('/.well-known/oauth-authorization-server', [McpDiscoveryController::class, 'authorizationServer']);

        // Deliberately available to every active signed-in human: asset/tool ACL remains enforced on MCP calls.
        // Passport's controller requests admin login only after our feature/client/input checks;
        // approval and denial require the same active session identity in McpAuthorization.
        Route::prefix('oauth/mcp')->middleware(['web', McpAuthorization::class, 'throttle:mcp-oauth-browser'])->group(function (): void {
            Route::get('authorize', [AuthorizationController::class, 'authorize'])->name('mcp.oauth.authorize');
            Route::post('authorize', [ApproveAuthorizationController::class, 'approve'])->name('mcp.oauth.approve');
            Route::delete('authorize', [DenyAuthorizationController::class, 'deny'])->name('mcp.oauth.deny');
        });
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('mcp-oauth-token', function (Request $request): array {
            $client = $request->input('client_id', $request->getUser());
            $grant = $request->input('grant_type') === 'refresh_token'
                ? $request->input('refresh_token') : $request->input('code');

            return $this->mcpLimits(client: is_string($client) ? $client : '', discriminator: is_string($grant) && $grant !== '' ? $grant : 'missing|'.$request->ip(), ip: (string) $request->ip());
        });
        RateLimiter::for('mcp-oauth-browser', function (Request $request): array {
            $client = $request->isMethod('GET') ? $request->query('client_id') : $request->session()->get('mcpClient');
            $actor = $request->user('admin')?->getAuthIdentifier();

            return $this->mcpLimits(client: is_string($client) ? $client : '', discriminator: $actor !== null ? 'user|'.$actor : 'guest|'.$request->ip(), ip: (string) $request->ip());
        });
    }

    private function mcpLimits(string $client, string $discriminator, string $ip): array
    {
        $key = (string) config('app.key');
        $clientKey = hash_hmac('sha256', $client.'|'.$ip, $key);
        $grantKey = hash_hmac('sha256', $client.'|'.$discriminator, $key);

        // No raw user identifier, authorization code or refresh credential reaches cache keys.
        // Bound random-grant abuse per client and source IP; a public client id must never
        // let one unauthenticated source consume the quota of unrelated hosted-client users.
        return [
            Limit::perMinute((int) config('mcp-oauth.client_ip_rate_limit', 600))->by('client-ip:'.$clientKey),
            Limit::perMinute((int) config('mcp-oauth.grant_rate_limit', 10))->by('grant:'.$grantKey),
        ];
    }
}
