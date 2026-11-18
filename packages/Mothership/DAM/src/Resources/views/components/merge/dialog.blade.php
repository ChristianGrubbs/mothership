{{--
    P3b-1 (D7), D5: the Library's "Create stack" dialog, on one screen. Review the selected files (drop any that do not
    belong), choose the cover the others stack under, optionally say what the stack is grouped by, read what changes,
    then confirm. The merge preview loads by itself and reloads whenever the files or the cover change; its full detail
    (sources, tags, properties, links, comments) folds under "Show all details". Opened by `dam:open-merge-dialog` with
    { assets: [{ id, name, thumb?, extension?, stack_basis?, stack_key? }], context, reviewId?, coverId?, alsoRefresh? }; coverId
    makes that file the default cover (else the first stack, else the first file). With exactly one stack among the files and
    that stack as the cover, the dialog is "Add to stack": its title, button and notice say so. A stack that other files are
    merged into can only receive files, so the preview's `nested` answer holds it as the cover and says why. alsoRefresh lists further contexts that
    receive `dam:merge-done` too (a drag across tabs). Answers `dam:merge-done` with
    { context, reviewId, destinationId } so the opening tab refreshes, clears its selection and points at the new stack,
    and `dam:merge-dialog-closed` with { reviewId, merged } whenever it closes. A request that arrives while the dialog is
    open waits its turn rather than replacing the review on screen.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-merge-dialog-template">
        <div
            v-if="open"
            ref="dialog"
            class="fixed inset-0 flex items-center justify-center bg-black/60 p-4"
            {{-- Above the upload panel (10005) it can be opened from, below flashes (10060) and the preview (10010). --}}
            style="z-index: 10007"
            role="dialog"
            aria-modal="true"
            aria-labelledby="dam-merge-title"
            aria-describedby="dam-merge-intro"
            tabindex="-1"
            data-dam-merge-dialog
            @click.self="close"
            @keydown.esc.prevent="close"
            @keydown.tab="trapFocus"
        >
            <div class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-cherry-900">
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <div class="min-w-0">
                        <h2 id="dam-merge-title" class="text-lg font-bold text-gray-800 dark:text-white" data-dam-merge-title>@{{ adding ? t.addTitle : t.title }}</h2>
                        <p id="dam-merge-intro" class="text-xs text-gray-500 dark:text-gray-400">@{{ intro }}</p>
                    </div>
                    <button type="button" class="shrink-0 rounded text-2xl leading-none text-gray-500 hover:text-gray-800 dark:hover:text-white" :aria-label="t.close" @click="close">×</button>
                </div>

                <div class="min-h-[10rem] flex-1 overflow-y-auto px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{-- 1. The files, the cover among them, and a way to drop any that were selected by mistake. --}}
                    {{-- min-w-0 and a minmax(0, 1fr) column: a fieldset and an auto grid track both grow to an unbroken file name,
                         which opened the review scrolled sideways with the other names cut off (G1-15). --}}
                    <fieldset class="min-w-0" data-dam-merge-pick>
                        <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.files-title')</legend>
                        <ul class="grid grid-cols-1 gap-1.5" data-dam-merge-files>
                            <li
                                v-for="asset in assets"
                                :key="asset.id"
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-2 py-1.5 transition-colors"
                                :class="destinationId === asset.id ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30' : 'border-gray-200 dark:border-gray-700'"
                                :data-dam-merge-file="asset.id"
                            >
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-100 dark:bg-cherry-800">
                                    <img v-if="asset.thumb && ! brokenThumbs[asset.id]" :src="asset.thumb" alt="" class="h-full w-full object-cover" loading="lazy" @@error="brokenThumbs[asset.id] = true">
                                    <span v-else class="text-[10px] font-bold uppercase text-gray-500 dark:text-gray-400">@{{ asset.extension || '?' }}</span>
                                </span>
                                {{-- The whole name wraps (it is what tells the files apart); the row wraps its controls below it when the dialog is narrow. --}}
                                <span class="min-w-[9rem] flex-1">
                                    <span class="block break-words font-medium text-gray-800 [overflow-wrap:anywhere] dark:text-gray-100" :title="asset.name">@{{ wrappable(asset.name) }}</span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                        <template v-if="asset.extension">@{{ asset.extension.toUpperCase() }}</template>
                                        <template v-if="asset.files > 1"> · @{{ t.filesCount.replace(':count', asset.files) }}</template>
                                    </span>
                                </span>
                                <label class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded px-2 py-1 text-xs font-semibold" :class="destinationId === asset.id ? 'text-primary-700 dark:text-primary-200' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white'">
                                    <input type="radio" name="dam-merge-destination" :value="asset.id" v-model="destinationId" class="h-4 w-4 accent-primary-600" :disabled="merging || coverLocked(asset)" :title="coverLocked(asset) ? t.stackLocked : null">
                                    @lang('dam::app.admin.dam.asset.merge.cover')
                                </label>
                                <button
                                    type="button"
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded text-lg leading-none text-gray-400 hover:bg-gray-100 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-cherry-800"
                                    :disabled="assets.length <= 2 || merging"
                                    :title="assets.length <= 2 ? t.minTwo : t.remove.replace(':name', asset.name)"
                                    :aria-label="t.remove.replace(':name', asset.name)"
                                    data-dam-merge-remove
                                    @click="remove(asset.id)"
                                >×</button>
                            </li>
                        </ul>
                        <p v-if="stackCoverId !== null" class="mt-2 text-xs font-semibold text-primary-700 dark:text-primary-300" role="status" data-dam-merge-stack-stays>@{{ t.stackStays.replace(':name', stackCoverName) }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.cover-hint')</p>
                    </fieldset>

                    {{-- 2. Optional: what the stack is grouped by, the same basis and key the Files tab's Stack panel sets. --}}
                    <fieldset class="mt-4" data-dam-merge-label>
                        <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.label-title')</legend>
                        <div class="flex flex-wrap gap-2">
                            <label class="flex min-w-[9rem] flex-col gap-1 text-xs text-gray-600 dark:text-gray-300">
                                @lang('dam::app.admin.dam.asset.stack.basis')
                                <select v-model="basis" class="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-700 dark:border-gray-600 dark:bg-cherry-800 dark:text-gray-200" :disabled="merging" data-dam-merge-basis>
                                    <option value="">@lang('dam::app.admin.dam.asset.merge.no-label')</option>
                                    <option v-for="(label, value) in bases" :key="value" :value="value">@{{ label }}</option>
                                </select>
                            </label>
                            <label v-if="basis" class="flex min-w-[12rem] flex-1 flex-col gap-1 text-xs text-gray-600 dark:text-gray-300">
                                @{{ keyLabels[basis] }}
                                <input
                                    ref="key"
                                    v-model="key"
                                    type="text"
                                    maxlength="191"
                                    class="rounded-md border px-2 py-1.5 text-sm text-gray-700 dark:bg-cherry-800 dark:text-gray-200"
                                    :class="keyMissing ? 'border-red-400 dark:border-red-500' : 'border-gray-300 dark:border-gray-600'"
                                    :placeholder="keyPlaceholders[basis]"
                                    :aria-invalid="keyMissing"
                                    :disabled="merging"
                                    data-dam-merge-key
                                >
                                <span v-if="keyMissing" class="text-red-600 dark:text-red-400">@lang('dam::app.admin.dam.asset.stack.key-required')</span>
                            </label>
                        </div>
                    </fieldset>

                    {{-- 3. What changes: a short summary, a blocking reason, and the full preview on request. --}}
                    <section class="mt-4" aria-live="polite" data-dam-merge-summary>
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.changes-title')</h3>
                        <p v-if="loading && ! preview" role="status" class="text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.preview-loading')</p>
                        <p v-else-if="error" role="alert" class="text-red-600 dark:text-red-400">
                            @{{ error }}
                            <button type="button" class="ml-1 font-semibold underline" @click="loadPreview">@lang('dam::app.admin.dam.asset.stack-fan.retry')</button>
                        </p>
                        <template v-else-if="preview">
                            <p v-if="preview.blocked" role="alert" class="mb-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200" data-dam-merge-blocked>@{{ preview.blocked.message }}</p>
                            {{-- A long cover name wraps instead of widening the dialog (G1-15). --}}
                            <ul v-if="! preview.blocked" class="grid min-w-0 grid-cols-1 gap-1 [overflow-wrap:anywhere]" :class="{ 'opacity-60': loading }" data-dam-merge-summary>
                                <li class="flex gap-2"><span aria-hidden="true">•</span><span>@{{ joinSummary }}</span></li>
                                <li class="flex gap-2" :class="accessWarning ? 'text-amber-700 dark:text-amber-300' : ''" data-dam-merge-access-summary>
                                    <span aria-hidden="true">•</span><span>@{{ accessSummary }}</span>
                                </li>
                                <li class="flex gap-2 text-gray-500 dark:text-gray-400"><span aria-hidden="true">•</span><span>@lang('dam::app.admin.dam.asset.merge.reversible')</span></li>
                            </ul>
                            <label class="mt-2 flex items-center gap-2">
                                <input type="checkbox" v-model="unionTags" class="h-4 w-4 accent-primary-600" :disabled="loading || merging" @change="loadPreview" data-dam-merge-union>
                                @lang('dam::app.admin.dam.asset.merge.union-tags')
                            </label>

                            <details class="mt-3 rounded border border-gray-200 dark:border-gray-700" data-dam-merge-details>
                                <summary class="cursor-pointer select-none px-3 py-2 text-xs font-semibold text-primary-700 dark:text-primary-300">@lang('dam::app.admin.dam.asset.merge.show-details')</summary>
                                <div class="border-t border-gray-200 px-3 py-2 [overflow-wrap:anywhere] dark:border-gray-700">
                                    <section v-for="source in preview.sources" :key="source.id" class="mb-4 rounded border border-gray-200 p-3 dark:border-gray-700" data-dam-merge-source>
                                        <h4 class="font-semibold text-gray-800 dark:text-white">@{{ t.fromSource.replace(':name', source.name) }}</h4>

                                        <h5 class="mt-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.files-linked')</h5>
                                        <ul class="mt-1 grid gap-1">
                                            <li v-for="file in source.files" :key="file.attachment_id" class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-full bg-primary-100 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-900 dark:text-primary-200">@{{ roleLabels[file.role_on_merge] || file.role_on_merge }}</span>
                                                <span class="min-w-0 break-words">@{{ file.label }}</span>
                                            </li>
                                        </ul>
                                        <p v-if="source.kept_on_source.length" class="mt-1 text-xs text-gray-500 dark:text-gray-400">@{{ choice(t.keptOnSource, source.kept_on_source.length).replace(':count', source.kept_on_source.length) }}</p>

                                        <h5 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.access')</h5>
                                        <p v-if="source.access.gains.length" class="mt-1" :class="source.access.allowed ? '' : 'text-red-700 dark:text-red-300'" data-dam-merge-gains>@{{ t.gains.replace(':names', names(source.access.gains)) }}</p>
                                        <p v-if="source.access.losses.length" class="mt-1">@{{ t.losses.replace(':names', names(source.access.losses)) }}</p>
                                        <p v-if="source.access.links" class="mt-1" :class="source.access.allowed ? '' : 'text-red-700 dark:text-red-300'" data-dam-merge-link-gains>@{{ choice(t.linkGains, source.access.links).replace(':count', source.access.links) }}</p>
                                        <p v-if="! source.access.gains.length && ! source.access.losses.length && ! source.access.links" class="mt-1 text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.no-access-change')</p>

                                        <h5 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.links')</h5>
                                        <p v-if="source.links.direct.length" class="mt-1">@{{ choice(t.linksDirect, source.links.direct.length).replace(':count', source.links.direct.length) }}</p>
                                        <p v-if="source.links.folders.length" class="mt-1">@{{ t.linksFolders.replace(':names', source.links.folders.map(f => f.folder_name || '…').join(', ')) }}</p>
                                        <p v-if="! source.links.direct.length && ! source.links.folders.length" class="mt-1 text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.no-links')</p>

                                        <h5 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.incoming')</h5>
                                        <p v-if="source.incoming.edges.length" class="mt-1">@{{ source.incoming.edges.map(e => e.from.name).join(', ') }}</p>
                                        <p v-if="source.incoming.restricted" class="mt-1 text-gray-500 dark:text-gray-400">@{{ choice(t.incomingRestricted, source.incoming.restricted).replace(':count', source.incoming.restricted) }}</p>
                                        <p v-if="! source.incoming.edges.length && ! source.incoming.restricted" class="mt-1 text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.no-incoming')</p>

                                        <p v-if="source.comments" class="mt-3 text-gray-600 dark:text-gray-300">@{{ choice(t.comments, source.comments).replace(':count', source.comments) }}</p>
                                    </section>

                                    <section class="mb-2" data-dam-merge-metadata>
                                        <h4 class="font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.asset.merge.metadata')</h4>

                                        <h5 class="mt-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.tags')</h5>
                                        <ul v-if="preview.metadata.tags.length" class="mt-1 flex flex-wrap gap-2">
                                            <li v-for="tag in preview.metadata.tags" :key="tag.name" class="rounded-full px-2 py-0.5 text-xs" :class="tagClass(tag.result)">
                                                @{{ tag.name }} · @{{ tagResults[tag.result] }}
                                            </li>
                                        </ul>
                                        <p v-else class="mt-1 text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.none')</p>

                                        <h5 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.properties')</h5>
                                        <ul v-if="preview.metadata.properties.length" class="mt-1 grid gap-1">
                                            <li v-for="property in preview.metadata.properties" :key="property.name + '|' + property.language" class="flex flex-wrap gap-x-2">
                                                <span class="font-semibold">@{{ property.name }}</span>
                                                <span class="min-w-0 break-words">@{{ property.value }}</span>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">@{{ propertySource(property) }}</span>
                                                <span v-if="property.conflict" class="text-xs text-amber-700 dark:text-amber-300">@lang('dam::app.admin.dam.asset.merge.conflict')</span>
                                            </li>
                                        </ul>
                                        <p v-else class="mt-1 text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.none')</p>

                                        <template v-if="preview.metadata.fields.length">
                                            <h5 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.fields')</h5>
                                            <p v-for="field in preview.metadata.fields" :key="field.field" class="mt-1">
                                                @{{ field.destination || t.none }}
                                                <span v-if="field.conflict" class="text-xs text-amber-700 dark:text-amber-300">· @lang('dam::app.admin.dam.asset.merge.conflict')</span>
                                            </p>
                                        </template>
                                    </section>
                                </div>
                            </details>
                        </template>
                    </section>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                    <button type="button" class="secondary-button" :disabled="merging" @click="close">@lang('dam::app.admin.dam.asset.merge.cancel')</button>
                    <button type="button" class="primary-button" :disabled="! canConfirm" @click="confirm" data-dam-merge-confirm>
                        @{{ merging ? t.merging : confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-dam-merge-dialog', {
            template: '#v-dam-merge-dialog-template',

            data() {
                return {
                    open: false,
                    assets: [],
                    context: null,
                    alsoRefresh: [],
                    destinationId: null,
                    unionTags: true,
                    preview: null,
                    loading: false,
                    merging: false,
                    reviewId: null,
                    /** The near-duplicate group this review was opened from, `{ group_key, members }`: sent with the preview and the confirmation, which the server refuses (409) once that group changed. */
                    review: null,
                    /** Reviews asked for while this one is open, shown in turn. */
                    queue: [],
                    error: '',
                    basis: '',
                    key: '',
                    triedConfirm: false,
                    labelTouched: false,
                    /** A stack that other files are already merged into: it can only receive files, never join another stack. */
                    stackCoverId: null,
                    // True while show() seeds the fields, so the watchers below treat it as no one's edit.
                    seeding: false,
                    brokenThumbs: {},
                    returnFocus: null,
                    requestGeneration: 0,
                    roleLabels: @js(trans('dam::app.admin.dam.asset.files.roles')),
                    bases: @js(trans('dam::app.admin.dam.asset.stack.bases')),
                    keyLabels: @js(trans('dam::app.admin.dam.asset.stack.key-labels')),
                    keyPlaceholders: @js(trans('dam::app.admin.dam.asset.stack.key-placeholders')),
                    tagResults: {
                        kept: @js(trans('dam::app.admin.dam.asset.merge.tag-kept')),
                        added: @js(trans('dam::app.admin.dam.asset.merge.tag-added')),
                        not_added: @js(trans('dam::app.admin.dam.asset.merge.tag-not-added')),
                    },
                    t: {
                        intro: @js(trans('dam::app.admin.dam.asset.merge.intro')),
                        close: @js(trans('dam::app.admin.dam.asset.merge.close')),
                        remove: @js(trans('dam::app.admin.dam.asset.merge.remove')),
                        minTwo: @js(trans('dam::app.admin.dam.asset.merge.min-two')),
                        filesCount: @js(trans('dam::app.admin.dam.asset.stack.files-count')),
                        joins: @js(trans('dam::app.admin.dam.asset.merge.summary-joins')),
                        accessSame: @js(trans('dam::app.admin.dam.asset.merge.no-access-change')),
                        accessGains: @js(trans('dam::app.admin.dam.asset.merge.summary-gains')),
                        accessLinks: @js(trans('dam::app.admin.dam.asset.merge.summary-links')),
                        accessLosses: @js(trans('dam::app.admin.dam.asset.merge.summary-losses')),
                        fromSource: @js(trans('dam::app.admin.dam.asset.merge.from-source')),
                        fromDestination: @js(trans('dam::app.admin.dam.asset.merge.from-destination')),
                        keptOnSource: @js(trans('dam::app.admin.dam.asset.merge.kept-on-source')),
                        gains: @js(trans('dam::app.admin.dam.asset.merge.gains')),
                        linkGains: @js(trans('dam::app.admin.dam.asset.merge.link-gains')),
                        losses: @js(trans('dam::app.admin.dam.asset.merge.losses')),
                        linksDirect: @js(trans('dam::app.admin.dam.asset.merge.links-direct')),
                        linksFolders: @js(trans('dam::app.admin.dam.asset.merge.links-folders')),
                        incomingRestricted: @js(trans('dam::app.admin.dam.asset.merge.incoming-restricted')),
                        comments: @js(trans('dam::app.admin.dam.asset.merge.comments')),
                        none: @js(trans('dam::app.admin.dam.asset.merge.none')),
                        title: @js(trans('dam::app.admin.dam.asset.merge.dialog-title')),
                        addTitle: @js(trans('dam::app.admin.dam.asset.merge.add-dialog-title')),
                        addIntro: @js(trans('dam::app.admin.dam.asset.merge.add-intro')),
                        addConfirm: @js(trans('dam::app.admin.dam.asset.merge.add-confirm')),
                        added: @js(trans('dam::app.admin.dam.asset.merge.added')),
                        stackStays: @js(trans('dam::app.admin.dam.asset.merge.stack-stays')),
                        stackLocked: @js(trans('dam::app.admin.dam.asset.merge.stack-locked')),
                        confirm: @js(trans('dam::app.admin.dam.asset.merge.confirm')),
                        merging: @js(trans('dam::app.admin.dam.asset.merge.merging')),
                        needTwo: @js(trans('dam::app.admin.dam.asset.merge.need-two')),
                        previewFailed: @js(trans('dam::app.admin.dam.asset.merge.preview-failed')),
                        created: @js(trans('dam::app.admin.dam.asset.merge.created')),
                        labelFailed: @js(trans('dam::app.admin.dam.asset.merge.label-failed')),
                        failed: @js(trans('dam::app.admin.dam.asset.merge.action-failed')),
                    },
                };
            },

            computed: {
                destination() {
                    return this.assets.find((asset) => asset.id === this.destinationId) || null;
                },
                /** Files are being added to a stack: exactly one file in the review already is one, and it is the representative. */
                adding() {
                    const stacks = this.assets.filter((asset) => asset.files > 1);

                    return stacks.length === 1 && stacks[0].id === this.destinationId;
                },
                intro() {
                    const count = this.assets.length - (this.adding ? 1 : 0);

                    return this.adding
                        ? this.choice(this.t.addIntro, count).replace(':count', count).replace(':name', this.destination?.name || '')
                        : this.choice(this.t.intro, count).replace(':count', count);
                },
                confirmLabel() {
                    const count = this.assets.length - (this.adding ? 1 : 0);

                    return this.adding
                        ? this.choice(this.t.addConfirm, count).replace(':count', count)
                        : this.t.confirm.replace(':count', count);
                },
                stackCoverName() {
                    return this.assets.find((asset) => asset.id === this.stackCoverId)?.name || '';
                },
                sourceIds() {
                    return this.assets.map((asset) => asset.id).filter((id) => id !== this.destinationId);
                },
                keyMissing() {
                    return !! this.basis && this.triedConfirm && this.key.trim() === '';
                },
                /** The label only changes when someone picked or edited one; an untouched cover keeps its own. */
                labelChanged() {
                    const current = this.destination;

                    return (this.basis || null) !== (current?.stack_basis || null)
                        || (this.basis ? this.key.trim() : null) !== (this.basis ? String(current?.stack_key || '').trim() : null);
                },
                canConfirm() {
                    return !! this.preview && ! this.preview.blocked && ! this.loading && ! this.merging && this.assets.length >= 2;
                },
                joinSummary() {
                    // A refused merge joins nothing: the refusal above is all the review says about what happens.
                    if (this.preview?.blocked) return '';

                    const files = (this.preview?.sources || []).reduce((sum, source) => sum + source.files.length, 0);

                    return this.choice(this.t.joins, files)
                        .replace(':count', files)
                        .replace(':name', this.destination?.name || '');
                },
                accessWarning() {
                    return (this.preview?.sources || []).some((source) => source.access.gains.length || source.access.links);
                },
                accessSummary() {
                    const sources = this.preview?.sources || [];
                    const gains = new Set(sources.flatMap((source) => source.access.gains.map((person) => person.name)));
                    const losses = new Set(sources.flatMap((source) => source.access.losses.map((person) => person.name)));
                    const links = sources.reduce((sum, source) => sum + Number(source.access.links || 0), 0);
                    const parts = [];
                    if (gains.size) parts.push(this.choice(this.t.accessGains, gains.size).replace(':count', gains.size));
                    if (links) parts.push(this.choice(this.t.accessLinks, links).replace(':count', links));
                    if (losses.size) parts.push(this.choice(this.t.accessLosses, losses.size).replace(':count', losses.size));

                    return parts.length ? parts.join(' ') : this.t.accessSame;
                },
            },

            watch: {
                destinationId(id, previous) {
                    if (! this.open || this.seeding || id === previous) return;
                    // A cover that already has a label brings it along; otherwise keep what was typed.
                    const cover = this.destination;
                    if (cover?.stack_basis && ! this.labelTouched) {
                        this.basis = cover.stack_basis;
                        this.key = cover.stack_key || '';
                    }
                    this.loadPreview();
                },
                basis(value) {
                    if (! this.open || this.seeding) return;
                    this.labelTouched = true;
                    if (value) this.$nextTick(() => this.$refs.key?.focus());
                },
                key() {
                    if (this.open && ! this.seeding) this.labelTouched = true;
                },
            },

            mounted() {
                this._onOpen = (request = {}) => {
                    // Queue behind any waiting review too, so reviews open in arrival order.
                    if (this.open || this.queue.length) {
                        this.queue.push(request);
                        return;
                    }
                    this.show(request.assets, request.context, request.reviewId, request);
                };
                this.$emitter.on('dam:open-merge-dialog', this._onOpen);
                // Lets the upload panel know a review can open here; pages without this dialog skip the stack offer.
                window.damMergeDialogReady = true;
            },

            beforeUnmount() {
                this.$emitter.off('dam:open-merge-dialog', this._onOpen);
                window.damMergeDialogReady = false;
            },

            methods: {
                // Display only: a break opportunity after _ and . lets an underscore or dot name wrap between its parts, not mid-number.
                wrappable(name) {
                    return String(name).replace(/([_.])/g, '$1\u200b');
                },

                /** The singular or plural half of a "one|many" string. */
                choice(text, count) {
                    const [one, many] = String(text).split('|');

                    return Number(count) === 1 || many === undefined ? one : many;
                },

                show(assets, context, reviewId = null, options = {}) {
                    const list = (Array.isArray(assets) ? assets : [])
                        .map((asset) => ({
                            id: Number(asset.id),
                            name: String(asset.name || asset.id),
                            thumb: asset.thumb || null,
                            extension: asset.extension ? String(asset.extension).toLowerCase() : '',
                            files: Number(asset.attachment_count || 0),
                            stack_basis: asset.stack_basis || null,
                            stack_key: asset.stack_key || null,
                        }))
                        .filter((asset) => asset.id > 0);

                    if (list.length < 2) {
                        this.$emitter.emit('add-flash', { type: 'warning', message: this.t.needTwo });
                        this.$emitter.emit('dam:merge-dialog-closed', { reviewId, merged: false });
                        this.showNext();
                        return;
                    }

                    this.seeding = true;
                    this.returnFocus = document.activeElement;
                    this.assets = list;
                    this.context = context ?? null;
                    this.reviewId = reviewId ?? null;
                    this.review = options?.review?.group_key && Array.isArray(options.review.members)
                        ? { group_key: String(options.review.group_key), members: options.review.members.map(Number) }
                        : null;
                    this.alsoRefresh = Array.isArray(options?.alsoRefresh) ? [...options.alsoRefresh] : [];
                    // The cover defaults to a file that already is a stack, so adding to a stack keeps its name.
                    const cover = list.find((asset) => asset.id === Number(options?.coverId))
                        || list.find((asset) => asset.files > 1) || list[0];
                    this.destinationId = cover.id;
                    this.stackCoverId = null;
                    this.basis = cover.stack_basis || '';
                    this.key = cover.stack_key || '';
                    this.unionTags = true;
                    this.preview = null;
                    this.error = '';
                    this.triedConfirm = false;
                    this.brokenThumbs = {};
                    this.open = true;
                    this.labelTouched = false;
                    this.$nextTick(() => {
                        this.seeding = false;
                        this.$refs.dialog?.querySelector('input[type=radio]:checked')?.focus();
                    });
                    this.loadPreview();
                },

                close({ merged = false } = {}) {
                    if (this.merging) return;
                    this.requestGeneration++;
                    this.open = false;
                    this.$emitter.emit('dam:merge-dialog-closed', { reviewId: this.reviewId, merged });
                    this.$nextTick(() => {
                        this.returnFocus?.focus?.();
                        this.showNext();
                    });
                },

                showNext() {
                    if (this.open || ! this.queue.length) return;
                    const next = this.queue.shift();
                    this.show(next.assets, next.context, next.reviewId, next);
                },

                remove(id) {
                    if (this.assets.length <= 2 || this.merging) return;
                    const index = this.assets.findIndex((asset) => asset.id === id);
                    this.assets = this.assets.filter((asset) => asset.id !== id);
                    // The held stack left the review: nothing is held as the representative any more.
                    if (this.stackCoverId === id) this.stackCoverId = null;
                    if (this.destinationId === id) {
                        this.destinationId = this.assets[0].id;
                    } else {
                        this.loadPreview();
                    }
                    // Keep the keyboard on the list: the next row's remove button, else the previous one.
                    this.$nextTick(() => {
                        const buttons = this.$refs.dialog?.querySelectorAll('[data-dam-merge-remove]') || [];
                        (buttons[Math.min(index, buttons.length - 1)]?.disabled ? this.$refs.dialog?.querySelector('input[type=radio]:checked') : buttons[Math.min(index, buttons.length - 1)])?.focus();
                    });
                },

                /** Keeps Tab and Shift+Tab inside the dialog while it is open. */
                trapFocus(event) {
                    const focusable = Array.from(this.$refs.dialog?.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), summary, [href], [tabindex]:not([tabindex="-1"])') || []);
                    if (! focusable.length) return;
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                },

                /** A stack with other files merged into it cannot become part of another stack, so it is held as the representative. */
                coverLocked(asset) {
                    return this.stackCoverId !== null && asset.id !== this.stackCoverId;
                },

                /**
                 * The merge preview names a source that other files are merged into (`nested`). Asking for it as a source would
                 * be refused, so it is made the representative instead and the new files are added to it; the dialog says so.
                 */
                keepMergedStackAsCover(preview) {
                    const stack = (preview?.sources || []).find((source) => source.nested);

                    if (! stack || this.stackCoverId !== null || ! this.assets.some((asset) => asset.id === Number(stack.id))) return false;

                    this.stackCoverId = Number(stack.id);
                    this.destinationId = this.stackCoverId;

                    return true;
                },

                url(name, id = this.destinationId) {
                    const urls = {
                        preview: @js(route('admin.dam.assets.merges.preview', ['asset' => '__ASSET__'])),
                        store: @js(route('admin.dam.assets.merges.store', ['asset' => '__ASSET__'])),
                        stack: @js(route('admin.dam.assets.stack.update', ['asset' => '__ASSET__'])),
                    };

                    return urls[name].replace('__ASSET__', id);
                },

                async loadPreview() {
                    // Only the newest request may land: the cover, the files or the tag choice can change mid-flight.
                    const generation = ++this.requestGeneration;
                    this.loading = true;
                    this.error = '';
                    try {
                        const { data } = await this.$axios.get(this.url('preview'), { params: { sources: this.sourceIds, union_tags: this.unionTags ? 1 : 0, ...(this.review ? { review_key: this.review.group_key, review_members: this.review.members } : {}) } });
                        if (generation !== this.requestGeneration) return;
                        this.preview = data;
                        // The stack now leads, and the preview reloads for it: nothing to show for the refused one meanwhile.
                        if (this.keepMergedStackAsCover(data)) this.preview = null;
                    } catch (error) {
                        if (generation !== this.requestGeneration) return;
                        this.preview = null;
                        this.error = error.response?.data?.message || this.t.previewFailed;
                    } finally {
                        if (generation === this.requestGeneration) this.loading = false;
                    }
                },

                async confirm() {
                    this.triedConfirm = true;
                    if (! this.canConfirm) return;
                    if (this.basis && this.key.trim() === '') {
                        this.$refs.key?.focus();
                        return;
                    }
                    this.merging = true;
                    // Everything the confirmation decided, taken now: nothing after the first await may read live state.
                    const destinationId = this.destinationId;
                    const name = this.destination?.name || '';
                    const adding = this.adding;
                    const count = this.assets.length - (adding ? 1 : 0);
                    const context = this.context;
                    const alsoRefresh = [...this.alsoRefresh];
                    const reviewId = this.reviewId;
                    const review = this.review;
                    const label = this.labelChanged ? { basis: this.basis || null, key: this.basis ? this.key.trim() : null } : null;
                    try {
                        const { data } = await this.$axios.post(this.url('store'), { sources: this.sourceIds, union_tags: this.unionTags, ...(review ? { review } : {}) });
                        let labelFailed = false;
                        if (label) {
                            try {
                                await this.$axios.put(this.url('stack', destinationId), label);
                            } catch (_) {
                                labelFailed = true;
                            }
                        }
                        this.$emitter.emit('add-flash', { type: 'success', message: (adding ? this.choice(this.t.added, count) : this.t.created).replace(':name', name).replace(':count', count) });
                        if (labelFailed) this.$emitter.emit('add-flash', { type: 'warning', message: this.t.labelFailed });
                        this.$emitter.emit('dam:merge-done', { context, alsoRefresh, reviewId, destinationId, message: data.message });
                        this.merging = false;
                        this.close({ merged: true });
                    } catch (error) {
                        this.merging = false;
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || this.t.failed });
                        this.loadPreview();
                    }
                },

                names(people) {
                    return people.map((person) => person.name).join(', ');
                },

                tagClass(result) {
                    return {
                        kept: 'bg-gray-100 text-gray-700 dark:bg-cherry-800 dark:text-gray-200',
                        added: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                        not_added: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
                    }[result];
                },

                propertySource(property) {
                    if (property.result === 'destination') return this.t.fromDestination;
                    const id = Number(String(property.result).split(':')[1]);
                    const source = this.preview?.sources.find((row) => row.id === id);

                    return this.t.fromSource.replace(':name', source?.name || id);
                },
            },
        });
    </script>
@endPushOnce

<v-dam-merge-dialog></v-dam-merge-dialog>
