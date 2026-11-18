@props(['isMultiRow' => false])

@include('dam::components.shared.asset-card')

<v-gallery-table>
    {{ $slot }}
</v-gallery-table>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-gallery-table-template"
    >
        <div class="w-full">
            <slot name="body-header">
                <div
                    class="flex flex-row gap-2 items-center pb-4"
                    v-if="$parent.available.records.length && $parent.available.massActions.length"
                >
                    <label
                        for="mass_action_select_all_records"
                        class="flex items-center gap-2 cursor-pointer"
                    >
                        <input
                            type="checkbox"
                            name="mass_action_select_all_records"
                            id="mass_action_select_all_records"
                            class="peer sr-only"
                            aria-label="@lang('admin::app.components.datagrid.table.select-all')"
                            :checked="$parent.applied.massActions.meta.mode === 'all'"
                            :indeterminate="$parent.applied.massActions.meta.mode === 'partial'"
                            @change="$parent.selectAllRecords(); $event.target.checked = $parent.applied.massActions.meta.mode === 'all'"
                        >

                        <span
                            class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 cursor-pointer rounded-md text-2xl"
                            :class="{
                                'peer-checked:icon-checkbox-check peer-checked:text-primary-700': $parent.applied.massActions.meta.mode === 'all',
                                'peer-indeterminate:icon-checkbox-partial peer-indeterminate:text-primary-700': $parent.applied.massActions.meta.mode === 'partial',
                            }"
                        ></span>

                        <span class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                            @lang('dam::app.admin.dam.index.select-all')
                        </span>
                    </label>
                </div>
            </slot>

            <div
                class="grid grid-cols-2 md:!grid-cols-3 xl:!grid-cols-4 2xl:!grid-cols-5 gap-4"
                v-if="$parent.available.records.length"
            >
                <slot name="body">
                    <template v-if="$parent.isLoading">
                        <x-admin::shimmer.datagrid.table.body :isMultiRow="$isMultiRow" />
                    </template>

                    <template v-else>
                        <div
                            v-for="record in $parent.available.records"
                            :key="record.id"
                        >
                            {{--
                                isolate keeps the card's own badge (z-10) and favorite button (z-20)
                                inside the card, so the next card's badge cannot paint over an open
                                kebab menu (absolute z-10) that hangs down from the caption row.
                            --}}
                            <v-dam-asset-card
                                class="isolate"
                                :asset="record"
                                :draggable="true"
                                @dragstart="onAssetDragStart($event, record)"
                                @preview="previewImage(record.id)"
                                @edit="editImage(record.id)"
                                @delete="deleteImage(record.id)"
                            ></v-dam-asset-card>

                            <div class="flex gap-1.5 items-center mt-2">
                                <label
                                    v-if="$parent.available.massActions.length"
                                    :for="`mass_action_select_record_${record[$parent.available.meta.primary_column]}`"
                                    class="flex items-center cursor-pointer shrink-0"
                                >
                                    <input
                                        type="checkbox"
                                        class="peer sr-only"
                                        :aria-label="'{{ __('admin::app.components.datagrid.table.select-row') }}'.replace(':name', () => record.display_name || record.file_name)"
                                        :name="`mass_action_select_record_${record[$parent.available.meta.primary_column]}`"
                                        :value="record[$parent.available.meta.primary_column]"
                                        :id="`mass_action_select_record_${record[$parent.available.meta.primary_column]}`"
                                        v-model="$parent.applied.massActions.indices"
                                        @change="$parent.setCurrentSelectionMode"
                                    >

                                    <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 peer-checked:icon-checkbox-check peer-checked:text-primary-700 rounded-md text-2xl shrink-0"></span>
                                </label>

                                <h2
                                    class="text-xs sm:text-sm font-normal text-gray-600 dark:text-gray-300 truncate"
                                    v-text="record.display_name || record.file_name"
                                ></h2>
                                {{-- Teleported and right-aligned: the panel is fixed against the kebab and kept inside the viewport (a phone's right-edge kebab would otherwise hang a 20rem menu past the screen). --}}
                                <x-admin::dropdown position="bottom-right" :teleport="true">
                                    <x-slot:toggle>
                                        <button type="button" class="shrink-0 p-1 text-gray-500" title="@lang('dam::app.admin.explorer.list.header.actions')">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"></circle><circle cx="12" cy="12" r="2"></circle><circle cx="12" cy="19" r="2"></circle></svg>
                                        </button>
                                    </x-slot:toggle>
                                    <x-slot:menu>
                                        @if (bouncer()->hasPermission('dam.asset.view'))
                                        <li>
                                            <button type="button" class="block w-full px-4 py-2 text-start hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-800" @click="previewImage(record.id)">@lang('dam::app.admin.dam.asset.edit.preview-modal.card.preview')</button>
                                        </li>
                                        @endif
                                        @if (bouncer()->hasPermission('dam.asset.edit'))
                                        <li>
                                            <button type="button" class="block w-full px-4 py-2 text-start hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-800" @click="editImage(record.id)">@lang('dam::app.admin.dam.index.directory.actions.edit')</button>
                                        </li>
                                        @endif
                                        @if (bouncer()->hasPermission('dam.asset.destroy'))
                                        <li>
                                            <button type="button" class="block w-full px-4 py-2 text-start hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-800" @click="deleteImage(record.id)">@lang('dam::app.admin.dam.index.directory.actions.delete')</button>
                                        </li>
                                        @endif
                                    </x-slot:menu>
                                </x-admin::dropdown>
                            </div>
                        </div>
                    </template>
                </slot>
            </div>

            {{-- A picker opened with a deadline (timeout-ms) shows its loading shimmer, not the empty state, until its first answer. --}}
            <template v-else-if="'timeoutMs' in $parent && $parent.timeoutMs && $parent.isLoading">
                <x-admin::shimmer.datagrid.table.body :isMultiRow="$isMultiRow" />
            </template>

            <template v-else>
                @include('dam::components.shared.empty-state')
            </template>
        </div>
    </script>

    <script type="module">
        app.component('v-gallery-table', {
            template: '#v-gallery-table-template',

            data() {
                return {};
            },

            computed: {
                gridsCount() {
                    let count = this.$parent.available.columns.length;

                    if (this.$parent.available.actions.length) {
                        ++count;
                    }

                    if (this.$parent.available.massActions.length) {
                        ++count;
                    }

                    return count;
                },
            },

            methods: {
                onAssetDragStart(event, record) {
                    event.dataTransfer.setData('application/json', JSON.stringify(window.MothershipFileOps.dragPayload({ assetId: record.id, selectedIds: this.$parent.applied.massActions.indices })));
                    event.dataTransfer.effectAllowed = 'move';
                },
                deleteImage(recordId) {
                    this.$emitter.emit('open-delete-modal', {
                        agree: () => {
                            window.MothershipFileOps.deleteWithConfirmation({message: "@lang('dam::app.admin.dam.asset.delete-blocked-confirm')", emitter: this.$emitter, request: force => this.$axios.delete(`{{ route('admin.dam.assets.destroy', ':id') }}`.replace(':id', recordId), { data: { force } }) })
                                .then(response => {
                                    if (!response) return;
                                    const { data } = response;
                                    this.$emitter.emit('add-flash', {
                                        type: 'success',
                                        message: data.message,
                                    });

                                    this.$emitter.emit('delete-assets', {
                                        actionType: 'single-action',
                                        count: 1,
                                    });

                                    this.$parent.get();
                                })
                                .catch(error => {
                                    this.$emitter.emit('add-flash', {
                                        type: 'error',
                                        message: error?.response?.data?.message,
                                    });
                                });
                        }
                    });
                },

                editImage(recordId) {
                    // Hand the visible gallery order AND the exact browsed scope (the applied
                    // directory_id filter, or "all" when none is set) over, so the details
                    // page's chevrons walk the same order and folder this click came from.
                    const siblings = this.$parent.available.records.map(record => record.id);
                    const dirFilter = (this.$parent.applied?.filters?.columns || []).find(column => column.index === 'directory_id');
                    const directoryId = dirFilter?.value?.[0] || 'all';
                    window.MothershipDetailsNavigation?.writeSiblings({ siblings, scopeLabel: 'gallery', directoryId });
                    const url = `{{ route('admin.dam.assets.edit', ':id') }}`.replace(':id', recordId);
                    this.$navigate(window.MothershipDetailsNavigation ? window.MothershipDetailsNavigation.withNavMarker(url, directoryId) : url);
                },

                previewImage(recordId) {
                    // Matches the `record.id` the template's @preview handler passes as recordId.
                    // mitt's emit(type, event?) carries exactly one payload.
                    const siblings = this.$parent.available.records.map(record => record.id);
                    const dirFilter = (this.$parent.applied?.filters?.columns || []).find(column => column.index === 'directory_id');
                    const directoryId = dirFilter?.value?.[0] || 'all';
                    this.$emitter.emit('dam-open-preview', {id: recordId, siblings, directoryId});
                },
            },
        });
    </script>
@endpushOnce
