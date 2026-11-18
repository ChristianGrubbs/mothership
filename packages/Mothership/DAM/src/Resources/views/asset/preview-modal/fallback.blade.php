<div class="flex flex-col items-center gap-4 text-center p-8">
    {{-- A 360 source with no playable proxy lands here, so the build workflow
         has to be reachable from the fallback and not only from the card. --}}
    @include('dam::asset.preview-modal.pano360.status', ['panoramaSurface' => 'fallback'])

    <img
        :src="previewData.placeholderSvg"
        :alt="(previewData.display_name || previewData.file_name)"
        class="h-20 w-20 object-contain opacity-40"
    />
    <p class="text-sm text-gray-500 dark:text-gray-400 max-w-lg">
        <template v-if="(previewData.extension || '').toLowerCase() === 'osv'">
            @lang('dam::app.admin.dam.asset.edit.preview-modal.no-osv-preview')
        </template>
        <template v-else-if="previewData.playbackProcessing">
            @lang('dam::app.admin.dam.asset.edit.preview-modal.preview-processing')
        </template>
        <template v-else>
            @lang('dam::app.admin.dam.asset.edit.preview-modal.not-available')
        </template>
    </p>
    <div class="flex items-center gap-3">
        <a
            :href="previewData.downloadUrl"
            data-dam-download
            :data-dam-download-name="previewData.variantName || previewData.file_name"
            :data-dam-download-thumb="previewData.downloadThumb"
            class="primary-button inline-flex"
        >
            @lang('dam::app.admin.dam.asset.edit.preview-modal.download-original')
        </a>
        @if (bouncer()->hasPermission('dam.asset.files') && bouncer()->hasPermission('dam.asset.files.index'))
        <a
            v-if="previewData.filesUrl"
            :href="filesTabHref()"
            class="secondary-button inline-flex"
        >
            @lang('dam::app.admin.dam.asset.edit.preview-modal.open-files')
        </a>
        @endif
        @if (bouncer()->hasPermission('dam.asset.download_compressed'))
        <template v-if="previewData.downloadCompressedUrl && !['zip','rar','7z','gz','tar','bz2','xz'].includes((previewData.extension || '').toLowerCase())">
            <a
                :href="previewData.downloadCompressedUrl"
                data-dam-download
                :data-dam-download-name="previewData.variantName || previewData.file_name"
                :data-dam-download-thumb="previewData.downloadThumb"
                class="secondary-button inline-flex"
            >
                @lang('dam::app.admin.dam.asset.edit.preview-modal.download-zip')
            </a>
        </template>
        @endif
    </div>
</div>
