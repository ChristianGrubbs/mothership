#!/usr/bin/env bash
# Rebuild the committed admin and DAM front-end bundles exactly as CI does.
#
# Nothing builds these on deploy: the Docker images and the Studio's nginx serve
# public/themes/*/build straight from the repository. A Tailwind utility first
# used in a template after the last rebuild is therefore never generated and
# silently does nothing (2026-09-23: the Library folder cards had no grid, and
# the 3D viewer's flip buttons and build plates never shipped). Run this after
# changing a template, a stylesheet or bundled JavaScript, and commit what it
# changes. .github/workflows/js_tests.yml runs it on every pull request and
# tools/frontend/check-bundles.cjs fails on any difference.
#
# Both packages install from their committed lockfiles, so the output is
# byte-identical to CI's. CI uses Node 22.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"

for package in Admin DAM; do
    npm ci --no-audit --no-fund --no-progress --prefix "$root/packages/Mothership/$package"
    npm run build --prefix "$root/packages/Mothership/$package"
done

# A fresh install publishes the DAM build from the package (DAMServiceProvider),
# so the package copy must match the served one.
rm -rf "$root/packages/Mothership/DAM/publishable/dam/build"
cp -R "$root/public/themes/dam/build" "$root/packages/Mothership/DAM/publishable/dam/build"
