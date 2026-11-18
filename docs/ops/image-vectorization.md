# Image Vectorization operations

The tool is a separate Home item. Add it through Customize → Add on an existing saved layout.
The asset Preview toolbar offers **Convert ▾** for supported raster files. Choosing SVG or PDF queues conversion in place and shows an image overlay. The server publishes the selected format as an output attachment in the source asset's Files tab, including when the user leaves the page. Original content is preserved. Existing SVG assets render directly in the Preview tab and expanded image viewer.

Asset conversion routes validate asset edit/Files access, original bytes, the existing 20 MiB/pixel limits, and the selected format. The request records its source asset and format; replay must match both and the input hash. File publication and allowance settlement share the conversion transaction lock. A failed or expired request cannot attach a late output. The Home tool continues to provide its separate account-owned SVG/PDF exports.

Available allowance is non-expiring. User managers set the available count in Settings → Users → Edit.
In-progress reservations are separate; a failed job returns one reservation, while a completed job consumes it.
Only the verified `MOTHERSHIP_VECTORIZATION_OWNER_ADMIN_ID` is unlimited.

The server uses `MOTHERSHIP_VECTORIZER_API_ID` and `MOTHERSHIP_VECTORIZER_API_SECRET` from the runtime environment.
The provider charges for production tracing and a second format; these are distinct from one user allocation.
Outputs are private under `storage/app/private/vectorizations/results/<conversion UUID>/`.
HTTP routes enforce creator ownership. Downloads are local and do not call the provider.

Worker: `mothership-vectorization-q`, database connection `vectorization`, queue `vectorization`, timeout 480 seconds,
`retry_after` 1200 seconds (`VECTORIZATION_QUEUE_RETRY_AFTER`; raised from 600 when this connection/queue
started also carrying `Mothership\DAM\Jobs\OptimizeAsset`, whose own 1020s timeout would otherwise exceed the
old reservation window), one attempt. Every five minutes `dam:vectorizations:expire` refunds stale processing
jobs after 15 minutes and stale queued requests after 30 minutes. No automatic paid-call retry occurs.
Diagnostics use `storage/logs/home.jsonl`, gated by `MOTHERSHIP_HOME_LOG_ENABLED`, and contain structural metadata only.

Disable new submissions with `MOTHERSHIP_VECTORIZATION_ENABLED=false` and rebuild cached config.
Allow existing work to finish before stopping the worker. Keep the additive tables and stored results.
Rollback uses a normal reviewed revert and deployment, never dropping user data or rewriting history.

## Release and live acceptance — 2026-09-08

Implementation merged in PR 69 of the earlier repository; live acceptance repairs merged in PR 70. The deployment of `297076e1824ff4b5783cb26635e91377fdbbdd07` to the deployed runtime at the time exited 0 at 2026-09-09 00:39:57Z (September 8 locally). All services and the application probe passed. The previous FPM image is recorded in the deployment receipt for rollback.

Verified through authenticated Chrome against the deployed runtime at the time:

- Home Customize → Add persists the separate tool, preserves existing tiles and opens its iframe. StylePress, Looper and an existing image-editor document still respond.
- Verified active human owner ID1 is unlimited, including disabled allowance-edit controls. Another full-access user starts at zero; an ordinary user cannot grant credits (403).
- The real manager panel loads and saves allowances, survives actual admin Ajax navigation, and grants one conversion. Zero allowance disables submission.
- Conversion `4f4878d1-ce7c-4f42-b038-bcdca0741452` completed using one allocation. Reloaded history, SVG preview, 200% zoom, SVG/PDF browser downloads and Copy SVG pass. Windows clipboard normalizes LF to CRLF; copied content matches after newline normalization.
- Repeated downloads add no job or charge. The exhausted user cannot submit again. Owner and another full-access user receive 404 for another creator's result and both exports.

SVG: 26,054 bytes, viewBox `0.00 0.00 197.00 256.00`, 43 paths; SHA-256 `716c60ada5fc3b3df26c1b779b44b0afb4a33d48e7cc29b82000c08c1d93e81e`.
PDF: 6,098 bytes; SHA-256 `6008e1b80a798a0d1790a19d46e560dfdb3e44f45f1df27aaad121440b9bb920`. Poppler validates one 197×256-point page, PDF1.4.

The first application attempt failed local validation and refunded its reservation. Captured real responses isolated two compatibility bugs: a standard SVG1.1 PUBLIC declaration and a valid PDF comment before `trailer`. Narrow normalization and the existing PDF whitespace skipper now accept those artifacts while preserving malformed-output rejection. Original bodies are regression fixtures under `packages/Mothership/DAM/tests/Fixtures/vectorization-provider/`.

Provider balance moved from 232.94 to 229.74 credits: **3.20 of the authorized 25 credits used** (failed trace1.00, retained diagnostic pair1.10, successful application pair1.10). Clipboard/access rechecks reused the completed result without another provider call. Temporary human accounts2/3 were disabled after acceptance; owner1 stays active and result ownership/files remain intact.

Both successful retained pairs were archived in the maintainer's media archive, outside this repository, with their SVG embeds and same-tracing PDF companions verified there.

## Verification and limits

All application editing, commits, PHP/Pest, Chrome and container execution took place in the checkout of the deployed runtime at the time.

| Check | Result |
| --- | --- |
| Provider client, including real response fixtures | 85 passed / 218 assertions; run `a816a906-459d-4923-b2c6-d1cf6c81f296` |
| Allowance backend | 5 passed / 25 assertions; run `bfde4263-4e07-4f5e-8ec3-af3610938205` |
| Chrome tool and real-Vue allowance suites | 8 passed, 0 failed, 0 skipped |
| Focused lifecycle/worker vectorization gate before final narrow repairs | 86 passed / 266 assertions |
| Home/User regression | 57 passed / 255 assertions |
| Readiness isolated owner/credential/inactive-owner cases | 4 passed / 5 assertions |
| Full DAM | 1,256 passed, 3 skipped, 2 documented baseline fillable failures; 4,231 assertions; run `248eb6f1-8d1e-4dc8-a28c-1e1390cb58f6` |
| Tooling | 66 passed, 1 skip, 1 unchanged Windows Path-literal baseline failure |
| Formatting and diff checks | Passed for changed files |

Disposable verifier databases were dropped and locks released. Runtime readiness passes; an ephemeral disabled-config process fails readiness with exit1. Actual queue command and five-minute expiry schedule match the operational settings above.

All 17 GitHub Actions jobs on each release PR did not start because of the account billing/spending restriction; their failure annotations were checked individually. They are not executed test failures. Execution on the deployed runtime at the time is the verification evidence.

Guarded independent task, whole-branch and scoped repair reviews were completed. Remaining advisory coverage gaps are a bare standard DOCTYPE without an XML declaration and a failed recovery GET following an allowance conflict. General compact-arc SVG grammar, Adobe Illustrator import and arbitrary AI-client SVG preservation are not certified by this acceptance.

Acceptance evidence is retained under `.superpowers/sdd/2026-09-08-image-vectorization/` in the feature checkout used for that acceptance (not in this repository): `live-vectorization-evidence.json`, `live-output-validation.json`, `clipboard-probe-result.json`, `runtime-checks.json`, `acceptance-cleanup.json`, deployment receipts and scoped worker/review reports. Historical failed probes remain separately named; the final browser receipt has status `passed`.
