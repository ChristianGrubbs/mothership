# 0014 — Share links hand out every stacked file of the asset they reach

- Status: accepted (2026-10-19, operator request "complete file access through shared links"; planner decision PD1)
- Amends: the "only alternates are public" rule documented on `SharedViewerController::sharedAlternates()` (removed)

## Context
A public link showed the main file and `alternate` slots only. Recipients of a stacked asset (cover plus its
attachments) could not see or download the other files they were sent.

## Decision
A link authorizes, for each asset it reaches (the asset of an asset link; each member of a folder or collection link
after the existing membership checks), the current revision of every file slot except the roles in
`Asset::UNSTACKED_ROLES` (`version`, `proxy`, `thumbnail`). `StackMembers` is the only definition of that set (see the amendment below); every
listing, thumbnail, preview, download and ZIP route on the public path looks a file up inside it and answers 404
otherwise. Superseded revisions, other assets' slots, capture-group companions and relationship-linked assets are not
part of it. Expiry, revocation, password and downloads-off apply unchanged. With downloads off, a related file gets only
derivative previews (still images, posters): its original, including video, audio and PDF playback, answers 403, as do
ZIPs. The main file keeps its existing preview-only behaviour: an inline-safe original (MP4, MP3, PDF, PNG) is still
shown inline through `/preview`, and any other type answers 403.

## Consequences
Working files (source, project, mask, prompt, sidecar) on a shared asset become visible to link holders. An owner who
wants a file private moves it off the asset before sharing. Narrowing later is one constant, `Asset::UNSTACKED_ROLES`: the ordinary member query lives in `StackMembers`, and merge
sources go through `MergedSourceLinks`.

## Amendment 2026-10-19 (shared member query)
The member query itself now lives in `Mothership\DAM\Services\Stack\StackMembers`, the one definition shared by share links
and the Creative Hub. `SharedFiles` stays the share-link policy layer on top of it, and merge sources still resolve
through `MergedSourceLinks`.

## Amendment 2026-10-24 (web page members)
A preview-only link renders a web member exactly like the representative's page, with no implicit download or edit
access: `SharedFiles::present()` adds `file_id`, `checksum` and a `page_url` for an eligible current own web member
(ADR 0007, 2026-10-24), and the viewer shows it in the sandboxed page frame. A copied selected-member link carries
`?file=<slot>&file_id=<id>&checksum=<64 hex>` and is validated as a whole before it selects anything: a changed,
moved or removed file is an explicit unavailable state, never the representative or a newer revision. The old
`?file=<slot>` link and the unpinned default stage are unchanged.
