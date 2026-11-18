<?php

use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;

if (! function_exists('dam_can_view_dir')) {
    function dam_can_view_dir(int $directoryId): bool
    {
        return app(DirectoryPermissionService::class)->canView($directoryId);
    }
}

if (! function_exists('dam_viewable_directory_ids')) {
    function dam_viewable_directory_ids(): array
    {
        return app(DirectoryPermissionService::class)->viewableIds();
    }
}

if (! function_exists('dam_acl_bypass')) {
    /**
     * The Library client's access gate. A bypass role reaches every folder the sidebar and
     * explorer are allowed to render, because those surfaces are already filtered through
     * viewableIds(). Keying this on anything narrower would demote the primary admin to a
     * page-load id snapshot, which cannot know about folders created later in the same session.
     */
    function dam_acl_bypass(): bool
    {
        return app(DirectoryPermissionService::class)->roleBypass();
    }
}

if (! function_exists('dam_accessible_dir_ids')) {
    /**
     * Only the roles whose client gates actually consult the list get one; under dam_acl_bypass()
     * every gate short-circuits before reading it, so shipping the full id set would be a stale
     * snapshot and a full-table read on every Library page load.
     */
    function dam_accessible_dir_ids(): array
    {
        return dam_acl_bypass() ? [] : app(DirectoryPermissionService::class)->directlyGrantedIds();
    }
}

if (! function_exists('dam_library_root_page')) {
    /**
     * The Library root's first folder page (DirectoryRepository::getLibraryRootPage()), read once per
     * request: the folder strip and the sidebar tree's placeholder both size from it.
     */
    function dam_library_root_page(): ?array
    {
        return once(fn () => app(DirectoryRepository::class)->getLibraryRootPage());
    }
}

if (! function_exists('dam_library_folder_seed')) {
    /**
     * The folder strip's seed: dam_library_root_page() plus each folder's asset count, so the badges
     * are there at first paint too.
     */
    function dam_library_folder_seed(): ?array
    {
        return once(fn () => app(DirectoryRepository::class)->withSubtreeAssetCounts(dam_library_root_page()));
    }
}

if (! function_exists('dam_can_access_dir')) {
    function dam_can_access_dir(int $directoryId): bool
    {
        return app(DirectoryPermissionService::class)->canAccess($directoryId);
    }
}

if (! function_exists('dam_access_lists')) {
    /**
     * The one flag every view and every Vue component reads to know which sharing model is live
     * (ADR 0010): true only while the DAM_ACCESS_MODEL row is exactly `lists`. Under `grants` — the
     * default — the Share dialog's access section, the per-file Share entries and the sharing indicators
     * render nothing, and the folder-access panel stays.
     */
    function dam_access_lists(): bool
    {
        return app(AccessModel::class)->lists();
    }
}
