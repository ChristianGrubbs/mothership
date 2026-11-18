# Managed macOS lifecycle

`lifecycle.js` plans and installs only the dedicated user LaunchAgent
`app.themothership.transfers`. It never edits The Mothership's environment, Cloudflare,
DNS, dependencies, or another launchd job. No production action occurs without
`--apply`. Run it as the owning logged-in account; GUI-domain supervision requires
that account's login session. This helper does not provision a system daemon.

## Inputs and preparation

The CLI accepts the following named arguments. Every filesystem argument must be
absolute. The deployment controller supplies verified host paths; these are argument
descriptions, not a ready-to-run production command.

| Argument | Meaning |
| --- | --- |
| `--release` | Immutable release's `tools/transfers` directory, containing `server/index.js` and installed production dependencies. Symlinks resolve to a fixed release path. |
| `--node` | Existing executable, probed with `--version`; Node 22 or newer. No installation is attempted. |
| `--env-file` | Existing environment file outside the release, owned by the current account with mode `0600`. Populate from Keychain through the deployment controller, never CLI arguments or logs. |
| `--state-dir` | Persistent writable directory outside every immutable release. Reuse this exact directory for upgrade and rollback. |
| `--plist` | Persistent destination ending in `app.themothership.transfers.plist`, ordinarily in the owning account's `Library/LaunchAgents`. |
| `--domain` | Optional `gui/<numeric-uid>`; defaults to the invoking account. |
| `--apply` | Explicitly install/restart; omitted means read-only plan. |

The environment file uses Node's built-in `--env-file` format and the runtime variables
in the service README. It must set the separate child/parent origins, shared signing
key and loopback port. No secrets are copied into the plist. The plist pins
`TRANSFERS_STATE_DIR`; Node preserves that inherited setting over the environment file.
Never put secrets into `NODE_OPTIONS`: only the documented service settings belong in
this file. The controller owns environmental and network configuration.

## Plan, install and verify

1. Invoke the helper with the path arguments and no `--apply`. Its JSON plan reports
   paths, label and domain only. It does not create directories, write a plist, read
   environment values, or call launchctl. It executes the explicitly supplied Node
   binary solely to verify its version.
2. Review the resolved release, state and environment paths. Preserve the existing
   replay ledger when upgrading an already-running service. Add `--apply` to the same
   invocation to install. The helper validates the generated plist using `plutil`,
   stops only the dedicated existing job, atomically replaces its plist, and bootstraps
   it with `RunAtLoad`, `KeepAlive` and a ten-second crash throttle.
3. Verify the dedicated launchd job, loopback `/health`, separate-origin `/health`, and
   authenticated browser transfer through the application's real session endpoint.
   Bootstrap success means the job was registered; it does not prove HTTP readiness
   or application authorization. The deployment controller performs these checks.

If bootstrap fails, the helper restores the previous plist and, when the previous job
was loaded, attempts to bootstrap it. A rollback failure is reported distinctly.
An existing job without an on-disk rollback plist is rejected before stopping it.

## Relay switch

The relay ([service README](../README.md#relay)) is on in every release that contains
it; no environment change is needed to deploy it. To turn it off, add `TRANSFERS_RELAY=0`
to the private environment file and restart only this LaunchAgent; `/config` then
answers `"relay":null`. Removing the line and restarting turns it back on. The relay
keeps no state on disk, so neither change touches the replay ledger.

## Persistent state and rollback

`TRANSFERS_STATE_DIR` holds `grant-replay.json`, `logs/transfers.jsonl`, launchd
`logs/stdout.log` and `logs/stderr.log`. Keep the state directory private, preserve it
across releases, and run exactly one process against the replay ledger. It contains
hashed grant IDs/expirations, never raw grants or session/user identities. Enabling
`TRANSFERS_LOGGING=1` on the helper adds counts-free lifecycle events at
`logs/lifecycle.jsonl`; no configuration values are logged.

For a coordinated connection investigation, the runtime owner can set
`TRANSFERS_LOGGING=1` in the private service environment and restart only this
LaunchAgent after reviewed deployment. Handshake records use typed states/counts
and transient socket IDs to correlate the two endpoints; no account identity,
candidate addresses, SDP, grants or file content is retained. Client and server
reserve separate budgets of 30 failure and 15 informational records/socket/minute.
The service keeps the current log and one previous 5 MiB file, rotating by size.
Disable collection by removing the setting (or setting `0`) and restarting the
same service; verify the authenticated response has `diagnosticsEnabled: false`.
Retain only the bounded diagnostic evidence needed for the investigation. A code
rollback uses the previous release below and the same state/replay ledger.

To roll back, rerun the same helper first without `--apply`, changing only `--release`
to the retained previous release's `tools/transfers` path. Keep `--state-dir`,
`--env-file`, `--node`, `--domain` and `--plist` unchanged. Review the plan, apply it,
then repeat readiness and authenticated transfer verification. Keep the previous
release until the replacement passes those checks. Do not delete or reset the replay
ledger during rollback; existing grant replay protections must survive.

## Local checks

`npm test` includes CLI parsing, explicit apply gating, read-only plan generation,
XML escaping, private environment permissions, Node-version validation, persistent
path validation and rollback planning. Tests use a disposable local fixture and
never invoke launchctl. Actual launchd installation and production health checks
remain deployment evidence, not unit-test claims.
