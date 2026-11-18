{{--
    The 360 build workflow strip: badge, the one action that makes sense right
    now (Open / Build / Rebuild / Retry), live progress with Cancel while an
    attempt runs, and — for users who may edit the asset — the mode override
    that decides whether this asset is treated as a panorama at all.

    Rendered wherever a panorama can be acted on (the inline card and the
    fallback panel), so it is written to be self-contained and to stay silent
    for anything that is not classified as a panorama.

    $panoramaSurface names the including surface — both can be on screen at
    once (card behind an open modal showing the fallback), so every strip
    stays individually addressable.
--}}
<div
    v-if="panoramaVisible && panoramaState.classification?.is_panorama"
    class="flex flex-wrap items-center gap-2 w-full px-3 py-2 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/60 text-xs"
    data-dam-panorama-status="{{ $panoramaSurface ?? 'card' }}"
>
    <span v-if="panoramaState.classification?.is_panorama" class="rounded-full bg-primary-600 px-2.5 py-0.5 text-[10px] font-bold text-white shadow-sm">
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.grid-badge')
    </span>

    <span v-if="panoramaState.rendition && panoramaState.rendition.status === 'queued'" class="font-semibold text-gray-600 dark:text-gray-300" data-dam-panorama-progress>
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.queued')
    </span>

    <span v-else-if="panoramaState.rendition && panoramaState.rendition.status === 'running'" class="font-semibold text-gray-600 dark:text-gray-300 tabular-nums" data-dam-panorama-progress>
        @{{ panoramaProgressLabel }}
    </span>

    <span v-else-if="panoramaState.rendition && panoramaState.rendition.status === 'failed'" class="font-semibold text-red-600 dark:text-red-400" data-dam-panorama-progress>
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.failed')
    </span>

    <span v-else-if="panoramaState.rendition && panoramaState.rendition.status === 'cancelled'" class="font-semibold text-gray-500 dark:text-gray-400" data-dam-panorama-progress>
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.cancelled')
    </span>

    <button
        v-if="panoramaCanOpen"
        type="button"
        class="rounded-lg bg-primary-600 px-3 py-1.5 font-semibold text-white hover:bg-primary-700"
        data-dam-360-open
        @click="openPreview"
    >
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.open')
    </button>

    <button
        v-if="panoramaCanEdit && panoramaCanBuild"
        type="button"
        class="rounded-lg bg-primary-600 px-3 py-1.5 font-semibold text-white hover:bg-primary-700 disabled:opacity-50"
        :disabled="panoramaBusy"
        data-dam-panorama-build
        @click="buildPanoramaPreview"
    >
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.build')
    </button>

    <button
        v-if="panoramaCanEdit && panoramaCanRebuild"
        type="button"
        class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-1.5 font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50"
        :disabled="panoramaBusy"
        data-dam-panorama-rebuild
        @click="rebuildPanoramaPreview"
    >
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.rebuild')
    </button>

    <button
        v-if="panoramaCanEdit && panoramaCanRetry"
        type="button"
        class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-1.5 font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50"
        :disabled="panoramaBusy"
        data-dam-panorama-retry
        @click="retryPanoramaPreview"
    >
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.retry')
    </button>

    <button
        v-if="panoramaCanEdit && panoramaCanCancel"
        type="button"
        class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-1.5 font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50"
        :disabled="panoramaBusy"
        data-dam-panorama-cancel
        @click="cancelPanoramaPreview"
    >
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.cancel')
    </button>

    <span v-if="panoramaProcessingUnavailable" class="text-gray-500 dark:text-gray-400" role="status">
            @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.processing-disabled')
    </span>

    @include('dam::asset.preview-modal.pano360.mode')

    {{-- A source that would not load is not a source the browser refused: the
         playback is still there to retry, and no build is offered for it. --}}
    <button
        v-if="panoramaLoadFailed"
        type="button"
        class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-1.5 font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800"
        data-dam-panorama-retry-playback
        @click="panoramaRetryPlayback"
    >
        @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.retry-playback')
    </button>

    <p v-if="panoramaError" class="w-full text-red-600 dark:text-red-400" role="status" data-dam-panorama-error>
        @{{ panoramaError }}
    </p>
</div>

{{-- Keep an explicit flat override reversible without mounting panorama status/actions. --}}
<div
    v-else-if="panoramaVisible && panoramaCanEdit"
    class="flex w-full px-3 py-2 text-xs"
    data-dam-panorama-override
>
    @include('dam::asset.preview-modal.pano360.mode')
</div>
