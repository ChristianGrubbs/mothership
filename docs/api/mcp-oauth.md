# MCP authorization (OAuth, inactive)

> **Inactive since November 2026.** Agents use the local `mothership` stdio server, which needs no sign-in
> ([mothership-mcp.md](mothership-mcp.md)). These OAuth classes (`Mcp*` in `packages/Mothership/AdminApi`) stay for the
> later remote mode, where `Mcp::web('/mcp', MothershipServer::class)` sits behind them. Until then `mcp-oauth.enabled`
> is `false` in `config/mcp-oauth.php`, so discovery and consent answer as disabled. The rest of this page describes the
> layer as built.

The remote endpoint will be `/mcp`. Register `McpOAuthServiceProvider` after
`AdminApiServiceProvider` and put the endpoint behind `mcp.auth`. The provider
does not mount the MCP server itself. Feature availability follows `mcp-oauth.enabled`,
which is `false` until the remote mode ships. The canonical issuer is the configured HTTPS
`APP_URL`. Deployment requires existing Passport signing keys.

## Public origins (several hostnames)

The Mothership can serve the same endpoint on more than one hostname. The extra public origins
come from `APP_PUBLIC_ORIGINS` (the app-wide list, see `config/app.php`), comma-separated,
each a bare HTTPS origin with no path or trailing slash, for example
`APP_PUBLIC_ORIGINS=https://mothership.example.org,https://mothership.example.net`. Set
`MOTHERSHIP_MCP_OAUTH_PUBLIC_ORIGINS` only to give MCP OAuth a different list; it defaults to
`APP_PUBLIC_ORIGINS`. The `APP_URL` origin is always included and need not be repeated.
Unset or empty means `APP_URL` only, which is the earlier behavior.

When a request's `Host` (host and any non-default port) equals one listed origin, that
origin is the issuer and `<origin>/mcp` is the resource for that request: in both
discovery documents, the `WWW-Authenticate` `resource_metadata` URL, the required
authorization and token `resource`, the RFC 9207 `iss` callback parameter, the access
token's resource audience and the onboarding wizard's
endpoint. The advertised strings are always taken from configuration, never copied from
the `Host` header, and the scheme is the configured `https` whatever the proxy reports.
Any other `Host`, and any listed entry that is not a bare HTTPS origin, falls back to
`APP_URL`. Laravel's `TRUSTED_HOSTS` must also admit each hostname, or the request is
rejected before it reaches this code.

Each origin is its own resource (RFC 9728) and issuer (RFC 8414): a client that connects
to `https://mothership.example.net/mcp` authorizes, exchanges and refreshes there, and
its token's audience is `https://mothership.example.net/mcp`, so that token is
rejected on another hostname. Tokens issued for `APP_URL` keep working on `APP_URL`.
Registered clients need no change: client records carry no issuer or resource. Passport
authorization codes and refresh tokens store no resource, so a grant obtained on one
listed origin can be redeemed at another listed origin's token endpoint; the new token is
then bound to the origin of that token request. All listed origins are the same server and
user database, so this crosses no trust boundary.

The same list drives the web app (`UsePublicOrigin` middleware): on an allowlisted host,
`url()`/`route()`/`asset()`, `config('app.url')` and the public disk URL follow that origin
for the request, so the admin-login redirect from `/oauth/mcp/authorize`, the login form
and the return to consent all stay on the hostname the browser used. Console, queue and
scheduler work stays on `APP_URL`.

## Client provisioning

This service uses **pre-registered public clients with S256 PKCE**. Dynamic client
registration, client-secret grants, implicit grants, and device grants are not exposed.
Create a separate client for each intended web integration with the `mcp:client`
Artisan command, supplying its display name and one or more `--redirect` options.
Copy the exact HTTPS redirect from that web client's connector setup; never use a
wildcard, a guessed callback, a fragment, or embedded credentials. A native client
on the user's own machine (Claude Code, Codex) instead registers a loopback callback
(RFC 8252 §7.3) on a fixed port, for example `http://localhost:47863/callback` for
Claude Code started with `--client-id <id> --callback-port 47863`. Only the hosts
`localhost`, `127.0.0.1` and `[::1]` are accepted over plain HTTP, and the port must be
explicit and non-zero. RFC 8252 lets loopback clients vary the port per request; this
server deliberately does not, and exact-matches the whole redirect including the port,
so native clients that need an ephemeral callback port are unsupported. The issuer
stays HTTPS-only. The command prints
only the public client ID. Add that ID to the comma-separated `MOTHERSHIP_MCP_OAUTH_CLIENT_IDS`
deployment setting. An unlisted or revoked client cannot authorize or call the MCP endpoint.

For the self-service web wizard, also map the intended public clients using
`MOTHERSHIP_MCP_OAUTH_CLAUDE_CLIENT_ID` and `MOTHERSHIP_MCP_OAUTH_CHATGPT_CLIENT_ID`. Each mapped ID must
remain in `MOTHERSHIP_MCP_OAUTH_CLIENT_IDS`. The wizard displays/copies only an existing,
non-revoked public authorization-code client on that allowlist; it never displays
a client secret. Provider IDs must differ; equal IDs are hidden. The canonical
OAuth policy also rejects mixed grant types and invalid redirect URIs.
Missing, revoked, confidential or unlisted mappings show shared
service setup as pending. This mapping does not approve individual users or prove
a vendor callback/tool workflow. After activation, ordinary users can copy the
public ID directly from their own wizard without an operator lookup.

Claude and ChatGPT connections must offer a custom OAuth client ID and support public
PKCE clients. On 2026-10-23, actual Claude browser and ChatGPT Work setup screens were
inspected: Claude offers its own-client flow with an optional blank secret and
Streamable HTTP; ChatGPT Work offers User-Defined OAuth Client, optional secret,
token authentication `none` and S256. These observations establish configuration
compatibility only, not successful discovery, authorization or execution.

Enter the deployed remote `/mcp` URL and provisioned client ID. No client secret
is issued. Copy the callback from the actual connector being created: ChatGPT can
generate a connection-specific callback, which may change if setup is recreated.
Do not assume a fixed callback for either client. Choose the supplied client rather
than automatic registration or published-client identity. A UI requiring DCR or a
confidential client remains unsupported. Do not paste a REST password token.

MCP authorization advertises RFC 9207 issuer identification and includes the exact
canonical `iss` in successful and error callback responses. OpenAI documents this as
the prerequisite for a stable ChatGPT callback; see
[Protect callbacks with issuer identification](https://developers.openai.com/plugins/build/auth#protect-callbacks-with-issuer-identification).
After deployment, rediscover metadata in the actual client and copy its displayed
callback before registration. Verify the shared public client works across independent
eligible accounts before claiming that personal setup needs no additional provisioning.
The metadata change alone does not prove the client selected a stable callback.

Provisioning is once per integration, not once per user. Every active human user
connects as themselves and gives their own consent; no administrator-role
requirement or per-user MCP approval is introduced. Vendor workspace owners may need
to add/publish the shared connector before members can connect. Existing Hub,
asset and folder permissions remain authoritative for tool operations.

Discovery is available at `/.well-known/oauth-protected-resource/mcp` and
`/.well-known/oauth-authorization-server`. The authorization endpoint is
`/oauth/mcp/authorize`; the existing Passport token endpoint remains `/oauth/token`
(or its configured Passport prefix). Metadata advertises the exact endpoints.

## Grant contract

Authorization requests require `response_type=code`, `scope=mcp:use`, an exact registered
`redirect_uri`, `code_challenge_method=S256`, a valid SHA-256 challenge, and `resource`
equal to this origin's HTTPS issuer plus `/mcp` (see Public origins). Clients should send and verify
OAuth `state`. Every connection presents explicit browser consent, protected by the
existing admin login session, CSRF middleware and Passport's single-use consent nonce.
Only active human Mothership users can approve; API robot accounts cannot.

Code exchanges require the same origin's `resource`, client ID, redirect URI and
matching `code_verifier`. Refresh requests also require that resource and client ID.
Passport validates, expires and revokes codes/tokens and rotates refresh credentials.
The exact `mcp:use` scope can only be issued to an allowlisted MCP client by the
authorization-code or refresh grant, and active-account status is checked at issuance.

MCP JWTs retain Passport's client audience as the first audience and add the
MCP resource of the origin that issued them as a second signed audience. The MCP middleware verifies Passport's
signature/expiry/revocation, the resource audience, exact scope, active client and active
human account on every request. Browser cookies, REST/password grants, wrong-resource
tokens and arbitrary upstream tokens are rejected. No token is passed to an upstream
service. Removing a client from the allowlist or revoking it blocks its grants
immediately; disabling the account or revoking its Passport access token also blocks it.

The middleware makes the authenticated `Admin` available through `request()->user()`
and the `admin` guard during the downstream call; it restores the guard afterward and
does not log into or persist a browser session. The Passport token exposes `client_id`
for actor binding. MCP routes must not add browser session authentication as a fallback.
Tool execution must still enforce the existing asset ACL and ownership services.

`MOTHERSHIP_MCP_OAUTH_LOGGING` enables bounded JSONL decision events at
`storage/logs/mcp-oauth.jsonl`. Parameters, identities, callback URLs, codes and
tokens are excluded. It defaults off. `oauth.exchange` lines carry the validated `grant` type and, on
failure, the bounded OAuth `error` code. The same exchange is recorded in first-party analytics as
`craft.oauth.token.issued` or `craft.oauth.token.failed` (see `docs/analytics-posthog.md`). Those two stored
event names keep their original spelling so earlier rows still group with new ones.

## Verification and integration

`tests/Feature/McpOAuthTest.php` exercises real Passport authorization/code/token
exchanges and JWT validation against a private SQLite test database. It includes PKCE,
redirect/resource rejection, consent denial/replay, active user checks, client/token
revocation, expiry, refresh rotation, and REST/browser credential rejection. Existing
REST grant routes and custom UUID-safe ClientRepository remain in place.

Before shipping, integrate the provider and MCP route, refresh the API route inventory,
rebuild committed frontend bundles for the consent Blade, review its rendered appearance,
and perform actual Claude/ChatGPT Work connector acceptance using ordinary human
accounts. Deployment needs the canonical public HTTPS issuer, existing Passport keys,
registered exact callbacks and client allowlist.
Rebuild cached configuration/routes after activation. No new OAuth schema migration
is required. Verify discovery, sign-in and consent, code exchange, tools/list and a
real publication/reimport before reporting connected editing. Verify refresh sends the
same resource and works without an invented `offline_access` scope; the server advertises
only `mcp:use`. Record the actual client's behavior rather than inferring it from UI.
No live acceptance or deployment is implied by local tests. Official integration reference:
[Laravel Passport](https://laravel.com/docs/13.x/passport) and
[Laravel MCP authorization](https://laravel.com/docs/13.x/mcp#authorization).

### Refresh lifetime

The defaults keep `ACCESS_TOKEN_TTL=3600` and set `REFRESH_TOKEN_TTL=2592000` (30 days), allowing
ordinary idle periods between visits while retaining one-hour access credentials.
Successful refresh rotates the credentials and starts a new refresh lifetime.
This is a sliding window, with no absolute session lifetime: clients that keep refreshing
within 30 days can continue indefinitely unless an applicable account, client or token
revocation/status restriction blocks access.

These settings are global Passport settings: the longer lifetime also applies to refresh
credentials issued to existing REST/password-grant clients. It does not change scopes,
resource checks, user status checks or revocation. Existing deployment environment values
override these defaults. Activation belongs to the deployment owner, who must set the
refresh lifetime explicitly and rebuild configuration/restart persistent application workers.
Changing the source defaults alone does not establish the effective live configuration.

Already-issued credentials retain their encoded expiration. A fresh authorization after
activation establishes the longer lifetime. An expired refresh cannot be repaired by
changing configuration. Confirm the effective values and newly issued expiry, then observe an actual Claude refresh after
access expiry, including the required resource. Server regression tests do not establish
that a particular browser client sends a valid refresh request.
