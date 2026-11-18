<v-meta-info :asset-ids="{{ $asset->id }}">
</v-meta-info>
@pushOnce('scripts')
    <script type="text/x-template" id="v-meta-info-template">
        <div>
            {!! view_render_event('mothership.dam.asset.edit.card.accordian.embedded_meta_info.before', ['asset' => $asset]) !!}
            <x-admin::accordion :isActive="true">
                <x-slot:header>
                    <p class="p-2.5 text-gray-800 dark:text-white text-base font-semibold">
                        @lang('dam::app.admin.dam.asset.edit.embedded_meta_info')
                    </p>
                </x-slot>
                <x-slot:content class="overflow-x-auto max-w-full !px-0 !pb-0">
                    <div class="flex flex-col gap-2.5 mt-3.5">
                        <p v-if="selectedName" class="w-full px-4 break-words" data-dam-metadata-member>@{{ selectedName }}</p>
                        <div class="table-responsive grid w-full box-shadow rounded bg-white dark:bg-cherry-900 overflow-x-auto">
                            <div v-if="loading">
                                <x-admin::table class="w-full text-base text-gray-800 dark:text-gray-200">
                                    <x-admin::table.thead class="border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 bg-primary-50 dark:bg-cherry-900">
                                        <x-admin::table.thead.tr>
                                            <x-admin::table.th class="py-2">@lang('dam::app.admin.dam.asset.edit.name')</x-admin::table.th>
                                            <x-admin::table.th class="py-2">@lang('dam::app.admin.dam.asset.edit.value')</x-admin::table.th>
                                        </x-admin::table.thead.tr>
                                    </x-admin::table.thead>
                                </x-admin::table>
                            </div>

                            <p v-else-if="error" class="text-red-600 p-4">@{{ error }}</p>

                            <div
                                v-else-if="! Object.keys(metadata).length"
                                class="py-6 text-center text-gray-600 dark:text-gray-300"
                            >
                                @lang('dam::app.admin.dam.asset.edit.no-metadata-available')
                            </div>

                            {{-- VIS2-01: the stock table is min-w-[800px]; under sm the rows stack (name above value) so both are always visible. --}}
                            <x-admin::table v-else class="w-full !min-w-0 max-sm:block text-base text-gray-800 dark:text-gray-200">
                                <x-admin::table.thead class="border-b text-gray-600 dark:text-gray-300 bg-primary-50 dark:bg-cherry-900 max-sm:hidden">
                                    <x-admin::table.thead.tr>
                                        <x-admin::table.th class="py-2">@lang('dam::app.admin.dam.asset.edit.name')</x-admin::table.th>
                                        <x-admin::table.th class="py-2">@lang('dam::app.admin.dam.asset.edit.value')</x-admin::table.th>
                                    </x-admin::table.thead.tr>
                                </x-admin::table.thead>

                                <template v-for="(value, name) in metadata">
                                    <x-admin::table.thead.tr class="hover:bg-primary-50 dark:hover:bg-cherry-800 transition max-sm:block max-sm:border-b dark:max-sm:border-gray-800" ::key="name">
                                        <x-admin::table.td class="text-gray-800 dark:text-white max-sm:block max-sm:pb-0 max-sm:font-semibold max-sm:whitespace-pre-wrap max-sm:break-words">@{{ name }}</x-admin::table.td>
                                        <x-admin::table.td class="text-gray-700 dark:text-white whitespace-pre-wrap break-words max-sm:block max-sm:pt-1">
                                            <template v-if="isObjectOrArrayChange(value)">
                                                <x-admin::table class="w-full !min-w-0">
                                                    <template v-for="(v, k) in value">
                                                        <x-admin::table.thead.tr class="hover:bg-primary-50 dark:hover:bg-cherry-800" ::key="k">
                                                            <x-admin::table.td class="p-2 text-gray-800 dark:text-white whitespace-pre-wrap break-words">@{{ k }}</x-admin::table.td>
                                                            <x-admin::table.td class="p-2 text-gray-800 dark:text-white whitespace-pre-wrap break-words">
                                                                <pre v-if="isObjectOrArrayChange(v)" class="whitespace-pre-wrap break-words text-sm">@{{ JSON.stringify(v, null, 2) }}</pre>
                                                                <span v-else>@{{ v }}</span>
                                                            </x-admin::table.td>
                                                        </x-admin::table.thead.tr>
                                                    </template>
                                                </x-admin::table>
                                            </template>

                                            <template v-else-if="name === 'FileDateTime'">
                                                <span v-if="value !== 0">@{{ new Date(value * 1000).toLocaleString() }}</span>
                                                <span v-else-if="metadata['IFD0'] && metadata['IFD0']['DateTime']">@{{ formatExifDate(metadata['IFD0']['DateTime']) }}</span>
                                                <span v-else>@{{ value }}</span>
                                            </template>

                                            <template v-else>
                                                @{{ value }}
                                            </template>
                                        </x-admin::table.td>
                                    </x-admin::table.thead.tr>
                                </template>
                            </x-admin::table>
                        </div>
                    </div>
                </x-slot>
            </x-admin::accordion>

            {!! view_render_event('mothership.dam.asset.edit.card.accordian.embedded_meta_info.after', ['asset' => $asset]) !!}
        </div>
    </script>
    <script type="module">
        app.component('v-meta-info', {
            template: '#v-meta-info-template',
            props: {
                assetIds: {
                    type: [Number, String],
                    required: true
                }
            },
            data() {
                return {
                    loading: true,
                    metadata: {},
                    error: null,
                    selectedName: null,
                    requestGeneration: 0,
                };
            },
            mounted() {
                this.onMemberChanged = ({ assetId } = {}) => {
                    if (Number(assetId) === Number(this.assetIds)) this.fetchMetadataById();
                };
                this.$emitter.on('dam:stack-file-shown', this.onMemberChanged);
                this.fetchMetadataById();
            },
            beforeUnmount() {
                this.requestGeneration++;
                this.$emitter.off('dam:stack-file-shown', this.onMemberChanged);
            },
            methods: {
                fetchMetadataById() {
                    const generation = ++this.requestGeneration;
                    const fileId = new URL(window.location.href).searchParams.get('file');
                    this.loading = true;
                    this.metadata = {};
                    this.error = null;
                    this.selectedName = null;
                    let url = `{{ route('admin.dam.assets.metadata', ['id' => '__ID__']) }}`.replace('__ID__', this
                        .assetIds);
                    if (fileId !== null) url += `?file=${encodeURIComponent(fileId)}`;
                    this.$axios.get(url)
                        .then(resp => {
                            if (generation !== this.requestGeneration) return;
                            if (resp.data.success === false) throw new Error(resp.data.message);
                            this.metadata = resp.data.data || {};
                            this.selectedName = resp.data.file?.name || null;
                        })
                        .catch(e => {
                            if (generation !== this.requestGeneration) return;
                            this.error = e.response?.data?.message || e.message;
                        })
                        .finally(() => {
                            if (generation === this.requestGeneration) this.loading = false;
                        });
                },
                isObjectOrArrayChange(val) {
                    return val && typeof val === 'object';
                },
                formatExifDate(dateStr) {
                    if (!dateStr) return '';
                    return dateStr.replace(/^(\d{4}):(\d{2}):(\d{2})/, '$1-$2-$3');
                },
            }
        });
    </script>
@endPushOnce
