# Navigation performance — 2026-09-05

The deployed runtime at the time bind-mounted the entire host checkout over the PHP image. This
made autoloading and Blade view lookup cross the host-to-container filesystem boundary.
FPM now runs its built application code on Linux-native storage, with compiled
views in disposable native tmpfs. Asset storage remains at its original location.

## Evidence before the change

Authenticated HTTP requests against the existing production library, 10 samples
per route/path, alternating LAN and public tunnel requests:

| Route | Path | Median | IQR |
| --- | --- | ---: | ---: |
| DAM | LAN | 852 ms | 122 ms |
| DAM | Cloudflare | 893 ms | 109 ms |
| Tags | LAN | 737 ms | 117 ms |
| Tags | Cloudflare | 786 ms | 78 ms |

Individual cold requests took roughly 5 seconds. A 50-item library grid requested
all 50 thumbnails immediately. The browser recorded only one 56 ms long task
during a 4.9-second Tags navigation; the delay was mainly the page response.

An instrumented PHP kernel/render probe counted 482 file-existence checks, 114
modification-time checks, and 89 `isFile` checks on a warm DAM request. Filesystem
checks consumed about 600 ms; database queries took about 1 ms. Most existence
checks came from Laravel's view finder. The same page in a disposable native
snapshot took about 22 ms warm, with the same 661,202-byte rendered size and only
about 2.4 ms in those filesystem checks. CLI bootstrap fell from about 5.6 s to
0.5 s. This in-process probe isolates filesystem cost; it is not a claim about
end-to-end HTTP latency. The snapshot used the image's production dependencies.

Small theme/view-cache changes were investigated but not shipped: fixing code
locality removes the measured cost without introducing a new view-cache policy.

## Thumbnail acceptance

Both shared and explorer cards use one page-scoped IntersectionObserver directive.
Only visible/nearby images receive a `src`; updates replace pending sources, and
unmounting disconnects observation. Existing image-error placeholders remain in
place, with eager loading as the fallback when IntersectionObserver is unavailable.
Visible images receive their URLs on the first observer callback rather than
synchronously during mounting; this avoids forcing layout on every mounted card.

`tools/browser/check-lazy-thumbnails.cjs` uses a running application's Vue runtime
and a real browser scroll container. The test-first run, before the directive was
added, fetched 50/50. The fixed fixture asserts at most 5 initial requests (3 were
observed in acceptance), then verifies scrolling, source replacement,
unmount cleanup, the no-observer fallback, and empty sources. This is a manual/live
acceptance check, separate from PHPUnit. Set `BASE_URL` to the running app and supply Playwright through
the existing browser-tool runtime. `PLAYWRIGHT_CHROMIUM_CHANNEL=chrome` selects
installed Chrome where needed. No login or library mutation is required.

## Library navigation regression tests — 2026-09-06

`tools/browser/tests/library-navigation.test.cjs` runs the Library URL/tab-state
regressions with Node. Five real DOM tests are optional: they are **skipped** when
`MOTHERSHIP_PLAYWRIGHT_MODULE_PATH` is unset, and Vue tree/sidebar tests also need
`MOTHERSHIP_VUE_RUNTIME_PATH`. A default Node run therefore does not verify the
header form, pointer/keyboard resize, lazy tree rendering or Iconvault focus and
cross-origin Escape handling. Release acceptance must report zero skips.

Use the existing Playwright/browser runtime and the Vue compiler already supplied
by the locked DAM dependencies; no new package is required. Configure these paths
for the machine running the checks:

| Variable | Value |
| --- | --- |
| `MOTHERSHIP_REPO` | Absolute path of the Mothership checkout under test. |
| `MOTHERSHIP_PLAYWRIGHT_MODULE_PATH` | Absolute path of the existing Playwright module directory. |
| `MOTHERSHIP_VUE_RUNTIME_PATH` | The checkout's `packages/Mothership/DAM/node_modules/vue/dist/vue.global.js`; the global compiler build is required, not the ESM or runtime-only build. |
| `MOTHERSHIP_BROWSER_CHANNEL` | Installed browser channel; `chrome` is the tested default. |

After setting the first two variables, this invocation supplies the checkout's Vue
path and fails early if the required runtime paths are missing:

```sh
cd "${MOTHERSHIP_REPO:?Set MOTHERSHIP_REPO to the absolute checkout path}"
: "${MOTHERSHIP_PLAYWRIGHT_MODULE_PATH:?Set this to the existing Playwright module directory}"
test -d "$MOTHERSHIP_PLAYWRIGHT_MODULE_PATH" && test -f "$MOTHERSHIP_REPO/packages/Mothership/DAM/node_modules/vue/dist/vue.global.js" &&
gtimeout --preserve-status 60s env \
MOTHERSHIP_PLAYWRIGHT_MODULE_PATH="$MOTHERSHIP_PLAYWRIGHT_MODULE_PATH" \
MOTHERSHIP_VUE_RUNTIME_PATH="$MOTHERSHIP_REPO/packages/Mothership/DAM/node_modules/vue/dist/vue.global.js" \
MOTHERSHIP_BROWSER_CHANNEL=chrome \
node --test "$MOTHERSHIP_REPO/tools/browser/tests/library-navigation.test.cjs"
```

The browser tests use real DOM/Vue behavior with
mocked responses, require no credentials and do not mutate the live library.
Authenticated live checks separately verify Iconvault embedding and Escape while
focus is inside the cross-origin catalog. The frame requests closure with
`mothership:iconvault:close`; Mothership accepts only the exact Iconvault origin
and the currently mounted frame window. The same rule guards
`mothership:iconvault:ready`, the frame's answer to the dialog's
`mothership:iconvault:hello`: without that answer the dialog says Iconvault did
not open and offers the new-tab link, because an iframe's `load` event also
fires when the browser refuses the frame (`docs/iconvault-api.md`, "Knowing
whether it opened").

## JavaScript and browser suites in CI — 2026-09-13

`.github/workflows/js_tests.yml` ("JavaScript Tests") runs the three Node suites
that no workflow previously executed, on the self-hosted `windows-gpu` pool:

| Suite | Command |
| --- | --- |
| DAM unit tests | `node --test packages/Mothership/DAM/tests/js/` |
| SphereSeam | `node --test packages/Mothership/SphereSeam/tests/sphereseam.test.mjs` |
| Browser DOM tests | `node --test tools/browser/tests/` |

Until then a pure-JavaScript change to `packages/Mothership/DAM/src/Resources/assets/js/`
could pass every check and still ship a broken UI; PR #138 shipped a Home gadget
popup whose only exit was gated behind a suppressed `window.confirm`, and PR #140
fixed it after the fact.

Every runtime path the browser suites read is resolved from the checkout and
verified to exist before the suites run, so an unset variable fails the job
instead of turning its tests into silent skips:

| Variable | Value on the runner |
| --- | --- |
| `MOTHERSHIP_PLAYWRIGHT_MODULE_PATH` | `tests/e2e-pw/node_modules/playwright` |
| `MOTHERSHIP_VUE_RUNTIME_PATH`, `MOTHERSHIP_VUE_GLOBAL_PATH` | `packages/Mothership/DAM/node_modules/vue/dist/vue.global.js` |
| `MOTHERSHIP_VUE_MODULE_PATH` | `packages/Mothership/DAM/node_modules/vue` (a package directory, `require.resolve`d by the vectorization tests) |
| `MOTHERSHIP_SORTABLE_PATH` | `packages/Mothership/DAM/node_modules/sortablejs/Sortable.min.js` |
| `MOTHERSHIP_VUEDRAGGABLE_PATH` | `packages/Mothership/DAM/node_modules/vuedraggable/dist/vuedraggable.umd.js` |
| `MOTHERSHIP_BROWSER_CHANNEL` | `chromium` — the runners carry Playwright's bundled Chromium, not installed Google Chrome. Local runs keep `chrome` as the default. |

`.gitignore` excludes `package-lock.json` repo-wide but re-includes the DAM and
admin packages', so CI installs both with `npm ci` against their committed locks
and falls back to `npm install` for `tests/e2e-pw`, which has no committed lock.

### Committed bundles — 2026-09-23

The same workflow's "Committed Frontend Bundles" job runs
`tools/frontend/build-bundles.sh` and then `tools/frontend/check-bundles.cjs`.
The Docker images and the Studio serve `public/themes/admin/default/build` and
`public/themes/dam/build` straight from the repository, and no deploy step runs
`npm run build`. A Tailwind utility first used in a template after the last
rebuild is never generated, and a change to bundled JavaScript never ships. By
2026-09-23 the Library folder cards had no grid, and the 3D viewer's flip
buttons and build plates from PR #20 had never been built.

The job fails if the rebuild changes, adds or removes any file under either
build directory or `packages/Mothership/DAM/publishable/dam/build`. It also lists
each class that the rebuilt CSS has and the committed CSS lacks, with the
template that uses it. To fix it, run `tools/frontend/build-bundles.sh` on
Node 22 and commit the result. Both lockfiles make the output byte-identical
to CI's.

One test is expected to skip: the password-protected-PDF case needs an encrypted
fixture (`MOTHERSHIP_PDF_PASSWORD_FIXTURE`) that is not in the repository. The
job asserts the browser suite reports at least one pass and no more than that one
skip, so a missing runtime path cannot quietly disable browser coverage.

## Deployment checks

- FPM must have no mount at `/var/www/html`, retain writable persistent storage,
  and provide native temporary compiled views.
- Build and recreate FPM; Git pull or a PHP-FPM reload alone does not ship code.
- Verify authenticated DAM and Tags navigation, thumbnail scrolling, asset details,
  and an authorized cached thumbnail through nginx after deployment.
- Compare the same HTTP routes against the table above, using median and spread.

The runtime lifecycle is in [the Studio runtime guide](studio-runtime.md).
