<x-admin::layouts>
    <x-slot:title>@lang('dam::app.admin.dam.groups.title')</x-slot:title>
    <v-access-groups></v-access-groups>
    @pushOnce('scripts')
    <script type="text/x-template" id="v-access-groups-template">
        <div class="mx-auto max-w-6xl pb-10">
            <x-admin::breadcrumbs :leaf="trans('dam::app.admin.dam.groups.title')" />

            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.groups.title')</h1>
                    <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.groups.description')</p>
                </div>
                <button type="button" class="primary-button shrink-0 whitespace-nowrap" :disabled="loading" @click="edit()">
                    @lang('dam::app.admin.dam.groups.create')
                </button>
            </div>

            <div v-if="loading" data-groups-loading class="mt-6 grid gap-6 md:grid-cols-2">
                <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-cherry-800 dark:bg-cherry-900">
                    <div class="h-4 w-1/3 animate-pulse rounded bg-gray-200 dark:bg-cherry-800"></div>
                    <div class="mt-4 space-y-3">
                        <div v-for="n in 4" :key="n" class="h-12 animate-pulse rounded bg-gray-100 dark:bg-cherry-800"></div>
                    </div>
                </section>
            </div>

            <div v-else-if="error" data-groups-error role="alert" class="mt-6 flex flex-col items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                <span>@{{ error }}</span>
                <button type="button" class="secondary-button" data-groups-retry @click="load">
                    @lang('dam::app.admin.dam.groups.retry')
                </button>
            </div>

            <div v-else class="mt-6 grid gap-6 md:grid-cols-2">
                <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-cherry-800 dark:bg-cherry-900">
                    <label for="group-search" class="text-sm font-medium text-gray-700 dark:text-slate-200">
                        @lang('dam::app.admin.dam.groups.search-groups')
                    </label>
                    <input
                        id="group-search"
                        v-model="search"
                        placeholder="@lang('dam::app.admin.dam.groups.search-groups-placeholder')"
                        class="mt-2 w-full rounded border border-gray-300 p-2 text-gray-700 dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200"
                    >

                    {{-- Everyone is built in and read-only, yet active: it renders as a plain row rather than a
                         disabled button, which the global button:disabled rule would grey out. --}}
                    <component
                        v-for="group in filteredGroups"
                        :key="group.id"
                        :is="group.is_system ? 'div' : 'button'"
                        :type="group.is_system ? null : 'button'"
                        data-group-row
                        :data-group-everyone="group.is_system || null"
                        :title="group.is_system ? @js(trans('dam::app.admin.dam.groups.everyone-read-only')) : null"
                        @click="group.is_system || edit(group)"
                        class="flex w-full items-center justify-between border-b border-gray-200 py-4 text-left last:border-b-0 dark:border-cherry-800"
                        :class="group.is_system ? 'cursor-default' : 'hover:bg-primary-50 hover:bg-opacity-30 dark:hover:bg-cherry-800'"
                    >
                        <span class="flex items-center gap-2 font-medium text-gray-800 dark:text-white">
                            @{{ group.name }}
                            <span
                                v-if="group.is_system"
                                class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-cherry-800 dark:text-slate-300"
                            >
                                @lang('dam::app.admin.dam.groups.built-in-badge')
                            </span>
                            <span
                                v-else-if="group.read_only"
                                class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-cherry-800 dark:text-slate-300"
                            >
                                @lang('dam::app.admin.dam.groups.read-only-badge')
                            </span>
                        </span>
                        <span class="text-sm text-gray-500 dark:text-slate-400">@{{ memberCountLabel(group) }}</span>
                    </component>
                    <p v-if="!filteredGroups.length" class="mt-4 text-sm text-gray-500 dark:text-slate-400">
                        @lang('dam::app.admin.dam.groups.empty')
                    </p>
                </section>

                <form v-if="draft" @submit.prevent="save" class="rounded-lg border border-gray-200 bg-white p-4 dark:border-cherry-800 dark:bg-cherry-900">
                    <fieldset :disabled="busy">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">
                            @{{ draft.id ? @js(trans('dam::app.admin.dam.groups.edit-title')) : @js(trans('dam::app.admin.dam.groups.create-title')) }}
                        </h2>

                        <p v-if="draft.read_only" data-group-read-only-note class="mt-3 rounded bg-gray-50 p-3 text-sm text-gray-600 dark:bg-cherry-800 dark:text-slate-300">
                            @{{ draft.read_only_reason }}
                        </p>

                        <label for="group-name" class="mt-4 block text-sm font-medium text-gray-700 dark:text-slate-200">
                            @lang('dam::app.admin.dam.groups.name-label')
                        </label>
                        <input
                            ref="name"
                            id="group-name"
                            v-model="draft.name"
                            :disabled="draft.read_only"
                            required
                            maxlength="100"
                            class="mt-2 w-full rounded border border-gray-300 p-2 text-gray-700 dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200"
                            placeholder="@lang('dam::app.admin.dam.groups.name-placeholder')"
                        >

                        <label for="member-search" class="mt-4 block text-sm font-medium text-gray-700 dark:text-slate-200">
                            @lang('dam::app.admin.dam.groups.member-search-label') · @{{ draft.member_ids.length }}
                        </label>
                        <input
                            v-if="!draft.read_only"
                            id="member-search"
                            v-model="memberSearch"
                            placeholder="@lang('dam::app.admin.dam.groups.member-search-placeholder')"
                            class="mt-2 w-full rounded border border-gray-300 p-2 text-gray-700 dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200"
                        >
                        <div class="mt-2 max-h-72 overflow-y-auto">
                            <label
                                v-for="user in filteredUsers"
                                :key="user.id"
                                data-group-member
                                class="flex items-center gap-3 border-b border-gray-200 py-3 text-sm dark:border-cherry-800"
                                :class="draft.read_only ? 'opacity-70' : ''"
                            >
                                <input
                                    type="checkbox"
                                    :value="user.id"
                                    v-model="draft.member_ids"
                                    :disabled="draft.read_only"
                                >
                                <span class="text-gray-700 dark:text-slate-200">
                                    @{{ user.name }}
                                    <span
                                        v-if="!user.status"
                                        class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-cherry-800 dark:text-slate-300"
                                    >
                                        @lang('dam::app.admin.dam.groups.inactive-badge')
                                    </span>
                                    <span class="block text-xs text-gray-500 dark:text-slate-400">@{{ user.email }} · @{{ user.role_name }}</span>
                                </span>
                            </label>
                            <p v-if="!draft.read_only && !filteredUsers.length" class="py-3 text-sm text-gray-500 dark:text-slate-400">
                                @lang('dam::app.admin.dam.groups.no-matching-users')
                            </p>
                        </div>

                        <div v-if="draft.access_roots" data-group-access-roots class="mt-5">
                            <p class="text-sm font-medium text-gray-700 dark:text-slate-200">
                                @lang('dam::app.admin.dam.groups.access-roots-title')
                            </p>
                            <p v-if="!draft.access_roots.length" class="mt-2 text-sm text-gray-500 dark:text-slate-400">
                                @lang('dam::app.admin.dam.groups.access-roots-empty')
                            </p>
                            <ul v-else class="mt-2 space-y-1">
                                <li v-for="folder in draft.access_roots" :key="folder.id">
                                    <a
                                        :href="libraryUrl(folder.id)"
                                        data-group-access-root
                                        class="text-sm text-primary-700 hover:underline dark:text-primary-400"
                                        :title="folder.path"
                                    >
                                        @{{ folder.path }}
                                    </a>
                                </li>
                            </ul>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <button
                                v-if="!draft.read_only"
                                type="submit"
                                data-group-save
                                class="primary-button"
                                :aria-busy="busy"
                            >
                                @{{ busy ? @js(trans('dam::app.admin.dam.groups.saving')) : @js(trans('dam::app.admin.dam.groups.save')) }}
                            </button>
                            <button type="button" class="secondary-button" @click="draft = null">
                                @lang('dam::app.admin.dam.groups.cancel')
                            </button>
                            <button
                                v-if="draft.id && !draft.read_only"
                                type="button"
                                data-group-delete
                                class="text-sm text-red-600"
                                @click="remove"
                            >
                                @lang('dam::app.admin.dam.groups.delete')
                            </button>
                        </div>
                    </fieldset>
                </form>
                <p v-else class="p-4 text-sm text-gray-500 dark:text-slate-400">
                    @lang('dam::app.admin.dam.groups.select-prompt')
                </p>
            </div>
        </div>
    </script>
    <script type="module">
    app.component('v-access-groups', {
        template: '#v-access-groups-template',
        data() {
            return { groups: [], users: [], draft: null, search: '', memberSearch: '', initialMemberIds: [], loading: true, busy: false, error: '' };
        },
        computed: {
            filteredGroups() {
                const term = this.search.toLowerCase();
                const matches = this.groups.filter(group => group.name.toLowerCase().includes(term));

                // Everyone first, with a Built-in badge (spec, Groups).
                return [...matches.filter(group => group.is_system), ...matches.filter(group => !group.is_system)];
            },
            filteredUsers() {
                const term = this.memberSearch.toLowerCase();

                const matches = this.users.filter(user => (user.name + ' ' + user.email).toLowerCase().includes(term));

                // Members as the group was opened come first (stable, so ticking a box never reorders the list).
                return [...matches.filter(user => this.initialMemberIds.includes(user.id)), ...matches.filter(user => !this.initialMemberIds.includes(user.id))];
            },
        },
        mounted() {
            this.load();
        },
        methods: {
            base() {
                return @json(route('admin.settings.groups.index'));
            },
            libraryUrl(directoryId) {
                return @json(route('admin.dam.index')) + '?directory_id=' + directoryId + '&scope=directory';
            },
            memberCountLabel(group) {
                const count = group.member_count ?? 0;

                return (count === 1 ? @js(trans_choice('dam::app.admin.dam.access.members-count', 1, ['count' => ':count'])) : @js(trans_choice('dam::app.admin.dam.access.members-count', 2, ['count' => ':count']))).replace(':count', count);
            },
            async load() {
                this.loading = true;
                this.error = '';
                try {
                    const { data } = await this.$axios.get(this.base() + '/list');
                    this.groups = data.groups;
                    this.users = data.users;
                } catch (e) {
                    this.error = @js(trans('dam::app.admin.dam.groups.load-failed'));
                } finally {
                    this.loading = false;
                }
            },
            edit(group) {
                if (group?.is_system) return;

                this.draft = group
                    ? { ...JSON.parse(JSON.stringify(group)), read_only_reason: this.readOnlyReason(group) }
                    : { name: '', member_ids: [], read_only: false };
                this.memberSearch = '';
                this.initialMemberIds = [...(this.draft.member_ids || [])];
                this.$nextTick(() => this.$refs.name?.focus());
            },
            readOnlyReason(group) {
                return group.read_only ? @js(trans('dam::app.admin.dam.groups.role-group-read-only')) : '';
            },
            async save() {
                if (this.busy || this.draft.read_only) return;
                this.busy = true;
                try {
                    const payload = { name: this.draft.name.trim(), member_ids: this.draft.member_ids };
                    const { data } = this.draft.id
                        ? await this.$axios.put(this.base() + '/' + this.draft.id, payload)
                        : await this.$axios.post(this.base(), payload);
                    this.draft = null;
                    this.$emitter.emit('add-flash', { type: 'success', message: @js(trans('dam::app.admin.dam.groups.save-success')) });
                    await this.load();
                } catch (e) {
                    this.$emitter.emit('add-flash', {
                        type: 'error',
                        message: e.response?.data?.message || @js(trans('dam::app.admin.dam.groups.save-failed')),
                    });
                } finally {
                    this.busy = false;
                }
            },
            async remove() {
                if (this.busy || this.draft.read_only) return;
                if (! window.confirm(@js(trans('dam::app.admin.dam.groups.delete-confirm')).replace(':name', this.draft.name))) return;

                this.busy = true;
                try {
                    await this.$axios.delete(this.base() + '/' + this.draft.id);
                    this.draft = null;
                    this.$emitter.emit('add-flash', { type: 'success', message: @js(trans('dam::app.admin.dam.groups.delete-success')) });
                    await this.load();
                } catch (e) {
                    this.$emitter.emit('add-flash', {
                        type: 'error',
                        message: e.response?.data?.message || @js(trans('dam::app.admin.dam.groups.delete-failed')),
                    });
                } finally {
                    this.busy = false;
                }
            },
        },
    });
    </script>
    @endPushOnce
</x-admin::layouts>
