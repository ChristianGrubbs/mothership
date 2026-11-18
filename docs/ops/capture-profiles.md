# Detail layouts and capture profiles

Open **DAM → Configuration → Details layouts & capture profiles**. Configuration viewing and editing use the existing `dam.configuration.index` and `dam.configuration.update` permissions. Capture changes also require asset editing and directory access.

## Detail layouts

Create a layout for an extension (`mp4`, `osv`, `3mf`), a category (`video`, `image`, `audio`, `document`), or `*` for the default. Extension matches take precedence over asset kind/category and then the default. Select built-in or property fields, reorder by dragging or arrow buttons, and choose whether empty values are hidden. Save layouts explicitly. Removing a layout restores its fallback; with no applicable layout, the original information pane remains. This changes the preview information pane only; full metadata remains available in the asset details tabs.

## Capture profiles

Name the capture type, list its matching extensions, and order primary candidates. Extensions outside the priority list are companion-only. Enable automatic grouping for new uploads, or leave a profile manual. Automatic grouping requires one unambiguous enabled profile for every member. Ambiguous matches remain separate.

Matching uses the exact, case-sensitive filename stem in one DAM folder; extensions are compared in lowercase. Assets assigned to multiple folders, repeated extensions within the same capture, and matches spanning existing groups are not automatically merged. At least one primary candidate and two files are required. Upload completion re-evaluates the group, so arrival order does not determine the primary. A manual primary is retained when later files arrive.

## Existing library and corrections

Choose a saved profile and folder, preview the proposed groups, then apply. The server rejects stale previews when files or the profile change. Saving a profile alone does not regroup existing assets.

The Files tab lists all capture members and their attachments, with Open and Download actions. Admins can change primary, apply another compatible profile, or ungroup the capture. Ungrouping preserves every file, original metadata, asset ID, relationship and attachment; it disables automatic regrouping for those records. Applying a profile explicitly from Files can group them again. Storage-only path changes preserve captures. A stem or folder change detaches just the changed record; valid remaining members stay grouped and retain automatic grouping. Deleting the primary makes surviving members visible.

## Storage and deployment

Grouping is a presentation layer over original asset records: `dam_capture_profiles`, `dam_capture_groups`, and nullable `dam_assets.capture_group_id`. No existing asset is grouped during migration. Explorer, legacy grid/picker, public-share browse/navigation and folder-tree counts exclude companions; direct references and file access continue to resolve each original record, with its existing access checks.

Deploy the migration before refreshing caches and FPM/queue workers. No new runtime dependency is required. Rollback can deploy the previous application version while retaining the additive tables/columns; original records/files remain intact and the previous code displays each asset separately. Do not roll migrations down as part of routine rollback.

## Verification

`CaptureProfilesTest.php` covers ordered layout resolution, preview/apply, folder isolation, upload order, manual primary, ungroup, ambiguous profiles, stale previews, permissions, attachment downloads, view rendering, duplicate extensions, multiple-folder memberships and primary deletion. Broader graph, relationship, upload and folder-count suites run on the PostgreSQL leg of the self-hosted Pest workflow. New Vue templates compile with the installed Vue compiler; the production DAM build includes the new styles.
