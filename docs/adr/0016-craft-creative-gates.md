# ADR 0016: Hosted craft work proves a Territory before production, and publishing a designed piece needs an evidence manifest

Date: 2026-10-28 · Status: accepted (operator grill 2026-10-28; skill text in PR1, server enforcement in PR2, session font preload in PR3) · Builds on: ADR 0012, ADR 0015

## Context
On 2026-10-28 a ChatGPT Work run made a 60 s gym signage loop (asset 2709) through the hosted craft connector.
Every measured limit passed, and the piece was still well short of the Compadre commission bar the skills
already name: flat panels, one layout repeated, Inter instead of the brand face, an off-brand colour and a typeset
organization name in place of the logo.

The run had these failures:
- It routed to FilmCraft and drew its graphics with FilmCraft's basic shape and text tools. EffectCraft, a
  near-After-Effects engine whose skill already claims signage loops, went unused.
- The user approved a written direction. No rendered frames were shown.
- The agent's own critique was the only judge.
- No logo files existed in the Library, and the session's font list did not have the brand face.
- An 11-minute full export ran before anything creative had been proven, and it died on the workspace byte
  limit.

The skills held the right guidance. Nothing made the steps happen.

## Decision
- **Lead app.** A piece whose value is designed motion is a Motion package, and EffectCraft is its lead app,
  footage included. An Edit is led by FilmCraft and takes its designed graphics from EffectCraft as ProRes
  4444 with alpha. The FilmCraft skill opens with this redirect. `craft_get_skill` and `craft_create_session`
  for FilmCraft return the same pointer, so a client that skips the router still sees it.
- **Floors and the Bar are separate gates.**
  - Floors are measured pass/fail limits. The Bar is judged by the Commission test.
  - A *not at bar* verdict blocks publishing unless the user explicitly says "ship it anyway", quoted in the
    record.
- **The user judges Territories from rendered evidence.** Before production, the agent shows 2–3 Territories
  as Style frames on the real placement, plus one 3–5 s Motion proof for motion. Approval of prose alone does
  not count. A Named reference becomes a written criteria table that sets craft, never identity. The agent
  never approves its own Territory. The operator may delegate acceptance-test runs to their own local agent
  (Claude Code on the operator's Mac), which may approve the frame checkpoint itself only on runs labelled as
  tests and never published as deliveries; this is not available to hosted clients and is not in the skills.
- **Evidence manifest.** `craft_save_asset` refuses a cover export of a designed piece unless the manifest
  is present: kind, approved Style frame and proof file ids, Floor numbers, and the Bar verdict or the
  user's quoted override. A job that declares kind `utility` is exempt. Until the server check ships, the
  skills tell agents to carry the manifest with the publish.
- **Tools.** Three tools are added:
  - `craft_measure` returns the Floor numbers (motion-life, loudness, contrast and type height) for a
    `/work` export.
  - `craft_delete_file` frees workspace bytes.
  - A Proof export (540p, at most 10 s, with the seam for loops) must pass the Floors before a full-length
    export.
- **Brand and references.**
  - Two Library folders are created, readable by every The Mothership user (not admins only), so every user gets
    the same Bar:
    - The **Brand kit**: the real logo files only (primary logo, wordmark, mascot mark; SVG and PNG; colour,
      black and white).
    - **Quality references**: the operator's example media, filed by piece kind, never shared outside The
      Mothership or published.
  - **Brand fonts** are the organization's licensed family, already uploaded by the
    operator to the Hub Fonts & Brushes store (ADR 0015) with visibility Everyone. That store is the single
    source for brand fonts; the Library holds none. Mulish is only the public-web stand-in, used in a craft
    piece only with the user's explicit OK.
  - The skills name both folders, with their IDs in one shared reference section.
  - A follow-up (PR3) preloads the Everyone-visible brand fonts from that store into every hosted session
    before its first font scan. Until then the skills tell agents to check the session's font list for the brand face
    and stop if it is missing.

## Consequences
- **What a hosted client must now do:** stop for a frame checkpoint. That makes runs slower, and they can no
  longer finish unattended unless the user approves in the conversation.
- **What the server can't verify:** that the approval came from a human. The manifest records the quoted
  approval, so the claim is auditable but not prevented.
- **What existing clients will see:** publishes that worked before are refused until they send a manifest
  or declare `utility`.
- **Rejected:**
  - Skill text alone. It was already present and was skipped.
  - A reviewer model as the only judge. ChatGPT can't call one reliably.
  - A Fonts folder in the Library. ADR 0015's store already holds family, style and visibility, and a second
    copy would drift.

## Implementation note (PR2, 2026-10-28)

The server-side enforcement in the hosted craft MCP refines the decision in four places. The decision itself
is unchanged.

- **The Proof-export rule is enforced at publish.** A full-length export is a native engine tool called
  through `craft_call_tool`. Its name and arguments are engine-defined and discovered at runtime, and its
  duration and resolution exist only after the render, so the server can't reliably see or stop it.
  - `craft_save_asset` checks it instead. For a video (or multi-frame GIF) of a designed piece that is not a
    Proof export, the session must have a passing Proof measurement from `craft_measure`; otherwise the
    publish reports `proof_missing`.
  - `utility` and `non_visual` are exempt, and an unknown kind counts as designed.
  - The session's newest Proof measurement is the one that counts, so a newer failing Proof is never
    hidden by an older passing one. When that newest Proof failed and the manifest quotes the user's
    `evidence.floors.override_quote`, `proof_missing` is reported, not refused, and the stored manifest
    records that the override covered the Proof rule. With no Proof at all it is never overridden: a
    full render before any test render is not allowed.
  - This does not prevent a wasted long render. A `craft_call_tool` export or render call answers an
    advisory `proof_notice` when the session has no passing Proof, and the skill text says the same.
- **Stack attachments report and never refuse.** A native file or an extra export attached with
  `stack_asset_id` may carry a manifest or inherit the cover's kind. Its problems, `proof_missing`
  included, are reported in the receipt and the log, and the file publishes. The cover export is the gate:
  it must carry verified Floors from a `craft_measure` record of its own bytes.
- **The evidence manifest starts in warn mode.** `CRAFT_MCP_EVIDENCE_MODE` defaults to `warn`: a publish
  proceeds and its receipt lists the problems. The operator switches it to `block` after reviewing the
  warn-mode decision logs for misfires. Until then, contrary to Consequences above, existing clients are
  warned, not refused.
- **`craft_delete_file` stays hidden behind `CRAFT_MCP_DELETE_FILE`** until the craft worker release that
  implements `delete_file` is deployed with operator approval.

## Implementation note (PR3, 2026-10-28)

The brand font preload refines the follow-up named in the Decision. The decision itself is unchanged.

- **The fonts ride in the worker's `create` request and are written before launch.** Laravel sends
  `fonts: [{name, data, sha256}]`; the worker validates the whole list, then writes it to `/work/.fonts/`
  after it makes the session directory and before it launches the engine VM. No engine can scan earlier, so
  this holds for every app, including those whose font mechanism the repo does not establish.
- **Rejected:**
  - `put_file` after `create`. The engine already runs by then, so it races any startup scan; it also costs a
    round trip per face and would need the transfer gate, which makes `create` fail during any import.
  - A worker-side shared fonts directory or read-only mount. Laravel and the worker share no filesystem, the
    copy would go stale when sharing changes, and a new mount point means rebuilding every engine image.
  - A server-side native rescan. Native tool names are discovered at runtime, so the skills keep "list, and
    rescan with the app's own command if missing".
- **Selection is an allowlist of families shared with Everyone.** Only Hub `ttf` and `otf` faces visible to
  the caller, shared with Everyone and in `CRAFT_MCP_PRELOAD_FONT_FAMILIES` (the brand family by default) are sent, and
  the caller needs `dam.hub.resources`. Requiring Everyone keeps a full admin from receiving someone's
  private copy, and the allowlist keeps Mulish out.
- **The server confirms rather than assumes.** `craft_create_session` reports `brand_fonts` with `status`
  `loaded`, `partial`, `none`, `off`, `unconfirmed` (no matching worker echo, as from an older worker) or
  `failed` (the worker refused the fonts and Laravel retried once without them). The block carries no paths,
  file names, uuids or bytes.
- **The preload stays off until deployed.** `CRAFT_MCP_PRELOAD_FONTS` defaults to false. The order is the
  worker release, then Laravel with the switch off, then a check that the stored family name matches, then
  the switch, with operator approval.

## Implementation note (looking at 100%, 2026-11-11)

Three design batches showed that an agent could not judge its own export at real size: native previews are
fitted to at most 1600 px, so the only way to see type edges, cut-out fringes or print detail was to
publish the file and download it. `craft_view` closes that without widening what leaves a session:

- **Same exit, same budget.** Pixels reach the calling client only as MCP image blocks inside the inline
  preview budget that tool results already use (three images, 1.5 MiB of base64, 512 KiB each, 1600 px).
  A region is one image pixel per source pixel or it is left out with the reason; it is never scaled.
  There is still no way to take a whole full-resolution file out of a session except by publishing it.
- **Read-only and unrecorded.** It reads the file as `craft_measure` does (a shared measurement slot, the
  transfer gate, a private staging directory that is always removed), writes nothing to the session and
  makes no Measurement record. Looking is not evidence: Floors still come from `craft_measure`.
- **The agent looks; the user still approves.** A view is for the agent's own critique passes. It does not
  replace showing Style frames to the user, and no gate reads it.
