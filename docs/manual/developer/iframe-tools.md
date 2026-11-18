# Iframe Home tools

A hosted tool uses kind `tool` in the Home widget registry and a stable widget ID. Its files
live beneath `packages/Mothership/DAM/src/Resources/widgets/{widget}/`; `entry` selects the HTML
file inside that directory. The server confines paths with realpath checks and serves a MIME
allowlist. There is no arbitrary remote widget installation endpoint.

## Working notes example

`examples/widgets/registry.php` defines the inert `docs-notes` entry. Its source is
`packages/Mothership/DAM/src/Resources/widgets/docs-notes/index.html`. Explicitly merge that entry
into development configuration, then allow its ID and `help` permission for the test role.
The notes example reads/writes localStorage, downloads a text Blob, and observes active state.
It does not run a background job; tools that do must pause that work when inactive.

The HTML must contain the literal `<head>` tag (case-insensitive, **without attributes**).
`HomeToolController` injects the bootstrap and `home-frame.js` at that marker. A missing marker
currently returns HTML without injection, so verify the actual response before debugging state.
The injected `window.__MOTHERSHIP_FRAME__` supplies account/widget identity, initial state,
state URL and CSRF context. Do not hardcode an account, credential or admin prefix in the tool.

## Persistent state

The injected bridge replaces localStorage with an account-backed Storage-like object. State is
scoped by admin account and widget ID. It debounces PUT saves for 450 milliseconds. The server
allows at most 100 string keys, at most 65,536 characters per value, and at most 131,072 bytes
for the JSON-encoded state. This is preference storage, not a media database; keep binary assets
outside it. The notes sample caps text at 60,000 characters to stay below the per-value limit.

Same-origin write fetch/XHR requests receive CSRF from the injected bridge. Do not assume
cross-origin requests get that behavior. Failed preference saves send `preference-error` to
the host; they are not successful persistence acknowledgments.

## Messages and lifecycle

Messages use protocol `mothership-widget/v1`. Validate both source window and same origin
before accepting them. The host sends `theme` (light/dark) and `active`. The bridge sends
`ready` after DOMContentLoaded, `dirty` for input/drop, `escape` for Escape, and
`preference-error` after a failed save. The host also treats `media-loaded` as a dirty-session
signal, and `params` from a document without the bridge. Use the existing bridge rather than
duplicating these listeners.

**Close tool** asks to discard work only when work may be lost. The bridge's `ready` carries
`payload.reportsWork: true`: a promise that the document reports work as it starts. While the
host has heard that promise from the frame's current document and no dirty-session signal since,
it closes the tool in one click. A frame without the bridge, or one that has left the tool, is
still asked about, and so is a tool whose accepted Reload has not arrived yet. So a tool must report every way work can start. The bridge covers typing,
choosing in a form control and dropping a file. Work that starts any other way (a button, a
canvas drag, a paste, an image chosen in a picker built from buttons) sends no `input` or `drop`
event, and the tool must post `dirty` itself, as Loop Maker and StylePress do. `params` from a
document with the bridge is not counted: a tool may send one as it finishes loading, before anyone
has touched it. Home sends no message that edits a tool. A host that does must mark the session
itself, because no person acted in the frame. Reload always asks, and Escape always asks.

A tool that holds nothing to export (a read-only catalogue, for example) sends its own `ready`
with `payload.holdsWork: false`. The host then closes it from **Close tool** without the discard
confirmation whatever was done in it, and ignores its dirty signals, so a search inside it never
arms the close or leave-page prompt. Escape still asks. The declaration belongs to the document that sent it: a
reloaded or replaced document must send it again. `holdsWork: true` withdraws it and is itself
a report of work: the host asks about that tool from then on, until the tool sends
`holdsWork: false` again. A tool whose answer changes sends `ready` each time, as the Video
Downloader does while a fetched file waits to be saved on the device. Do not send
`holdsWork: false` from a tool that loads media, keeps unsaved edits, or runs a job whose
result closing would lose.

Collapse hides and retains the same iframe and session; reopen resumes it. Confirmed Close
removes it. Dirty sessions participate in navigation and beforeunload confirmation. A local
Blob download does not produce a generic saved/exported acknowledgement and does not clear
dirty state.

## Saving a file

The bootstrap also injects `device-save.js`. In a browser with a download manager it does
nothing: an `<a download>` link or a Blob anchor click downloads as it always has.

The Mothership installed to an iPhone or iPad Home Screen (`navigator.standalone` on a touch screen)
is one window with no download manager, and following a file link there replaces the whole app
with a file preview that has no way back. In that app the helper takes over:

- a click on `<a download>` whose address is this origin, `blob:` or `data:`;
- a script calling `.click()` on such an anchor that was never added to the page.

It fetches the file (500 MB ceiling), shows a card at the bottom of the frame and opens the share
sheet. When the sheet needs a tap of its own, the card offers **Save to this device**. A tool that
runs its own save flow may call `window.mothershipDeviceSave.save({ url, name })` or
`({ blob, name })` when `window.mothershipDeviceSave.active` is true.

So save with a `download` link or a Blob anchor. Do not send the frame, or `window.open`, to a
file: a file link without the `download` attribute is not taken over. If a frame does land on a
file, the Home shell keeps the window on Home and tells the person to use **Reload** or
**Close tool**; only a frame that lands on a page (`text/html`) is moved to the full window.
`tools/browser/tests/device-save.test.cjs` and `support/installed-app.cjs` show how to test a
tool as the installed app.

## Test and distribute

Exercise the actual sample HTML with the injected bridge: reload persisted account state,
switch accounts, reject another origin/window, edit and observe the delayed PUT, simulate a
failed save, send theme/active, collapse/reopen, and download text. Test traversal, disallowed
MIME, oversized state and denied roles at the HTTP layer. `DocumentationExamplesTest` checks
sample activation and injection; existing Home tests cover the shared state/registry boundaries.

Keep the sample disabled by default and ship local assets with the repository build. Avoid
network/CDN dependencies for a small tool. On an update, preserve its ID and storage keys or
provide an explicit migration. Removing an entry hides the tool; do not silently erase users'
saved state. [Native widgets](native-widgets.md) · [Extension lifecycle](extensions.md).
