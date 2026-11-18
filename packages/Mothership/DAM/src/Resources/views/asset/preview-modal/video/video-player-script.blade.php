<script type="module">
window._damVideoPlayer = {

    data: {
        videoSpeed:              1,
        videoIsPlaying:          false,
        videoCurrentTime:        0,
        videoDuration:           0,
        videoVolume:             1,
        videoEnded:              false,
        videoIsSeeking:          false,
        videoControlsVisible:    true,
        videoControlsTimer:      null,
        videoIsFullscreen:       false,
        videoIsMuted:            false,
        videoBuffered:           0,
        videoIsBuffering:        false,
        videoIsLooping:          false,
        videoClickFlash:         false,
        videoClickTimer:         null,
        videoSeekTooltip:        '',
        videoSeekTooltipX:       0,
        videoSeekTooltipVisible: false,
        videoSupportsPiP:        typeof document !== 'undefined' && 'pictureInPictureEnabled' in document,
        videoMenuOpen:           false,
        videoSpeedOpen:          false,
        videoLinkCopied:         false,
        videoFrameBusy:          false,
        videoFrameStatus:        '',
        videoFrameGeneration:    0,
        videoCtxMenuOpen:        false,
        videoCtxMenuStyle:       {},
        _videoCtxKeyHandler:     null,
        videoCaptionsEnabled:    false,
        videoActiveCues:         [],
        _videoSpeedBtnRect:      null,
        _videoTextTrack:         null,
        _videoCueChangeHandler:  null,
        videoQuality:            'auto',
        videoQualityOpen:        false,
        _videoQualityBtnRect:    null,
        _videoQualityRestore:    null,
        _videoQualityGen:        0,
        _videoMasterEl:          null,
    },

    computed: {
        videoCurrentTimeDisplay() { return this._formatTime(this.videoCurrentTime); },
        videoDurationDisplay()    { return this._formatTime(this.videoDuration); },

        videoSpeedMenuStyle() {
            const r = this._videoSpeedBtnRect;
            if (!r) return {};

            const menuH = 208;
            const top   = r.top - menuH - 8;
            return {
                top:  (top < 8 ? r.bottom + 8 : top) + 'px',
                left: Math.round(r.left + r.width / 2) + 'px',
                transform: 'translateX(-50%)',
            };
        },

        videoActiveSrc() {
            const proxy = this.previewData?.playbackUrl || this.previewData?.mediaUrl || '';
            const master = this.previewData?.originalUrl || '';

            if (!this.previewData?.playbackIsProxy || !master) return proxy;

            return this.videoQuality === 'original' ? master : proxy;
        },

        videoActiveType() {
            if (this.videoQualityAvailable && this.videoQuality === 'original') return undefined;
            // A source is a hint the browser may veto: Chrome answers '' to canPlayType('video/quicktime') yet decodes a
            // .mov (H.264 or HEVC) fine, and a <source> whose type it rejects is never loaded. Only a preview proxy
            // (always MP4) names its type; the file's own bytes are tried without one.
            if (!this.previewData?.playbackIsProxy) return undefined;
            return this.previewData?.playbackMime || this.previewData?.mime_type;
        },

        videoQualityAvailable() {
            return !!(this.previewData?.playbackIsProxy && this.previewData?.originalUrl);
        },

        videoQualityMenuStyle() {
            const r = this._videoQualityBtnRect;
            if (!r) return {};

            const menuH = 96;
            const top   = r.top - menuH - 8;
            return {
                top:  (top < 8 ? r.bottom + 8 : top) + 'px',
                left: Math.round(r.left + r.width / 2) + 'px',
                transform: 'translateX(-50%)',
            };
        },
    },

    methods: {
        videoResetState() {
            this.videoCancelFrame();
            this.videoDetachCaptionTrack();
            this.videoSpeed = 1; this.videoIsPlaying = false;
            this.videoCurrentTime = 0; this.videoDuration = 0;
            this.videoVolume = 1; this.videoEnded = false;
            this.videoControlsVisible = true; clearTimeout(this.videoControlsTimer);
            // Previews autoplay muted (shared lab machines); the player shows an unmute pill.
            this.videoIsMuted = true; this.videoIsLooping = false;
            this.videoIsBuffering = false; this.videoBuffered = 0;
            this.videoClickFlash = false;
            this.videoMenuOpen = false; this.videoSpeedOpen = false; this.videoLinkCopied = false;
            this.videoCloseContextMenu();
            this.videoCaptionsEnabled = false; this.videoActiveCues = [];
            try { const sv = parseFloat(localStorage.getItem('dam_video_volume')); if (!isNaN(sv)) this.videoVolume = sv; } catch(_) {}
            this.videoQuality = 'auto'; this.videoQualityOpen = false;
            try { const q = localStorage.getItem('dam_video_quality'); if (q === 'original' || q === 'auto') this.videoQuality = q; } catch(_) {}
        },

        videoInitEl() {
            this.videoCancelFrame();
            if (this.$refs.videoEl) {
                this.$refs.videoEl.pause();
                this.$refs.videoEl.currentTime = 0;
                this.$refs.videoEl.volume = this.videoVolume;
                this.$refs.videoEl.muted = this.videoIsMuted;
                this.$refs.videoEl.playbackRate = 1;
                this.$refs.videoEl.loop = false;
            }
            this.videoCurrentTime = 0;
            this.$nextTick(() => this.videoBindCaptionTrack());
        },

        videoStopOnClose() {
            this.videoCancelFrame();
            if (this.$refs.videoEl) this.$refs.videoEl.pause();
            this.videoIsPlaying = false;
            this.videoMenuOpen = false;
            this.videoCloseContextMenu();
            clearTimeout(this.videoControlsTimer);
        },

        videoMounted() {
            document.addEventListener('fullscreenchange', this.videoOnFullscreenChange);
            document.addEventListener('webkitfullscreenchange', this.videoOnFullscreenChange);
        },

        videoBeforeUnmount() {
            this.videoCancelFrame();
            this.videoCloseContextMenu();
            document.removeEventListener('fullscreenchange', this.videoOnFullscreenChange);
            document.removeEventListener('webkitfullscreenchange', this.videoOnFullscreenChange);
            this.videoDetachCaptionTrack();
        },

        videoBindCaptionTrack() {
            const track = this.$refs.videoEl?.textTracks?.[0] || null;

            if (track === this._videoTextTrack) {
                this.videoApplyCaptionMode();
                return;
            }

            this.videoDetachCaptionTrack();

            if (!track) return;

            this._videoTextTrack = track;
            this._videoCueChangeHandler = () => this.videoSyncActiveCues();
            track.addEventListener('cuechange', this._videoCueChangeHandler);
            this.videoApplyCaptionMode();
        },

        videoDetachCaptionTrack() {
            if (this._videoTextTrack && this._videoCueChangeHandler) {
                this._videoTextTrack.removeEventListener('cuechange', this._videoCueChangeHandler);
            }

            if (this._videoTextTrack) this._videoTextTrack.mode = 'disabled';

            this._videoTextTrack = null;
            this._videoCueChangeHandler = null;
            this.videoActiveCues = [];
        },

        videoApplyCaptionMode() {
            const track = this._videoTextTrack;

            if (!track) return;

            if (!this.videoCaptionsEnabled) {
                track.mode = 'disabled';
                this.videoActiveCues = [];
                return;
            }

            const isSpherical = this.previewData?.playbackKind === 'pano360-video' && !this.panoFlatView;
            track.mode = isSpherical ? 'hidden' : 'showing';
            this.videoSyncActiveCues();
        },

        videoSyncActiveCues() {
            if (!this.videoCaptionsEnabled || !this._videoTextTrack) {
                this.videoActiveCues = [];
                return;
            }

            this.videoActiveCues = Array.from(this._videoTextTrack.activeCues || [])
                .map((cue) => cue.text)
                .filter(Boolean);
        },

        videoToggleCaptions() {
            this.videoCaptionsEnabled = !this.videoCaptionsEnabled;
            this.videoBindCaptionTrack();
            this.videoKeepControls();
        },

        videoOnCaptionTrackLoad() {
            this.videoBindCaptionTrack();
        },

        setVideoSpeed(rate) {
            this.videoSpeed = rate;
            if (this.$refs.videoEl) this.$refs.videoEl.playbackRate = rate;
        },

        videoToggleSpeedMenu() {
            if (!this.videoSpeedOpen) {
                this._videoSpeedBtnRect = this.$refs.videoSpeedBtn
                    ? this.$refs.videoSpeedBtn.getBoundingClientRect()
                    : null;
                this.videoKeepControls();
            }
            this.videoSpeedOpen = !this.videoSpeedOpen;
        },

        videoToggleQualityMenu() {
            if (!this.videoQualityOpen) {
                this._videoQualityBtnRect = this.$refs.videoQualityBtn
                    ? this.$refs.videoQualityBtn.getBoundingClientRect()
                    : null;
                this.videoKeepControls();
            }
            this.videoQualityOpen = !this.videoQualityOpen;
        },

        /**
         * Swapping the source resets the media element, so the playback
         * position and play/pause state are captured first and restored once
         * the new source reports its metadata. Without this, choosing
         * "Original" 12 minutes into a video would restart it from zero.
         *
         * The source swap itself is deferred to $nextTick: Vue has not yet
         * patched <source :src="videoActiveSrc"> at the point videoQuality
         * changes, so calling video.load() synchronously would reload the
         * OLD source. Waiting a tick lets the DOM update first. The restore
         * is also guarded against the previewed asset changing in between
         * (e.g. the user closes this video and opens another before the
         * tick runs) by comparing the asset id and the video element.
         */
        setVideoQuality(quality) {
            this.videoCancelQualityRestore();
            const gen = this._videoQualityGen;

            if (quality === this.videoQuality) { this.videoQualityOpen = false; return; }

            const video = this.$refs.videoEl;
            const at = video ? video.currentTime : 0;
            const wasPlaying = video ? !video.paused : false;
            const assetId = this.previewData?.id;

            this.videoQuality = quality;
            this.videoQualityOpen = false;

            try { localStorage.setItem('dam_video_quality', quality); } catch(_) {}

            if (!video) return;

            this.$nextTick(() => {
                if (gen !== this._videoQualityGen) return;
                if (video !== this.$refs.videoEl || assetId !== this.previewData?.id) return;

                const restore = () => {
                    video.removeEventListener('loadedmetadata', restore);
                    if (gen !== this._videoQualityGen) return;
                    if (this._videoQualityRestore && this._videoQualityRestore.handler === restore) {
                        this._videoQualityRestore = null;
                    }
                    if (video !== this.$refs.videoEl || assetId !== this.previewData?.id) return;
                    try { video.currentTime = at; } catch(_) {}
                    if (wasPlaying) video.play().catch(() => {}); else video.pause();
                };

                this._videoQualityRestore = { el: video, handler: restore };
                video.addEventListener('loadedmetadata', restore);
                video.load();
            });
        },

        videoSkip(sec) {
            const el = this.$refs.videoEl;
            if (!el) return;
            el.currentTime = Math.max(0, Math.min(el.duration || 0, el.currentTime + sec));
            this.videoCurrentTime = el.currentTime;
        },

        videoShowControls() {
            this.videoControlsVisible = true;
            clearTimeout(this.videoControlsTimer);
            if (this.videoIsPlaying) {
                this.videoControlsTimer = setTimeout(() => { this.videoControlsVisible = false; }, 3000);
            }
        },

        videoKeepControls() {
            this.videoControlsVisible = true;
            clearTimeout(this.videoControlsTimer);
        },

        videoTogglePlay() {
            const el = this.$refs.videoEl;
            if (!el) return;
            if (this.videoEnded) { el.currentTime = 0; this.videoCurrentTime = 0; this.videoEnded = false; }
            if (el.paused) {
                el.play(); this.videoIsPlaying = true; this.videoShowControls();
            } else {
                el.pause(); this.videoIsPlaying = false; this.videoKeepControls();
            }
            this.videoClickFlash = true;
            clearTimeout(this._videoFlashTimer);
            this._videoFlashTimer = setTimeout(() => { this.videoClickFlash = false; }, 400);
        },

        videoOnClick() {
            clearTimeout(this.videoClickTimer);
            this.videoClickTimer = setTimeout(() => { this.videoTogglePlay(); }, 220);
        },

        videoOnDblClick() {
            clearTimeout(this.videoClickTimer);
            this.videoToggleFullscreen();
        },

        /*
         * iPhone Safari has no element fullscreen (requestFullscreen and its webkit prefix are both undefined there),
         * only the native video player's webkitEnterFullscreen. Elsewhere the custom controls go fullscreen with
         * the video; prefixed Safari and a refused request fall back to the native player.
         */
        videoToggleFullscreen() {
            const container = this.$refs.videoContainer;
            if (!container) return;
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                return;
            }
            const request = container.requestFullscreen || container.webkitRequestFullscreen;
            if (!request) { this.videoEnterNativeFullscreen(); return; }
            Promise.resolve(request.call(container)).catch(() => this.videoEnterNativeFullscreen());
        },

        videoEnterNativeFullscreen() {
            const el = this.$refs.videoEl;
            try { el?.webkitEnterFullscreen?.(); } catch (_) { /* metadata not loaded yet: nothing to show */ }
        },

        videoToggleMute() {
            const el = this.$refs.videoEl;
            if (!el) return;
            this.videoIsMuted = !this.videoIsMuted;
            el.muted = this.videoIsMuted;
        },

        videoToggleLoop() {
            this.videoIsLooping = !this.videoIsLooping;
            if (this.$refs.videoEl) this.$refs.videoEl.loop = this.videoIsLooping;
        },

        async videoTogglePiP() {
            const el = this.$refs.videoEl;
            if (!el) return;
            try {
                if (document.pictureInPictureElement) await document.exitPictureInPicture();
                else await el.requestPictureInPicture();
            } catch(_) {}
        },

        videoOnSeekDown(e) {
            e.preventDefault();
            this.videoIsSeeking = true;
            this._seekFromEvent(e);
            const isTouch = e.type === 'touchstart';
            const moveEvt = isTouch ? 'touchmove' : 'mousemove';
            const endEvt  = isTouch ? 'touchend'  : 'mouseup';
            const move = (ev) => { if (this.videoIsSeeking) { ev.preventDefault(); this._seekFromEvent(ev); } };
            const up   = () => {
                this.videoIsSeeking = false;
                window.removeEventListener(moveEvt, move);
                window.removeEventListener(endEvt,  up);
            };
            window.addEventListener(moveEvt, move, { passive: false });
            window.addEventListener(endEvt,  up);
        },

        _seekFromEvent(e) {
            const container = this.$refs.videoSeekContainer;
            if (!container || !this.videoDuration) return;
            const rect    = container.getBoundingClientRect();
            const clientX = e.touches ? (e.touches[0] ?? e.changedTouches[0])?.clientX : e.clientX;
            const pct     = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            const t       = pct * this.videoDuration;
            this.videoCurrentTime = t;
            if (this.$refs.videoEl) this.$refs.videoEl.currentTime = t;
        },

        videoOnSeekHover(e) {
            if (!this.videoDuration) return;
            const container = this.$refs.videoSeekContainer;
            if (!container) return;
            const rect = container.getBoundingClientRect();
            const relX = Math.max(0, Math.min(rect.width, e.clientX - rect.left));
            this.videoSeekTooltip        = this._formatTime((relX / rect.width) * this.videoDuration);
            this.videoSeekTooltipX       = relX;
            this.videoSeekTooltipVisible = true;
        },

        videoOnSeekLeave() { this.videoSeekTooltipVisible = false; },

        videoOnVolume(e) {
            const v = parseFloat(e.target.value);
            this.videoVolume = v;
            if (this.$refs.videoEl) this.$refs.videoEl.volume = v;
            if (v > 0 && this.videoIsMuted) { this.videoIsMuted = false; if (this.$refs.videoEl) this.$refs.videoEl.muted = false; }
            try { localStorage.setItem('dam_video_volume', v); } catch(_) {}
        },

        videoOnTimeUpdate() {
            const el = this.$refs.videoEl;
            if (!el || this.videoIsSeeking) return;
            this.videoCurrentTime = el.currentTime;
        },

        videoOnLoadedMeta() {
            if (this.$refs.videoEl) this.videoDuration = this.$refs.videoEl.duration;
            this.videoBindCaptionTrack();
        },

        videoOnEnded() {
            this.videoIsPlaying = false;
            if (!this.videoIsLooping) this.videoEnded = true;
        },

        videoOnFullscreenChange() {
            this.videoIsFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement);
        },

        videoOnProgress() {
            const el = this.$refs.videoEl;
            if (!el || !el.buffered.length || !el.duration) { this.videoBuffered = 0; return; }
            this.videoBuffered = (el.buffered.end(el.buffered.length - 1) / el.duration) * 100;
        },

        videoOnWaiting() { this.videoIsBuffering = true; },

        videoOnCanPlay() { this.videoIsBuffering = false; },

        /**
         * Right-click menu on the <video>: the same frame actions as the top-right
         * buttons, at the pointer. Opened at the click point and clamped to the
         * viewport; closed by its backdrop, Escape, a reset, or picking an item.
         */
        videoOpenContextMenu(event) {
            this.videoKeepControls();
            const width = 224, height = 96, margin = 8;
            const left = Math.max(margin, Math.min(event.clientX, window.innerWidth - width - margin));
            const top = Math.max(margin, Math.min(event.clientY, window.innerHeight - height - margin));
            this.videoCtxMenuStyle = { left: `${left}px`, top: `${top}px` };
            this.videoCtxMenuOpen = true;
            if (!this._videoCtxKeyHandler) {
                // Capture phase on the document, so Escape closes the menu before the modal's window listener closes the preview.
                this._videoCtxKeyHandler = (e) => {
                    if (e.key !== 'Escape') return;
                    e.stopImmediatePropagation();
                    e.preventDefault();
                    this.videoCloseContextMenu();
                };
                document.addEventListener('keydown', this._videoCtxKeyHandler, true);
            }
        },

        videoCloseContextMenu() {
            this.videoCtxMenuOpen = false;
            if (this._videoCtxKeyHandler) {
                document.removeEventListener('keydown', this._videoCtxKeyHandler, true);
                this._videoCtxKeyHandler = null;
            }
        },

        videoSetFrameAsThumbnail() {
            this.videoCloseContextMenu();
            this.videoMenuOpen = false;
            return this.videoCaptureFrame({action: 'thumbnail'});
        },

        /**
         * A copy of `source` no larger than `max` on its longest side; `source`
         * itself is released. Returned unchanged when it already fits.
         */
        _videoFitCanvas(source, max) {
            const longest = Math.max(source.width, source.height);
            if (longest <= max) return source;
            const scale = max / longest;
            const fitted = document.createElement('canvas');
            fitted.width = Math.max(1, Math.round(source.width * scale));
            fitted.height = Math.max(1, Math.round(source.height * scale));
            fitted.getContext('2d').drawImage(source, 0, 0, fitted.width, fitted.height);
            source.width = 0; source.height = 0;
            return fitted;
        },

        videoCancelFrame() {
            this.videoFrameGeneration++;
            this.videoFrameBusy = false;
            this.videoFrameStatus = '';
            this.videoCancelQualityRestore();
            if (this._videoMasterEl) {
                const master = this._videoMasterEl;
                this._videoMasterEl = null;
                // Release the master's decoder and any in-flight fetch, not just the node.
                master.removeAttribute('src');
                master.load();
                master.remove();
            }
        },

        videoCancelQualityRestore() {
            this._videoQualityGen++;
            if (this._videoQualityRestore) {
                const { el, handler } = this._videoQualityRestore;
                el.removeEventListener('loadedmetadata', handler);
                this._videoQualityRestore = null;
            }
        },

        videoFrameLog({event, action, width = 0, height = 0}) {
            if (!window.damMediaDebug) return;
            try {
                this._videoFrameRunId ||= `frame-${Date.now()}-${Math.random().toString(36).slice(2)}`;
                const key = 'mothership.media-preview.log';
                const line = JSON.stringify({ts: new Date().toISOString(), run_id: this._videoFrameRunId, level: event.endsWith('error') ? 'error' : 'info', event, loc: 'dam/video/frame', ctx: {action, width, height, generation: this.videoFrameGeneration, asset_id: this.previewData?.id || null}});
                sessionStorage.setItem(key, [...(sessionStorage.getItem(key) || '').split('\n').filter(Boolean).slice(-99), line].join('\n'));
            } catch { /* Optional diagnostics never block capture. */ }
        },

        /**
         * The element a frame should be drawn from while a 1080p proxy plays.
         *
         * That is NOT the visible player — capturing from it would quietly
         * hand back a 1080p PNG for a 4K master (ADR 0003: what streams is a
         * delivery decision, what a frame is cut from is a fidelity
         * decision). Instead a hidden <video> holding the master is seeked to
         * the click-time timestamp `at` and drawn from.
         *
         * The element is created once per asset and reused, because the
         * expensive part is the master's initial metadata fetch, not the
         * seek. It is same-origin, so the canvas stays untainted and
         * toBlob() keeps working. videoCancelFrame() tears it down.
         *
         * Resolves to the visible player whenever the master is unavailable,
         * errors, or has not seeked within 8 s — the caller then captures at
         * whatever resolution it can actually get, and says so.
         */
        videoCaptureSource(video, at) {
            const master = this.previewData?.originalUrl;
            const usingProxy = this.previewData?.playbackIsProxy && this.videoQuality !== 'original';

            if (!usingProxy || !master) return Promise.resolve(video);

            return new Promise((resolve) => {
                let hidden = this._videoMasterEl;

                // Rebuilt after an error so one failed fetch does not pin every later capture to the proxy.
                if (!hidden || hidden.dataset.damSrc !== master || hidden.error) {
                    if (hidden) hidden.remove();
                    hidden = document.createElement('video');
                    hidden.muted = true;
                    hidden.preload = 'metadata';
                    hidden.playsInline = true;
                    hidden.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px';
                    hidden.dataset.damSrc = master;
                    hidden.src = master;
                    document.body.appendChild(hidden);
                    this._videoMasterEl = hidden;
                }

                if (hidden._damPresentation?.handle != null && typeof hidden.cancelVideoFrameCallback === 'function') {
                    hidden.cancelVideoFrameCallback(hidden._damPresentation.handle);
                }

                let settled = false;
                let timer = null;
                // Evidence that the browser presented a decoded frame for THIS seek. Alpha alone cannot say so: an
                // alpha-bearing master (VP8/VP9 WebM) can legitimately decode to a fully transparent frame. Armed
                // after the seek starts, so the frame shown before it cannot count, and re-armed for any frame
                // that is not the target's. Where the API is missing the capture keeps the alpha-based fallback.
                // A second capture at the timestamp the master already presented (Download, then Copy) gets no new
                // frame, so it inherits the evidence; any other timestamp starts without it.
                const alreadyPresented = hidden._damPresentedAt === at && ! hidden.seeking;
                if (! alreadyPresented) hidden._damPresentedAt = null;
                const presentation = {presented: alreadyPresented, handle: null};
                hidden._damPresentation = presentation;
                const arm = () => {
                    if (typeof hidden.requestVideoFrameCallback !== 'function' || hidden._damPresentation !== presentation) return;
                    presentation.handle = hidden.requestVideoFrameCallback((now, metadata) => {
                        // The target frame is presented before `seeked` fires, so `seeking` cannot tell; the frame's own
                        // media time can only rule frames out: a malformed report, or a frame stamped after `at`, is not
                        // the frame current at `at`. Frame length is unknown (any frame rate is valid, a 1 fps master's
                        // current frame at 0.5 s is stamped 0.0), so no earlier bound is invented. Callbacks are armed
                        // after this seek began and the previous one is cancelled, so a frame shown before it never counts.
                        const mediaTime = metadata?.mediaTime;
                        if (typeof mediaTime === 'number' && Number.isFinite(mediaTime) && mediaTime >= 0 && mediaTime <= at + 0.001) {
                            presentation.presented = true;
                            hidden._damPresentedAt = at;
                        } else {
                            arm();
                        }
                    });
                };
                const seek = () => {
                    try { hidden.currentTime = at; } catch (_) { finish(video); return; }
                    arm();
                };
                // A stale `seeked` from an earlier, timed-out seek must not resolve this one early.
                const onSeeked = () => { if (!hidden.seeking) finish(hidden); };
                const onError  = () => finish(video);
                const finish = (el) => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timer);
                    hidden.removeEventListener('seeked', onSeeked);
                    hidden.removeEventListener('error', onError);
                    hidden.removeEventListener('loadedmetadata', seek);
                    resolve(el);
                };

                hidden.addEventListener('seeked', onSeeked);
                hidden.addEventListener('error', onError);
                timer = setTimeout(() => finish(video), 8000);

                if (hidden.readyState >= 1) seek();
                else hidden.addEventListener('loadedmetadata', seek, {once: true});
            });
        },

        // True only when every pixel of the canvas is fully transparent (nothing was drawn). Scans in strips and
        // stops at the first visible pixel, so a real frame costs one strip and sparse content is never mistaken for blank.
        _videoCanvasBlank(canvas) {
            const context = canvas.getContext('2d');
            const strip = 64;

            for (let y = 0; y < canvas.height; y += strip) {
                const data = context.getImageData(0, y, canvas.width, Math.min(strip, canvas.height - y)).data;

                for (let i = 3; i < data.length; i += 4) {
                    if (data[i] !== 0) return false;
                }
            }

            return true;
        },

        async videoCaptureFrame({action}) {
            if (this.videoFrameBusy) return;
            this.videoKeepControls();
            const video = this.$refs.videoEl;
            if (!video || video.readyState < 2 || video.seeking || this.videoIsSeeking || !video.videoWidth || !video.videoHeight) {
                this.videoFrameStatus = 'Wait for the video frame to finish loading or seeking.';
                return;
            }
            if (action === 'copy' && (!navigator.clipboard?.write || typeof ClipboardItem === 'undefined')) {
                this.videoFrameStatus = 'Image copying is unavailable in this browser. Use Download frame.';
                return;
            }

            // 'thumbnail' cuts the same master frame, fits it to 1024 px, encodes JPEG and stores it
            // as the asset's custom thumbnail through the endpoint the modal put on previewData.
            const thumbnail = action === 'thumbnail';
            const generation = this.videoFrameGeneration;
            const source = video.currentSrc;
            const assetId = this.previewData?.id;
            const endpoint = this.previewData?.customThumbnailUrl;
            const active = () => generation === this.videoFrameGeneration && video === this.$refs.videoEl && source === video.currentSrc && assetId === this.previewData?.id;
            // The frame the user clicked on, even if playback moves on while the master seeks.
            const at = video.currentTime;
            // Snapshotted at click time, independently of whether a master URL exists: a proxy-playing
            // asset with no browser-readable original still has to say its frame is preview resolution.
            const proxyPlaying = !!(this.previewData?.playbackIsProxy && this.videoQuality !== 'original');
            // Streaming a proxy is a delivery decision; the frame is still cut from the master (ADR 0003).
            const useMaster = proxyPlaying && !!this.previewData?.originalUrl;
            let width = video.videoWidth, height = video.videoHeight;
            let gotMaster = false;
            const name = (this.previewData?.file_name || 'video').replace(/\.[^.]+$/, '').replace(/[<>:"/\\|?*\u0000-\u001f]/g, '-').slice(0, 150) || 'video';
            const filename = `${name}-frame-${at.toFixed(3).replace('.', '-')}s.${thumbnail ? 'jpg' : 'png'}`;
            this.videoFrameBusy = true;
            this.videoFrameStatus = 'Capturing frame…';
            if (!useMaster) this.videoFrameLog({event: 'capture.start', action, width, height});
            try {
                const encode = (drawn) => new Promise((resolve, reject) => {
                    const canvas = thumbnail ? this._videoFitCanvas(drawn, 1024) : drawn;
                    canvas.toBlob(blob => {
                        canvas.width = 0; canvas.height = 0;
                        if (!active()) reject(new DOMException('Viewer changed', 'AbortError'));
                        else if (blob) resolve(blob);
                        else reject(new Error('Frame encoding failed'));
                    }, thumbnail ? 'image/jpeg' : 'image/png', thumbnail ? 0.9 : undefined);
                });
                let png;
                if (useMaster) {
                    // The fallback frame, drawn before any await: the proxy keeps playing while the master
                    // loads (up to 8 s), so drawing it at resolution time would export a later frame under
                    // this click's timestamp. Every fallback — error, timeout, no decoded frame — uses this.
                    const snapshot = document.createElement('canvas');
                    snapshot.width = width; snapshot.height = height;
                    snapshot.getContext('2d').drawImage(video, 0, 0, width, height);
                    const releaseSnapshot = () => { snapshot.width = 0; snapshot.height = 0; };
                    // One chain, no await before the clipboard write below: the master seek can take up
                    // to 8 s, and a pending blob keeps the click's user activation valid for that long.
                    const captured = this.videoCaptureSource(video, at).then(async (src) => {
                        if (!active()) throw new DOMException('Viewer changed', 'AbortError');
                        // A master that seeked without a decoded frame would draw blank; use the snapshot instead.
                        gotMaster = src !== video && src.readyState >= 2;
                        let canvas = snapshot;
                        if (gotMaster) {
                            const fallbackWidth = width, fallbackHeight = height;
                            width = src.videoWidth || width;
                            height = src.videoHeight || height;
                            canvas = document.createElement('canvas');
                            canvas.width = width; canvas.height = height;
                            const draw = () => canvas.getContext('2d').drawImage(src, 0, 0, width, height);
                            draw();
                            // `seeked` with readyState 2 can still come before the first presentable frame (hardware
                            // decode under load): the draw is then fully transparent. Redraw per tick for up to 1 s unless the
                            // browser has presented a frame (a transparent alpha master is then the real content), then give up
                            // on the master and export the click-time snapshot instead of a blank image.
                            // A blank draw is a genuine transparent frame once the browser reports presenting one.
                            const presented = () => src._damPresentation?.presented === true;
                            for (let attempt = 0; attempt < 20 && this._videoCanvasBlank(canvas) && ! presented(); attempt++) {
                                await new Promise((tick) => setTimeout(tick, 50));
                                if (!active()) throw new DOMException('Viewer changed', 'AbortError');
                                draw();
                            }
                            if (this._videoCanvasBlank(canvas) && ! presented()) {
                                canvas.width = 0; canvas.height = 0;
                                gotMaster = false;
                                width = fallbackWidth; height = fallbackHeight;
                                canvas = snapshot;
                            } else {
                                releaseSnapshot();
                            }
                        }
                        this.videoFrameLog({event: 'capture.start', action, width, height});
                        return encode(canvas).then((blob) => ({blob, width, height, gotMaster}));
                    });
                    // encode() already zeroes whichever canvas it consumed; this also covers an aborted capture.
                    captured.then(releaseSnapshot, releaseSnapshot);
                    png = captured.then((result) => result.blob);
                } else {
                    const canvas = document.createElement('canvas');
                    canvas.width = width; canvas.height = height;
                    // Draw before any await: capture this frame even if playback continues.
                    canvas.getContext('2d').drawImage(video, 0, 0, width, height);
                    png = encode(canvas);
                }
                // A clipboard refusal may arrive before it consumes the encoding promise.
                png.catch(() => {});
                if (action === 'copy') {
                    // Preserve transient user activation while seeking and PNG encoding are asynchronous.
                    await navigator.clipboard.write([new ClipboardItem({'image/png': png})]);
                } else if (thumbnail) {
                    const blob = await png;
                    if (!active()) return;
                    if (!endpoint) throw new Error('This asset has no custom-thumbnail endpoint.');
                    const form = new FormData();
                    form.append('thumbnail', blob, filename);
                    const {data} = await this.$axios.post(endpoint, form);
                    // The id was fixed at click time, so the card for that asset refreshes even if the viewer moved on.
                    if (data?.thumbnail_url) this.$emitter.emit('dam:thumbnail-updated', {id: assetId, url: data.thumbnail_url});
                    this.$emitter.emit('add-flash', {type: 'success', message: 'Thumbnail updated'});
                } else {
                    const blob = await png;
                    if (!active()) return;
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url; link.download = filename;
                    document.body.appendChild(link);
                    try { link.click(); } finally {
                        link.remove();
                        setTimeout(() => URL.revokeObjectURL(url), 1000);
                    }
                }
                if (active()) {
                    // A proxy frame returned while a proxy plays is said out loud, not left to the numbers.
                    const suffix = proxyPlaying && !gotMaster ? ' — preview resolution; the original could not be read.' : '';
                    this.videoFrameStatus = (thumbnail ? 'Thumbnail updated.' : action === 'copy' ? `Frame copied (${width} × ${height}).` : `Frame downloaded (${width} × ${height}).`) + suffix;
                    this.videoFrameLog({event: 'capture.complete', action, width, height});
                }
            } catch (error) {
                if (thumbnail && error?.name !== 'AbortError') {
                    this.$emitter.emit('add-flash', {type: 'error', message: error?.response?.data?.errors?.thumbnail?.[0]
                        || error?.response?.data?.message || 'Could not set this frame as the thumbnail.'});
                }
                if (active()) {
                    this.videoFrameStatus = thumbnail ? 'Could not set this frame as the thumbnail.' : action === 'copy' ? 'Could not copy this frame. Use Download frame.' : 'Could not capture this frame. Wait for playback to load and try again.';
                    this.videoFrameLog({event: 'capture.error', action, width, height});
                }
            } finally {
                // A quality switch during the up-to-8 s master seek changes the source without bumping the
                // generation; this capture still owns the lock and must release it, or capture stays dead.
                if (active()) this.videoFrameBusy = false;
                else if (generation === this.videoFrameGeneration) { this.videoFrameBusy = false; this.videoFrameStatus = ''; }
            }
        },

        videoCopyLink(url) {
            url = url || window.location.href;
            const done = () => {
                this.videoLinkCopied = true;
                setTimeout(() => { this.videoLinkCopied = false; this.videoMenuOpen = false; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done).catch(() => this._videoCopyFallback(url, done));
            } else {
                this._videoCopyFallback(url, done);
            }
        },

        _videoCopyFallback(text, done) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
            document.body.appendChild(ta);
            ta.focus(); ta.select();
            let copied = false;
            try { copied = document.execCommand('copy'); } catch(_) {}
            document.body.removeChild(ta);
            if (copied) done();
        },
    },
};
</script>
