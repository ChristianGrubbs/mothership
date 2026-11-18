<x-admin::layouts>
    <x-slot:title>@lang('dam::app.admin.automation.mappings.title')</x-slot:title>

    <v-dam-metadata-mappings :initial='@json($initial, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'></v-dam-metadata-mappings>

    @pushOnce('scripts')
    <script type="text/x-template" id="v-dam-metadata-mappings-template">
        <div class="mx-auto max-w-6xl pb-10" data-metadata-mappings>
            <x-admin::breadcrumbs :leaf="trans('dam::app.admin.automation.mappings.title')" :own-trail="true" :parents="[['label' => trans('admin::app.components.layouts.sidebar.configure'), 'url' => route('admin.dam.configuration.index')]]" />
            <a class="mt-2 inline-block text-sm text-primary-600" href="{{ route('admin.dam.configuration.index') }}">@lang('dam::app.admin.automation.back')</a>

            <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.automation.mappings.title')</h1>
                    <p class="mt-2 max-w-2xl text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.mappings.description')</p>
                </div>
                <button type="button" class="primary-button whitespace-nowrap" data-mapping-new @click="startCreate">@lang('dam::app.admin.automation.mappings.new')</button>
            </div>

            <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
                <!-- The editor comes first on a phone, so it opens where the person is looking. -->
                <section
                    v-if="draft"
                    ref="editor"
                    class="order-first min-w-0 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-cherry-900 lg:order-last"
                    data-mapping-form
                    :aria-label="draft.id ? @js(trans('dam::app.admin.automation.mappings.edit-title')) : @js(trans('dam::app.admin.automation.mappings.create-title'))"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-800 dark:text-white">@{{ draft.id ? @js(trans('dam::app.admin.automation.mappings.edit-title')) : @js(trans('dam::app.admin.automation.mappings.create-title')) }}</h2>
                        <span v-if="draft.id" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-cherry-800 dark:text-gray-300">@{{ versionLabel(draft.version) }}</span>
                    </div>

                    <form class="mt-4 grid gap-4" @submit.prevent="save">
                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            @lang('dam::app.admin.automation.mappings.form.name')
                            <input v-model.trim="draft.name" maxlength="191" required class="w-full min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-mapping-input="name">
                            <span v-if="errors.name" class="text-xs font-normal text-red-600">@{{ errors.name }}</span>
                        </label>

                        <label class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-200">
                            <input type="checkbox" v-model="draft.enabled" class="h-4 w-4 accent-primary-600" data-mapping-input="enabled">
                            @lang('dam::app.admin.automation.mappings.form.enabled')
                        </label>

                        <fieldset class="min-w-0 grid gap-2 rounded-lg border border-gray-200 p-3 dark:border-cherry-800" data-mapping-key-picker>
                            <legend class="px-1 text-sm font-medium text-gray-700 dark:text-gray-200">@lang('dam::app.admin.automation.mappings.form.key')</legend>
                            <input v-model.trim="draft.source_key" maxlength="191" required aria-describedby="mapping-key-hint" class="w-full min-w-0 rounded-md border border-gray-300 px-3 py-2 font-mono text-sm dark:border-cherry-700 dark:bg-cherry-800" data-mapping-input="source_key" :aria-label="@js(trans('dam::app.admin.automation.mappings.form.key'))">
                            <span id="mapping-key-hint" class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.mappings.form.key-hint')</span>
                            <span v-if="errors.source_key" class="text-xs text-red-600">@{{ errors.source_key }}</span>

                            <div class="mt-1 flex flex-wrap items-end gap-2">
                                <label class="grid min-w-0 flex-1 gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                    @lang('dam::app.admin.automation.mappings.form.sample')
                                    <input v-model.trim="sampleId" inputmode="numeric" pattern="[0-9]*" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-mapping-sample @keydown.enter.prevent="loadKeys">
                                </label>
                                <button type="button" class="secondary-button" :disabled="!sampleId || keysBusy" @click="loadKeys" data-mapping-load-keys>@lang('dam::app.admin.automation.mappings.form.load-keys')</button>
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.mappings.form.sample-hint')</span>
                            <p v-if="keysError" role="alert" class="text-xs text-red-600">@{{ keysError }}</p>
                            <p v-else-if="keys && !keys.length" class="text-xs text-gray-500">@lang('dam::app.admin.automation.mappings.form.keys-empty')</p>
                            <ul v-else-if="keys" class="max-h-56 divide-y divide-gray-100 overflow-auto rounded-md border border-gray-200 dark:divide-cherry-800 dark:border-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.mappings.form.keys'))" data-mapping-keys>
                                <li v-for="key in keys" :key="key.key">
                                    <button type="button" class="grid w-full gap-0.5 px-3 py-1.5 text-left hover:bg-primary-50 focus:bg-primary-50 focus:outline-none dark:hover:bg-cherry-800 dark:focus:bg-cherry-800" :class="{ 'bg-primary-50 dark:bg-cherry-800': key.key === draft.source_key }" :aria-pressed="key.key === draft.source_key" @click="draft.source_key = key.key">
                                        <span class="break-words font-mono text-xs font-semibold text-gray-800 dark:text-white">@{{ key.key }}</span>
                                        <span class="truncate text-xs text-gray-500 dark:text-gray-400">@{{ key.sample }}</span>
                                    </button>
                                </li>
                            </ul>
                        </fieldset>

                        <fieldset class="min-w-0 grid gap-2" data-mapping-transforms>
                            <legend class="mb-1 text-sm font-medium text-gray-700 dark:text-gray-200">@lang('dam::app.admin.automation.mappings.form.transforms')</legend>
                            <div v-for="(step, index) in draft.transforms" :key="index" class="grid gap-2 rounded-lg border border-gray-200 p-2.5 dark:border-cherry-800" :data-mapping-transform="index">
                                <div class="flex flex-wrap items-center gap-2">
                                    <select v-model="step.type" class="w-full min-w-0 flex-1 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="stepLabel(index)" @change="resetStep(step)">
                                        <option v-for="type in initial.transforms" :key="type" :value="type">@{{ transformLabel(type) }}</option>
                                    </select>
                                    <button type="button" class="h-8 w-8 rounded-md text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-cherry-800" :disabled="index === 0" :aria-label="stepLabel(index) + ': ' + @js(trans('dam::app.admin.automation.move-up'))" @click="moveStep(index, -1)">▲</button>
                                    <button type="button" class="h-8 w-8 rounded-md text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-cherry-800" :disabled="index === draft.transforms.length - 1" :aria-label="stepLabel(index) + ': ' + @js(trans('dam::app.admin.automation.move-down'))" @click="moveStep(index, 1)">▼</button>
                                    <button type="button" class="h-8 w-8 rounded-md text-gray-500 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-cherry-800" :aria-label="stepLabel(index) + ': ' + @js(trans('dam::app.admin.automation.remove'))" @click="draft.transforms.splice(index, 1)">×</button>
                                </div>
                                <label v-if="step.type === 'split'" class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                    @lang('dam::app.admin.automation.mappings.form.delimiter')
                                    <input v-model="step.delimiter" maxlength="8" required class="w-24 rounded-md border border-gray-300 px-2.5 py-1.5 font-mono text-sm dark:border-cherry-700 dark:bg-cherry-800">
                                </label>
                                <div v-if="step.type === 'regex'" class="grid grid-cols-[minmax(0,1fr)_5rem] gap-2">
                                    <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                        @lang('dam::app.admin.automation.mappings.form.pattern')
                                        <input v-model="step.pattern" maxlength="191" required class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 font-mono text-sm dark:border-cherry-700 dark:bg-cherry-800">
                                    </label>
                                    <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                        @lang('dam::app.admin.automation.mappings.form.group')
                                        <input v-model.number="step.group" type="number" min="0" max="9" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800">
                                    </label>
                                </div>
                                <template v-if="step.type === 'map'">
                                    <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                        @lang('dam::app.admin.automation.mappings.form.map-values')
                                        <textarea v-model="step.text" rows="3" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 font-mono text-sm dark:border-cherry-700 dark:bg-cherry-800"></textarea>
                                    </label>
                                    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                        <input type="checkbox" v-model="step.drop_unmapped" class="h-4 w-4 accent-primary-600">
                                        @lang('dam::app.admin.automation.mappings.form.drop-unmapped')
                                    </label>
                                </template>
                                <span v-if="errors['transforms.' + index]" class="text-xs text-red-600">@{{ errors['transforms.' + index] }}</span>
                            </div>
                            <button type="button" class="justify-self-start text-sm font-medium text-primary-600 hover:underline" @click="draft.transforms.push({ type: 'trim' })" data-mapping-add-transform>+ @lang('dam::app.admin.automation.mappings.form.add-transform')</button>
                            <span v-if="errors.transforms" class="text-xs text-red-600">@{{ errors.transforms }}</span>
                        </fieldset>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                                @lang('dam::app.admin.automation.mappings.form.target')
                                <select v-model="draft.target_type" class="w-full min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-mapping-input="target_type">
                                    <option v-for="target in initial.targets" :key="target" :value="target">@{{ targetLabel(target) }}</option>
                                </select>
                            </label>
                            <label v-if="draft.target_type === 'field'" class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                                @lang('dam::app.admin.automation.mappings.form.field')
                                <select v-model="draft.target_id" required class="w-full min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-mapping-input="target_id">
                                    <option :value="null" disabled>@lang('dam::app.admin.automation.choose')</option>
                                    <option v-for="field in initial.fields" :key="field.id" :value="field.id">@{{ field.label }}</option>
                                </select>
                                <span v-if="errors.target_id" class="text-xs font-normal text-red-600">@{{ errors.target_id }}</span>
                            </label>
                            <p v-if="draft.target_type === 'label'" class="self-end text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.mappings.form.label-hint')</p>
                        </div>

                        <fieldset class="min-w-0 grid gap-1.5">
                            <legend class="mb-1 text-sm font-medium text-gray-700 dark:text-gray-200">@lang('dam::app.admin.automation.mappings.form.mode')</legend>
                            <label v-for="mode in initial.modes" :key="mode" class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <input type="radio" v-model="draft.write_mode" :value="mode" class="mt-0.5 h-4 w-4 accent-primary-600" name="mapping-mode">
                                @{{ modeLabel(mode) }}
                            </label>
                        </fieldset>

                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            @lang('dam::app.admin.automation.mappings.form.scope')
                            <select v-model="draft.scope_directory_id" class="w-full min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-mapping-input="scope_directory_id">
                                <option :value="null">@lang('dam::app.admin.automation.mappings.form.scope-all')</option>
                                <option v-for="folder in initial.folders" :key="folder.id" :value="folder.id">@{{ folder.path }}</option>
                            </select>
                            <span v-if="errors.scope_directory_id" class="text-xs font-normal text-red-600">@{{ errors.scope_directory_id }}</span>
                        </label>

                        <p v-if="errors.general" role="alert" class="text-sm text-red-600">@{{ errors.general }}</p>

                        <div class="flex flex-wrap items-center gap-3 pt-1">
                            <button type="submit" class="primary-button" :disabled="busy" :aria-busy="busy" data-mapping-save>@{{ busy ? @js(trans('dam::app.admin.automation.saving')) : @js(trans('dam::app.admin.automation.save')) }}</button>
                            <button type="button" class="transparent-button" @click="cancel">@lang('dam::app.admin.automation.cancel')</button>
                        </div>
                    </form>

                    <section class="mt-6 border-t border-gray-200 pt-5 dark:border-cherry-800" data-mapping-preview :aria-label="@js(trans('dam::app.admin.automation.dry-run.title'))">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.automation.selection.title')</h3>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                @lang('dam::app.admin.automation.selection.asset-ids')
                                <input v-model="selection.ids" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-mapping-selection-ids>
                                <span class="font-normal text-gray-500">@lang('dam::app.admin.automation.selection.asset-hint')</span>
                            </label>
                            <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                @lang('dam::app.admin.automation.selection.folder')
                                <select v-model="selection.folder" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-mapping-selection-folder>
                                    <option :value="null">@lang('dam::app.admin.automation.selection.no-folder')</option>
                                    <option v-for="folder in initial.folders" :key="folder.id" :value="folder.id">@{{ folder.path }}</option>
                                </select>
                                <span class="font-normal text-gray-500">@lang('dam::app.admin.automation.selection.folder-hint')</span>
                            </label>
                        </div>
                        <p v-if="errors.selection" role="alert" class="mt-2 text-xs text-red-600">@{{ errors.selection }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <button type="button" class="secondary-button" :disabled="previewBusy || !hasSelection" :aria-busy="previewBusy" @click="dryRun" data-mapping-dry-run>@{{ previewBusy ? @js(trans('dam::app.admin.automation.dry-run.running')) : @js(trans('dam::app.admin.automation.dry-run.button')) }}</button>
                            <button v-if="draft.id" type="button" class="secondary-button" :disabled="runBusy || !hasSelection" :aria-busy="runBusy" @click="runNow" data-mapping-run>@{{ runBusy ? @js(trans('dam::app.admin.automation.running')) : @js(trans('dam::app.admin.automation.run-now')) }}</button>
                            <span v-if="draft.id" class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.run-saved-hint')</span>
                        </div>

                        <div v-if="preview" class="mt-4" data-mapping-dry-run-result aria-live="polite">
                            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.dry-run.hint')</p>
                            <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white">@{{ previewSummary }}</p>
                            <p v-if="!previewRows.length" class="mt-2 text-sm text-gray-500">@lang('dam::app.admin.automation.dry-run.empty')</p>
                            <ul v-if="previewRows.length" class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 text-xs sm:hidden dark:divide-cherry-800 dark:border-cherry-800">
                                <li v-for="(row, index) in previewRows" :key="index" class="grid gap-1 px-3 py-2">
                                    <div class="flex items-start justify-between gap-2"><span class="min-w-0 break-words font-medium text-gray-800 dark:text-white">@{{ row.name }} <span class="font-normal text-gray-400">#@{{ row.asset_id }}</span></span><span :class="outcomeClass(row.outcome)" class="shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 font-medium">@{{ outcomeLabel(row.outcome) }}</span></div>
                                    <span class="break-words text-gray-600 dark:text-gray-300">@{{ row.target }}</span>
                                    <span class="break-words text-gray-700 dark:text-gray-200"><span class="text-gray-500">@{{ shown(row.before) }}</span> → <span class="font-medium">@{{ shown(row.after ?? row.value) }}</span></span>
                                    <span v-if="row.reason" class="text-gray-500 dark:text-gray-400">@{{ reasonLabel(row.reason) }}</span>
                                </li>
                            </ul>
                            <div v-if="previewRows.length" class="mt-2 hidden overflow-x-auto sm:block rounded-lg border border-gray-200 dark:border-cherry-800">
                                <table class="w-full min-w-[34rem] text-left text-xs">
                                    <thead class="bg-gray-50 text-gray-600 dark:bg-cherry-800 dark:text-gray-300">
                                        <tr>
                                            <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.asset')</th>
                                            <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.target')</th>
                                            <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.change')</th>
                                            <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.outcome')</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-cherry-800">
                                        <tr v-for="(row, index) in previewRows" :key="index" class="align-top">
                                            <td class="max-w-[12rem] px-3 py-2"><span class="block truncate text-gray-800 dark:text-white" :title="row.name">@{{ row.name }}</span><span class="text-gray-400">#@{{ row.asset_id }}</span></td>
                                            <td class="break-words px-3 py-2 font-mono text-gray-700 dark:text-gray-200">@{{ row.target }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200"><span class="text-gray-500">@{{ shown(row.before) }}</span> → <span class="font-medium">@{{ shown(row.after ?? row.value) }}</span></td>
                                            <td class="px-3 py-2"><span :class="outcomeClass(row.outcome)" class="whitespace-nowrap rounded-full px-2 py-0.5 font-medium">@{{ outcomeLabel(row.outcome) }}</span><span v-if="row.reason" class="mt-1 block text-gray-500 dark:text-gray-400">@{{ reasonLabel(row.reason) }}</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                </section>

                <section class="min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-cherry-900" data-mapping-list>
                    <p v-if="!mappings.length" class="p-6 text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.mappings.empty')</p>

                    <ul v-else class="divide-y divide-gray-100 dark:divide-cherry-800">
                        <li
                            v-for="mapping in mappings"
                            :key="mapping.id"
                            class="grid gap-2 px-4 py-3"
                            :class="{ 'bg-primary-50/60 dark:bg-primary-900/20': draft && draft.id === mapping.id }"
                            :data-mapping-row="mapping.id"
                        >
                            <div class="flex items-start gap-3">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="mapping.enabled ? 'true' : 'false'"
                                    :aria-label="toggleLabel(mapping)"
                                    class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                                    :class="mapping.enabled ? 'bg-primary-600' : 'bg-gray-300 dark:bg-cherry-700'"
                                    @click="toggle(mapping)"
                                    data-mapping-toggle
                                >
                                    <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform" :class="mapping.enabled ? 'translate-x-4' : 'translate-x-0.5'"></span>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-gray-800 dark:text-white" :title="mapping.name">@{{ mapping.name }}</p>
                                    <p class="mt-0.5 break-words text-xs text-gray-600 dark:text-gray-300"><span class="font-mono">@{{ mapping.source_key }}</span> → @{{ targetText(mapping) }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-cherry-800 dark:text-gray-300">@{{ versionLabel(mapping.version) }}</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 pl-12 text-xs text-gray-500 dark:text-gray-400">
                                <span>@{{ modeLabel(mapping.write_mode) }}</span>
                                <span v-if="mapping.transforms.length">· @{{ mapping.transforms.map(step => transformLabel(step.type)).join(' → ') }}</span>
                                <span>· @{{ lastRunText(mapping) }}</span>
                            </div>
                            <div class="flex flex-wrap gap-1 pl-10">
                                <button type="button" class="rounded-md px-2.5 py-1 text-sm font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-cherry-800" :data-mapping-edit="mapping.id" @click="startEdit(mapping)">@lang('dam::app.admin.automation.edit')</button>
                                <button type="button" class="rounded-md px-2.5 py-1 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-cherry-800" @click="remove(mapping)">@lang('dam::app.admin.automation.delete')</button>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </script>

    <script type="module">
    app.component('v-dam-metadata-mappings', {
        template: '#v-dam-metadata-mappings-template',
        props: ['initial'],

        data() {
            return {
                mappings: this.initial.mappings,
                draft: null,
                busy: false,
                errors: {},
                sampleId: '',
                keys: null,
                keysBusy: false,
                keysError: null,
                selection: { ids: '', folder: null },
                preview: null,
                previewBusy: false,
                runBusy: false,
            };
        },

        computed: {
            hasSelection() { return this.selection.folder !== null || this.selectionIds().length > 0; },
            previewRows() {
                return (this.preview || []).flatMap(asset => asset.writes.map(write => ({ ...write, asset_id: asset.asset_id, name: asset.name })));
            },
            previewSummary() {
                const count = outcome => this.previewRows.filter(row => row.outcome === outcome).length;
                return @js(trans('dam::app.admin.automation.dry-run.summary'))
                    .replace(':writes', count('written')).replace(':skipped', count('skipped')).replace(':refused', count('refused')).replace(':assets', (this.preview || []).length);
            },
        },

        methods: {
            targetLabel(target) { return @js(trans('dam::app.admin.automation.mappings.targets'))[target] ?? target; },
            modeLabel(mode) { return @js(trans('dam::app.admin.automation.mappings.modes'))[mode] ?? mode; },
            transformLabel(type) { return @js(trans('dam::app.admin.automation.mappings.transform-types'))[type] ?? type; },
            outcomeLabel(outcome) { return @js(trans('dam::app.admin.automation.outcomes'))[outcome] ?? outcome; },
            reasonLabel(reason) { return @js(trans('dam::app.admin.automation.reasons'))[reason] ?? reason; },
            versionLabel(version) { return @js(trans('dam::app.admin.automation.version')).replace(':n', version); },
            stepLabel(index) { return @js(trans('dam::app.admin.automation.mappings.form.step')).replace(':n', index + 1); },
            toggleLabel(mapping) { return @js(trans('dam::app.admin.automation.toggle')).replace(':name', () => mapping.name); },
            shown(value) { return value === null || value === undefined || value === '' ? @js(trans('dam::app.admin.automation.dry-run.empty-value')) : value; },
            outcomeClass(outcome) {
                return { written: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200', skipped: 'bg-gray-100 text-gray-600 dark:bg-cherry-800 dark:text-gray-300', refused: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' }[outcome];
            },
            targetText(mapping) {
                return mapping.target_type === 'field' ? this.targetLabel('field') + ': ' + (mapping.target_label ?? '#' + mapping.target_id) : this.targetLabel(mapping.target_type);
            },
            lastRunText(mapping) {
                if (!mapping.last_run_at) return @js(trans('dam::app.admin.automation.never-run'));
                const counts = mapping.last_run_counts || {};
                return @js(trans('dam::app.admin.automation.mappings.last-run')).replace(':date', new Date(mapping.last_run_at).toLocaleString())
                    .replace(':written', counts.written ?? 0).replace(':skipped', counts.skipped ?? 0).replace(':refused', counts.refused ?? 0);
            },
            focusEditor() { this.$nextTick(() => this.$refs.editor?.querySelector('input')?.focus()); },
            startCreate() {
                this.errors = {};
                this.preview = null;
                this.draft = { id: null, version: 1, name: '', enabled: true, source_key: '', transforms: [], target_type: 'tag', target_id: null, write_mode: 'fill_empty', scope_directory_id: null };
                this.focusEditor();
            },
            startEdit(mapping) {
                this.errors = {};
                this.preview = null;
                const draft = JSON.parse(JSON.stringify(mapping));
                // The value map edits as "from = to" lines.
                draft.transforms = draft.transforms.map(step => step.type === 'map' ? { ...step, text: Object.entries(step.values || {}).map(([from, to]) => from + ' = ' + to).join('\n') } : step);
                this.draft = draft;
                this.focusEditor();
            },
            cancel() { this.draft = null; this.errors = {}; this.preview = null; },
            resetStep(step) {
                Object.keys(step).forEach(key => key !== 'type' && delete step[key]);
                if (step.type === 'regex') step.group = 1;
                if (step.type === 'map') { step.text = ''; step.drop_unmapped = false; }
            },
            moveStep(index, direction) {
                const steps = this.draft.transforms;
                steps.splice(index + direction, 0, ...steps.splice(index, 1));
            },
            payload() {
                const transforms = this.draft.transforms.map(step => {
                    if (step.type !== 'map') return step;
                    const values = {};
                    (step.text || '').split('\n').forEach(line => {
                        const at = line.indexOf('=');
                        if (at > 0) values[line.slice(0, at).trim()] = line.slice(at + 1).trim();
                    });
                    return { type: 'map', values, drop_unmapped: !!step.drop_unmapped };
                });
                return {
                    name: this.draft.name, enabled: this.draft.enabled, source_key: this.draft.source_key, transforms,
                    target_type: this.draft.target_type, target_id: this.draft.target_type === 'field' ? this.draft.target_id : null,
                    write_mode: this.draft.write_mode, scope_directory_id: this.draft.scope_directory_id,
                };
            },
            selectionIds() { return String(this.selection.ids).split(/[\s,]+/).map(id => parseInt(id, 10)).filter(id => id > 0); },
            selectionBody() { return this.selection.folder !== null ? { directory_id: this.selection.folder } : { asset_ids: this.selectionIds() }; },
            showErrors(error) {
                const errors = error.response?.data?.errors || {};
                this.errors = {};
                Object.entries(errors).forEach(([key, messages]) => {
                    const name = key.startsWith('transforms.') ? key.split('.').slice(0, 2).join('.') : (['asset_ids', 'directory_id'].includes(key.split('.')[0]) ? 'selection' : key.split('.')[0]);
                    this.errors[name] = [].concat(messages).join(' ');
                });
                if (!Object.keys(errors).length) this.errors.general = error.response?.data?.message || @js(trans('dam::app.admin.automation.error'));
            },
            async loadKeys() {
                if (!this.sampleId || this.keysBusy) return;
                this.keysBusy = true;
                this.keysError = null;
                try {
                    const { data } = await this.$axios.get(@js(route('admin.dam.configuration.mappings.keys')), { params: { asset_id: this.sampleId } });
                    this.keys = data.keys;
                } catch (error) {
                    this.keys = null;
                    this.keysError = error.response?.status === 404 ? @js(trans('dam::app.admin.automation.validation.selection')) : (error.response?.data?.message || @js(trans('dam::app.admin.automation.error')));
                } finally {
                    this.keysBusy = false;
                }
            },
            async save() {
                if (this.busy) return;
                this.busy = true;
                try {
                    const { data } = this.draft.id
                        ? await this.$axios.put(@js(route('admin.dam.configuration.mappings.update', '__id__')).replace('__id__', this.draft.id), this.payload())
                        : await this.$axios.post(@js(route('admin.dam.configuration.mappings.store')), this.payload());
                    const index = this.mappings.findIndex(m => m.id === data.mapping.id);
                    index < 0 ? this.mappings.push(data.mapping) : this.mappings.splice(index, 1, data.mapping);
                    this.startEdit(data.mapping);
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                } catch (error) {
                    this.showErrors(error);
                } finally {
                    this.busy = false;
                }
            },
            async toggle(mapping) {
                try {
                    const { data } = await this.$axios.put(@js(route('admin.dam.configuration.mappings.update', '__id__')).replace('__id__', mapping.id), { enabled: !mapping.enabled });
                    this.mappings.splice(this.mappings.indexOf(mapping), 1, data.mapping);
                    if (this.draft?.id === mapping.id) this.draft.enabled = data.mapping.enabled;
                } catch (error) {
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || @js(trans('dam::app.admin.automation.error')) });
                }
            },
            async dryRun() {
                if (this.previewBusy) return;
                this.previewBusy = true;
                delete this.errors.selection;
                try {
                    const { data } = await this.$axios.post(@js(route('admin.dam.configuration.mappings.dry_run')), { id: this.draft.id, mapping: this.payload(), ...this.selectionBody() });
                    this.preview = data.assets;
                } catch (error) {
                    this.showErrors(error);
                } finally {
                    this.previewBusy = false;
                }
            },
            async runNow() {
                if (this.runBusy || !this.draft.id) return;
                this.runBusy = true;
                delete this.errors.selection;
                try {
                    const { data } = await this.$axios.post(@js(route('admin.dam.configuration.mappings.run', '__id__')).replace('__id__', this.draft.id), this.selectionBody());
                    this.mappings.splice(this.mappings.findIndex(m => m.id === data.mapping.id), 1, data.mapping);
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                } catch (error) {
                    this.showErrors(error);
                } finally {
                    this.runBusy = false;
                }
            },
            remove(mapping) {
                this.$emitter.emit('open-confirm-modal', {
                    message: @js(trans('dam::app.admin.automation.confirm-delete')).replace(':name', () => mapping.name),
                    options: { btnAgree: @js(trans('dam::app.admin.automation.delete')), btnDisagree: @js(trans('dam::app.admin.automation.cancel')), btnAgreeClass: 'danger-button', btnDisagreeClass: 'transparent-button' },
                    agree: async () => {
                        try {
                            const { data } = await this.$axios.delete(@js(route('admin.dam.configuration.mappings.destroy', '__id__')).replace('__id__', mapping.id));
                            this.mappings = this.mappings.filter(m => m.id !== mapping.id);
                            if (this.draft?.id === mapping.id) this.cancel();
                            this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        } catch (error) {
                            this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || @js(trans('dam::app.admin.automation.error')) });
                        }
                    },
                    disagree: () => {},
                });
            },
        },
    });
    </script>
    @endPushOnce
</x-admin::layouts>
