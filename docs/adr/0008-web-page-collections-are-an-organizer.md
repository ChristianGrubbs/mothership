# ADR 0008: Web page collections are an organizer, not a permission boundary

Date: 2026-09-22 · Status: accepted

## Context
The Web Pages area (ADR 0007) lists `file_type = 'web'` assets scoped by the Library's directory grants.
Users need to group pages by project or topic. The Library directory tree is a permission structure:
creating a folder there changes who can see what, and the operator does not want organizing pages to
double as an access decision.

## Decision
Web pages are organized in **collections**: a separate, nestable, team-shared tree
(`dam_web_collections`) with at most one **placement** per page (`dam_web_collection_assets`).
Collections carry no permission. Every listing keeps the directory-grant filter, so two admins can see
different pages inside the same collection. A page's Library directory and its collection are
independent facts; the card shows both. Create/rename/move/delete of collections and moving pages
require the new ACL `dam.pages.organize`, included in the `web_pages` Sandbox capability.

## Consequences
- No change to `DirectoryPermissionService`, share links, or the page routes.
- Deleting a collection reparents its children and pages; it never deletes assets.
- Depth is capped at 10 and sibling names are unique, enforced in `WebCollectionService`.
- A future per-user or shareable collection is a new decision, not an extension of this one.
