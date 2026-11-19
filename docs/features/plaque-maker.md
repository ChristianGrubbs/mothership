# Plaque Maker (Home tool)

Written 2026-11-17. A Home tool (Gadget shape) for animated video overlays: an icon or image, a title, a subtitle,
an optional amount and a synchronized sound, exported as transparent stills, animations, frame sequences and a WAV.
Widget id `plaque`, folder `packages/Mothership/DAM/src/Resources/widgets/plaque/`, registered in
`packages/Mothership/DAM/src/Config/home.php` with `always_available` (it runs in the browser and spends nothing). Labels,
presets, messages and generated content are English.

## Structure

| File | Holds | DOM? |
| --- | --- | --- |
| `plaque-core.js` | Set schema and version, defaults, the importer (`validateSet`), presets, keyword topic matching, the timeline, layout and **the frame function** (`drawFrame`), the sound schedule | No: UMD, loaded as a `<script>` in the page and by `require()` in Node tests |
| `plaque-encode.js` | Encoders that take frames the frame function drew: APNG, GIF89a (binary transparency; frames render at 50 fps at most, the fastest rate GIF delays hold), STORE zip, 48 kHz 16-bit PCM WAV, chroma-key WebM through `MediaRecorder` | Canvas and `MediaRecorder` only |
| `plaque-sounds.js` | Built-in sounds, synthesized into `AudioBuffer`s (no audio files ship) | Web Audio |
| `app.js` | The editor: list, inspector, preview, transport, and the Export, Save to Library, Open, Presets and Generate sheets; undo and redo; autosave; the tool page's bar | Yes |
| `index.html`, `style.css` | The page, on the shared tool kit (`widgets/_kit/tool-kit.css`) and its tokens, which equal the shell's in both themes | — |

Three small server routes, all in `HomePlaqueController` and all gated by the widget registry:

| Route | Inventory class | Does |
| --- | --- | --- |
| `GET {admin}/home/plaque/context` | metadata | Says whether this account has the Library and AI (`{library, ai}`), always 200, so the tool never fires a request that fails |
| `GET {admin}/home/plaque/sets` | listing (probed by `HomePlaqueTest`) | The `.plaque.json` assets the account may list in the Library, newest first, each with the ordinary asset download URL. It exists because the Library's folder listing does not return assets by default (`dam.tree.show_assets`) |
| `POST {admin}/home/plaque/generate` | mutation, `throttle:10,1` | One AI draft (see AI generation) |

The tool itself is available to everyone (`always_available`). The Library list, saving to the Library and AI need
the Library permission (`dam.assets`), the same line the other Library-backed tools draw. Everything else goes through
endpoints that already exist (see Hand-off routes).

## One frame function

`drawFrame(ctx, scene, t, resolve)` draws the whole output at time `t` (seconds) onto a 2D context sized to the output.
`scene = { set, output, items }`, where `items` are the plaques being played (one, the selection, or the set) laid end
to end by `timeline(items)`. `resolve(id)` returns an already-decoded image for an asset id. Everything the frame
depends on is in those arguments: no clock, no randomness, no DOM reads. The preview canvas calls it on every
animation frame with the audio clock's time; every exporter calls it for `t = n / fps`. Preview and export cannot
drift apart because there is only one drawing path. A Node test records the context calls twice for the same input
and requires identical logs (determinism), and checks timeline arithmetic.

Per plaque: `in`, `hold`, `out` seconds; entrance and exit styles (`fade`, `slide-up`, `slide-down`, `slide-left`,
`slide-right`, `pop`, `none`); easing (`ease-out`, `ease-in-out`, `linear`, `back`); a count-up for the amount;
social click effects at set times inside the plaque's local time.

## Set JSON (version 1)

```json
{
  "format": "mothership-plaque-set",
  "version": 1,
  "name": "Launch week",
  "output": { "mode": "frame", "width": 1920, "height": 1080, "fps": 30, "anchor": "bottom-left", "margin": 64, "scale": 1, "padSound": true },
  "plaques": [
    {
      "id": "p_abc123", "kind": "standard",
      "title": "New video out now", "subtitle": "Link in the description",
      "amount": { "enabled": false, "value": 0, "prefix": "$", "suffix": "", "decimals": 2, "countUp": true },
      "visual": { "type": "icon", "icon": "play", "symbol": "", "assetId": null },
      "style": { "layout": "row", "background": "#101114", "text": "#ffffff", "accent": "#bc232c", "radius": 18,
                 "font": "system", "titleSize": 34, "subtitleSize": 22, "width": 640, "fit": "wrap", "shadow": true },
      "timing": { "in": 0.5, "hold": 3, "out": 0.5 },
      "animation": { "enter": "slide-up", "exit": "fade", "easing": "ease-out", "distance": 60 },
      "sound": { "source": "builtin:chime", "volume": 0.8, "offset": 0 },
      "social": null
    }
  ],
  "assets": { "a_1": { "name": "logo.png", "type": "image/png", "data": "data:image/png;base64,…" } }
}
```

- `social` (kind `social`): `{ pageAssetId, platformAssetId, handle, actions: [{ label, doneLabel, glyph, at, sound }] }`.
  Each action is a timed click: the pointer moves to the button, presses it, the button flips to `doneLabel`.
- Assets are data URLs inside the set, so a set file is complete on its own. Audio assets use the same map.
- **The importer (`validateSet`) is the only way in**: file import, paste, library open, autosave restore, preset
  load and AI output all pass through it. It returns `{ ok, set, errors[] }` and never touches the current set; the
  caller swaps sets only on `ok`. It rejects unknown versions, clamps numbers to their ranges, drops unknown keys,
  refuses asset data that is not an image or audio data URL, and caps sizes (200 plaques, 25 MB of assets).
- Credentials and provider settings never enter a set: the importer drops any key it does not know, and the AI
  model name is not stored.
- Version bumps add a migration step in `validateSet`; version 1 is the first.

## Persistence

- Autosave: the whole set, debounced 600 ms, into IndexedDB (`mothership-plaque-<account id>` / `sets` / `current`)
  inside the tool frame. The host scopes the frame's `localStorage` to the account but not IndexedDB, so the database
  name carries the account: another person signing in on the same browser starts clean. Undo snapshots keep the asset
  map they refer to, so undoing an import brings back the earlier set's images and sounds. The frame's `localStorage` is the host's per-account preference store (64 KB per key), too small for
  images, so it holds only small preferences (last export format, panel state).
- Work reported to the host: typing in the editor is not reported as work (`data-agh-ephemeral`), because every change
  to the set is kept. While a change waits to be kept, after the browser refused to keep one, or while an open preset
  draft has been edited (a draft lives in memory until it is added), the tool sends `ready` with `holdsWork: true` and
  `dirty`, so the host asks before closing it; once none of these holds it sends `holdsWork: false` (see
  `docs/manual/developer/iframe-tools.md`).
- Undo and redo: snapshots of the set (assets are shared by reference), 100 deep. Bulk edits, AI append or replace,
  and imports are one step each.
- User presets live in IndexedDB (`presets`), so they survive a new set; a preset is a plaque with a name and no set around it.

## Hand-off routes

| # | Route | One action | Status |
| --- | --- | --- | --- |
| 2 | **Export to disk** | Export: PNG, GIF, APNG, zipped PNG sequence, chroma-key WebM; WAV; set JSON | Works offline |
| 3 | **Save to The Mothership library** | "Save to Library", inside the tool page only: a render, the sound as a WAV (optional) and the set JSON go through the page's bridge (`MothershipFrame.save`, `POST {admin}/tools/plaque/library`), which hands `files[]` + `directory_id` to the Library's own upload, into a folder the person picks from the Library's folders (`MothershipFrame.folders()`); the folder is remembered. They land wherever the asset disk is configured (local or the Umbrel bucket); no path is assumed | Works when the account may upload to that folder |
| 1 | **Hand back to an agent** | "Copy for an agent", in Export when the set file is picked, puts the set JSON (schema-tagged, ready to edit) on the clipboard; "Save to library" also files `<name>.plaque.json` beside the renders, which an agent reads with the library tools (today the REST API `GET /api/v1/rest/assets`; after the MCP rework, `library_search` + `library_fetch`). The reverse works the same way: an agent that made or restyled plaques, icons, images or sounds with the stack's creative skills writes a `*.plaque.json` (assets inline as data URLs) and the person uses "Paste" or "Open file", or "Open from library" on a saved `.plaque.json` | Works; the MCP-native read is the MCP rework plan's `library_*` tools |
| 4 | **Send to ArtCraft** | — | **Gap.** ArtCraft exposes only its stdio relay (`craft_*` tools) and local library folders; a browser page cannot reach either, and the storage plan forbids assuming a local path. Under the MCP rework's decided option A (Phase 4), the relay's library backend becomes The Mothership, so a plaque saved by route 3 is importable with `craft_import_asset` with no widget change. Smallest interface if the operator wants a direct button before that: the relay reads a Mothership asset id (`craft_import_asset {asset_id}`), which is exactly Phase 4. Nothing is added to the UI until it works |

## AI generation

Optional. `POST {admin}/home/plaque/generate` sends the brief (description, optional counts for themed, social and
donation plaques, a video length, a destination and a tone) to the server, which asks the active Magic AI platform
through the same server-side path as the StylePress titler: the platform's API key never reaches the page, and the
system prompt lives on the server, so the route is not a general model proxy. The PIM's own `/magic-ai/*` routes are
not used: the Studio runs with `MOTHERSHIP_PIM_ENABLED=false`, where they answer 403. When no platform with a chat
model is set up, `context` says `ai: false` and the Generate button stays hidden. The answer passes through
`validateSet`; the person previews it, then appends or replaces, and one undo reverts it. Offline, English keyword
matching (`suggestTopics`) offers topic presets from the same description.

## Optional extensions

| Extension | Status |
| --- | --- |
| Frame stepping | Built: the step buttons and the arrow keys move one frame at the output fps |
| Continuous sequence export | Built: "Whole set" exports the set as one timeline in every animated format |
| Transparent WebM | Built only where it works: on opening Export, the tool records a two-frame test clip and reads it back; the format is listed only when the alpha survived that round trip in this browser |
| Transparent MOV | Not built: browsers cannot encode ProRes 4444 or any alpha MOV |
| Local video backgrounds, MP4 over source video | Not built: browser MP4 encoding with sound is not available everywhere, and a format that fails in some browsers would break "expose only working formats". The PNG sequence and the WAV, or the chroma-key WebM, composite over source video in any editor |

## Interface (tool kit and the tool page's bar)

- Surface: the tool page (`/admin/tools/plaque`), 360 px to 1440 px wide, pointer and touch, and folding phones.
  Job: make a set of overlay plaques and get them out in the format the next tool needs.
- Panes, by the tool's own width: from 1100 px the set list (280 px), the preview over Output, and Edit (360 px),
  each scrolling by itself. From 720 px, the preview with a segment (Plaques, Output) picking the pane below, and Edit
  beside them. Under 720 px, the preview on top and a three-part segment (Plaques, Edit, Output); adding a plaque
  opens Edit. A book fold parts the columns at the fold; a tabletop fold puts the stage alone above it (see iPhone
  Duo below).
- Bar: inside the tool page the bar names the set, its menu holds "Open a set…", and it holds Export and Save to
  Library while the set has plaques and no preset draft is open. Opened on its own, the tool shows "Open a set…" and
  Export in its own row.
- Accent jobs (V1): the one commit per state (Save to Library in the bar, New plaque in the empty set, Add to set in
  a preset draft, the commit of each sheet), the current plaque in the list, the playhead. Selection also shows a
  check (V2).
- States: empty set (own look and words, three ways to start), editing, bulk edit, preset draft, playing, scrubbing,
  exporting (progress and cancel), export done, export failed, save to library (choose folder, no folder, saving,
  saved with a link in the page's notice, refused with the reason in the sheet), AI off, AI working, AI preview,
  import refused (list of reasons).

## iPhone Duo

- Tabletop: the stage alone fills the upright half. The transport (Play, the step buttons, what to play, Mute and the
  scrubber) sits on the flat half above the segment, so every control is where the hands are.
- Outer display upright (382 x 463 once the page's foot shows): the phone layout holds, with the preview on top and
  the pane scrolling by itself. The "whole tool scrolls" layout is for short windows that are wider than tall (a
  phone on its side).
- Close-up: when the stage would show the whole frame under 480 CSS px wide (the outer display, a phone, the open
  inner display flat or folded), the preview frames the plaques in play close up, centred and fitted to the stage, so their words can be
  read. The stage keeps its size. The Output pane shows the whole frame by default, and the Close-up switch after
  Mute turns it on or off. Exports never change.
- Touch: every control of the tool is a 44 px target under a coarse pointer. A row's checkbox keeps its 18 px mark,
  and the current row's Move buttons take a column beside the title.
- Export sheet: "Copy for an agent" takes its own row whenever the sheet itself is under 560 px wide (a container
  query), so a sheet on one half of an open Duo wraps the same way as a phone's.
