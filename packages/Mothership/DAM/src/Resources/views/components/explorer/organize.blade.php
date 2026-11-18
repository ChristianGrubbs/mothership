{{--
    P4: the Library sidebar's Organize panel (favorites, sections, labels, the viewer's boards and pinned saved
    searches) and the dialogs it owns: the add-to-board / labels / pin picker, the label and board manager, the
    section manager and Save search. Every count comes from the server, over what the viewer may list now.
    The explorer tab listens for `dam:organize-open` (and the picker for `dam:organize-picker`).
--}}
<v-dam-library-organize></v-dam-library-organize>
<v-dam-organize-picker></v-dam-organize-picker>
<v-dam-label-manager></v-dam-label-manager>
<v-dam-section-manager></v-dam-section-manager>
<v-dam-save-search></v-dam-save-search>

@once('v-dam-library-organize')
@push('scripts')
<script type="text/x-template" id="v-dam-library-organize-template">
    <div class="flex flex-col gap-3 p-4 bg-white dark:bg-cherry-900 rounded-lg box-shadow" data-dam-organize>
        <p class="text-base text-zinc-800 dark:text-slate-50 font-bold !leading-normal">@{{ t.title }}</p>

        <div v-if="! loaded" class="flex flex-col gap-2" aria-busy="true" data-organize-loading>
            <div v-for="n in 5" :key="n" class="h-7 animate-pulse rounded bg-gray-100 dark:bg-cherry-800"></div>
        </div>

        <!-- Never loaded: one error with Retry, not "Favorites 0" and empty sections. -->
        <x-admin::load-error
            v-else-if="error && ! ready"
            class="!py-6"
            message="error.message"
            retryable="error.retryable"
            retry="reload()"
        />

        <template v-else>
        <!-- A refresh failed after a good load: the last good state stays, marked as possibly stale. -->
        <p v-if="error" class="text-xs text-red-600 dark:text-red-400" role="status">@{{ t['load-failed'] }}</p>

        <button
            type="button"
            class="flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition-colors hover:bg-gray-100 dark:hover:bg-cherry-800"
            :class="isActive('favorites') ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200' : 'text-zinc-700 dark:text-white'"
            data-organize-favorites
            @click="open({ type: 'favorites', name: t.favorites })"
        >
            <i class="icon-star text-lg text-primary-400 shrink-0" aria-hidden="true"></i>
            <span class="text-sm flex-1 truncate">@{{ t.favorites }}</span>
            <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@{{ state.favorites }}</span>
        </button>

        <div v-if="state.sections.length || state.can.manage_sections" class="flex flex-col gap-1" data-organize-sections>
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">@{{ t['sections-title'] }}</p>
                <button v-if="state.can.manage_sections" type="button" class="text-xs text-primary-600 dark:text-primary-300 hover:underline" data-organize-manage-sections @click="$emitter.emit('dam:section-manager-open')">@{{ t.manage }}</button>
            </div>
            <p v-if="loaded && ! state.sections.length" class="text-xs text-gray-400 dark:text-gray-500 px-2">@{{ t.sections.empty }}</p>
            <button
                v-for="section in state.sections"
                :key="'s' + section.id"
                type="button"
                class="flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition-colors hover:bg-gray-100 dark:hover:bg-cherry-800"
                :class="isActive('section', section.id) ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200' : 'text-zinc-700 dark:text-white'"
                :data-organize-section="section.id"
                @click="open({ type: 'section', id: section.id, name: section.name, default_sort: section.default_sort })"
            >
                <svg class="shrink-0 text-primary-400" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/></svg>
                <span class="text-sm flex-1 truncate">@{{ section.name }}</span>
                <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@{{ section.count }}</span>
            </button>
        </div>

        <div class="flex flex-col gap-1" data-organize-labels>
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">@{{ t['labels-title'] }}</p>
                <button v-if="state.can.manage_labels" type="button" class="text-xs text-primary-600 dark:text-primary-300 hover:underline" data-organize-manage-labels @click="$emitter.emit('dam:label-manager-open', { kind: 'shared' })">@{{ t.manage }}</button>
            </div>
            <p v-if="loaded && ! state.labels.length" class="text-xs text-gray-400 dark:text-gray-500 px-2">@{{ t.labels.empty }}</p>
            <div class="flex flex-col gap-0.5 max-h-64 overflow-y-auto pr-1">
                <button
                    v-for="node in visibleLabels"
                    :key="'l' + node.id"
                    type="button"
                    class="flex items-center gap-1.5 pr-2 py-1.5 rounded-md text-left transition-colors hover:bg-gray-100 dark:hover:bg-cherry-800"
                    :class="isActive('label', node.id) ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200' : 'text-zinc-700 dark:text-white'"
                    :style="{ paddingLeft: (4 + node.depth * 14) + 'px' }"
                    :data-organize-label="node.id"
                    @click="open({ type: 'label', id: node.id, name: node.name })"
                >
                    <span
                        class="w-4 h-4 flex items-center justify-center shrink-0 text-gray-400"
                        :aria-label="node.children.length ? (expanded[node.id] ? 'Collapse' : 'Expand') : undefined"
                        @click.stop="node.children.length && toggle(node.id)"
                    >
                        <svg v-if="node.children.length" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" :style="expanded[node.id] ? 'transform:rotate(90deg)' : ''" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                    </span>
                    <svg class="shrink-0 text-primary-400" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
                    <span class="text-sm flex-1 truncate">@{{ node.name }}</span>
                    <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@{{ node.count }}</span>
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-1" data-organize-boards>
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">@{{ t['boards-title'] }}</p>
                <button v-if="state.boards.length" type="button" class="text-xs text-primary-600 dark:text-primary-300 hover:underline" data-organize-manage-boards @click="$emitter.emit('dam:label-manager-open', { kind: 'board' })">@{{ t.manage }}</button>
            </div>
            <p v-if="loaded && ! state.boards.length" class="text-xs text-gray-400 dark:text-gray-500 px-2">@{{ t.labels['empty-boards'] }}</p>
            <button
                v-for="node in flatBoards"
                :key="'b' + node.id"
                type="button"
                class="flex items-center gap-2 pr-2 py-1.5 rounded-md text-left transition-colors hover:bg-gray-100 dark:hover:bg-cherry-800"
                :class="isActive('board', node.id) ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200' : 'text-zinc-700 dark:text-white'"
                :style="{ paddingLeft: (8 + node.depth * 14) + 'px' }"
                :data-organize-board="node.id"
                @click="open({ type: 'board', id: node.id, name: node.name })"
            >
                <svg class="shrink-0 text-primary-400" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                <span class="text-sm flex-1 truncate">@{{ node.name }}</span>
                <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@{{ node.count }}</span>
            </button>
            <form class="flex items-center gap-1.5 px-1 pt-1" @submit.prevent="createBoard">
                <input
                    v-model="newBoard"
                    type="text"
                    maxlength="191"
                    :placeholder="t.labels['new-board']"
                    :aria-label="t.labels['new-board']"
                    class="min-w-0 flex-1 rounded-md border border-gray-200 dark:border-cherry-700 bg-white dark:bg-cherry-800 px-2 py-1 text-sm text-gray-700 dark:text-gray-200"
                    data-organize-new-board
                />
                <button type="submit" class="secondary-button !px-2 !py-1 text-xs" :disabled="! newBoard.trim() || creatingBoard">@{{ t.labels.add }}</button>
            </form>
        </div>

        <div class="flex flex-col gap-1" data-organize-saved>
            <p class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">@{{ t['saved-title'] }}</p>
            <p v-if="loaded && ! state.saved_searches.length" class="text-xs text-gray-400 dark:text-gray-500 px-2">@{{ t['saved-searches'].empty }}</p>
            <div
                v-for="search in pinnedSearches"
                :key="'q' + search.id"
                class="group flex items-center gap-2 px-2 py-1.5 rounded-md transition-colors hover:bg-gray-100 dark:hover:bg-cherry-800"
                :class="isActive('saved', search.id) ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200' : 'text-zinc-700 dark:text-white'"
                :data-organize-saved-search="search.id"
            >
                <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left" @click="open({ type: 'saved', id: search.id, name: search.name, definition: search.definition })">
                    <i class="icon-search text-lg text-primary-400 shrink-0" aria-hidden="true"></i>
                    <span class="text-sm flex-1 truncate">@{{ search.name }}</span>
                </button>
                <span v-if="search.visibility === 'shared'" class="text-[10px] rounded bg-gray-100 dark:bg-cherry-800 px-1.5 py-0.5 text-gray-500 dark:text-gray-300 shrink-0">@{{ t['saved-searches']['shared-badge'] }}</span>
                <button v-if="search.mine" type="button" class="text-gray-400 hover:text-red-600 shrink-0 opacity-0 group-hover:opacity-100 focus:opacity-100" :aria-label="t['saved-searches'].delete + ' ' + search.name" @click="deleteSearch(search)">×</button>
            </div>
            <details v-if="otherSearches.length" class="mt-1" data-organize-saved-more :open="otherSearches.some((search) => isActive('saved', search.id))">
                <summary class="cursor-pointer px-2 py-1 text-xs text-gray-500 dark:text-gray-400" :title="t['saved-searches']['more-note']">@{{ t['saved-searches']['more-title'] }} (@{{ otherSearches.length }})</summary>
                <div
                    v-for="search in otherSearches"
                    :key="'qo' + search.id"
                    class="group flex items-center gap-2 px-2 py-1.5 rounded-md transition-colors hover:bg-gray-100 dark:hover:bg-cherry-800"
                    :class="isActive('saved', search.id) ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200' : 'text-zinc-700 dark:text-white'"
                    :data-organize-saved-search="search.id"
                >
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left" @click="open({ type: 'saved', id: search.id, name: search.name, definition: search.definition })">
                        <i class="icon-search text-lg text-primary-400 shrink-0" aria-hidden="true"></i>
                        <span class="text-sm flex-1 truncate">@{{ search.name }}</span>
                    </button>
                    <span v-if="search.visibility === 'shared'" class="text-[10px] rounded bg-gray-100 dark:bg-cherry-800 px-1.5 py-0.5 text-gray-500 dark:text-gray-300 shrink-0">@{{ t['saved-searches']['shared-badge'] }}</span>
                    <button v-if="search.mine" type="button" class="text-gray-400 hover:text-red-600 shrink-0 opacity-0 group-hover:opacity-100 focus:opacity-100" :aria-label="t['saved-searches'].delete + ' ' + search.name" @click="deleteSearch(search)">×</button>
                </div>
            </details>
        </div>
        </template>
    </div>
</script>

<script type="text/x-template" id="v-dam-organize-picker-template">
    <teleport to="body">
        <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center bg-gray-500 bg-opacity-50" style="z-index:10010;" @click.self="close" @keydown.esc="close" data-organize-picker>
            <div class="flex max-h-[85vh] w-full flex-col rounded-xl border border-gray-200 bg-white dark:border-cherry-600 dark:bg-cherry-900 mx-4" style="max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.25);" role="dialog" aria-modal="true" :aria-label="title">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-5 py-4 dark:border-cherry-800">
                    <h3 class="text-base font-bold text-gray-800 dark:text-white">@{{ title }}</h3>
                    <button type="button" class="text-xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-white" :aria-label="t.picker.done" @click="close">×</button>
                </div>
                <div class="flex gap-1 border-b border-gray-100 px-5 pt-3 dark:border-cherry-800" role="tablist">
                    <button v-for="tab in tabs" :key="tab.id" type="button" role="tab" :aria-selected="focus === tab.id" class="rounded-t-md px-3 py-1.5 text-sm" :class="focus === tab.id ? 'border-b-2 border-primary-600 font-semibold text-primary-700 dark:text-primary-200' : 'text-gray-500 dark:text-gray-400'" :data-organize-picker-tab="tab.id" @click="focus = tab.id">@{{ tab.label }}</button>
                </div>
                <div class="px-5 pt-3">
                    <input v-model="filter" type="text" :placeholder="t.picker.filter" :aria-label="t.picker.filter" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 dark:border-cherry-600 dark:bg-cherry-800 dark:text-gray-200" ref="filter" />
                </div>
                <div class="flex-1 overflow-y-auto px-5 py-3">
                    <p v-if="loading" class="py-6 text-center text-sm text-gray-400">…</p>
                    <template v-else>
                        <button
                            v-for="row in rows"
                            :key="row.id"
                            type="button"
                            class="flex w-full items-center gap-2 rounded-md py-2 pr-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-800"
                            :style="{ paddingLeft: (8 + (row.depth || 0) * 14) + 'px' }"
                            :aria-pressed="row.state === 'all'"
                            :data-organize-picker-row="row.id"
                            :disabled="busy"
                            @click="toggleRow(row)"
                        >
                            <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded border" :class="row.state === 'none' ? 'border-gray-300 dark:border-cherry-600' : 'border-primary-600 bg-primary-600 text-white'" aria-hidden="true">
                                <svg v-if="row.state === 'all'" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4"><polyline points="4 12 10 18 20 6"/></svg>
                                <span v-else-if="row.state === 'some'" class="block h-0.5 w-2 bg-white"></span>
                            </span>
                            <span class="flex-1 truncate">@{{ row.name }}</span>
                            <span v-if="row.state === 'some'" class="text-xs text-gray-400">@{{ t.picker.partly }}</span>
                            <span class="text-xs text-gray-400">@{{ row.count }}</span>
                        </button>
                        <p v-if="! rows.length && ! (focus === 'boards' && filter.trim())" class="py-4 text-center text-xs text-gray-400">@{{ focus === 'boards' ? t.labels['empty-boards'] : (focus === 'labels' ? t.labels.empty : t.sections.empty) }}</p>
                        <button v-if="focus === 'boards' && filter.trim() && ! exactBoard" type="button" class="mt-1 flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-sm font-semibold text-primary-700 hover:bg-primary-50 dark:text-primary-200 dark:hover:bg-cherry-800" data-organize-picker-create :disabled="busy" @click="createBoard">
                            + @{{ t.picker['create-board'].replace(':name', filter.trim()) }}
                        </button>
                    </template>
                </div>
                <div class="flex justify-end border-t border-gray-100 px-5 py-3 dark:border-cherry-800">
                    <button type="button" class="primary-button" @click="close">@{{ t.picker.done }}</button>
                </div>
            </div>
        </div>
    </teleport>
</script>

<script type="text/x-template" id="v-dam-label-manager-template">
    <teleport to="body">
        <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center bg-gray-500 bg-opacity-50" style="z-index:10010;" @click.self="close" data-label-manager>
            <div class="flex max-h-[85vh] w-full flex-col rounded-xl border border-gray-200 bg-white dark:border-cherry-600 dark:bg-cherry-900 mx-4" style="max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.25);" role="dialog" aria-modal="true" :aria-label="title">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-5 py-4 dark:border-cherry-800">
                    <h3 class="text-base font-bold text-gray-800 dark:text-white">@{{ title }}</h3>
                    <button type="button" class="text-xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-white" :aria-label="t.labels.close" @click="close">×</button>
                </div>
                <form class="flex flex-wrap items-center gap-2 px-5 pt-4" @submit.prevent="create">
                    <input v-model="draft.name" type="text" maxlength="191" required :placeholder="kind === 'board' ? t.labels['board-placeholder'] : t.labels['new-placeholder']" :aria-label="t.labels.name" class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 dark:border-cherry-600 dark:bg-cherry-800 dark:text-gray-200" data-label-manager-name />
                    <select v-model="draft.parent_id" :aria-label="t.labels['nest-under']" class="max-w-[45%] rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-700 dark:border-cherry-600 dark:bg-cherry-800 dark:text-gray-200">
                        <option :value="null">@{{ t.labels['top-level'] }}</option>
                        <option v-for="node in flat" :key="node.id" :value="node.id" :disabled="node.depth >= 4">@{{ '— '.repeat(node.depth) + node.name }}</option>
                    </select>
                    <button type="submit" class="primary-button" :disabled="busy || ! draft.name.trim()">@{{ t.labels.add }}</button>
                </form>
                <div class="flex-1 overflow-y-auto px-5 py-3">
                    <p v-if="! flat.length" class="py-4 text-center text-xs text-gray-400">@{{ kind === 'board' ? t.labels['empty-boards'] : t.labels.empty }}</p>
                    <div v-for="node in flat" :key="node.id" class="flex items-center gap-1.5 border-b border-gray-50 py-1.5 dark:border-cherry-800" :style="{ paddingLeft: (node.depth * 16) + 'px' }" :data-label-manager-row="node.id">
                        <template v-if="editing === node.id">
                            <input v-model="editName" type="text" maxlength="191" :aria-label="t.labels.name" class="min-w-0 flex-1 rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-cherry-600 dark:bg-cherry-800 dark:text-gray-200" @keydown.enter.prevent="rename(node)" @keydown.esc.prevent="editing = null" ref="rename" />
                            <select v-model="editParent" :aria-label="t.labels['nest-under']" class="max-w-[40%] rounded-md border border-gray-300 px-1.5 py-1 text-sm dark:border-cherry-600 dark:bg-cherry-800 dark:text-gray-200" data-label-manager-parent>
                                <option :value="null">@{{ t.labels['top-level'] }}</option>
                                <option v-for="option in parentChoices(node)" :key="option.id" :value="option.id">@{{ '— '.repeat(option.depth) + option.name }}</option>
                            </select>
                            <button type="button" class="text-xs font-semibold text-primary-700 dark:text-primary-200" @click="rename(node)">@{{ t.labels.save }}</button>
                            <button type="button" class="text-xs text-gray-500" @click="editing = null">@{{ t.labels.cancel }}</button>
                        </template>
                        <template v-else>
                            <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-200">@{{ node.name }} <span class="text-xs text-gray-400">· @{{ node.count }}</span></span>
                            <button type="button" class="h-7 w-7 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-cherry-800" :aria-label="t.labels['move-up'] + ' ' + node.name" :disabled="busy || node.index === 0" @click="move(node, -1)">↑</button>
                            <button type="button" class="h-7 w-7 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-cherry-800" :aria-label="t.labels['move-down'] + ' ' + node.name" :disabled="busy || node.last" @click="move(node, 1)">↓</button>
                            <button type="button" class="rounded px-1.5 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-cherry-800" @click="startRename(node)">@{{ t.labels.rename }}</button>
                            <button type="button" class="rounded px-1.5 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-cherry-800" @click="remove(node)">@{{ t.labels.delete }}</button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </teleport>
</script>

<script type="text/x-template" id="v-dam-section-manager-template">
    <teleport to="body">
        <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center bg-gray-500 bg-opacity-50" style="z-index:10010;" @click.self="close" data-section-manager>
            <div class="flex max-h-[85vh] w-full flex-col rounded-xl border border-gray-200 bg-white dark:border-cherry-600 dark:bg-cherry-900 mx-4" style="max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.25);" role="dialog" aria-modal="true" :aria-label="t.sections['manager-title']">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-5 py-4 dark:border-cherry-800">
                    <h3 class="text-base font-bold text-gray-800 dark:text-white">@{{ t.sections['manager-title'] }}</h3>
                    <button type="button" class="text-xl leading-none text-gray-400 hover:text-gray-700 dark:hover:text-white" :aria-label="t.sections.close" @click="close">×</button>
                </div>
                <p class="px-5 pt-3 text-xs text-gray-500 dark:text-gray-400">@{{ t.sections['access-note'] }}</p>
                <div class="flex-1 overflow-y-auto px-5 py-3">
                    <div v-for="(section, index) in sections" :key="section.id" class="flex items-center gap-1.5 border-b border-gray-50 py-2 dark:border-cherry-800" :data-section-manager-row="section.id">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-700 dark:text-gray-200">@{{ section.name }} <span class="text-xs font-normal text-gray-400">· @{{ section.count }}</span></p>
                            <p class="truncate text-xs text-gray-400">@{{ ruleSummary(section) }}</p>
                        </div>
                        <button type="button" class="h-7 w-7 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-cherry-800" :aria-label="t.sections['move-up'] + ' ' + section.name" :disabled="busy || index === 0" @click="move(section, index - 1)">↑</button>
                        <button type="button" class="h-7 w-7 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-cherry-800" :aria-label="t.sections['move-down'] + ' ' + section.name" :disabled="busy || index === sections.length - 1" @click="move(section, index + 1)">↓</button>
                        <button type="button" class="rounded px-1.5 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-cherry-800" @click="edit(section)">@{{ t.labels.rename }}</button>
                        <button type="button" class="rounded px-1.5 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-cherry-800" @click="remove(section)">@{{ t.sections.delete }}</button>
                    </div>
                    <p v-if="! sections.length" class="py-3 text-center text-xs text-gray-400">@{{ t.sections.empty }}</p>
                </div>
                <form class="flex flex-col gap-3 border-t border-gray-100 px-5 py-4 dark:border-cherry-800" @submit.prevent="save" data-section-form>
                    <p class="text-sm font-semibold text-gray-800 dark:text-white">@{{ form.id ? form.originalName : t.sections.new }}</p>
                    <label class="flex flex-col gap-1 text-xs text-gray-600 dark:text-gray-300">@{{ t.sections.name }}
                        <input v-model="form.name" type="text" maxlength="191" required class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 dark:border-cherry-600 dark:bg-cherry-800 dark:text-gray-200" data-section-name />
                    </label>
                    <fieldset class="flex flex-col gap-1">
                        <legend class="text-xs text-gray-600 dark:text-gray-300">@{{ t.sections['media-types'] }}</legend>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="(label, type) in t['media-types']" :key="type" class="flex items-center gap-1.5 rounded-full border border-gray-200 px-2.5 py-1 text-xs text-gray-700 dark:border-cherry-700 dark:text-gray-200">
                                <input v-model="form.media_types" type="checkbox" :value="type" class="accent-primary-600" /> @{{ label }}
                            </label>
                        </div>
                    </fieldset>
                    <div class="flex flex-wrap gap-3">
                        <label class="flex min-w-[10rem] flex-1 flex-col gap-1 text-xs text-gray-600 dark:text-gray-300">@{{ t.sections.extensions }}
                            <input v-model="form.extensions" type="text" :placeholder="t.sections['extensions-hint']" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 dark:border-cherry-600 dark:bg-cherry-800 dark:text-gray-200" />
                        </label>
                        <label class="flex min-w-[10rem] flex-1 flex-col gap-1 text-xs text-gray-600 dark:text-gray-300">@{{ t.sections['default-sort'] }}
                            <select v-model="form.default_sort" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-700 dark:border-cherry-600 dark:bg-cherry-800 dark:text-gray-200">
                                <option :value="null">@{{ t.sections['library-default'] }}</option>
                                <option v-for="option in sortChoices" :key="option.value" :value="option.value">@{{ option.label }}</option>
                            </select>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button v-if="form.id" type="button" class="secondary-button" @click="reset">@{{ t.sections.cancel }}</button>
                        <button type="submit" class="primary-button" :disabled="busy || ! form.name.trim()">@{{ t.sections.save }}</button>
                    </div>
                </form>
            </div>
        </div>
    </teleport>
</script>

<script type="text/x-template" id="v-dam-save-search-template">
    <teleport to="body">
        <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center bg-gray-500 bg-opacity-50" style="z-index:10010;" @click.self="close" data-save-search>
            <form class="w-full rounded-xl border border-gray-200 bg-white p-6 dark:border-cherry-600 dark:bg-cherry-900 mx-4" style="max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.25);" role="dialog" aria-modal="true" :aria-label="t['saved-searches']['dialog-title']" @submit.prevent="save">
                <h3 class="mb-1 text-base font-bold text-gray-800 dark:text-white">@{{ t['saved-searches']['dialog-title'] }}</h3>
                <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">@{{ t['saved-searches']['rerun-note'] }}</p>
                <label class="mb-3 flex flex-col gap-1 text-xs text-gray-600 dark:text-gray-300">@{{ t['saved-searches'].name }}
                    <input v-model="name" ref="name" type="text" maxlength="191" required class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-cherry-600 dark:bg-cherry-800 dark:text-gray-200" data-save-search-name />
                </label>
                <label class="mb-3 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input v-model="pinned" type="checkbox" class="accent-primary-600" /> @{{ t['saved-searches'].pinned }}
                </label>
                <fieldset class="mb-4 flex flex-col gap-1.5">
                    <legend class="mb-1 text-xs text-gray-600 dark:text-gray-300">@{{ t['saved-searches'].visibility }}</legend>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200"><input v-model="visibility" type="radio" value="private" class="accent-primary-600" /> @{{ t['saved-searches'].private }}</label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200"><input v-model="visibility" type="radio" value="shared" class="accent-primary-600" /> @{{ t['saved-searches'].shared }}</label>
                </fieldset>
                <div class="flex justify-end gap-2">
                    <button type="button" class="secondary-button" @click="close">@{{ t['saved-searches'].cancel }}</button>
                    <button type="submit" class="primary-button" :disabled="busy || ! name.trim()">@{{ t['saved-searches'].save }}</button>
                </div>
            </form>
        </div>
    </teleport>
</script>

<script type="module">
    // One place for the endpoints and strings every organize component uses.
    window.MothershipOrganize = window.MothershipOrganize || {
        t: @json(trans('dam::app.admin.organize')),
        url(name, ...ids) {
            let url = {
                organize: "{{ route('admin.dam.organize.index') }}",
                preferences: "{{ route('admin.dam.library.preferences.update') }}",
                labels: "{{ route('admin.dam.labels.store') }}",
                label: "{{ route('admin.dam.labels.update', '__0__') }}",
                labelAssets: "{{ route('admin.dam.labels.assets.store', '__0__') }}",
                labelAsset: "{{ route('admin.dam.labels.assets.update', ['__0__', '__1__']) }}",
                boards: "{{ route('admin.dam.boards.store') }}",
                board: "{{ route('admin.dam.boards.update', '__0__') }}",
                boardAssets: "{{ route('admin.dam.boards.assets.store', '__0__') }}",
                boardAsset: "{{ route('admin.dam.boards.assets.update', ['__0__', '__1__']) }}",
                searches: "{{ route('admin.dam.saved-searches.store') }}",
                search: "{{ route('admin.dam.saved-searches.update', '__0__') }}",
                sections: "{{ route('admin.dam.sections.store') }}",
                section: "{{ route('admin.dam.sections.update', '__0__') }}",
                pins: "{{ route('admin.dam.sections.pins.store', '__0__') }}",
                pin: "{{ route('admin.dam.sections.pins.update', ['__0__', '__1__']) }}",
            }[name];
            ids.forEach((id, index) => { url = url.replace(`__${index}__`, encodeURIComponent(id)); });

            return url;
        },
        // A tree as rows with depth, sibling index and whether it is the last sibling.
        flatten(nodes, depth = 0, out = [], expanded = null) {
            nodes.forEach((node, index) => {
                out.push({ ...node, depth, index, last: index === nodes.length - 1 });
                if (node.children?.length && (expanded === null || expanded[node.id])) this.flatten(node.children, depth + 1, out, expanded);
            });

            return out;
        },
        error(error) {
            const data = error?.response?.data ?? {};

            return Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? '';
        },
    };

    app.component('v-dam-library-organize', {
        template: '#v-dam-library-organize-template',

        data() {
            return {
                t: window.MothershipOrganize.t,
                state: { labels: [], boards: [], sections: [], saved_searches: [], favorites: 0, sort: null, popularity: false, can: {} },
                loaded: false,
                // true once one load succeeded; null or the shared request-failure description.
                ready: false,
                error: null,
                expanded: {},
                active: null,
                newBoard: '',
                creatingBoard: false,
            };
        },

        computed: {
            visibleLabels() { return window.MothershipOrganize.flatten(this.state.labels, 0, [], this.expanded); },
            flatBoards() { return window.MothershipOrganize.flatten(this.state.boards); },
            pinnedSearches() { return this.state.saved_searches.filter((search) => search.pinned); },
            // Saved searches that are not pinned into this viewer's sidebar (their own unpinned ones, and those others
            // shared): listed here so none is stored but unreachable.
            otherSearches() { return this.state.saved_searches.filter((search) => ! search.pinned); },
        },

        mounted() {
            this.load();
            this._onChanged = () => this.load();
            this._onActive = (active) => { this.active = active; };
            this.$emitter.on('dam:organize-changed', this._onChanged);
            this.$emitter.on('dam:organize-active', this._onActive);
        },

        beforeUnmount() {
            this.$emitter.off('dam:organize-changed', this._onChanged);
            this.$emitter.off('dam:organize-active', this._onActive);
        },

        methods: {
            load() {
                return this.$axios.get(window.MothershipOrganize.url('organize'))
                    .then(({ data }) => {
                        this.state = data;
                        this.error = null;
                        this.ready = true;
                        window.MothershipOrganize.state = data;
                        this.$emitter.emit('dam:organize-state', data);
                    })
                    .catch((error) => { this.error = (globalThis.mothership?.requestError?.(error) ?? { message: error?.response?.data?.message ?? '', retryable: true }); })
                    .finally(() => { this.loaded = true; });
            },

            reload() {
                this.loaded = false;

                return this.load();
            },

            isActive(type, id = null) {
                return this.active?.type === type && (id === null || Number(this.active?.id) === Number(id));
            },

            toggle(id) { this.expanded = { ...this.expanded, [id]: ! this.expanded[id] }; },

            open(target) {
                this.active = { type: target.type, id: target.id ?? null };
                this.$emitter.emit('dam:organize-open', target);
            },

            createBoard() {
                const name = this.newBoard.trim();
                // A second Enter while the first create is in flight must not make a second board.
                if (! name || this.creatingBoard) return;
                this.creatingBoard = true;
                this.$axios.post(window.MothershipOrganize.url('boards'), { name })
                    .then(() => { this.newBoard = ''; this.load(); })
                    .catch((error) => this.$emitter.emit('add-flash', { type: 'error', message: window.MothershipOrganize.error(error) }))
                    .finally(() => { this.creatingBoard = false; });
            },

            deleteSearch(search) {
                this.$emitter.emit('open-confirm-modal', {
                    message: this.t['saved-searches']['delete-confirm'].replace(':name', search.name),
                    agree: () => this.$axios.delete(window.MothershipOrganize.url('search', search.id))
                        .then(({ data }) => { this.$emitter.emit('add-flash', { type: 'success', message: data.message }); this.load(); })
                        .catch((error) => this.$emitter.emit('add-flash', { type: 'error', message: window.MothershipOrganize.error(error) })),
                });
            },
        },
    });

    // Add the selected files to boards and labels, or pin them to sections: each row toggles all of them in or out.
    app.component('v-dam-organize-picker', {
        template: '#v-dam-organize-picker-template',

        data() {
            return { t: window.MothershipOrganize.t, isOpen: false, assetIds: [], focus: 'boards', filter: '', loading: false, busy: false, data: null, changed: false };
        },

        computed: {
            title() {
                const count = this.assetIds.length;
                const [one, many] = this.t.picker.title.replace('{1} ', '').split('|[2,*] ');

                return (count === 1 ? one : many).replace(':count', count);
            },
            can() { return this.data?.can ?? {}; },
            tabs() {
                const tabs = [{ id: 'boards', label: this.t.picker.boards }];
                if (this.can.assign_labels) tabs.push({ id: 'labels', label: this.t.picker.labels });
                if (this.can.manage_sections) tabs.push({ id: 'sections', label: this.t.picker.sections });

                return tabs;
            },
            rows() {
                if (! this.data) return [];
                const needle = this.filter.trim().toLowerCase();
                const source = this.focus === 'sections'
                    ? this.data.sections.map((section) => ({ ...section, held: section.pins, depth: 0 }))
                    : window.MothershipOrganize.flatten(this.focus === 'boards' ? this.data.boards : this.data.labels).map((node) => ({ ...node, held: node.holds }));

                return source
                    .filter((row) => ! needle || row.name.toLowerCase().includes(needle))
                    .map((row) => ({ ...row, state: row.held >= this.assetIds.length ? 'all' : (row.held > 0 ? 'some' : 'none') }));
            },
            exactBoard() {
                const name = this.filter.trim().toLowerCase();

                return window.MothershipOrganize.flatten(this.data?.boards ?? []).some((node) => node.name.toLowerCase() === name);
            },
        },

        mounted() {
            this._onOpen = ({ assetIds = [], focus = 'boards' } = {}) => {
                this.assetIds = [...new Set(assetIds.map(Number))].filter(Boolean);
                if (! this.assetIds.length) return;
                this.focus = focus; this.filter = ''; this.changed = false; this.isOpen = true;
                this.load().then(() => { if (! this.tabs.some((tab) => tab.id === this.focus)) this.focus = 'boards'; });
                this.$nextTick(() => this.$refs.filter?.focus());
            };
            this.$emitter.on('dam:organize-picker', this._onOpen);
        },

        beforeUnmount() { this.$emitter.off('dam:organize-picker', this._onOpen); },

        methods: {
            load() {
                this.loading = ! this.data;

                return this.$axios.get(window.MothershipOrganize.url('organize'), { params: { asset_ids: this.assetIds } })
                    .then(({ data }) => { this.data = data; })
                    .catch((error) => this.$emitter.emit('add-flash', { type: 'error', message: window.MothershipOrganize.error(error) }))
                    .finally(() => { this.loading = false; });
            },

            endpoint(row) {
                if (this.focus === 'sections') return window.MothershipOrganize.url('pins', row.id);

                return window.MothershipOrganize.url(this.focus === 'boards' ? 'boardAssets' : 'labelAssets', row.id);
            },

            toggleRow(row) {
                const adding = row.state !== 'all';
                const request = adding
                    ? this.$axios.post(this.endpoint(row), { asset_ids: this.assetIds })
                    : this.$axios.delete(this.endpoint(row), { data: { asset_ids: this.assetIds } });
                const verb = this.focus === 'sections' ? (adding ? 'pinned' : 'unpinned') : (adding ? 'added' : 'removed');
                this.busy = true;
                request
                    .then(() => { this.changed = true; this.$emitter.emit('add-flash', { type: 'success', message: this.t.picker[verb].replace(':name', row.name) }); return this.load(); })
                    .catch((error) => this.$emitter.emit('add-flash', { type: 'error', message: window.MothershipOrganize.error(error) || this.t['assets-not-found'] }))
                    .finally(() => { this.busy = false; });
            },

            createBoard() {
                const name = this.filter.trim();
                if (this.busy) return;
                this.busy = true;
                this.$axios.post(window.MothershipOrganize.url('boards'), { name })
                    .then(({ data }) => this.$axios.post(window.MothershipOrganize.url('boardAssets', data.data.id), { asset_ids: this.assetIds }))
                    .then(() => { this.changed = true; this.filter = ''; this.$emitter.emit('add-flash', { type: 'success', message: this.t.picker.added.replace(':name', name) }); return this.load(); })
                    .catch((error) => this.$emitter.emit('add-flash', { type: 'error', message: window.MothershipOrganize.error(error) }))
                    .finally(() => { this.busy = false; });
            },

            close() {
                this.isOpen = false;
                if (this.changed) {
                    this.$emitter.emit('dam:organize-changed');
                    this.$emitter.emit('dam:organize-refresh');
                }
            },
        },
    });

    // Shared labels (with dam.labels.manage) or the viewer's own boards: create, nest, rename, reorder, delete.
    app.component('v-dam-label-manager', {
        template: '#v-dam-label-manager-template',

        data() {
            return { t: window.MothershipOrganize.t, isOpen: false, kind: 'shared', tree: [], busy: false, draft: { name: '', parent_id: null }, editing: null, editName: '', editParent: null };
        },

        computed: {
            title() { return this.kind === 'board' ? this.t.labels['boards-manager-title'] : this.t.labels['manager-title']; },
            flat() { return window.MothershipOrganize.flatten(this.tree); },
        },

        mounted() {
            this._onOpen = ({ kind = 'shared' } = {}) => {
                this.kind = kind; this.draft = { name: '', parent_id: null }; this.editing = null; this.isOpen = true;
                this.load();
            };
            this.$emitter.on('dam:label-manager-open', this._onOpen);
        },

        beforeUnmount() { this.$emitter.off('dam:label-manager-open', this._onOpen); },

        methods: {
            load() {
                return this.$axios.get(window.MothershipOrganize.url('organize'))
                    .then(({ data }) => { this.tree = this.kind === 'board' ? data.boards : data.labels; });
            },
            url(id = null) {
                return id === null
                    ? window.MothershipOrganize.url(this.kind === 'board' ? 'boards' : 'labels')
                    : window.MothershipOrganize.url(this.kind === 'board' ? 'board' : 'label', id);
            },
            run(request) {
                this.busy = true;

                return request
                    .then(({ data }) => { if (data?.message) this.$emitter.emit('add-flash', { type: 'success', message: data.message }); this.$emitter.emit('dam:organize-changed'); return this.load(); })
                    .catch((error) => this.$emitter.emit('add-flash', { type: 'error', message: window.MothershipOrganize.error(error) }))
                    .finally(() => { this.busy = false; });
            },
            create() {
                if (this.busy) return;
                this.run(this.$axios.post(this.url(), { name: this.draft.name.trim(), parent_id: this.draft.parent_id }))
                    .then(() => { this.draft.name = ''; });
            },
            // A label can move under any label except itself and its own nested labels (the server enforces it too).
            parentChoices(node) {
                const rows = this.flat;
                const start = rows.findIndex((row) => row.id === node.id);
                let end = start + 1;
                while (end < rows.length && rows[end].depth > node.depth) end++;
                const excluded = new Set(rows.slice(start, end).map((row) => row.id));

                return rows.filter((row) => ! excluded.has(row.id));
            },
            // Every label nested under this one, at any depth: deleting the label deletes them too.
            nestedCount(node) {
                const count = (children) => (children ?? []).reduce((total, child) => total + 1 + count(child.children), 0);

                return count(node.children);
            },
            startRename(node) {
                this.editing = node.id; this.editName = node.name; this.editParent = node.parent_id ?? null;
                this.$nextTick(() => (Array.isArray(this.$refs.rename) ? this.$refs.rename[0] : this.$refs.rename)?.focus());
            },
            rename(node) {
                const payload = { name: this.editName.trim() };
                if ((node.parent_id ?? null) !== this.editParent) payload.parent_id = this.editParent;
                this.run(this.$axios.put(this.url(node.id), payload)).then(() => { this.editing = null; });
            },
            move(node, delta) { this.run(this.$axios.put(this.url(node.id), { position: node.index + delta })); },
            remove(node) {
                this.$emitter.emit('open-confirm-modal', {
                    message: this.nestedCount(node) > 0
                        ? this.t.labels['delete-confirm-nested'].replace(':name', node.name).replace(':count', this.nestedCount(node))
                        : (this.kind === 'board' ? this.t.labels['delete-confirm-board'] : this.t.labels['delete-confirm']).replace(':name', node.name),
                    agree: () => this.run(this.$axios.delete(this.url(node.id))).then(() => this.$emitter.emit('dam:organize-refresh')),
                });
            },
            close() { this.isOpen = false; },
        },
    });

    // Sections (dam.sections.manage): rule, default sort, order.
    app.component('v-dam-section-manager', {
        template: '#v-dam-section-manager-template',

        data() {
            return { t: window.MothershipOrganize.t, isOpen: false, sections: [], busy: false, form: this.blank(), popularity: false };
        },

        computed: {
            sortChoices() {
                const options = [...(window.MothershipLibraryNavigation?.sortOptions ?? [])];
                options.unshift({ value: 'position:asc', label: this.t.sort.position });
                if (this.popularity) options.push({ value: 'popularity:desc', label: this.t.sort.popularity });

                return options;
            },
        },

        mounted() {
            this._onOpen = () => { this.form = this.blank(); this.isOpen = true; this.load(); };
            this.$emitter.on('dam:section-manager-open', this._onOpen);
        },

        beforeUnmount() { this.$emitter.off('dam:section-manager-open', this._onOpen); },

        methods: {
            blank() { return { id: null, originalName: '', name: '', media_types: [], extensions: '', default_sort: null }; },
            load() {
                return this.$axios.get(window.MothershipOrganize.url('organize'))
                    .then(({ data }) => { this.sections = data.sections; this.popularity = data.popularity; });
            },
            ruleSummary(section) {
                const parts = [
                    ...section.rule.media_types.map((type) => this.t['media-types'][type] ?? type),
                    ...section.rule.extensions.map((extension) => '.' + extension),
                ];

                return parts.length ? `${this.t.sections['rule-summary']}: ${parts.join(', ')}` : this.t.sections['no-rule'];
            },
            run(request) {
                this.busy = true;

                return request
                    .then(({ data }) => { if (data?.message) this.$emitter.emit('add-flash', { type: 'success', message: data.message }); this.$emitter.emit('dam:organize-changed'); this.$emitter.emit('dam:organize-refresh'); return this.load(); })
                    .catch((error) => { this.$emitter.emit('add-flash', { type: 'error', message: window.MothershipOrganize.error(error) }); throw error; })
                    .finally(() => { this.busy = false; });
            },
            edit(section) {
                this.form = { id: section.id, originalName: section.name, name: section.name, media_types: [...section.rule.media_types], extensions: section.rule.extensions.join(', '), default_sort: section.default_sort };
            },
            reset() { this.form = this.blank(); },
            save() {
                if (this.busy) return;
                const payload = {
                    name: this.form.name.trim(),
                    rule: { media_types: this.form.media_types, extensions: this.form.extensions.split(/[\s,]+/).filter(Boolean) },
                    default_sort: this.form.default_sort,
                };
                const request = this.form.id
                    ? this.$axios.put(window.MothershipOrganize.url('section', this.form.id), payload)
                    : this.$axios.post(window.MothershipOrganize.url('sections'), payload);
                this.run(request).then(() => this.reset()).catch(() => {});
            },
            move(section, position) { this.run(this.$axios.put(window.MothershipOrganize.url('section', section.id), { position })).catch(() => {}); },
            remove(section) {
                this.$emitter.emit('open-confirm-modal', {
                    message: this.t.sections['delete-confirm'].replace(':name', section.name),
                    agree: () => this.run(this.$axios.delete(window.MothershipOrganize.url('section', section.id))).catch(() => {}),
                });
            },
            close() { this.isOpen = false; },
        },
    });

    // Save the current Library query (never its results) under a name.
    app.component('v-dam-save-search', {
        template: '#v-dam-save-search-template',

        data() {
            return { t: window.MothershipOrganize.t, isOpen: false, definition: {}, name: '', pinned: true, visibility: 'private', busy: false };
        },

        mounted() {
            this._onOpen = ({ definition = {}, name = '' } = {}) => {
                this.definition = definition; this.name = name; this.pinned = true; this.visibility = 'private'; this.isOpen = true;
                this.$nextTick(() => this.$refs.name?.focus());
            };
            this.$emitter.on('dam:save-search-open', this._onOpen);
        },

        beforeUnmount() { this.$emitter.off('dam:save-search-open', this._onOpen); },

        methods: {
            save() {
                if (this.busy) return;
                this.busy = true;
                this.$axios.post(window.MothershipOrganize.url('searches'), { name: this.name.trim(), definition: this.definition, pinned: this.pinned, visibility: this.visibility })
                    .then(({ data }) => { this.$emitter.emit('add-flash', { type: 'success', message: data.message }); this.$emitter.emit('dam:organize-changed'); this.isOpen = false; })
                    .catch((error) => this.$emitter.emit('add-flash', { type: 'error', message: window.MothershipOrganize.error(error) }))
                    .finally(() => { this.busy = false; });
            },
            close() { this.isOpen = false; },
        },
    });
</script>
@endpush
@endonce
