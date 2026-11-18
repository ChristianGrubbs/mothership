# The Mothership Transfers

Maintained Mothership adaptation of PairDrop 1.11.2. Files travel through the actual
PairDrop WebRTC `Peer`, `RTCPeer`, `FileChunker` and `FileDigester` implementation;
this is not an independent replacement transport. When two browsers have no direct
route, the same `Peer` protocol runs through this service instead (see [Relay](#relay)).
See [UPSTREAM.md](UPSTREAM.md).

## Runtime

Node 20+; install the locked dependencies with `npm ci` in this directory. Run
`npm start` in a process supervisor. The service binds loopback by default. A reverse
proxy supplies HTTPS/WebSocket upgrades at the separate transfer origin. Configure:

| Variable | Required value |
| --- | --- |
| `TRANSFERS_SHARED_KEY` | Raw UTF-8 key, at least 32 bytes; identical to Laravel's `DAM_TRANSFERS_SHARED_KEY`. Inject from Keychain/environment. |
| `TRANSFERS_ORIGIN` | Exact HTTPS child origin, no slash or path. |
| `TRANSFERS_PARENT_ORIGINS` | Comma-separated exact The Mothership origins (bare `https://host`, no path or slash). All are CSP frame ancestors; postMessage is pinned to the one actually framing the client. |
| `TRANSFERS_PARENT_ORIGIN` | Older single-origin form, used only when `TRANSFERS_PARENT_ORIGINS` is unset or empty. |
| `TRANSFERS_PORT` | Local service port, default 3001. |
| `TRANSFERS_HOST` | Bind address, default 127.0.0.1. |
| `TRANSFERS_STATE_DIR` | Absolute persistent directory for the replay ledger and logs; defaults to package-local `storage` for development. Production must use a path outside releases. |
| `TRANSFERS_RTC_CONFIG` | Optional JSON RTCConfiguration, default `{"iceServers":[]}`. Supply deployment STUN/TURN configuration for cross-network peers. This configuration is browser-visible; use short-lived TURN credentials. |
| `TRANSFERS_RELAY` | The relay is on by default. `0` turns it off: devices without a direct route then show "Could not connect". |
| `TRANSFERS_RELAY_AFTER_MS` | How long a direct route is tried before the relay is used; default 6000, allowed 100 to 15000. |
| `TRANSFERS_LOGGING` | `1` enables JSONL at `storage/logs/transfers.jsonl`; off otherwise. Authenticated connection diagnostics include bounded states, candidate counts, numeric error codes, browser/platform and transient socket IDs, never candidate addresses, URLs, SDP or file contents. Both client and server limit each socket to 30 failure and 15 informational diagnostics per minute, reserving failure capacity. The service rotates at 5 MiB and keeps one previous file (`.1`). |

`/health` reports process health. `/` is the embedded client; `/ws` is the authenticated
signaling channel and, when a direct route fails, the relay. No public-IP discovery,
guest sockets, or shared upstream PairDrop server is used. All authenticated users start
in the staff room.
Creating/joining a six-digit room allows staff to pair a subset of devices; codes
expire in five minutes and joining allows five attempts per minute per connection.
Leaving returns to the full staff roster. A code is never an authentication credential.
Room changes are unavailable during active transfers. Persistent device pairing,
public guest rooms, ZIP downloads and upstream PWA install are not enabled in this
initial Mothership runtime. The original pairing source is preserved for future adaptation.

For managed macOS supervision, use the dry-run-first [lifecycle helper](deploy/README.md).
It accepts an existing Node 22-or-newer executable and a private environment file,
generates the dedicated launchd job, and supports rollback to a retained release.

## Relay

Two browsers on different networks often have no WebRTC route: browsers hide private
addresses behind mDNS names that resolve only on one network segment, and a symmetric
NAT (the first site's network is one) defeats STUN. The operator approved a relay through this
service on 2026-11-11; the contract is in [UPSTREAM.md](UPSTREAM.md).

- A direct route is tried first for `TRANSFERS_RELAY_AFTER_MS`. If it does not open, or
  an open one later fails, the browser sends `{type:'relay',to,state:'open'}` and the
  service forwards `{type:'relay',sender,state:'open'}` to that peer, which answers the
  same way. The service keeps the record of who asked, so a browser that asks after the
  other side is answered at once. The device row reads "Ready · through The Mothership" (`data-transport="relay"`)
  once both have asked. A pair stays on the relay until one of the two sockets ends.
- A relayed message is one binary WebSocket frame: 36 ASCII bytes naming the other peer,
  one kind byte (0 = protocol text, 1 = file chunk), then the payload (at most 196,608
  bytes). The service replaces the first 36 bytes with the sending socket's id before
  delivery, so a browser cannot name a different sender.
- The service delivers a frame only when both sockets are authenticated with live
  grants, share a room, and have both asked for the relay with each other. Anything
  else is dropped. Changing rooms or disconnecting ends the relay for both sides.
- Bytes are forwarded in memory and never written to disk or to the log. A sender is
  paused above 2,000 frames a second or while the recipient has more than 4 MiB
  waiting, and its session ends if the recipient's backlog passes 8 MiB. PairDrop's own
  1 MB partition acknowledgment keeps an honest transfer far below both.
- With `TRANSFERS_LOGGING=1` the log records `relay-opened` (two socket ids) and
  `relay-closed` (socket id, bytes and frames forwarded for it).
- If the other browser never answers (for example an old page still open), the row
  shows "Could not connect · Retry" after 10 seconds.
- A relayed transfer is as fast as the slower of the two connections to the service and
  is not end-to-end encrypted; HTTPS protects it to and from the service.

## Parent bridge v1

The child sends `{channel:'mothership-transfers',version:1,type:'ready'}`. Its parent obtains
a grant from the current Laravel session and sends `initialize` with `grant` and
`selectedUserId` (string or null). Send `authenticate` with a fresh grant every 30
seconds. `select-user` changes highlighting; `disconnect` closes sockets, cancels
transfers and discards received blobs. A new `initialize` reconnects after signout.
Prefer `selectedPeerId` on `initialize` and `select-peer` with `selectedPeerId` to
target an exact device. User selection remains supported for older hosts. Device
labels are allowlisted platform/browser hints plus a short browser ID, never
raw user-agent text; the signed name remains the identity authority.
Tabs share a random browser ID in local storage, initialized under a Web Lock where
available. The server hides other tabs belonging to the same signed account and
browser. The UI and host ready-peer list group remote tabs by signed account plus
browser ID, preferring an active transfer or an open channel. Closing a tab leaves
the remaining connected tab usable. Separate devices/browser profiles remain
distinct even for the same account. Transport IDs stay per connection so each tab
can send and receive; browser IDs grant no access and are pinned on authentication.
If browser storage is denied, connections remain usable with per-tab identities.
Send `theme` with `theme:'light'` or `theme:'dark'` after initialization and whenever
the host theme changes. This pins the child appearance over OS preferences; invalid
theme values and messages from other origins/windows are ignored.
The child picks its parent from that allowlist by exact match only: `location.ancestorOrigins[0]`
where the browser provides it (Chromium, Safari), else the `hub_parent` query parameter the
parent adds to the frame URL (Firefox), else the sole entry of a one-origin list. The claim is
removed from the URL at startup and only selects among allowed origins; posts still name it as
targetOrigin, so a false claim delivers and accepts nothing. With several origins and neither
signal (Firefox under an older host page), only the data-free `ready` is posted to each allowed
origin, and the first message from `window.parent` with an allowed origin fixes the parent.
The child validates both parent origin and source, and emits:

- `state`: `status:'ready'|'connecting'|'disconnected'`, `readyPeers:[{userId,peerId,name,deviceName}]`, `activeCount`.
- `reconnect`: the user requests reconnection; obtain a fresh grant and send `initialize`.
- `incoming-offer`: open the host panel to show the file acceptance or message.
- `error`: a bounded code (`connection_lost`, `connection_unavailable`, `session_expired`).

Pause host renewal when the child reports `disconnected`. Its Reconnect action
requests fresh initialization rather than reusing a grant. Text-message panel reveals
are bounded to one per five seconds; later messages still appear in the activity
list. File offers always reveal their acceptance controls. The child displays its
authenticated own-device label so staff can identify it in another device's roster.
Pre-authentication connections have a separate 128-global/eight-per-address
cap and a two-second deadline, independent of the 1000 authenticated-staff slots.
Pre-authentication rejection and timeout terminate the transport immediately;
authenticated capacity is rechecked when a pending socket first authenticates.
For the local Cloudflare connector, the per-address cap uses `CF-Connecting-IP`
only when the socket originates from `127.0.0.1`, `::1`, or mapped loopback
`::ffff:127.0.0.1` and the header is a single valid IPv4/IPv6 address. Invalid or
missing headers fall back to the socket address. Headers from direct external
clients are ignored. This boundary trusts the local connector to overwrite the
header; keep the production listener on loopback and do not expose another local
proxy that passes arbitrary client-supplied CF headers unchanged.

Only peers with an open WebRTC data channel or an open relay appear in `readyPeers`. Names are signed
server identities; peer-controlled display-name messages are ignored. Grants never
appear in URLs, storage or logging. Grant consumption is single-use; only the SHA-256
of consumed jti values and expiry timestamps persist in `TRANSFERS_STATE_DIR/grant-replay.json`
(development default `storage/grant-replay.json`).
Preserve this private runtime directory across service restarts. A corrupt/unwritable
ledger fails closed. The ledger has a hard 100,000-live-entry limit and prunes expired entries;
run one signaling process per configured origin (no multi-worker/load-balanced mode).
If the process restarts, all WebSockets disappear and clients need a fresh grant.

The signed grant is unpadded base64url(JSON) + `.` + unpadded
base64url(HMAC-SHA256(base64urlJSON, rawKey)). Claims are `v:1`,
`aud:'mothership-transfers'`, nonempty string `sub`, `name`, `sid`, `jti`, integer `iat`
and `exp`, maximum 90-second lifetime, 5-second future-issue skew. Renewal cannot
change `sub` or `sid`. Expired or replayed grants close the connection. Every relay
checks live room membership. No files enter the Mothership Library or its asset storage.

## Verification

CI-compatible `npm ci --ignore-scripts && npm test` covers grant, WebSocket and relay
trust boundaries (`tests/relay.test.js`) without Chrome or external services. `npm run test:browser`
uses an ephemeral in-memory signing key and a fixture parent on a separate origin,
launching two independent Chrome contexts through `~/ai-stack/tools/chrome-launch.mjs`.
The optional `TRANSFERS_CHROME_LAUNCHER` absolute path selects an equivalent approved
launcher for another managed host; it must export `launchChrome`. Chrome is never
downloaded by this package. `TRANSFERS_SCREENSHOTS` selects an existing review-output
location for desktop/mobile/dark evidence. Both tests fail on unavailable prerequisites.

The browser test uses the production child interface, real WebSocket signaling,
real WebRTC and a saved browser download with exact SHA-256 comparison, plus empty
files, Unicode names, decline/cancel, messages, authenticated rooms, parent-source
validation, cleanup and reconnect. `tests/relay.browser.test.js` gives the two browsers
no WebRTC route at all and proves files (SHA-256 compared), text and decline through the
relay with no data channel ever open. It proves local transport, not Laravel session
authorization, TURN reachability, production deployment or CI. Parent integration
and deployment acceptance remain separate gates.

Received files occupy browser memory until saved/disconnected; transfers do not resume
after navigation. The host should warn on active transfers and expose its separate-window
entry before file selection. Cancel clears both devices' pending and active transfer state.

Individual ICE gathering errors and rejected remote candidates retain only a bounded
source/code diagnostic and do not interrupt an otherwise healthy connection. SDP,
failed ICE, and closed data channels still interrupt active transfers. A transient
`disconnected` state gets a five-second grace period; reconnecting within it preserves
transfer state. A connection attempt without an open data channel expires after 15
seconds, including an unanswered offer during asymmetric peer recreation. With the
relay on, the attempt instead ends after `TRANSFERS_RELAY_AFTER_MS` and the pair moves
to the relay; the rest of this paragraph describes a service with the relay off. After a
terminal failure, the device row shows disconnected and is
recreated on the next authenticated roster refresh (normally within the host's
30-second grant renewal). Failed transfers are discarded, never silently resumed.
The browser regression proves recovery through the scheduled authentication renewal
and its service-broadcast roster. Transient disconnect grace is covered by fake-timer
tests; a physical-network disconnected-to-connected transition remains unverified.


## Identity and clipboard

The authenticated response includes `name` from the verified grant, alongside the
existing device label. The child displays “Signed in as” and snapshots the other
person's name/device for activity so completion, cancellation and interruption
remain identifiable after roster removal. Names are never accepted from peers.

The send dialog offers Files and Text or clipboard. Paste clipboard and Copy text
access the native clipboard only on a button click. The host iframe must allow
`clipboard-read; clipboard-write`; browser denial/unavailability falls back to
manual paste or selection/copy. Text uses `textContent`, preserves Unicode and
newlines, and is limited to 10,000 UTF-16 code units without silent truncation.
Clipboard contents and messages are never logged. Browser verification exercises
native Chromium paste/copy and injected permission-denied fallback separately.
