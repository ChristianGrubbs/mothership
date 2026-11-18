# Folder access and groups

Super users can right-click a sidebar folder and choose **Manage access**.
Search for a user or group, add it, optionally select **Include subfolders**,
then save. The checkbox applies to existing and future descendants.

**Settings → Groups** manages group names and membership. Users may belong to
multiple groups. Groups grant folder access; the user's existing role still
controls operations such as uploads, editing, deletion and downloads.

Access is additive: a role, a group or a direct user grant can provide it.
The dialog separates editable grants on the selected folder from access
provided by parent folders and roles. Removing a direct grant does not cancel
those other grants. Change inherited grants at their source. Super users and
roles configured for all folders retain that access.

Creating a folder through personal/group access does not grant it to unrelated
role peers. An inherited grant covers the new folder automatically; otherwise
the creator receives a direct grant. Existing role-based creation is preserved.

## Implementation and verification

The FolderAccessService adds user/group directory IDs to the existing
DirectoryPermissionService, so its callers use the combined grants. Ancestors
remain visible for navigation without exposing their assets. Group deletion
cascades through memberships and group grants; user grants are independent.

The new management routes require the authenticated admin role to have
permission_type=all, independently of their folder access.

FolderAccessTest.php covers CRUD, membership revocation, inheritance, role
coexistence, invalid input, management authorization, rendered views and creator
scope. Run it with the existing directory permission, auto-grant, inheritance,
role tab, API and granted-ID response tests.

Optional DAM_ACCESS_LOGGING=true emits typed identifiers/counts to
storage/logs/folder-access.jsonl. It is disabled by default and excludes names,
email addresses and request bodies.

Deployment applies the additive 2026_09_05_200000 migration before workers
restart. A code rollback can leave the new tables intact; the previous version
ignores their grants. Do not roll down the migration if those grants need to be
preserved for a subsequent deployment.
