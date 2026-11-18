<aside
    v-show="isInfoPanelOpen"
    class="w-[380px] max-w-[45vw] max-md:absolute max-md:inset-y-0 max-md:right-0 max-md:z-20 max-md:w-full max-md:max-w-full shrink-0 overflow-y-auto border-l border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-xl"
    data-dam-info-panel
>
    <div class="sticky top-0 z-10 flex items-center gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-900">
        <span class="icon-information text-xl text-primary-600 dark:text-primary-400"></span>
        <p class="flex-1 text-sm font-semibold text-gray-800 dark:text-white">
            {{ trans('dam::app.admin.dam.asset.edit.preview-modal.file-information') }}
        </p>
        <button
            type="button"
            class="flex items-center justify-center w-8 h-8 rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
            aria-label="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.close-info') }}"
            @click="toggleInfoPanel"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    {{-- D5: a stacked file opened from the fan; the details below still describe the stack's cover file. --}}
    <p v-if="previewData.variantFileId" class="mx-5 mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-200" data-dam-info-variant-note>
        @{{ @js(trans('dam::app.admin.dam.asset.stack-fan.info-describes-cover')).replace(':name', previewData.coverName || previewData.display_name || previewData.file_name) }}
    </p>

    <div v-if="isInfoLoading" class="flex items-center justify-center gap-2 px-5 py-10 text-sm text-gray-500">
        <span class="h-5 w-5 animate-spin rounded-full border-2 border-primary-600 border-t-transparent"></span>
        {{ trans('dam::app.admin.dam.asset.edit.preview-modal.loading-info') }}
    </div>

    <div v-else-if="infoError" class="m-5 rounded-lg bg-red-50 dark:bg-red-950/30 p-4 text-sm text-red-700 dark:text-red-300">
        @{{ infoError }}
    </div>

    <div v-else-if="infoData" class="px-5 pb-6">
        <!-- P4a (D9): typed custom fields, editable by editors; the badge counts required fields still empty. -->
        <v-dam-field-editor
            v-if="infoData.fields?.length"
            class="!pt-3 pb-2"
            :asset-id="infoAssetId ?? previewData?.id"
            :fields="infoData.fields"
            :missing="infoData.missing_required ?? []"
            :can-edit="!! infoData.can_edit_fields"
            @saved="onInfoFieldsSaved"
        ></v-dam-field-editor>
        <p v-if="infoData.details_note && infoData.fields?.length" class="pb-2 text-xs text-gray-500 dark:text-gray-400" data-dam-details-note>@{{ infoData.details_note }}</p>

        <dl v-if="infoData.detail_fields !== null && infoData.detail_fields !== undefined" class="divide-y divide-gray-100 dark:divide-gray-800">
            <div v-for="field in infoData.detail_fields" :key="field.key" class="grid grid-cols-2 gap-4 py-3">
                <dt class="text-xs text-gray-500">@{{ field.label }}</dt>
                <dd class="text-xs text-right break-words">@{{ formatInfoValue(field.value) || '—' }}</dd>
            </div>
            <p v-if="!infoData.detail_fields.length" class="py-4 text-sm text-gray-500">No selected fields have values.</p>
        </dl>
        <template v-else>
        <dl class="divide-y divide-gray-100 dark:divide-gray-800">
            <div v-for="field in infoBuiltinFields" :key="field.label" v-show="field.value !== null && field.value !== ''" class="grid grid-cols-[minmax(100px,0.8fr)_minmax(0,1.2fr)] gap-4 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">@{{ field.label }}</dt>
                <dd class="text-xs font-medium text-gray-800 dark:text-gray-100 text-right break-words">@{{ field.value }}</dd>
            </div>
        </dl>

        <div v-if="infoData.directory_breadcrumb?.length" class="pt-5">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ trans('dam::app.admin.dam.asset.edit.preview-modal.folder-path') }}
            </h3>
            <nav class="flex flex-wrap items-center gap-1 text-xs" aria-label="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.folder-path') }}">
                <template v-for="(crumb, index) in infoData.directory_breadcrumb" :key="crumb.id">
                    <span v-if="index" class="text-gray-400">/</span>
                    <a :href="'{{ route('admin.dam.assets.index') }}?directory_id=' + crumb.id" class="text-primary-700 dark:text-primary-300 hover:underline">@{{ crumb.name }}</a>
                </template>
            </nav>
        </div>

        <div v-if="infoData.tags?.length" class="pt-5">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                @{{ infoStackLabel(@js(trans('dam::app.admin.dam.asset.edit.tags'))) }}
            </h3>
            <div class="flex flex-wrap gap-1.5">
                <span v-for="tag in infoData.tags" :key="tag.id" class="rounded-full bg-primary-50 dark:bg-primary-900/40 px-2.5 py-1 text-xs font-medium text-primary-700 dark:text-primary-200">@{{ tag.name }}</span>
            </div>
        </div>

        {{-- A file whose picture is still being made: say what the AI is waiting for. --}}
        <p v-if="infoData.ai_eligibility?.state === 'awaiting_preview'" class="pt-5 text-xs text-gray-400" data-dam-ai-awaiting>@{{ infoData.ai_eligibility.message }}</p>

        <div v-if="infoData.ai" class="pt-5 space-y-3" data-dam-ai-info>
            <p v-if="infoData.ai.status === 'pending' || infoData.ai.status === 'running'" class="text-xs text-gray-400">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.ai-pending') }}</p>
            <p v-else-if="infoData.ai.status === 'failed'" class="text-xs text-gray-400">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.ai-failed') }}</p>

            <div v-if="infoData.ai.caption">
                <h3 class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ trans('dam::app.admin.dam.asset.edit.preview-modal.ai-caption') }}
                    <span class="rounded bg-violet-100 dark:bg-violet-900/40 px-1 py-0.5 text-[10px] font-semibold text-violet-700 dark:text-violet-200">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.ai-badge') }}</span>
                </h3>
                <p class="text-xs text-gray-700 dark:text-gray-200">@{{ infoData.ai.caption }}</p>
            </div>

            <div v-if="infoData.ai.tags?.length">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.ai-tags') }}</h3>
                    <button v-if="infoCanReviewAi" type="button" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-300" data-dam-ai-accept-all @click="reviewAiTag(null, 'accept-all')">{{ trans('dam::app.admin.dam.asset.edit.ai.accept-all') }}</button>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <span v-for="tag in infoData.ai.tags" :key="tag.term" class="inline-flex items-center gap-1 rounded-full border border-dashed border-violet-300 dark:border-violet-700 px-2.5 py-1 text-xs font-medium text-violet-700 dark:text-violet-200" data-dam-ai-tag>
                        <span class="text-[10px] font-semibold">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.ai-badge') }}</span>
                        @{{ tag.label }}
                        <template v-if="infoCanReviewAi">
                            <button type="button" class="hover:text-green-700" :aria-label="@js(trans('dam::app.admin.dam.asset.edit.preview-modal.ai-accept')) + ' ' + tag.label" :title="@js(trans('dam::app.admin.dam.asset.edit.preview-modal.ai-accept'))" data-dam-ai-accept @click="reviewAiTag(tag.term, 'accept')">✓</button>
                            <button type="button" class="hover:text-red-700" :aria-label="@js(trans('dam::app.admin.dam.asset.edit.preview-modal.ai-dismiss')) + ' ' + tag.label" :title="@js(trans('dam::app.admin.dam.asset.edit.preview-modal.ai-dismiss'))" data-dam-ai-dismiss @click="reviewAiTag(tag.term, 'dismiss')">×</button>
                        </template>
                    </span>
                </div>
            </div>

            <div v-if="infoData.ai.keywords?.length">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.ai-suggestions') }}</h3>
                <p class="text-xs text-gray-600 dark:text-gray-300">@{{ infoData.ai.keywords.join(', ') }}</p>
            </div>
        </div>

        <div class="pt-5">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                @{{ infoStackLabel(@js(trans('dam::app.admin.dam.asset.edit.preview-modal.properties'))) }}
            </h3>
            <dl v-if="infoData.properties?.length" class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800">
                <div v-for="(property, index) in infoData.properties" :key="index" class="grid grid-cols-[minmax(100px,0.8fr)_minmax(0,1.2fr)] gap-4 px-3 py-2.5">
                    <dt class="text-xs text-gray-500 dark:text-gray-400 break-words">@{{ property.name }}</dt>
                    <dd class="text-xs font-medium text-gray-800 dark:text-gray-100 text-right break-words">@{{ formatInfoValue(property.value) }}</dd>
                </div>
            </dl>
            <p v-else class="text-xs text-gray-400">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.no-properties') }}</p>
        </div>
        </template>
    </div>
</aside>
