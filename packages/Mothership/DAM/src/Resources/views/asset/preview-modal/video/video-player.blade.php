<div ref="videoContainer" class="relative w-full h-full" data-dam-flat-video-player @mousemove="videoShowControls" @mouseleave="videoShowControls">

    <div
        :style="{opacity: videoControlsVisible || videoFrameBusy ? 1 : 0, pointerEvents: videoControlsVisible || videoFrameBusy ? 'auto' : 'none'}"
        style="position:absolute;top:12px;right:12px;z-index:20;display:flex;flex-direction:column;align-items:flex-end;gap:6px;max-width:calc(100% - 24px)"
        @mouseenter="videoKeepControls"
        @focusin="videoKeepControls"
        @keydown.space.stop
        @click.stop
        @dblclick.stop
    >
        <div v-if="! previewData.previewOnly" style="display:flex;gap:1px;background:rgba(15,23,42,.88);border:1px solid rgba(255,255,255,.25);border-radius:8px;overflow:hidden;color:white">
            <button type="button" :disabled="videoFrameBusy" @click="videoCaptureFrame({action: 'copy'})" title="Copy current frame as a full-resolution PNG" style="padding:8px 12px;font-size:12px;font-weight:600;white-space:nowrap">Copy frame</button>
            <button type="button" :disabled="videoFrameBusy" @click="videoCaptureFrame({action: 'download'})" title="Download current frame as a full-resolution PNG" style="padding:8px 12px;font-size:12px;font-weight:600;white-space:nowrap;border-left:1px solid rgba(255,255,255,.25)">Download frame</button>
        </div>
        <span v-if="videoFrameStatus" role="status" aria-live="polite" data-dam-frame-status :title="videoFrameStatus" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:6px 9px;border-radius:6px;background:rgba(15,23,42,.9);color:white;font-size:12px;max-width:100%">@{{ videoFrameStatus }}</span>
    </div>

    <!-- Right-click menu on the video itself. The native menu is suppressed only on the <video>
         (ADR 0003: both actions cut the frame from the master, whichever quality is streaming). -->
    <!-- A preview-only share (P2 S5b) has nothing on this menu, so it never opens there. -->
    <template v-if="videoCtxMenuOpen && ! previewData.previewOnly">
        <div class="fixed inset-0" style="z-index: 10015" @click="videoCloseContextMenu" @contextmenu.prevent="videoCloseContextMenu"></div>
        <div
            data-dam-video-ctx-menu
            role="menu"
            class="fixed w-56 rounded-lg bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-2xl py-1 text-sm overflow-hidden"
            style="z-index: 10020"
            :style="videoCtxMenuStyle"
            @click.stop
            @dblclick.stop
            @keydown.space.stop
        >
            <button
                v-if="previewData.canSetThumbnail"
                type="button"
                role="menuitem"
                data-dam-set-thumbnail
                :disabled="videoFrameBusy"
                class="w-full flex items-center gap-2.5 px-4 py-2.5 text-left text-gray-700 dark:text-gray-200 hover:bg-gray-600 hover:text-white dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-wait transition-colors"
                @click="videoSetFrameAsThumbnail"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
            </svg>
                @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.frame-thumbnail')
            </button>
            {{-- Another file of a stack: the picture it would set is the stack's, so the control stays visible but off, with the reason. --}}
            <button
                v-else-if="previewData.thumbnailLocked"
                type="button"
                role="menuitem"
                data-dam-set-thumbnail
                disabled
                title="@lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.frame-thumbnail-locked')"
                class="w-full flex items-center gap-2.5 px-4 py-2.5 text-left text-gray-700 dark:text-gray-200 opacity-50 cursor-not-allowed"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
            </svg>
                @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.frame-thumbnail')
            </button>
            <button
                v-if="! previewData.previewOnly"
                type="button"
                role="menuitem"
                data-dam-download-frame
                :disabled="videoFrameBusy"
                class="w-full flex items-center gap-2.5 px-4 py-2.5 text-left text-gray-700 dark:text-gray-200 hover:bg-gray-600 hover:text-white dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-wait transition-colors"
                @click="videoCloseContextMenu(); videoCaptureFrame({action: 'download'})"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
                @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.download-frame')
            </button>
        </div>
    </template>

    <div
        class="relative w-full h-full flex items-center justify-center bg-black select-none"
        :class="!videoControlsVisible ? 'cursor-none' : 'cursor-pointer'"
        @click="videoOnClick"
        @dblclick="videoOnDblClick"
    >
        <video
            ref="videoEl"
            autoplay
            muted
            playsinline
            data-dam-video-autoplay-muted
            @contextmenu.prevent="videoOpenContextMenu"
            class="absolute inset-0 w-full h-full object-contain"
            @timeupdate="videoOnTimeUpdate"
            @loadedmetadata="videoOnLoadedMeta"
            @progress="videoOnProgress"
            @waiting="videoOnWaiting"
            @canplay="videoOnCanPlay"
            @ended="videoOnEnded"
            @play="videoIsPlaying = true"
            @pause="videoIsPlaying = false"
        >
            <source :src="videoActiveSrc" :type="videoActiveType">
            <track
                v-if="previewData.captionUrl"
                kind="captions"
                :src="previewData.captionUrl"
                :label="previewData.captionLabel || 'Captions'"
                data-dam-caption-track
                @load="videoOnCaptionTrackLoad"
            >
            @lang('dam::app.admin.dam.asset.edit.preview-modal.not-available')
        </video>

        <button
            v-if="videoIsMuted"
            type="button"
            class="absolute top-3 max-[639px]:top-24 left-3 z-10 flex items-center gap-2 px-3 py-1.5 rounded-full bg-black/70 text-white text-xs font-semibold shadow hover:bg-black/85 transition-colors"
            data-dam-video-unmute
            aria-label="@lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.unmute')"
            @click.stop="videoToggleMute"
            @dblclick.stop
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                <line x1="23" y1="9" x2="17" y2="15"/>
                <line x1="17" y1="9" x2="23" y2="15"/>
            </svg>
            @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.muted-hint')
        </button>

        <div v-if="videoIsBuffering" class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <div class="w-12 h-12 rounded-full border-4 border-white/20 border-t-primary-400 animate-spin"></div>
        </div>

        <div
            class="absolute inset-0 flex items-center justify-center pointer-events-none transition-opacity duration-300"
            :class="(!videoIsPlaying && !videoIsBuffering) || videoClickFlash ? 'opacity-100' : 'opacity-0'"
        >
            <div
                class="w-16 h-16 rounded-full bg-black/50 flex items-center justify-center transition-transform duration-150"
                :class="videoClickFlash ? 'scale-90' : 'scale-100'"
            >
                <svg v-if="!videoIsPlaying" xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-white translate-x-0.5" viewBox="0 0 24 24" fill="currentColor">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-white" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>
                </svg>
            </div>
        </div>
    </div>

    <div
        class="absolute bottom-0 left-0 right-0 flex flex-col pt-14 bg-gradient-to-t from-black/90 via-black/60 to-transparent transition-opacity duration-300"
        :class="videoControlsVisible ? 'opacity-100' : 'opacity-0 pointer-events-none'"
        @mouseenter="videoKeepControls"
        @mouseleave="videoShowControls"
    >

        <div class="px-4 pb-1">
            <div
                ref="videoSeekContainer"
                class="relative h-4 group cursor-pointer"
                @mousedown="videoOnSeekDown"
                @touchstart.prevent="videoOnSeekDown"
                @mousemove="videoOnSeekHover"
                @mouseleave="videoOnSeekLeave"
            >
                <div
                    v-show="videoSeekTooltipVisible && videoDuration"
                    class="absolute px-1.5 py-0.5 rounded bg-black/80 text-white text-xs font-mono pointer-events-none whitespace-nowrap z-10"
                    :style="{ left: videoSeekTooltipX + 'px', bottom: 'calc(100% + 8px)', transform: 'translateX(-50%)' }"
                >@{{ videoSeekTooltip }}</div>

                <div class="absolute inset-x-0 top-1/2 -translate-y-1/2 h-1.5 rounded-full bg-white/30 overflow-hidden">
                    <div
                        class="absolute inset-y-0 left-0 bg-white/60 transition-[width] duration-300"
                        :style="{ width: videoBuffered + '%' }"
                    ></div>
                    <div
                        class="absolute inset-y-0 left-0 bg-primary-400"
                        :style="{ width: (videoDuration ? (videoCurrentTime / videoDuration) * 100 : 0) + '%' }"
                    ></div>
                </div>

                <div
                    class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 w-3 h-3 rounded-full bg-white shadow pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity"
                    :style="{ left: (videoDuration ? (videoCurrentTime / videoDuration) * 100 : 0) + '%' }"
                ></div>
            </div>
        </div>

        <div class="flex items-center gap-2 px-4 pb-3 text-white">

            <button
                type="button"
                class="flex items-center justify-center w-8 h-8 rounded-full bg-primary-600 hover:bg-primary-700 text-white shadow transition-colors shrink-0"
                @click="videoTogglePlay"
            >
                <svg v-if="videoIsPlaying" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 translate-x-0.5" viewBox="0 0 24 24" fill="currentColor">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
            </button>

            <button
                type="button"
                class="dam-ctrl-desktop items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium hover:bg-white/10 border border-white/30 hover:border-white/60 transition-colors shrink-0"
                title="@lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.back-10s')"
                @click="videoSkip(-10)"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>
                </svg>
                @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.10s')
            </button>

            <button
                type="button"
                class="dam-ctrl-desktop items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium hover:bg-white/10 border border-white/30 hover:border-white/60 transition-colors shrink-0"
                title="@lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.forward-10s')"
                @click="videoSkip(10)"
            >
                @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.10s')
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12a9 9 0 1 1-9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>
                </svg>
            </button>

            <span class="text-xs font-mono tabular-nums shrink-0 opacity-80">
                @{{ videoCurrentTimeDisplay }} / @{{ videoDurationDisplay }}
            </span>

            <div class="flex-1"></div>

            <div v-if="videoQualityAvailable" class="dam-ctrl-desktop relative items-center shrink-0">
                <button
                    ref="videoQualityBtn"
                    type="button"
                    class="flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold opacity-70 hover:opacity-100 hover:bg-white/10 transition-colors"
                    title="Playback quality"
                    @click.stop="videoToggleQualityMenu"
                >
                    @{{ videoQuality === 'original' ? 'Original' : '1080p' }}
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>

                <template v-if="videoQualityOpen">
                    <div class="fixed inset-0" style="z-index: 10015" @click="videoQualityOpen = false"></div>
                    <div
                        class="fixed rounded-lg bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-2xl py-1 text-sm overflow-hidden whitespace-nowrap"
                        :style="videoQualityMenuStyle"
                        style="z-index: 10020"
                    >
                        <template v-for="option in [{value: 'auto', label: 'Auto (1080p)'}, {value: 'original', label: 'Original'}]" :key="option.value">
                            <button
                                type="button"
                                class="w-full flex items-center justify-between gap-4 px-4 py-2 text-xs font-semibold transition-colors"
                                :class="videoQuality === option.value ? 'bg-primary-600 text-white' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'"
                                @click="setVideoQuality(option.value)"
                            >
                                <span>@{{ option.label }}</span>
                                <svg v-if="videoQuality === option.value" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </button>
                        </template>
                    </div>
                </template>
            </div>

            <div class="dam-ctrl-desktop relative items-center shrink-0">
                <button
                    ref="videoSpeedBtn"
                    type="button"
                    class="flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold opacity-70 hover:opacity-100 hover:bg-white/10 transition-colors"
                    title="@lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.speed')"
                    @click.stop="videoToggleSpeedMenu"
                >
                    @{{ videoSpeed }}×
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>

                <template v-if="videoSpeedOpen">
                    <div
                        class="fixed inset-0"
                        style="z-index: 10015"
                        @click="videoSpeedOpen = false"
                    ></div>
                    <div
                        class="fixed rounded-lg bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-2xl py-1 text-sm overflow-hidden whitespace-nowrap"
                        :style="videoSpeedMenuStyle"
                        style="z-index: 10020"
                    >
                        <template v-for="rate in [0.5, 0.75, 1, 1.25, 1.5, 2]" :key="rate">
                            <button
                                type="button"
                                class="w-full flex items-center justify-between gap-4 px-4 py-2 text-xs font-semibold transition-colors"
                                :class="videoSpeed === rate ? 'bg-primary-600 text-white' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'"
                                @click="setVideoSpeed(rate); videoSpeedOpen = false"
                            >
                                <span>@{{ rate }}×</span>
                                <svg v-if="videoSpeed === rate" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </button>
                        </template>
                    </div>
                </template>
            </div>

            <button
                v-if="previewData.captionUrl"
                type="button"
                class="flex items-center justify-center min-w-8 h-7 rounded px-1.5 text-[11px] font-bold transition-colors shrink-0"
                :class="videoCaptionsEnabled ? 'bg-primary-600 text-white' : 'opacity-70 hover:opacity-100 hover:bg-white/10'"
                :title="videoCaptionsEnabled ? @js(trans('dam::app.admin.dam.asset.edit.preview-modal.video-player.captions-disable')) : @js(trans('dam::app.admin.dam.asset.edit.preview-modal.video-player.captions-enable'))"
                :aria-label="videoCaptionsEnabled ? @js(trans('dam::app.admin.dam.asset.edit.preview-modal.video-player.captions-disable')) : @js(trans('dam::app.admin.dam.asset.edit.preview-modal.video-player.captions-enable'))"
                :aria-pressed="videoCaptionsEnabled"
                data-dam-caption-control
                @click="videoToggleCaptions"
            >
                CC
            </button>

            <button
                type="button"
                class="dam-ctrl-desktop items-center justify-center w-7 h-7 rounded transition-colors shrink-0"
                :class="videoIsLooping ? 'bg-primary-600 text-white' : 'opacity-70 hover:opacity-100 hover:bg-white/10'"
                title="@lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.loop')"
                @click="videoToggleLoop"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                    <polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                </svg>
            </button>

            <div class="dam-ctrl-desktop items-center gap-1.5 shrink-0">
                <button
                    type="button"
                    class="opacity-70 hover:opacity-100 transition-opacity shrink-0"
                    :title="videoIsMuted ? @js(trans('dam::app.admin.dam.asset.edit.preview-modal.video-player.unmute')) : @js(trans('dam::app.admin.dam.asset.edit.preview-modal.video-player.mute'))"
                    :aria-label="videoIsMuted ? @js(trans('dam::app.admin.dam.asset.edit.preview-modal.video-player.unmute')) : @js(trans('dam::app.admin.dam.asset.edit.preview-modal.video-player.mute'))"
                    :aria-pressed="videoIsMuted ? 'true' : 'false'"
                    @click="videoToggleMute"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                        <template v-if="!videoIsMuted">
                            <path v-if="videoVolume > 0.5" d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/>
                            <path v-else-if="videoVolume > 0" d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                        </template>
                        <template v-else>
                            <line x1="23" y1="9" x2="17" y2="15"/>
                            <line x1="17" y1="9" x2="23" y2="15"/>
                        </template>
                    </svg>
                </button>
                <input
                    type="range"
                    class="w-20 h-1 accent-primary-400 cursor-pointer opacity-80 hover:opacity-100"
                    min="0" max="1" step="0.01"
                    :value="videoIsMuted ? 0 : videoVolume"
                    @input="videoOnVolume"
                />
            </div>

            <button
                type="button"
                class="flex items-center justify-center w-7 h-7 rounded hover:bg-white/10 opacity-70 hover:opacity-100 transition-opacity shrink-0"
                title="@lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.fullscreen')"
                @click="videoToggleFullscreen"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                </svg>
            </button>

            <div class="relative shrink-0">
                <button
                    type="button"
                    class="flex items-center justify-center w-7 h-7 rounded hover:bg-white/10 opacity-70 hover:opacity-100 transition-opacity"
                    title="@lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.more-actions')"
                    @click="videoMenuOpen = !videoMenuOpen; videoMenuOpen ? videoKeepControls() : null"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/>
                    </svg>
                </button>

                <div
                    v-if="videoMenuOpen"
                    class="fixed inset-0"
                    style="z-index: 10015"
                    @click="videoMenuOpen = false"
                ></div>

                <div
                    v-if="videoMenuOpen"
                    data-dam-video-overflow-menu
                    class="absolute bottom-12 right-0 w-52 rounded-lg bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-2xl py-1 text-sm overflow-hidden"
                    style="z-index: 10020"
                >
                    <a
                        v-if="previewData.downloadUrl"
                        :href="previewData.downloadUrl"
                        data-dam-download
                        :data-dam-download-name="previewData.variantName || previewData.file_name"
                        :data-dam-download-thumb="previewData.downloadThumb"
                        class="flex items-center gap-2.5 px-4 py-2.5 text-gray-700 dark:text-gray-200 hover:bg-gray-600 hover:text-white dark:hover:bg-gray-600 transition-colors"
                        @click="videoMenuOpen = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.download-file')
                    </a>

                    {{-- P6 D26: the published 1080p H.264 proxy, through the same authorized download route. --}}
                    <a
                        v-if="previewData.webMp4Url"
                        :href="previewData.webMp4Url"
                        data-dam-download
                        :data-dam-download-name="previewData.variantName || previewData.file_name"
                        :data-dam-download-thumb="previewData.downloadThumb"
                        data-dam-download-web-mp4
                        class="flex items-center gap-2.5 px-4 py-2.5 text-gray-700 dark:text-gray-200 hover:bg-gray-600 hover:text-white dark:hover:bg-gray-600 transition-colors"
                        @click="videoMenuOpen = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.download-web-mp4')
                    </a>

                    @if (bouncer()->hasPermission('dam.asset.download_compressed'))
                    <a
                        v-if="previewData.downloadCompressedUrl"
                        :href="previewData.downloadCompressedUrl"
                        data-dam-download
                        :data-dam-download-name="previewData.variantName || previewData.file_name"
                        :data-dam-download-thumb="previewData.downloadThumb"
                        class="flex items-center gap-2.5 px-4 py-2.5 text-gray-700 dark:text-gray-200 hover:bg-gray-600 hover:text-white dark:hover:bg-gray-600 transition-colors"
                        @click="videoMenuOpen = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8l-6-6H5a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8z"/><polyline points="15 2 15 8 21 8"/><line x1="12" y1="12" x2="12" y2="18"/><polyline points="9 15 12 18 15 15"/>
                        </svg>
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.download-zip')
                    </a>
                    @endif

                    <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>

                    <button
                        v-if="previewData.canSetThumbnail"
                        type="button"
                        data-dam-set-thumbnail
                        :disabled="videoFrameBusy"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-left text-gray-700 dark:text-gray-200 hover:bg-gray-600 hover:text-white dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-wait transition-colors"
                        @click="videoSetFrameAsThumbnail"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
                    </svg>
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.frame-thumbnail')
                    </button>

                    {{-- Another file of a stack: the picture it would set is the stack's, so the control stays visible but off, with the reason. --}}
                    <button
                        v-else-if="previewData.thumbnailLocked"
                        type="button"
                        role="menuitem"
                        data-dam-set-thumbnail
                        disabled
                        title="@lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.frame-thumbnail-locked')"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-left text-gray-700 dark:text-gray-200 opacity-50 cursor-not-allowed"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
                    </svg>
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.frame-thumbnail')
                    </button>

                    <button
                        type="button"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-gray-700 dark:text-gray-200 hover:bg-gray-600 hover:text-white dark:hover:bg-gray-600 transition-colors text-left"
                        @click="videoCopyLink(previewData.mediaUrl)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                        </svg>
                        <span v-if="videoLinkCopied" class="text-green-600 dark:text-green-400">
                            @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.link-copied')
                        </span>
                        <span v-else>
                            @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.copy-link')
                        </span>
                    </button>

                    <a
                        :href="previewData.mediaUrl"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center gap-2.5 px-4 py-2.5 text-gray-700 dark:text-gray-200 hover:bg-gray-600 hover:text-white dark:hover:bg-gray-600 transition-colors"
                        @click="videoMenuOpen = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.open-in-new-tab')
                    </a>

                    <button
                        v-if="videoSupportsPiP"
                        type="button"
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-gray-700 dark:text-gray-200 hover:bg-gray-600 hover:text-white dark:hover:bg-gray-600 transition-colors text-left"
                        @click="videoTogglePiP(); videoMenuOpen = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="3" width="20" height="14" rx="2"/><rect x="12" y="11" width="9" height="7" rx="1"/>
                        </svg>
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.video-player.picture-in-picture')
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>
