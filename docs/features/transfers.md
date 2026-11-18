# The Mothership Transfers

Transfers sends files and messages between signed-in staff browsers: directly where the
two browsers can reach each other, and through the Transfers service where they cannot.
Home's Studio & people card offers Send only for devices that are connected either way.
The sidebar's Transfers page is at `/admin/dam/hub/transfers`.
Home activity timestamps do not determine whether a device can receive.

The receiving person accepts or declines a file offer, then saves the received file.
The page shows progress and cancellation. A separate-window link lets a person keep
Transfers open while browsing The Mothership in another window. Leaving the owning page
during a transfer warns that it will stop; transfers do not resume after navigation.
Received files occupy browser memory and must be saved before leaving.

## Architecture

`tools/transfers` maintains the pinned PairDrop transport with its original license
and source attribution. A separate HTTPS origin serves the child interface and Node
WebSocket signaling service. The Mothership owns staff authentication and the host interface.
Files never pass through Laravel, Library storage or an upload API, and nothing is stored.

A direct WebRTC route is tried first. Two devices on different networks often have none:
on 2026-11-11 two devices on different subnets of one site could not connect, because browsers
hide private addresses and that site's NAT defeats STUN. After six seconds without a
direct route the pair moves to a relay through the Transfers service, approved by the
operator that day. The service forwards the bytes in memory between the two signed-in
devices, which must share a room and must both have asked for the relay; it writes no
file content to disk or to its log. The device row then reads "Ready · through The
Mothership". Relayed transfers are protected by HTTPS to and from the service, are not
end-to-end encrypted, and are limited by the slower of the two connections. The
[service README](../../tools/transfers/README.md#relay) has the frame format and limits;
`TRANSFERS_RELAY=0` in the service environment turns the relay off.

The parent POSTs to `admin.dam.transfers.grant` using its current session and CSRF token.
Laravel signs a single-use grant for the active human account. It expires after 90
seconds and renews every 30 seconds; account disablement prevents renewal. The child
and parent validate both message origin and source. The service validates signature,
audience, expiry, replay and stable account/session identity before discovery or signaling.
Every signaling relay requires current shared-room membership. Consumed grant identifiers
are hashed in a private replay ledger that must survive service upgrades.

All authenticated devices initially share the staff room. Six-digit temporary rooms
allow a subset of staff devices to connect. Codes expire after five minutes and are
not login credentials. Anonymous devices cannot discover staff. Persistent saved-device
pairing, QR invitations, ZIP packaging, public guest rooms and Library import/export
remain follow-up work; this first release provides authenticated live browser transfers.

## Configuration and rollout

Feature configuration lives under `dam.transfers`:

| Environment variable | Purpose |
| --- | --- |
| `DAM_TRANSFERS_ENABLED` | Enables the page, Home integration and sidebar row; defaults off. |
| `DAM_TRANSFERS_ORIGIN` | Dedicated child HTTPS origin, distinct from The Mothership. |
| `DAM_TRANSFERS_SHARED_KEY` | Server-only raw key matching the signaling service; at least 32 bytes. |
| `DAM_TRANSFERS_LOGGING` | Optional counts/ID-only JSONL grant diagnostics; defaults off. |

The public origin must proxy WebSocket upgrades to the single service process.
See [service runtime and verification](../../tools/transfers/README.md) for its
environment, supervisor, persistent state and transport tests. Cross-network reachability
comes from the relay, which needs no account or extra configuration. STUN/TURN in
`TRANSFERS_RTC_CONFIG` only widens the set of pairs that connect directly; TURN
credentials sent to browsers must be designed for client exposure and limited lifetime.

Enable the flag only after the service is healthy and an actual two-session browser
transfer succeeds. Studio `app.env` changes require stack recreation because container
environment and cached Laravel configuration are established at boot. Roll back the
flag with the prior app environment and restart through the existing Studio deployment
workflow; preserve the previous service release and replay ledger.

## Verification contract

`TransfersTest.php` covers session-derived identity, signature/lifetime, renewed unique
grants, disabled/API accounts, missing sessions and invalid configuration. The host
Node tests cover pinned messaging, grant failure, remount and navigation ownership.
Service tests cover replay across restart, expired/changed identity, WebSocket origin,
unauthenticated discovery and room membership. The browser proof uses two actual
WebRTC contexts, offers/accepts/saves a file and compares SHA-256, including empty
files, Unicode names, cancellation, decline, messages and rooms. Relay tests cover the
delivery rule (both asked, same room, sender stamped by the service), malformed and
oversize frames, pacing and the log; a second browser proof gives two browsers no
WebRTC route and compares SHA-256 for files sent through the relay. Integrated acceptance
must additionally obtain grants from two real Laravel sessions.

Local proofs, self-hosted CI and deployed acceptance are distinct. Never infer
cross-network success or a production release from the local transport proof.
