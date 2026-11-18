{{-- The Library tree's loading placeholder, laid out like the loaded tree: the "All assets" row, the root
     row and one 36 px row per root folder (dam_library_root_page()). The generic three-group shimmer
     filled the sidebar's max height, so on a short tree the cards below jumped up when it loaded. --}}
@php($rootPage = dam_library_root_page())

@if ($rootPage)
    <div aria-hidden="true">
        <div class="flex gap-2 items-center mb-2 p-1" style="height: {{ bouncer()->hasPermission('dam.directory.store') ? 38 : 28 }}px">
            <div class="shimmer w-20 h-5"></div>
        </div>

        <div class="shimmer mb-2 rounded-md" style="height: 34px"></div>

        {{-- A structural wrapper hides its own row and opens its children in its place. Labels that wrap
             (long names in a narrow sidebar) still grow the loaded tree; the server can't measure them. --}}
        {{-- A wrapper's children load one tree page at a time, so it reserves at most a page plus its "Load more" row. --}}
        @php($pageSize = \Mothership\DAM\Repositories\DirectoryRepository::DEFAULT_TREE_PAGE_SIZE)
        {{-- The overall cap keeps the markup bounded; the container's max-height clips far sooner. --}}
        @php($rowCount = min($pageSize + 1, collect($rootPage['children'])->sum(fn ($child) => $child->is_structural_wrapper ? min((int) $child->children_count, $pageSize + 1) : 1)))

        @for ($row = 0; $row < $rowCount; $row++)
            <div class="flex items-center gap-1.5" style="height: 36px">
                <div class="shimmer w-4 h-4"></div>
                {{-- Inline sizes: the DAM bundle doesn't build these arbitrary values (the admin one does). --}}
                <div class="shimmer w-5" style="height: 21px"></div>
                <div class="shimmer" style="width: 105px; height: 21px"></div>
            </div>
        @endfor

        {{-- The root item's (empty) asset list keeps its mb-1. --}}
        <div style="height: 4px"></div>
    </div>
@else
    <x-admin::shimmer.tree />
@endif
