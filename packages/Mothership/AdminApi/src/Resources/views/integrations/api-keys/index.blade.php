@php
    $permissionTypes = collect((new \Mothership\AdminApi\Models\Apikey)->getPermissionTypes())
        ->toJson();
@endphp

<x-admin::layouts>
    <!-- Page Title -->
    <x-slot:title>
        @lang('admin::app.configuration.integrations.index.title')
    </x-slot>

    <x-admin::layouts.page-header :title="trans('admin::app.configuration.integrations.index.title')">
        <x-slot:actions>
            @if (bouncer()->hasPermission('configuration.integrations.create')) 
                <button
                    type="button"
                    class="primary-button"
                    @click="$refs.integrationCreateModal.toggle()"
                >
                    @lang('admin::app.configuration.integrations.index.create-btn')
                </button>
            @endif
        </x-slot>
    </x-admin::layouts.page-header>

    @if (bouncer()->hasPermission('configuration.integrations.create'))
        <x-admin::form
            ajax
            :track-dirty="false"
            :action="route('admin.configuration.integrations.store')"
        >
            <x-admin::modal ref="integrationCreateModal">
                <x-slot:header>
                    <p class="text-lg text-gray-800 dark:text-white font-bold">
                        @lang('admin::app.configuration.integrations.create.title')
                    </p>
                </x-slot>

                <x-slot:content>
                    <v-integration-create-fields></v-integration-create-fields>
                </x-slot>

                <x-slot:footer>
                    <button
                        type="submit"
                        class="primary-button"
                    >
                        @lang('admin::app.configuration.integrations.create.save-btn')
                    </button>
                </x-slot>
            </x-admin::modal>
        </x-admin::form>

        @pushOnce('scripts')
            <script
                type="text/x-template"
                id="v-integration-create-fields-template"
            >
                <div>
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('admin::app.configuration.integrations.create.name')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            name="name"
                            rules="required"
                            :label="trans('admin::app.configuration.integrations.create.name')"
                            :placeholder="trans('admin::app.configuration.integrations.create.name')"
                        />

                        <x-admin::form.control-group.error control-name="name" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('admin::app.configuration.integrations.create.permissions')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="select"
                            name="permission_type"
                            rules="required"
                            v-model="permission_type"
                            :label="trans('admin::app.configuration.integrations.create.permissions')"
                            :placeholder="trans('admin::app.configuration.integrations.create.permissions')"
                            :options="$permissionTypes"
                            track-by="id"
                            label-by="label"
                        />

                        <x-admin::form.control-group.error control-name="permission_type" />
                    </x-admin::form.control-group>

                    <div
                        v-if="selected_permission_type == 'custom'"
                        class="max-h-96 overflow-y-auto"
                    >
                        <x-admin::tree.view
                            input-type="checkbox"
                            value-field="key"
                            id-field="key"
                            :items="json_encode($acl->items)"
                            :fallback-locale="config('app.fallback_locale')"
                        />
                    </div>
                </div>
            </script>

            <script type="module">
                app.component('v-integration-create-fields', {
                    template: '#v-integration-create-fields-template',

                    data() {
                        return {
                            permission_type: '',
                            selected_permission_type: '',
                        };
                    },

                    watch: {
                        permission_type(value) {
                            this.selected_permission_type = this.parseValue(value)?.id ?? value;
                        },
                    },

                    methods: {
                        parseValue(value) {
                            try {
                                return value ? JSON.parse(value) : null;
                            } catch (error) {
                                return value;
                            }
                        },
                    },
                });
            </script>
        @endPushOnce
    @endif

    {!! view_render_event('mothership.admin.configuration.integrations.list.before') !!}
    
    <x-admin::datagrid src="{{ route('admin.configuration.integrations.index') }}" />

    {!! view_render_event('mothership.admin.configuration.integrations.list.after') !!}

</x-admin::layouts>
