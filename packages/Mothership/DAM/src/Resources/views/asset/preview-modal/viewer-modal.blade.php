@include('dam::components.shared.name-tail')
@include('dam::asset.preview-modal.viewer-layout')
@include('dam::components.explorer.member-download')
<div
    v-if="isOpen"
    class="fixed inset-0 z-[10010] flex items-center justify-center p-4"
    style="background: rgba(3, 7, 18, 0.88);"
    data-dam-preview-backdrop
    @click.self="closePreviewWithInfoReset"
>
    <section
        class="relative min-w-0 max-w-full flex flex-col overflow-hidden rounded-xl bg-white dark:bg-gray-900 shadow-2xl focus:outline-none"
        :style="{ width: ((previewData.extension || '').toLowerCase() === 'pdf' || previewData.adobe?.pdf) ? 'calc(100vw - 2rem)' : 'min(96vw, 1600px)', height: 'calc(100vh - 2rem)' }"
        data-dam-preview-dialog
        role="dialog"
        aria-modal="true"
        tabindex="-1"
        :aria-label="(previewData.display_name || previewData.file_name)"
        @click.stop
    >
        <div data-dam-preview-header class="relative flex flex-wrap items-center gap-x-2 lg:gap-x-3 gap-y-2 px-5 py-2 lg:py-3 pr-16 sm:pr-20 shrink-0 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900" style="min-height: 4rem;">
            <button
                type="button"
                class="min-w-0 max-w-full flex items-center gap-2 min-h-10 px-3 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                data-dam-preview-back
                :aria-label="previewBackLabel"
                :title="previewBackLabel"
                @click="closePreviewWithInfoReset"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M19 12H5"></path>
                    <path d="M12 19l-7-7 7-7"></path>
                </svg>
                <span class="truncate max-w-[16rem]" data-dam-preview-label>@{{ previewBackLabel }}</span>
            </button>

            <span class="shrink-0 px-2 py-0.5 rounded text-xs font-semibold" :class="previewData.typeColor" data-dam-preview-ext>
                @{{ previewData.extension_upper }}
            </span>

            {{-- D5: one file of a stack, opened from the stack's fan, names itself first and its stack second. --}}
            <div v-if="previewData.variantLabel || defaultFileLabel()" class="order-last basis-full min-w-0 text-sm" data-dam-preview-variant>
                <p class="font-semibold text-gray-800 dark:text-white [overflow-wrap:anywhere]" :title="previewData.variantLabel || defaultFileLabel()">@{{ previewData.variantLabel || defaultFileLabel() }}<template v-if="! previewData.variantLabel"> · @lang('dam::app.admin.dam.asset.files.representative')</template></p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 [overflow-wrap:anywhere]" :title="previewData.display_name || previewData.file_name">@{{ @js(trans('dam::app.admin.dam.asset.stack-fan.in-stack')).replace(':name', previewData.display_name || previewData.file_name) }}</p>
            </div>
            {{-- VIS-04/VIS-09: under sm the title takes its own full-width line (order-last keeps the toolbar buttons beside the back button); a long name is cut in the middle so the extension stays visible. --}}
            <p v-else class="order-last basis-full min-w-0 text-sm font-semibold text-gray-800 dark:text-white [overflow-wrap:anywhere]" :title="previewData.display_name || previewData.file_name" data-dam-preview-title>@{{ previewData.display_name || previewData.file_name }}</p>

            {{-- The actions. A plain run of header items on a desktop (display: contents); on a phone, the Duo's
                 displays and short screens, one bar of their own (viewer-layout.blade.php). --}}
            <div data-dam-preview-tools>
            <span v-if="previewData.playbackLabel && ['video', 'audio', 'pano360-video'].includes(previewData.playbackKind)" data-dam-playback-label class="hidden lg:block shrink-0 text-xs text-gray-500 dark:text-gray-400">
                @lang('dam::app.admin.dam.asset.edit.preview-modal.playing') <strong class="font-semibold">@{{ previewData.playbackLabel }}</strong>
            </span>

            @if (bouncer()->hasPermission('dam.asset.files') && bouncer()->hasPermission('dam.asset.files.index'))
            <a v-if="previewData.filesUrl" :href="filesTabHref()" class="shrink-0 text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline" data-dam-preview-files-link title="@lang('dam::app.admin.dam.asset.edit.preview-modal.open-files')" @if (! ($fromDetailsPage ?? false)) @click="onOpenDetailsClick" @endif>
                <svg data-dam-compact-icon xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="8" width="13" height="13" rx="2"></rect><path d="M4 16V5a2 2 0 0 1 2-2h11"></path></svg>
                <span data-dam-preview-label>@lang('dam::app.admin.dam.asset.edit.preview-modal.open-files')</span>
            </a>
            @endif

            <span v-if="previewData.fileSize" class="shrink-0 text-xs text-gray-600 dark:text-gray-300 hidden sm:block" data-dam-preview-size>@{{ previewData.fileSize }}</span>

            {{--
                previewNeighbours only exists on v-dam-grid-preview-modal (the Library lightbox);
                v-asset-preview-modal (the asset-details page, via onDamAssetChanged) shares this
                partial but does not define it. Vue warns "accessed during render but is not
                defined on instance" for any reference to a property that isn't on the component
                at all, even behind `?.`, so $hasPreviewNavigation keeps this markup out of that
                consumer's compiled template entirely rather than just hiding it at runtime.
            --}}
            @if($hasPreviewNavigation ?? false)
                <span v-if="previewNeighbours.total" class="shrink-0 text-xs text-gray-600 dark:text-gray-300 hidden sm:block" data-dam-preview-position>
                    @{{ previewNeighbours.position }} {{ trans('dam::app.admin.dam.asset.edit.preview-modal.of') }} @{{ previewNeighbours.total }}
                </span>
            @endif

            {{--
                Every image preview surface except the Library context menu has no download at
                all (custom_download forces a required-width/height re-encode; the modal itself
                has no download link) — this partial is shared by both preview surfaces
                (grid-preview-modal.blade.php's lightbox and preview-modal.blade.php on the
                details page), so this single addition covers both, for every asset type.
                A real button beside Open details, not a text link: as a 12px link it was hard
                to find. Icon-only below lg, like Share, so the toolbar stays one row on a phone, landscape included.
            --}}
            @if (bouncer()->hasPermission('dam.asset.download'))
                <v-dam-member-download
                    v-if="previewData.variantFileId || (previewVariants.length > 1 && previewVariants.some(file => file.is_representative))"
                    :key="'download-' + (previewData.variantFileId || previewData.id)"
                    label-class="max-[1023px]:hidden"
                    data-dam-preview-download-as
                    :file="{ id: previewData.variantFileId || previewVariants.find(file => file.is_representative)?.id, asset_id: previewData.variantAssetId || previewData.id, file_name: previewData.variantName || previewData.file_name, extension: previewData.extension, file_type: previewData.file_type, thumbnail_url: previewData.downloadThumb }"
                ></v-dam-member-download>
            @endif
            <a
                v-if="previewData.downloadUrl"
                :href="previewData.downloadUrl"
                data-dam-download
                :data-dam-download-name="previewData.variantName || previewData.file_name"
                :data-dam-download-thumb="previewData.downloadThumb"
                class="shrink-0 flex items-center justify-center gap-2 min-h-10 min-w-10 px-2 min-[1024px]:px-3 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                data-dam-preview-download-original
                title="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.download-original') }}"
                aria-label="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.download-original') }}"
            >
                <span class="text-xl icon-dam-download" style="color: inherit;" aria-hidden="true"></span>
                <span class="max-[1023px]:hidden">{{ trans('dam::app.admin.dam.asset.edit.button.download') }}</span>
            </a>

            @unless($fromDetailsPage ?? false)
            <a
                v-if="previewData.editUrl"
                @if($hasPreviewNavigation ?? false)
                :href="openDetailsHref()"
                @click="onOpenDetailsClick"
                @else
                :href="previewData.editUrl"
                @endif
                class="primary-button shrink-0"
                data-dam-open-details
                title="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.open-details') }}"
            >
                <svg data-dam-compact-icon xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6"></path><path d="M10 14 21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg>
                <span data-dam-preview-label>{{ trans('dam::app.admin.dam.asset.edit.preview-modal.open-details') }}</span>
            </a>
            @endunless

            {{-- Same dialog as the asset page's Share button. Icon-only below lg so the header row still
                 fits 375px beside the close button; the label survives as aria-label/title. --}}
            @if (dam_access_lists() && bouncer()->hasPermission('dam.asset.share'))
            <button
                v-if="previewData.id"
                type="button"
                class="shrink-0 flex items-center justify-center gap-2 min-h-10 min-w-10 px-2 min-[1024px]:px-3 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                data-dam-preview-share
                :disabled="isAssetLocked"
                :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isAssetLocked }"
                title="{{ trans('dam::app.admin.dam.asset.edit.button.share') }}"
                aria-label="{{ trans('dam::app.admin.dam.asset.edit.button.share') }}"
                @click="$emitter.emit('open-share-modal', { targetType: 'asset', targetId: previewData.id })"
            >
                <span class="text-xl icon-dam-link" style="color: inherit;" aria-hidden="true"></span>
                <span class="max-[1023px]:hidden">{{ trans('dam::app.admin.dam.asset.edit.button.share') }}</span>
            </button>
            @endif

            {{-- Find similar answers in the Library explorer tab only: the Library page opts in with $showFindSimilar; the picker, asset fields, details page and classic datagrid do not. --}}
            @if (config('ai-index.search_enabled') && config('dam.explorer.enabled') && ($showFindSimilar ?? false) && ! ($fromDetailsPage ?? false))
            <button
                v-if="previewData.id"
                type="button"
                class="shrink-0 flex items-center justify-center gap-2 min-h-10 min-w-10 px-2 min-[1024px]:px-3 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                data-dam-preview-find-similar
                title="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.find-similar') }}"
                aria-label="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.find-similar') }}"
                @click="findSimilarFromPreview"
            >
                <span class="text-xl icon-search" style="color: inherit;" aria-hidden="true"></span>
                <span class="max-[1023px]:hidden">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.find-similar') }}</span>
            </button>
            @endif

            <button
                type="button"
                class="shrink-0 flex items-center justify-center gap-2 min-h-10 min-w-10 px-2 min-[1024px]:px-3 rounded-lg border text-sm font-semibold transition-colors"
                :class="isInfoPanelOpen ? 'border-primary-600 bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-200' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700'"
                data-dam-info-toggle
                title="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.info') }}"
                aria-label="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.info') }}"
                :aria-expanded="isInfoPanelOpen"
                @click="toggleInfoPanel"
            >
                <span class="icon-information" aria-hidden="true"></span>
                <span class="max-[1023px]:hidden">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.info') }}</span>
            </button>
            </div>

            <button
                type="button"
                class="absolute top-3 right-3 flex items-center justify-center w-10 h-10 rounded-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 shadow-lg hover:bg-primary-700 dark:hover:bg-primary-100 transition-colors"
                data-dam-preview-close
                @click="closePreviewWithInfoReset"
                title="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.close') }} (Esc)"
                aria-label="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.close') }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>

        <nav data-dam-preview-nav class="flex shrink-0 flex-wrap items-center justify-center gap-3 border-b border-gray-200 px-4 py-2 text-xs dark:border-gray-700" aria-label="Preview navigation">
            <div v-if="memberNavigation().total > 1" class="flex items-center gap-2" data-dam-member-navigation>
                {{-- A chevron stands in for the words on a phone and the Duo (viewer-layout.blade.php); the words stay the name. --}}
                <button type="button" class="min-h-10 rounded border px-3 disabled:opacity-40" :disabled="isLoading || ! memberNavigation().previous" @click="navigateMember({direction: -1})" data-dam-member-prev aria-label="Previous file"><svg data-dam-nav-glyph xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg><span data-dam-nav-text>Previous file</span></button>
                <span aria-live="polite">File @{{ memberNavigation().position }} of @{{ memberNavigation().total }}</span>
                <button type="button" class="min-h-10 rounded border px-3 disabled:opacity-40" :disabled="isLoading || ! memberNavigation().next" @click="navigateMember({direction: 1})" data-dam-member-next aria-label="Next file"><svg data-dam-nav-glyph xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"></path></svg><span data-dam-nav-text>Next file</span></button>
            </div>
            @if($hasPreviewNavigation ?? false)
                <div v-if="previewOrigin?.kind !== 'files'" class="flex items-center gap-2" data-dam-result-navigation>
                    <button type="button" class="min-h-10 rounded border px-3 disabled:opacity-40" :disabled="isLoading || previewNeighbours.previousId == null" @click="navigatePrev" data-dam-preview-prev aria-label="Previous Library result"><svg data-dam-nav-glyph xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg><span data-dam-nav-text>Previous Library result</span></button>
                    <button type="button" class="min-h-10 rounded border px-3 disabled:opacity-40" :disabled="isLoading || previewNeighbours.nextId == null" @click="navigateNext" data-dam-preview-next aria-label="Next Library result"><svg data-dam-nav-glyph xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"></path></svg><span data-dam-nav-text>Next Library result</span></button>
                </div>
                <button v-if="previewOrigin?.kind === 'chooser'" type="button" class="min-h-10 px-3 text-primary-600" @click="backToLibrary" data-dam-preview-back-library>Back to Library</button>
            @endif
        </nav>

        {{-- A stack's files: shown only when the entry holds more than one (P3a S4). --}}
        <v-dam-attachment-strip
            v-if="previewData.id"
            :key="'strip-' + previewData.id"
            :asset-id="Number(previewData.id)"
            :count-hint="previewData.attachment_count ?? null"
            :active-file-id="previewData.variantFileId ?? null"
            can-switch
            @open-file="switchStackedFile"
            @files-loaded="previewVariants = $event"
        ></v-dam-attachment-strip>
        <div :key="previewData.id + ':' + (previewData.variantFileId || 'cover')" data-dam-preview-stage class="relative flex-1 min-h-0 overflow-hidden flex bg-gray-50 dark:bg-gray-950">
            <div class="group relative flex-1 min-w-0 min-h-0 overflow-hidden flex items-center justify-center">
                <template v-if="previewData.file_type === 'web'">
                    <v-dam-web-page-frame :key="'web-' + previewData.id" :asset-id="previewData.id" :file-name="previewData.file_name || ''" exitable @dam-exit-preview="closePreviewWithInfoReset"></v-dam-web-page-frame>
                </template>

                <template v-else-if="previewData.gameAsset">
                    <v-dam-game-asset-preview :key="'game-' + previewData.id" :preview="previewData.gameAsset" :asset-id="previewData.id" :file-name="previewData.display_name || previewData.file_name || ''"></v-dam-game-asset-preview>
                </template>

                <template v-else-if="['3mf', 'glb', 'stl'].includes((previewData.extension || '').toLowerCase())">
                    <v-dam-model-viewer :key="previewData.downloadUrl" :url="previewData.downloadUrl" :extension="previewData.extension" :asset-id="previewData.variantFileId ? null : previewData.id" :file-id="previewData.variantFileId || null" :size-bytes="previewData.fileSizeBytes || 0" :size-label="previewData.fileSize || ''" :download-url="previewData.downloadUrl" :download-name="previewData.variantName || previewData.file_name || ''"></v-dam-model-viewer>
                </template>

                <template v-else-if="['pano360-video', 'pano360-image'].includes(previewData.playbackKind)">
                    @include('dam::asset.preview-modal.pano360.viewer')
                </template>

                <template v-else-if="previewData.playbackKind === 'video'">
                    @include('dam::asset.preview-modal.video.video-player')
                </template>

                <template v-else-if="((previewData.extension || '').toLowerCase() === 'pdf' || previewData.adobe?.pdf)">
                    @include('dam::asset.preview-modal.files.pdf-viewer')
                </template>

                <template v-else-if="previewData.playbackKind === 'image'">
                    @include('dam::asset.preview-modal.image.image-viewer')
                </template>

                <template v-else-if="previewData.file_type === 'audio'">
                    @include('dam::asset.preview-modal.audio.audio-player')
                </template>

                <template v-else-if="previewData.variantFileId && isTextFile(String(previewData.mime_type || ''), [previewVariant?.label, previewVariant?.file_name])">
                    <div data-dam-file-text class="flex h-full w-full flex-col p-4">
                        <div class="mb-2 flex items-center justify-between gap-3 text-xs text-gray-500">
                            <span>@{{ textState === 'loading' ? 'Loading text…' : textState === 'error' ? textError : (textTruncated ? 'Showing the first 1 MB. Download for the full file.' : 'Text preview') }}</span>
                            <button v-if="textState === 'ready'" type="button" class="min-h-10 text-primary-600" @click="copyText">@{{ textCopied ? 'Copied' : 'Copy' }}</button>
                        </div>
                        <pre v-if="textState === 'ready'" class="min-h-0 flex-1 overflow-auto whitespace-pre-wrap break-words rounded border p-3 font-mono text-xs">@{{ textContent }}</pre>
                    </div>
                </template>

                <template v-else>
                    @include('dam::asset.preview-modal.fallback')
                </template>
            </div>

            @include('dam::asset.preview-modal.info-panel')
        </div>


    </section>
</div>
