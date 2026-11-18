<x-admin::layouts>
    <x-slot:title>@lang('dam::app.admin.dam.collections.title')</x-slot>

    <v-dam-collections></v-dam-collections>

    @include('dam::collections.form')

    @pushOnce('scripts')
    <script type="text/x-template" id="v-dam-collections-template">
        <div class="mx-auto max-w-6xl pb-10">
            <x-admin::breadcrumbs :leaf="trans('dam::app.admin.dam.collections.title')" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.collections.title')</h1>
                    <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.description')</p>
                </div>
                <button v-if="can.create" type="button" class="primary-button shrink-0 whitespace-nowrap" data-collection-new :disabled="loading" @click="creating = true">
                    @lang('dam::app.admin.dam.collections.create')
                </button>
            </div>

            <div v-if="creating" class="mt-6">
                <v-dam-collection-form @saved="onCreated" @cancel="creating = false"></v-dam-collection-form>
            </div>

            <div class="mt-6 flex gap-1 border-b border-gray-200 dark:border-cherry-800" role="tablist" :aria-label="@js(trans('dam::app.admin.dam.collections.title'))">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    :id="'collections-tab-' + tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="active === tab.key ? 'true' : 'false'"
                    :aria-controls="'collections-panel-' + tab.key"
                    :tabindex="active === tab.key ? 0 : -1"
                    :data-collections-tab="tab.key"
                    class="-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium"
                    :class="active === tab.key ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-300' : 'border-transparent text-gray-600 hover:text-gray-800 dark:text-slate-400 dark:hover:text-white'"
                    @click="select(tab.key)"
                    @keydown.right.prevent="step(1)"
                    @keydown.left.prevent="step(-1)"
                >
                    @{{ tab.label }}
                    <span v-if="! loading && ! error" class="ml-1 rounded-full bg-gray-100 px-2 text-xs text-gray-600 dark:bg-cherry-800 dark:text-slate-300">@{{ lists[tab.key].length }}</span>
                </button>
            </div>

            <div v-if="loading" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-collections-loading>
                <div v-for="n in 3" :key="n" class="h-32 animate-pulse rounded-lg bg-gray-100 dark:bg-cherry-500"></div>
            </div>

            <x-admin::load-error
                v-else-if="error"
                class="mt-6"
                :title="trans('dam::app.admin.dam.collections.load-failed')"
                message="error.message"
                retryable="error.retryable"
                retry="load()"
            />

            <section
                v-else
                :id="'collections-panel-' + active"
                role="tabpanel"
                :aria-labelledby="'collections-tab-' + active"
                class="mt-6"
                :data-collections-panel="active"
            >
                <p v-if="! lists[active].length" class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-cherry-700 dark:text-slate-400">
                    @{{ active === 'shared' ? @js(trans('dam::app.admin.dam.collections.empty-shared')) : (can.create ? @js(trans('dam::app.admin.dam.collections.empty-managed')) : @js(trans('dam::app.admin.dam.collections.empty-managed-no-create'))) }}
                </p>

                <ul v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="collection in lists[active]" :key="collection.id" class="min-w-0">
                        <a
                            :href="collection.url"
                            :data-collection-card="collection.id"
                            class="flex h-full flex-col rounded-lg border border-gray-200 bg-white p-4 transition hover:border-primary-400 hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600 dark:border-cherry-800 dark:bg-cherry-900"
                        >
                            <span class="flex items-start justify-between gap-2">
                                <span class="min-w-0 break-words [overflow-wrap:anywhere] text-base font-semibold text-gray-800 dark:text-white">@{{ collection.name }}</span>
                                <span class="icon-catalog shrink-0 text-2xl text-gray-400" aria-hidden="true"></span>
                            </span>
                            <span v-if="collection.description" class="mt-1 line-clamp-2 text-sm text-gray-500 dark:text-slate-400">@{{ collection.description }}</span>
                            <span class="mt-auto flex flex-wrap items-center gap-2 pt-3 text-xs">
                                <span class="rounded-full px-2 py-0.5 font-semibold" :class="collection.audience === 'public' ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200' : 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-200'">
                                    @{{ audienceLabel(collection.audience) }}
                                </span>
                                <span v-if="collection.state !== 'active'" class="rounded-full bg-gray-200 px-2 py-0.5 font-semibold text-gray-700 dark:bg-cherry-800 dark:text-slate-300">@{{ stateLabel(collection.state) }}</span>
                                <span class="text-gray-600 dark:text-slate-300">@{{ countLabel(collection.can_manage && collection.state !== 'active' ? collection.total_members : collection.member_count) }}</span>
                                <span v-if="collection.expires_at" class="text-gray-500 dark:text-slate-400">· @{{ expiresLabel(collection) }}</span>
                            </span>
                            <span v-if="active === 'shared' && collection.owner" class="mt-1 text-xs text-gray-500 dark:text-slate-400">@{{ ownerLabel(collection.owner.name) }}</span>
                        </a>
                    </li>
                </ul>
            </section>
        </div>
    </script>

    <script type="module">
    app.component('v-dam-collections', {
        template: '#v-dam-collections-template',
        data() {
            const tab = new URLSearchParams(window.location.search).get('tab');

            return {
                active: tab === 'shared' ? 'shared' : 'managed',
                lists: { managed: [], shared: [] },
                can: { create: false },
                loading: true,
                // null, or the shared description of the failed request (message, retryable).
                error: null,
                creating: false,
                tabs: [
                    { key: 'managed', label: @js(trans('dam::app.admin.dam.collections.tabs.managed')) },
                    { key: 'shared', label: @js(trans('dam::app.admin.dam.collections.tabs.shared')) },
                ],
            };
        },
        mounted() {
            this.load();
        },
        methods: {
            async load() {
                this.loading = true;
                this.error = null;
                try {
                    const { data } = await this.$axios.get(@json(route('admin.dam.collections.list')));
                    this.lists = { managed: data.managed, shared: data.shared };
                    this.can = data.can;
                } catch (e) {
                    this.error = (globalThis.mothership?.requestError?.(e) ?? { message: e?.response?.data?.message ?? '', retryable: true });
                } finally {
                    this.loading = false;
                }
            },
            select(key) {
                this.active = key;
                const url = new URL(window.location.href);
                key === 'shared' ? url.searchParams.set('tab', 'shared') : url.searchParams.delete('tab');
                window.history.replaceState(null, '', url);
            },
            step(delta) {
                const keys = this.tabs.map(t => t.key);
                const next = keys[(keys.indexOf(this.active) + delta + keys.length) % keys.length];
                this.select(next);
                this.$nextTick(() => document.getElementById('collections-tab-' + next)?.focus());
            },
            onCreated(collection) {
                window.location.href = collection.url;
            },
            audienceLabel(audience) {
                return audience === 'public' ? @js(trans('dam::app.admin.dam.collections.audience.public')) : @js(trans('dam::app.admin.dam.collections.audience.internal'));
            },
            stateLabel(state) {
                return ({ expired: @js(trans('dam::app.admin.dam.collections.state.expired')), archived: @js(trans('dam::app.admin.dam.collections.state.archived')) })[state] ?? state;
            },
            countLabel(count) {
                return (count === 1 ? @js(trans_choice('dam::app.admin.dam.collections.files-count', 1, ['count' => ':count'])) : @js(trans_choice('dam::app.admin.dam.collections.files-count', 2, ['count' => ':count']))).replace(':count', count);
            },
            expiresLabel(collection) {
                const date = new Date(collection.expires_at).toLocaleDateString();

                return (collection.state === 'expired' ? @js(trans('dam::app.admin.dam.collections.expired-on')) : @js(trans('dam::app.admin.dam.collections.expires-on'))).replace(':date', date);
            },
            ownerLabel(name) {
                return @js(trans('dam::app.admin.dam.collections.shared-by')).replace(':name', name);
            },
        },
    });
    </script>
    @endPushOnce
</x-admin::layouts>
