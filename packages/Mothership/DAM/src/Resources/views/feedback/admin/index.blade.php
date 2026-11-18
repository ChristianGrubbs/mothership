{{--
    Settings → Feedback: the triage grid and its drawer. The grid lists
    through FeedbackAdminController; the drawer reads, saves and posts notes through FeedbackController (show, update,
    notes.store), which check FeedbackAccess themselves. Status and the issue URL save only on Save. Nothing here calls
    GitHub: "Copy as GitHub issue" puts markdown on the clipboard.
--}}
<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.feedback.triage.title')
    </x-slot>

    <v-dam-feedback-triage>
        <x-admin::shimmer.datagrid />
    </v-dam-feedback-triage>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-feedback-triage-template">
            <div>
                {{-- Its menu row lives under Library for gating but shows in the avatar's Settings section: name that trail. --}}
                <x-admin::breadcrumbs :leaf="trans('dam::app.admin.feedback.triage.title')" :own-trail="true" :parents="[['label' => trans('admin::app.components.layouts.sidebar.settings'), 'url' => route('admin.settings.channels.index')]]" />

                <x-admin::layouts.page-header
                    class="mt-1.5"
                    :title="trans('dam::app.admin.feedback.triage.title')"
                    :description="trans('dam::app.admin.feedback.triage.description')"
                    :breadcrumb="false"
                />

                {{-- Quick filters (the mock's chips). They write the grid's own column filters, so the toolbar's filter panel shows the same state. --}}
                <div class="flex flex-wrap gap-2 mt-4" data-feedback-filters>
                    <label
                        v-for="filter in quickFilters"
                        :key="filter.key"
                        class="relative flex items-center gap-1.5 h-8 pl-2.5 pr-7 rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 text-[13px] text-gray-700 dark:text-slate-200 focus-within:border-primary-500 dark:focus-within:border-primary-400"
                    >
                        <span class="text-gray-500 dark:text-slate-400" v-text="filter.label + ':'"></span>

                        <select
                            class="appearance-none bg-transparent pr-1 font-medium text-gray-800 dark:text-white focus:outline-none cursor-pointer"
                            :data-filter="filter.key"
                            :value="quick[filter.key]"
                            v-on:change="setQuick(filter.key, $event.target.value)"
                        >
                            <option
                                v-for="option in filter.options"
                                :key="option.value"
                                :value="option.value"
                                v-text="option.label"
                            ></option>
                        </select>

                        <span class="icon-chevron-down pointer-events-none absolute right-1.5 text-lg text-gray-500 dark:text-slate-400"></span>
                    </label>
                </div>

                <x-admin::datagrid :src="route('admin.dam.feedback.index')" ref="datagrid" storage-key="dam-feedback-triage">
                    <template #header="{ columns, applied, sortPage, isLoading }">
                        <template v-if="isLoading">
                            <x-admin::shimmer.datagrid.table.head />
                        </template>

                        <template v-else>
                            <div class="row grid gap-3 min-h-[47px] px-4 py-2.5 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 bg-primary-50 dark:bg-cherry-900 font-semibold items-center" :style="gridColumns">
                                <p
                                    v-for="column in headerColumns(columns)"
                                    :key="column.index"
                                    class="flex gap-1.5 items-center min-w-0"
                                    :class="{ 'cursor-pointer select-none hover:text-gray-800 dark:hover:text-white': column.sortable }"
                                    v-on:click="column.sortable && sortPage(column)"
                                >
                                    <span class="truncate" v-text="column.label"></span>
                                    <i
                                        v-if="column.index == applied.sort.column"
                                        class="text-base align-text-bottom"
                                        :class="[applied.sort.order === 'asc' ? 'icon-down-stat' : 'icon-up-stat']"
                                    ></i>
                                </p>
                            </div>
                        </template>
                    </template>

                    <template #body="{ records, isLoading }">
                        <template v-if="isLoading">
                            <x-admin::shimmer.datagrid.table.body />
                        </template>

                        <template v-else>
                            <p v-if="! records.length" class="px-4 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
                                @lang('dam::app.admin.feedback.triage.empty')
                            </p>

                            <button
                                v-for="record in records"
                                :key="record.id"
                                type="button"
                                class="row grid w-full gap-3 items-center px-4 py-3 border-b dark:border-cherry-800 text-left text-sm text-gray-600 dark:text-gray-300 transition-colors hover:bg-gray-50 dark:hover:bg-cherry-800 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600"
                                :class="{ '!bg-primary-50 dark:!bg-cherry-800': record.id === selectedId }"
                                :style="gridColumns"
                                :data-report-row="record.id"
                                :aria-current="record.id === selectedId ? 'true' : null"
                                :aria-label="'#' + record.id + ' ' + record.title + ' · ' + label('statuses', record.status)"
                                v-on:click="openReport(record.id)"
                            >
                                <span class="tabular-nums text-gray-500 dark:text-slate-400" v-text="record.id"></span>

                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-gray-800 dark:text-white" :title="record.title" v-text="record.title"></span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-slate-400" v-text="[record.reporter_name, record.where].filter(Boolean).join(' · ')"></span>
                                </span>

                                <span v-text="label('types', record.type)"></span>
                                <span v-text="record.severity ? label('severities', record.severity) : '—'"></span>

                                <span>
                                    <span class="inline-block whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium" :class="statusClass(record.status)" v-text="label('statuses', record.status)"></span>
                                </span>

                                <span class="whitespace-nowrap" :title="record.created_at" v-text="shortDate(record.created_at)"></span>
                            </button>
                        </template>
                    </template>
                </x-admin::datagrid>

                {{-- The drawer: one report, read through admin.dam.feedback.show. --}}
                {{-- No header slot: the drawer's own close button would close before an unsaved change could stop it. --}}
                <x-admin::drawer ref="drawer" width="480px">
                    {{-- !flex-none: sized by its content (the panel scrolls), so no row is squeezed when the notes grow. --}}
                    <x-slot:content class="!p-4 !flex-none grid gap-4 content-start">
                        <div class="relative pr-10 min-w-0" data-feedback-drawer>
                            <p class="text-base font-semibold leading-snug text-gray-900 dark:text-white break-words" v-text="report ? report.title : lang.drawer.loading"></p>
                            <p v-if="report" class="mt-1 text-xs text-gray-500 dark:text-slate-400" v-text="byline"></p>
                            <button
                                type="button"
                                class="absolute -top-1 -right-1 icon-cancel text-3xl rounded-md hover:bg-primary-50 dark:hover:bg-cherry-700"
                                :aria-label="lang.drawer.close"
                                data-feedback-close
                                v-on:click="requestClose"
                            ></button>
                        </div>

                        {{-- Asked before an unsaved status or issue change is dropped (closing, or opening another row). --}}
                        <div
                            v-if="pending"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
                            role="alertdialog"
                            :aria-label="lang.drawer.discard"
                            data-feedback-discard
                        >
                            <span v-text="lang.drawer.discard"></span>
                            <span class="flex gap-2">
                                <button type="button" class="transparent-button !py-1" v-on:click="pending = null" v-text="lang.drawer.keep"></button>
                                <button type="button" class="primary-button !py-1" v-on:click="discard" v-text="lang.drawer['discard-yes']"></button>
                            </span>
                        </div>

                        <p v-if="loadFailed" class="text-sm text-red-600 dark:text-red-400" v-text="lang.drawer['load-failed']"></p>

                        <template v-if="report">
                            {{-- Screenshot: a click opens it full size. --}}
                            <button
                                v-if="report.screenshot_url"
                                type="button"
                                class="group relative block w-full shrink-0 overflow-hidden rounded-md border border-gray-200 dark:border-cherry-700 bg-gray-100 dark:bg-cherry-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                                :aria-label="lang.drawer['full-size']"
                                data-feedback-screenshot
                                v-on:click="lightbox = true"
                            >
                                <img :src="report.screenshot_url" :alt="screenshotAlt" class="block w-full max-h-64 object-cover object-top" loading="lazy">
                                <span class="absolute right-1.5 bottom-1.5 rounded bg-gray-900/75 px-1.5 py-0.5 text-[11px] text-white group-hover:bg-gray-900" v-text="'⤢ ' + lang.drawer['full-size']"></span>
                            </button>

                            <p class="text-[13px] leading-5 text-gray-700 dark:text-slate-300 whitespace-pre-line break-words" v-text="report.description"></p>

                            {{-- Triage: saved together, only on Save. --}}
                            <div class="grid gap-2">
                                <div class="grid grid-cols-2 gap-2.5">
                                    <label class="grid gap-1">
                                        <span class="text-xs font-medium text-gray-600 dark:text-slate-300" v-text="lang.drawer.status"></span>
                                        <select
                                            v-model="form.status"
                                            name="status"
                                            class="h-9 rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-2 text-sm text-gray-800 dark:text-white focus:outline-none focus:border-primary-500"
                                        >
                                            <option v-for="status in statuses" :key="status" :value="status" v-text="label('statuses', status)"></option>
                                        </select>
                                    </label>

                                    <label class="grid gap-1">
                                        <span class="flex items-center justify-between text-xs font-medium text-gray-600 dark:text-slate-300">
                                            <span v-text="lang.drawer.issue"></span>
                                            <a
                                                v-if="report.github_issue_url"
                                                :href="report.github_issue_url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-primary-700 dark:text-primary-400 hover:underline"
                                                :title="lang.drawer['open-issue']"
                                                v-text="issueShort"
                                            ></a>
                                        </span>
                                        <input
                                            v-model.trim="form.github_issue_url"
                                            name="github_issue_url"
                                            type="url"
                                            inputmode="url"
                                            :placeholder="lang.drawer['issue-hint']"
                                            class="h-9 rounded-md border bg-white dark:bg-cherry-900 px-2 text-sm text-gray-800 dark:text-white focus:outline-none focus:border-primary-500"
                                            :class="errors.github_issue_url ? 'border-red-500' : 'border-gray-300 dark:border-cherry-700'"
                                            v-on:keydown.enter.prevent="save"
                                        >
                                    </label>
                                </div>

                                <p v-if="errors.github_issue_url || errors.status" class="text-xs text-red-600 dark:text-red-400" v-text="errors.github_issue_url || errors.status"></p>

                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs" :class="dirty ? 'text-amber-700 dark:text-amber-400' : 'text-transparent'" aria-live="polite" v-text="dirty ? lang.drawer.unsaved : '.'"></span>
                                    <button type="button" class="primary-button !py-1.5" :disabled="! dirty || saving" data-feedback-save v-on:click="save" v-text="saving ? lang.drawer.saving : lang.drawer.save"></button>
                                </div>
                            </div>

                            {{-- Notes: Internal unless the triager chooses otherwise. --}}
                            <div class="grid gap-2">
                                <p class="text-xs font-medium text-gray-600 dark:text-slate-300" v-text="lang.drawer.notes"></p>

                                <p v-if="! report.notes || ! report.notes.length" class="text-xs text-gray-400 dark:text-slate-500" v-text="lang.drawer['no-notes']"></p>

                                <ol v-else class="grid gap-1.5" data-feedback-notes>
                                    <li
                                        v-for="note in report.notes"
                                        :key="note.id"
                                        class="rounded-md px-3 py-2 text-[13px] leading-5"
                                        :class="note.kind === 'status_change' ? 'bg-transparent px-0 py-0.5 text-xs text-gray-500 dark:text-slate-400' : (note.visible_to_reporter ? 'bg-primary-50 dark:bg-cherry-900 border-l-2 border-primary-400 dark:border-primary-500 text-gray-700 dark:text-slate-200' : 'bg-gray-100 dark:bg-cherry-900 text-gray-700 dark:text-slate-200')"
                                    >
                                        <template v-if="note.kind === 'status_change'">
                                            <span v-text="statusLine(note)"></span>
                                        </template>

                                        <template v-else>
                                            <span class="block text-[11px] text-gray-500 dark:text-slate-400" v-text="noteMeta(note)"></span>
                                            <span class="block whitespace-pre-line break-words" v-text="note.body"></span>
                                        </template>
                                    </li>
                                </ol>

                                <textarea
                                    v-model="noteBody"
                                    name="note"
                                    rows="2"
                                    maxlength="5000"
                                    :placeholder="lang.drawer['note-placeholder']"
                                    class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-2.5 py-2 text-sm text-gray-800 dark:text-white focus:outline-none focus:border-primary-500"
                                ></textarea>

                                <div class="flex items-center justify-between gap-2">
                                    <div class="inline-flex rounded-md bg-gray-100 dark:bg-cherry-900 p-0.5 text-xs" role="radiogroup" :aria-label="lang.drawer.notes">
                                        <button
                                            v-for="option in [{ visible: false, label: lang.drawer.internal }, { visible: true, label: lang.drawer.visible }]"
                                            :key="String(option.visible)"
                                            type="button"
                                            role="radio"
                                            class="rounded px-2.5 py-1 transition-colors"
                                            :class="noteVisible === option.visible ? 'bg-white dark:bg-cherry-700 font-medium text-gray-900 dark:text-white shadow-sm' : 'text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-white'"
                                            :aria-checked="noteVisible === option.visible ? 'true' : 'false'"
                                            :data-note-visibility="option.visible ? 'visible' : 'internal'"
                                            v-on:click="noteVisible = option.visible"
                                            v-text="option.label"
                                        ></button>
                                    </div>

                                    <button type="button" class="secondary-button !py-1" :disabled="! noteBody.trim() || posting" data-feedback-post v-on:click="postNote" v-text="posting ? lang.drawer.posting : lang.drawer.post"></button>
                                </div>

                                <p v-if="errors.body" class="text-xs text-red-600 dark:text-red-400" v-text="errors.body"></p>
                            </div>

                            {{-- Context: a one-line summary, the detail on demand. --}}
                            <details class="group text-xs text-gray-500 dark:text-slate-400">
                                <summary class="cursor-pointer list-none select-none hover:text-gray-800 dark:hover:text-white">
                                    <span v-text="lang.drawer.context + ': ' + contextSummary"></span>
                                    <span class="inline-block transition-transform group-open:rotate-180">▾</span>
                                </summary>

                                <dl class="mt-2 grid grid-cols-[88px_1fr] gap-x-3 gap-y-1">
                                    <template v-for="row in contextRows" :key="row.label">
                                        <dt class="text-gray-500 dark:text-slate-400" v-text="row.label"></dt>
                                        <dd class="min-w-0 break-all font-mono text-[11px] leading-5 text-gray-700 dark:text-slate-300" v-text="row.value"></dd>
                                    </template>
                                </dl>

                                <ol v-if="consoleLines.length" class="mt-2 grid gap-1 rounded-md bg-gray-50 dark:bg-cherry-900 p-2 font-mono text-[11px] leading-4 text-gray-700 dark:text-slate-300">
                                    <li v-for="(line, index) in consoleLines" :key="index" class="break-all" v-text="line"></li>
                                </ol>
                            </details>
                        </template>
                    </x-slot>

                    <x-slot:footer class="!pb-4 px-4 pt-3 border-t dark:border-cherry-700">
                        <div v-if="report" class="flex items-center justify-between gap-3">
                            <span class="text-xs text-gray-500 dark:text-slate-400" aria-live="polite" v-text="copyNote"></span>
                            <button type="button" class="secondary-button" data-feedback-copy v-on:click="copyIssue" v-text="lang.drawer['copy-issue']"></button>
                        </div>
                    </x-slot>
                </x-admin::drawer>

                {{-- Full-size screenshot. --}}
                <teleport to="body">
                    <div
                        v-if="lightbox && report && report.screenshot_url"
                        class="fixed inset-0 z-[10010] flex items-center justify-center bg-gray-950/85 p-6 cursor-zoom-out"
                        role="dialog"
                        aria-modal="true"
                        :aria-label="screenshotAlt"
                        data-feedback-lightbox
                        v-on:click="lightbox = false"
                    >
                        <img :src="report.screenshot_url" :alt="screenshotAlt" class="max-h-full max-w-full rounded shadow-2xl">
                        <button type="button" class="absolute top-4 right-4 icon-cancel text-3xl text-white rounded-md hover:bg-white/10" :aria-label="lang.drawer.close" v-on:click.stop="lightbox = false"></button>
                    </div>
                </teleport>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-feedback-triage', {
                template: '#v-dam-feedback-triage-template',

                data() {
                    return {
                        lang: @json(trans('dam::app.admin.feedback.triage')),
                        statuses: @json($statuses),
                        types: @json($types),
                        severities: @json($severities),
                        routes: {
                            show: @json(route('admin.dam.feedback.show', ':id')),
                            update: @json(route('admin.dam.feedback.update', ':id')),
                            notes: @json(route('admin.dam.feedback.notes.store', ':id')),
                        },
                        me: @json(auth()->guard('admin')->user()?->name),
                        quick: { status: 'open', type: '', reporter: '', severity: '' },
                        reporters: [],
                        selectedId: null,
                        report: null,
                        loadFailed: false,
                        form: { status: '', github_issue_url: '' },
                        errors: {},
                        saving: false,
                        noteBody: '',
                        noteVisible: false,
                        posting: false,
                        lightbox: false,
                        copyNote: '',
                        pending: null,
                    };
                },

                computed: {
                    gridColumns() {
                        return 'grid-template-columns: 52px minmax(200px, 3fr) minmax(72px, 1fr) minmax(84px, 1fr) minmax(96px, 1fr) minmax(88px, 1fr)';
                    },

                    quickFilters() {
                        const all = { value: '', label: this.lang.filters.all };

                        return [
                            { key: 'status', label: this.lang.filters.status, options: [
                                { value: 'open', label: this.lang.filters.open },
                                { value: 'closed', label: this.lang.filters.closed },
                                all,
                                ...this.statuses.map(value => ({ value, label: this.label('statuses', value) })),
                            ] },
                            { key: 'type', label: this.lang.filters.type, options: [all, ...this.types.map(value => ({ value, label: this.label('types', value) }))] },
                            { key: 'reporter', label: this.lang.filters.reporter, options: [all, ...this.reporters.map(option => ({ value: String(option.value), label: option.label }))] },
                            { key: 'severity', label: this.lang.filters.severity, options: [all, ...this.severities.map(value => ({ value, label: this.label('severities', value) }))] },
                        ];
                    },

                    dirty() {
                        return !! this.report && (
                            this.form.status !== this.report.status
                            || (this.form.github_issue_url || '') !== (this.report.github_issue_url || '')
                        );
                    },

                    byline() {
                        const report = this.report;

                        return [
                            '#' + report.id,
                            this.label('types', report.type),
                            report.severity ? this.label('severities', report.severity) : null,
                            report.reporter ? report.reporter.name : this.lang['deleted-user'],
                            this.longDate(report.created_at),
                        ].filter(Boolean).join(' · ');
                    },

                    screenshotAlt() {
                        return this.report ? this.lang.drawer['screenshot-alt'].replace(':id', this.report.id) : '';
                    },

                    issueShort() {
                        const match = (this.report?.github_issue_url || '').match(/\/(issues|pull)\/(\d+)$/);

                        return match ? '#' + match[2] + ' ↗' : '↗';
                    },

                    context() {
                        return this.report?.context || {};
                    },

                    browserLine() {
                        const context = this.context;
                        const size = context.viewport ? context.viewport + (context.dpr && String(context.dpr) !== '1' ? ' @' + context.dpr + 'x' : '') : null;

                        return [context.browser, context.os, size].filter(Boolean).join(' · ');
                    },

                    consoleLines() {
                        return (this.context.console || []).map(line => {
                            const where = line.source ? ' (' + line.source + (line.line ? ':' + line.line : '') + ')' : '';

                            const at = line.ts ? new Date(line.ts) : null;
                            const time = at && ! Number.isNaN(at.getTime()) ? at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' ' : '';

                            return time + (line.kind || 'console') + ': ' + line.message + where;
                        });
                    },

                    consoleCount() {
                        const count = this.consoleLines.length;

                        if (! count) {
                            return this.lang.drawer['console-none'];
                        }

                        return count === 1 ? this.lang.drawer['console-one'] : this.lang.drawer['console-many'].replace(':count', count);
                    },

                    contextSummary() {
                        return [this.browserLine, this.shortBuild, this.consoleCount].filter(Boolean).join(' · ');
                    },

                    shortBuild() {
                        const build = this.context.build || '';

                        return /^[0-9a-f]{12,}$/i.test(build) ? build.slice(0, 8) : build;
                    },

                    contextRows() {
                        const report = this.report;

                        return [
                            { label: this.lang.drawer.page, value: report.page_url },
                            { label: this.lang.drawer.where, value: [report.hub_app, report.route_name].filter(Boolean).join(' · ') },
                            { label: this.lang.drawer.browser, value: this.browserLine },
                            { label: this.lang.drawer.build, value: this.context.build },
                            { label: this.lang.drawer.reported, value: [report.created_at, this.context.timezone].filter(Boolean).join(' · ') },
                            { label: this.lang.drawer.console, value: this.consoleCount },
                        ].filter(row => row.value);
                    },
                },

                mounted() {
                    let visited = false;

                    try {
                        visited = window.localStorage.getItem('dam-feedback-triage-quick') !== null;
                    } catch (e) {}

                    // The grid restores its own saved filters; a first visit starts on the open reports.
                    if (visited) {
                        this.syncQuick();
                    } else {
                        this.applyQuick();
                    }

                    this.$watch(() => this.$refs.datagrid?.available?.columns, (columns) => {
                        const reporter = (columns || []).find(column => column.index === 'reporter');

                        this.reporters = reporter?.options?.params?.options || [];
                    }, { immediate: true });

                    // The toolbar's filter panel edits the same columns: keep the quick filters showing what applies.
                    this.$watch(() => JSON.stringify(this.$refs.datagrid?.applied?.filters?.columns || []), () => this.syncQuick());

                    const fromUrl = @json($openReport);

                    if (fromUrl) {
                        this.openReport(fromUrl);
                    }

                    window.addEventListener('keydown', this.onKeydown);
                },

                beforeUnmount() {
                    window.removeEventListener('keydown', this.onKeydown);
                },

                methods: {
                    label(group, value) {
                        return this.lang[group]?.[value] ?? value;
                    },

                    statusClass(status) {
                        return {
                            new: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
                            triaged: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                            in_progress: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
                            fixed: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
                            wont_fix: 'bg-gray-100 text-gray-600 dark:bg-cherry-900 dark:text-slate-400',
                        }[status] || 'bg-gray-100 text-gray-600';
                    },

                    headerColumns(columns) {
                        const order = ['id', 'title', 'type', 'severity', 'status', 'created_at'];

                        return order.map(index => columns.find(column => column.index === index)).filter(Boolean);
                    },

                    shortDate(value) {
                        const date = new Date(value);

                        if (Number.isNaN(date.getTime())) {
                            return value || '';
                        }

                        const today = new Date();
                        const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);

                        if (date.toDateString() === today.toDateString()) {
                            return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                        }

                        if (date.toDateString() === yesterday.toDateString()) {
                            return new Intl.RelativeTimeFormat([], { numeric: 'auto' }).format(-1, 'day').replace(/^./, c => c.toUpperCase());
                        }

                        return date.toLocaleDateString([], date.getFullYear() === today.getFullYear() ? { month: 'short', day: 'numeric' } : { year: 'numeric', month: 'short', day: 'numeric' });
                    },

                    longDate(value) {
                        const date = new Date(value);

                        if (Number.isNaN(date.getTime())) {
                            return value || '';
                        }

                        const time = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

                        return date.toDateString() === new Date().toDateString()
                            ? new Intl.RelativeTimeFormat([], { numeric: 'auto' }).format(0, 'day').replace(/^./, c => c.toUpperCase()) + ' ' + time
                            : this.shortDate(value) + ' ' + time;
                    },

                    who(name) {
                        return name && name === this.me ? this.lang.drawer.you : (name || this.lang['deleted-user']);
                    },

                    setQuick(key, value) {
                        this.quick[key] = value;
                        this.applyQuick();
                    },

                    syncQuick() {
                        const grid = this.$refs.datagrid;

                        if (! grid) {
                            return;
                        }

                        const same = (a, b) => a.length === b.length && a.every(value => b.includes(value));
                        const status = grid.getAppliedColumnValues('status').map(String);
                        const single = index => {
                            const values = grid.getAppliedColumnValues(index).map(String);

                            return values.length === 1 ? values[0] : '';
                        };

                        this.quick = {
                            status: same(status, ['new', 'triaged', 'in_progress']) ? 'open' : (same(status, ['fixed', 'wont_fix']) ? 'closed' : (status.length === 1 ? status[0] : '')),
                            type: single('type'),
                            reporter: single('reporter'),
                            severity: single('severity'),
                        };

                        try {
                            window.localStorage.setItem('dam-feedback-triage-quick', JSON.stringify(this.quick));
                        } catch (e) {}
                    },

                    applyQuick() {
                        const grid = this.$refs.datagrid;

                        if (! grid) {
                            return;
                        }

                        const values = {
                            status: this.quick.status === 'open' ? ['new', 'triaged', 'in_progress'] : (this.quick.status === 'closed' ? ['fixed', 'wont_fix'] : (this.quick.status ? [this.quick.status] : [])),
                            type: this.quick.type ? [this.quick.type] : [],
                            reporter: this.quick.reporter ? [this.quick.reporter] : [],
                            severity: this.quick.severity ? [this.quick.severity] : [],
                        };

                        for (const [index, list] of Object.entries(values)) {
                            grid.setAppliedColumnValues({ index }, list);
                        }

                        grid.applied.pagination.page = 1;
                        grid.get();
                    },

                    confirmDiscard(then) {
                        if (! this.dirty) {
                            then();

                            return;
                        }

                        this.pending = then;
                    },

                    discard() {
                        const then = this.pending;

                        this.pending = null;
                        this.form = { status: this.report.status, github_issue_url: this.report.github_issue_url || '' };
                        then?.();
                    },

                    requestClose() {
                        this.confirmDiscard(() => this.reset());
                    },

                    openReport(id) {
                        if (this.report && this.report.id === id && this.$refs.drawer.isOpen) {
                            return;
                        }

                        this.confirmDiscard(() => this.load(id));
                    },

                    load(id) {
                        this.selectedId = id;
                        this.pending = null;
                        this.report = null;
                        this.loadFailed = false;
                        this.errors = {};
                        this.noteBody = '';
                        this.noteVisible = false;
                        this.copyNote = '';
                        this.lightbox = false;
                        this.$refs.drawer.open();

                        this.$axios.get(this.routes.show.replace(':id', id))
                            .then(({ data }) => {
                                if (this.selectedId === id) {
                                    this.setReport(data.report);
                                }
                            })
                            .catch(() => {
                                this.loadFailed = true;
                            });
                    },

                    setReport(report) {
                        this.report = report;
                        this.form = { status: report.status, github_issue_url: report.github_issue_url || '' };
                    },

                    reset() {
                        this.$refs.drawer.close();
                        this.pending = null;
                        this.selectedId = null;
                        this.report = null;
                        this.lightbox = false;
                    },

                    onKeydown(event) {
                        if (event.key !== 'Escape') {
                            return;
                        }

                        if (this.lightbox) {
                            this.lightbox = false;
                            event.stopPropagation();
                        } else if (this.$refs.drawer?.isOpen) {
                            this.requestClose();
                        }
                    },

                    save() {
                        if (! this.dirty || this.saving) {
                            return;
                        }

                        const payload = {};

                        if (this.form.status !== this.report.status) {
                            payload.status = this.form.status;
                        }

                        if ((this.form.github_issue_url || '') !== (this.report.github_issue_url || '')) {
                            payload.github_issue_url = this.form.github_issue_url || null;
                        }

                        this.saving = true;
                        this.errors = {};

                        this.$axios.patch(this.routes.update.replace(':id', this.report.id), payload)
                            .then(({ data }) => {
                                this.setReport(data.report);
                                this.$emitter.emit('add-flash', { type: 'success', message: this.lang.drawer.saved.replace(':id', data.report.id) });
                                this.$refs.datagrid.get();
                            })
                            .catch((error) => {
                                const errors = error?.response?.data?.errors || {};

                                this.errors = { status: errors.status?.[0], github_issue_url: errors.github_issue_url?.[0] };

                                if (! this.errors.status && ! this.errors.github_issue_url) {
                                    this.$emitter.emit('add-flash', { type: 'error', message: error?.response?.data?.message || this.lang.drawer['save-failed'] });
                                }
                            })
                            .finally(() => {
                                this.saving = false;
                            });
                    },

                    postNote() {
                        const body = this.noteBody.trim();

                        if (! body || this.posting) {
                            return;
                        }

                        this.posting = true;
                        this.errors = { ...this.errors, body: null };

                        this.$axios.post(this.routes.notes.replace(':id', this.report.id), { body, visible_to_reporter: this.noteVisible })
                            .then(({ data }) => {
                                // Keep unsaved status/issue edits: only the notes come from the server.
                                this.report = { ...this.report, notes: data.report.notes, updated_at: data.report.updated_at };
                                this.noteBody = '';
                                this.noteVisible = false;
                            })
                            .catch((error) => {
                                this.errors = { ...this.errors, body: error?.response?.data?.errors?.body?.[0] || this.lang.drawer['note-failed'] };
                            })
                            .finally(() => {
                                this.posting = false;
                            });
                    },

                    noteMeta(note) {
                        const who = this.who(note.author);
                        const audience = note.visible_to_reporter ? this.lang.drawer.visible : this.lang.drawer.internal;

                        return [who, audience.toLowerCase(), this.shortDate(note.created_at)].join(' · ');
                    },

                    statusLine(note) {
                        return this.lang.drawer['status-change']
                            .replace(':from', this.label('statuses', note.from_status))
                            .replace(':to', this.label('statuses', note.to_status))
                            + ' · ' + this.who(note.author) + ' · ' + this.shortDate(note.created_at);
                    },

                    issueMarkdown() {
                        const report = this.report;
                        const issue = this.lang.issue;
                        const cell = value => String(value ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ');
                        const code = value => value ? '`' + String(value).replace(/`/g, "'") + '`' : '';
                        const lines = [
                            '## ' + report.title,
                            '',
                            report.description,
                            '',
                            '| | |',
                            '|---|---|',
                            '| ' + issue['reported-by'] + ' | ' + cell([report.reporter?.name, report.created_at].filter(Boolean).join(' · ')) + ' |',
                            '| ' + issue.status + ' | ' + cell([this.label('types', report.type), report.severity ? this.label('severities', report.severity) : null, this.label('statuses', report.status)].filter(Boolean).join(' · ')) + ' |',
                        ];

                        if (report.page_url) {
                            lines.push('| ' + issue.page + ' | ' + code(cell(report.page_url)) + ' |');
                        }

                        if (report.route_name || report.hub_app) {
                            lines.push('| ' + issue.where + ' | ' + code(cell([report.hub_app, report.route_name].filter(Boolean).join(' · '))) + ' |');
                        }

                        if (this.browserLine) {
                            lines.push('| ' + issue.browser + ' | ' + cell(this.browserLine) + ' |');
                        }

                        if (this.context.build) {
                            lines.push('| ' + issue.build + ' | ' + code(cell(this.context.build)) + ' |');
                        }

                        if (this.consoleLines.length) {
                            lines.push('', '<details><summary>' + issue.console + ' (' + this.consoleLines.length + ')</summary>', '', '```', ...this.consoleLines.map(line => line.replace(/```/g, "'''")), '```', '</details>');
                        }

                        if (report.has_screenshot) {
                            lines.push('', issue.screenshot.replace(':id', report.id));
                        }

                        const link = window.location.origin + window.location.pathname + '?report=' + report.id;

                        lines.push('', '_[' + issue.footer.replace(':id', report.id) + '](' + link + ')_');

                        return lines.join('\n');
                    },

                    copyIssue() {
                        const text = this.issueMarkdown();
                        const done = () => { this.copyNote = this.lang.drawer.copied; };
                        const failed = () => { this.copyNote = this.lang.drawer['copy-failed']; };

                        if (navigator.clipboard?.writeText) {
                            navigator.clipboard.writeText(text).then(done, () => this.copyFallback(text) ? done() : failed());

                            return;
                        }

                        this.copyFallback(text) ? done() : failed();
                    },

                    copyFallback(text) {
                        const area = document.createElement('textarea');
                        area.value = text;
                        area.setAttribute('readonly', '');
                        area.style.position = 'fixed';
                        area.style.opacity = '0';
                        document.body.appendChild(area);
                        area.select();

                        let copied = false;

                        try {
                            copied = document.execCommand('copy');
                        } catch (e) {}

                        area.remove();

                        return copied;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
