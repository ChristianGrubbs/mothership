{{--
    Rename with AI (Library mass action): one proposed `date_event_place` name per selected file, from
    admin.dam.assets.ai-rename.suggest, reviewed and edited here, then applied through the ordinary rename. A file
    with no AI description gets one first (the describe action). Opened with `dam:open-ai-rename`; emits
    `dam:ai-rename-applied` with the opener's context.
--}}
@pushOnce('scripts')
<style>
    .dam-ai-rename-modal [role="dialog"] { display: flex; flex-direction: column; max-height: calc(100dvh - 2rem); overflow: hidden; }
    .dam-ai-rename-modal [role="dialog"] > :first-child { flex: none; }
    .dam-ai-rename-modal [role="dialog"] > :nth-child(2) { display: flex; flex-direction: column; min-height: 0; }
    .dam-ai-rename-modal [data-dam-ai-rename] { display: flex; flex-direction: column; min-height: 0; }
    .dam-ai-rename-modal [data-dam-ai-rename] > * { flex-shrink: 0; }
    .dam-ai-rename-modal [data-dam-ai-rename] > [data-dam-ai-rename-list] { flex: 0 1 auto; min-height: 5rem; }
    .dam-ai-rename-row.is-completed input[type="text"] { background: #f3f4f6; border-color: transparent; color: #6b7280; }
    .dark .dam-ai-rename-row.is-completed input[type="text"] { background: #1f2937; color: #9ca3af; }
    @media (max-width: 639px) {
        .dam-ai-rename-row > label, .dam-ai-rename-row > img, .dam-ai-rename-row > span { grid-row: 1; }
        .dam-ai-rename-identity { display: contents; }
        .dam-ai-rename-identity > p:first-child { grid-column: 3; grid-row: 1; align-self: center; }
        .dam-ai-rename-edit, .dam-ai-rename-identity > p:not(:first-child) { grid-column: 1 / -1; }
    }
</style>
<script type="text/x-template" id="v-dam-ai-rename-modal-template">
    {{-- The header × only toggles (no close event), so a toggle to closed ends the run too. --}}
    <x-admin::modal ref="aiRenameModal" type="large" class="dam-ai-rename-modal" @close="run += 1" @toggle="onToggle">
        <x-slot:header>
            <p class="text-lg font-bold text-gray-800 dark:text-white">@{{ labels.title.replace(':count', rows.length) }}</p>
        </x-slot>

        <x-slot:content>
            <div class="grid gap-3" data-dam-ai-rename>
                <p class="text-sm text-gray-600 dark:text-slate-300">@lang('dam::app.admin.explorer.ai-rename.hint')</p>
                <p v-if="skippedFolders" class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.explorer.ai-rename.folders')</p>
                <p v-if="checking" class="text-xs text-gray-500 dark:text-slate-400" data-dam-ai-rename-checking>@lang('dam::app.admin.explorer.ai-rename.checking')</p>
                {{-- Mixed selection: which files go on, which stay out, and (on each row) why. Zero eligible leaves nothing to apply. --}}
                <p v-if="! checking && blockedCount" class="text-xs font-semibold" :class="eligibleCount ? 'text-amber-700 dark:text-amber-300' : 'text-red-600'" data-dam-ai-rename-mixed>
                    @{{ eligibleCount ? labels.mixed.replace(':eligible', eligibleCount).replace(':total', rows.length) : labels.noneEligible }}
                </p>

                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" :checked="allEligibleSelected" :indeterminate="selectionIndeterminate" :disabled="checking || applying || ! selectableRows.length" @change="setAllEligible({ checked: $event.target.checked })">
                        @lang('dam::app.admin.explorer.ai-rename.select-all')
                    </label>
                    <button type="button" :disabled="applying || ! selectedCount" @click="setAllEligible({ checked: false })">@lang('dam::app.admin.explorer.ai-rename.clear-selection')</button>
                    <span>@{{ labels.selected.replace(':count', selectedCount).replace(':total', selectableRows.length) }}</span>
                </div>
                <p v-if="summary" class="text-sm" role="status">@{{ summary }}</p>
                <button v-if="membersError" type="button" class="w-fit text-sm font-semibold text-primary-600" @click="open({ assets: sourceAssets, skippedFolders, context })">@lang('dam::app.admin.explorer.ai-rename.retry')</button>
                <div class="max-h-[58vh] overflow-y-auto rounded-lg border border-gray-200 divide-y divide-gray-100 dark:border-gray-700 dark:divide-gray-800" data-dam-ai-rename-list>
                    <div v-for="row in rows" :key="row.key" class="dam-ai-rename-row grid grid-cols-[auto_56px_minmax(0,1fr)] items-start gap-3 px-3 py-2.5" :class="{ 'is-completed': row.state === 'renamed' }" :data-dam-ai-rename-row="row.key">
                        <label class="relative mt-4 flex cursor-pointer items-center">
                            <input type="checkbox" class="peer sr-only" v-model="row.checked" @change="row.selectionExplicit = true" :disabled="applying || row.state === 'renamed' || row.state === 'ineligible'" :aria-label="row.current">
                            <span class="icon-checkbox-normal rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-300" aria-hidden="true"></span>
                        </label>

                        <img v-if="row.thumb" :src="row.thumb" alt="" loading="lazy" class="h-14 w-14 rounded object-cover bg-gray-100 dark:bg-gray-800">
                        <span v-else class="h-14 w-14 rounded bg-gray-100 dark:bg-gray-800" aria-hidden="true"></span>

                        <div class="dam-ai-rename-identity flex min-w-0 flex-col gap-1">
                            <p class="break-words text-xs text-gray-500 dark:text-gray-400" :title="row.current">@{{ row.stackName }} · @{{ row.current }}</p>

                            <div v-if="row.state === 'ready' || row.state === 'renamed' || row.state === 'error'" class="dam-ai-rename-edit flex items-center gap-1">
                                <input
                                    v-model.trim="row.name"
                                    type="text"
                                    maxlength="200"
                                    class="min-w-0 flex-1 rounded-md border border-gray-300 px-2 py-1 font-mono text-xs text-gray-800 dark:border-gray-600 dark:bg-cherry-800 dark:text-gray-100"
                                    :disabled="applying || row.state === 'renamed'"
                                    :aria-label="labels.proposed"
                                    @input="row.edited = true"
                                >
                                <span v-if="row.ext" class="shrink-0 font-mono text-xs text-gray-500">.@{{ row.ext }}</span>
                            </div>

                            {{-- Left out of the rename, with the server's reason; the file itself is untouched and stays in the Library. --}}
                            <p v-else-if="row.state === 'ineligible'" class="text-xs text-gray-500 dark:text-gray-400" data-dam-ai-rename-skipped>@{{ labels.notEligible }}: @{{ row.message }}</p>

                            <p v-else-if="row.state === 'failed'" class="flex items-center gap-2 text-xs text-red-600">
                                @{{ row.error || labels.noDescription }}
                                <button type="button" class="font-semibold text-primary-600 hover:underline" :disabled="applying" @click="retry(row)">@lang('dam::app.admin.explorer.ai-rename.retry')</button>
                            </p>

                            <p v-else class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="row.state !== 'queued'" class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-violet-400 border-t-transparent" aria-hidden="true"></span>
                                @{{ row.state === 'describing' ? labels.describing : (row.state === 'thinking' ? labels.thinking : '…') }}
                            </p>

                            <p v-if="row.state === 'renamed'" class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400" data-dam-ai-rename-completed>
                                <span class="text-green-600" aria-hidden="true">✓</span>
                                <span v-if="row.unchanged" data-dam-ai-rename-unchanged>@{{ labels.unchanged }}</span>
                            </p>
                            <p v-if="row.representative && row.title && setTitle && row.state !== 'failed'" class="break-words text-xs text-gray-600 dark:text-gray-300">@{{ row.title }}</p>
                            <p v-if="row.event" class="break-words text-[11px] text-violet-700 dark:text-violet-300">@{{ labels.event.replace(':event', row.event) }}</p>
                            <p v-if="row.dateSource === 'uploaded'" class="text-[11px] text-amber-700 dark:text-amber-300">@lang('dam::app.admin.explorer.ai-rename.uploaded-date')</p>
                            <p v-if="row.state === 'error'" class="text-xs text-red-600">@{{ row.error }}</p>
                        </div>
                    </div>
                </div>

                <label class="relative flex w-fit cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" class="peer sr-only" v-model="setTitle" :disabled="applying">
                    <span class="icon-checkbox-normal rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-300" aria-hidden="true"></span>
                    @lang('dam::app.admin.explorer.ai-rename.set-title')
                </label>

                <div class="flex items-center justify-end gap-2">
                    <button type="button" class="secondary-button" @click="close">@lang('dam::app.admin.explorer.ai-rename.cancel')</button>
                    <button type="button" class="primary-button" :disabled="applying || ! applicable.length" data-dam-ai-rename-apply @click="apply">
                        @{{ labels.apply.replace(':count', applicable.length) }}
                    </button>
                </div>
            </div>
        </x-slot>
    </x-admin::modal>
</script>

<script type="module">
    /** Names cameras and phones give files; these start ticked, already-descriptive names do not. */
    const damCameraName = /^(img|dsc[nf]?|_dsc|_mg|mvi|mov|vid|pxl|gopr|g[hx]\d{2}|dji|cimg|pict|sam|hpim|pano|dcim|p\d{6,})[_-]?\d|^\d{8}[_-]\d{6}|^img-\d{8}-wa\d+/i;

    app.component('v-dam-ai-rename-modal', {
        template: '#v-dam-ai-rename-modal-template',
        data() {
            return {
                rows: [],
                checking: false,
                skippedFolders: false,
                setTitle: true,
                applying: false,
                summary: '',
                membersError: false,
                sourceAssets: [],
                canListMembers: @js(bouncer()->hasPermission('dam.asset.files.index')),
                context: null,
                // Bumped on every open and close (button, header ×, Esc), so a run still going for a closed modal
                // stops: no more suggestions or renames, and nothing written into the next one.
                run: 0,
                labels: {
                    title: @js(trans('dam::app.admin.explorer.ai-rename.title')),
                    proposed: @js(trans('dam::app.admin.explorer.ai-rename.proposed')),
                    describing: @js(trans('dam::app.admin.explorer.ai-rename.describing')),
                    thinking: @js(trans('dam::app.admin.explorer.ai-rename.thinking')),
                    failed: @js(trans('dam::app.admin.explorer.ai-rename.failed')),
                    event: @js(trans('dam::app.admin.explorer.ai-rename.event')),
                    apply: @js(trans('dam::app.admin.explorer.ai-rename.apply')),
                    unavailable: @js(trans('dam::app.admin.dam.asset.edit.ai.unavailable')),
                    mixed: @js(trans('dam::app.admin.explorer.ai-rename.mixed')),
                    noneEligible: @js(trans('dam::app.admin.explorer.ai-rename.none-eligible')),
                    notEligible: @js(trans('dam::app.admin.explorer.ai-rename.not-eligible')),
                    noDescription: @js(trans('dam::app.admin.explorer.ai-rename.no-description')),
                    describeTimeout: @js(trans('dam::app.admin.explorer.ai-rename.describe-timeout')),
                    renameFailed: @js(trans('dam::app.admin.explorer.ai-rename.rename-failed')),
                    unchanged: @js(trans('dam::app.admin.explorer.ai-rename.unchanged')),
                    selected: @js(trans('dam::app.admin.explorer.ai-rename.selected')),
                    summary: @js(trans('dam::app.admin.explorer.ai-rename.summary')),
                },
                urls: {
                    eligibility: @js(route('admin.dam.assets.ai-tags.eligibility')),
                    suggest: @js(route('admin.dam.assets.ai-rename.suggest')),
                    rename: @js(route('admin.dam.assets.rename')),
                    members: @js(route('admin.dam.assets.ai-rename.members')),
                    applyMember: @js(route('admin.dam.assets.ai-rename.apply-member')),
                    describe: @js(route('admin.dam.assets.ai-tags.describe', '__id__')),
                    show: @js(route('admin.dam.assets.ai-tags.show', '__id__')),
                },
            };
        },
        computed: {
            selectableRows() { return this.rows.filter(row => ! ['ineligible', 'renamed'].includes(row.state)); },
            selectedCount() { return this.selectableRows.filter(row => row.checked).length; },
            allEligibleSelected() { return this.selectableRows.length > 0 && this.selectedCount === this.selectableRows.length; },
            selectionIndeterminate() { return this.selectedCount > 0 && ! this.allEligibleSelected; },
            eligibleCount() { return this.rows.filter(row => row.state !== 'ineligible').length; },
            blockedCount() { return this.rows.length - this.eligibleCount; },
            applicable() {
                return this.rows.filter(row => row.checked && (row.state === 'ready' || row.state === 'error') && row.name);
            },
        },
        mounted() { this.$emitter.on('dam:open-ai-rename', this.open); },
        beforeUnmount() { this.run += 1; this.$emitter.off('dam:open-ai-rename', this.open); },
        methods: {
            setAllEligible({ checked }) {
                this.selectableRows.forEach(row => { row.checked = checked; row.selectionExplicit = true; });
            },
            async open({ assets, skippedFolders, context } = {}) {
                this.run += 1;
                this.context = context ?? null;
                this.skippedFolders = !! skippedFolders;
                this.applying = false;
                this.summary = '';
                this.membersError = false;
                this.sourceAssets = assets ?? [];
                this.rows = [];
                this.checking = true;
                this.$refs.aiRenameModal.open();

                const run = this.run;
                if (! this.canListMembers) {
                    this.rows = this.sourceAssets.map((asset, order) => {
                        const current = String(asset.name ?? asset.id);
                        const dot = current.lastIndexOf('.');
                        return {
                            id: Number(asset.id), attachmentId: null, fileId: null, key: `${asset.id}:asset`, legacy: true, representative: true,
                            current, stackName: current, thumb: asset.thumb ?? null, known: asset.known !== false,
                            ext: dot > 0 ? current.slice(dot + 1) : '', order,
                            checked: damCameraName.test(current), selectionExplicit: false, state: 'queued', unchanged: false, outcome: '', message: '',
                            stem: '', name: '', title: '', event: '', capturedAt: '', dateSource: '', edited: false, error: '',
                        };
                    });
                    await this.filterEligible(run);
                    if (run === this.run) this.suggestAll(this.rows.filter(row => row.state === 'queued'), run);
                    return;
                }
                try {
                    const { data } = await this.$axios.post(this.urls.members, { asset_ids: [...new Set((assets ?? []).map(asset => Number(asset.id)))] });
                    if (run !== this.run) return;
                    this.rows = (data.rows ?? []).map((member, order) => {
                        if (! member.attachment_id || ! member.file_id) return this.unavailableRow({ assetId: member.asset_id, message: member.eligibility?.message || this.labels.notEligible, order });
                        const parent = (assets ?? []).find(asset => Number(asset.id) === Number(member.asset_id));
                        const current = String(member.file_name);
                        const eligible = member.eligibility?.state === 'eligible';
                        return {
                            id: Number(member.asset_id), attachmentId: Number(member.attachment_id), fileId: Number(member.file_id),
                            key: `${member.asset_id}:${member.attachment_id}`, representative: !! member.is_representative,
                            stackName: parent?.name ?? String(member.asset_id), current, serverName: current,
                            thumb: member.thumbnail_url || (member.is_representative ? parent?.thumb : null), known: true, ext: member.extension || '', order,
                            checked: eligible && damCameraName.test(current), selectionExplicit: false,
                            state: eligible ? 'queued' : 'ineligible', unchanged: false, outcome: '', message: member.eligibility?.message || '',
                            stem: '', name: '', title: '', event: '', capturedAt: '', dateSource: '', edited: false, error: '',
                        };
                    });
                } catch (error) {
                    if (run === this.run) {
                        this.summary = error.response?.data?.message || this.labels.unavailable;
                        this.membersError = true;
                        this.rows = this.sourceAssets.map((asset, order) => this.unavailableRow({ assetId: asset.id, message: this.summary, order }));
                    }
                } finally {
                    if (run === this.run) this.checking = false;
                }
                if (run !== this.run) return;

                // Ticked files first: they are the ones about to be renamed. One at a time, as the AI works one at a time.
                // A file already set aside (not eligible) or failed fast (service down) is not asked.
                this.suggestAll(this.rows.filter(row => row.state === 'queued').sort((a, b) => Number(b.checked) - Number(a.checked)), run);
            },
            unavailableRow({ assetId, message, order }) {
                return { id: Number(assetId), attachmentId: null, fileId: null, key: `${assetId}:unavailable`, current: this.labels.notEligible,
                    stackName: String(assetId), thumb: null, ext: '', order, state: 'ineligible', checked: false, message, name: '', outcome: '' };
            },
            /**
             * Asks the server which of the files the AI can describe, and sets the others aside with the reason, so
             * they are never submitted. If the question itself fails the files go on as before: each suggestion call
             * is checked on the server again.
             */
            async filterEligible(run) {
                this.checking = true;

                try {
                    const { data } = await this.$axios.post(this.urls.eligibility, { ids: [...new Set(this.rows.map(row => row.id))] });
                    if (run !== this.run) return;

                    this.rows.forEach(row => {
                        const answer = data.eligibility?.[row.id];
                        const permanent = ['unsupported', 'awaiting_preview'].includes(answer?.state) || answer?.reason === 'no_image';

                        // The service is not answering (the cached health, or this file's own state): every file that
                        // could be named fails at once with that reason, instead of waiting minutes for a describe that cannot come.
                        if (! permanent && (data.available === false || answer?.state === 'service_unavailable')) {
                            Object.assign(row, { state: 'failed', error: this.labels.unavailable });

                            return;
                        }

                        if (answer?.can_rename) return;
                        this.skip(row, answer?.message || this.labels.notEligible);
                    });
                    // Files the AI will not take go last, under the ones that will be renamed.
                    this.rows = [...this.rows].sort((a, b) => Number(a.state === 'ineligible') - Number(b.state === 'ineligible'));
                } catch (error) {
                } finally {
                    if (run === this.run) this.checking = false;
                }
            },
            skip(row, message) {
                Object.assign(row, { state: 'ineligible', checked: false, message, error: '' });
            },
            close() {
                this.run += 1;
                this.$refs.aiRenameModal.close();
            },
            onToggle({ isActive } = {}) {
                if (! isActive) this.run += 1;
            },
            async suggestAll(rows, run) {
                for (const row of rows) {
                    if (run !== this.run) return;
                    await this.suggest(row, run);
                }
            },
            async suggest(row, run, described = false) {
                row.state = 'thinking';
                row.error = '';

                try {
                    const body = { id: row.id };
                    if (! row.legacy) body.file_id = row.fileId;
                    const { data } = await this.$axios.post(this.urls.suggest, body);
                    if (run !== this.run) return;
                    // A file selected on another page arrived as its id only; the server names it.
                    if (! row.known && data.file_name) {
                        row.current = data.file_name;
                        if (! row.selectionExplicit) row.checked = damCameraName.test(data.file_name);
                        row.known = true;
                    }
                    // The name the server had when it made this suggestion: what a rename is measured against (not the listing's, which may be older).
                    if (data.file_name) row.serverName = data.file_name;
                    Object.assign(row, { state: 'ready', stem: data.stem, title: data.title, event: data.event, capturedAt: data.captured_at, dateSource: data.date_source, ext: data.extension || row.ext });
                    this.number();
                } catch (error) {
                    if (run !== this.run) return;

                    // The server's own check, for a list the client got wrong or a file that changed since.
                    if (error.response?.status === 422 && error.response?.data?.eligibility) {
                        this.skip(row, error.response.data.message || this.labels.notEligible);
                        return;
                    }

                    if (! row.fileId && error.response?.status === 409 && ! described) {
                        if (await this.describe(row, run)) await this.suggest(row, run, true);
                        return;
                    }

                    row.state = 'failed';
                    row.error = error.response?.data?.message || this.labels.noDescription;
                }
            },
            /** Asks for the file's AI description and waits for it (up to five minutes). */
            async describe(row, run) {
                row.state = 'describing';

                try {
                    await this.$axios.post(this.urls.describe.replace('__id__', row.id));
                } catch (error) {
                    if (error.response?.status === 422 && error.response?.data?.eligibility) {
                        this.skip(row, error.response.data.message || this.labels.notEligible);
                        return false;
                    }

                    row.state = 'failed';
                    row.error = error.response?.data?.message || this.labels.unavailable;
                    return false;
                }

                const started = Date.now();

                while (Date.now() - started < 300000) {
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    if (run !== this.run) return false;

                    try {
                        const { data } = await this.$axios.get(this.urls.show.replace('__id__', row.id));
                        if (data.ai?.caption) return true;

                        const state = data.eligibility?.state;

                        // The server's reading of the run: the service is not answering, or the file cannot be described.
                        // Either way it ends here with that reason, not after the full wait with none.
                        if (state === 'service_unavailable') return this.fail(row, this.labels.unavailable);
                        if (['unsupported', 'awaiting_preview', 'failed'].includes(state)) return this.fail(row, data.eligibility.message);
                        if (data.ai?.status === 'failed' || data.ai?.status === 'done') return this.fail(row, data.eligibility?.message);
                    } catch (error) {}
                }

                return this.fail(row, this.labels.describeTimeout);
            },
            /** Ends a row's wait as failed, always with a reason a person can read. */
            fail(row, message) {
                row.state = 'failed';
                row.error = message || this.labels.noDescription;

                return false;
            },
            retry(row) {
                this.suggest(row, this.run);
            },
            /** Files sharing a proposed stem are numbered _01, _02, … in capture order; a name a person typed is left alone. */
            number() {
                const groups = {};
                this.rows.filter(row => row.state === 'ready' && row.stem).forEach(row => (groups[row.stem] ??= []).push(row));

                Object.values(groups).forEach(group => {
                    group.sort((a, b) => (a.capturedAt || '').localeCompare(b.capturedAt || '') || a.order - b.order);
                    group.forEach((row, index) => {
                        if (! row.edited) row.name = group.length > 1 ? `${row.stem}_${String(index + 1).padStart(2, '0')}` : row.stem;
                    });
                });
            },
            async apply() {
                this.applying = true;
                const run = this.run;
                const context = this.context;
                const rows = [...this.applicable];
                // `done` is files really renamed; `unchanged` already had that name (and title): not renamed, not an error.
                // `titled`: the file name stayed and only the title was set; the server calls that a change, but it is not a rename.
                let done = 0;
                let titled = 0;
                let unchanged = 0;

                for (const row of rows) {
                    if (run !== this.run) break;

                    // A name already taken in the folder gets -2, -3, … rather than failing the batch.
                    for (let attempt = 0; attempt < 6; attempt++) {
                        const name = attempt === 0 ? row.name : `${row.name}-${attempt + 1}`;
                        const stem = row.ext && name.toLowerCase().endsWith(`.${row.ext.toLowerCase()}`) ? name.slice(0, -(row.ext.length + 1)) : name;
                        const fileName = row.ext ? `${stem}.${row.ext}` : stem;
                        const body = row.legacy ? { id: row.id, file_name: fileName } : { asset_id: row.id, attachment_id: row.attachmentId, file_id: row.fileId, file_name: fileName };
                        if (this.setTitle && row.representative && row.title) body.title = row.title;

                        try {
                            const { data } = await this.$axios.post(row.legacy ? this.urls.rename : this.urls.applyMember, body);

                            // Answered 200 with code `unchanged`; an older server answered 404 with the same code (below).
                            if (data?.status === 'unchanged' || data?.code === 'unchanged') {
                                Object.assign(row, { name, state: 'renamed', checked: false, unchanged: true, error: '' });
                                unchanged += 1;
                                row.outcome = 'unchanged';
                            } else {
                                Object.assign(row, { name, state: 'renamed', checked: false, unchanged: false, error: '' });

                                // Renamed or not is the server's word: the name it applied (it trims and may normalise what was asked)
                                // against the name it had when it made the suggestion. The name the person asked for proves nothing.
                                const before = row.serverName ?? row.current;
                                const applied = data?.file_name ?? data?.data?.file_name ?? body.file_name.trim();

                                if (applied === before) { titled += 1; row.outcome = 'titled'; }
                                else { done += 1; row.outcome = 'renamed'; }
                            }

                            break;
                        } catch (error) {
                            const code = error.response?.data?.code;

                            if (code === 'unchanged') {
                                Object.assign(row, { name, state: 'renamed', checked: false, unchanged: true, error: '' });
                                unchanged += 1;
                                row.outcome = 'unchanged';
                                break;
                            }

                            row.state = 'error';
                            row.error = error.response?.data?.message || this.labels.renameFailed;
                            // Only a name clash (taken up front, or the unique-index race) is worth another number.
                            if (code !== 'name_taken' && code !== 'name_conflict') break;
                            if (run !== this.run) break;
                        }
                    }
                }

                // Closed or reopened mid-run: the opener still refreshes, but the new dialog is left alone.
                if (run !== this.run) {
                    if (done || titled) this.$emitter.emit('dam:ai-rename-applied', { context, done, titled });
                    return;
                }

                this.applying = false;
                // `done` is the real rename count and `titled` the title-only changes: an opener reloads when either is above 0.
                this.$emitter.emit('dam:ai-rename-applied', { context, done, titled });

                // Said of the whole selection: files set aside, files that failed (here or while the AI described them) and
                // files still being worked on keep their rows on screen, and the dialog stays open for them.
                const blocked = this.blockedCount;
                const failed = this.rows.filter(row => row.state === 'error' || row.state === 'failed').length;
                const waiting = this.rows.filter(row => ['queued', 'describing', 'thinking'].includes(row.state)).length;
                const counts = {
                    renamed: this.rows.filter(row => row.outcome === 'renamed').length,
                    unchanged: this.rows.filter(row => row.outcome === 'unchanged').length,
                    titled: this.rows.filter(row => row.outcome === 'titled').length,
                    failed, skipped: this.rows.filter(row => row.state === 'ineligible' || (! row.checked && row.state !== 'renamed')).length,
                };
                this.summary = Object.entries(counts).reduce((message, [key, value]) => message.replace(`:${key}`, value), this.labels.summary);

                // Every file tried was already named so, and nothing is left to look at: nothing to report.
                if (! failed && ! blocked && ! waiting && ! done && ! titled) {
                    this.close();

                    return;
                }

                this.$emitter.emit('add-flash', { type: failed || waiting || blocked ? 'warning' : 'success', message: this.summary });

                // Only a clean run closes; otherwise the reasons stay on screen.
                if (! failed && ! waiting && ! blocked) this.close();
            },
        },
    });
</script>
@endPushOnce
