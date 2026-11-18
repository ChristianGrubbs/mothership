@once
<script type="text/x-template" id="v-dam-web-pages-browser-template">
    <div
        class="relative flex flex-col gap-3 py-3"
        :aria-busy="busy"
        data-web-pages-root
        @keydown="onGlobalKeydown"
        @dragenter="onFileDragEnter"
        @dragover.prevent="onFileDragOver"
        @dragleave="onFileDragLeave"
        @drop.prevent="onFileDrop"
    >
        <p v-if="notice" class="rounded bg-amber-100 px-3 py-2 text-sm text-amber-800 dark:bg-amber-900 dark:text-amber-200" data-web-pages-notice>@{{ notice }}</p>

        {{-- Header: breadcrumb (every crumb stays a link so the DOM tests and the Playwright spec can
             click the root by text), then the toolbar. The current level is bold so the trail reads
             as "where am I" rather than a stray blue link. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav :aria-label="labels.title" class="flex min-w-0 flex-wrap items-center gap-1 text-sm" data-web-pages-breadcrumb>
                <template v-if="!collapseBreadcrumb">
                    <template v-for="(crumb, index) in breadcrumb" :key="'crumb-'+(crumb.id ?? 'root')">
                        <span v-if="index > 0" class="icon-chevron-right text-gray-400" aria-hidden="true"></span>
                        <a
                            href="#"
                            class="rounded px-1 py-0.5 hover:underline"
                            :class="{'font-semibold text-gray-800 dark:text-white': index === breadcrumb.length - 1, 'text-blue-600 dark:text-primary-400': index !== breadcrumb.length - 1, 'ring-2 ring-primary-500': dragOverId === crumb.id}"
                            :aria-current="index === breadcrumb.length - 1 ? 'page' : null"
                            :data-drag-over="dragOverId === crumb.id"
                            @click.prevent="openCollection(crumb.id)"
                            @dragover.prevent="onDragOverTarget(crumb.id)"
                            @dragleave="onDragLeaveTarget(crumb.id)"
                            @drop.prevent="onDrop($event, crumb.id)"
                        >@{{ crumb.name }}</a>
                    </template>
                </template>
                <a href="#" class="text-blue-600 hover:underline dark:text-primary-400" v-else @click.prevent="openCollection(breadcrumb[breadcrumb.length - 2].id)">&lsaquo; @{{ breadcrumb[breadcrumb.length - 2].name }}</a>
            </nav>

            <div class="flex flex-wrap items-center gap-2" data-web-pages-toolbar data-fold-wrap>
                <label class="relative block">
                    <span class="sr-only">@{{ labels.search }}</span>
                    <span class="icon-search absolute left-2 top-2 text-base text-gray-400" aria-hidden="true"></span>
                    <input
                        type="search"
                        class="w-56 rounded border border-gray-200 bg-white py-1 pe-8 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        style="padding-inline-start: 1.75rem;"
                        :placeholder="labels.search"
                        v-model="filters.q"
                        data-web-pages-search
                        @input="onSearchInput"
                    />
                </label>
                <select class="custom-select bg-white py-1 pe-8 text-gray-800 dark:bg-gray-800 dark:text-white" v-model="filters.owner" :aria-label="labels.browser.owner" data-web-pages-owner @change="load({page: 1})">
                    <option value="everyone">@{{ labels['owner-everyone'] }}</option>
                    <option value="me">@{{ labels['owner-me'] }}</option>
                </select>
                <select class="custom-select bg-white py-1 pe-8 text-gray-800 dark:bg-gray-800 dark:text-white" v-model="filters.sort" :aria-label="labels.browser.sort" data-web-pages-sort @change="onSortChange">
                    <option v-for="option in sortOptions" :key="option.value" :value="option.value">@{{ option.label }}</option>
                </select>
                <div class="inline-flex overflow-hidden rounded border border-gray-200 dark:border-gray-700" role="group" :aria-label="labels.browser.view" data-web-pages-view>
                    <button type="button" class="px-2 py-1 text-sm" :class="{'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-white': view === 'grid', 'text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800': view !== 'grid'}" :aria-pressed="view === 'grid'" :title="labels.browser['view-grid']" data-action="view-grid" @click="setView('grid')">
                        <span class="icon-dam-image text-base" aria-hidden="true"></span><span class="sr-only">@{{ labels.browser['view-grid'] }}</span>
                    </button>
                    <button type="button" class="border-l border-gray-200 px-2 py-1 text-sm dark:border-gray-700" :class="{'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-white': view === 'list', 'text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800': view !== 'list'}" :aria-pressed="view === 'list'" :title="labels.browser['view-list']" data-action="view-list" @click="setView('list')">
                        <span class="icon-menu text-base" aria-hidden="true"></span><span class="sr-only">@{{ labels.browser['view-list'] }}</span>
                    </button>
                </div>
                <button v-if="can.organize" type="button" class="secondary-button py-1" :class="{'ring-2 ring-primary-500': selectMode}" :aria-pressed="selectMode" data-action="toggle-select" @click="toggleSelectMode">@{{ labels.browser.select }}</button>
                <button ref="newFolderButton" v-if="can.organize" type="button" class="secondary-button py-1" data-action="new-folder" @click="openDialog('new')">@{{ labels.browser['new-folder'] }}</button>
                <button v-if="can.upload" type="button" class="primary-button py-1" data-action="upload-here" @click="openDialog('upload')">@{{ labels.browser['upload-here'] }}</button>
            </div>
        </div>

        <div v-if="selectMode" class="flex flex-wrap items-center gap-3 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300" data-web-pages-select-toolbar>
            <span data-selected-count>@{{ trans(labels.browser.selected, {count: selected.size}) }}</span>
            <button v-if="showMoveControls" type="button" class="secondary-button py-1" :disabled="selected.size === 0" @click="openDialog('move')" data-action="move-selected">@{{ labels.browser['move-to'] }}</button>
            <button type="button" class="secondary-button py-1" @click="clearSelection" data-action="clear-selection">@{{ labels.browser.clear }}</button>
        </div>

        <div aria-live="polite" class="sr-only" data-web-pages-announce>@{{ announcement }}</div>

        <div v-if="loadError" class="flex flex-wrap items-center justify-between gap-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-gray-800 dark:text-red-300" role="alert" data-web-pages-error>
            <span>@{{ labels.browser['load-failed'] }}</span>
            <button type="button" class="secondary-button py-1" data-action="retry" @click="load()">@{{ labels.browser.retry }}</button>
        </div>

        {{-- Folders: the folder glyph the Library uses, a count badge instead of "(n)" wrapping into the
             name, and a kebab that shows itself on hover, focus or a touch screen. The same menu opens on
             right-click, ContextMenu and Shift+F10. `relative` on the card keeps the menu anchored to it
             instead of to the grid. --}}
        <template v-if="!searchMode && children.length">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@{{ labels.browser.folders }}</p>
            <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));" data-web-pages-folders>
                <article
                    v-for="child in children"
                    :key="'folder-'+child.id"
                    role="button"
                    tabindex="0"
                    class="group relative flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white p-2 text-start transition hover:border-gray-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-900"
                    :class="{'ring-2 ring-primary-500': dragOverId === child.id}"
                    :aria-label="child.name"
                    :data-web-collection="child.id"
                    :draggable="cardsDraggable && child.can_modify === true"
                    @dragstart="onFolderDragStart($event, child)"
                    @dragover.prevent="onDragOverTarget(child.id)"
                    @dragleave="onDragLeaveTarget(child.id)"
                    @drop.prevent="onDrop($event, child.id)"
                    @dblclick="startRename(child)"
                    @keydown="onCardKeydown($event, {type: 'folder', item: child})"
                    v-on:contextmenu="onFolderContextMenu($event, child)"
                    v-on:click="onFolderClick($event, child)"
                >
                    <span class="icon-dam-folder shrink-0 text-2xl text-amber-600" aria-hidden="true"></span>
                    <div class="flex min-w-0 flex-1 flex-col">
                        <input
                            v-if="renaming === child.id"
                            :ref="setRenameRef"
                            class="w-full rounded border border-gray-200 bg-white px-1 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                            :value="child.name"
                            :aria-label="labels.browser.rename"
                            data-rename-input
                            @click.stop
                            @keydown.enter.prevent="saveRename($event, child)"
                            @keydown.esc.stop="cancelRename"
                            @blur="saveRename($event, child)"
                        />
                        <span v-else class="truncate text-sm font-medium text-gray-800 dark:text-white" :title="child.name">@{{ child.name }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400" data-folder-count>@{{ pageCountLabel(child) }}</span>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded p-1 text-gray-500 opacity-0 transition-opacity hover:bg-gray-100 group-hover:opacity-100 dark:hover:bg-gray-800"
                        :class="{'opacity-100': isTouch || openMenu === 'folder-' + child.id || menuFocus === 'folder-' + child.id}"
                        style="min-width: 1.75rem;"
                        aria-haspopup="menu"
                        :aria-expanded="openMenu === 'folder-' + child.id"
                        :aria-label="trans(labels.browser['folder-actions-for'], {name: child.name})"
                        :data-web-collection-menu="child.id"
                        v-on:click.stop="toggleFolderMenu($event, child)"
                        v-on:focus="menuFocus = 'folder-' + child.id"
                        v-on:blur="menuFocus = null"
                        v-on:keydown.shift.f10.prevent="toggleFolderMenu($event, child)"
                        v-on:keydown.context-menu.prevent="toggleFolderMenu($event, child)"
                    ><span class="icon-dam-menu text-base" aria-hidden="true"></span></button>
                    <div v-if="openMenu === 'folder-' + child.id" role="menu" class="absolute right-2 z-10 mt-1 rounded border border-gray-200 bg-white p-1 text-gray-800 shadow dark:border-gray-700 dark:bg-gray-800 dark:text-white" style="top: 100%; min-width: 10rem;" :data-web-collection-menu-open="child.id" v-on:keydown="onFolderMenuKeydown($event)">
                        <button type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" data-action="open-folder" v-on:click.stop="openFolderFromMenu(child)">@{{ labels.browser.open }}</button>
                        <button v-if="folderMayShare(child)" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" data-action="share-folder" v-on:click.stop="openShareFolder(child)">@{{ labels.browser['share-folder'] }}</button>
                        <button v-if="folderMayModify(child)" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" data-action="rename-folder" v-on:click.stop="startRename(child)">@{{ labels.browser.rename }}</button>
                        <button v-if="showMoveControls && child.can_modify === true" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" data-action="move-folder" v-on:click.stop="openMoveFolder(child)">@{{ labels.browser['move-to'] }}</button>
                        <button v-if="folderMayDelete(child)" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-gray-700" data-action="delete-folder" v-on:click.stop="confirmDelete(child)">@{{ labels.browser.delete }}</button>
                    </div>
                </article>
            </div>
        </template>

        <p v-if="searchMode" class="text-sm font-semibold text-gray-600 dark:text-gray-300">@{{ trans(labels.browser['results-for'], {q: filters.q}) }}</p>

        {{-- Skeleton while the very first load of a level is in flight; an already rendered level just
             dims (aria-busy on the root) so the layout does not jump. --}}
        <div v-if="busy && !hasContent" class="grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));" data-web-pages-skeleton aria-hidden="true">
            <div v-for="n in 4" :key="'skeleton-'+n" class="animate-pulse overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="aspect-video bg-gray-200 dark:bg-gray-800"></div>
                <div class="flex flex-col gap-2 p-3">
                    <div class="h-4 w-32 rounded bg-gray-200 dark:bg-gray-800"></div>
                    <div class="h-3 w-24 rounded bg-gray-100 dark:bg-gray-800"></div>
                </div>
            </div>
        </div>

        {{-- Empty states carry the guidance; the one Upload here button lives in the toolbar, so the
             page never shows two identical primary buttons. The root copy changes when folders exist:
             "no web pages yet" was wrong while CIL held one. --}}
        <template v-else-if="pages.data.length === 0 && !loadError">
            <div v-if="!searchMode && !collection" class="flex flex-col items-center gap-2 py-12 text-center text-gray-500 dark:text-gray-400" data-web-pages-empty="root">
                <span class="icon-dam-link text-4xl text-gray-400" aria-hidden="true"></span>
                <p>@{{ children.length ? labels.browser['empty-root-with-folders'] : labels.browser['empty-root'] }}</p>
                <p v-if="can.upload" class="text-xs">@{{ labels.browser['drop-hint'] }}</p>
            </div>
            <div v-else-if="!searchMode && collection" class="flex flex-col items-center gap-2 py-12 text-center text-gray-500 dark:text-gray-400" data-web-pages-empty="folder">
                <span class="icon-dam-folder text-4xl text-gray-400" aria-hidden="true"></span>
                <p>@{{ labels.browser['empty-folder'] }}</p>
                <p v-if="can.upload" class="text-xs">@{{ labels.browser['drop-hint'] }}</p>
                <button v-if="showMoveControls && selected.size" type="button" class="secondary-button py-1" @click="openDialog('move')">@{{ labels.browser['move-pages-here'] }}</button>
            </div>
            <div v-else-if="searchMode" class="flex flex-col items-center gap-2 py-12 text-center text-gray-500 dark:text-gray-400" data-web-pages-empty="no-matches">
                <span class="icon-search text-4xl text-gray-400" aria-hidden="true"></span>
                <p>@{{ trans(labels.browser['no-matches'], {q: filters.q}) }}</p>
                <button type="button" class="secondary-button py-1" @click="clearSearch">@{{ labels.browser.clear }}</button>
            </div>
        </template>

        {{-- Grid view. Inline grid template, like the folder grid above: the committed CSS bundle is
             not rebuilt on deploy, so an arbitrary-value utility used only here never exists and the
             cards collapse to one full-width column. --}}
        <div v-if="pages.data.length && view === 'grid'" class="grid gap-4" :class="{'opacity-60 pointer-events-none': busy}" style="grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));" data-web-pages-cards>
            <article
                v-for="page in pages.data"
                :key="'page-'+page.id"
                role="button"
                tabindex="0"
                class="group relative flex cursor-pointer flex-col rounded-lg border border-gray-200 bg-white transition hover:border-gray-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-900"
                :class="{'ring-2 ring-primary-500': selected.has(page.id)}"
                :aria-label="page.display_name"
                :data-web-page="page.id"
                :draggable="cardsDraggable"
                @dragstart="onPageDragStart($event, page)"
                @click="onCardClick($event, page)"
                @keydown="onCardKeydown($event, {type: 'page', item: page})"
            >
                <input v-if="selectMode" type="checkbox" class="absolute left-2 top-2 z-10 h-4 w-4" :checked="selected.has(page.id)" :aria-label="page.display_name" @click.stop="toggleSelect(page.id)" :data-select-page="page.id" />
                {{-- The image is taken out of flow: an in-flow image's intrinsic size overrides
                     aspect-video (min-height: auto), so a 301x206 placeholder made the box 1.46:1.
                     Only this box clips (with the card's top radius): an overflow-hidden card
                     cut off the kebab menu's last items. --}}
                <div class="relative block aspect-video overflow-hidden bg-gray-100 dark:bg-gray-800" style="border-top-left-radius: calc(0.5rem - 1px); border-top-right-radius: calc(0.5rem - 1px);">
                    <img :src="page.state === 'ready' ? page.thumbnail_url : placeholderSvg" alt="" class="absolute inset-0 h-full w-full object-cover" loading="lazy" v-on:error="onThumbError" />
                    <span v-if="page.state === 'preparing'" class="absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-200" data-page-state="preparing"><span class="icon-processing animate-pulse" aria-hidden="true"></span>@{{ labels.preparing }}</span>
                    <span v-else-if="page.state === 'failed'" class="absolute left-2 top-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700" data-page-state="failed">@{{ page.error === 'disk_unsupported' ? labels['disk-unsupported'] : labels['bundle-failed'] }}</span>
                    <span v-if="page.state === 'ready' && !selectMode" class="absolute inset-0 flex items-center justify-center opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true">
                        <span class="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-800 shadow"><span class="icon-dam-preview text-base"></span>@{{ labels.browser.preview }}</span>
                    </span>
                </div>
                <div class="flex flex-1 flex-col gap-1 p-3">
                    <p class="truncate font-semibold text-gray-800 dark:text-white" :title="page.display_name">@{{ page.display_name }}</p>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400" data-page-meta>@{{ pageMeta(page) }}</p>
                    <div v-if="page.library_directory || page.collection_path || page.has_active_share" class="flex flex-wrap gap-1 text-xs">
                        <span v-if="page.library_directory" class="rounded bg-gray-100 px-1 text-gray-700 dark:bg-gray-800 dark:text-gray-300" data-library-folder-chip>@{{ page.library_directory }}</span>
                        <span v-if="page.collection_path" class="rounded bg-gray-100 px-1 text-gray-700 dark:bg-gray-800 dark:text-gray-300" data-collection-path>@{{ page.collection_path }}</span>
                        <span v-if="page.has_active_share" class="rounded bg-emerald-100 px-1 text-emerald-800">@{{ labels.shared }}</span>
                    </div>
                    <div class="mt-auto flex items-center justify-end pt-1">
                        <button
                            type="button"
                            class="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                            aria-haspopup="menu"
                            :aria-expanded="openMenu === 'page-' + page.id"
                            :aria-label="trans(labels.browser['actions-for'], {name: page.display_name})"
                            :data-web-page-menu="page.id"
                            @click.stop="toggleMenu('page-' + page.id)"
                            @keydown.shift.f10.prevent.stop="toggleMenu('page-' + page.id)"
                        ><span class="icon-dam-menu text-base" aria-hidden="true"></span></button>
                    </div>
                    <div v-if="openMenu === 'page-' + page.id" role="menu" class="absolute right-2 z-10 mt-1 rounded border border-gray-200 bg-white p-1 text-gray-800 shadow dark:border-gray-700 dark:bg-gray-800 dark:text-white" style="min-width: 11rem;" :data-web-page-menu-open="page.id">
                        <a v-if="page.state === 'ready'" role="menuitem" class="block rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" :href="routes.show + '/' + page.id + '/open'" target="_blank" rel="noopener" @click.stop>@{{ labels['open-full-screen'] }}</a>
                        <button v-if="can.share" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" :disabled="sharingId === page.id" :aria-busy="sharingId === page.id" @click.stop="copyShare(page)">@{{ sharingId === page.id ? labels['creating-link'] : labels['copy-share-link'] }}</button>
                        <button v-if="showMoveControls" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" @click.stop="openMovePages([page.id])">@{{ labels.browser['move-to'] }}</button>
                        <a v-if="can.download" role="menuitem" class="block rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" :href="routes.download + '/' + page.id" @click.stop>@{{ labels['download-original'] }}</a>
                        <button type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" @click.stop="setThumbnail(page)">@{{ labels.browser['set-thumbnail'] }}</button>
                        <button v-if="page.can_delete" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-gray-700" data-action="trash-page" v-on:click.stop="confirmTrashPage(page)">@{{ labels['move-to-trash'] }}</button>
                    </div>
                </div>
            </article>
        </div>

        {{-- List view: one row per page, same data attributes as the cards so selection, drag, keyboard
             and the menu all keep working; the thumbnail keeps 16:9 at a fixed width. --}}
        <div v-if="pages.data.length && view === 'list'" class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700" :class="{'opacity-60 pointer-events-none': busy}" data-web-pages-list>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th v-if="selectMode" class="w-8 px-2 py-2"></th>
                        <th class="px-3 py-2 text-start font-medium" colspan="2">@{{ labels.browser['col-name'] }}</th>
                        <th class="px-3 py-2 text-start font-medium">@{{ labels.browser['col-folder'] }}</th>
                        <th class="px-3 py-2 text-start font-medium">@{{ labels.browser['col-updated'] }}</th>
                        <th class="px-3 py-2 text-start font-medium">@{{ labels.browser['col-creator'] }}</th>
                        <th class="w-10 px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-700">
                    <tr
                        v-for="page in pages.data"
                        :key="'row-'+page.id"
                        tabindex="0"
                        class="group cursor-pointer bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800"
                        :class="{'bg-gray-100 dark:bg-gray-700': selected.has(page.id)}"
                        :aria-label="page.display_name"
                        :data-web-page="page.id"
                        :draggable="cardsDraggable"
                        @dragstart="onPageDragStart($event, page)"
                        @click="onCardClick($event, page)"
                        @keydown="onCardKeydown($event, {type: 'page', item: page})"
                    >
                        <td v-if="selectMode" class="px-2 py-2"><input type="checkbox" class="h-4 w-4" :checked="selected.has(page.id)" :aria-label="page.display_name" @click.stop="toggleSelect(page.id)" :data-select-page="page.id" /></td>
                        <td class="w-24 px-3 py-2">
                            <div class="relative aspect-video w-20 overflow-hidden rounded bg-gray-100 dark:bg-gray-800">
                                <img :src="page.state === 'ready' ? page.thumbnail_url : placeholderSvg" alt="" class="absolute inset-0 h-full w-full object-cover" loading="lazy" v-on:error="onThumbError" />
                            </div>
                        </td>
                        <td class="min-w-0 px-3 py-2">
                            <p class="truncate font-semibold text-gray-800 dark:text-white" :title="page.display_name">@{{ page.display_name }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="page.state === 'preparing'" class="text-amber-600" data-page-state="preparing">@{{ labels.preparing }}</span>
                                <span v-else-if="page.state === 'failed'" class="text-red-600" data-page-state="failed">@{{ page.error === 'disk_unsupported' ? labels['disk-unsupported'] : labels['bundle-failed'] }}</span>
                                <span v-else>@{{ trans(labels.versions, {count: page.versions_count}) }}</span>
                                <span v-if="page.has_active_share" class="ml-1 rounded bg-emerald-100 px-1 text-emerald-800">@{{ labels.shared }}</span>
                            </p>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-300">@{{ page.collection_path || page.library_directory || '' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-300" :title="page.updated_at || ''">@{{ relativeTime(page.updated_at) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-300">@{{ page.creator || '' }}</td>
                        <td class="relative px-2 py-2 text-right">
                            <button
                                type="button"
                                class="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"
                                aria-haspopup="menu"
                                :aria-expanded="openMenu === 'page-' + page.id"
                                :aria-label="trans(labels.browser['actions-for'], {name: page.display_name})"
                                :data-web-page-menu="page.id"
                                @click.stop="toggleMenu('page-' + page.id)"
                                @keydown.shift.f10.prevent.stop="toggleMenu('page-' + page.id)"
                            ><span class="icon-dam-menu text-base" aria-hidden="true"></span></button>
                            <div v-if="openMenu === 'page-' + page.id" role="menu" class="absolute right-2 z-10 mt-1 rounded border border-gray-200 bg-white p-1 text-left text-gray-800 shadow dark:border-gray-700 dark:bg-gray-800 dark:text-white" style="min-width: 11rem;" :data-web-page-menu-open="page.id">
                                <a v-if="page.state === 'ready'" role="menuitem" class="block rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" :href="routes.show + '/' + page.id + '/open'" target="_blank" rel="noopener" @click.stop>@{{ labels['open-full-screen'] }}</a>
                                <button v-if="can.share" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" :disabled="sharingId === page.id" :aria-busy="sharingId === page.id" @click.stop="copyShare(page)">@{{ sharingId === page.id ? labels['creating-link'] : labels['copy-share-link'] }}</button>
                                <button v-if="showMoveControls" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" @click.stop="openMovePages([page.id])">@{{ labels.browser['move-to'] }}</button>
                                <a v-if="can.download" role="menuitem" class="block rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" :href="routes.download + '/' + page.id" @click.stop>@{{ labels['download-original'] }}</a>
                                <button type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700" @click.stop="setThumbnail(page)">@{{ labels.browser['set-thumbnail'] }}</button>
                                <button v-if="page.can_delete" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-gray-700" data-action="trash-page" v-on:click.stop="confirmTrashPage(page)">@{{ labels['move-to-trash'] }}</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="pages.total > 0" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm text-gray-500 dark:text-gray-400" data-web-pages-pagination>
            <span data-page-range>@{{ pageRange }}</span>
            <div v-if="pages.last_page > 1" class="flex items-center gap-2">
                <button type="button" class="secondary-button py-1" :disabled="pages.current_page <= 1" @click="load({page: pages.current_page - 1})">&lsaquo; @{{ labels.browser.prev }}</button>
                <span class="tabular-nums">@{{ pages.current_page }} / @{{ pages.last_page }}</span>
                <button type="button" class="secondary-button py-1" :disabled="pages.current_page >= pages.last_page" @click="load({page: pages.current_page + 1})">@{{ labels.browser.next }} &rsaquo;</button>
            </div>
        </div>

        {{-- Whole-area file drop: dragging files anywhere over the browser stages them for Upload here
             into the level being viewed. Internal card drags carry their own MIME type and never
             trigger this overlay. --}}
        <div v-if="fileDragDepth > 0 && can.upload" class="absolute inset-0 z-10 flex items-center justify-center rounded-lg border-2 border-dashed border-primary-500 bg-white opacity-60 dark:bg-gray-900" data-web-pages-file-drop aria-hidden="true">
            <p class="text-lg font-semibold text-gray-800 dark:text-white">@{{ labels.browser['drop-files'] }}</p>
        </div>

        {{-- Every dialog is a real modal: the `dialog` watcher calls showModal(), so it sits in the
             top layer with the page behind it inert, and Esc arrives as the native cancel event.
             The <dialog> itself is the full-viewport overlay (sized inline: the UA dialog box
             styles are not Tailwind's), and a press-and-release on the overlay outside the panel
             is Cancel. --}}
        <dialog v-if="dialog === 'new'" :aria-label="labels.browser['new-folder']" data-dialog="new-folder" :style="overlayStyle" @cancel.prevent="closeDialog">
            <div class="flex h-full w-full items-center justify-center p-4" @mousedown="onOverlayMousedown" @click="onOverlayClick">
                <section class="w-full max-w-md rounded-xl bg-white p-5 text-gray-800 shadow-2xl dark:bg-gray-900 dark:text-white">
                    <form @submit.prevent="submitNewFolder">
                        <label class="block text-sm">@{{ labels.browser['new-folder'] }}
                            <input ref="dialogInput" class="mt-1 w-full rounded border bg-white px-2 py-1 text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-white" v-model="dialogName" maxlength="{{ \Mothership\DAM\Services\WebCollectionService::NAME_MAX }}" autofocus />
                        </label>
                        <p v-if="dialogError" class="mt-1 text-sm text-red-600" data-dialog-error>@{{ dialogError }}</p>
                        <div class="mt-3 flex justify-end gap-2">
                            <button type="button" class="secondary-button py-1" @click="closeDialog">@{{ labels.browser.cancel }}</button>
                            <button type="submit" class="primary-button py-1" :disabled="busyAction">@{{ labels.browser.save }}</button>
                        </div>
                    </form>
                </section>
            </div>
        </dialog>

        <dialog v-if="dialog === 'delete'" :aria-label="labels.browser.delete" data-dialog="delete" :style="overlayStyle" @cancel.prevent="closeDialog">
            <div class="flex h-full w-full items-center justify-center p-4" @mousedown="onOverlayMousedown" @click="onOverlayClick">
                <section class="w-full max-w-md rounded-xl bg-white p-5 text-gray-800 shadow-2xl dark:bg-gray-900 dark:text-white">
                    <p data-dialog-text>@{{ trans(labels.browser['delete-confirm'], {children: dialogTarget?.children_count ?? 0, pages: dialogTarget?.page_count ?? 0, target: deleteTargetName}) }}</p>
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" class="secondary-button py-1" @click="closeDialog">@{{ labels.browser.cancel }}</button>
                        <button type="button" class="primary-button py-1" data-action="confirm-delete" :disabled="busyAction" @click="submitDelete">@{{ labels.browser.delete }}</button>
                    </div>
                </section>
            </div>
        </dialog>

        <dialog v-if="dialog === 'trash-page'" :aria-label="labels['move-to-trash']" data-dialog="trash-page" :style="overlayStyle" v-on:cancel.prevent="cancelTrashPage">
            <div class="flex h-full w-full items-center justify-center p-4">
                <section class="w-full max-w-md rounded-xl bg-white p-5 text-gray-800 shadow-2xl dark:bg-gray-900 dark:text-white">
                    <p data-dialog-text>@{{ trans(labels['trash-page-confirm'], {name: dialogTarget?.display_name}) }}</p>
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" class="secondary-button py-1" autofocus :disabled="busyAction" v-on:click="cancelTrashPage">@{{ labels.browser.cancel }}</button>
                        <button type="button" class="primary-button py-1" data-action="confirm-trash-page" :disabled="busyAction" v-on:click="submitTrashPage">@{{ labels['move-to-trash'] }}</button>
                    </div>
                </section>
            </div>
        </dialog>

        {{-- Share pages in folder: a snapshot of the pages directly in this folder, one ordinary public link each. Nothing is
             created until Create public links; Escape and Cancel before that create nothing, and Stop ends a run between pages. --}}
        <dialog v-if="dialog === 'share-folder' && folderShare" :aria-label="labels.browser['share-folder-title']" data-dialog="share-folder" :style="overlayStyle" v-on:cancel.prevent="cancelShareFolder">
            <div class="flex h-full w-full items-center justify-center p-4">
                <section class="flex max-h-full w-full max-w-lg flex-col rounded-xl bg-white p-5 text-gray-800 shadow-2xl dark:bg-gray-900 dark:text-white" :aria-busy="folderShare.state === 'loading' || folderShare.state === 'sharing'">
                    <p class="min-w-0 break-words font-semibold" data-share-folder-title>@{{ labels.browser['share-folder-title'] }}: @{{ folderShare.name }}</p>

                    <p v-if="folderShare.state === 'loading'" class="mt-3 text-sm text-gray-500 dark:text-gray-400" role="status" data-share-folder-state="loading">@{{ labels.browser['share-folder-loading'] }}</p>
                    <p v-else-if="folderShare.state === 'failed'" class="mt-3 text-sm text-red-600" role="alert" data-share-folder-state="failed">@{{ labels.browser['share-folder-failed'] }}</p>
                    <p v-else-if="folderShare.state === 'empty'" class="mt-3 text-sm" role="status" data-share-folder-state="empty">@{{ labels.browser['share-folder-empty'] }}</p>

                    <template v-else-if="folderShare.state === 'confirm'">
                        <p class="mt-3 text-sm font-semibold" data-share-folder-count>@{{ shareFolderCountLabel }}</p>
                        <p class="mt-1 text-sm" data-share-folder-explain>@{{ labels.browser['share-folder-explain'] }}</p>
                        <p v-if="folderShare.keepCount" class="mt-1 text-sm" data-share-folder-keep>@{{ trans(labels.browser['share-folder-keep'], {count: folderShare.keepCount}) }}</p>
                        <p v-if="folderShare.omitted" class="mt-1 text-sm text-amber-700 dark:text-amber-300" data-share-folder-omitted>@{{ trans(labels.browser['share-folder-omitted'], {omitted: folderShare.omitted}) }}</p>
                        <ul class="mt-2 min-h-0 overflow-y-auto rounded border border-gray-200 p-2 text-sm dark:border-gray-700" style="max-height: 12rem;" ref="shareFolderList" v-on:scroll="checkShareFolderMore" data-share-folder-pages>
                            <li v-for="row in folderShare.rows" :key="'share-folder-page-' + row.id" class="flex flex-wrap items-baseline justify-between gap-x-2 py-0.5">
                                <span class="min-w-0 truncate" :title="row.name">@{{ row.name }}</span>
                                <span v-if="row.reuse" class="min-w-0 text-xs text-gray-500 dark:text-gray-400" data-share-folder-kept>@{{ shareFolderKeptLabel(row.reuse) }}</span>
                            </li>
                        </ul>
                        <p v-if="folderShare.moreBelow" class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-share-folder-more>@{{ labels.browser['share-folder-more'] }}</p>
                    </template>

                    <template v-else>
                        <p class="mt-3 text-sm" role="status" data-share-folder-progress>@{{ folderShare.state === 'sharing' ? trans(labels.browser['share-folder-progress'], {done: folderShare.done, total: folderShare.rows.length}) : shareFolderSummary }}</p>
                        <p v-if="folderShare.stopped" class="mt-1 text-sm text-amber-700 dark:text-amber-300" data-share-folder-stopped>@{{ labels.browser['share-folder-stopped'] }}</p>
                        <ul class="mt-2 min-h-0 overflow-y-auto rounded border border-gray-200 p-2 text-sm dark:border-gray-700" style="max-height: 15rem;" ref="shareFolderList" v-on:scroll="checkShareFolderMore" data-share-folder-results>
                            <li v-for="row in folderShare.rows" :key="'share-folder-result-' + row.id" class="py-1" :data-share-folder-result="row.id" :data-status="row.status">
                                <span class="block break-words font-medium">@{{ row.name }}</span>
                                <span class="block break-words text-xs" :class="{'text-emerald-800 dark:text-emerald-300': row.status === 'created' || row.status === 'kept', 'text-amber-700 dark:text-amber-300': row.status === 'changed' || row.status === 'not-attempted', 'text-gray-500 dark:text-gray-400': row.status === 'pending' && !row.working, 'font-semibold text-gray-800 dark:text-white': row.status === 'pending' && row.working, 'text-red-600': row.status === 'not-allowed' || row.status === 'failed' || row.status === 'uncertain'}">@{{ shareFolderRowLabel(row) }}</span>
                            </li>
                        </ul>
                        <p v-if="folderShare.moreBelow" class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-share-folder-more>@{{ labels.browser['share-folder-more'] }}</p>
                        <div v-if="folderShare.copyFailed" class="mt-2">
                            <label class="block text-xs text-gray-500 dark:text-gray-400">@{{ labels.browser['share-folder-links'] }}
                                <textarea ref="shareFolderLinks" class="mt-1 w-full rounded border bg-white px-2 py-1 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-white" rows="4" readonly :value="folderShare.linksText" v-on:focus="$event.target.select()"></textarea>
                            </label>
                        </div>
                    </template>

                    <div class="mt-3 flex flex-wrap justify-end gap-2">
                        <button v-if="folderShare.state === 'sharing'" type="button" class="secondary-button py-1" data-action="share-folder-stop" data-share-folder-focus :disabled="folderShare.stop" v-on:click="cancelShareFolder">@{{ labels.browser['share-folder-stop'] }}</button>
                        <template v-else-if="folderShare.state === 'done'">
                            <button v-if="folderShare.linksText" type="button" class="secondary-button py-1" data-action="share-folder-copy" v-on:click="copyFolderShareLinks">@{{ labels.browser['share-folder-copy-links'] }}</button>
                            <button type="button" class="primary-button py-1" data-action="share-folder-close" data-share-folder-focus v-on:click="cancelShareFolder">@{{ labels.browser.close }}</button>
                        </template>
                        <template v-else>
                            <button type="button" class="secondary-button py-1" data-action="share-folder-cancel" data-share-folder-focus v-on:click="cancelShareFolder">@{{ labels.browser.cancel }}</button>
                            <button v-if="folderShare.state === 'confirm'" type="button" class="primary-button py-1" data-action="share-folder-create" v-on:click="startShareFolder">@{{ labels.browser['share-folder-create'] }}</button>
                        </template>
                    </div>
                </section>
            </div>
        </dialog>

        <dialog v-if="dialog === 'move'" :aria-label="labels.browser['move-to']" data-dialog="move" :style="overlayStyle" @cancel.prevent="closeDialog">
            <div class="flex h-full w-full items-center justify-center p-4" @mousedown="onOverlayMousedown" @click="onOverlayClick">
                <section class="flex max-h-full w-full max-w-md flex-col rounded-xl bg-white p-5 text-gray-800 shadow-2xl dark:bg-gray-900 dark:text-white">
                    <p class="font-semibold">@{{ labels.browser['move-to'] }}</p>
                    <ul class="mt-2 min-h-0 overflow-y-auto">
                        <li><button type="button" class="flex w-full items-center gap-2 rounded px-2 py-1 text-left hover:bg-gray-100 dark:hover:bg-gray-800" @click="submitMove(null)"><span class="icon-dam-link text-gray-400" aria-hidden="true"></span>@{{ labels.browser.unfiled }}</button></li>
                        <li v-for="node in moveTree" :key="'move-'+node.id">
                            <button type="button" class="flex w-full items-center gap-2 rounded px-2 py-1 text-left hover:bg-gray-100 disabled:opacity-50 dark:hover:bg-gray-800" :style="{paddingInlineStart: (0.5 + node.ancestorIds.length) + 'rem'}" :disabled="moveDisabled(node)" @click="submitMove(node.id)"><span class="icon-dam-folder text-amber-600" aria-hidden="true"></span>@{{ node.name }}</button>
                        </li>
                    </ul>
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" class="secondary-button py-1" @click="closeDialog">@{{ labels.browser.cancel }}</button>
                    </div>
                </section>
            </div>
        </dialog>

        <dialog v-if="dialog === 'upload'" :aria-label="labels.browser['upload-here']" data-dialog="upload" :style="overlayStyle" @cancel.prevent="closeDialog">
            <div class="flex h-full w-full items-center justify-center p-4" @mousedown="onOverlayMousedown" @click="onOverlayClick">
                <section class="flex max-h-full w-full max-w-md flex-col rounded-xl bg-white p-5 text-gray-800 shadow-2xl dark:bg-gray-900 dark:text-white">
                    <p class="font-semibold">@{{ labels.browser['upload-here'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400" data-upload-into>@{{ trans(labels.browser['upload-into'], {folder: breadcrumb[breadcrumb.length - 1]?.name ?? labels.title}) }}</p>
                    <label v-if="uploadDirectories.length > 1" class="mt-2 block text-sm">@{{ labels.browser['upload-target'] }}
                        <select class="custom-select mt-1 w-full bg-white py-1 pe-8 text-gray-800 dark:bg-gray-800 dark:text-white" v-model.number="uploadDirectoryId" @change="rememberUploadDirectory">
                            <option v-for="dir in uploadDirectories" :key="dir.id" :value="dir.id">@{{ dir.name }}</option>
                        </select>
                    </label>
                    <p v-else-if="uploadDirectories.length === 1" class="mt-2 text-sm text-gray-500 dark:text-gray-400" data-upload-destination>@{{ labels.browser['upload-target'] }} @{{ uploadDirectories[0].name }}</p>
                    <div
                        class="mt-2 flex flex-col items-center justify-center gap-2 rounded border-2 border-dashed border-gray-200 p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400"
                        :class="{'ring-2 ring-primary-500 border-primary-500': uploadDragOver}"
                        data-upload-dropzone
                        @dragover.prevent="uploadDragOver = true"
                        @dragleave="uploadDragOver = false"
                        @drop.prevent="onUploadDrop($event)"
                    >
                        <span class="icon-dam-upload text-2xl" aria-hidden="true"></span>
                        <p>@{{ labels.browser['drop-accepted'] }}</p>
                        <input type="file" multiple accept=".html,.htm,.zip" class="text-xs" @change="queueUploads($event.target.files); $event.target.value = ''" />
                    </div>
                    <ul class="mt-2 min-h-0 overflow-y-auto text-sm">
                        <li v-for="file in uploadQueue" :key="'queued-'+file.name" class="flex items-center justify-between gap-2 py-0.5" data-upload-queued>
                            <span class="truncate">@{{ file.name }}</span>
                            <button type="button" class="text-xs text-gray-500 hover:text-red-600" :aria-label="labels.browser.remove + ' ' + file.name" @click="unqueueUpload(file)"><span class="icon-dam-close" aria-hidden="true"></span></button>
                        </li>
                        <li v-for="row in uploadRows" :key="row.name" class="flex items-center justify-between gap-2 py-0.5" data-upload-row>
                            <span class="truncate">@{{ row.name }}</span>
                            <span class="shrink-0 text-xs" :class="{'text-emerald-800': row.ok === true, 'text-red-600': row.ok === false, 'text-amber-600': row.ok === null}">@{{ row.status }}</span>
                        </li>
                    </ul>
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" class="secondary-button py-1" @click="closeDialog">@{{ uploadRows.length && !uploadQueue.length ? labels.browser.close : labels.browser.cancel }}</button>
                        <button type="button" class="primary-button py-1" data-action="upload-start" :disabled="!uploadQueue.length || uploadBusy || !uploadDirectoryId" @click="startUpload">@{{ uploadBusy ? labels.browser.uploading : labels.browser.upload }}</button>
                    </div>
                </section>
            </div>
        </dialog>

        <dialog v-if="dialog === 'preview'" :aria-label="labels.browser.preview" data-dialog="preview" :style="overlayStyle" @cancel.prevent="closeDialog">
            <div class="flex h-full w-full items-center justify-center p-4" @mousedown="onOverlayMousedown" @click="onOverlayClick">
                <section class="flex flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-gray-900" style="width: 100%; max-width: 1600px; height: 100%;" data-preview-panel>
                    {{-- The title keeps at least 12rem and the buttons wrap under it on a phone.
                         Close takes initial focus: the first button would otherwise be Copy
                         share link, which creates a public link on a stray Enter. --}}
                    <header class="flex shrink-0 flex-wrap items-center gap-3 border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                        <p class="min-w-0 truncate text-sm font-semibold text-gray-800 dark:text-white" style="flex: 1 1 12rem;" :title="previewPage?.display_name">@{{ previewPage?.display_name }}</p>
                        <a v-if="previewPage?.state === 'ready' && previewOpens" class="secondary-button py-1" :href="previewOpenUrl" target="_blank" rel="noopener" data-action="open-full-screen">@{{ labels['open-full-screen'] }}</a>
                        {{-- Held in place, not removed, while the pinned file does not open (changed, gone, being checked, or no page): the rows below must not jump. --}}
                        <button v-else-if="previewPage?.state === 'ready'" type="button" class="secondary-button py-1" disabled data-action="open-full-screen-unavailable">@{{ labels['open-full-screen'] }}</button>
                        {{-- No link is minted for a pinned file that does not open: the same states that hold full screen back. --}}
                        <button v-if="can.share" type="button" class="secondary-button py-1" data-action="preview-copy-share-link" :disabled="sharingId === previewPage?.id || !! previewChange" :aria-busy="sharingId === previewPage?.id" @click="copyShare(previewPage)">@{{ sharingId === previewPage?.id ? labels['creating-link'] : labels['copy-share-link'] }}</button>
                        <button v-if="previewPage?.can_delete" type="button" class="secondary-button py-1" data-action="trash-page" v-on:click="confirmTrashPage(previewPage)">@{{ labels['move-to-trash'] }}</button>
                        <button type="button" class="secondary-button py-1" data-action="close-preview" autofocus @click="closeDialog">@{{ labels.browser.close }}</button>
                    </header>
                    {{-- The stack's files, read-only: choosing one only changes what is previewed, never the stack. The count is of
                         files, not revisions. Shown only for a stack of more than one file. --}}
                    <div v-if="previewMembers.length > 1 || (previewPin && previewChange)" class="flex shrink-0 flex-wrap items-center gap-2 border-b border-gray-200 px-5 py-2 text-sm dark:border-gray-700" data-preview-members>
                        <button type="button" class="secondary-button py-1" data-action="member-prev" :aria-label="labels.browser['member-prev']" :title="labels.browser['member-prev']" :disabled="previewMemberIndex <= 0" @click="stepMember(-1)"><span class="icon-left" aria-hidden="true"></span></button>
                        {{-- On a phone the strip takes its own row below the arrows and the position, and scrolls sideways. --}}
                        <nav class="flex min-w-0 items-center gap-1 overflow-x-auto" :class="isNarrow ? 'order-last basis-full' : 'flex-1'" :aria-label="labels.browser['member-select']" data-preview-member-strip>
                            {{-- The pinned file's slot is gone: its name stays, marked, so the strip never names another file for it. --}}
                            <button
                                v-if="previewPin && previewMemberIndex < 0"
                                type="button"
                                class="primary-button max-w-[12rem] shrink-0 py-1 line-through"
                                aria-pressed="true"
                                aria-disabled="true"
                                data-preview-member-removed
                                :title="previewPin.name"
                            ><span class="block truncate">@{{ previewPin.name }}</span></button>
                            <button
                                v-for="member in previewMembers"
                                :key="'member-' + member.attachment_id"
                                type="button"
                                class="max-w-[12rem] shrink-0 py-1"
                                :class="previewPin?.attachment === member.attachment_id ? 'primary-button' : 'secondary-button'"
                                :aria-pressed="previewPin?.attachment === member.attachment_id ? 'true' : 'false'"
                                :data-preview-member="member.attachment_id"
                                :title="member.name"
                                @click="selectMember(member)"
                            ><span class="block truncate">@{{ member.name }}</span></button>
                        </nav>
                        <span class="shrink-0 text-gray-600 dark:text-gray-300" data-preview-member-position>@{{ previewMemberPosition }}</span>
                        <button type="button" class="secondary-button py-1" data-action="member-next" :aria-label="labels.browser['member-next']" :title="labels.browser['member-next']" :disabled="previewMemberIndex < 0 || previewMemberIndex >= previewMembers.length - 1" @click="stepMember(1)"><span class="icon-right" aria-hidden="true"></span></button>
                    </div>
                    <p v-if="previewMembersState === 'failed'" class="shrink-0 border-b border-gray-200 px-5 py-2 text-sm text-amber-700 dark:border-gray-700 dark:text-amber-300" role="status" data-preview-members-failed>@{{ labels.browser['members-failed'] }}</p>
                    <div class="min-h-0 flex-1">
                        <p v-if="previewPage && previewMembersState === 'loading'" class="p-5 text-sm text-gray-500" role="status" data-preview-members-loading>@{{ labels.browser['members-loading'] }}</p>
                        <v-dam-web-page-frame
                            v-else-if="previewPage"
                            :key="previewFrameKey"
                            :asset-id="previewPage.id"
                            :file-name="previewFrameName"
                            :file-id="previewPin ? previewPin.file : null"
                            :checksum="previewPin ? previewPin.checksum : ''"
                            :status-kind="previewChange || ''"
                            v-on:dam-web-member-changed="onMemberChanged"
                        >
                            {{-- The one message about a changed file is the stage's; the choice to open its newer file sits under it. --}}
                            <template v-slot:status-action>
                                <button v-if="previewChange === 'changed'" type="button" class="secondary-button mt-3 py-1" data-action="show-current-member" @click="showCurrentMember">@{{ labels['member-show-current'] }}</button>
                                <button v-else-if="previewChange === 'unknown'" type="button" class="secondary-button mt-3 py-1" data-action="check-member-again" @click="checkMemberAgain">@{{ labels['member-check-again'] }}</button>
                            </template>
                        </v-dam-web-page-frame>
                    </div>
                </section>
            </div>
        </dialog>

        {{-- Shown once a new link exists: iOS Safari drops the tap's clipboard permission across the
             network wait, so copying needs a fresh tap on this button. Kept out of `dialog` so it can
             stack over the preview. --}}
        <dialog v-if="shareUrl" :aria-label="labels['share-link-title']" data-share-dialog :style="overlayStyle" @cancel.prevent="closeShareDialog">
            <div class="flex h-full w-full items-center justify-center p-4" @mousedown="onOverlayMousedown" @click="onShareOverlayClick">
                <section class="w-full max-w-md rounded-xl bg-white p-5 text-gray-800 shadow-2xl dark:bg-gray-900 dark:text-white">
                    <p class="text-sm font-semibold">@{{ labels['share-link-title'] }}</p>
                    <input ref="shareInput" class="mt-2 w-full rounded border bg-white px-2 py-1 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-white" :value="shareUrl" readonly @focus="$event.target.select()" />
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" class="secondary-button py-1" @click="closeShareDialog">@{{ labels.browser.close }}</button>
                        <button type="button" class="primary-button py-1" data-action="copy-share-url" autofocus @click="copyShareUrl">@{{ labels.copy }}</button>
                    </div>
                </section>
            </div>
        </dialog>
        {{-- A public link opens without signing in: creating one is confirmed here, stacked above the page. --}}
        <dialog v-if="shareConfirmPage" :aria-label="labels.browser['share-confirm']" data-share-confirm :style="overlayStyle" @cancel.prevent="cancelShareConfirm">
            <div class="flex h-full w-full items-center justify-center p-4">
                <section class="w-full max-w-md rounded-xl bg-white p-5 text-gray-800 shadow-2xl dark:bg-gray-900 dark:text-white">
                    <p class="text-sm">@{{ labels.browser['share-confirm'] }}</p>
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" class="secondary-button py-1" data-action="share-confirm-cancel" autofocus @click="cancelShareConfirm">@{{ labels.browser.cancel ?? labels.browser.close }}</button>
                        <button type="button" class="primary-button py-1" data-action="share-confirm-create" @click="createShare">@{{ labels.browser['share-confirm-create'] }}</button>
                    </div>
                </section>
            </div>
        </dialog>
    </div>
</script>
<script type="module">
    // Module scope, not a component method: called from data() before the instance (and its
    // methods) exist. Guarded try/catch because localStorage can throw (private browsing,
    // storage disabled) and a blocked read must never break mounting the browser.
    function readRememberedUploadDirectoryId(uploadDirectories) {
        try {
            const stored = Number(window.localStorage.getItem('dam.web-pages.upload-directory'));
            return uploadDirectories.some((dir) => dir.id === stored) ? stored : null;
        } catch (e) {
            return null;
        }
    }

    function readRememberedView() {
        try {
            const stored = window.localStorage.getItem('dam.web-pages.view');
            return stored === 'list' ? 'list' : 'grid';
        } catch (e) {
            return 'grid';
        }
    }

    // Mirrors WebPageController::listingFilters(): anything else falls back to newest.
    const SORTS = ['newest', 'oldest', 'name_asc', 'name_desc'];

    app.component('v-dam-web-pages-browser', {
        template: '#v-dam-web-pages-browser-template',
        props: {
            initial: { type: Object, required: true },
            routes: { type: Object, required: true },
            can: { type: Object, required: true },
            labels: { type: Object, required: true },
            notice: { type: String, default: null },
            uploadDirectories: { type: Array, default: () => [] },
        },
        data() {
            return {
                collection: this.initial.collection ?? null,
                breadcrumb: this.initial.breadcrumb ?? [],
                children: this.initial.children ?? [],
                pages: this.initial.pages ?? { data: [], current_page: 1, last_page: 1, total: 0 },
                searchMode: this.initial.search_mode ?? false,
                filters: {
                    owner: this.initial.filters?.owner ?? 'everyone',
                    q: this.initial.filters?.q ?? '',
                    sort: SORTS.includes(this.initial.filters?.sort) ? this.initial.filters.sort : 'newest',
                },
                view: readRememberedView(),
                selectMode: false,
                selected: new Set(),
                dragOverId: null,
                fileDragDepth: 0,
                dialog: null,
                sharingId: null,
                shareUrl: null,
                shareConfirmPage: null,
                shareReturnFocus: null,
                // The Share pages in folder run: null when closed, else { id, name, state: loading|failed|empty|confirm|sharing|done, rows, ... }.
                folderShare: null,
                dialogName: '',
                dialogError: null,
                dialogTarget: null,
                trashReturnTo: null,
                dialogIds: [],
                moveTree: [],
                busy: false,
                busyAction: false,
                loadError: false,
                // Monotonic request counter: a response is applied only when it belongs to the most
                // recent load(), so a slow folder listing cannot overwrite a newer search result.
                loadSeq: 0,
                loadAbort: null,
                openMenu: null,
                menuFocus: null,
                // The card a folder menu was opened from; Escape and Tab hand focus back to it.
                menuOpener: null,
                renaming: null,
                renameEl: null,
                announcement: '',
                previewPage: null,
                // The previewed page's stack members (canonical order), the exact file on display (null while the stack is
                // one file: the legacy page), and what a one-time refresh found out about it.
                previewMembers: [],
                previewMembersState: 'idle',
                previewPin: null,
                previewChange: null,
                previewRefreshed: false,
                previewSeq: 0,
                previewNonce: 0,
                previewAbort: null,
                returnFocus: null,
                overlayStyle: 'width: 100%; height: 100%; max-width: 100%; max-height: 100%; margin: 0; padding: 0; border: 0; background: rgba(3, 7, 18, 0.72);',
                uploadDirectoryId: readRememberedUploadDirectoryId(this.uploadDirectories) ?? this.uploadDirectories[0]?.id ?? null,
                uploadDragOver: false,
                uploadRows: [],
                uploadQueue: [],
                uploadBusy: false,
                pollHandle: null,
                pollCount: 0,
                searchTimer: null,
                placeholderSvg: 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="16" height="9"%3E%3Crect width="16" height="9" fill="%23e5e7eb"/%3E%3C/svg%3E',
                isTouch: typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches,
                // The spec's mobile width (<= 640px), where the breadcrumb collapses to "‹ Parent".
                // matchMedia rather than a Tailwind variant: no class for this breakpoint exists
                // in the committed CSS bundles.
                isNarrow: typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 640px)').matches,
                overlayPressed: false,
                overlayOpenedAt: 0,
            };
        },
        computed: {
            // The destination named in the delete-confirm dialog: deleting a child folder
            // reparents its own children/pages to the collection CURRENTLY being browsed (the
            // deleted folder's parent), which is the last breadcrumb entry -- never the
            // grandparent -- and only "Unfiled" when that current collection is the root.
            deleteTargetName() {
                return this.collection ? this.breadcrumb[this.breadcrumb.length - 1]?.name : this.labels.browser.unfiled;
            },
            showMoveControls() {
                return this.can.organize;
            },
            // "Share 12 pages": the label is a `singular|plural` pair, like page-count.
            shareFolderCountLabel() {
                const count = this.folderShare?.rows?.length ?? 0;
                const [one, many = one] = (this.labels.browser['share-folder-confirm'] || '').split('|');

                return this.trans(count === 1 ? one : many, { count });
            },
            shareFolderSummary() {
                const rows = this.folderShare?.rows ?? [];
                const created = rows.filter((row) => row.status === 'created').length;
                const kept = rows.filter((row) => row.status === 'kept').length;
                // An uncertain write may have happened, so it is counted on its own, never as "not shared".
                const unknown = rows.filter((row) => row.status === 'uncertain').length;
                const failed = rows.length - created - kept - unknown;

                return unknown
                    ? this.trans(this.labels.browser['share-folder-unknown'], { created, kept, failed, unknown })
                    : this.trans(this.labels.browser['share-folder-summary'], { created, kept, failed });
            },
            previewMemberIndex() {
                const pin = this.previewPin;
                return pin ? this.previewMembers.findIndex((member) => member.attachment_id === pin.attachment) : -1;
            },
            // "File 2 of 4": files in the stack, never revisions. Nothing for a pinned file that is no longer in it.
            previewMemberPosition() {
                if (this.previewMemberIndex < 0) return '';

                return this.trans(this.labels.browser['member-position'], { position: this.previewMemberIndex + 1, count: this.previewMembers.length });
            },
            // The frame remounts (and so abandons its requests) whenever the displayed file changes.
            previewFrameKey() {
                const pin = this.previewPin;
                return 'web-' + this.previewPage?.id + '-' + (pin ? pin.file + '-' + pin.checksum : 'legacy') + '-' + this.previewNonce;
            },
            previewFrameName() {
                return this.previewPin?.name || this.previewPage?.file_name || '';
            },
            // Whether the displayed file opens as a page: the legacy page (no pin), or a pinned file that is current and that the
            // server lists as a page candidate (its grant answers 422 `unsupported` for exactly the others). Not while it changed,
            // went away or is being checked (previewChange).
            previewOpens() {
                const pin = this.previewPin;

                return !this.previewChange && (!pin || pin.candidate);
            },
            // Full screen opens exactly the displayed file.
            previewOpenUrl() {
                const base = this.routes.show + '/' + this.previewPage?.id + '/open';
                const pin = this.previewPin;
                return pin ? base + '?file=' + encodeURIComponent(pin.file) + '&checksum=' + encodeURIComponent(pin.checksum) : base;
            },
            cardsDraggable() {
                return this.can.organize && !this.isTouch;
            },
            collapseBreadcrumb() {
                return this.isNarrow && this.breadcrumb.length > 1;
            },
            hasContent() {
                return this.pages.data.length > 0 || (!this.searchMode && this.children.length > 0);
            },
            sortOptions() {
                return SORTS.map((value) => ({ value, label: this.labels.browser['sort-' + value.replace('_', '-')] || value }));
            },
            // "Showing 1–24 of 57": the server paginates 24 per page; the last page is shorter.
            pageRange() {
                // A full page tells the page size directly; on the (shorter) last page it is what
                // the earlier pages held between them.
                const { total, last_page: lastPage, current_page: currentPage, data } = this.pages;
                const perPage = currentPage < lastPage ? data.length : (lastPage > 1 ? (total - data.length) / (lastPage - 1) : total);
                const from = total === 0 ? 0 : (currentPage - 1) * Math.max(1, perPage) + 1;
                const to = Math.min(this.pages.total, from + this.pages.data.length - 1);
                return this.trans(this.labels.browser.showing, { from, to, total: this.pages.total });
            },
        },
        watch: {
            // A dialog renders only while `dialog` names it; once mounted it is promoted to a real
            // modal. Focus returns to whatever opened it (the card, a toolbar button) on close; a
            // kebab menu item disappears with its menu, so focus returns to that menu's trigger.
            dialog(name, previous) {
                if (name === 'preview' && previous !== 'preview') this.loadPreviewMembers(this.previewPage);
                else if (previous === 'preview' && name !== 'preview') this.resetPreviewMembers();
                if (name && !previous) {
                    const origin = document.activeElement;
                    const card = origin?.closest?.('[role="menu"]') ? origin.closest('[data-web-collection],[data-web-page]') : null;
                    this.returnFocus = card?.querySelector('[aria-haspopup="menu"]') ?? origin;
                }
                if (!name) {
                    const opener = this.returnFocus;
                    this.returnFocus = null;
                    this.$nextTick(() => { if (opener?.isConnected) opener.focus?.(); });
                    return;
                }
                this.overlayOpenedAt = performance.now();
                this.$nextTick(() => {
                    const modal = this.$el?.querySelector?.('dialog[data-dialog]');
                    if (!modal || modal.open) return;
                    if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', '');
                });
            },
        },
        methods: {
            // "1 page" / "N pages": the label is a `singular|plural` pair.
            pageCountLabel(child) {
                const [one, many = one] = (this.labels.browser['page-count'] || '').split('|');

                const count = child.total_page_count ?? child.page_count;

                return this.trans(count === 1 ? one : many, { count });
            },
            trans(template, params = {}) {
                // Longest placeholder first, so `:to` never eats the front of `:total`.
                return Object.entries(params).sort(([a], [b]) => b.length - a.length).reduce((text, [key, value]) => text.replaceAll(':' + key, value), template || '');
            },
            // Admin-shell toast plus the live region; a no-op toast where the flash group is not
            // mounted (tests, previews).
            notify(type, message) {
                if (!message) return;
                this.announcement = message;
                this.$emitter?.emit?.('add-flash', { type, message });
            },
            listingQuery(params = {}) {
                const collection = params.collection !== undefined ? params.collection : this.collection;
                const query = new URLSearchParams();
                if (collection) query.set('collection', collection);
                if (this.filters.owner !== 'everyone') query.set('owner', this.filters.owner);
                if (this.filters.q) query.set('q', this.filters.q);
                if (this.filters.sort !== 'newest') query.set('sort', this.filters.sort);
                if (params.page && params.page > 1) query.set('page', params.page);
                return query;
            },
            /**
             * Fetch one browser level. `params.history` is 'push' (a folder change the Back button
             * should undo), 'replace' (filters, paging) or 'none' (popstate, poll, retry). Only the
             * most recent request may apply its response; earlier in-flight ones are aborted and
             * their late responses ignored.
             */
            async load(params = {}) {
                const seq = ++this.loadSeq;
                this.loadAbort?.abort?.();
                const controller = typeof AbortController === 'function' ? new AbortController() : null;
                this.loadAbort = controller;
                this.busy = true;
                this.loadError = false;
                this.openMenu = null;
                const query = this.listingQuery(params);
                const url = this.routes.listing + '?' + query.toString();
                try {
                    const response = await this.request(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller?.signal });
                    if (seq !== this.loadSeq) return;
                    if (!response.ok) throw new Error('listing ' + response.status);
                    const body = await response.json();
                    if (seq !== this.loadSeq) return;
                    this.applyListing(body);
                    this.syncHistory(params.history ?? 'replace', query);
                } catch (error) {
                    if (error?.name === 'AbortError' || seq !== this.loadSeq) return;
                    this.loadError = true;
                } finally {
                    if (seq === this.loadSeq) this.busy = false;
                }
            },
            applyListing(body) {
                this.collection = body.collection ?? null;
                this.breadcrumb = body.breadcrumb ?? [];
                this.children = body.children ?? [];
                this.pages = body.pages ?? { data: [], current_page: 1, last_page: 1, total: 0 };
                this.searchMode = body.search_mode ?? false;
                if (body.missing_collection) this.notify('warning', this.labels.browser['missing-collection']);
            },
            // The browser seams the DOM smoke test stubs: network and history.
            request(url, options) {
                return fetch(url, options);
            },
            historyApi() {
                return typeof window !== 'undefined' ? (window.history ?? null) : null;
            },
            syncHistory(mode, query) {
                const history = this.historyApi();
                if (mode === 'none' || !history) return;
                // The filters travel with the entry, so Back restores the listing the URL names.
                const state = { webPages: { collection: this.collection, page: this.pages.current_page, owner: this.filters.owner, q: this.filters.q, sort: this.filters.sort } };
                const url = query.toString() ? '?' + query.toString() : (typeof window !== 'undefined' ? (window.location?.pathname ?? '') : '');
                if (mode === 'push' && history.pushState) history.pushState(state, '', url);
                else if (history.replaceState) history.replaceState(state, '', url);
            },
            onPopState(event) {
                const state = event?.state?.webPages;
                if (!state) return;
                this.filters.owner = state.owner === 'me' ? 'me' : 'everyone';
                this.filters.q = state.q ?? '';
                this.filters.sort = SORTS.includes(state.sort) ? state.sort : 'newest';
                return this.load({ collection: state.collection ?? '', page: state.page ?? 1, history: 'none' });
            },
            openCollection(id) {
                this.filters.q = '';
                this.clearSelection();
                return this.load({ collection: id ?? '', page: 1, history: 'push' });
            },
            onSearchInput() {
                clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => this.load({ page: 1 }), 250);
            },
            clearSearch() {
                this.filters.q = '';
                this.load({ page: 1 });
            },
            onSortChange() {
                this.load({ page: 1 });
            },
            setView(view) {
                this.view = view === 'list' ? 'list' : 'grid';
                this.openMenu = null;
                try { window.localStorage.setItem('dam.web-pages.view', this.view); } catch (e) { /* nothing to remember */ }
            },
            relativeTime(iso) {
                if (!iso) return '';
                const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
                const steps = [[60, 'second'], [60, 'minute'], [24, 'hour'], [30, 'day'], [12, 'month'], [Infinity, 'year']];
                let value = seconds;
                let unit = 'second';
                for (const [size, name] of steps) {
                    if (Math.abs(value) < size) { unit = name; break; }
                    value = Math.round(value / size);
                    unit = name;
                }
                if (Math.abs(value) < 5 && unit === 'second') return this.labels.browser['just-now'] || 'just now';
                const formatter = typeof Intl !== 'undefined' && Intl.RelativeTimeFormat
                    ? new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
                    : null;
                return formatter ? formatter.format(-value, unit) : `${value} ${unit}${value === 1 ? '' : 's'} ago`;
            },
            // Card meta without placeholder dashes: only the parts that exist, joined by dots.
            pageMeta(page) {
                return [this.relativeTime(page.updated_at), page.creator, this.trans(this.labels.versions, { count: page.versions_count })].filter(Boolean).join(' · ');
            },
            toggleSelectMode() {
                this.selectMode = !this.selectMode;
                if (!this.selectMode) this.selected.clear();
            },
            toggleSelect(id) {
                if (this.selected.has(id)) this.selected.delete(id); else this.selected.add(id);
                this.selected = new Set(this.selected);
            },
            clearSelection() {
                this.selected = new Set();
                this.selectMode = false;
            },
            toggleMenu(key) {
                // A page menu has no card to hand focus back to; drop any folder card a dismissed folder menu left.
                this.menuOpener = null;
                this.openMenu = this.openMenu === key ? null : key;
            },
            // Presentation of the service's own-items rules (WebCollectionService::abilities()); the
            // write routes still decide. Global organize gates every organizer write first.
            folderMayModify(child) {
                return this.can.organize && child.can_modify === true;
            },
            folderMayDelete(child) {
                return this.can.organize && child.can_delete === true;
            },
            // Share pages in folder: offered where links can be made and the folder lists pages; the candidate route and
            // every link request still decide.
            folderMayShare(child) {
                return this.can.share === true && (child.page_count ?? 0) > 0;
            },
            shareFolderKeptLabel(reuse) {
                const expiry = reuse?.expires_at
                    ? this.trans(this.labels.browser['share-folder-expires'], { date: this.formatShareDate(reuse.expires_at) })
                    : this.labels.browser['share-folder-no-expiry'];

                // Every option the kept link carries, as the link itself has it: nothing here changes it.
                const options = [expiry];
                if (reuse?.has_password) options.push(this.labels.browser['share-folder-password']);
                if (reuse?.allow_download === false) options.push(this.labels.browser['share-folder-preview']);
                if (reuse?.requires_creator_access === true) options.push(this.labels.browser['share-folder-creator']);

                return this.trans(this.labels.browser['share-folder-kept'], { expiry: options.join(', ') });
            },
            formatShareDate(iso) {
                const date = new Date(iso);

                return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            },
            shareFolderRowLabel(row) {
                const key = { created: 'share-folder-created', changed: 'share-folder-changed', 'not-allowed': 'share-folder-denied', failed: 'share-folder-row-failed', uncertain: 'share-folder-uncertain', 'not-attempted': 'share-folder-skipped' }[row.status];
                if (row.status === 'kept') return this.shareFolderKeptLabel(row.reuse);
                if (row.status === 'pending') return this.labels.browser[row.working ? 'share-folder-working' : 'share-folder-waiting'];

                return this.labels.browser[key] || '';
            },
            focusShareFolder() {
                this.$nextTick?.(() => this.$el?.querySelector?.('dialog[data-dialog="share-folder"] [data-share-folder-focus]')?.focus?.());
            },
            // Step one is observational: it asks which direct pages this person could share and creates nothing.
            async openShareFolder(child) {
                this.closeMenu();
                this.folderShare = { id: child.id, name: child.name, state: 'loading', rows: [], omitted: 0, keepCount: 0, done: 0, stop: false, stopped: false, copyFailed: false, linksText: '', moreBelow: false, abort: null, stopping: false };
                // Read it back: under Vue the stored run is a reactive proxy, and only that one repaints and compares equal later.
                const run = this.folderShare;
                this.openDialog('share-folder');
                this.focusShareFolder();
                try {
                    const response = await this.request(this.routes.shareCandidates + '/' + child.id + '/candidates', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    const body = await response.json().catch(() => null);
                    if (this.folderShare !== run) return;
                    if (!response.ok || !body || !Array.isArray(body.pages)) { run.state = 'failed'; return; }
                    run.rows = body.pages.map((page) => ({ id: page.id, name: page.display_name, revision: page.revision, reuse: page.reuse ?? null, status: 'pending', url: null }));
                    run.omitted = Number(body.omitted) || 0;
                    run.keepCount = run.rows.filter((row) => row.reuse).length;
                    run.state = run.rows.length ? 'confirm' : 'empty';
                    this.$nextTick?.(() => this.checkShareFolderMore());
                } catch (e) {
                    if (this.folderShare === run) run.state = 'failed';
                } finally {
                    if (this.folderShare === run) this.focusShareFolder();
                }
            },
            // Cancel and Escape: before the confirmation they close and create nothing; during a run they stop it after the
            // page in flight; afterwards they close.
            cancelShareFolder() {
                // One Escape reaches this twice (the key handler, then the dialog's own cancel event). The first stops the run, which
                // can finish between the two, so the second must not close the result the viewer has not seen yet.
                if (this.folderShare?.state === 'sharing' || this.folderShare?.stopping) {
                    this.stopShareFolder();
                    return;
                }
                this.closeDialog();
            },
            // Stop and Escape (through cancelShareFolder) land here; an incidental close is ignored while a run is going (closeDialog).
            // No further page is started, and the request in flight is abandoned at once (it may never answer): its page is
            // reported as possibly shared, never retried, never read if it answers late.
            stopShareFolder() {
                const run = this.folderShare;
                if (!run) return;
                run.stop = true;
                run.abort?.();
                run.stopping = true;
                setTimeout(() => { run.stopping = false; }, 0);
            },
            // Whether the last row is cut off by the box, so a cut-off row is never mistaken for the end of the list. Measured on
            // the row's own text against the padding box: the box's bottom padding scrolls too, and a list whose rows all show says nothing.
            checkShareFolderMore() {
                const run = this.folderShare;
                const list = this.$refs?.shareFolderList;
                if (!run || !list) return;
                const last = list.lastElementChild?.lastElementChild;
                run.moreBelow = !!last && last.getBoundingClientRect().bottom > list.getBoundingClientRect().top + list.clientTop + list.clientHeight + 1;
            },
            // The confirmed run: one ordinary link request per page, in order, each re-checked by the server at creation.
            // A definite refusal is that page's result and the run goes on; an answer that never came back stops the run,
            // because that write may have happened and must not be retried blindly.
            async startShareFolder() {
                const run = this.folderShare;
                if (!run || run.state !== 'confirm') return;
                run.state = 'sharing';
                run.done = 0;
                this.focusShareFolder();
                let halted = false;
                for (const row of run.rows) {
                    if (halted || run.stop) {
                        row.status = 'not-attempted';
                        if (!halted) run.stopped = true;
                        continue;
                    }
                    row.working = true;
                    this.$nextTick?.(() => this.checkShareFolderMore());
                    const controller = new AbortController();
                    run.abort = () => controller.abort();
                    try {
                        // Raced against the abort as well as handed the signal, so a request that never answers, a body that never
                        // finishes (or a transport that ignores the signal) cannot keep the run, and the dialog, stuck. The race stays armed
                        // until the whole answer, body included, has been read; a late answer after an abort is never looked at.
                        const aborted = new Promise((resolve, reject) => controller.signal.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), { name: 'AbortError' })), { once: true }));
                        aborted.catch(() => {});
                        const answer = (async () => {
                            const reply = await this.postJson(this.routes.sharedLinksStore, {
                                share_type: 'asset', target_id: row.id, web_collection_id: run.id, page_revision: row.revision, public_link_confirmed: true, expiry_days: 30,
                            }, { signal: controller.signal });
                            return { reply, data: await reply.json().catch(() => null) };
                        })();
                        answer.catch(() => {});
                        const { reply: response, data: body } = await Promise.race([answer, aborted]);
                        // Answered in full: from here Stop only prevents the next page.
                        run.abort = null;
                        const token = body?.share?.token;
                        if (response.ok && token) {
                            row.status = body.reused ? 'kept' : 'created';
                            row.reuse = body.reused ? { expires_at: body.share.expires_at ?? null, has_password: body.share.has_password === true, allow_download: body.share.allow_download !== false, requires_creator_access: body.share.requires_creator_access === true } : null;
                            row.url = this.shareLinkFor(row, token);
                        } else if (response.status === 409) row.status = 'changed';
                        else if (response.status === 404 || response.status === 403) row.status = 'not-allowed';
                        else if (response.status >= 500 || response.ok) { row.status = 'uncertain'; halted = true; }
                        else { row.status = 'failed'; if (response.status === 401 || response.status === 419) halted = true; }
                    } catch (e) {
                        row.status = 'uncertain';
                        halted = true;
                    }
                    run.abort = null;
                    row.working = false;
                    run.done++;
                    this.$nextTick?.(() => this.checkShareFolderMore());
                }
                if (halted) run.stopped = true;
                run.linksText = run.rows.filter((row) => row.url).map((row) => row.name + '\n' + row.url).join('\n\n');
                run.state = 'done';
                this.focusShareFolder();
                this.$nextTick?.(() => this.checkShareFolderMore());
                const made = run.rows.filter((row) => row.url).length;
                this.notify(made === run.rows.length ? 'success' : 'warning', this.shareFolderSummary);
                if (made) this.load();
            },
            async copyFolderShareLinks() {
                const run = this.folderShare;
                if (!run?.linksText) return;
                if (await this.writeClipboard(run.linksText)) {
                    run.copyFailed = false;
                    this.notify('success', this.labels.browser['share-folder-copied']);
                    return;
                }
                // Nothing could be copied: show the links selected, as the single-link dialog does.
                run.copyFailed = true;
                this.$nextTick?.(() => { this.$refs.shareFolderLinks?.focus?.(); this.$refs.shareFolderLinks?.select?.(); });
            },
            showFolderMenu(child, card) {
                this.menuOpener = card ?? null;
                this.openMenu = 'folder-' + child.id;
                this.$nextTick?.(() => card?.querySelector?.('[role="menuitem"]')?.focus?.());
            },
            // restoreFocus: the menu was dismissed with the keyboard, so the card it came from keeps focus.
            closeMenu({ restoreFocus = false } = {}) {
                const opener = this.menuOpener;
                this.openMenu = null;
                this.menuOpener = null;
                if (restoreFocus) this.$nextTick?.(() => { if (opener?.isConnected) opener.focus?.(); });
            },
            toggleFolderMenu(event, child) {
                if (this.openMenu === 'folder-' + child.id) {
                    this.closeMenu({ restoreFocus: true });
                    return;
                }
                this.showFolderMenu(child, event.currentTarget?.closest?.('[data-web-collection]'));
            },
            // Right-click, long-press and the ContextMenu key. The rename field keeps the browser's own
            // menu (copy/paste); everywhere else the card's menu replaces it and nothing navigates.
            onFolderContextMenu(event, child) {
                if (event.target?.tagName === 'INPUT') return;
                event.preventDefault();
                event.stopPropagation();
                this.showFolderMenu(child, event.currentTarget);
            },
            // A secondary-button click, or ctrl+click on a Mac (which the OS treats as a right-click),
            // opens the menu and must not also open the folder.
            onFolderClick(event, child) {
                if (event.button > 0 || (event.ctrlKey && /Mac|iPhone|iPad/i.test(navigator?.platform ?? ''))) {
                    // Stopped here so the document click listener does not dismiss the menu the same gesture just opened.
                    event.stopPropagation();
                    return;
                }
                this.openCollection(child.id);
            },
            openFolderFromMenu(child) {
                this.closeMenu();
                this.openCollection(child.id);
            },
            onFolderMenuKeydown(event) {
                const items = Array.from(event.currentTarget?.querySelectorAll?.('[role="menuitem"]') ?? []);
                const index = items.indexOf(event.target);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus?.();
                } else if (event.key === 'Home' || event.key === 'End') {
                    event.preventDefault();
                    items[event.key === 'Home' ? 0 : items.length - 1]?.focus?.();
                } else if (event.key === 'Tab') {
                    event.preventDefault();
                    this.closeMenu({ restoreFocus: true });
                }
            },
            focusFolderCard(id) {
                this.$nextTick?.(() => this.$el?.querySelector?.('[data-web-collection="' + id + '"]')?.focus?.());
            },
            openDialog(name) {
                this.dialog = name;
                this.dialogName = '';
                this.dialogError = null;
                if (name === 'move') this.loadMoveTree();
            },
            closeDialog() {
                // A running folder share ends only through its own Stop or Escape (cancelShareFolder), so an incidental close, such as a
                // stray press on the overlay, neither hides the run nor abandons the page in flight.
                if (this.folderShare?.state === 'sharing' || this.folderShare?.stopping) return;
                this.folderShare = null;
                this.dialog = null;
                this.trashReturnTo = null;
                this.dialogTarget = null;
                this.dialogIds = [];
                // Cancel discards staged files; a later Upload must never send what was cancelled.
                this.uploadQueue = [];
                this.uploadRows = [];
                this.uploadDragOver = false;
            },
            // A drag that starts inside the panel (selecting the typed name) and is released on the
            // overlay also dispatches a click to the overlay; only a press that began there cancels.
            // The second press of a double-click on the button that opened the dialog lands on the
            // overlay a moment later; a press that soon after opening is that, not a Cancel.
            onOverlayMousedown(event) {
                const settled = event.timeStamp - this.overlayOpenedAt >= 500;
                this.overlayPressed = settled && event.target === event.currentTarget;
            },
            onOverlayClick(event) {
                if (this.overlayPressed && event.target === event.currentTarget) this.closeDialog();
                this.overlayPressed = false;
            },
            // A ready page's thumbnail is made on first open, so until then the file 404s: show the placeholder, not a broken image.
            onThumbError(event) {
                if (event.target.dataset.thumbFallback) return;
                event.target.dataset.thumbFallback = '1';
                event.target.src = this.placeholderSvg;
            },
            async errorMessage(response, fallback) {
                const body = await response.json().catch(() => ({}));
                return body.errors?.name?.[0] || body.errors?.parent_id?.[0] || body.message || fallback;
            },
            async submitNewFolder() {
                this.busyAction = true;
                try {
                    const response = await this.postJson(this.routes.store, { name: this.dialogName, parent_id: this.collection });
                    if (!response.ok) {
                        this.dialogError = await this.errorMessage(response, this.labels.browser['create-failed']);
                        this.notify('error', this.dialogError);
                        return;
                    }
                    const name = this.dialogName;
                    this.closeDialog();
                    this.notify('success', this.trans(this.labels.browser.created, { name }));
                    this.load();
                } catch (e) {
                    this.dialogError = this.labels.browser['create-failed'];
                    this.notify('error', this.dialogError);
                } finally {
                    this.busyAction = false;
                }
            },
            setRenameRef(el) {
                this.renameEl = el || null;
            },
            startRename(child) {
                if (!this.folderMayModify(child)) return;
                this.openMenu = null;
                this.renaming = child.id;
                this.$nextTick?.(() => { this.renameEl?.focus?.(); this.renameEl?.select?.(); });
            },
            cancelRename() {
                const id = this.renaming;
                this.renaming = null;
                this.focusFolderCard(id);
            },
            async saveRename(event, child) {
                if (this.renaming !== child.id) return;
                const name = event.target.value.trim();
                this.renaming = null;
                // Enter leaves the field; keep keyboard focus on the folder rather than dropping it to the page.
                if (event.type === 'keydown') this.focusFolderCard(child.id);
                if (!name || name === child.name) return;
                try {
                    const response = await this.patchJson(this.routes.update + '/' + child.id, { name });
                    if (!response.ok) { this.notify('error', await this.errorMessage(response, this.labels.browser['rename-failed'])); return; }
                    this.notify('success', this.trans(this.labels.browser.renamed, { name }));
                    this.load();
                } catch (e) {
                    this.notify('error', this.labels.browser['rename-failed']);
                }
            },
            resetPreviewMembers() {
                // Invalidates every in-flight members request: a late answer cannot repaint another page or file.
                this.previewSeq++;
                this.previewAbort?.abort?.();
                this.previewAbort = null;
                this.previewMembers = [];
                this.previewMembersState = 'idle';
                this.previewPin = null;
                this.previewChange = null;
                this.previewRefreshed = false;
            },
            pinOf(member) {
                return { attachment: member.attachment_id, file: member.file_id, checksum: member.checksum, name: member.name, candidate: member.candidate === true };
            },
            /**
             * Reads the stack's files for the previewed page. The first read picks the display: a stack of one file keeps the
             * legacy page; several files pin the representative as an exact member too (the legacy bundle records no
             * checksum). A refresh (after the frame reports a changed file) only updates the list and says what happened to
             * the pinned file; it never moves the display on its own.
             */
            async loadPreviewMembers(page, { refresh = false } = {}) {
                if (!page) return;
                const seq = ++this.previewSeq;
                this.previewAbort?.abort?.();
                const controller = typeof AbortController === 'function' ? new AbortController() : null;
                this.previewAbort = controller;
                // A refresh explains what happened to the file pinned now; if the viewer moves to another file meanwhile, the
                // answer still updates the list but says nothing about the file they left.
                const pinned = this.previewPin;
                if (!refresh) {
                    this.previewMembers = [];
                    this.previewPin = null;
                    this.previewChange = null;
                    this.previewRefreshed = false;
                    this.previewMembersState = 'loading';
                }
                try {
                    const response = await this.request(this.routes.show + '/' + page.id + '/members', { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller?.signal });
                    if (seq !== this.previewSeq) return;
                    if (!response.ok) throw new Error('members ' + response.status);
                    const body = await response.json();
                    if (seq !== this.previewSeq) return;
                    const members = Array.isArray(body.members) ? body.members : [];
                    this.previewMembers = members;
                    this.previewMembersState = 'ready';
                    if (refresh) {
                        if (this.previewPin !== pinned || !pinned) return;
                        // Only now is there evidence: the slot is gone, or it holds another current file.
                        this.previewChange = members.some((member) => member.attachment_id === pinned.attachment) ? 'changed' : 'unavailable';
                        return;
                    }
                    this.previewPin = members.length > 1 ? this.pinOf(members.find((member) => member.is_main) || members[0]) : null;
                } catch (e) {
                    if (seq !== this.previewSeq || e?.name === 'AbortError') return;
                    if (refresh) {
                        // No answer is no evidence: the pinned file stays pinned, the list stays as it was, and the stage says
                        // the state could not be checked, with a choice to check again.
                        if (this.previewPin === pinned && pinned) this.previewChange = 'unknown';
                        return;
                    }
                    // The list could not be read: say so and keep the page's own legacy preview, never a guessed file.
                    this.previewMembersState = 'failed';
                }
            },
            selectMember(member) {
                if (!member || this.previewPin?.attachment === member.attachment_id && this.previewPin?.file === member.file_id && this.previewPin?.checksum === member.checksum) return;
                this.previewPin = this.pinOf(member);
                this.previewChange = null;
                this.previewRefreshed = false;
            },
            stepMember(direction) {
                const next = this.previewMembers[this.previewMemberIndex + direction];
                if (next) this.selectMember(next);
            },
            onMemberChanged() {
                // The frame only knows the pinned file is not current. Say nothing more until the one list refresh for this
                // displayed file settles: never a loop, and never a guess about which of changed or removed it was.
                if (this.previewRefreshed || !this.previewPage || !this.previewPin) return;
                this.previewRefreshed = true;
                this.previewChange = 'checking';
                this.loadPreviewMembers(this.previewPage, { refresh: true });
            },
            // The viewer's own request after a refresh that could not be read: one more read, started by this click only.
            checkMemberAgain() {
                if (this.previewChange !== 'unknown' || !this.previewPage || !this.previewPin) return;
                this.previewChange = 'checking';
                this.loadPreviewMembers(this.previewPage, { refresh: true });
            },
            // The viewer's own choice: the pinned slot now holds a newer file, and only this click opens it.
            showCurrentMember() {
                const pin = this.previewPin;
                const current = pin ? this.previewMembers.find((member) => member.attachment_id === pin.attachment) : null;
                if (!current) return;
                if (current.file_id === pin.file && current.checksum === pin.checksum) {
                    // The pin is current again (the slot was restored): remount the frame to ask for it afresh.
                    this.previewChange = null;
                    this.previewRefreshed = false;
                    this.previewNonce++;
                    return;
                }
                this.selectMember(current);
            },
            // The address a copied share link carries: the displayed file's exact pin (slot, file, FULL checksum) for a
            // page member, the slot alone for a file that is not a page, the plain link for a one-file stack.
            shareLinkFor(page, token) {
                const base = `${window.location.origin}/share/${token}`;
                const pin = this.dialog === 'preview' && this.previewPage?.id === page.id ? this.previewPin : null;
                if (!pin) return base;
                if (!pin.candidate) return base + '?file=' + encodeURIComponent(pin.attachment);
                return base + '?file=' + encodeURIComponent(pin.attachment) + '&file_id=' + encodeURIComponent(pin.file) + '&checksum=' + encodeURIComponent(pin.checksum);
            },
            confirmTrashPage(page) {
                if (!page?.can_delete || this.busyAction) return;
                this.openMenu = null;
                this.trashReturnTo = this.dialog === 'preview' ? 'preview' : null;
                this.dialogTarget = page;
                this.dialog = 'trash-page';
            },
            cancelTrashPage() {
                if (this.busyAction) return;
                if (this.trashReturnTo === 'preview') {
                    this.dialog = 'preview';
                    this.dialogTarget = null;
                    this.trashReturnTo = null;
                } else this.closeDialog();
            },
            async submitTrashPage() {
                const page = this.dialogTarget;
                if (!page?.can_delete || this.busyAction) return;
                this.busyAction = true;
                try {
                    const response = await this.request(this.routes.trashPage + '/' + page.id, {
                        method: 'DELETE', credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    if (!response.ok) {
                        this.notify('error', await this.errorMessage(response, this.labels['trash-page-failed']));
                        if (response.status === 403 || response.status === 404) {
                            this.previewPage = null;
                            this.closeDialog();
                            await this.load();
                        }
                        return;
                    }
                    this.selected.delete(page.id);
                    this.previewPage = null;
                    this.closeDialog();
                    this.notify('success', this.labels['page-trashed']);
                    await this.load();
                } catch (e) {
                    this.notify('error', this.labels['trash-page-failed']);
                } finally {
                    this.busyAction = false;
                }
            },
            confirmDelete(child) {
                this.openMenu = null;
                this.dialogTarget = child;
                this.dialog = 'delete';
            },
            async submitDelete() {
                const child = this.dialogTarget;
                if (!child) return;
                this.busyAction = true;
                try {
                    const response = await this.request(this.routes.destroy + '/' + child.id, {
                        method: 'DELETE', credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    if (!response.ok) { this.notify('error', await this.errorMessage(response, this.labels.browser['delete-failed'])); return; }
                    this.closeDialog();
                    this.notify('success', this.trans(this.labels.browser.deleted, { name: child.name }));
                    this.load();
                } catch (e) {
                    this.notify('error', this.labels.browser['delete-failed']);
                } finally {
                    this.busyAction = false;
                }
            },
            openMovePages(ids) {
                this.openMenu = null;
                this.dialogIds = ids;
                this.dialogTarget = null;
                this.dialog = 'move';
                this.loadMoveTree();
            },
            openMoveFolder(child) {
                this.openMenu = null;
                this.dialogIds = [];
                this.dialogTarget = child;
                this.dialog = 'move';
                this.loadMoveTree();
            },
            async loadMoveTree() {
                try {
                    const response = await this.request(this.routes.listing + '?tree=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    const body = await response.json();
                    this.moveTree = this.flattenTree(body.children ?? []);
                } catch (e) {
                    this.moveTree = [];
                    this.notify('error', this.labels.browser['load-failed']);
                }
            },
            // ancestorIds carries every ancestor id above this node (root-first), so a node deep
            // in the tree still knows its whole lineage without walking back up through `all`.
            flattenTree(nodes, ancestorIds = [], out = []) {
                for (const node of nodes) {
                    out.push({ ...node, ancestorIds });
                    if (node.children) this.flattenTree(node.children, [...ancestorIds, node.id], out);
                }
                return out;
            },
            moveDisabled(node) {
                if (this.dialogTarget) return node.id === this.dialogTarget.id || this.isDescendantOf(node, this.dialogTarget.id);
                return false;
            },
            // True when $ancestorId is $node itself or anywhere in $node's lineage, so moving a
            // folder disables its entire subtree in the dialog, not just the folder itself.
            isDescendantOf(node, ancestorId) {
                return node.id === ancestorId || (node.ancestorIds ?? []).includes(ancestorId);
            },
            moveSummary(result) {
                return result.rejected.length
                    ? this.trans(this.labels.browser['move-partial'], { placed: result.placed.length, rejected: result.rejected.length })
                    : this.trans(this.labels.browser.moved, { count: result.placed.length });
            },
            async submitMove(collectionId) {
                if (this.dialogTarget) {
                    await this.moveFolder(this.dialogTarget.id, collectionId);
                    return;
                }
                const ids = this.dialogIds.length ? this.dialogIds : Array.from(this.selected);
                const result = await this.movePages(ids, collectionId);
                if (result) {
                    this.closeDialog();
                    this.clearSelection();
                    this.notify(result.rejected.length ? 'warning' : 'success', this.moveSummary(result));
                    this.load();
                }
            },
            async moveFolder(id, parentId) {
                try {
                    const response = await this.patchJson(this.routes.update + '/' + id, { parent_id: parentId });
                    if (!response.ok) { this.notify('error', await this.errorMessage(response, this.labels.browser['move-failed'])); return; }
                    this.closeDialog();
                    this.load();
                } catch (e) {
                    this.notify('error', this.labels.browser['move-failed']);
                }
            },
            async movePages(ids, collectionId) {
                try {
                    const response = await this.postJson(this.routes.move, { asset_ids: ids, collection_id: collectionId });
                    if (response.status !== 200 && response.status !== 403) {
                        this.notify('error', await this.errorMessage(response, this.labels.browser['move-failed']));
                        return null;
                    }
                    return response.json();
                } catch (e) {
                    this.notify('error', this.labels.browser['move-failed']);
                    return null;
                }
            },
            onFolderDragStart(event, child) {
                event.dataTransfer.setData('application/x-mothership-web-move', JSON.stringify({ type: 'folder', ids: [child.id] }));
            },
            onPageDragStart(event, page) {
                const ids = this.selectMode && this.selected.has(page.id) ? Array.from(this.selected) : [page.id];
                event.dataTransfer.setData('application/x-mothership-web-move', JSON.stringify({ type: 'page', ids }));
            },
            onDragOverTarget(id) {
                if (this.isTouch) return;
                this.dragOverId = id;
            },
            onDragLeaveTarget(id) {
                if (this.dragOverId === id) this.dragOverId = null;
            },
            // Reads $event, never window.event: window.event is an IE-era global the DOM spec
            // never required Firefox to populate, so a drop handler that relied on it silently
            // no-op'd there. Every @drop binding in the template now passes $event explicitly.
            async onDrop(event, collectionId) {
                this.dragOverId = null;
                const raw = event?.dataTransfer?.getData?.('application/x-mothership-web-move');
                if (!raw) return;
                const payload = JSON.parse(raw);
                if (payload.type === 'folder') {
                    if (payload.ids[0] === collectionId) return;
                    await this.moveFolder(payload.ids[0], collectionId);
                    return;
                }
                const result = await this.movePages(payload.ids, collectionId);
                if (result) {
                    this.notify(result.rejected.length ? 'warning' : 'success', this.moveSummary(result));
                    this.load();
                }
            },
            // Files dragged in from the desktop (the OS drag carries a 'Files' type; a card drag
            // carries only the mothership type) stage for Upload here into the current level.
            isFileDrag(event) {
                const types = Array.from(event?.dataTransfer?.types ?? []);
                return this.can.upload && types.includes('Files') && !types.includes('application/x-mothership-web-move');
            },
            onFileDragEnter(event) {
                if (!this.isFileDrag(event) || this.dialog) return;
                this.fileDragDepth++;
            },
            onFileDragOver(event) {
                if (this.isFileDrag(event) && event.dataTransfer) event.dataTransfer.dropEffect = this.dialog ? 'none' : 'copy';
            },
            onFileDragLeave(event) {
                if (!this.isFileDrag(event) || this.dialog) return;
                this.fileDragDepth = Math.max(0, this.fileDragDepth - 1);
            },
            onFileDrop(event) {
                const wasFileDrag = this.isFileDrag(event);
                this.fileDragDepth = 0;
                if (!wasFileDrag || this.dialog) return;
                this.openDialog('upload');
                this.queueUploads(event.dataTransfer?.files);
            },
            onCardClick(event, page) {
                if (this.selectMode) { this.toggleSelect(page.id); return; }
                if (event.shiftKey || event.metaKey || event.ctrlKey) {
                    this.selectMode = true;
                    this.toggleSelect(page.id);
                    return;
                }
                if (page.state === 'ready') {
                    this.previewPage = page;
                    this.dialog = 'preview';
                } else if (page.state === 'preparing') {
                    this.notify('info', this.labels.preparing);
                }
            },
            onCardKeydown(event, target) {
                // Keys aimed at the kebab, its menu or the rename input bubble here too; they are theirs.
                if (event.target !== event.currentTarget) return;
                if (event.key === 'Enter') {
                    // Without this, the same Enter's keypress lands on the preview's first
                    // focusable button once showModal() has moved focus into the dialog.
                    event.preventDefault();
                    if (target.type === 'folder') this.openCollection(target.item.id);
                    else this.onCardClick(event, target.item);
                } else if (event.key === ' ' || event.key === 'Spacebar') {
                    if (this.selectMode && target.type === 'page') {
                        event.preventDefault();
                        this.toggleSelect(target.item.id);
                    }
                } else if (target.type === 'folder' && (event.key === 'ContextMenu' || (event.key === 'F10' && event.shiftKey))) {
                    event.preventDefault();
                    this.showFolderMenu(target.item, event.currentTarget);
                } else if (event.key === 'F2' && target.type === 'folder') {
                    event.preventDefault();
                    this.startRename(target.item);
                } else if (event.key === 'Escape') {
                    this.clearSelection();
                } else if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.focusAdjacentCard(event, 1);
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.focusAdjacentCard(event, -1);
                }
            },
            focusAdjacentCard(event, delta) {
                const cards = Array.from(this.$el?.querySelectorAll?.('[data-web-collection],[data-web-page]') ?? []);
                const index = cards.indexOf(event.currentTarget || event.target);
                cards[index + delta]?.focus?.();
            },
            // 'n' focuses New folder from anywhere in the browser, unless the user is typing in a
            // field (search input, dialog name field, rename input) where 'n' is just a letter.
            onGlobalKeydown(event) {
                if (event.key === 'Escape' && this.shareConfirmPage) {
                    this.cancelShareConfirm();
                    return;
                }
                if (event.key === 'Escape' && this.shareUrl) {
                    this.closeShareDialog();
                    return;
                }
                if (event.key === 'Escape' && this.dialog) {
                    if (this.dialog === 'trash-page') this.cancelTrashPage();
                    else if (this.dialog === 'share-folder') this.cancelShareFolder();
                    else this.closeDialog();
                    return;
                }
                if (event.key === 'Escape' && this.openMenu) {
                    this.closeMenu({ restoreFocus: true });
                    return;
                }
                const tag = event.target?.tagName;
                if (event.key === 'n' && this.can.organize && tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') {
                    event.preventDefault();
                    this.$refs.newFolderButton?.focus?.();
                }
            },
            // Clipboard write that keeps its own success signal: the async API can reject outside a
            // user gesture (iOS Safari), and execCommand reports failure by returning false.
            async writeClipboard(text) {
                try {
                    if (typeof navigator.clipboard?.writeText === 'function') {
                        await navigator.clipboard.writeText(text);
                        return true;
                    }
                } catch (e) {}
                const tmp = document.createElement('textarea');
                tmp.value = text;
                tmp.style.position = 'fixed';
                tmp.style.opacity = '0';
                document.body.appendChild(tmp);
                tmp.focus();
                tmp.select();
                let copied = false;
                try { copied = document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(tmp);
                return copied;
            },
            openShareDialog(url) {
                this.shareReturnFocus = document.activeElement;
                this.shareUrl = url;
                this.overlayOpenedAt = performance.now();
                this.$nextTick(() => {
                    const modal = this.$el?.querySelector?.('dialog[data-share-dialog]');
                    if (!modal || modal.open) return;
                    if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', '');
                });
            },
            closeShareDialog() {
                const opener = this.shareReturnFocus;
                this.shareUrl = null;
                this.shareReturnFocus = null;
                this.$nextTick(() => { if (opener?.isConnected) opener.focus?.(); });
            },
            onShareOverlayClick(event) {
                if (this.overlayPressed && event.target === event.currentTarget) this.closeShareDialog();
                this.overlayPressed = false;
            },
            async copyShareUrl() {
                if (await this.writeClipboard(this.shareUrl)) {
                    this.notify('success', this.labels.copied);
                    this.closeShareDialog();
                } else {
                    // Nothing could be copied: leave the dialog open with the link selected.
                    this.$refs.shareInput?.focus?.();
                    this.$refs.shareInput?.select?.();
                }
            },
            // A link pinned to the displayed file is not made while that file changed, went away or is being checked: it would be
            // born stale. The stage already says why, and the button is disabled; this keeps a pending confirmation honest too.
            sharePinBlocked(page) {
                return this.dialog === 'preview' && !!page && this.previewPage?.id === page.id && !!this.previewChange;
            },
            async copyShare(page) {
                if (this.sharingId !== null || this.sharePinBlocked(page)) return;
                let token = page.active_share_token;
                if (!token) {
                    // Nothing is created until the viewer confirms the link is public.
                    this.shareReturnFocus = document.activeElement;
                    this.shareConfirmPage = page;
                    this.openMenu = null;
                    this.$nextTick(() => {
                        const modal = this.$el?.querySelector?.('dialog[data-share-confirm]');
                        if (!modal || modal.open) return;
                        if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', '');
                    });
                    return;
                }
                this.openMenu = null;
                const url = this.shareLinkFor(page, token);
                if (await this.writeClipboard(url)) this.notify('success', this.labels.copied);
                else this.openShareDialog(url);
            },
            cancelShareConfirm() {
                const opener = this.shareReturnFocus;
                this.shareConfirmPage = null;
                this.shareReturnFocus = null;
                this.$nextTick(() => { if (opener?.isConnected) opener.focus?.(); });
            },
            async createShare() {
                const page = this.shareConfirmPage;
                if (!page || this.sharingId !== null) return;
                // The file changed while the confirmation was open: nothing is created for a pin that no longer opens.
                if (this.sharePinBlocked(page)) { this.cancelShareConfirm(); return; }
                this.shareConfirmPage = null;
                let token = null;
                {
                    // The menu stays open (its item shows "Creating link…") until the link exists.
                    this.sharingId = page.id;
                    try {
                        const response = await this.postJson(this.routes.sharedLinksStore, { share_type: 'asset', target_id: page.id, public_link_confirmed: true, expiry_days: 30 });
                        const body = await response.json().catch(() => ({}));
                        token = body?.share?.token;
                        if (!response.ok || !token) { this.notify('error', this.labels.browser['share-failed']); return; }
                        page.active_share_token = token;
                        page.has_active_share = true;
                    } catch (e) {
                        this.notify('error', this.labels.browser['share-failed']);
                        return;
                    } finally {
                        this.sharingId = null;
                        this.openMenu = null;
                    }
                    // The tap's clipboard permission did not survive the wait: hand over a fresh one (unless the file changed meanwhile:
                    // the link exists, and is copied once a current file is chosen).
                    if (!this.sharePinBlocked(page)) this.openShareDialog(this.shareLinkFor(page, token));
                }
            },
            setThumbnail(page) {
                this.openMenu = null;
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/*';
                input.onchange = async () => {
                    const file = input.files[0];
                    if (!file) return;
                    const body = new FormData();
                    body.append('thumbnail', file);
                    try {
                        const response = await this.request(`${this.routes.customThumbnail}/${page.id}/custom-thumbnail`, {
                            method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        // Without Accept: JSON a refused file is answered with a redirect that fetch follows to a 200 page,
                        // which used to read as success (G2-05).
                        if (!response.ok) { this.notify('error', await this.errorMessage(response, this.labels.browser['thumbnail-failed'])); return; }
                        this.notify('success', this.labels.browser['thumbnail-set']);
                        this.load();
                    } catch (e) {
                        this.notify('error', this.labels.browser['thumbnail-failed']);
                    }
                };
                input.click();
            },
            rememberUploadDirectory() {
                try {
                    window.localStorage.setItem('dam.web-pages.upload-directory', String(this.uploadDirectoryId));
                } catch (e) { /* private browsing / storage disabled: nothing to remember */ }
            },
            onUploadDrop(event) {
                this.uploadDragOver = false;
                this.queueUploads(event.dataTransfer?.files);
            },
            // Picking or dropping only stages files; nothing is sent until Upload is pressed. Files
            // that cannot be web pages are skipped here instead of failing one by one server-side.
            queueUploads(fileList) {
                const all = Array.from(fileList || []);
                const accepted = all.filter((file) => /\.(html?|zip)$/i.test(file.name));
                const queued = new Set(this.uploadQueue.map((file) => file.name));
                this.uploadQueue.push(...accepted.filter((file) => !queued.has(file.name)));
                if (all.length > accepted.length) this.notify('warning', this.trans(this.labels.browser['skipped-files'], { count: all.length - accepted.length }));
            },
            unqueueUpload(file) {
                this.uploadQueue = this.uploadQueue.filter((queued) => queued !== file);
            },
            async startUpload() {
                const files = this.uploadQueue;
                this.uploadQueue = [];
                this.uploadBusy = true;
                try { await this.onUploadFiles(files); } finally { this.uploadBusy = false; }
            },
            async onUploadFiles(fileList) {
                const files = Array.from(fileList || []);
                const target = this.collection;
                // The dialog keeps earlier rows visible, so the toasts describe only this batch.
                const batch = [];
                for (const file of files) {
                    const row = { name: file.name, status: this.labels.browser.uploading, ok: null };
                    this.uploadRows.push(row);
                    batch.push(row);
                    try {
                        const body = new FormData();
                        body.append('directory_id', this.uploadDirectoryId);
                        body.append('context', 'pages');
                        body.append('files[]', file);
                        const response = await this.request(this.routes.upload, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': this.csrf() } });
                        const result = await response.json().catch(() => ({}));
                        const uploaded = result.files?.[0];
                        if (!response.ok || !uploaded) { row.status = result.message || this.labels.browser['upload-failed']; row.ok = false; continue; }
                        // Root uploads are already unfiled: no placement request to make, but the upload
                        // endpoint accepts any file, so a zip with no index.html (stored as a plain document)
                        // must not be reported as a placed page.
                        const moveResult = target
                            ? await this.movePages([uploaded.id], target)
                            : (uploaded.file_type && uploaded.file_type !== 'web'
                                ? { placed: [], rejected: [{ id: uploaded.id, reason: 'not-web' }] }
                                : { placed: [uploaded.id], rejected: [] });
                        if (!moveResult) {
                            row.status = this.labels.browser['placed-unfiled'];
                            row.ok = false;
                        } else if (moveResult.rejected?.length) {
                            const reason = moveResult.rejected[0].reason;
                            row.status = reason === 'not-web' ? this.labels.browser['not-web'] : reason;
                            row.ok = false;
                        } else {
                            // A new version of a same-name page, or a new page under the next free name, is said here.
                            row.status = result.published_notes?.[0] || this.labels.browser.placed;
                            row.ok = true;
                        }
                    } catch (e) { row.status = e?.message || this.labels.browser['upload-failed']; row.ok = false; }
                }
                const placed = batch.filter((row) => row.ok === true).length;
                const failed = batch.filter((row) => row.ok === false);
                if (placed) this.notify('success', this.trans(this.labels.browser.uploaded, { count: placed }));
                // The rows keep the per-file reason; the toast carries the first one so a failure is
                // never only visible inside the dialog.
                if (failed.length) this.notify('error', failed[0].status || this.labels.browser['upload-failed']);
                // Awaited: schedulePollIfPreparing must inspect the freshly loaded list, not the one
                // from before the upload, or a newly uploaded "preparing" page (very often the only
                // preparing card) is invisible to it and polling stops on the first tick.
                await this.load();
                this.schedulePollIfPreparing();
            },
            async schedulePollIfPreparing() {
                clearTimeout(this.pollHandle);
                if (!this.pages.data.some((page) => page.state === 'preparing') || this.pollCount >= 24) { this.pollCount = 0; return; }
                this.pollCount++;
                this.pollHandle = setTimeout(async () => { await this.load({ page: this.pages.current_page, history: 'none' }); this.schedulePollIfPreparing(); }, 5000);
            },
            csrf() {
                // No <meta name="csrf-token"> exists in the admin layout; the token is embedded as
                // a Blade constant here, the same way frame-script.blade.php and the old
                // index.blade.php inline script obtained it.
                return @json(csrf_token());
            },
            postJson(url, data, options = {}) {
                return this.request(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() }, body: JSON.stringify(data), ...options });
            },
            patchJson(url, data) {
                return this.request(url, { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() }, body: JSON.stringify(data) });
            },
        },
        mounted() {
            this.onDocumentClick = () => { this.closeMenu(); };
            document.addEventListener('click', this.onDocumentClick);
            // A right-click outside any card (a card's own handler stops propagation) dismisses an open menu.
            document.addEventListener('contextmenu', this.onDocumentClick);
            this.onPopStateBound = (event) => this.onPopState(event);
            window.addEventListener?.('popstate', this.onPopStateBound);
            this.narrowQuery = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 640px)') : null;
            this.onNarrowChange = (event) => { this.isNarrow = event.matches; };
            this.narrowQuery?.addEventListener?.('change', this.onNarrowChange);
            // Seed the history entry for the level the server rendered, so Back from the first
            // folder opened lands here instead of leaving the page.
            if (typeof URLSearchParams === 'function') this.syncHistory('replace', this.listingQuery({ page: this.pages.current_page }));
            if (this.pages.data.some((page) => page.state === 'preparing')) this.schedulePollIfPreparing();
        },
        beforeUnmount() {
            document.removeEventListener('click', this.onDocumentClick);
            document.removeEventListener('contextmenu', this.onDocumentClick);
            window.removeEventListener?.('popstate', this.onPopStateBound);
            this.narrowQuery?.removeEventListener?.('change', this.onNarrowChange);
            clearTimeout(this.pollHandle);
            clearTimeout(this.searchTimer);
            this.loadAbort?.abort?.();
        },
    });
</script>
@endonce
