# The Mothership installation and network behavior

Root-path hosting is required; subdirectory deployments are not supported. The public manifest starts at the existing Home route. Signed-out launch follows normal login. Icons use the
existing brand mark at 192 and 512 pixels. Supporting browsers offer Install The Mothership in the account menu
only after a real `beforeinstallprompt` event. Browser-native installation remains available where supported;
iOS Safari uses its Share → Add to Home Screen interface rather than this Chromium event.

The worker covers The Mothership's origin only. Navigation is
network-only with `cache: no-store`; API responses, POSTs, user files and authenticated pages are never cached.
Only the public, credential-free offline explanation is cached. It clearly requires reconnection for work.
An HTTP authorization/error response is retained as an HTTP response, not disguised as offline success.

Worker updates are checked on return/online with the script HTTP cache bypassed. A new worker waits until old
app windows close: no forced reload or unsaved-editor loss. Reopening obtains fresh network pages. Only caches
prefixed `mothership-offline-` are deleted during activation. Bump the worker version when its fallback changes.

Local acceptance covers the manifest, real icon dimensions, account-independent fallback, worker navigation
and cache behavior, and offline/reconnect at desktop and 390px mobile layouts. Mobile emulation does not verify
iOS installation. Before rollout acceptance, exercise real installation and standalone launch, login/logout,
Home navigation, worker upgrade with an editor open, and offline/reconnect in each stated supported browser.
