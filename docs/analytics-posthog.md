# PostHog export

## Client error diagnostics

`client.error.raised` retains its five-event document cap and existing privacy
gates. Future errors may carry a known `error_type`, numeric `line`/`column`, and
the basename of a hashed JavaScript bundle under same-origin
`/themes/dam/build/assets/` or `/themes/admin/default/build/assets/`. Bundle names
also pass a strict server-side format check. Unknown types and untrusted sources
are omitted. Rejections use only the first accepted static frame of a bounded
stack; primitive reasons carry no detail. Messages, stack text, query strings,
asset filenames and arbitrary URLs never become event properties. Inline Blade
errors may instead carry `source_kind = 'document'` and numeric coordinates only
when the source matches the current document's origin and pathname; that path and
its query are never sent. Static bundles carry `source_kind = 'bundle'`. The historical
Library errors have no retained diagnostic source and remain unexplained; this
adds evidence for future occurrences rather than establishing a historical fix.

## Web Vitals measurement contract

The collector pins official `web-vitals` **6.2.3** to replace incorrect custom
algorithms (sum-of-all-shifts CLS, maximum-event-duration INP, and unfinalized LCP).
The existing configured 10% document sampling and server privacy gates remain in
force. Values remain integer milliseconds, with CLS in thousandths. Only scalar
metric fields are sent; performance entries, URLs, DOM targets and text are omitted.

New snapshots carry `measurement = 'web-vitals-6.2.3'`, `metric_id`, monotonically
increasing `metric_revision`, and `navigation_type`. The library finalizes LCP on
interaction/hidden and computes CLS session windows and INP interaction p98.
CLS/INP can change after a page returns from hidden: a changed rounded value emits
a new revision of the same metric; an unchanged callback emits nothing. Normal
event UUID/outbox UUID deduplication still handles a resent transport batch.
**Before any percentile or sample count, select the latest revision per metric ID.**
Snapshots are immutable events, not independent samples. Reports must also filter
the measurement version: old events lacking it are **legacy proxies** and must
never be pooled with standards-based measurements. The local journey summary
labels that distinction; it does not calculate performance percentiles.

Attribution is to the initial server route for the entire document lifecycle.
History/SPA route changes do not create page-load metrics or change attribution.
Soft-navigation measurement is disabled. A BFCache restoration uses the library's
new metric IDs and `back-forward-cache` type, still attributed to the document's
initial route; treat each restored lifecycle as a new measurement instance.

Existing PostHog insights using raw `quantile(properties.value)` require this
deduplication before they can report new standards-based percentiles. Example
HogQL for a chosen report window (apply the same production/environment/route
filters inside the subquery; `value` for CLS still means thousandths):

```sql
SELECT metric, route, count() AS samples,
       quantile(0.75)(value) AS p75, quantile(0.95)(value) AS p95
FROM (
    SELECT properties.metric AS metric, properties.route AS route,
           properties.metric_id AS metric_id,
           argMax(toFloat(properties.value), toInt(properties.metric_revision)) AS value
    FROM events
    WHERE event = 'client.web_vitals.sampled'
      AND properties.measurement = 'web-vitals-6.2.3'
      AND properties.environment = 'production'
      AND timestamp >= now() - INTERVAL 30 DAY
    GROUP BY metric, route, metric_id
)
GROUP BY metric, route
```

The window selects metric instances with a snapshot reported in that window,
using their latest in-window revision. `metric_id` is library-generated per
instance, distinct across hard navigations and BFCache restorations. The pin's
source was inspected as well as current upstream documentation. Like earlier
official releases, it assumes observer deliveries while a shift session is live;
one artificially batched delivery spanning multiple expired CLS windows does
not preserve an earlier maximum. The integration regression uses separate
observer deliveries for distinct windows and keeps all calculations in the library.
Cloud insight changes and production event acceptance are separate release steps.

The existing first-party collector, event registry, server actor resolution and local reports remain authoritative.
Set `DAM_POSTHOG_ENABLED=true`, `DAM_POSTHOG_HOST=https://us.i.posthog.com` and inject
`DAM_POSTHOG_PROJECT_TOKEN` from Keychain into the deployment environment. The exporter never exposes this
configuration value to JavaScript. The explicitly authorized replay integration below makes the project's
write-only ingestion identifier browser-visible; personal/management API keys remain server-only.
The scheduler runs `dam:analytics:export` each minute. Apply migrations before enabling export.

## Optional authenticated session replay

Replay uses an explicitly configured public
ingestion identifier, `DAM_POSTHOG_REPLAY_PUBLIC_KEY`, and `DAM_POSTHOG_REPLAY_ENABLED=true` (default false).
The public identifier must belong to the same PostHog project as the exporter for session linkage to work.
It may be the same project ingestion token value, deliberately made browser-visible by this opt-in feature.
It is never a personal API key; bootstrap never falls back to the exporter token configuration. The SDK is
pinned to posthog-js 1.438.13 and its recorder is bundled locally. Official US/EU remote configuration GETs
use the fixed regional assets host; snapshots use the configured ingestion host. Custom hosts stay fixed.
No product events are captured by this SDK: only actual `$snapshot` batches survive its send filter.

Authenticated eligible admin pages load replay; guest pages do not. Existing opt-out,
DNT, GPC and suspected-bot gates apply. Every relay request rechecks those rules. The fixed authenticated
relay permits only the configured-token remote configuration GET and recording/flags POST paths; normal
CSRF protection remains. Fetch with beacon disabled preserves the CSRF header on navigation. Payloads
are bounded to 2 MB, requests use 3-second connect/10-second total timeouts, no redirects are followed,
and no cookies/auth/CSRF headers or payload logs are forwarded or persisted. Remote configuration is reduced
to recording enable/rate/minimum duration with the recording endpoint fixed to /s/.

The SDK and server exporter share the existing HMAC pseudonym. A nullable indexed replay_session_id stores
only a validated actual initialized SDK session. Export attaches it as `$session_id`; the existing journey_id
continues to mean the first-party collector journey. Same-origin requests read the SDK id at dispatch time,
so rotation is respected. Logout and account/CSRF-session changes stop/reset recording. Chunk finalization
retains the initiating request id through its serialized job; background work never invents an id.
Migration rollback intentionally retains this nullable column and recorded linkage; disable replay before
rolling back application code. No destructive down migration is required.

All text is masked except explicitly marked static navigation labels. Inputs, editable content, scripts,
file/media/image contents, canvases and all iframes are blocked; attributes and page addresses are scrubbed.
Console, network bodies/headers, performance, canvas, external scripts, surveys and autocapture are disabled.
This records parent-app navigation and first-party workflow steps, including launches of embedded tools.
It does not record cross-origin Craft editor internals, iframe documents, canvas/WebGL/3D pixels, images,
audio or video. Those boundaries can still have existing first-party confirmed step events; replay does not
prove an iframe edit or file receipt beyond the event's documented meaning.

Rollout remains held for explicit operator go-ahead: migrate the additive schema, deploy the exact reviewed
bundles/code, set the two replay environment values above (the release follows the image's build commit, see
"Delivery health and release"),
and enable project session recording at sample rate 1.00. Existing PostHog retention remains 30 days.
Cloud recording is currently disabled; local controlled-upstream proof is not live playback acceptance.
After activation, verify a real eligible Home → Library → preview/upload session has a playable masked
recording, that exported steps share its actual session id and release, that opt-out stops further uploads,
and that ordinary app behavior works when replay transport fails. Roll back by disabling local replay and
restoring the staged prior cloud opt-in/sample settings, then reverting the app release if needed. Preserve
service environment/data/relay configuration and the additive linkage data.

Local proof: `node --test tools/replay/browser.test.cjs` boots the actual Laravel relay against an ephemeral
TLS upstream and the real bundled recorder (requires the local synthetic migrated SQLite fixture and
Playwright/Chrome, defaulting to tests/e2e-pw; MOTHERSHIP_PLAYWRIGHT_MODULE_PATH can select another installed
Playwright module, and MOTHERSHIP_CHROME_LAUNCHER selects the stack's required Chrome launcher when applicable).
The existing self-hosted JavaScript browser job runs this integration using its established browser cache,
per-runner directory and apt-lock recovery. PHP regression covers auth, privacy, path/token denial, size/error handling, linkage,
cross-user rejection and additive rollback. Production cloud playback remains a separate acceptance gate.
Export alone sits outside the scheduler's overlap-protected group: the command already owns a 40-minute
cache lock, covering its bounded HTTP batch. An interrupted background scheduler process must not leave a
second default 24-hour mutex blocking all delivery. `onOneServer` still selects one scheduled launch per minute.

Validated raw events enqueue transactionally; HTTP happens outside the user request. Aggregate-only DNT/GPC,
opt-out and suspected-bot traffic is not exported. Pending entries are discarded after opt-out or source deletion.
A job's events (`ActorResolver::forAdminId`) and API-key uploads never read a user agent: a queue worker's only
request is Laravel's console one, whose `Symfony` agent would read as a script, and an API client is a script by
design. Their owner's opt-out alone decides. Every upload path (`upload.file.*` `path`: chunked, direct, folder,
reupload, hub, portal, api) records one attempt and one terminal event per `upload_id`; a guest portal upload counts
as the portal visitor, not the request's creator.
Export uses a keyed pseudonym, no person profile, no raw account ID, no search text or view capability.
The payload is removed after delivery; transient failures retry with capped exponential delay for up to twelve
attempts. Per-event 400/413/422 rejection clears the payload and marks it failed. Configuration failures
(401/403/404) retain the payload, back off and stop the batch. Exhausted transient retries retain payloads in
failed rows until source retention/forgetting deletes them; an operator can requeue them after correction.
Monitor failed counts: discarded invalid payloads cannot be recovered from this outbox. Export runs in
the background and stops a batch on service unavailability so other scheduled work is not held up.
Disabling export stops new staging and delivery. Deleting local raw events cascades to their outbox entries.
Already delivered PostHog events require the project's separate retention/deletion policy; local forgetting is
not a remote deletion API. Rotating APP_KEY changes external pseudonyms. Production should use a shared cache
backend for the export lock. Capture UUID dedup also protects interrupted sends.

An operation that fails and then succeeds has two immutable outcome events with different deterministic UUIDs,
and the same `operation_id`. Count successful operations distinctly, not all terminal events. For final failure
counts, exclude operations that later succeeded. Retry of the same outcome reuses its first UUID and timestamp.

## Route views, device context and internal actors

`route.viewed` is the per-route denominator, with `route` (server route name, never a path) and `navigation`:
`document` for each hard load, `spa` for each admin AJAX visit (the destination's collector config names the
route; a destination without one, such as an opt-out boundary, sends nothing), and `restore` for each
back-forward-cache restore. Divide feature events by distinct `journey_id` with a `route.viewed` on that route,
not by vitals (10% sampled) or widget exposures. Errors and vitals keep the initial document route.

Every client event carries `device_class` (`phone`, `tablet`, `desktop`: a coarse primary pointer splits on the
shorter screen side at 600 CSS px) and `viewport_width` (`lt640`, `640-1023`, `1024-1439`, `1440-1919`,
`ge1920`). They come from the envelope field `x`, are declared under `client_context` in `Config/dam_events.php`,
and an undeclared value drops the event. Events stored before this change have neither prop.

Every export carries `internal_actor`, true only for admin ids listed in `DAM_ANALYTICS_INTERNAL_ADMIN_IDS`
(comma-separated; the operator, agent and QA accounts). Persons are disabled, so a person cohort cannot filter
test traffic: the project's test-account filter must be the event property `internal_actor` is not `true`.
The list is deployment configuration in `app.env`; changing it needs a stack recreate like any other value.

Tool funnel: tools open from the Tools page, and the tool frame sends `home.launch.attempted` →
`home.launch.loaded` on the surface `tools`. Use `widget_id`, `launch_id`, `journey_id`, `duration_ms`, and
`environment` to segment. Loaded means document bootstrap only, not task success. `home.launch.slow` is
nonterminal; `failed` and `abandoned` explain unsuccessful launches. Home's Tools shelf, which sent
`home.feature.exposed` and opened tools in a popup on the surface `home`, is gone. The funnel's first step,
`home.feature.exposed`, pauses until the Tools page emits the exposure; until then the funnel starts at
`home.launch.attempted`. Home sends `home.feature.activated` only for its own cards and links. Earlier events keep
their meaning.
Confirmed app-use events must be added at the application's actual completion boundary; do not relabel loaded as
success. Existing upload successes are real server outcomes.

The `hub.open.*`, `hub.save.*` and `hub.connect.*` events came from the in-app Creative Hub and its Craft connect
wizard, both removed 2026-11-17. Nothing emits them now; the names stay in the event catalog so earlier data keeps
its meaning.

The Craft connector token endpoint records `craft.oauth.token.issued` (for the token's account) and
`craft.oauth.token.failed` (on the system actor, with `status` and the bounded OAuth `error_code`). Both carry
`grant_type` (`authorization_code` or `refresh_token`) and the wizard `provider` mapping (`claude`, `chatgpt`,
`other`). A lasting connection shows as refresh grants. Repeated `authorization_code` issues for one account, with
no refreshes between them, mean the client is consenting again rather than refreshing. Codes, tokens, client ids
and callbacks are never recorded.

Dashboard acceptance: production-only attempts-to-loaded and p50/p95 load duration by tool; slow/failure/abandon
counts by tool/error; distinct successful operation conversions; exposure-to-attempt by tool once the Tools page
emits the exposure. Compare the
same date windows and device cohorts. Event delivery and dashboard creation must be checked in project 677447
after deployment; local tests with HTTP fakes are not that proof.

## Transfers and PWA

Transfers reports file offers through the pinned origin/frame bridge and the existing admin collector. Each
local operation has a random `transfer_id`, `direction` (`send` or `receive`) and file count. A sender's
`transfer.file.attempted` ends in `acknowledged`, `declined`, `cancelled` or `failed`; acknowledgment requires
the transport's validated final-file receipt acknowledgment. A receiver records its own attempt and `received`
only when actual files are assembled in its browser. Neither event proves a disk save. Receiver and sender
operation IDs differ: correlate within one side, never join the two as the same operation. Text messages, file
names, content, peer identity and room codes are never included. The separate Transfers window has no admin
collector; these metrics describe embedded The Mothership operations.

PWA signals are `pwa.installable`, `pwa.install.prompted`, `pwa.install.choice` (`accepted` or `dismissed`),
`pwa.installed`, `pwa.standalone.opened` and `pwa.update.ready`. Prompt acceptance is not an installation:
only the browser's `appinstalled` signal records installed. Prompted records invocation of the browser prompt,
not the user's choice or proof the browser displayed it. Standalone opened counts authenticated page loads in
standalone display mode, not unique application launches. Update ready counts observations per page load of an
actual waiting worker on an already controlled page, not distinct releases or completed updates.
Browser support varies; absence of a signal does not establish a failed install.
These client events use the normal collector privacy gate and are admin-scoped.

## Delivery health and release

The release is the commit the image was built from. The Studio images bake it in as `MOTHERSHIP_BUILD_COMMIT`
(`config('app.build_commit')`, the commit the sign-in page publishes), so every deploy gets its own release and
nothing has to be set. When the image names no full commit (a local run, or an image built without the build
argument), the release is `DAM_ANALYTICS_RELEASE`: a commit SHA or a bounded alphanumeric release identifier.
A build commit always wins over `DAM_ANALYTICS_RELEASE`, so a value left in the environment does no harm.
It is snapshotted into newly staged events as `release`; existing backlog retains its original release.
Invalid values are omitted. This needs no migration.

`dam:analytics:export --health` returns JSON without network calls or writing telemetry: `pending`, `failed`,
`oldest_pending_age_seconds` (from original source receipt, including delayed retries), and
`last_delivered_at` (latest retained successful delivery). Empty backlog age/never-delivered time are null.
Pending includes not-yet-due retries. A failed count above zero needs investigation; increasing backlog age
or stale delivery time during known activity indicates delivery trouble. The retained local snapshot remains
available when PostHog is unreachable. It is not itself a PostHog event or historical dashboard timeseries.

After the deployment owner serializes this release, verify these representative paths against production:

1. Send one disposable file between two embedded sessions; compare sender attempted/acknowledged and receiver
   attempted/received by each local transfer ID. Decline/cancel another offer and interrupt an active connection.
   Confirm no filename, message, room code or peer identity enters either stored event props or export payload.
2. In a supporting browser, observe eligibility, dismiss/accept a real prompt, installation and a standalone
   launch separately. A fresh install is not an update; only a later waiting worker should emit update.ready.
3. Read delivery health before/after the scheduled exporter runs. Confirm new events arrive in project677447
   with production environment and the deployed release. Compare UUIDs during a controlled retry; PostHog must
   retain one event per UUID. Do not infer production delivery from local tests or an empty dashboard.
