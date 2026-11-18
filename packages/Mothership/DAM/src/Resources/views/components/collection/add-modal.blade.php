{{--
    P4 D2: "Add to collection", from a Library multi-select and from an asset's page. Lists the collections the user
    manages (or makes a new one) and adds the chosen files in order. The server decides: a file the user cannot see
    answers 404, one they may not redistribute 403, and then nothing is added.
    Open with: $emitter.emit('dam:open-collection-add-modal', { assetIds: [..], context })
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-collection-add-modal-template">
        <x-admin::modal ref="collectionAddModal">
            <x-slot:header>
                <p class="text-lg font-bold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.collections.add.title')</p>
            </x-slot>

            <x-slot:content>
                <form class="flex flex-col gap-3" @submit.prevent="submit" data-collection-add-form>
                    <p class="text-sm text-gray-600 dark:text-slate-300">@{{ subtitle }}</p>

                    <p v-if="loading" class="text-sm text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.add.loading')</p>

                    <fieldset v-else class="min-w-0">
                        <legend class="mb-1 text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.admin.dam.collections.add.choose')</legend>
                        <div class="max-h-64 overflow-y-auto rounded border border-gray-200 dark:border-cherry-700">
                            <label
                                v-for="collection in collections"
                                :key="collection.id"
                                class="flex cursor-pointer items-center gap-3 border-b border-gray-100 px-3 py-2 text-sm last:border-b-0 hover:bg-gray-50 dark:border-cherry-800 dark:hover:bg-cherry-800"
                                :data-collection-add-option="collection.id"
                            >
                                <input type="radio" name="collection-add-target" :value="collection.id" v-model="target">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-gray-800 dark:text-white">@{{ collection.name }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-slate-400">@{{ describe(collection) }}</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-cherry-800" data-collection-add-option="new">
                                <input type="radio" name="collection-add-target" value="new" v-model="target">
                                <span class="text-gray-800 dark:text-white">@lang('dam::app.admin.dam.collections.add.new')</span>
                            </label>
                        </div>
                    </fieldset>

                    <div v-if="target === 'new'">
                        <label for="collection-add-new-name" class="block text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.admin.dam.collections.form.name')</label>
                        <input id="collection-add-new-name" ref="newName" v-model="newName" maxlength="191" class="mt-1 w-full rounded border border-gray-300 p-2 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200">
                        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.add.new-help')</p>
                    </div>

                    <p v-if="error" class="text-sm text-red-600" role="alert">@{{ error }}</p>

                    <div class="mt-1 flex items-center justify-end gap-2">
                        <button type="button" class="secondary-button" @click="close">@lang('dam::app.admin.dam.collections.form.cancel')</button>
                        <button type="submit" class="primary-button" :disabled="busy || loading || ! target || (target === 'new' && ! newName.trim())" data-collection-add-submit>
                            @{{ busy ? @js(trans('dam::app.admin.dam.collections.add.adding')) : @js(trans('dam::app.admin.dam.collections.add.submit')) }}
                        </button>
                    </div>
                </form>
            </x-slot>
        </x-admin::modal>
    </script>

    <script type="text/x-template" id="v-dam-add-to-collection-button-template">
        <button type="button" class="transparent-button" data-add-to-collection @click="open">
            <span class="icon-catalog text-xl" style="color: inherit;" aria-hidden="true"></span>
            <span>@lang('dam::app.admin.dam.collections.add.action')</span>
        </button>
    </script>

    <script type="module">
        app.component('v-dam-collection-add-modal', {
            template: '#v-dam-collection-add-modal-template',

            data() {
                return { assetIds: [], context: null, collections: [], target: null, newName: '', loading: false, busy: false, error: '' };
            },

            computed: {
                subtitle() {
                    const count = this.assetIds.length;

                    return (count === 1 ? @js(trans_choice('dam::app.admin.dam.collections.add.subtitle', 1, ['count' => ':count'])) : @js(trans_choice('dam::app.admin.dam.collections.add.subtitle', 2, ['count' => ':count']))).replace(':count', count);
                },
            },

            mounted() {
                this.$emitter.on('dam:open-collection-add-modal', ({ assetIds, context }) => {
                    this.assetIds = Array.isArray(assetIds) ? assetIds.map(Number).filter(Boolean) : [];
                    this.context = context ?? null;
                    this.error = '';
                    this.newName = '';

                    if (! this.assetIds.length) {
                        this.$emitter.emit('add-flash', { type: 'warning', message: @js(trans('dam::app.admin.dam.collections.add.no-items')) });
                        return;
                    }

                    this.load();
                    this.$refs.collectionAddModal.toggle();
                });
            },

            methods: {
                async load() {
                    this.loading = true;
                    try {
                        const { data } = await this.$axios.get(@json(route('admin.dam.collections.list')));
                        this.collections = data.managed.filter(c => c.can_manage);
                        // Never someone else's collection: the one used last, else one the viewer owns, else a new one.
                        const userId = window.MothershipLibraryNavigation?.userId ?? null;
                        let last = null;
                        try { last = Number(localStorage.getItem('dam.collection-add.last.' + userId)) || null; } catch (e) {}
                        const preferred = this.collections.find(c => c.id === last) ?? this.collections.find(c => userId !== null && c.owner?.id === userId);
                        this.target = preferred ? preferred.id : 'new';
                    } catch (e) {
                        this.collections = [];
                        this.target = 'new';
                    } finally {
                        this.loading = false;
                        if (this.target === 'new') this.$nextTick(() => this.$refs.newName?.focus());
                    }
                },
                describe(collection) {
                    const count = (collection.member_count === 1 ? @js(trans_choice('dam::app.admin.dam.collections.files-count', 1, ['count' => ':count'])) : @js(trans_choice('dam::app.admin.dam.collections.files-count', 2, ['count' => ':count']))).replace(':count', collection.member_count);
                    const audience = collection.audience === 'public' ? @js(trans('dam::app.admin.dam.collections.audience.public')) : @js(trans('dam::app.admin.dam.collections.audience.internal'));

                    return audience + ' · ' + count;
                },
                close() {
                    this.$refs.collectionAddModal.toggle();
                },
                async submit() {
                    if (this.busy) return;
                    this.busy = true;
                    this.error = '';
                    try {
                        let collectionId = this.target;
                        if (this.target === 'new') {
                            const { data } = await this.$axios.post(@json(route('admin.dam.collections.store')), { name: this.newName.trim() });
                            collectionId = data.collection.id;
                        }
                        const { data } = await this.$axios.post(@json(route('admin.dam.collections.members.store', ['id' => '__ID__'])).replace('__ID__', collectionId), { asset_ids: this.assetIds });
                        try { localStorage.setItem('dam.collection-add.last.' + (window.MothershipLibraryNavigation?.userId ?? null), String(collectionId)); } catch (e) {}
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        this.$emitter.emit('dam:collection-add:done', { context: this.context, collectionId });
                        this.close();
                    } catch (e) {
                        this.error = e.response?.data?.message || @js(trans('dam::app.admin.dam.collections.action-failed'));
                    } finally {
                        this.busy = false;
                    }
                },
            },
        });

        app.component('v-dam-add-to-collection-button', {
            template: '#v-dam-add-to-collection-button-template',
            props: { assetId: { type: Number, required: true } },
            data() {
                return { currentAssetId: this.assetId, onAssetChange: null };
            },
            mounted() {
                // The asset page's ‹ › navigation swaps the asset in place (dam-asset-changed), as the Share button tracks it.
                this.onAssetChange = (data) => { if (data?.asset?.id) this.currentAssetId = data.asset.id; };
                this.$emitter.on('dam-asset-changed', this.onAssetChange);
            },
            unmounted() {
                if (this.onAssetChange) this.$emitter.off('dam-asset-changed', this.onAssetChange);
            },
            methods: {
                open() {
                    this.$emitter.emit('dam:open-collection-add-modal', { assetIds: [this.currentAssetId], context: 'asset' });
                },
            },
        });
    </script>
@endPushOnce

@once('dam-collection-add-modal')
    <v-dam-collection-add-modal></v-dam-collection-add-modal>
@endonce
