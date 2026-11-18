<div
    ref="panoContainer"
    class="relative w-full h-full overflow-hidden bg-black"
    :style="panoPseudoFullscreen ? { position: 'fixed', inset: '0', zIndex: 60, height: '100dvh' } : null"
    data-dam-360-viewer
>
    <canvas
        v-show="!panoFlatView"
        ref="panoCanvas"
        class="block w-full h-full touch-none cursor-grab active:cursor-grabbing"
        aria-label="@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.drag-hint')"
    ></canvas>

    <video
        v-if="previewData.playbackKind === 'pano360-video'"
        ref="videoEl"
        :src="previewData.playbackUrl"
        :class="panoFlatView ? 'block w-full h-full object-contain' : 'hidden'"
        :controls="panoFlatView"
        preload="metadata"
        playsinline
        data-dam-360-flat-player
        @timeupdate="videoOnTimeUpdate"
        @loadedmetadata="videoOnLoadedMeta"
        @progress="videoOnProgress"
        @waiting="videoOnWaiting"
        @canplay="videoOnCanPlay"
        @ended="videoOnEnded"
        @play="videoIsPlaying = true"
        @pause="videoIsPlaying = false"
        v-on:error="panoOnVideoError"
    >
        {{-- Use the video's source directly: a failed child <source> emits its
             own non-bubbling error and leaves video.error null, bypassing retry. --}}
        <track
            v-if="previewData.captionUrl"
            kind="captions"
            :src="previewData.captionUrl"
            :label="previewData.captionLabel || 'Captions'"
            data-dam-caption-track
            @load="videoOnCaptionTrackLoad"
        >
    </video>

    <img
        v-else
        ref="panoImg"
        {{-- Anonymous CORS, declared before src: the panorama is uploaded into a WebGL texture from
             this very element, and a preview redirected to another origin (S3) taints it otherwise. --}}
        crossorigin="anonymous"
        :src="previewData.playbackUrl"
        :alt="(previewData.display_name || previewData.file_name)"
        :class="panoFlatView ? 'block w-full h-full object-contain' : 'hidden'"
    />

    {{-- A load failure keeps the playback in place, so the modal stays on the
         viewer branch and the card's status strip is behind the backdrop —
         the message and its retry have to be reachable from in here. --}}
    <div
        v-if="panoError || panoramaError"
        class="absolute top-12 left-1/2 -translate-x-1/2 z-20 flex max-w-xl items-center gap-3 rounded-lg bg-amber-950/90 px-4 py-2 text-xs text-amber-100 shadow"
        role="status"
    >
        <span>@{{ panoError || panoramaError }}</span>
        <button
            v-if="panoramaLoadFailed"
            type="button"
            class="rounded border border-amber-200/50 px-2 py-0.5 font-semibold hover:bg-amber-100/10"
            data-dam-panorama-retry-playback
            @click="panoramaRetryPlayback"
        >
            @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.retry-playback')
        </button>
    </div>

    <div class="absolute top-3 left-3 right-3 z-20 flex flex-wrap items-center gap-2 pointer-events-none">
        <span class="whitespace-nowrap rounded-full bg-primary-600/95 px-3 py-1 text-xs font-bold text-white shadow">
            @{{ panoramaBadgeLabel }}
        </span>
        <span v-if="!panoFlatView" class="min-w-0 rounded-full bg-black/65 px-3 py-1 text-xs text-white/90">
            <template v-if="panoCoarsePointer">@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.drag-hint-touch')</template>
            <template v-else>@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.drag-hint')</template>
        </span>
    </div>

    <div
        v-if="panoLoading && !panoFlatView"
        class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-black text-sm text-white/90 pointer-events-none"
        role="status"
        aria-live="polite"
        data-dam-360-loading
    >
        <svg class="h-8 w-8 animate-spin text-white/80" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <span>@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.loading')</span>
    </div>

    <div
        v-if="previewData.playbackKind === 'pano360-video' && !panoFlatView && videoCaptionsEnabled && videoActiveCues.length"
        class="absolute inset-x-4 bottom-24 z-20 flex flex-col items-center gap-1 pointer-events-none text-center"
        data-dam-360-caption-overlay
        role="status"
        aria-live="polite"
    >
        <span
            v-for="(cue, index) in videoActiveCues"
            :key="index"
            class="max-w-3xl whitespace-pre-line rounded bg-black/85 px-3 py-1.5 text-base font-semibold leading-snug text-white shadow-lg"
        >@{{ cue }}</span>
    </div>

    <div class="absolute bottom-0 inset-x-0 z-20 flex flex-wrap items-center gap-3 bg-gradient-to-t from-black/95 via-black/75 to-transparent px-4 pb-4 pt-12 text-white">
        <template v-if="previewData.playbackKind === 'pano360-video' && !panoFlatView">
            <button type="button" class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold hover:bg-primary-700" @click="videoTogglePlay">
                <span v-if="videoIsPlaying">@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.pause')</span>
                <span v-else>@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.play')</span>
            </button>
            <input
                type="range"
                class="min-w-32 flex-1 accent-primary-400"
                min="0"
                :max="videoDuration || 0"
                step="0.1"
                :value="videoCurrentTime"
                :aria-label="@js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.seek'))"
                data-dam-360-seek
                @input="panoSeek"
            />
            <span class="text-xs tabular-nums">@{{ videoCurrentTimeDisplay }} / @{{ videoDurationDisplay }}</span>
            <button type="button" class="rounded-lg border border-white/40 px-3 py-1.5 text-xs font-semibold hover:bg-white/10" @click="videoToggleMute">
                <span v-if="videoIsMuted">@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.unmute')</span>
                <span v-else>@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.mute')</span>
            </button>
            <button
                v-if="previewData.captionUrl"
                type="button"
                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors"
                :class="videoCaptionsEnabled ? 'border-primary-400 bg-primary-600 text-white' : 'border-white/40 hover:bg-white/10'"
                :aria-pressed="videoCaptionsEnabled"
                data-dam-360-caption-control
                @click="videoToggleCaptions"
            >
                @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.captions')
            </button>
        </template>

        <button type="button" class="rounded-lg border border-white/40 px-3 py-1.5 text-xs font-semibold hover:bg-white/10" @click="panoToggleFlat">
            <span v-if="panoFlatView">@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.sphere-view')</span>
            <span v-else>@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.flat-view')</span>
        </button>
        <button
            type="button"
            class="rounded-lg border border-white/40 px-3 py-1.5 text-xs font-semibold hover:bg-white/10"
            data-dam-360-fullscreen
            @click="panoToggleFullscreen"
        >
            @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.fullscreen')
        </button>

        {{-- The camera's own numbers, so a drag or a wheel tick is observable
             from outside the WebGL canvas — by a person and by a browser test. --}}
        <span v-if="!panoFlatView" class="ml-auto flex items-center gap-3 text-[11px] tabular-nums text-white/80">
            <span>
                @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.longitude')
                <span data-dam-360-longitude>@{{ Math.round(panoLongitude) }}°</span>
            </span>
            <span>
                @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.fov')
                <span data-dam-360-fov>@{{ Math.round(panoFov) }}°</span>
            </span>
        </span>
    </div>
</div>
