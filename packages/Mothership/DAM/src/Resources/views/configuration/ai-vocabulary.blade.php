{{--
    P5c (D8): Settings, Library, AI vocabulary. The words the AI may propose as tags: add, rename (re-indexes search),
    disable (stops new proposals and drops open ones from review; accepted tags stay), synonyms, and a mapping to an
    existing tag. Every save goes through AiVocabulary::save(), so TagNameRule and the 200-term cap apply here as in REST.
--}}
<x-admin::layouts>
    <x-slot:title>@lang('dam::app.admin.ai-vocabulary.title')</x-slot:title>

    <v-dam-ai-vocabulary :initial="@js($initial)"></v-dam-ai-vocabulary>

    @pushOnce('scripts')
    <script type="text/x-template" id="v-dam-ai-vocabulary-template">
        <div class="mx-auto max-w-6xl pb-10" data-dam-ai-vocabulary>
            <a class="text-sm text-primary-600" href="{{ route('admin.dam.configuration.index') }}">@lang('dam::app.admin.ai-vocabulary.back')</a>

            <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.ai-vocabulary.title')</h1>
                    <p class="mt-2 max-w-2xl text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.ai-vocabulary.description')</p>
                    <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200" data-dam-ai-vocabulary-count v-text="enabledLabel"></p>
                </div>
                <button type="button" class="primary-button whitespace-nowrap" data-dam-ai-vocabulary-new @click="startCreate">@lang('dam::app.admin.ai-vocabulary.new')</button>
            </div>

            <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <section
                    v-if="draft"
                    class="order-first rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-cherry-900 lg:sticky lg:top-4 lg:order-last"
                    data-dam-ai-vocabulary-form
                    :aria-label="draft.isNew ? t.new : t.edit"
                >
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white" v-text="draft.isNew ? t.new : draft.term"></h2>

                    <form class="mt-4 grid gap-4" @submit.prevent="save">
                        <label v-if="draft.isNew" class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            @lang('dam::app.admin.ai-vocabulary.term')
                            <input v-model.trim="draft.term" maxlength="64" required pattern="[a-z0-9][a-z0-9\-]*" class="rounded-md border border-gray-300 px-3 py-2 font-mono text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-vocabulary-input="term">
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">@lang('dam::app.admin.ai-vocabulary.term-hint')</span>
                            <span v-if="errors.term" class="text-xs font-normal text-red-600" role="alert" v-text="errors.term"></span>
                        </label>

                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            @lang('dam::app.admin.ai-vocabulary.label')
                            <input v-model.trim="draft.label" maxlength="191" required class="rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-vocabulary-input="label">
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">@lang('dam::app.admin.ai-vocabulary.label-hint')</span>
                            <span v-if="errors.label" class="text-xs font-normal text-red-600" role="alert" v-text="errors.label"></span>
                        </label>

                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            @lang('dam::app.admin.ai-vocabulary.synonyms')
                            <input v-model="draft.synonyms" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-vocabulary-input="synonyms">
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400" v-text="t.synonymsHint"></span>
                            <span v-if="errors.synonyms" class="text-xs font-normal text-red-600" role="alert" v-text="errors.synonyms"></span>
                        </label>

                        <div class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            <label for="dam-ai-vocabulary-tag">@lang('dam::app.admin.ai-vocabulary.maps-to')</label>
                            <div v-if="draft.maps_to" class="flex items-center justify-between gap-2 rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700">
                                <span class="truncate" v-text="draft.maps_to.name"></span>
                                <button type="button" class="shrink-0 text-xs text-primary-700 hover:underline dark:text-primary-300" @click="draft.maps_to = null">@lang('dam::app.admin.ai-vocabulary.clear-mapping')</button>
                            </div>
                            <div v-else class="relative">
                                <input
                                    id="dam-ai-vocabulary-tag"
                                    v-model="tagQuery"
                                    type="search"
                                    autocomplete="off"
                                    :placeholder="t.searchTags"
                                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800"
                                    @input="searchTags"
                                    data-vocabulary-input="maps_to"
                                >
                                <ul v-if="tagResults.length" class="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-cherry-700 dark:bg-cherry-800">
                                    <li v-for="tag in tagResults" :key="tag.id">
                                        <button type="button" class="block w-full truncate px-3 py-1.5 text-start text-sm font-normal hover:bg-primary-50 focus:bg-primary-50 dark:hover:bg-cherry-700" @click="pickTag(tag)" v-text="tag.name"></button>
                                    </li>
                                </ul>
                            </div>
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">@lang('dam::app.admin.ai-vocabulary.maps-to-hint')</span>
                            <span v-if="errors.maps_to_tag_id" class="text-xs font-normal text-red-600" role="alert" v-text="errors.maps_to_tag_id"></span>
                        </div>

                        <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                            <input v-model="draft.enabled" type="checkbox" class="h-4 w-4" data-vocabulary-input="enabled">
                            @lang('dam::app.admin.ai-vocabulary.enabled')
                        </label>
                        <span v-if="errors.enabled" class="-mt-2 text-xs font-normal text-red-600" role="alert" v-text="errors.enabled"></span>

                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="primary-button" :disabled="busy" v-text="busy ? t.saving : t.save"></button>
                            <button type="button" class="secondary-button" :disabled="busy" @click="draft = null">@lang('dam::app.admin.ai-vocabulary.cancel')</button>
                        </div>
                    </form>
                </section>

                <section class="min-w-0 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-cherry-900">
                    <div class="border-b border-gray-200 p-3 dark:border-gray-700">
                        <input v-model="filter" type="search" :placeholder="t.filter" :aria-label="t.filter" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-cherry-700 dark:bg-cherry-800 sm:max-w-xs">
                    </div>

                    <p v-if="! shown.length" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.ai-vocabulary.empty')</p>

                    <!-- Below md each term is a card; from md a table row. -->
                    <div v-else>
                        <div class="hidden grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.4fr)_minmax(0,1fr)_6rem_8.5rem] gap-3 border-b border-gray-200 bg-primary-50 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:border-gray-700 dark:bg-cherry-900 dark:text-gray-300 md:grid">
                            <span>@lang('dam::app.admin.ai-vocabulary.term')</span>
                            <span>@lang('dam::app.admin.ai-vocabulary.label')</span>
                            <span>@lang('dam::app.admin.ai-vocabulary.synonyms')</span>
                            <span>@lang('dam::app.admin.ai-vocabulary.maps-to')</span>
                            <span>@lang('dam::app.admin.ai-vocabulary.status')</span>
                            <span></span>
                        </div>

                        <div
                            v-for="row in shown"
                            :key="row.term"
                            class="flex flex-col gap-1 border-b border-gray-100 px-4 py-3 text-sm text-gray-700 last:border-b-0 dark:border-cherry-800 dark:text-gray-300 md:grid md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.4fr)_minmax(0,1fr)_6rem_8.5rem] md:items-center md:gap-3"
                            :class="{ 'opacity-60': ! row.enabled }"
                            data-dam-ai-vocabulary-row
                            :data-term="row.term"
                        >
                            <span class="truncate font-mono text-xs" :title="row.term" v-text="row.term"></span>
                            <span class="truncate font-semibold text-gray-800 dark:text-white md:font-normal md:text-gray-700 md:dark:text-gray-300" :title="row.label" v-text="row.label"></span>
                            <span class="truncate text-gray-500 dark:text-gray-400" :title="row.synonyms.join(', ')"><span class="me-1 md:hidden">@lang('dam::app.admin.ai-vocabulary.synonyms'):</span><span v-text="row.synonyms.join(', ') || '—'"></span></span>
                            <span class="truncate text-gray-500 dark:text-gray-400"><span class="me-1 md:hidden">@lang('dam::app.admin.ai-vocabulary.maps-to'):</span><span v-text="row.maps_to ? row.maps_to.name : '—'"></span></span>
                            <span>
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold" :class="row.enabled ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-cherry-800 dark:text-gray-300'" v-text="row.enabled ? t.enabled : t.disabled"></span>
                            </span>
                            <span class="mt-1 flex flex-wrap gap-2 md:mt-0 md:justify-end">
                                <button type="button" class="secondary-button !h-8 !px-2.5 !py-1 text-xs" :disabled="busy" @click="startEdit(row)">@lang('dam::app.admin.ai-vocabulary.edit')</button>
                                <button type="button" class="transparent-button !h-8 !px-2.5 !py-1 text-xs" :disabled="busy" @click="toggle(row)" v-text="row.enabled ? t.disable : t.enable"></button>
                            </span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-dam-ai-vocabulary', {
            template: '#v-dam-ai-vocabulary-template',

            props: {
                initial: { type: Object, required: true },
            },

            data() {
                return {
                    terms: this.initial.terms,
                    draft: null,
                    errors: {},
                    busy: false,
                    filter: '',
                    tagQuery: '',
                    tagResults: [],
                    t: {
                        new: @js(trans('dam::app.admin.ai-vocabulary.new')),
                        edit: @js(trans('dam::app.admin.ai-vocabulary.edit')),
                        save: @js(trans('dam::app.admin.ai-vocabulary.save')),
                        saving: @js(trans('dam::app.admin.ai-vocabulary.saving')),
                        enabled: @js(trans('dam::app.admin.ai-vocabulary.enabled')),
                        disabled: @js(trans('dam::app.admin.ai-vocabulary.disabled')),
                        enable: @js(trans('dam::app.admin.ai-vocabulary.enable')),
                        disable: @js(trans('dam::app.admin.ai-vocabulary.disable')),
                        filter: @js(trans('dam::app.admin.ai-vocabulary.filter')),
                        searchTags: @js(trans('dam::app.admin.ai-vocabulary.search-tags')),
                        failed: @js(trans('dam::app.admin.ai-vocabulary.failed')),
                        count: @js(trans('dam::app.admin.ai-vocabulary.enabled-count')),
                        synonymsHint: @js(trans('dam::app.admin.ai-vocabulary.synonyms-hint', ['max' => $initial['max_synonyms']])),
                    },
                };
            },

            computed: {
                shown() {
                    const needle = this.filter.trim().toLowerCase();
                    if (! needle) return this.terms;

                    return this.terms.filter(row => [row.term, row.label, ...row.synonyms].some(text => String(text).toLowerCase().includes(needle)));
                },

                enabledLabel() {
                    return this.t.count.replace(':count', this.terms.filter(row => row.enabled).length).replace(':max', this.initial.max_enabled);
                },
            },

            methods: {
                startCreate() {
                    this.errors = {};
                    this.tagQuery = '';
                    this.tagResults = [];
                    this.draft = { isNew: true, term: '', label: '', synonyms: '', maps_to: null, enabled: true };
                },

                startEdit(row) {
                    this.errors = {};
                    this.tagQuery = '';
                    this.tagResults = [];
                    this.draft = { isNew: false, term: row.term, label: row.label, synonyms: row.synonyms.join(', '), maps_to: row.maps_to, enabled: row.enabled };
                },

                searchTags() {
                    clearTimeout(this._tagTimer);
                    const query = this.tagQuery.trim();
                    if (! query) {
                        this.tagResults = [];
                        return;
                    }
                    this._tagTimer = setTimeout(async () => {
                        try {
                            const { data } = await this.$axios.get(@js(route('admin.dam.tags.list')), { params: { query, per_page: 8 } });
                            if (query === this.tagQuery.trim()) this.tagResults = data.data || [];
                        } catch (e) {
                            this.tagResults = [];
                        }
                    }, 250);
                },

                pickTag(tag) {
                    this.draft.maps_to = { id: tag.id, name: tag.name };
                    this.tagQuery = '';
                    this.tagResults = [];
                },

                payload(draft) {
                    const body = {
                        label: draft.label,
                        enabled: draft.enabled,
                        synonyms: draft.synonyms.split(',').map(s => s.trim()).filter(Boolean),
                        maps_to_tag_id: draft.maps_to ? draft.maps_to.id : null,
                    };
                    if (draft.isNew) body.term = draft.term;

                    return body;
                },

                replace(saved) {
                    const index = this.terms.findIndex(row => row.term === saved.term);
                    if (index === -1) this.terms.push(saved);
                    else this.terms.splice(index, 1, saved);
                },

                firstErrors(error) {
                    const errors = {};
                    Object.entries(error.response?.data?.errors || {}).forEach(([field, messages]) => { errors[field.split('.')[0]] = messages[0]; });

                    return errors;
                },

                async save() {
                    this.busy = true;
                    this.errors = {};
                    try {
                        const { data } = this.draft.isNew
                            ? await this.$axios.post(@js(route('admin.dam.configuration.ai-vocabulary.store')), this.payload(this.draft))
                            : await this.$axios.put(@js(route('admin.dam.configuration.ai-vocabulary.update', ['term' => '__TERM__'])).replace('__TERM__', encodeURIComponent(this.draft.term)), this.payload(this.draft));
                        this.replace(data.term);
                        this.draft = null;
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                    } catch (error) {
                        this.errors = this.firstErrors(error);
                        if (! Object.keys(this.errors).length) this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || this.t.failed });
                    } finally {
                        this.busy = false;
                    }
                },

                async toggle(row) {
                    this.busy = true;
                    try {
                        const { data } = await this.$axios.put(@js(route('admin.dam.configuration.ai-vocabulary.update', ['term' => '__TERM__'])).replace('__TERM__', encodeURIComponent(row.term)), { enabled: ! row.enabled });
                        this.replace(data.term);
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                    } catch (error) {
                        const first = Object.values(this.firstErrors(error))[0];
                        this.$emitter.emit('add-flash', { type: 'error', message: first || error.response?.data?.message || this.t.failed });
                    } finally {
                        this.busy = false;
                    }
                },
            },
        });
    </script>
    @endPushOnce
</x-admin::layouts>
