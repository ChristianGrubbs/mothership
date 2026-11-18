# ADR 0012: Creative Hub apps run on their own static origin and talk to The Mothership only by postMessage

Date: 2026-10-16 · Status: accepted

## Context
The operator wants the ArtCraft web apps (PhotoCraft, PdfCraft and eleven more; Apache-2.0 static wasm builds)
inside The Mothership. They must keep their own browser storage (preferences, OPFS libraries, IndexedDB), which rules out
ADR 0007's opaque-origin sandbox. Serving them under `mothership.example.com` would run third-party script next
to the signed-in admin session (`mothership_session` is host-only, `path=/`), the risk ADR 0007 exists to avoid.
ADR 0007's 2026-09-22 decision against a separate serving hostname was scoped to Web Pages v1.

## Decision
- The apps are served unmodified from one static origin, `https://hub.mothership.example.com/<app>/`, by a
  launchd nginx on the Studio behind the existing tunnel. The host holds no credentials and no The Mothership data.
- The Mothership iframes an app with `sandbox="allow-scripts allow-same-origin allow-downloads allow-forms allow-modals
  allow-popups"` (cross-origin, so `allow-same-origin` grants only the app's own storage; no top navigation).
- Files cross only as `postMessage` transfers between the signed-in The Mothership page, which reads bytes through the
  existing access-checked download route, and an injected `hub-agent.js`. Both sides pin the other's origin.
- Saves come back to a DAM endpoint that stores a new asset by default and a new version only on explicit choice.
- Which apps exist and what they can open is data (`registry.json` on the hub host), so an app update never
  needs a Mothership deploy.

## Consequences
- One more hostname, tunnel rule and LaunchAgent on the Studio, managed by `tools/creative-hub/hubctl.py`.
- All apps share one origin, so their browser storage shares one partition; an app that collides moves to its own
  subdomain through the registry's reserved `origin` field.
- Users' work inside an app lives in their browser until they save it to The Mothership.

## Amendment 2026-10-18 — an app whose storage reaches past its own names gets its own origin

**Context.** The storage check of 2026-10-17 (spec section 8) found that LightCraft's `?reset`, after its confirm,
clears the whole OPFS root of the origin it runs on. On the shared hub origin that deleted EffectCraft's projects and
FilmCraft's media and recovery files. LightCraft was held out of the registry until it had an origin of its own.

**Decision.**
- A registry app may name its own origin: `"origin": "https://<host>"` (https, one hostname, no port or path; unique
  across apps; never a parent origin). `hubctl check` enforces this. An app without the field stays on the shared hub
  origin. LightCraft is the first: `https://hub-lightcraft.mothership.example.com`. That is one label under the zone,
  so Cloudflare's Universal SSL certificate already covers it, as it covers `mothership-hub`.
- **One nginx, one port, one server block per hostname.** `hubctl render nginx` keeps the shared hub as the
  `default_server`, with the apps that have no `origin`. Each app with an `origin` gets its own `server_name` block on
  the same `127.0.0.1:18411` listener, serving `/healthz`, `/__hub/` (the same generation, so one `publish` serves every
  hostname) and that one app. The shared hostname never serves an app that has its own origin, and its own hostname
  serves nothing else. cloudflared passes the public Host header through (the hub's ingress rule sets no
  `httpHostHeader`), so the second hostname is a second ingress rule to the same service.
- **The Mothership never takes an origin from the registry.** The registry's `origin` only marks an app as needing its own
  origin. The browser-facing value comes from The Mothership's config, `dam.hub.app_origins` (`DAM_HUB_APP_ORIGINS`, as
  `id=origin` pairs; ~~its default is the production LightCraft origin~~, superseded: the default is now empty, see the
  2026-11-09 amendment). It is used for that app's iframe `src` and as
  the `postMessage` target and accepted origin. An app the registry marks but the config does not name is left out of
  the launcher, Open with and the workspace. It is never opened on the shared origin. A compromised hub registry
  therefore still cannot send a member's file bytes to a host The Mothership did not configure, which is the same rule that
  already applies to the hub origin (spec section 5).
- **Tooling.** `hubctl smoke` checks an app with its own origin on that origin, and checks that the shared hub
  answers 404 for it. `hubctl deploy` refuses an app whose server block is missing from `nginx.conf`. `hubctl serve`
  and the verify harness map such an app to its own local port (`--app-port` and `--app-origin`). A local origin
  differs by port, as a production one differs by hostname.
- **CSP.** The Mothership's admin pages send no `Content-Security-Policy`; a 2026-10-18 probe of `/admin/login` showed only
  `X-Frame-Options: SAMEORIGIN`. So there is no `frame-src` to extend. If one is added later, it must list the hub
  origin and every configured app origin.

**Consequences.**
- One more tunnel ingress rule and one proxied CNAME per app with its own origin, added by the operator. There is no
  new port and no new LaunchAgent.
- An app with its own origin has its own storage partition. Its reset clears only its own data. Moving an app between
  origins abandons whatever it stored on the old one, so an app's origin is chosen before it is first listed and is
  never changed after that, like the hub hostname.

## Amendment 2026-11-09 — `dam.hub.app_origins` defaults to empty

The 2026-10-18 amendment above said the default of `dam.hub.app_origins` is the production LightCraft origin. A
same-day security follow-up (the 2026-10-18 multiuser readiness audit) changed the default to empty
(`'app_origins' => (string) env('DAM_HUB_APP_ORIGINS', '')`, `packages/Mothership/DAM/src/Config/dam.php`). Nothing is
offered by default: The Mothership offers LightCraft only once the operator sets
`DAM_HUB_APP_ORIGINS=lightcraft=https://hub-lightcraft.mothership.example.com` in Studio `app.env`. `app.env` reaches
the containers through `--env-file`, so the change takes effect only after a stack recreate through the deploy scripts;
`.env` plus `config:cache` alone does not reach running containers. The rest of the decision stands.
