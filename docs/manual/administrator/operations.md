# Release, diagnose and roll back Mothership

## Before you begin

Coordinate one runtime owner and choose an already reviewed, immutable application release. Record its full source commit, the preceding known-good release and the current application, queue and Looper image tags before deployment. Preserve the database volumes and the storage state directory throughout verification, deployment and rollback.

Candidate verification happens before the release, in the repository's pull-request checks: the Pest suites run on the self-hosted runner pool against disposable databases (PostgreSQL is the merge gate), alongside static analysis, code style, translation and JavaScript/browser suites, which need no database. A passing candidate check does not itself deploy the release.

The production runtime is the Studio private stack started by `tools/studio/runtime.py`; its prerequisites, state layout and commands are in the repository's Studio runtime guide (`docs/ops/studio-runtime.md`). The launcher has four lifecycle commands: `up`, `status`, `verify` and `down`.

## Steps

1. Confirm the release's required checks passed on the exact commit you intend to deploy, and that the documentation corpus for that commit is the reviewed one.
2. Promote the reviewed release through the normal Git path, then update the runtime source checkout with a fast-forward-only pull. Drain active jobs first: container replacement uses a short grace period, and a long panorama or video-preview job interrupted during lifecycle work loses that attempt.
3. Run `down`, then build the application image and the queue image from that exact source and tag them for the release. Build and tag the Looper image as well whenever the release changes the Looper backend's source; when it does not, keep using the recorded Looper tag. Keep the stack down for the whole build window and stop the image builder before continuing; the launcher refuses to start while the builder is running. A source pull or PHP reload alone cannot replace code baked into the application image.
4. Run `up` with the new image tags (application, queue and, when rebuilt, Looper). It starts the dependencies, then the application server, whose start applies pending migrations and clears compiled views, then the general, panorama/video-preview, StylePress and vectorization workers and the scheduler. Containers carry a configuration digest, so `up` replaces only owned containers whose configuration changed and preserves all data.
5. Run `status` and `verify`. `verify` checks the containers, the health endpoint, the login page and the login page's static references. Record the deployed full source commit in the release acceptance evidence and compare it with the approved candidate. This exact source identity—not an upstream package version or Packagist freshness—identifies the documentation being served. The current Help interface does not expose a documentation source marker, so do not claim that the interface proves release identity.
6. Exercise one ordinary request and one job in each affected lane. The general queue handles the normal application queues; panorama, image editing and StylePress have dedicated workers because their runtime and retry behavior differ, and vectorization has its own worker as well. Confirm the scheduler is also running.
7. When a job fails, record its job identifier and queue, then inspect the matching worker log and the Laravel application log. Determine whether the failure came from application code, provider response, input, timeout or unavailable infrastructure. Correct that cause before retrying the specific failed job; sending it to a different queue or repeatedly retrying an unresolved paid-provider request does not diagnose it.
8. If the release must be withdrawn, restore the preceding application/documentation source with a forward corrective commit and run the normal deployment path again. For a short application-runtime rollback, first return the runtime source checkout to the preceding release, then run `up` with the previously recorded application, queue and Looper image tags. Both halves are required: PHP runs from the image, but the web server serves static files from the checkout's `public/` tree, so old images over a newer checkout would mix preceding PHP with newer JavaScript and CSS. The launcher replaces only the affected containers and keeps storage and the other services intact. Never delete database or storage volumes as a rollback step.

## Prove the release is live

A deploy request and its result name what proves the release is live. A tag alone does not.

1. **Name both image families, and read them from the running containers.** PHP-FPM and the StylePress worker run `mothership-fpm:<sha12>`; the other Laravel workers and the scheduler run `mothership-q:<sha12>` (Nginx uses its own infrastructure image). A claim that a Laravel release is live gives both tags (the `--fpm-image` and `--queue-image` lines) and the image reference each running container reports, taken from the container runtime. `status` shows only role, running state and IP, so it proves liveness, not which image a worker runs. For the FPM side, `verify` compares the build commit that `/admin/login` publishes with the FPM tag, but accepts an image built without the commit argument (it reports `unknown`): count it as release identity only when the result is a match on the expected full commit.
2. **Name one thing only this release has.** When the release changes a container binding, a middleware or a route, check that the running web container has it (for the consent-session fix, `ReadOnlyAwareStartSession` bound in `DAMServiceProvider`), and cite that check as the proof.
3. **No hosted MCP endpoint to check.** The hosted Craft MCP (`/mcp/craft`) and its upload links were removed in November 2026. Agents reach the library through the local `mothership` stdio server (`php artisan mcp:start mothership`, see `docs/api/mothership-mcp.md`), which is not served over the network, so a release has no MCP route to prove.

## Expected result

`status` shows every owned container running and `verify` passes: web, application, general queue, panorama, StylePress, vectorization and scheduler. Requests use the rebuilt application image; background work reaches its intended queue. The release evidence names the exact documentation source commit that passed acceptance.

A rollback restores the preceding application behavior without erasing originals, renditions, logs, accounts, Home layouts or database state. Any migration compatibility concern remains visible and is handled through the normal forward release process rather than by destroying persistent data.

## Troubleshooting

- **A Git update, image build, migration or `verify` fails.** Stop at that failure. Do not continue manually from a later step; diagnose the first failing boundary, keep the current volumes and state, and rerun the complete sequence after correction.
- **`up` refuses to start.** The image builder is still running, the state directory is owned by a different identity, or an unowned process or resource is using one of the stack's names. Resolve the reported cause; the launcher never takes over a resource it does not own.
- **The page still serves old code after a source update.** The application server runs code baked into its image. Rebuild the image and run `up` with the new tag; a source pull or reload is insufficient.
- **You expect warmed Laravel caches.** The Studio launcher does not warm configuration, route, view and event caches. Application start clears compiled views; do not build caches by hand inside a running container, because the next replacement discards them.
- **A worker starts with stale configuration.** Workers run the baked queue image with the environment the launcher injects. Change the private environment file or the image, then run `up` so the configuration digest replaces that worker; a restart signal alone does not change its configuration.
- **A worker exits and comes back.** Each queue process runs under a small supervisor that restarts it after a clean exit or a memory-limit recycle. After any other failure it waits five seconds and restarts, and stops only after five failures in a row. Inspect that worker's log before running `up` again; do not start a blind restart loop.
- **A failed job is not moving.** Verify its queue has the matching dedicated or general worker, inspect that worker's logs and the application exception, fix the cause, then retry only the identified job.
- **Documentation content does not match the approved release.** Compare the deployed source commit in the release evidence with the accepted source commit. The current Help interface has no source marker; do not infer freshness from installed package versions. Rebuild the approved application release through the release workflow.
- **Rollback would require removing volumes.** Stop. Volume deletion is teardown, not rollback. `down` never deletes the network, the named volumes or the state directory; restore the preceding source or the preserved image tags while leaving the database and storage in place.
