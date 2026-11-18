{{--
    P3b-1 (D7): the destination's "Merged from N assets" list on its Files tab. Each merge shows the source by the name
    it had, the files it gave, its comments (they stay on the source) and its live links, with Split (a per-slot choice
    for slots changed since the merge), Finalize (explicit, irreversible) and Revoke links. A finalized merge stays
    listed, labelled finalized, with no actions: its comments were copied into this asset's own.
--}}
<v-dam-asset-merges :initial-asset-id="{{ $assetId }}"></v-dam-asset-merges>

@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-asset-merges-template">
        <section v-if="merges.length" class="mt-3.5 rounded-lg bg-white p-5 box-shadow dark:bg-cherry-900" data-dam-merges>
            <h2 class="text-base font-semibold">@{{ choice(t.mergedFrom, merges.length).replace(':count', merges.length) }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.merged-from-hint')</p>

            <ul class="mt-4 grid gap-3">
                <li v-for="merge in merges" :key="merge.id" class="rounded border border-gray-200 p-3 text-sm dark:border-gray-700" data-dam-merge-row>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="break-words font-semibold text-gray-800 dark:text-white">
                                @{{ merge.source.name }}
                                <span v-if="merge.status === 'finalized'" class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200" data-dam-merge-finalized>@{{ t.finalizedOn.replace(':date', formatDate(merge.finalized_at)) }}</span>
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @{{ t.mergedBy.replace(':date', formatDate(merge.merged_at)).replace(':name', merge.merged_by || systemLabel) }}
                                · @{{ choice(t.filesCount, merge.files).replace(':count', merge.files) }}
                                <template v-if="merge.active_links"> · @{{ choice(t.activeLinks, merge.active_links).replace(':count', merge.active_links) }}</template>
                            </p>
                        </div>
                        <div v-if="canEdit && merge.status === 'active'" class="flex shrink-0 flex-wrap items-center gap-3">
                            <button type="button" class="text-primary-600" :disabled="busy" @click="openSplit(merge)" data-dam-merge-split>@lang('dam::app.admin.dam.asset.merge.split')</button>
                            <button type="button" class="text-primary-600" :disabled="busy" @click="openFinalize(merge)" data-dam-merge-finalize>@lang('dam::app.admin.dam.asset.merge.finalize')</button>
                            <button v-if="merge.can_revoke_links && merge.active_links" type="button" class="font-semibold text-red-700 dark:text-red-300" :disabled="busy" @click="revokeLinks(merge)" data-dam-merge-revoke>@lang('dam::app.admin.dam.asset.merge.revoke-links')</button>
                        </div>
                    </div>

                    <p v-if="merge.status === 'finalized' && merge.comments.count" class="mt-2 text-xs text-gray-500 dark:text-gray-400">@{{ choice(t.commentsCopied, merge.comments.count).replace(':count', merge.comments.count) }}</p>
                    <template v-else-if="merge.comments.count">
                        <button type="button" class="mt-2 text-xs text-primary-600" :aria-expanded="commentsFor === merge.id" @click="commentsFor = commentsFor === merge.id ? null : merge.id">
                            @{{ commentsFor === merge.id ? t.hideComments : t.showComments.replace(':count', merge.comments.count) }}
                        </button>
                        <ol v-if="commentsFor === merge.id" class="mt-2 grid gap-2 border-l-2 border-gray-200 pl-3 dark:border-gray-700" :aria-label="t.commentsOn.replace(':name', merge.source.name)">
                            <li v-for="comment in merge.comments.items" :key="comment.id">
                                <p class="text-xs text-gray-500 dark:text-gray-400">@{{ comment.author || systemLabel }} · @{{ formatDate(comment.created_at) }}</p>
                                <p class="whitespace-pre-wrap break-words">@{{ comment.text }}</p>
                            </li>
                        </ol>
                    </template>
                </li>
            </ul>

            <div v-if="dialog" ref="dialog" class="fixed inset-0 z-[10001] flex items-center justify-center bg-black/60 p-4" :role="dialog.kind === 'finalize' ? 'alertdialog' : 'dialog'" aria-modal="true" aria-labelledby="dam-merge-dialog-title" tabindex="-1" @click.self="closeDialog" @keydown.esc.prevent="closeDialog" @keydown.tab="trapFocus" data-dam-merge-action-dialog>
                <div class="flex max-h-full w-full max-w-xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-cherry-900">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                        <h2 id="dam-merge-dialog-title" class="min-w-0 break-words text-lg font-bold text-gray-800 dark:text-white">@{{ dialogTitle }}</h2>
                        <button type="button" class="shrink-0 text-2xl leading-none text-gray-500 hover:text-gray-800 dark:hover:text-white" :aria-label="t.close" @click="closeDialog">×</button>
                    </div>

                    <div class="flex-1 overflow-y-auto px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                        <template v-if="dialog.kind === 'split'">
                            <p v-if="dialog.loading" role="status" class="py-6 text-center text-gray-500">@lang('dam::app.admin.dam.asset.merge.preview-loading')</p>
                            <template v-else-if="dialog.plan">
                                <p data-dam-split-intro>@{{ splitIntro }}</p>
                                <ul class="mt-3 grid gap-3">
                                    <li v-for="slot in dialog.plan.slots" :key="slot.attachment_id" class="rounded border border-gray-200 p-3 dark:border-gray-700" data-dam-split-slot>
                                        <p class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full bg-primary-100 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-900 dark:text-primary-200">@{{ roleLabels[slot.role] || slot.role }}</span>
                                            <span class="min-w-0 break-words">@{{ slot.label }}</span>
                                        </p>
                                        <p v-if="slot.pinned" class="mt-2 text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.merge.split-pinned')</p>
                                        <fieldset v-else-if="slot.asks" class="mt-2">
                                            <legend class="text-xs text-gray-600 dark:text-gray-300">@{{ choice(t.splitAsks, slot.new_revisions).replace(':count', slot.new_revisions) }}</legend>
                                            <label class="mt-1 flex items-center gap-2">
                                                <input type="radio" :name="'dam-split-' + slot.attachment_id" value="destination" v-model="dialog.choices[slot.attachment_id]" class="h-4 w-4 accent-primary-600">
                                                @lang('dam::app.admin.dam.asset.merge.keep-destination')
                                            </label>
                                            <label class="mt-1 flex items-center gap-2">
                                                <input type="radio" :name="'dam-split-' + slot.attachment_id" value="source" v-model="dialog.choices[slot.attachment_id]" class="h-4 w-4 accent-primary-600">
                                                @{{ choice(t.copySource, slot.new_revisions).replace(':name', dialog.merge.source.name) }}
                                            </label>
                                        </fieldset>
                                    </li>
                                </ul>
                                <p v-if="dialog.plan.tags_removed.length" class="mt-3">@{{ t.tagsRemoved.replace(':names', dialog.plan.tags_removed.join(', ')) }}</p>
                                <p v-if="dialog.plan.properties_removed.length" class="mt-1">@{{ t.propertiesRemoved.replace(':names', dialog.plan.properties_removed.join(', ')) }}</p>
                            </template>
                        </template>

                        <template v-else>
                            <p id="dam-merge-finalize-message">@{{ t.finalizeMessage.replace(':name', dialog.merge.source.name) }}</p>
                            <label class="mt-3 flex items-center gap-2 font-semibold">
                                <input type="checkbox" v-model="dialog.confirmed" class="h-4 w-4 accent-primary-600" data-dam-finalize-check>
                                @lang('dam::app.admin.dam.asset.merge.finalize-check')
                            </label>
                        </template>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                        <button type="button" class="secondary-button" :disabled="busy" @click="closeDialog">@lang('dam::app.admin.dam.asset.merge.cancel')</button>
                        <button v-if="dialog.kind === 'split'" type="button" class="primary-button" :disabled="busy || dialog.loading || ! dialog.plan" @click="split" data-dam-split-confirm>@{{ dialog.fromFile ? t.unstackConfirm : @js(trans('dam::app.admin.dam.asset.merge.split-confirm')) }}</button>
                        <button v-else type="button" class="primary-button" :disabled="busy || ! dialog.confirmed" @click="finalize" data-dam-finalize-confirm>@lang('dam::app.admin.dam.asset.merge.finalize-confirm')</button>
                    </div>
                </div>
            </div>
        </section>
    </script>

    <script type="module">
        app.component('v-dam-asset-merges', {
            template: '#v-dam-asset-merges-template',

            props: {
                initialAssetId: { type: Number, required: true },
            },

            data() {
                return {
                    assetId: this.initialAssetId,
                    merges: [],
                    canEdit: false,
                    folder: null,
                    busy: false,
                    commentsFor: null,
                    dialog: null,
                    returnFocus: null,
                    roleLabels: @js(trans('dam::app.admin.dam.asset.files.roles')),
                    systemLabel: @js(trans('dam::app.admin.dam.asset.files.by-system')),
                    t: {
                        mergedFrom: @js(trans('dam::app.admin.dam.asset.merge.merged-from')),
                        mergedBy: @js(trans('dam::app.admin.dam.asset.merge.merged-by')),
                        filesCount: @js(trans('dam::app.admin.dam.asset.merge.files-count')),
                        activeLinks: @js(trans('dam::app.admin.dam.asset.merge.active-links')),
                        showComments: @js(trans('dam::app.admin.dam.asset.merge.show-comments')),
                        finalizedOn: @js(trans('dam::app.admin.dam.asset.merge.finalized-on')),
                        commentsCopied: @js(trans('dam::app.admin.dam.asset.merge.comments-copied')),
                        hideComments: @js(trans('dam::app.admin.dam.asset.merge.hide-comments')),
                        commentsOn: @js(trans('dam::app.admin.dam.asset.merge.comments-on')),
                        close: @js(trans('dam::app.admin.dam.asset.merge.close')),
                        splitTitle: @js(trans('dam::app.admin.dam.asset.merge.split-title')),
                        unstackTitle: @js(trans('dam::app.admin.dam.asset.merge.unstack-title')),
                        unstackIntro: @js(trans('dam::app.admin.dam.asset.merge.unstack-intro')),
                        unstackIntroNoFolder: @js(trans('dam::app.admin.dam.asset.merge.unstack-intro-no-folder')),
                        unstackConfirm: @js(trans('dam::app.admin.dam.asset.merge.unstack-confirm')),
                        splitAsks: @js(trans('dam::app.admin.dam.asset.merge.split-asks')),
                        copySource: @js(trans('dam::app.admin.dam.asset.merge.copy-source')),
                        tagsRemoved: @js(trans('dam::app.admin.dam.asset.merge.tags-removed')),
                        propertiesRemoved: @js(trans('dam::app.admin.dam.asset.merge.properties-removed')),
                        finalizeTitle: @js(trans('dam::app.admin.dam.asset.merge.finalize-title')),
                        finalizeMessage: @js(trans('dam::app.admin.dam.asset.merge.finalize-message')),
                        revokeConfirm: @js(trans('dam::app.admin.dam.asset.merge.revoke-confirm')),
                        loadFailed: @js(trans('dam::app.admin.dam.asset.merge.load-failed')),
                        failed: @js(trans('dam::app.admin.dam.asset.merge.action-failed')),
                    },
                };
            },

            computed: {
                dialogTitle() {
                    if (! this.dialog) return '';

                    if (this.dialog.kind === 'split' && this.dialog.fromFile) return this.t.unstackTitle.replace(':name', this.dialog.merge.source.name);

                    return (this.dialog.kind === 'split' ? this.t.splitTitle : this.t.finalizeTitle).replace(':name', this.dialog.merge.source.name);
                },
                splitIntro() {
                    if (! this.dialog?.fromFile) return @js(trans('dam::app.admin.dam.asset.merge.split-intro'));

                    return this.folder ? this.t.unstackIntro.replace(':folder', this.folder) : this.t.unstackIntroNoFolder;
                },
            },

            mounted() {
                this.load();
                this._onAssetChange = ({ asset } = {}) => {
                    if (! asset?.id) return;
                    this.assetId = Number(asset.id);
                    this.dialog = null;
                    this.load();
                };
                this.$emitter.on('dam-asset-changed', this._onAssetChange);
                // A file row's "Remove from stack" is this same Split, opened for the merge that linked the file in.
                this._onSplitRequest = async ({ assetId, mergeId } = {}) => {
                    if (Number(assetId) !== Number(this.assetId)) return;
                    if (! this.merges.some((merge) => Number(merge.id) === Number(mergeId))) await this.load();
                    const merge = this.merges.find((candidate) => Number(candidate.id) === Number(mergeId) && candidate.status === 'active');
                    if (merge) this.openSplit(merge, { fromFile: true });
                };
                this.$emitter.on('dam-merge-split-request', this._onSplitRequest);
            },

            beforeUnmount() {
                this.$emitter.off('dam-asset-changed', this._onAssetChange);
                this.$emitter.off('dam-merge-split-request', this._onSplitRequest);
            },

            methods: {
                url(name, mergeId = '') {
                    const urls = {
                        index: @js(route('admin.dam.assets.merges.index', ['asset' => '__ASSET__'])),
                        splitPreview: @js(route('admin.dam.assets.merges.split_preview', ['asset' => '__ASSET__', 'merge' => '__MERGE__'])),
                        split: @js(route('admin.dam.assets.merges.split', ['asset' => '__ASSET__', 'merge' => '__MERGE__'])),
                        finalize: @js(route('admin.dam.assets.merges.finalize', ['asset' => '__ASSET__', 'merge' => '__MERGE__'])),
                        revoke: @js(route('admin.dam.assets.merges.revoke_links', ['asset' => '__ASSET__', 'merge' => '__MERGE__'])),
                    };

                    return urls[name].replace('__ASSET__', this.assetId).replace('__MERGE__', mergeId);
                },

                /** The singular or plural half of a "one|many" string. */
                choice(text, count) {
                    const [one, many] = String(text).split('|');

                    return Number(count) === 1 || many === undefined ? one : many;
                },

                // In the signed-in person's time zone, like the asset's Details on the same page, not the browser's (G1-13).
                formatDate(value) {
                    if (! value) return '—';
                    const options = { dateStyle: 'medium', timeStyle: 'short' };
                    try {
                        return new Date(value).toLocaleString([], { ...options, timeZone: @js(auth('admin')->user()?->timezone ?: config('app.timezone', 'UTC')) });
                    } catch {
                        return new Date(value).toLocaleString([], options);
                    }
                },

                async load() {
                    const assetId = this.assetId;
                    try {
                        const { data } = await this.$axios.get(this.url('index'));
                        if (assetId !== this.assetId) return;
                        this.merges = data.merges || [];
                        this.canEdit = !! data.can_edit;
                        this.folder = data.folder || null;
                    } catch (error) {
                        if (assetId !== this.assetId) return;
                        this.merges = [];
                        // A 404 is an asset the viewer cannot open; the page says so already.
                        if (error.response?.status !== 404) {
                            this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || this.t.loadFailed });
                        }
                    }
                },

                async openSplit(merge, { fromFile = false } = {}) {
                    this.openDialog({ kind: 'split', merge, fromFile, loading: true, plan: null, choices: {} });
                    try {
                        const { data } = await this.$axios.get(this.url('splitPreview', merge.id));
                        if (this.dialog?.merge.id !== merge.id) return;
                        this.dialog.choices = Object.fromEntries(data.slots.filter((slot) => slot.asks).map((slot) => [slot.attachment_id, slot.default]));
                        this.dialog.plan = data;
                    } catch (error) {
                        this.dialog = null;
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || this.t.failed });
                        this.load();
                    } finally {
                        if (this.dialog) this.dialog.loading = false;
                    }
                },

                openFinalize(merge) {
                    this.openDialog({ kind: 'finalize', merge, confirmed: false });
                },

                openDialog(dialog) {
                    this.returnFocus = document.activeElement;
                    this.dialog = dialog;
                    this.$nextTick(() => this.$refs.dialog?.focus());
                },

                closeDialog() {
                    if (this.busy) return;
                    this.dialog = null;
                    this.$nextTick(() => this.returnFocus?.focus?.());
                },

                /** Keeps Tab and Shift+Tab inside the open dialog. */
                trapFocus(event) {
                    const focusable = Array.from(this.$refs.dialog?.querySelectorAll('button:not([disabled]), input:not([disabled]), [href]') || []);
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

                async act(request) {
                    if (this.busy) return;
                    this.busy = true;
                    try {
                        const { data } = await request();
                        this.merges = data.merges ?? this.merges;
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        this.busy = false;
                        this.closeDialog();
                        // The Files tab lists what the destination holds now.
                        this.$emitter.emit('dam-merges-changed', { assetId: this.assetId });
                    } catch (error) {
                        this.busy = false;
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || this.t.failed });
                        this.closeDialog();
                        this.load();
                    }
                },

                split() {
                    const merge = this.dialog.merge;
                    const choices = { ...this.dialog.choices };

                    return this.act(() => this.$axios.post(this.url('split', merge.id), { choices }));
                },

                finalize() {
                    const merge = this.dialog.merge;

                    return this.act(() => this.$axios.post(this.url('finalize', merge.id), { confirm: true }));
                },

                revokeLinks(merge) {
                    this.$emitter.emit('open-delete-modal', {
                        message: this.t.revokeConfirm.replace(':name', merge.source.name),
                        agree: () => this.act(() => this.$axios.post(this.url('revoke', merge.id))).then(() => this.load()),
                    });
                },
            },
        });
    </script>
@endPushOnce
