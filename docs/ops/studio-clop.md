# The Mothership native Clop optimize service

The production Mac runs [Clop](https://sindresorhus.com/clop) natively so the Optimize
action can shrink images, video, and PDFs on real macOS hardware. The Mothership's Linux
containers reach it through the private Apple-container bridge; it has no
public listener, Cloudflare route, or DNS record.

## Installed runtime

The Clop service needs no Python environment or model weights: it is a single stdlib-only script
(`tools/studio/clop-service/server.py`) run directly with the Studio's system
`python3` (3.9-compatible). All mutable state is on the Studio internal disk under:

`~/Studio Data/Mothership Native Services/clop`

- Deployed service source: `runtime/clop-service/server.py`
- Process log: `logs/clop-service.log`
- Service JSONL events (gated by `CLOP_SERVICE_LOG=1`): `logs/clop-service-events.jsonl`
- Manager JSONL events (gated by `CLOP_SERVICE_LOG=1`): `manager-events.jsonl`
- PID/config receipt: `run/clop-service.json`

Nothing here is copied into Git except the versioned `server.py` and the manager,
`tools/studio/clop_service.py`.

## Host <-> app contract

The contract below is fixed; the PHP client in `packages/Mothership/DAM` is built to
match it exactly.

- `GET /health` -> `200 {"ok":true,"clop":"<abs path>","version":"<CFBundleShortVersionString or null>"}`,
  or `503 {"ok":false,"error":"clop_missing"}` when the Clop binary is missing or
  not executable.
- `POST /optimize?type=image|video|pdf&<options>` with the raw file bytes as the
  body.
  - Required header `X-Clop-Filename: <basename with extension>`.
  - `Content-Length` is required and capped by `CLOP_MAX_BYTES` (default 2 GiB) ->
    `413`.
  - Options:
    - image: `scale=1|0.75|0.5` (default `1`), `adaptive=0|1` (default `0`).
    - video: `compression=25|50|75` (default `50`), `encoder=hardware|software`
      (default `hardware`), `scale=1|0.75|0.5` (default `1`),
      `remove_audio=0|1` (default `0`).
    - pdf: none.
    - Any other value, or a missing/unrecognized `type`, -> `422 {"error":"invalid_option"}`.
  - Behaviour: the body is written to a fresh `tempfile.mkdtemp()` as `in.<ext>` (a
    disposable copy), then run through
    `clop optimise <type> ... -n -j -o <tmp>/out <tmp>/in.<ext>` under a hard
    timeout (`CLOP_TIMEOUT`, default 900s; the whole process group is killed on
    expiry). Only one Clop process runs at a time (serialized with a lock). The
    temp directory is always removed, on every exit path.
  - Success -> `200` with the output bytes as the body, `Content-Type` guessed
    from the actual output extension (Clop's adaptive image compression can turn a
    PNG into a JPEG), and headers `X-Clop-Old-Bytes`, `X-Clop-New-Bytes`,
    `X-Clop-Extension` (lowercase, no dot), plus `X-Clop-Width` / `X-Clop-Height`
    when Clop reported them. The host never trusts Clop's self-reported
    `oldBytes`/`newBytes` for this decision or for these headers: `X-Clop-Old-Bytes`
    is always the received request body length, and both the "not smaller" test
    below and `X-Clop-New-Bytes` are always the byte count actually read back off
    the output file on disk.
  - Output extension allowlist (host-side first line of defence; the app enforces
    its own per-family extension check and MIME sniff on top of this): the output
    file's extension must be one of `jpg`/`jpeg`/`png` for `image`, `mp4`/`mov` for
    `video`, or `pdf` for `pdf`. Anything else -> `422 {"error":"optimize_failed"}`,
    even if the file is genuinely smaller.
  - Not smaller (actual on-disk bytes >= the received body length, or `done` empty
    with `failed` empty) -> `200 {"outcome":"no_reduction","old_bytes":N}` with
    header `X-Clop-Outcome: no_reduction`.
  - Failure: Clop's `failed` is non-empty, its stdout isn't JSON, the reported
    output file is missing, the output file is empty, or its extension fails the
    allowlist above -> `422 {"error":"optimize_failed"}`; a timeout ->
    `504 {"error":"timeout"}` and kills the whole Clop process group, including any
    descendants it spawned, not just the direct child. No response ever echoes a
    filesystem path or a raw Clop error string; that detail only goes to the JSONL
    event log when `CLOP_SERVICE_LOG=1`.

## Private connection

Point The Mothership at the Clop port on the private gateway:

`MOTHERSHIP_OPTIMIZER_API=http://192.168.164.1:18766`

`192.168.164.1` is the `ipv4Gateway` of `mothership-private` (pinned to
`192.168.164.0/24` by `runtime.py`). On a legacy network the gateway can differ;
use the `ipv4Gateway` that `container network inspect mothership-private` reports.

The manager (`clop_service.py`) rejects unspecified and public bind addresses.
If the `mothership-private` network is ever
recreated with a different subnet, rediscover the gateway with
`container network inspect mothership-private` and restart the service and the
application environment together.

## Lifecycle

**The bridge is started by `runtime.py up`.** When `MOTHERSHIP_OPTIMIZER_API` is set
in `app.env`, `runtime.py up` runs this manager's `up` on that URL's host and port
(state under `Mothership Native Services/clop`, a sibling of the private state root,
overridable with `--native-state`), idempotently, before it verifies. The login
autostart and every deploy call `runtime.py up` through
`~/Desktop/Mothership-Private.command`, so a reboot or deploy restarts the bridge.
`runtime.py verify` health-checks it from FPM and fails naming `optimizer` if it
is down. The commands below are for manual recovery and one-off checks.

Run the manager from the current The Mothership source tree after the private container
network exists:

```bash
/opt/homebrew/bin/python3 "$HOME/Studio Workspaces/Mothership/source/tools/studio/clop_service.py" up \
  --source "$HOME/Studio Workspaces/Mothership/source" \
  --state "$HOME/Studio Data/Mothership Native Services/clop" \
  --host 192.168.164.1 --port 18766
```

`up` deploys only `server.py` (digest-checked, so it's idempotent when the process
and source digest already match), requires the Clop CLI to exist at `--clop-bin`
(default `~/.local/bin/clop`), launches it in a detached session,
and waits for HTTP health. `down` terminates only the process recorded in this
service's PID receipt. `status` reports the recorded Clop path and app version.

Verify the service is reachable without sending it a real file:

```bash
/opt/homebrew/bin/python3 "$HOME/Studio Workspaces/Mothership/source/tools/studio/clop_service.py" verify \
  --source "$HOME/Studio Workspaces/Mothership/source" \
  --state "$HOME/Studio Data/Mothership Native Services/clop" \
  --host 192.168.164.1 --port 18766 --timeout 60
```

Then post a disposable fixture directly to `/optimize` (for example a small PNG
with `type=image&scale=1&adaptive=0` and `X-Clop-Filename: fixture.png`) and
confirm the response is either the optimized bytes or a `no_reduction` outcome.
This never touches the Mothership library.

## Environment keys

- `CLOP_BIN` - absolute path to the Clop CLI. Default
  `~/.local/bin/clop`.
- `CLOP_HOST` / `CLOP_PORT` - bind address for the sidecar itself; the manager sets
  these from `--host`/`--port` when it starts the process.
- `CLOP_MAX_BYTES` - request body cap in bytes. Default 2 GiB.
- `CLOP_TIMEOUT` - hard per-request Clop timeout in seconds. Default 900.
- `CLOP_APP_PATH` - path to `Clop.app`, used only to read
  `CFBundleShortVersionString` for `/health`. Default `/Applications/Clop.app`.
- `CLOP_SERVICE_LOG` - set to `1` to enable JSONL event logging (both the server
  process and the manager). Off by default.
- `CLOP_SERVICE_LOG_PATH` - where the server writes its JSONL events; the manager
  sets this to `logs/clop-service-events.jsonl` under its state directory.

## Local Network note

This sidecar has no public route. If Studio prompts for a
"Local Network" permission the first time the manager or a container reaches it,
approve it for the Terminal/python process that owns the bind; declining breaks
the private bridge path entirely, not just this feature.

## Recovery

If the service fails, inspect `logs/clop-service.log`, then run `down` followed by
`up`. A missing Clop binary is a provisioning failure (`up` refuses to start
without it); `GET /health` reports `503` if the binary later disappears or loses
its executable bit while the process is already running.
