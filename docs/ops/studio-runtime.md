# Studio private runtime

`tools/studio/runtime.py` runs the empty The Mothership library privately on the
production Mac with Apple container. By default, Apple Container publishes Nginx
only on `127.0.0.1:18090`. An opt-in launcher-owned Python byte relay can bind
that same loopback address and forward to Nginx's discovered private IPv4
address without an Apple Container web publication. Mailpit's browser UI stays
private unless an explicit loopback port is supplied.
PostgreSQL, Redis, Elasticsearch, Mailpit, and FPM have no host-published ports.
Queue workers, the scheduler, and the Looper backend also stay on the private
VM network.

## Studio invariants

Read this before any cleanup, tidy, or process work on the Studio. Quitting
Terminal on 2026-09-24 took the public site down with HTTP 502 for about 18
minutes.

Never quit, kill, or boot out:

- **Terminal.app.** The relay-mode web relay
  (`runtime.py ... --web-port 18090 ... relay-serve`, listening on
  `127.0.0.1:18090`) keeps its macOS Local Network grant through the desktop
  Terminal that launched it. Quitting Terminal leaves the listener up, but the
  relay can no longer reach Nginx. Closing an idle window is safe.
- **LaunchAgent `app.themothership.autostart`.** At login it runs
  `open -a Terminal` on
  `~/Library/Application Support/Mothership Autostart/mothership-autostart.command`.
  That wrapper syncs the private gateway and then runs
  `~/Desktop/Mothership-Private.command` in relay mode. This operator-installed
  wrapper is the only autostart; `runtime.py` installs none.
- **LaunchAgent `app.themothership.studio-bridge`.** It supervises the
  `cloudflared ... tunnel run` connector that serves
  `https://mothership.example.com`.
- **The Apple container services
  `com.apple.container.container-runtime-linux.mothership-private-*`** and the
  network `com.apple.container.container-network-vmnet.mothership-private`. Stop
  them only with `runtime.py ... down`.

Production path: `cloudflared` → the relay Python process on `127.0.0.1:18090`
→ the `mothership-private-nginx` container → `mothership-private-fpm`, with the
queue, scheduler, Looper, PostgreSQL, Redis, Elasticsearch, and Mailpit
containers behind it.

A process-name check is not enough. The relay's command line puts
`--web-port 18090` before `relay-serve`, so a pattern such as
`relay-serve.*--web-port` misses it. Match on the listener instead:
`lsof -nP -iTCP:18090 -sTCP:LISTEN`.

Recovery: if the public site returns 502 and nothing is listening on 18090, or
the relay lost its grant, run this in a Studio shell as the account that runs the services. It reopens
the wrapper in desktop Terminal, and `up` reuses unchanged containers and
refreshes the relay:

```bash
launchctl kickstart gui/$(id -u)/app.themothership.autostart
```

Then check `~/Library/Logs/mothership-autostart.jsonl` for `tunnel.ready`.

## Prerequisites

- Matching source checkout and local images built from it:
  `mothership-fpm:<sha12>` and `mothership-q:<sha12>`, where
  `<sha12>` is the first 12 characters of the commit the images were built
  from, plus `mothership-looper:bf4b288`. The FPM and queue tags change
  with every deploy that rebuilds them (see [Image builds](#image-builds)). The
  launcher has no built-in FPM or queue tag: `up`, `down`, `status`, and
  `verify` exit with status 2 unless `--fpm-image` and `--queue-image` are
  both passed.
- Private state root on the Studio internal disk with `app.env` at its root.
- `app.env` mode `0600` and non-empty `APP_KEY`, `DB_DATABASE`, `DB_USERNAME`,
  and `DB_PASSWORD`. Keep those values stable across restarts. The launcher
  injects current dependency IPs and forces `APP_ENV=production`,
  `APP_DEBUG=false`, `APP_URL=http://127.0.0.1:18090`, and
  `MOTHERSHIP_PIM_ENABLED=false`.
- Server-side sessions are encrypted by default with `APP_KEY`. Enabling this
  setting for a stack that has plaintext Redis sessions makes those existing
  sessions empty on their next request, so staff sign in again. Keep `APP_KEY`
  stable after enabling it.
- A private Higgsfield CLI session at `$STATE/higgsfield-session`, with the
  directory mode `0700` and files mode `0600`. FPM and StylePress
  share it read-write at `/var/lib/mothership/.config/higgsfield`.
  Keep the driver, expected account, and expected workspace settings in
  `app.env`. The launcher never prints or logs session contents. With the
  `subscription` driver, `verify` parses `credentials.json` only to confirm it
  is a non-empty JSON object and fails when the login is missing; sign in with
  `higgsfield auth login` and place the resulting file here.
- `MOTHERSHIP_OPTIMIZER_API` (Clop, `:18766`) and `MOTHERSHIP_AI_API` (AI,
  `:18767`), when set, must use the current private-network gateway, for example
  `http://192.168.164.1:18766`. The launcher reads the gateway from
  `container network inspect`, rejects a stale host, and checks `/health` from
  FPM before declaring the stack ready; see [Native bridges](#native-bridges).
- Apple container running. The launcher does not start or install the runtime.
- In **System Settings → Privacy & Security → Local Network**, enable
  `container-runtime-linux`. On macOS 27.0 beta build 26A428, a disabled toggle
  leaves the native loopback listener present but resets HTTP connections and
  records backend `Errno 65`. This is the permission behavior tracked in
  [apple/container#2029](https://github.com/apple/container/issues/2029); the
  launcher does not bypass the setting or reboot the Studio.
- The optional `--web-transport relay` mode requires Local Network access for
  Homebrew Python as well as `container-runtime-linux`. On this macOS build,
  invoke relay-mode `up` from a visible desktop Terminal window. A detached SSH
  or `launchctl submit` context still receives `Errno 65` after its SSH session
  ends even when Python is enabled in Local Network settings. The same command
  launched from desktop Terminal remains reachable after that window closes.
  No GUI hook, login item, or autostart is installed by this launcher. Keep the
  default `native-publish` transport as the operational fallback.
- Finish image builds before `up`, then run `container builder stop`. The
  launcher refuses to start while the default-network builder is running.
  Apple [container issue #2051](https://github.com/apple/container/issues/2051)
  documents a still-open multi-NAT bridge collision where teardown on one
  network can remove the other network's bridge until the helper restarts. It
  reproduced on this Studio when the default-network builder overlapped the
  private named network: host routing disappeared and all guest TCP failed.

`app.env` accepts blank lines, whole-line `#` comments, optional `export`, and
single- or double-quoted values. Trailing inline comments are value text; place
comments on their own lines.

## Native bridges

`runtime.py up` owns the startup of the Clop optimizer bridge
(`tools/studio/clop_service.py`, `MOTHERSHIP_OPTIMIZER_API`, `:18766`) and the AI
bridge (`tools/studio/ai_service.py`, `MOTHERSHIP_AI_API`, `:18767`), plus the
Home video download service (`tools/studio/video_service.py`,
`MOTHERSHIP_HOME_VIDEO_API`, `:18769`, [studio-video.md](studio-video.md)). A bridge
marked optional in `MANAGED_BRIDGES` has its failure printed and never fails `up` or
`verify`; none is marked optional today. After the
containers are healthy and before `verify`, `up` runs each configured bridge's
manager `up` on the gateway host and port from `app.env` (after the private
gateway check above). The managers are idempotent: a healthy bridge running
the current `server.py` is left alone, a stale one is restarted. A bridge whose
env key is unset is skipped with a printed notice. If a manager fails, `up`
finishes trying the other bridge and then exits non-zero naming the failed
bridge(s).

Bridge state lives under `Mothership Native Services/<clop|ai>`, a sibling of the
`--state` root (for `Studio Data/Mothership Private`, that is
`Studio Data/Mothership Native Services`). Pass `--native-state <dir>` to override.

`verify` health-checks the optimizer and the AI bridge from FPM (each only when
configured) and exits non-zero naming each unreachable
bridge, so `launcher verify passed` can no longer hide a dead bridge. Because the
login autostart and every deploy run `~/Desktop/Mothership-Private.command`,
which calls `runtime.py up`, a Studio reboot or a deploy refreshes each configured bridge.
No change to the Desktop launcher is needed.

### Bridge watch

Between reboots and deploys nothing else restarted a bridge. A crashed AI bridge
left new uploads undescribed until someone noticed. `tools/studio/bridge_watch.py`
runs every five minutes from the user LaunchAgent
`app.themothership.bridge-watch`
(`tools/studio/launchd/app.themothership.bridge-watch.plist`).

- It reads the optimizer and AI endpoints from `app.env` and probes each `/health`.
- A bridge that fails two checks in a row is handed to its manager's idempotent
  `up`. It goes through Terminal (`open -a Terminal`), as the autostart does, so
  the new process has the same Local Network standing as one started at login.
- At most one restart per bridge every 15 minutes.
- It never restarts a bridge while `mothership-private-fpm` is not running.
- Stack self-heal (added 2026-10-19 after that day's container-service outage):
  while `mothership-private-fpm` is not running and the public
  `https://mothership.example.com/admin/login` fails (not 2xx/3xx) on two checks
  in a row, it runs `launchctl kickstart gui/$UID/app.themothership.autostart`.
  It waits while an autostart run is in progress (the newest run in
  `~/Library/Logs/mothership-autostart.jsonl` has no `tunnel.ready` or
  `autostart.failed` yet and began under 20 minutes ago, or its script is still
  running), keeps a 15-minute cooldown and makes at most three attempts an hour.
  A deploy that keeps the public URL down for ten minutes can trigger it.
  Rehearse without acting: `/usr/bin/python3 .../bridge_watch.py --simulate-down --dry-run`.
- Every decision is logged to `~/Library/Logs/mothership-bridge-watch.jsonl`.

Install or refresh it on the Studio after a deploy that changes it:

```bash
sed "s#__HOME__#$HOME#g" "$HOME/Studio Workspaces/Mothership/source/tools/studio/launchd/app.themothership.bridge-watch.plist" > "$HOME/Library/LaunchAgents/app.themothership.bridge-watch.plist"
launchctl bootout "gui/$(id -u)/app.themothership.bridge-watch" 2>/dev/null; launchctl bootstrap "gui/$(id -u)" "$HOME/Library/LaunchAgents/app.themothership.bridge-watch.plist"
```

The AI index has its own recovery for files the outage left behind
(`docs/ops/studio-ai.md`, "Recovery"). An hourly `dam:ai-index:reconcile --transient`
re-queues them once `/health` answers again.

## Lifecycle

From a fresh terminal on the Studio:

```bash
SOURCE="$HOME/Studio Workspaces/Mothership/source"
STATE="$HOME/Studio Data/Mothership Private"
TAG='<sha12>'
IMAGES=(--fpm-image "mothership-fpm:$TAG"
        --queue-image "mothership-q:$TAG"
        --looper-image 'mothership-looper:bf4b288')

python3 "$SOURCE/tools/studio/runtime.py" --source "$SOURCE" --state "$STATE" "${IMAGES[@]}" up
python3 "$SOURCE/tools/studio/runtime.py" --source "$SOURCE" --state "$STATE" "${IMAGES[@]}" status
python3 "$SOURCE/tools/studio/runtime.py" --source "$SOURCE" --state "$STATE" "${IMAGES[@]}" verify
python3 "$SOURCE/tools/studio/runtime.py" --source "$SOURCE" --state "$STATE" "${IMAGES[@]}" down
```

Set `TAG` to the deployed FPM and queue tag. The Desktop launcher
`~/Desktop/Mothership-Private.command` is the record: each deploy rewrites its
`--fpm-image`, `--queue-image` and `--looper-image` lines. `container image list`
shows which tags are present locally. A source fast-forward that does not
rebuild the images, such as a docs-only merge, leaves the tag on the older
commit, so do not derive `TAG` from the checkout's current `HEAD`.

Run native-publish lifecycle commands in an attached Studio shell. Run
relay-mode `up` from desktop Terminal as described above; SSH-launched Python
receives `Errno 65` when it opens routes to Apple Container VM addresses.
No login item, restart policy, or other host autostart is installed. With
`--web-transport relay`, `up` starts the relay as a detached child of the
desktop Terminal launcher after Nginx has a private address; `down` stops that
owned process before deleting containers. Pass the same transport option to
every lifecycle command. Omitting it selects `native-publish`.

`up` starts dependencies and the private Looper backend in order, discovers
their current IPv4 addresses, starts FPM and its first-boot migrations, then
starts the general, panorama/video-preview, StylePress, vectorization, and
ai-index (`mothership-private-ai-index-queue`) workers plus `schedule:work`. It renders the Nginx FPM upstream
and configures the selected localhost transport. Relay mode starts or refreshes
the process for Nginx's current private IP. It then verifies Looper health, the
selected transport, `/up`, `/admin/login`, and the
login page's local static references. RSS remains the configured Umbrel service.
Before starting FPM, it also probes PostgreSQL, Elasticsearch, Mailpit, and
Looper from the Redis guest. This fails early when the named network has IPs but
has lost its bridge or guest-to-guest routes.
After validating the Elasticsearch volume's ownership label, `up` uses a labeled
ephemeral Alpine container to set only that volume root to UID 1000/GID 0; it
does not recursively rewrite existing Elasticsearch data.
To expose Mailpit's UI locally, add `--mailpit-port 18091` to every lifecycle
command that needs the same configuration.

**Held start (maintenance).** `php artisan down` writes `storage/framework/down` on
the shared storage state, so the pause survives `down`, a rebuild and `up`: the
application answers 503, workers started without `--force` take no new jobs and
scheduled commands do not run. The Looper backend only writes in response to
requests, and Nginx reaches it through Laravel only, so nothing else writes
while the pause holds. Laravel exempts the health route, so `/up` stays 200. When that marker exists,
`verify` still requires `/up` 200 but requires `/admin/login` to answer 503 (a
200 under a hold is a failure), checks every container, the collector and the workers as
usual, skips the login-asset fetches, and prints `held … application NOT
reopened`. The autostart wrapper's public-URL check then fails on the 503 by
design; that failure does not stop anything. Smoke the candidate through the
bypass cookie of `artisan down --secret=<custody value>`, then reopen with
`artisan up`; a held start is never a reopening and a 503 is never a normal
verify pass.

`down` validates relay process identity and container ownership before stopping
the relay and deleting the fixed container names. It never deletes the private
network, named volumes, or application data below the state root. A foreign
process or resource using an owned name is a hard failure.

Drain active jobs before `up` or `down`. Container replacement and shutdown use
a 10-second grace period. Panorama/video-preview jobs can legitimately run for
hours and use `--tries=1`; interrupting one during lifecycle work can lose that
in-flight attempt instead of retrying it.

## Menu bar app

`tools/studio/menubar` is a Mac menu bar app that shows the stack's health and
starts, stops and restarts it. It drives the stack only through the wrapper's
`up`, `down` and `status`, and reads health with read-only checks.

Install or update it from Terminal on the host. Installing never starts, stops
or restarts the stack, and a failed update puts the previous version back:

```bash
zsh tools/studio/menubar/scripts/install.sh --wrapper <stack-wrapper> --app-env <state>/app.env --site-url https://<host>/up --disk <volume> --coordinator <lease-tool> --deploy-lease worktree:<live-checkout> --umbrel-ssh <user@umbrel-host> --umbrel-key <private-key> --umbrel-capacity-file <state>/storage/app/private/umbrel-capacity.json --craft-tool <artcraft>/tools/craft-local/craft_updates.py --bridge-source <live-checkout> --bridge-native <native-services-folder>
```

- The panel is a split-flap board in the site's style. Its **Graphite** and **White**
  themes change the panel around the board, and the board stays dark in both. Faults
  are written out in full under the board. The board animates only while the panel
  is open, and Reduce Motion turns the animation off.
- The **ArtCraft** row opens a page that lists installed and latest versions.
  **Update Apps** causes no outage and skips apps that are open. **Update Engines**
  asks first, stops the site, builds the engines, starts the site once, then
  activates the engines that pass their checks. It builds only ArtCraft engine
  images, and only while every stack container is stopped.
- After an engine update starts the site, the app waits up to 3 minutes for the
  site address to answer 200. If it doesn't, the panel says the site didn't come
  back and the app stops there. It never runs `up` a second time.
- **Restart** on the MCP server asks first if sessions are open or a key render is
  running. The ArtCraft tool makes every one of these changes.
- The panel's storage figures come from the Umbrel, where assets live: the space
  The Mothership's bucket uses and the space free on the Umbrel's data drive. The
  app reads them over SSH with one fixed read-only command, every 4 minutes, or
  every minute while the panel is open or after a failed read. If the Umbrel can't
  be read the panel says **Umbrel unreachable**. The line for this Mac is only the
  working space for drafts and staging.
- Health checks run every 30 seconds. Checks that launch a process run every
  5 minutes while the stack is healthy and the panel is closed, and every minute
  otherwise. Container memory and uptime are read only while the panel is open.
- The app is the LaunchAgent `app.themothership.menubar`. At each login it waits
  for the container runtime and runs `up` unless the stack is already running.
  Clear **Start site at login** in the panel to turn that off.
- The app starts the stack only at login or when you click its control. It never
  restarts the stack because it looks down, so a planned outage stays down.
- With `--bridge-source` and `--bridge-native` set, the app also watches the
  optimizer, AI and video bridges. A bridge that fails two health reads in a row is
  restarted with its own manager's `up`, one bridge at a time. The app never runs the
  stack's `up` or `down` for this, and never stops a process itself. It leaves the
  bridges alone while a stack action runs, while a deploy holds the lease on the live
  checkout, and while the site's PHP container isn't running. After two failed restarts
  it stops trying and the panel stays on **Needs attention** until the bridge answers
  again. If the bridge's manager reports that another program answers on its port, or
  macOS refuses the restart, it stops at once and says so. Another program on that port
  that doesn't answer the bridge's health check reads as two failed restarts. A stopped
  Ollama never counts against the AI bridge.
- A Mac that keeps the stack on a removable volume asks once for access to that
  volume and to the local network. Allow both, or the app cannot read the stack.
  If macOS refuses the drive, the panel says so and opens Privacy & Security.
- Ollama and the tunnel run outside the stack. If only they are down, the panel
  reports it but does not recommend a restart, because a restart would not fix it.
- Each action runs in its own session (`Resources/stack-action.sh`), so quitting
  the app does not interrupt it. The app picks the action up again when it reopens.
- Actions refuse to run while another `up` or `down` is in progress, while a
  deploy holds the lease on the live checkout, or when the coordinator can't say
  whether one does. An engine update also refuses while the site's queues are busy.
- While an action runs, the app writes `stack-action.json` in its actions folder.
  The deploy steps read that marker after they claim the lease.
- Output of each action is in
  `~/Library/Application Support/The Mothership Menu Bar/actions/`. The app's own
  log is `app.jsonl` in the folder above it.
- Remove it with `zsh tools/studio/menubar/scripts/install.sh --uninstall`. The
  site keeps running.

CI cannot build the app. Test it on a Mac with Xcode:

```bash
cd tools/studio/menubar && DEVELOPER_DIR=/Applications/Xcode-beta.app/Contents/Developer swift test
```

## State and ownership

The isolated named VM network is `mothership-private`. It deliberately uses the
runtime's shared network mode so containers and the Studio host can reach the
discovered VM addresses; it is not an airgapped network. Host publication is
still limited to explicit `127.0.0.1` listeners. In relay mode, the ownership record is
`$STATE/runtime/web-relay.json` with mode `0600`; it contains only process and
private endpoint metadata. Relay output goes to
`$STATE/runtime/web-relay.log`, also mode `0600`. `up` reuses a live relay only
when its recorded process, source script, port, and Nginx target all match;
otherwise it stops the owned process and refreshes it. Persistent volumes are
`mothership-private-postgres-data`, `mothership-private-redis-data`, and
`mothership-private-elasticsearch-data`. All owned resources carry
`org.mothership.runtime=studio-private-v1`; containers also carry a configuration
digest so `up` can restart unchanged containers or replace only owned stale
containers while preserving data.
Do not delete or recreate a named volume out of band while any container still
references it. Use `down` for container cleanup; volume lifecycle is deliberately
outside this launcher.

Application storage is a host bind at `$STATE/storage`, shared by FPM and every
worker and read-only in Nginx. Looper keeps uploads at
`$STATE/looper/uploads`. Mutable storage, uploads, and provider-session contents
do not participate in container configuration digests, so normal data writes
do not cause replacement on the next `up`. Nginx reads the checkout's `public/`
tree read-only and maps `/storage/` to the state tree. FPM runs only the code baked into the image; the
launcher never bind-mounts the checkout over `/var/www/html`.
Generated FPM and Nginx configuration is mounted as read-only directories to
match Apple Container's virtiofs bind-mount contract. A small FPM wrapper copies
the two generated overrides into the container filesystem, invokes the baked
setup functions for migrations and seeding, then starts PHP-FPM. The wrapper
leaves host storage ownership unchanged because Apple virtiofs rejects `chown`
on that bind; the image's `www-data` and Studio owner are both UID 502/GID 20.
It still repairs the image-local `bootstrap/cache` ownership.
The state root and application images use Studio UID 502/GID 20 by default.
When images are deliberately built for another identity, pass matching
`--host-uid` and `--host-gid` values on every launcher command. `up` rejects a
state root owned by a different identity. It keeps the Higgsfield directory at
`0700` and normalizes owned regular session files to `0600`, including files
rewritten by a vendor refresh.
On 2026-09-19, `php-fpm -tt` inside the built image accepted the baked pool plus
`zz-studio.conf`, reporting `pm=ondemand` and `pm.max_children=6`. The repeated
`[www]` section is the supported override mechanism used by the image's own
configuration and does not require a pool-uniqueness test.

Set `STUDIO_LOG=1` to append redacted JSONL lifecycle events to
`$STATE/events.jsonl`. Logs never include `app.env` contents or full container
inspection payloads.

The general, panorama, vectorization, ai-index, and scheduler roles use the queue image.
That image contains PHP CLI, Python/NumPy, FFmpeg/FFprobe, Poppler, ExifTool,
libvips, and `dcraw_emu`. StylePress uses the FPM image because it additionally contains the pinned ARM64 Higgsfield CLI. Node is a build-time
frontend dependency only; the baked Laravel images already contain the built
widget assets.

Feature-role caps on the 64 GB Studio are: Looper `2 CPU/4G`, general queue
`2 CPU/2G`, panorama/video-preview `4 CPU/6G`,
StylePress `1 CPU/1G`, vectorization `1 CPU/1G`, ai-index `1 CPU/1G`, and scheduler `1 CPU/1G`.
The scheduler needs headroom for the simultaneous hourly background PHP commands.
The previous 512M cap exhausted its cgroup at the 03:00 fan-out on 2026-10-22,
stopping subsequent ticks and analytics exports. After a scheduler deployment,
verify autonomous minute ticks and exports, then record cgroup memory.peak and
memory.events through the next hourly fan-out and the next 03:00–03:30 Studio local time
daily window (trash purge, prunes, full ai-index reconcile). Record the final peak
after 04:00 local once those commands finish. The 1G cap is a remediation
candidate, not proof of adequate peak headroom. A successful restart alone does
not establish recovery; sustained pressure or new OOM events require investigation.
The panorama lane is deliberately largest because it runs long Python/NumPy and
FFmpeg conversions.

Every queue process and `schedule:work` runs under a small PID 1 supervisor.
The existing Laravel recycle limits remain in force: general queue after 1,000
jobs or one hour, vectorization after 50 jobs, ai-index after 200 jobs
(`--timeout=300 --tries=4`), and StylePress
after one hour. Clean exit `0` and Laravel's memory-limit recycle
exit `12` always restart after a two-second pause. Any other exit, a PHP
fatal's `255` included, is a failure: the worker restarts after a five-second
pause and logs `worker failed with status N (k/5)`. Five failures in a row
stop the container (`worker stopped with status N after 5 consecutive
failures; leaving container stopped`); a clean recycle, or a run of at least
60 seconds, starts the count again. So one job that kills the worker costs its
own attempts, not every queue behind it (2026-11-10: a 250 MB PSD ran
`ComputeAssetPhash` out of memory and the first fatal left the default queue
stopped for about 45 minutes). The supervisor publishes the current child PID inside the
container, and readiness checks validate that live child command rather than
the supervisor's PID 1 arguments. Panorama/video-preview has no artificial
recycle limit because one job can legitimately run for hours.

Relay mode exists because Apple Container's native web publication
intermittently truncated authenticated generated-file downloads while Nginx
logged the full body and the same URL fetched directly from the guest IP with
the complete length and hash. It is not the default because SSH and launchd
contexts cannot use Homebrew Python's Local Network grant on this Studio.
The relay does no HTTP parsing: it streams both directions with kernel
backpressure and preserves TCP half-closes. Its listener is fixed to IPv4
loopback and its target is restricted to a private, non-loopback IPv4 address.
It caps concurrent TCP sessions at 64. Capacity refusal or thread-start failure
is written to `web-relay.log` and, when `STUDIO_LOG=1`, to `events.jsonl`.
Malformed records and records whose PID has been reused are discarded without
signaling that unrelated process; `status` reports the discard. If an old relay
still owns the port after its record was lost, stop that known process before
retrying rather than signaling a PID inferred from the stale file.

## Image builds

FPM runs only the code baked into its image, so a deploy that changes
application code rebuilds the FPM and queue images from the deployed checkout.
Both are tagged with the first 12 characters of that commit. The Looper image
changes rarely and keeps its own tag, `bf4b288`.

```bash
SOURCE="$HOME/Studio Workspaces/Mothership/source"
COMMIT="$(git -C "$SOURCE" rev-parse HEAD)"
TAG="$(printf '%s' "$COMMIT" | cut -c1-12)"

for role in fpm q; do
  /opt/homebrew/bin/container build --arch arm64 --cpus 4 --memory 8G \
    --build-arg HOST_UID=502 --build-arg HOST_GID=20 \
    --build-arg MOTHERSHIP_BUILD_COMMIT="$COMMIT" \
    --file "$SOURCE/dockerfiles/$role.Dockerfile" \
    --tag "mothership-studio-$role:$TAG" "$SOURCE"
done

/opt/homebrew/bin/container builder stop
```

Then point the launcher's `--fpm-image` and `--queue-image` lines at the new
tag and run `up`. The image carries the full commit as `MOTHERSHIP_BUILD_COMMIT`;
the sign-in page and every admin page publish it read-only as
`<meta name="mothership-build-commit">`. `verify` reads that tag from
`/admin/login` and fails when it does not start with the `--fpm-image` tag, so a
deploy check names the commit that is actually serving. An image built without
the argument reports `unknown`, which `verify` logs as a warning and accepts, so
rolling back to an older image still verifies. Rebuild the Looper image from
`tools/studio/looper.Dockerfile` only when that Dockerfile or the Looper backend
changes, and give it a new tag.

### Pruning old image tags

Each deploy adds about 1.3 GB of FPM and queue images, and nothing else removes
them. After `up` and `verify` succeed, run `prune-images` with the same
arguments. It is a dry run unless you pass `--apply`:

```bash
python3 "$SOURCE/tools/studio/runtime.py" --source "$SOURCE" --state "$STATE" "${IMAGES[@]}" prune-images
python3 "$SOURCE/tools/studio/runtime.py" --source "$SOURCE" --state "$STATE" "${IMAGES[@]}" prune-images --apply
```

`prune-images` runs `verify` first and stops if it fails, if the maintenance
hold is on, or if the running `fpm` or `queue` container uses a different tag
from `--fpm-image` or `--queue-image`. It then prints one `keep` or
`(would) delete` line per tag. It considers only `mothership-fpm` and
`mothership-q` tags and keeps:

- the live tag;
- the newest older tag, for rollback;
- any tag built after the live one, such as a build that has not been deployed;
- any tag a container references by name or digest, whether it is running or
  stopped.

It never selects the Looper image (`mothership-looper:bf4b288`), the
infrastructure images, `craft-*` images, or the BuildKit cache. It deletes tags
with `container image delete` and refuses to run against `--instance stage`.

Keep the private stack down for the complete build window. Do not start a build
while any `mothership-private-*` container is running, and do not start `up`
until the builder has stopped. If issue #2051 symptoms occur, use `down`, stop
the builder, and let the operator restart the Apple Container service before
the next `up`; the launcher does not mutate the host service.

Run the focused unit tests with:

```bash
cd "$HOME/Studio Workspaces/Mothership/source"
python3 -m unittest tools.studio.tests.test_runtime -v
```
