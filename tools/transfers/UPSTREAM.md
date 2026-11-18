# PairDrop provenance and maintenance

- Project: https://github.com/schlagmichdoch/PairDrop
- Pinned commit: `1b0c9c9d903c3cfc706df1303dc75c3b54d04c77`
- Upstream package version: 1.11.2
- Imported: 2026-10-21 from the locally inspected pinned checkout.
- `public/vendor/pairdrop-network.js` is an unmodified copy of
  upstream `public/scripts/network.js` (including pairing and room protocol).
- `public/vendor/LICENSE` is the upstream GPL-3.0 license. The inherited package
  metadata says ISC, but the vendored source's actual upstream LICENSE governs it;
  this package corrects its metadata to GPL-3.0-only.
- `package-lock.json` starts from the upstream lock. `playwright-core` is the only
  added dependency, development-only, for the required managed-Chrome proof.

Mothership-specific adaptation lives in `public/transfers.js` and `server/`, keeping the
upstream file unchanged for reviewable updates. The adapter subclasses RTCPeer (and,
for the relay below, upstream's transport-neutral `Peer`),
reuses its file/data-channel protocol, disables peer-authored names, avoids upstream
plaintext console payload logging, fixes zero-byte reception, checks file headers,
and implements cancel/cleanup. Mothership authenticated staff discovery and ephemeral room
pairing replace the upstream anonymous signaling server and persistent pairing UI.

When updating upstream, record the exact new commit, compare Peer/RTCPeer and file
chunking changes against the adapter overrides, preserve its license, and rerun both
security and real-browser tests. Do not enable upstream anonymous connection or payload
fallback paths without a separately approved authentication contract. Upstream's own
`WSPeer` fallback (base64 chunks to anyone in a public room) stays unused.

## Approved relay (2026-11-11)

On 2026-11-11 the operator approved a payload relay through this service ("Relay via
our server"), after two-account tests on the live site showed that two devices at one site on
different subnets have no WebRTC route to each other. It is The Mothership's own, not upstream's,
and it runs under this contract:

- Only two authenticated The Mothership sockets with live grants that currently share a room
  can use it, and only after both browsers asked for the relay with each other.
- The service stamps the sender on every frame from the socket's own identity.
- File bytes pass through the service process in memory. Nothing is written to disk,
  to the log or to Library storage; the log holds socket ids and byte/frame counters.
- A direct WebRTC route is always tried first. `TRANSFERS_RELAY=0` turns the relay off.
- Relayed bytes are protected in transit by HTTPS to and from the service. They are not
  end-to-end encrypted the way a direct WebRTC channel is.

Changing any of these points needs a new approval. The frame format, limits and tests
are in [README.md](README.md#relay).
