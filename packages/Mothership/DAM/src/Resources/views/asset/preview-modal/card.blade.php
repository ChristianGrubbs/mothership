@php
    // Plain 70vh is the no-JS and phone height; from `sm` up frame-fit-script.blade.php replaces it with the space left below the frame.
    $previewFrame = 'h-[70vh]';
@endphp
{{-- On a phone (below sm, 525px) and on any screen 480px tall or less (a phone or iPhone Duo outer display in landscape), the
     card becomes a column: path, then the preview, then the file's actions (Export, Convert, Optimize, favourite, info, edit,
     open), so the preview starts right under the path instead of below two rows of buttons (operator, 2026-10-30). The
     header row goes display: contents there, so its two parts can sit either side of the preview. --}}
<div class="relative min-w-0 max-w-full w-full max-sm:flex max-sm:flex-col [@media(max-height:480px)]:flex [@media(max-height:480px)]:flex-col" data-dam-preview-card>
<div class="flex flex-wrap justify-between items-center gap-2 w-full px-2 py-1 border-b border-gray-100 dark:border-gray-700 max-sm:contents [@media(max-height:480px)]:contents">

    <nav class="flex items-center gap-1 flex-wrap text-sm min-w-0 max-sm:px-2 max-sm:py-1 [@media(max-height:480px)]:px-2 [@media(max-height:480px)]:py-1" aria-label="@lang('dam::app.admin.dam.asset.edit.directory-path')">
        <template v-for="(crumb, i) in displayDirectoryBreadcrumb" :key="crumb.id">
            <span v-if="i > 0" class="text-gray-500 dark:text-gray-400">/</span>
            <a
                :href="'{{ route('admin.dam.assets.index') }}?directory_id=' + crumb.id"
                class="px-1 py-0.5 rounded text-gray-600 dark:text-gray-300 hover:text-primary-700 dark:hover:text-primary-400 hover:underline"
            >@{{ crumb.name }}</a>
        </template>
        <span v-if="displayDirectoryBreadcrumb.length > 0" class="text-gray-500 dark:text-gray-400">/</span>
        <span class="px-1 py-0.5 rounded text-primary-700 dark:text-primary-300 font-semibold min-w-0 [overflow-wrap:anywhere]">@{{ (previewData.variantName || defaultFileLabel() || previewData.display_name || previewData.file_name) }}<template v-if="! previewData.variantName && defaultFileLabel()"> · @lang('dam::app.admin.dam.asset.files.representative')</template></span>
    </nav>

    {{-- max-w-full at every width keeps a long status note (Optimization unavailable…) inside the card: from md the row stays on one line and the note wraps its own text, so the row still sets the card's minimum width and the details panel moves beside the card only where it did before; below md, where that minimum would not fit, the row wraps. --}}
    <div class="flex min-w-0 items-center justify-end gap-1 max-w-full flex-wrap max-sm:order-last max-sm:px-2 max-sm:py-1 [@media(max-height:480px)]:order-last [@media(max-height:480px)]:px-2 [@media(max-height:480px)]:py-1" data-dam-card-actions>

    {{-- P6a: one-file exports run as a batch; the dialog and progress panel are mounted on the page. --}}
    @if (bouncer()->hasPermission('dam.asset.process'))
        <v-dam-asset-export
            v-if="! previewData.variantFileId && ['image', 'video', 'audio'].includes(previewData.file_type)"
            :key="'export-' + previewData.id"
            :asset-id="Number(previewData.id)"
            :file-type="previewData.file_type"
            :src="previewData.playbackUrl || previewData.mediaUrl || ''"
            :current-time="() => ($refs.videoEl ? $refs.videoEl.currentTime : 0)"
        ></v-dam-asset-export>
    @endif

    @if (bouncer()->hasPermission('dam.asset.edit') && bouncer()->hasPermission('dam.asset.files') && collect(app(\Mothership\DAM\Services\HomeWidgetRegistry::class)->catalog())->contains('id', 'vectorization'))
        <v-dam-asset-conversion
            v-if="! previewData.variantFileId && ['png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif', 'tif', 'tiff'].includes((previewData.extension || '').toLowerCase())"
            :key="previewData.id"
            :asset-id="Number(previewData.id)"
            :extension="previewData.extension"
            :state-url="'{{ route('admin.dam.assets.vectorization.state', ['id' => '__ASSET__']) }}'.replace('__ASSET__', previewData.id)"
            :store-url="'{{ route('admin.dam.assets.vectorization.store', ['id' => '__ASSET__']) }}'.replace('__ASSET__', previewData.id)"
            :files-url="previewData.filesUrl"
        ></v-dam-asset-conversion>
    @endif

    @if (bouncer()->hasPermission('dam.asset.edit') && bouncer()->hasPermission('dam.asset.files'))
        {{-- The file on show is optimized: the cover, or a stacked file by its entry and slot (a capture-group companion is
             its own entry, with no slot), the way Open with names it. The copy is saved to that entry's Files. --}}
        <v-dam-asset-optimization
            v-if="['jpg', 'jpeg', 'png', 'mp4', 'pdf'].includes((previewData.extension || '').toLowerCase())"
            :key="'optimize-' + (previewData.variantAssetId || previewData.id) + '-' + (previewData.variantAttachmentId || 'main')"
            :asset-id="Number(previewData.variantAssetId || previewData.id)"
            :extension="previewData.extension"
            :state-url="'{{ route('admin.dam.assets.optimization.state', ['id' => '__ASSET__']) }}'.replace('__ASSET__', previewData.variantAssetId || previewData.id) + (previewData.variantAttachmentId ? '?attachment=' + previewData.variantAttachmentId : '')"
            :store-url="'{{ route('admin.dam.assets.optimization.store', ['id' => '__ASSET__']) }}'.replace('__ASSET__', previewData.variantAssetId || previewData.id) + (previewData.variantAttachmentId ? '?attachment=' + previewData.variantAttachmentId : '')"
            :files-url="previewData.filesUrl"
        ></v-dam-asset-optimization>
    @endif

    <button type="button" class="flex items-center justify-center w-10 h-10 rounded-lg text-xl text-primary-600 dark:text-primary-300 hover:bg-primary-50 dark:hover:bg-cherry-800" :aria-label="favorite ? 'Remove from favorites' : 'Add to favorites'" @click="toggleFavorite">@{{ favorite ? '★' : '☆' }}</button>

    <div class="relative" @mouseleave="hideInfoPopover">
        <button
            type="button"
            class="flex items-center justify-center w-10 h-10 rounded-lg text-gray-400 dark:text-gray-50 hover:bg-primary-50 dark:hover:bg-cherry-800 hover:text-gray-700 dark:hover:text-gray-300 transition-colors"
            data-dam-info-trigger
            aria-label="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.info') }}"
            @mouseenter="showInfoPopover"
            @focus="showInfoPopover"
            @blur="hideInfoPopover"
            @click="isInfoOpen = true"
        >
            <span class="text-lg icon-information" aria-hidden="true"></span>
        </button>
        {{-- Teleported so the overflow-clipping preview column cannot cut the popover off. --}}
        <teleport to="body">
            <div
                v-if="infoPopoverVisible"
                :style="infoPopoverStyle"
                data-dam-info-popover
                class="rounded-xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-lg py-2 px-3 flex flex-col gap-1.5 text-xs pointer-events-none z-[60]"
            >
                <p class="font-semibold text-gray-700 dark:text-gray-200 truncate">@{{ (previewData.variantName || previewData.display_name || previewData.file_name) }}</p>
                <div class="flex items-center gap-2 flex-wrap">
                    <span v-if="previewData.extension_upper" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded font-semibold" :class="previewData.typeColor" :title="previewData.adobe?.title || null"><img v-if="previewData.adobe" :src="adobeIcon(previewData.adobe)" alt="" class="h-3.5 w-3.5 rounded-[3px]" draggable="false">@{{ previewData.extension_upper }}<span v-if="previewData.adobe" class="sr-only">@{{ previewData.adobe.app }}</span></span>
                    <span v-if="previewData.fileSize" class="text-gray-400 dark:text-gray-500">@{{ previewData.fileSize }}</span>
                    <span v-if="previewData.file_type === 'image' && (previewData.variantFileId ? previewData.variantWidth && previewData.variantHeight : previewData.width && previewData.height)" class="text-gray-400 dark:text-gray-500">@{{ previewData.variantFileId ? previewData.variantWidth : previewData.width }}×@{{ previewData.variantFileId ? previewData.variantHeight : previewData.height }}px</span>
                </div>
                <p class="text-gray-400 dark:text-gray-500 text-[11px]">{{ trans('dam::app.admin.dam.asset.edit.preview-modal.card.click-for-details') }}</p>
            </div>
        </teleport>
    </div>

    @if (bouncer()->hasPermission('dam.asset.editor'))
    {{-- A 3D model, PDF or video is not edited itself: its real thumbnail is, and saving sets it as the custom thumbnail.
         A stacked file on show is edited itself when its type allows (its canEditOriginal, set by the stack strip). --}}
    <button
        v-if="previewData.canEditOriginal"
        type="button"
        class="flex items-center justify-center w-10 h-10 rounded-lg text-gray-400 dark:text-gray-500 hover:bg-primary-50 dark:hover:bg-cherry-800 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
        title="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.card.edit-image') }}"
        aria-label="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.card.edit-image') }}"
        @click="editTarget = 'original'; isEditOpen = true"
    >
        <span class="text-lg icon-edit" aria-hidden="true"></span>
    </button>
    <button
        v-if="! previewData.variantFileId && ! previewData.canEditOriginal && previewData.canEditThumbnail"
        type="button"
        class="flex items-center justify-center w-10 h-10 rounded-lg text-gray-400 dark:text-gray-500 hover:bg-primary-50 dark:hover:bg-cherry-800 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
        title="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.card.edit-thumbnail') }}"
        aria-label="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.card.edit-thumbnail') }}"
        data-dam-edit-thumbnail
        @click="editTarget = 'thumbnail'; isEditOpen = true"
    >
        <span class="text-lg icon-edit" aria-hidden="true"></span>
    </button>
    @endif

    <button
        type="button"
        class="flex items-center justify-center w-10 h-10 rounded-lg text-gray-400 dark:text-gray-500 hover:bg-primary-50 dark:hover:bg-cherry-800 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
        title="{{ trans('dam::app.admin.dam.asset.edit.tab.preview') }}"
        aria-label="{{ trans('dam::app.admin.dam.asset.edit.tab.preview') }}"
        data-dam-preview-open
        @click="openPreview"
    >
        <span class="text-lg icon-dam-preview"></span>
    </button>
    </div>
</div>

@include('dam::asset.preview-modal.pano360.status', ['panoramaSurface' => 'card'])

<div class="flex items-center justify-center w-full rounded-lg overflow-hidden bg-gray-50 dark:bg-gray-900">
    <template v-if="previewData.file_type === 'web'">
        <div class="w-full {{ $previewFrame }}" data-preview-frame="web">
            <v-dam-web-page-frame :key="'web-' + previewData.id" :asset-id="previewData.id" :file-name="previewData.file_name || ''"></v-dam-web-page-frame>
        </div>
    </template>

    <template v-else-if="previewData.gameAsset">
        {{-- A Blender file or Unity asset: its embedded thumbnail, else the Blender or Unity placeholder the thumbnail route serves; the modal holds the details. --}}
        <button type="button" class="flex w-full flex-col items-center justify-center gap-3 p-6 text-primary-700 dark:text-primary-300" style="min-height:40vh;" @click="openPreview" data-dam-game-asset-open>
            <img :src="previewData.gameAsset.thumbnailUrl" :alt="previewData.variantName || previewData.display_name || previewData.file_name" class="max-h-80 max-w-full object-contain" />
            <span class="font-semibold">@lang('dam::app.admin.dam.asset.edit.preview-modal.game-asset.open')</span>
        </button>
    </template>

    <template v-else-if="['3mf', 'glb', 'stl'].includes((previewData.extension || '').toLowerCase())">
        <button type="button" class="relative flex flex-col items-center justify-center gap-3 w-full overflow-hidden text-primary-700 dark:text-primary-300" style="height: 40vh;" @click="openPreview" data-dam-model-open>
            {{-- The model thumbnail sits under the CTA. The server sends a revisioned URL only once a thumbnail exists (ModelThumbnailService::cardUrl), so an uncaptured model keeps the text-only card; :key re-requests it after an in-browser capture, and a failed load hides it. --}}
            <img
                v-if="previewData.modelThumbnailUrl"
                :key="previewData.modelThumbnailUrl"
                :src="previewData.modelThumbnailUrl"
                alt=""
                aria-hidden="true"
                data-dam-model-thumbnail
                class="absolute inset-0 w-full h-full object-contain p-4 opacity-90 pointer-events-none"
                v-on:error="$event.target.hidden = true"
            />
            <span class="relative flex flex-col items-center gap-3 rounded-xl bg-white/85 dark:bg-gray-900/80 px-6 py-4 shadow">
                <span class="text-4xl" aria-hidden="true">◇</span>
                <span class="font-semibold">Open interactive 3D preview</span>
                <span v-if="(previewData.extension || '').toLowerCase() === 'stl'" class="text-sm text-gray-500">Orbit, pan, zoom and object visibility</span>
                <span v-else class="text-sm text-gray-500">Orbit, zoom, plates, materials and animation</span>
            </span>
        </button>
    </template>

    <template v-else-if="['pano360-video', 'pano360-image'].includes(previewData.playbackKind)">
        <button type="button" class="relative block w-full bg-black {{ $previewFrame }}" data-preview-frame="pano360" @click="openPreview">
            <video
                v-if="previewData.playbackKind === 'pano360-video'"
                :src="previewData.playbackUrl"
                class="w-full h-full object-contain"
                preload="metadata"
                muted
                playsinline
                v-on:error="panoOnVideoError"
            ></video>
            <img v-else :src="previewData.playbackUrl" :alt="(previewData.variantName || previewData.display_name || previewData.file_name)" class="w-full h-full object-contain" />
            <span class="absolute top-3 left-3 rounded-full bg-primary-600 px-3 py-1 text-xs font-bold text-white shadow">
                @{{ panoramaBadgeLabel }}
            </span>
            <span class="absolute inset-0 flex items-center justify-center text-sm font-semibold text-white bg-black/20 hover:bg-black/10">
                @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.open-viewer')
            </span>
        </button>
    </template>

    <template v-else-if="previewData.playbackKind === 'image'">
        <div class="w-full {{ $previewFrame }}" data-preview-frame="image">
            <v-zoomable-image
                :style="(previewData.extension || '').toLowerCase() === 'svg' ? { backgroundColor: '#fff', backgroundImage: 'repeating-conic-gradient(#e5e7eb 0% 25%, #fff 0% 50%)', backgroundSize: '20px 20px' } : {}"
                :src="previewData.previewPath"
                :alt="(previewData.variantName || previewData.display_name || previewData.file_name)"
            ></v-zoomable-image>
        </div>
    </template>

    <template v-else-if="previewData.playbackKind === 'video'">
        <div class="w-full {{ $previewFrame }}" data-preview-frame="video">
            @include('dam::asset.preview-modal.video.video-player')
        </div>
    </template>

    <template v-else-if="previewData.file_type === 'audio'">
        <div v-if="!isOpen" class="w-full flex justify-center py-4">
            @include('dam::asset.preview-modal.audio.audio-player')
        </div>
    </template>

    <template v-else-if="((previewData.extension || '').toLowerCase() === 'pdf' || previewData.adobe?.pdf)">
        <button type="button" data-dam-pdf-expand @click="openPreview" class="flex w-full flex-col items-center justify-center gap-3 p-6 text-primary-700 dark:text-primary-300" style="min-height:40vh;">
            {{-- The server builds this URL (AssetHelper::pdfThumbnailUrl): the thumbnail route decodes its path twice, so a browser-encoded `+` or `%` would miss the file. --}}
            <img v-if="previewData.pdfThumbnailUrl" :src="previewData.pdfThumbnailUrl" :alt="previewData.variantName || previewData.display_name || previewData.file_name" class="max-h-80 max-w-full object-contain" />
            <span class="font-semibold">Expand PDF</span>
            <span class="text-sm text-gray-500">Read, fit, zoom and browse pages</span>
        </button>
    </template>

    <template v-else-if="previewData.variantFileId && isTextFile(String(previewData.mime_type || ''), [previewVariant?.label, previewVariant?.file_name])">
        <button type="button" @click="openPreview" data-dam-text-expand class="flex w-full min-w-0 flex-col items-center justify-center gap-3 p-6 text-primary-700 dark:text-primary-300" style="min-height:40vh;">
            <span class="text-3xl font-semibold">TXT</span>
            <span class="font-semibold">Open text preview</span>
            <span class="max-w-full text-sm [overflow-wrap:anywhere]">@{{ previewData.variantName }}</span>
        </button>
    </template>

    <template v-else>
        <div class="p-10 text-center">
            <img
                :src="previewData.placeholderSvg"
                :alt="(previewData.variantName || previewData.display_name || previewData.file_name)"
                class="mx-auto max-h-32 max-w-full object-contain opacity-60"
            />
            <p class="mt-3 text-sm text-gray-700 dark:text-gray-200 truncate max-w-md">@{{ (previewData.variantName || previewData.display_name || previewData.file_name) }}</p>
            <a
                v-if="previewData.downloadUrl"
                :href="previewData.downloadUrl"
                data-dam-download
                :data-dam-download-name="previewData.variantName || previewData.file_name"
                :data-dam-download-thumb="previewData.downloadThumb"
                class="mt-2 inline-block text-primary-600 dark:text-primary-300 hover:underline text-sm"
            >@lang('dam::app.admin.dam.asset.edit.button.download')</a>
        </div>
    </template>
</div>
</div>
