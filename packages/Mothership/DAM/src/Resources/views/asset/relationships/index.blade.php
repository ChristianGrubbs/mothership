@if (bouncer()->hasPermission('dam.asset.relationships.store'))
    @include('dam::asset.picker-modal')

    <v-dam-asset-picker></v-dam-asset-picker>
@endif

<v-dam-asset-relationships
    :initial-asset-id="{{ $assetId }}"
    :can-create="@js(bouncer()->hasPermission('dam.asset.relationships.store'))"
    :can-delete="@js(bouncer()->hasPermission('dam.asset.relationships.destroy'))"
></v-dam-asset-relationships>

@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-asset-relationships-template">
        <div class="flex flex-col gap-5 mt-3.5">
            <div
                v-if="canCreate"
                class="flex flex-col gap-4 rounded-lg bg-white p-5 box-shadow dark:bg-cherry-900"
            >
                <div>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white">
                        @lang('dam::app.admin.dam.asset.relationships.add-title')
                    </h2>

                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @lang('dam::app.admin.dam.asset.relationships.add-description')
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-800 dark:text-white">
                            @lang('dam::app.admin.dam.asset.relationships.type')
                        </label>

                        <select
                            v-model="selectedType"
                            class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-cherry-800 dark:text-gray-200"
                        >
                            <option v-for="type in types" :key="type" :value="type">@{{ type }}</option>
                            <option value="__custom">@lang('dam::app.admin.dam.asset.relationships.custom-type')</option>
                        </select>

                        <input
                            v-if="selectedType === '__custom'"
                            v-model.trim="customType"
                            type="text"
                            maxlength="64"
                            class="mt-2 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-cherry-800 dark:text-gray-200"
                            placeholder="@lang('dam::app.admin.dam.asset.relationships.custom-type-placeholder')"
                        />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-800 dark:text-white">
                            @lang('dam::app.admin.dam.asset.relationships.target')
                        </label>

                        <button
                            type="button"
                            class="secondary-button w-full justify-center"
                            @click="openPicker"
                        >
                            <span v-if="selectedAsset">@{{ selectedAsset.display_name || selectedAsset.title || selectedAsset.file_name }}</span>
                            <span v-else>@lang('dam::app.admin.dam.asset.relationships.choose-asset')</span>
                        </button>
                    </div>
                </div>

                <p v-if="error" class="text-sm text-red-600 dark:text-red-400">@{{ error }}</p>

                <div class="flex justify-end">
                    <button
                        type="button"
                        class="primary-button"
                        :disabled="saving || ! selectedAsset || ! relationshipType"
                        @click="addRelationship"
                    >
                        <span v-if="saving">@lang('dam::app.admin.dam.asset.relationships.adding')</span>
                        <span v-else>@lang('dam::app.admin.dam.asset.relationships.add')</span>
                    </button>
                </div>
            </div>

            <div class="rounded-lg bg-white p-5 box-shadow dark:bg-cherry-900">
                <div v-if="loading" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                    @lang('dam::app.admin.dam.asset.relationships.loading')
                </div>

                <div v-else-if="! sections.length" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                    @lang('dam::app.admin.dam.asset.relationships.empty')
                </div>

                <div v-else class="flex flex-col gap-6">
                    <section v-for="section in sections" :key="section.direction">
                        <h2 class="mb-3 text-base font-semibold text-gray-800 dark:text-white">
                            @{{ section.label }}
                        </h2>

                        <div class="flex flex-col gap-4">
                            <div v-for="group in section.groups" :key="`${section.direction}-${group.type}`">
                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    @{{ group.type }}
                                </div>

                                <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700">
                                    <table class="w-full text-left text-sm">
                                        <tbody>
                                            <tr
                                                v-for="relationship in group.relationships"
                                                :key="relationship.relationship_id"
                                                class="border-b border-gray-200 last:border-b-0 dark:border-gray-700"
                                            >
                                                <td class="w-16 p-3">
                                                    <a :href="relationship.asset.url" class="block" data-dam-relationship-link>
                                                        <img
                                                            :src="relationship.asset.thumbnail_url"
                                                            :alt="relationship.asset.display_name || relationship.asset.title || relationship.asset.file_name"
                                                            class="h-12 w-12 rounded-md object-cover"
                                                            loading="lazy"
                                                        />
                                                    </a>
                                                </td>

                                                <td class="min-w-0 p-3">
                                                    <a
                                                        :href="relationship.asset.url"
                                                        class="block truncate text-sm font-medium text-gray-800 hover:text-primary-700 hover:underline dark:text-white dark:hover:text-primary-300"
                                                        data-dam-relationship-link
                                                    >
                                                        @{{ relationship.asset.display_name || relationship.asset.title || relationship.asset.file_name }}
                                                    </a>

                                                    <p
                                                        v-if="relationship.asset.title"
                                                        class="truncate text-xs text-gray-500 dark:text-gray-400"
                                                    >
                                                        @{{ relationship.asset.file_name }}
                                                    </p>
                                                </td>

                                                <td class="w-px whitespace-nowrap p-3">
                                                    <span
                                                        class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                                                        :title="section.label"
                                                    >
                                                        <span aria-hidden="true">@{{ section.direction === 'outgoing' ? '→' : '←' }}</span>
                                                        @{{ section.direction }}
                                                    </span>
                                                </td>

                                                <td class="w-px whitespace-nowrap p-3 text-right">
                                                    <a
                                                        :href="relationship.asset.url"
                                                        class="secondary-button inline-flex items-center px-2.5 py-1 text-xs"
                                                        data-dam-relationship-open
                                                    >@lang('dam::app.admin.dam.asset.relationships.open')</a>
                                                </td>

                                                <td v-if="canDelete" class="w-12 p-3 text-right">
                                                    <button
                                                        type="button"
                                                        class="icon-delete rounded-md p-2 text-xl text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                                                        title="@lang('dam::app.admin.dam.asset.relationships.remove')"
                                                        aria-label="@lang('dam::app.admin.dam.asset.relationships.remove')"
                                                        :disabled="deletingId === relationship.relationship_id"
                                                        @click="removeRelationship(relationship.relationship_id)"
                                                    ></button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-dam-asset-relationships', {
            template: '#v-dam-asset-relationships-template',

            props: {
                initialAssetId: { type: Number, required: true },
                canCreate:      { type: Boolean, default: false },
                canDelete:      { type: Boolean, default: false },
            },

            data() {
                return {
                    assetId:       this.initialAssetId,
                    outgoing:      {},
                    incoming:      {},
                    types:         @json(\Mothership\DAM\Models\AssetRelationship::TYPES),
                    selectedType:  @js(\Mothership\DAM\Models\AssetRelationship::TYPE_RELATED_TO),
                    customType:    '',
                    selectedAsset: null,
                    loading:       true,
                    saving:        false,
                    deletingId:    null,
                    error:         '',
                };
            },

            computed: {
                relationshipType() {
                    return this.selectedType === '__custom' ? this.customType.trim() : this.selectedType;
                },

                sections() {
                    return [
                        {
                            direction: 'outgoing',
                            label: @js(trans('dam::app.admin.dam.asset.relationships.outgoing')),
                            groups: this.toGroups(this.outgoing),
                        },
                        {
                            direction: 'incoming',
                            label: @js(trans('dam::app.admin.dam.asset.relationships.incoming')),
                            groups: this.toGroups(this.incoming),
                        },
                    ].filter(section => section.groups.length);
                },
            },

            mounted() {
                this.loadRelationships();

                this._onAssetChange = ({ asset } = {}) => {
                    if (! asset?.id) return;

                    this.assetId = Number(asset.id);
                    this.selectedAsset = null;
                    this.error = '';
                    this.loadRelationships();
                };

                this.$emitter.on('dam-asset-changed', this._onAssetChange);
            },

            beforeUnmount() {
                if (this._onAssetChange) {
                    this.$emitter.off('dam-asset-changed', this._onAssetChange);
                }
            },

            methods: {
                toGroups(grouped) {
                    return Object.entries(grouped || {}).map(([type, relationships]) => ({
                        type,
                        relationships,
                    }));
                },

                relationshipsUrl() {
                    return @js(route('admin.dam.assets.relationships.index', ['asset' => '__ASSET__']))
                        .replace('__ASSET__', this.assetId);
                },

                destroyUrl(relationshipId) {
                    return @js(route('admin.dam.assets.relationships.destroy', [
                        'asset'        => '__ASSET__',
                        'relationship' => '__RELATIONSHIP__',
                    ]))
                        .replace('__ASSET__', this.assetId)
                        .replace('__RELATIONSHIP__', relationshipId);
                },

                async loadRelationships() {
                    this.loading = true;

                    try {
                        const { data } = await this.$axios.get(this.relationshipsUrl());
                        this.outgoing = data.outgoing || {};
                        this.incoming = data.incoming || {};
                        this.types = data.types || this.types;
                    } catch (error) {
                        this.error = error.response?.data?.message
                            || @js(trans('dam::app.admin.dam.asset.relationships.load-failed'));
                    } finally {
                        this.loading = false;
                    }
                },

                openPicker() {
                    this.$emitter.emit('dam-asset-picker:open', {
                        ids: [],
                        onAssign: ids => this.selectAsset(ids),
                    });
                },

                async selectAsset(ids) {
                    const targetId = (ids || []).find(id => Number(id) !== this.assetId);

                    if (! targetId) {
                        this.selectedAsset = null;
                        this.error = @js(trans('dam::app.admin.dam.asset.relationships.choose-other-asset'));

                        return;
                    }

                    try {
                        const { data } = await this.$axios.get(
                            @js(route('admin.dam.asset_picker.get_assets')),
                            { params: { assetIds: String(targetId) } }
                        );

                        this.selectedAsset = data[0] || null;
                        this.error = this.selectedAsset ? '' : @js(trans('dam::app.admin.dam.asset.relationships.asset-not-found'));
                    } catch (error) {
                        this.error = error.response?.data?.message
                            || @js(trans('dam::app.admin.dam.asset.relationships.asset-not-found'));
                    }
                },

                async addRelationship() {
                    if (! this.selectedAsset || ! this.relationshipType || this.saving) return;

                    this.saving = true;
                    this.error = '';

                    try {
                        await this.$axios.post(this.relationshipsUrl(), {
                            to_asset_id: this.selectedAsset.id,
                            type: this.relationshipType,
                        });

                        this.selectedAsset = null;
                        this.customType = '';
                        this.$emitter.emit('dam-badge-delta', { tab: 'relationships', delta: 1 });
                        await this.loadRelationships();
                    } catch (error) {
                        const errors = error.response?.data?.errors || {};
                        this.error = Object.values(errors).flat()[0]
                            || error.response?.data?.message
                            || @js(trans('dam::app.admin.dam.asset.relationships.add-failed'));
                    } finally {
                        this.saving = false;
                    }
                },

                async removeRelationship(relationshipId) {
                    if (this.deletingId !== null) return;

                    this.deletingId = relationshipId;
                    this.error = '';

                    try {
                        await this.$axios.delete(this.destroyUrl(relationshipId));
                        this.$emitter.emit('dam-badge-delta', { tab: 'relationships', delta: -1 });
                        await this.loadRelationships();
                    } catch (error) {
                        this.error = error.response?.data?.message
                            || @js(trans('dam::app.admin.dam.asset.relationships.remove-failed'));
                    } finally {
                        this.deletingId = null;
                    }
                },
            },
        });
    </script>
@endPushOnce
