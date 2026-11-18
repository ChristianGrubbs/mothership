# TinyMCE 7.9.3 with embedded DOMPurify 3.4.17

Admin installs `tinymce-7.9.3.tgz` through a local `file:` dependency. Its lockfile
pins the artifact's SHA-512 integrity. Vite copies that installed package into
the public build; it must never be replaced by the stock npm TinyMCE package.
No package is published externally. The upstream package version stays 7.9.3;
the local artifact, source patch, integrity and receipt identify this build.

TinyMCE embeds its own sanitizer. Updating Admin's separate DOMPurify dependency
does not update it. This build replaces embedded 3.2.6 with exact 3.4.17 to address
[GHSA-55q2-fjhq-7xh7](https://github.com/advisories/GHSA-55q2-fjhq-7xh7).
The patch changes only upstream `modules/tinymce/package.json`, `yarn.lock` and
`NOTICES.txt`; sanitizer integration and editor settings are unchanged.

## Provenance

- Upstream: [tinymce/tinymce](https://github.com/tinymce/tinymce), commit
  `9dac5bb3c875b9c5de43a3f6ea33f55437e77325` (7.9.3).
- `upstream-build-receipt.json`: sanitized build receipt, tool versions, exact
  source/archive/patch/package/core hashes, and prior browser evidence.
- `distribution-manifest.json`: hashes of all 202 upstream build outputs.
- `package-manifest.json`: hashes of all 238 files in the accepted npm artifact,
  including component metadata and entrypoints; verification requires the exact set.
- `package-build.py`: packaging script. It requires a fresh staging directory and preserves those 202 files
  byte for byte and adds 36 missing metadata/entrypoint files from upstream's
  component ZIP. The npm artifact has 238 files; Vite prunes it to 155.
- GPL license and third-party notices are retained. Built CSS retains its
  source-map comments; Vite's existing pruning removes the maps.

## Rebuild the artifact

Use a **new disposable source checkout outside The Mothership checkout**, with
a session authorized for that checkout. Do not modify a shared upstream clone.
Use Node 26.12.0, npm 11.20.0 and Yarn Classic 1.22.22, matching the receipt.
Yarn is a build-only dependency required by upstream's monorepo; runtime adds no
dependency. Python 3's standard library stages the package. Follow upstream's
[build instructions](https://github.com/tinymce/tinymce/blob/9dac5bb3c875b9c5de43a3f6ea33f55437e77325/CONTRIBUTING.md).

From the new upstream checkout, set `MOTHERSHIP_TINYMCE_ASSETS` to this directory's
absolute path, then run:

```sh
git checkout --detach 9dac5bb3c875b9c5de43a3f6ea33f55437e77325
test "$(git rev-parse HEAD)" = 9dac5bb3c875b9c5de43a3f6ea33f55437e77325
git apply --check "$MOTHERSHIP_TINYMCE_ASSETS/tinymce-dompurify-3.4.17.patch"
git apply "$MOTHERSHIP_TINYMCE_ASSETS/tinymce-dompurify-3.4.17.patch"
mkdir -p .build-cache/tmp .build-cache/yarn .build-cache/npm build-artifacts
export TMPDIR="$PWD/.build-cache/tmp"
export YARN_CACHE_FOLDER="$PWD/.build-cache/yarn"
export npm_config_cache="$PWD/.build-cache/npm"
export XDG_CACHE_HOME="$PWD/.build-cache"
yarn install --frozen-lockfile --ignore-engines --no-progress
yarn build
cp "$MOTHERSHIP_TINYMCE_ASSETS/package-build.py" build-artifacts/package-build.py
python3 build-artifacts/package-build.py
npm pack ./build-artifacts/npm-package --pack-destination ./build-artifacts --json
shasum -a 256 build-artifacts/tinymce-7.9.3.tgz modules/tinymce/js/tinymce/tinymce.min.js
```

The receipt records the accepted build's hashes. Cross-platform byte-identical
rebuilds have not been established. Compare all output hashes; investigate a
difference before replacing the artifact and receipt. Never edit minified public
files to manufacture a version match. Recheck license, notices and browser
behavior when changing the artifact.

## Integration checks

From the repository root, with Node 22 (the CI toolchain):

```sh
npm ci --no-audit --no-fund --no-progress --prefix packages/Mothership/Admin
npm run build --prefix packages/Mothership/Admin
```

The build runs `verify.cjs`, checking package integrity, all installed upstream
outputs, public byte parity, executable DOMPurify version assignments, licenses
and manifest references. The core must hash to
`6064fb079123cbbb2ff91253d9a75f3594d4fcd69587a2d7911323023314512e`.
The focused PHP check is `vendor/bin/pest packages/Mothership/Admin/tests/Feature/SelfHostedAssetsTest.php`.

The existing Node & Browser CI job discovers
`tools/browser/tests/admin-tinymce-sanitizer.test.cjs`. Locally, set
`MOTHERSHIP_PLAYWRIGHT_MODULE_PATH` to an installed Playwright module and run:

```sh
node --test tools/browser/tests/admin-tinymce-sanitizer.test.cjs
```

The first test exposes the bundled DOMPurify instance through one test-only
instrumentation assignment. It exercises in-place sanitization with a removable
wrapper and a harmless image `onload` counter, requiring no execution and safe
sibling retention. An always-run stock control confirms embedded 3.2.6 executes
that counter. Its test-only fixture, `tests/Fixtures/tinymce-7.9.3-stock.min.js.gz`,
is a deterministic gzip of the npm 7.9.3 core (SHA-256
`ca7a4ae9354b117b3133d5b449b4f807ac1484e66b5019c1381e1990ebb1ecd3`).
It retains the upstream GPL header and is covered by TinyMCE's retained
`license.md`; it is never copied into the public distribution.
This is a **library-level red/green reproduction**, not an exploit claim against this app.
The second test uses the unmodified shipped TinyMCE parser and checks bogus
wrapper removal, safe sibling retention and no marker execution. Further tests
cover MathML/SVG/mixed content and real editor initialization with Admin's plugin
list, public themes/models/icons/skins, and a sanitized content round trip. The upstream
receipt separately records 19 passing sanitizer browser tests. Full browser
exploitability and Studio acceptance remain unproven; this change does not deploy.
