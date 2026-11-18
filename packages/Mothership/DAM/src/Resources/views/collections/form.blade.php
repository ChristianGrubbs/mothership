{{--
    P4 D2/D23: the collection editor, shared by the Collections list (create) and a collection's page (edit).
    Name, description, audience (people and groups, or a public link), expiry and the /c/ slug. Every control is a
    native input or button, so the whole form is keyboard-operable.
--}}
@pushOnce('scripts')
<script type="text/x-template" id="v-dam-collection-form-template">
    <form @submit.prevent="save" data-collection-form class="rounded-lg border border-gray-200 bg-white p-4 dark:border-cherry-800 dark:bg-cherry-900" novalidate>
        <fieldset :disabled="busy" class="min-w-0">
            <legend class="text-lg font-semibold text-gray-800 dark:text-white">
                @{{ initial ? @js(trans('dam::app.admin.dam.collections.form.edit-title')) : @js(trans('dam::app.admin.dam.collections.form.create-title')) }}
            </legend>

            <label :for="uid + '-name'" class="mt-4 block text-sm font-medium text-gray-700 dark:text-slate-200">
                @lang('dam::app.admin.dam.collections.form.name')
            </label>
            <input
                :id="uid + '-name'"
                ref="name"
                v-model="draft.name"
                required
                maxlength="191"
                data-collection-name
                class="mt-1 w-full rounded border border-gray-300 p-2 text-gray-700 dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200"
            >
            <p v-if="errors.name" class="mt-1 text-xs text-red-600" role="alert">@{{ errors.name }}</p>

            <label :for="uid + '-description'" class="mt-4 block text-sm font-medium text-gray-700 dark:text-slate-200">
                @lang('dam::app.admin.dam.collections.form.description')
            </label>
            <textarea
                :id="uid + '-description'"
                v-model="draft.description"
                rows="2"
                maxlength="2000"
                class="mt-1 w-full rounded border border-gray-300 p-2 text-gray-700 dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200"
            ></textarea>

            <fieldset class="mt-4" data-collection-audience>
                <legend class="text-sm font-medium text-gray-700 dark:text-slate-200">@lang('dam::app.admin.dam.collections.form.audience')</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="option in audienceOptions"
                        :key="option.value"
                        class="flex cursor-pointer gap-3 rounded border p-3 text-sm"
                        :class="draft.audience === option.value ? 'border-primary-600 bg-primary-50 dark:border-primary-400 dark:bg-cherry-800' : 'border-gray-300 dark:border-cherry-700'"
                    >
                        <input type="radio" :value="option.value" v-model="draft.audience" :name="uid + '-audience'" class="mt-1" :data-collection-audience-option="option.value">
                        <span>
                            <span class="block font-medium text-gray-800 dark:text-white">@{{ option.label }}</span>
                            <span class="block text-xs text-gray-500 dark:text-slate-400">@{{ option.help }}</span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <div v-if="draft.audience === 'internal'" class="mt-4" data-collection-people>
                <label :for="uid + '-people'" class="block text-sm font-medium text-gray-700 dark:text-slate-200">
                    @lang('dam::app.admin.dam.collections.form.people')
                </label>
                <ul v-if="draft.groups.length || draft.users.length" class="mt-2 flex flex-wrap gap-2" aria-live="polite">
                    <li v-for="group in draft.groups" :key="'g' + group.id" class="flex items-center gap-1 rounded-full bg-gray-100 py-0.5 pl-3 pr-1 text-sm text-gray-700 dark:bg-cherry-800 dark:text-slate-200" data-collection-principal="group">
                        <span class="icon-users text-base" aria-hidden="true"></span>
                        @{{ group.name }}
                        <button type="button" class="rounded-full px-2 text-gray-500 hover:bg-gray-200 hover:text-gray-800 dark:hover:bg-cherry-700" :aria-label="label('remove-principal', { name: group.name })" @click="removePrincipal('groups', group.id)">×</button>
                    </li>
                    <li v-for="user in draft.users" :key="'u' + user.id" class="flex items-center gap-1 rounded-full bg-gray-100 py-0.5 pl-3 pr-1 text-sm text-gray-700 dark:bg-cherry-800 dark:text-slate-200" data-collection-principal="user">
                        @{{ user.name }}
                        <button type="button" class="rounded-full px-2 text-gray-500 hover:bg-gray-200 hover:text-gray-800 dark:hover:bg-cherry-700" :aria-label="label('remove-principal', { name: user.name })" @click="removePrincipal('users', user.id)">×</button>
                    </li>
                </ul>
                <p v-else class="mt-2 text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.form.people-none')</p>

                <input
                    :id="uid + '-people'"
                    v-model="principalQuery"
                    @input="searchPrincipals"
                    type="search"
                    autocomplete="off"
                    :placeholder="@js(trans('dam::app.admin.dam.collections.form.people-placeholder'))"
                    data-collection-people-search
                    class="mt-2 w-full rounded border border-gray-300 p-2 text-gray-700 dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200"
                >
                <ul v-if="principalResults.length" class="mt-1 max-h-48 overflow-y-auto rounded border border-gray-200 dark:border-cherry-700" :aria-label="@js(trans('dam::app.admin.dam.collections.form.people-results'))">
                    <li v-for="result in principalResults" :key="result.type + result.id" class="border-b border-gray-100 last:border-b-0 dark:border-cherry-800">
                        <button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-cherry-800" @click="addPrincipal(result)" data-collection-people-result>
                            <span class="min-w-0">
                                <span class="block truncate text-gray-800 dark:text-white">@{{ result.name }}</span>
                                <span class="block truncate text-xs text-gray-500 dark:text-slate-400">@{{ result.detail }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-medium text-primary-700 dark:text-primary-400">@lang('dam::app.admin.dam.collections.form.people-add')</span>
                        </button>
                    </li>
                </ul>
            </div>

            <p v-else class="mt-4 rounded bg-amber-50 p-3 text-xs text-amber-900 dark:bg-cherry-800 dark:text-amber-200" data-collection-public-note>
                @lang('dam::app.admin.dam.collections.form.public-note')
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label :for="uid + '-expires'" class="block text-sm font-medium text-gray-700 dark:text-slate-200">
                        @lang('dam::app.admin.dam.collections.form.expires')
                    </label>
                    <input
                        :id="uid + '-expires'"
                        v-model="draft.expires_on"
                        type="date"
                        data-collection-expires
                        class="mt-1 w-full rounded border border-gray-300 p-2 text-gray-700 dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200"
                    >
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.form.expires-help')</p>
                </div>

                <div>
                    <label :for="uid + '-slug'" class="block text-sm font-medium text-gray-700 dark:text-slate-200">
                        @lang('dam::app.admin.dam.collections.form.slug')
                    </label>
                    <div class="mt-1 flex items-stretch rounded border border-gray-300 dark:border-cherry-700">
                        <span class="flex items-center border-r border-gray-300 bg-gray-50 px-2 text-sm text-gray-500 dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-400" aria-hidden="true">/c/</span>
                        <input
                            :id="uid + '-slug'"
                            v-model="draft.slug"
                            maxlength="64"
                            autocomplete="off"
                            spellcheck="false"
                            data-collection-slug
                            :aria-describedby="uid + '-slug-help'"
                            class="min-w-0 flex-1 rounded-r p-2 text-gray-700 dark:bg-cherry-800 dark:text-slate-200"
                        >
                    </div>
                    <p :id="uid + '-slug-help'" class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                        @{{ draft.audience === 'public' ? @js(trans('dam::app.admin.dam.collections.form.slug-help-public')) : @js(trans('dam::app.admin.dam.collections.form.slug-help-internal')) }}
                    </p>
                    <p v-if="errors.slug" class="mt-1 text-xs text-red-600" role="alert" data-collection-slug-error>@{{ errors.slug }}</p>
                </div>
            </div>

            <label v-if="initial" class="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-slate-200">
                <input type="checkbox" v-model="draft.archived" data-collection-archived>
                @lang('dam::app.admin.dam.collections.form.archived')
            </label>

            <p v-if="errors.general" class="mt-4 text-sm text-red-600" role="alert">@{{ errors.general }}</p>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button type="submit" class="primary-button" data-collection-save :aria-busy="busy">
                    @{{ busy ? @js(trans('dam::app.admin.dam.collections.form.saving')) : (initial ? @js(trans('dam::app.admin.dam.collections.form.save')) : @js(trans('dam::app.admin.dam.collections.form.create'))) }}
                </button>
                <button type="button" class="secondary-button" @click="$emit('cancel')">@lang('dam::app.admin.dam.collections.form.cancel')</button>
                <button v-if="initial" type="button" class="ml-auto text-sm text-red-600 hover:underline" data-collection-delete @click="remove">
                    @lang('dam::app.admin.dam.collections.form.delete')
                </button>
            </div>
        </fieldset>
    </form>
</script>

<script type="module">
app.component('v-dam-collection-form', {
    template: '#v-dam-collection-form-template',
    props: {
        initial: { type: Object, default: null },
    },
    emits: ['saved', 'cancel', 'deleted'],
    data() {
        const c = this.initial;

        return {
            uid: 'collection-form-' + Math.random().toString(36).slice(2, 8),
            draft: {
                name: c?.name ?? '',
                description: c?.description ?? '',
                audience: c?.audience ?? 'internal',
                users: [...(c?.audience_principals?.users ?? [])],
                groups: [...(c?.audience_principals?.groups ?? [])],
                expires_on: c?.expires_at ? c.expires_at.slice(0, 10) : '',
                slug: c?.slug ?? '',
                archived: c?.status === 'archived',
            },
            principalQuery: '',
            principalResults: [],
            principalTimer: null,
            busy: false,
            errors: {},
            audienceOptions: [
                { value: 'internal', label: @js(trans('dam::app.admin.dam.collections.audience.internal')), help: @js(trans('dam::app.admin.dam.collections.audience.internal-help')) },
                { value: 'public', label: @js(trans('dam::app.admin.dam.collections.audience.public')), help: @js(trans('dam::app.admin.dam.collections.audience.public-help')) },
            ],
        };
    },
    mounted() {
        this.$nextTick(() => this.$refs.name?.focus());
    },
    methods: {
        label(key, replace = {}) {
            const labels = {
                'remove-principal': @js(trans('dam::app.admin.dam.collections.form.remove-principal')),
                'delete-confirm': @js(trans('dam::app.admin.dam.collections.form.delete-confirm')),
                'save-failed': @js(trans('dam::app.admin.dam.collections.form.save-failed')),
                'name-required': @js(trans('dam::app.admin.dam.collections.form.name-required')),
            };

            return Object.entries(replace).reduce((text, [k, v]) => text.replace(':' + k, v), labels[key] ?? key);
        },
        searchPrincipals() {
            clearTimeout(this.principalTimer);
            const q = this.principalQuery.trim();
            if (! q) {
                this.principalResults = [];
                return;
            }

            this.principalTimer = setTimeout(async () => {
                try {
                    const { data } = await this.$axios.get(@json(route('admin.dam.collections.principals')), { params: { q } });
                    const chosenGroups = this.draft.groups.map(g => g.id);
                    const chosenUsers = this.draft.users.map(u => u.id);
                    this.principalResults = [
                        ...data.groups.filter(g => ! chosenGroups.includes(g.id)).map(g => ({ type: 'group', id: g.id, name: g.name, detail: @js(trans('dam::app.admin.dam.collections.form.group')), raw: g })),
                        ...data.users.filter(u => ! chosenUsers.includes(u.id)).map(u => ({ type: 'user', id: u.id, name: u.name, detail: u.email, raw: u })),
                    ];
                } catch (e) {
                    this.principalResults = [];
                }
            }, 250);
        },
        addPrincipal(result) {
            const list = result.type === 'group' ? this.draft.groups : this.draft.users;
            if (! list.some(p => p.id === result.id)) list.push(result.raw);
            this.principalResults = this.principalResults.filter(r => ! (r.type === result.type && r.id === result.id));
        },
        removePrincipal(kind, id) {
            this.draft[kind] = this.draft[kind].filter(p => p.id !== id);
        },
        payload() {
            return {
                name: this.draft.name.trim(),
                description: this.draft.description?.trim() || null,
                audience: this.draft.audience,
                expires_at: this.draft.expires_on ? this.draft.expires_on + ' 23:59:59' : null,
                slug: this.draft.slug?.trim().toLowerCase() || null,
            };
        },
        async save() {
            if (this.busy) return;
            this.errors = {};
            if (! this.draft.name.trim()) {
                this.errors = { name: this.label('name-required') };
                this.$refs.name?.focus();
                return;
            }

            this.busy = true;
            const audience = { user_ids: this.draft.users.map(u => u.id), group_ids: this.draft.groups.map(g => g.id) };

            try {
                let collection;
                if (this.initial) {
                    const { data } = await this.$axios.patch(this.initial.update_url, { ...this.payload(), status: this.draft.archived ? 'archived' : 'active' });
                    collection = data.collection;
                    if (this.draft.audience === 'internal') {
                        const saved = await this.$axios.put(this.initial.audience_url, audience);
                        collection = saved.data.collection;
                    }
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                } else {
                    const { data } = await this.$axios.post(@json(route('admin.dam.collections.store')), { ...this.payload(), ...(this.draft.audience === 'internal' ? audience : {}) });
                    collection = data.collection;
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                }
                this.$emit('saved', collection);
            } catch (e) {
                const response = e.response?.data ?? {};
                const fieldErrors = response.errors ?? {};
                const message = response.message || this.label('save-failed');
                this.errors = {
                    name: fieldErrors.name?.[0],
                    slug: fieldErrors.slug?.[0] ?? (/slug|\/c\//i.test(message) ? message : undefined),
                };
                if (! this.errors.name && ! this.errors.slug) this.errors.general = message;
                this.$emitter.emit('add-flash', { type: 'error', message });
            } finally {
                this.busy = false;
            }
        },
        async remove() {
            if (this.busy || ! this.initial) return;
            if (! window.confirm(this.label('delete-confirm').replace(':name', this.initial.name))) return;

            this.busy = true;
            try {
                const { data } = await this.$axios.delete(this.initial.update_url);
                this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                this.$emit('deleted');
            } catch (e) {
                this.$emitter.emit('add-flash', { type: 'error', message: e.response?.data?.message || this.label('save-failed') });
            } finally {
                this.busy = false;
            }
        },
    },
});
</script>
@endPushOnce
