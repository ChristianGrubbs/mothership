<x-admin::layouts>
    <x-slot:title>@lang('dam::app.admin.automation.rules.title')</x-slot:title>

    <v-dam-automation-rules :initial='@json($initial, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'></v-dam-automation-rules>

    @pushOnce('scripts')
    <script type="text/x-template" id="v-dam-automation-rules-template">
        <div class="mx-auto max-w-6xl pb-10" data-automation-rules>
            <x-admin::breadcrumbs :leaf="trans('dam::app.admin.automation.rules.title')" :own-trail="true" :parents="[['label' => trans('admin::app.components.layouts.sidebar.configure'), 'url' => route('admin.dam.configuration.index')]]" />
            <a class="mt-2 inline-block text-sm text-primary-600" href="{{ route('admin.dam.configuration.index') }}">@lang('dam::app.admin.automation.back')</a>

            <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.automation.rules.title')</h1>
                    <p class="mt-2 max-w-2xl text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.rules.description')</p>
                </div>
                <button type="button" class="primary-button whitespace-nowrap" data-rule-new @click="startCreate">@lang('dam::app.admin.automation.rules.new')</button>
            </div>

            <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)]">
                <!-- The editor or the run log comes first on a phone, so it opens where the person is looking. -->
                <section
                    v-if="draft"
                    ref="editor"
                    class="order-first min-w-0 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-cherry-900 lg:order-last"
                    data-rule-form
                    :aria-label="draft.id ? @js(trans('dam::app.admin.automation.rules.edit-title')) : @js(trans('dam::app.admin.automation.rules.create-title'))"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-800 dark:text-white">@{{ draft.id ? @js(trans('dam::app.admin.automation.rules.edit-title')) : @js(trans('dam::app.admin.automation.rules.create-title')) }}</h2>
                        <span v-if="draft.id" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-cherry-800 dark:text-gray-300">@{{ versionLabel(draft.version) }}</span>
                    </div>

                    <form class="mt-4 grid gap-5" @submit.prevent="save">
                        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                                @lang('dam::app.admin.automation.rules.form.name')
                                <input v-model.trim="draft.name" maxlength="191" required class="w-full min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-rule-input="name">
                                <span v-if="errors.name" class="text-xs font-normal text-red-600">@{{ errors.name }}</span>
                            </label>
                            <label class="flex items-center gap-2 pb-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                                <input type="checkbox" v-model="draft.enabled" class="h-4 w-4 accent-primary-600" data-rule-input="enabled">
                                @lang('dam::app.admin.automation.rules.form.enabled')
                            </label>
                        </div>

                        <fieldset class="min-w-0 grid gap-2" data-rule-trigger>
                            <legend class="mb-1 text-sm font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.automation.rules.form.trigger')</legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <select v-model="draft.trigger_type" class="w-full min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.trigger'))" data-rule-input="trigger_type">
                                    <option v-for="trigger in initial.triggers" :key="trigger" :value="trigger">@{{ triggerLabel(trigger) }}</option>
                                </select>
                                <select v-if="draft.trigger_type === 'field_changed'" v-model="draft.trigger_field_id" class="w-full min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.trigger-field'))" data-rule-input="trigger_field_id">
                                    <option :value="null">@lang('dam::app.admin.automation.rules.form.any-field')</option>
                                    <option v-for="field in initial.fields" :key="field.id" :value="field.id">@{{ field.label }}</option>
                                </select>
                            </div>
                            <span v-if="errors.trigger_type || errors.trigger_field_id" class="text-xs text-red-600">@{{ errors.trigger_type || errors.trigger_field_id }}</span>
                        </fieldset>

                        <fieldset class="min-w-0 grid gap-2" data-rule-conditions>
                            <legend class="mb-1 text-sm font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.automation.rules.form.conditions')</legend>
                            <div class="flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-200">
                                <label class="flex items-center gap-2"><input type="radio" v-model="draft.conditions.match" value="all" name="rule-match" class="h-4 w-4 accent-primary-600">@lang('dam::app.admin.automation.rules.form.match-all')</label>
                                <label class="flex items-center gap-2"><input type="radio" v-model="draft.conditions.match" value="any" name="rule-match" class="h-4 w-4 accent-primary-600">@lang('dam::app.admin.automation.rules.form.match-any')</label>
                            </div>
                            <p v-if="!draft.conditions.conditions.length" class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.rules.form.no-conditions')</p>
                            <div v-for="(condition, index) in draft.conditions.conditions" :key="index" class="grid gap-2 rounded-lg border border-gray-200 p-2.5 dark:border-cherry-800" role="group" :aria-label="conditionLabel(index)" :data-rule-condition="index">
                                <div class="flex flex-wrap items-center gap-2">
                                    <label v-if="['has_tag', 'has_label'].includes(condition.type)" class="flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                                        <input type="checkbox" v-model="condition.negate" class="h-4 w-4 accent-primary-600">@lang('dam::app.admin.automation.rules.form.negate')
                                    </label>
                                    <select v-model="condition.type" class="w-full min-w-0 flex-1 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="conditionLabel(index)" @change="resetCondition(condition)">
                                        <option v-for="type in initial.condition_types" :key="type" :value="type">@{{ conditionTypeLabel(type) }}</option>
                                    </select>
                                    <button type="button" class="h-8 w-8 rounded-md text-gray-500 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-cherry-800" :aria-label="conditionLabel(index) + ': ' + @js(trans('dam::app.admin.automation.remove'))" @click="draft.conditions.conditions.splice(index, 1)">×</button>
                                </div>
                                <select v-if="condition.type === 'folder'" v-model="condition.directory_id" required class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.folder'))">
                                    <option :value="null" disabled>@lang('dam::app.admin.automation.choose')</option>
                                    <option v-for="folder in initial.folders" :key="folder.id" :value="folder.id">@{{ folder.path }}</option>
                                </select>
                                <input v-if="condition.type === 'extension'" v-model="condition.text" required class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :placeholder="'jpg, png'" :aria-label="@js(trans('dam::app.admin.automation.rules.form.values'))">
                                <input v-if="['mime', 'filename', 'has_tag'].includes(condition.type)" v-model="condition.value" required maxlength="191" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :placeholder="condition.type === 'mime' ? 'image/*' : (condition.type === 'filename' ? '*.jpg' : '')" :aria-label="@js(trans('dam::app.admin.automation.rules.form.value'))">
                                <select v-if="condition.type === 'has_label'" v-model="condition.label_id" required class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.label'))">
                                    <option :value="null" disabled>@lang('dam::app.admin.automation.choose')</option>
                                    <option v-for="label in initial.labels" :key="label.id" :value="label.id">@{{ label.name }}</option>
                                </select>
                                <div v-if="condition.type === 'field' || condition.type === 'exif'" class="grid gap-2 sm:grid-cols-3">
                                    <select v-if="condition.type === 'field'" v-model="condition.field_id" required class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.field'))">
                                        <option :value="null" disabled>@lang('dam::app.admin.automation.choose')</option>
                                        <option v-for="field in initial.fields" :key="field.id" :value="field.id">@{{ field.label }}</option>
                                    </select>
                                    <input v-else v-model.trim="condition.key" required maxlength="191" placeholder="EXIF:Make" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 font-mono text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.key'))">
                                    <select v-model="condition.op" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.op'))">
                                        <option v-for="op in (condition.type === 'field' ? initial.field_ops : initial.exif_ops)" :key="op" :value="op">@{{ opLabel(op) }}</option>
                                    </select>
                                    <input v-if="!['empty', 'not_empty', 'exists', 'not_exists'].includes(condition.op)" v-model="condition.value" required maxlength="191" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.value'))">
                                </div>
                                <span v-if="errors['conditions.conditions.' + index]" class="text-xs text-red-600">@{{ errors['conditions.conditions.' + index] }}</span>
                            </div>
                            <button type="button" class="justify-self-start text-sm font-medium text-primary-600 hover:underline" @click="addCondition" data-rule-add-condition>+ @lang('dam::app.admin.automation.rules.form.add-condition')</button>
                            <span v-if="errors.conditions" class="text-xs text-red-600">@{{ errors.conditions }}</span>
                        </fieldset>

                        <fieldset class="min-w-0 grid gap-2" data-rule-actions>
                            <legend class="mb-1 text-sm font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.automation.rules.form.actions')</legend>
                            <div v-for="(action, index) in draft.actions" :key="index" class="grid gap-2 rounded-lg border border-gray-200 p-2.5 dark:border-cherry-800" role="group" :aria-label="actionLabel(index)" :data-rule-action="index">
                                <div class="flex flex-wrap items-center gap-2">
                                    <select v-model="action.type" class="w-full min-w-0 flex-1 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="actionLabel(index)" @change="resetAction(action)">
                                        <option v-for="type in initial.action_types" :key="type" :value="type">@{{ actionTypeLabel(type) }}</option>
                                    </select>
                                    <button type="button" class="h-8 w-8 rounded-md text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-cherry-800" :disabled="index === 0" :aria-label="actionLabel(index) + ': ' + @js(trans('dam::app.admin.automation.move-up'))" @click="moveAction(index, -1)">▲</button>
                                    <button type="button" class="h-8 w-8 rounded-md text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-cherry-800" :disabled="index === draft.actions.length - 1" :aria-label="actionLabel(index) + ': ' + @js(trans('dam::app.admin.automation.move-down'))" @click="moveAction(index, 1)">▼</button>
                                    <button type="button" class="h-8 w-8 rounded-md text-gray-500 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-cherry-800" :aria-label="actionLabel(index) + ': ' + @js(trans('dam::app.admin.automation.remove'))" @click="draft.actions.splice(index, 1)">×</button>
                                </div>
                                <input v-if="action.type === 'add_tag'" v-model.trim="action.value" required maxlength="191" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.value'))">
                                <div v-if="action.type === 'set_field'" class="grid gap-2 sm:grid-cols-2">
                                    <select v-model="action.field_id" required class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.field'))">
                                        <option :value="null" disabled>@lang('dam::app.admin.automation.choose')</option>
                                        <option v-for="field in initial.fields" :key="field.id" :value="field.id">@{{ field.label }}</option>
                                    </select>
                                    <input v-model="action.value" required maxlength="191" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.value'))">
                                </div>
                                <select v-if="action.type === 'add_label'" v-model="action.label_id" required class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.label'))">
                                    <option :value="null" disabled>@lang('dam::app.admin.automation.choose')</option>
                                    <option v-for="label in initial.labels" :key="label.id" :value="label.id">@{{ label.name }}</option>
                                </select>
                                <select v-if="action.type === 'add_to_section'" v-model="action.section_id" required class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.section'))">
                                    <option :value="null" disabled>@lang('dam::app.admin.automation.choose')</option>
                                    <option v-for="section in initial.sections" :key="section.id" :value="section.id">@{{ section.name }}</option>
                                </select>
                                <select v-if="action.type === 'add_to_collection'" v-model="action.collection_id" required class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.automation.rules.form.collection'))">
                                    <option :value="null" disabled>@lang('dam::app.admin.automation.choose')</option>
                                    <option v-for="collection in initial.collections" :key="collection.id" :value="collection.id">@{{ collection.name }}</option>
                                </select>
                                <span v-if="errors['actions.' + index]" class="text-xs text-red-600">@{{ errors['actions.' + index] }}</span>
                            </div>
                            <button type="button" class="justify-self-start text-sm font-medium text-primary-600 hover:underline" @click="draft.actions.push({ type: 'add_tag', value: '' })" data-rule-add-action>+ @lang('dam::app.admin.automation.rules.form.add-action')</button>
                            <span v-if="errors.actions" class="text-xs text-red-600">@{{ errors.actions }}</span>
                        </fieldset>

                        <p v-if="errors.general" role="alert" class="text-sm text-red-600">@{{ errors.general }}</p>

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="submit" class="primary-button" :disabled="busy" :aria-busy="busy" data-rule-save>@{{ busy ? @js(trans('dam::app.admin.automation.saving')) : @js(trans('dam::app.admin.automation.save')) }}</button>
                            <button type="button" class="transparent-button" @click="cancel">@lang('dam::app.admin.automation.cancel')</button>
                        </div>
                    </form>

                    <section class="mt-6 border-t border-gray-200 pt-5 dark:border-cherry-800" data-rule-preview :aria-label="@js(trans('dam::app.admin.automation.dry-run.title'))">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.automation.selection.title')</h3>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                @lang('dam::app.admin.automation.selection.asset-ids')
                                <input v-model="selection.ids" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-rule-selection-ids>
                                <span class="font-normal text-gray-500">@lang('dam::app.admin.automation.selection.asset-hint')</span>
                            </label>
                            <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                @lang('dam::app.admin.automation.selection.folder')
                                <select v-model="selection.folder" class="w-full min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-rule-selection-folder>
                                    <option :value="null">@lang('dam::app.admin.automation.selection.no-folder')</option>
                                    <option v-for="folder in initial.folders" :key="folder.id" :value="folder.id">@{{ folder.path }}</option>
                                </select>
                                <span class="font-normal text-gray-500">@lang('dam::app.admin.automation.selection.folder-hint')</span>
                            </label>
                        </div>
                        <p v-if="errors.selection" role="alert" class="mt-2 text-xs text-red-600">@{{ errors.selection }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <button type="button" class="secondary-button" :disabled="previewBusy || !hasSelection" :aria-busy="previewBusy" @click="dryRun" data-rule-dry-run>@{{ previewBusy ? @js(trans('dam::app.admin.automation.dry-run.running')) : @js(trans('dam::app.admin.automation.dry-run.button')) }}</button>
                            <button v-if="draft.id" type="button" class="secondary-button" :disabled="runBusy || !hasSelection" :aria-busy="runBusy" @click="runNow" data-rule-run>@{{ runBusy ? @js(trans('dam::app.admin.automation.running')) : @js(trans('dam::app.admin.automation.run-now')) }}</button>
                            <span v-if="draft.id" class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.run-saved-hint')</span>
                        </div>

                        <div v-if="preview" class="mt-4" data-rule-dry-run-result aria-live="polite">
                            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.dry-run.hint')</p>
                            <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white">@{{ previewSummary }}</p>
                            <p v-if="!previewRows.length" class="mt-2 text-sm text-gray-500">@lang('dam::app.admin.automation.dry-run.empty')</p>
                            <ul v-if="previewRows.length" class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 text-xs sm:hidden dark:divide-cherry-800 dark:border-cherry-800">
                                <li v-for="(row, index) in previewRows" :key="index" class="grid gap-1 px-3 py-2">
                                    <div class="flex items-start justify-between gap-2"><span class="min-w-0 break-words font-medium text-gray-800 dark:text-white">@{{ row.name }} <span class="font-normal text-gray-400">#@{{ row.asset_id }}</span></span><span :class="outcomeClass(row.outcome)" class="shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 font-medium">@{{ outcomeLabel(row.outcome) }}</span></div>
                                    <span class="break-words text-gray-600 dark:text-gray-300">@{{ actionTypeLabel(row.type) }} · <span class="font-mono">@{{ row.target }}</span></span>
                                    <span class="break-words text-gray-700 dark:text-gray-200"><span class="text-gray-500">@{{ shown(row.before) }}</span> → <span class="font-medium">@{{ shown(row.after ?? row.value) }}</span></span>
                                    <span v-if="row.reason" class="text-gray-500 dark:text-gray-400">@{{ reasonLabel(row.reason) }}</span>
                                </li>
                            </ul>
                            <div v-if="previewRows.length" class="mt-2 hidden overflow-x-auto sm:block rounded-lg border border-gray-200 dark:border-cherry-800">
                                <table class="w-full min-w-[36rem] text-left text-xs">
                                    <thead class="bg-gray-50 text-gray-600 dark:bg-cherry-800 dark:text-gray-300">
                                        <tr>
                                            <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.asset')</th>
                                            <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.action')</th>
                                            <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.change')</th>
                                            <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.outcome')</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-cherry-800">
                                        <tr v-for="(row, index) in previewRows" :key="index" class="align-top">
                                            <td class="max-w-[12rem] px-3 py-2"><span class="block truncate text-gray-800 dark:text-white" :title="row.name">@{{ row.name }}</span><span class="text-gray-400">#@{{ row.asset_id }}</span></td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">@{{ actionTypeLabel(row.type) }}<span class="block break-words font-mono text-gray-500">@{{ row.target }}</span></td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200"><span class="text-gray-500">@{{ shown(row.before) }}</span> → <span class="font-medium">@{{ shown(row.after) }}</span></td>
                                            <td class="px-3 py-2"><span :class="outcomeClass(row.outcome)" class="whitespace-nowrap rounded-full px-2 py-0.5 font-medium">@{{ outcomeLabel(row.outcome) }}</span><span v-if="row.reason" class="mt-1 block text-gray-500 dark:text-gray-400">@{{ reasonLabel(row.reason) }}</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                </section>

                <section
                    v-else-if="log"
                    ref="log"
                    class="order-first min-w-0 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-cherry-900 lg:order-last"
                    data-rule-log
                    :aria-label="logTitle"
                    tabindex="-1"
                >
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="min-w-0 break-words text-base font-semibold text-gray-800 dark:text-white">@{{ logTitle }}</h2>
                        <button type="button" class="transparent-button shrink-0" @click="log = null">@lang('dam::app.admin.automation.rules.log.close')</button>
                    </div>
                    <p v-if="log.loading" class="mt-4 text-sm text-gray-500">@lang('dam::app.admin.automation.loading')</p>
                    <p v-else-if="!log.runs.length" class="mt-4 text-sm text-gray-500">@lang('dam::app.admin.automation.rules.log.empty')</p>
                    <ul v-if="!log.loading && log.runs.length" class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 text-xs sm:hidden dark:divide-cherry-800 dark:border-cherry-800">
                        <li v-for="run in log.runs" :key="run.id" class="flex items-start justify-between gap-3 px-3 py-2" :class="{ 'bg-primary-50/60 dark:bg-primary-900/20': log.detail && log.detail.run.id === run.id }">
                            <div class="grid min-w-0 gap-0.5 text-gray-700 dark:text-gray-200">
                                <span class="font-medium text-gray-800 dark:text-white">#@{{ run.id }} · @{{ versionLabel(run.rule_version) }} · @{{ statusLabel(run.status) }}</span>
                                <span>@{{ triggerLabel(run.trigger_type) }} · @{{ formatDate(run.started_at) }}</span>
                                <span class="tabular-nums text-gray-500">@lang('dam::app.admin.automation.rules.log.counts'): @{{ run.matched }} / @{{ run.written }} / @{{ run.skipped }} / @{{ run.refused }}</span>
                            </div>
                            <button type="button" class="shrink-0 rounded-md px-2 py-1 font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-cherry-800" :aria-expanded="log.detail && log.detail.run.id === run.id ? 'true' : 'false'" @click="openRun(run)">@lang('dam::app.admin.automation.rules.log.details')</button>
                        </li>
                    </ul>
                    <div v-if="!log.loading && log.runs.length" class="mt-4 hidden overflow-x-auto rounded-lg border border-gray-200 sm:block dark:border-cherry-800">
                        <table class="w-full min-w-[34rem] text-left text-xs">
                            <thead class="bg-gray-50 text-gray-600 dark:bg-cherry-800 dark:text-gray-300">
                                <tr>
                                    <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.rules.log.run')</th>
                                    <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.rules.log.trigger')</th>
                                    <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.rules.log.started')</th>
                                    <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.rules.log.counts')</th>
                                    <th scope="col" class="px-3 py-2 font-medium"><span class="sr-only">@lang('dam::app.admin.automation.rules.log.details')</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-cherry-800">
                                <tr v-for="run in log.runs" :key="run.id" :class="{ 'bg-primary-50/60 dark:bg-primary-900/20': log.detail && log.detail.run.id === run.id }">
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-800 dark:text-white">#@{{ run.id }} · @{{ versionLabel(run.rule_version) }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">@{{ triggerLabel(run.trigger_type) }}<span class="block text-gray-500">@{{ statusLabel(run.status) }}</span></td>
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-200">@{{ formatDate(run.started_at) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums text-gray-700 dark:text-gray-200">@{{ run.matched }} / @{{ run.written }} / @{{ run.skipped }} / @{{ run.refused }}</td>
                                    <td class="px-3 py-2 text-right"><button type="button" class="rounded-md px-2 py-1 font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-cherry-800" :aria-expanded="log.detail && log.detail.run.id === run.id ? 'true' : 'false'" @click="openRun(run)" :data-rule-log-run="run.id">@lang('dam::app.admin.automation.rules.log.details')</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="log.detail" class="mt-5" data-rule-log-detail aria-live="polite">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white">@{{ runActionsTitle }}</h3>
                        <p v-if="!log.detail.actions.length" class="mt-2 text-sm text-gray-500">@lang('dam::app.admin.automation.rules.log.none')</p>
                        <ul v-if="log.detail.actions.length" class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 text-xs sm:hidden dark:divide-cherry-800 dark:border-cherry-800">
                                <li v-for="(row, index) in log.detail.actions" :key="row.asset_id + ':' + row.index" class="grid gap-1 px-3 py-2">
                                    <div class="flex items-start justify-between gap-2"><span class="min-w-0 break-words font-medium text-gray-800 dark:text-white">@{{ row.name }} <span class="font-normal text-gray-400">#@{{ row.asset_id }}</span></span><span :class="outcomeClass(row.outcome)" class="shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 font-medium">@{{ outcomeLabel(row.outcome) }}</span></div>
                                    <span class="break-words text-gray-600 dark:text-gray-300">@{{ actionTypeLabel(row.type) }} · <span class="font-mono">@{{ row.target }}</span></span>
                                    <span class="break-words text-gray-700 dark:text-gray-200"><span class="text-gray-500">@{{ shown(row.before) }}</span> → <span class="font-medium">@{{ shown(row.after ?? row.value) }}</span></span>
                                    <span v-if="row.reason" class="text-gray-500 dark:text-gray-400">@{{ reasonLabel(row.reason) }}</span>
                                </li>
                            </ul>
                            <div v-if="log.detail.actions.length" class="mt-2 hidden overflow-x-auto sm:block rounded-lg border border-gray-200 dark:border-cherry-800">
                            <table class="w-full min-w-[34rem] text-left text-xs">
                                <thead class="bg-gray-50 text-gray-600 dark:bg-cherry-800 dark:text-gray-300">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.rules.log.asset')</th>
                                        <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.rules.log.action')</th>
                                        <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.change')</th>
                                        <th scope="col" class="px-3 py-2 font-medium">@lang('dam::app.admin.automation.dry-run.outcome')</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-cherry-800">
                                    <tr v-for="row in log.detail.actions" :key="row.asset_id + ':' + row.index" class="align-top">
                                        <td class="max-w-[12rem] px-3 py-2"><span class="block truncate text-gray-800 dark:text-white" :title="row.name">@{{ row.name }}</span><span class="text-gray-400">#@{{ row.asset_id }}</span></td>
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-200">@{{ actionTypeLabel(row.type) }}<span class="block break-words font-mono text-gray-500">@{{ row.target }}</span></td>
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-200"><span class="text-gray-500">@{{ shown(row.before) }}</span> → <span class="font-medium">@{{ shown(row.after) }}</span></td>
                                        <td class="px-3 py-2"><span :class="outcomeClass(row.outcome)" class="whitespace-nowrap rounded-full px-2 py-0.5 font-medium">@{{ outcomeLabel(row.outcome) }}</span><span v-if="row.reason" class="mt-1 block text-gray-500 dark:text-gray-400">@{{ reasonLabel(row.reason) }}</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-cherry-900" data-rule-list>
                    <p v-if="!rules.length" class="p-6 text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.automation.rules.empty')</p>

                    <ul v-else class="divide-y divide-gray-100 dark:divide-cherry-800">
                        <li
                            v-for="rule in rules"
                            :key="rule.id"
                            class="grid gap-2 px-4 py-3"
                            :class="{ 'bg-primary-50/60 dark:bg-primary-900/20': (draft && draft.id === rule.id) || (log && log.rule.id === rule.id) }"
                            :data-rule-row="rule.id"
                        >
                            <div class="flex items-start gap-3">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="rule.enabled ? 'true' : 'false'"
                                    :aria-label="toggleLabel(rule)"
                                    class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
                                    :class="rule.enabled ? 'bg-primary-600' : 'bg-gray-300 dark:bg-cherry-700'"
                                    @click="toggle(rule)"
                                    data-rule-toggle
                                >
                                    <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform" :class="rule.enabled ? 'translate-x-4' : 'translate-x-0.5'"></span>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-gray-800 dark:text-white" :title="rule.name">@{{ rule.name }}</p>
                                    <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">@{{ triggerText(rule) }} → @{{ rule.actions.map(action => actionTypeLabel(action.type)).join(', ') }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-cherry-800 dark:text-gray-300">@{{ versionLabel(rule.version) }}</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 pl-12 text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="rule.owner_name">@{{ ownerLabel(rule) }}</span>
                                <span>· @{{ lastRunText(rule) }}</span>
                                <span v-if="rule.runs_count">· @{{ runsCountLabel(rule.runs_count) }}</span>
                            </div>
                            <div class="flex flex-wrap gap-1 pl-10">
                                <button type="button" class="rounded-md px-2.5 py-1 text-sm font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-cherry-800" :data-rule-edit="rule.id" @click="startEdit(rule)">@lang('dam::app.admin.automation.edit')</button>
                                <button type="button" class="rounded-md px-2.5 py-1 text-sm font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-cherry-800" :data-rule-log-open="rule.id" @click="openLog(rule)">@lang('dam::app.admin.automation.rules.log.button')</button>
                                <button type="button" class="rounded-md px-2.5 py-1 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-cherry-800" @click="remove(rule)">@lang('dam::app.admin.automation.delete')</button>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </script>

    <script type="module">
    app.component('v-dam-automation-rules', {
        template: '#v-dam-automation-rules-template',
        props: ['initial'],

        data() {
            return {
                rules: this.initial.rules,
                draft: null,
                log: null,
                busy: false,
                errors: {},
                selection: { ids: '', folder: null },
                preview: null,
                previewBusy: false,
                runBusy: false,
            };
        },

        computed: {
            hasSelection() { return this.selection.folder !== null || this.selectionIds().length > 0; },
            previewRows() {
                return (this.preview || []).flatMap(asset => asset.actions.map(action => ({ ...action, asset_id: asset.asset_id, name: asset.name })));
            },
            previewSummary() {
                const count = outcome => this.previewRows.filter(row => row.outcome === outcome).length;
                return @js(trans('dam::app.admin.automation.dry-run.summary'))
                    .replace(':writes', count('written')).replace(':skipped', count('skipped')).replace(':refused', count('refused')).replace(':assets', (this.preview || []).length);
            },
            logTitle() { return this.log ? @js(trans('dam::app.admin.automation.rules.log.title')).replace(':name', () => this.log.rule.name) : ''; },
            runActionsTitle() { return this.log?.detail ? @js(trans('dam::app.admin.automation.rules.log.actions')).replace(':id', this.log.detail.run.id) : ''; },
        },

        methods: {
            triggerLabel(trigger) { return @js(trans('dam::app.admin.automation.rules.triggers'))[trigger] ?? trigger; },
            conditionTypeLabel(type) { return @js(trans('dam::app.admin.automation.rules.condition-types'))[type] ?? type; },
            actionTypeLabel(type) { return @js(trans('dam::app.admin.automation.rules.action-types'))[type] ?? type; },
            opLabel(op) { return @js(trans('dam::app.admin.automation.rules.ops'))[op] ?? op; },
            statusLabel(status) { return @js(trans('dam::app.admin.automation.rules.log.statuses'))[status] ?? status; },
            outcomeLabel(outcome) { return @js(trans('dam::app.admin.automation.outcomes'))[outcome] ?? outcome; },
            reasonLabel(reason) { return @js(trans('dam::app.admin.automation.reasons'))[reason] ?? reason; },
            versionLabel(version) { return @js(trans('dam::app.admin.automation.version')).replace(':n', version); },
            conditionLabel(index) { return @js(trans('dam::app.admin.automation.rules.form.condition-n')).replace(':n', index + 1); },
            actionLabel(index) { return @js(trans('dam::app.admin.automation.rules.form.action-n')).replace(':n', index + 1); },
            toggleLabel(rule) { return @js(trans('dam::app.admin.automation.toggle')).replace(':name', () => rule.name); },
            ownerLabel(rule) { return @js(trans('dam::app.admin.automation.rules.owner')).replace(':name', () => rule.owner_name); },
            runsCountLabel(count) {
                const [one, many] = @js(trans('dam::app.admin.automation.rules.runs-count')).split('|');
                return (count === 1 ? one : many).replace(':count', count);
            },
            formatDate(value) { return value ? new Date(value).toLocaleString() : ''; },
            shown(value) { return value === null || value === undefined || value === '' ? @js(trans('dam::app.admin.automation.dry-run.empty-value')) : value; },
            outcomeClass(outcome) {
                return { written: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200', skipped: 'bg-gray-100 text-gray-600 dark:bg-cherry-800 dark:text-gray-300', refused: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' }[outcome];
            },
            triggerText(rule) {
                const field = rule.trigger_type === 'field_changed' && rule.trigger_field_id ? this.initial.fields.find(f => f.id === rule.trigger_field_id) : null;
                return this.triggerLabel(rule.trigger_type) + (field ? ': ' + field.label : '');
            },
            lastRunText(rule) {
                const run = rule.last_run;
                if (!run) return @js(trans('dam::app.admin.automation.never-run'));
                return @js(trans('dam::app.admin.automation.rules.last-run')).replace(':date', this.formatDate(run.started_at))
                    .replace(':written', run.written).replace(':skipped', run.skipped).replace(':refused', run.refused);
            },
            focusPanel(ref) { this.$nextTick(() => { const el = this.$refs[ref]; (ref === 'editor' ? el?.querySelector('input') : el)?.focus(); }); },
            startCreate() {
                this.errors = {};
                this.preview = null;
                this.log = null;
                this.draft = { id: null, version: 1, name: '', enabled: true, trigger_type: 'ingest', trigger_field_id: null, conditions: { match: 'all', conditions: [] }, actions: [{ type: 'add_tag', value: '' }] };
                this.focusPanel('editor');
            },
            startEdit(rule) {
                this.errors = {};
                this.preview = null;
                this.log = null;
                const draft = JSON.parse(JSON.stringify(rule));
                draft.conditions = draft.conditions && draft.conditions.conditions ? draft.conditions : { match: 'all', conditions: [] };
                // Extension lists edit as comma-separated text.
                draft.conditions.conditions = draft.conditions.conditions.map(c => c.type === 'extension' ? { ...c, text: (c.values || []).join(', ') } : c);
                this.draft = draft;
                this.focusPanel('editor');
            },
            cancel() { this.draft = null; this.errors = {}; this.preview = null; },
            addCondition() { this.draft.conditions.conditions.push({ type: 'extension', text: '' }); },
            resetCondition(condition) {
                Object.keys(condition).forEach(key => key !== 'type' && delete condition[key]);
                if (condition.type === 'field') Object.assign(condition, { field_id: null, op: 'eq', value: '' });
                if (condition.type === 'exif') Object.assign(condition, { key: '', op: 'eq', value: '' });
                if (['folder', 'has_label'].includes(condition.type)) condition[condition.type === 'folder' ? 'directory_id' : 'label_id'] = null;
            },
            resetAction(action) {
                Object.keys(action).forEach(key => key !== 'type' && delete action[key]);
                const blanks = { add_tag: { value: '' }, set_field: { field_id: null, value: '' }, add_label: { label_id: null }, add_to_section: { section_id: null }, add_to_collection: { collection_id: null } };
                Object.assign(action, blanks[action.type] || {});
            },
            moveAction(index, direction) {
                const actions = this.draft.actions;
                actions.splice(index + direction, 0, ...actions.splice(index, 1));
            },
            payload() {
                const conditions = this.draft.conditions.conditions.map(c => {
                    if (c.type !== 'extension') return c;
                    const { text, ...rest } = c;
                    return { ...rest, values: String(text || '').split(/[\s,]+/).map(v => v.replace(/^\./, '').toLowerCase()).filter(Boolean) };
                });
                return {
                    name: this.draft.name, enabled: this.draft.enabled, trigger_type: this.draft.trigger_type,
                    trigger_field_id: this.draft.trigger_type === 'field_changed' ? this.draft.trigger_field_id : null,
                    conditions: { match: this.draft.conditions.match, conditions }, actions: this.draft.actions,
                };
            },
            selectionIds() { return String(this.selection.ids).split(/[\s,]+/).map(id => parseInt(id, 10)).filter(id => id > 0); },
            selectionBody() { return this.selection.folder !== null ? { directory_id: this.selection.folder } : { asset_ids: this.selectionIds() }; },
            showErrors(error, prefix = '') {
                const errors = error.response?.data?.errors || {};
                this.errors = {};
                Object.entries(errors).forEach(([key, messages]) => {
                    key = prefix && key.startsWith(prefix) ? key.slice(prefix.length) : key;
                    const parts = key.split('.');
                    const name = parts[0] === 'conditions' && parts.length >= 3 ? parts.slice(0, 3).join('.')
                        : (parts[0] === 'actions' && parts.length >= 2 ? parts.slice(0, 2).join('.')
                        : (['asset_ids', 'directory_id'].includes(parts[0]) ? 'selection' : parts[0]));
                    this.errors[name] = [].concat(messages).join(' ');
                });
                if (!Object.keys(errors).length) this.errors.general = error.response?.data?.message || @js(trans('dam::app.admin.automation.error'));
            },
            replaceRule(rule) {
                const index = this.rules.findIndex(r => r.id === rule.id);
                index < 0 ? this.rules.push(rule) : this.rules.splice(index, 1, rule);
            },
            async refreshRule(id) {
                const { data } = await this.$axios.get(@js(route('admin.dam.configuration.rules.index')), { headers: { Accept: 'application/json' } });
                this.rules = data.rules;
                return this.rules.find(r => r.id === id);
            },
            async save() {
                if (this.busy) return;
                this.busy = true;
                try {
                    const { data } = this.draft.id
                        ? await this.$axios.put(@js(route('admin.dam.configuration.rules.update', '__id__')).replace('__id__', this.draft.id), this.payload())
                        : await this.$axios.post(@js(route('admin.dam.configuration.rules.store')), this.payload());
                    this.replaceRule(data.rule);
                    this.startEdit(data.rule);
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                } catch (error) {
                    this.showErrors(error);
                } finally {
                    this.busy = false;
                }
            },
            async toggle(rule) {
                try {
                    const { data } = await this.$axios.put(@js(route('admin.dam.configuration.rules.update', '__id__')).replace('__id__', rule.id), { enabled: !rule.enabled });
                    this.replaceRule(data.rule);
                    if (this.draft?.id === rule.id) this.draft.enabled = data.rule.enabled;
                } catch (error) {
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || @js(trans('dam::app.admin.automation.error')) });
                }
            },
            async dryRun() {
                if (this.previewBusy) return;
                this.previewBusy = true;
                delete this.errors.selection;
                try {
                    const { data } = await this.$axios.post(@js(route('admin.dam.configuration.rules.dry_run')), { id: this.draft.id, rule: this.payload(), ...this.selectionBody() });
                    this.preview = data.assets;
                } catch (error) {
                    this.showErrors(error, 'rule.');
                } finally {
                    this.previewBusy = false;
                }
            },
            async runNow() {
                if (this.runBusy || !this.draft.id) return;
                this.runBusy = true;
                delete this.errors.selection;
                try {
                    const { data } = await this.$axios.post(@js(route('admin.dam.configuration.rules.run', '__id__')).replace('__id__', this.draft.id), this.selectionBody());
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                    const rule = await this.refreshRule(this.draft.id);
                    if (rule) this.openLog(rule, data.run);
                } catch (error) {
                    this.showErrors(error);
                } finally {
                    this.runBusy = false;
                }
            },
            async openLog(rule, run = null) {
                this.draft = null;
                this.preview = null;
                this.log = { rule, runs: [], loading: true, detail: null };
                this.focusPanel('log');
                try {
                    const { data } = await this.$axios.get(@js(route('admin.dam.configuration.rules.runs', '__id__')).replace('__id__', rule.id));
                    this.log.runs = data.runs;
                    this.log.loading = false;
                    if (run || data.runs.length) await this.openRun(run || data.runs[0]);
                } catch (error) {
                    this.log.loading = false;
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || @js(trans('dam::app.admin.automation.error')) });
                }
            },
            async openRun(run) {
                try {
                    const url = @js(route('admin.dam.configuration.rules.runs.show', ['__rule__', '__run__'])).replace('__rule__', this.log.rule.id).replace('__run__', run.id);
                    const { data } = await this.$axios.get(url);
                    this.log.detail = data;
                } catch (error) {
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || @js(trans('dam::app.admin.automation.error')) });
                }
            },
            remove(rule) {
                this.$emitter.emit('open-confirm-modal', {
                    message: @js(trans('dam::app.admin.automation.confirm-delete')).replace(':name', () => rule.name),
                    options: { btnAgree: @js(trans('dam::app.admin.automation.delete')), btnDisagree: @js(trans('dam::app.admin.automation.cancel')), btnAgreeClass: 'danger-button', btnDisagreeClass: 'transparent-button' },
                    agree: async () => {
                        try {
                            const { data } = await this.$axios.delete(@js(route('admin.dam.configuration.rules.destroy', '__id__')).replace('__id__', rule.id));
                            this.rules = this.rules.filter(r => r.id !== rule.id);
                            if (this.draft?.id === rule.id) this.cancel();
                            if (this.log?.rule.id === rule.id) this.log = null;
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
