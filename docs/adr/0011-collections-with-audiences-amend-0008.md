# ADR 0011: Collections are hand-picked sets with an audience; the Web Pages organizer becomes "page groups"

Date: 2026-10-07 · Status: accepted (P4c) · Amends: [ADR 0008](0008-web-page-collections-are-an-organizer.md) · Roadmap: D2, D23 and §3.3

## Context
ADR 0008 named the Web Pages organizer a **collection** (`dam_web_collections`): a team-shared tree that
organizes pages and grants nothing. ADR 0008 also said that a per-user or shareable collection would be a new
decision. Roadmap D2 asks for exactly that: an ordered, hand-picked set of files drawn from any folders, with an
audience (named people and groups, or one public link) and an expiry. D23 adds a short `/c/{slug}` address.
One word cannot name both things. The Web Pages UI never shows the word "collection" to users; it says "folders",
which is also the Library's word.

## Decision
- **"Collection" is the new feature.** In the UI, the API and the glossary, a collection is a hand-picked, ordered
  set of files with an audience and an optional expiry (`dam_collections`, `dam_collection_assets`,
  `dam_collection_audience`). The ADR 0008 organizer is called a **page group** in the UI and the glossary. Its
  code and tables keep their names (`dam_web_collections`, `WebCollectionService`, the `dam.pages.organize` ACL);
  only user-facing text changes.
- **The audience grant is bound to its container (§3.3).** A collection's audience may read a member only through
  the collection's own routes, which ask the access seam with `AccessContext::collection()` (`via =
  collection:<id>`). `AssetAccessDecision` hands that context, and only that context, to `CollectionGrant`. No
  other context reaches it, so being in a collection's audience never widens the Library, search, Find similar,
  Related, REST, ordinary zips or file routes.
- **A member is served only while it is out for use.** A withdrawn, pending, out-of-window or trashed member is
  neither served nor counted, with no owner or editor exemption inside a collection. Removing a member, shrinking
  the audience, archiving or expiring the collection all take effect on the next request; nothing is cached.
- **Adding a member is redistribution.** The adder must be able to see the file, and must own it, be a full
  admin, or hold `dam.asset.share`. A hidden file answers 404 and a visible one they may not redistribute
  answers 403; then nothing in the request is added.
- **A public collection reuses Share.** `dam_shares.share_type = collection` gets every S5 rule unchanged: a
  hashed token, the password prompt, download-off, creator access (here: still able to manage the collection),
  Reissue and expiry, through `ShareGate` and `EnforceShareGate`. A link answers like an expired one while its
  collection is expired or archived, and is revoked when the audience changes away from public. Collection
  links are managed on the collection's page; the Shared Links list leaves them out.
- **Slugs are an address, never a credential.** A slug is unique, lowercase and never a reserved word. A rename
  keeps the old slug in `dam_slug_redirects` as a 301 to the current one. For a public collection with an active
  link the slug leads to the link, whose rules still apply; for any other collection it leads to the collection
  page only after sign-in and the audience check. An unknown slug and one the visitor cannot open answer alike:
  the sign-in page when signed out, the same 404 when signed in.
- **Collections are deleted outright**, not trashed. Archiving is the reversible off switch.

## Consequences
- ADR 0008 stands for the organizer, under its new UI name. Its last consequence ("a shareable collection is a
  new decision") is this ADR.
- `AssetAccessDecision` gains one additive branch; `CollectionGrant` holds every collection rule.
- An audience may preview and download through the collection. Download-off is a link option only.
- Share links got their own slug later in P4 (D23): `/s/{slug}`, an alias that redirects to the link's token URL
  while the link opens, under the same rules and one register (`dam_slug_redirects` now holds every slug a
  collection or a share has claimed, so slugs are unique across both). An unknown, revoked or expired share slug
  answers like an unknown token; Reissue retires the slug. Collection sections remain a separate decision.
