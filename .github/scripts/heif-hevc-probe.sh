#!/usr/bin/env bash
# Exit 0 only when libvips really decodes the committed HEVC HEIC fixture: no "error in tile" on stderr (a missing libheif
# HEVC plugin makes vips warn that and still write a black image) and the left/right pixels are the fixture's red/blue.
# Profile warnings ("ignoring nclx profile") are harmless. Used by the HEIC step to decide whether to install the plugin.
set -u
fixture="${1:-packages/Mothership/DAM/tests/Fixtures/heic/two-tone-64x48.heic}"
work="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/heif-hevc-probe.XXXXXX")"
trap 'rm -rf "$work"' EXIT

vips copy "$fixture" "$work/probe.png" 2>"$work/stderr" >/dev/null || exit 1
grep -q 'error in tile' "$work/stderr" && exit 1

left="$(vips getpoint "$work/probe.png" 8 24 2>/dev/null)" || exit 1
right="$(vips getpoint "$work/probe.png" 56 24 2>/dev/null)" || exit 1
read -r lr lg lb _ <<<"$left"
read -r rr rg rb _ <<<"$right"
[ "${lr:-0}" -gt 180 ] && [ "${lg:-255}" -lt 60 ] && [ "${lb:-255}" -lt 60 ] || exit 1
[ "${rb:-0}" -gt 180 ] && [ "${rr:-255}" -lt 60 ] && [ "${rg:-255}" -lt 60 ] || exit 1
