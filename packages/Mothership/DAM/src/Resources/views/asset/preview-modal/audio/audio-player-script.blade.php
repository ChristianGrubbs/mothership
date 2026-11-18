<script type="module">
window._damAudioPlayer = {

    data: {
        audioIsPlaying:          false,
        audioIsLoading:          false,
        audioError:              '',
        audioGeneration:         0,
        audioCurrentTime:        0,
        audioDuration:           0,
        audioVolume:             1,
        audioEnded:              false,
        audioIsSeeking:          false,
        audioIsLooping:          false,
        audioIsMuted:            false,
        audioSpeed:              1,
        audioSeekTooltip:        '',
        audioSeekTooltipX:       0,
        audioSeekTooltipVisible: false,
        audioSpeedOpen:          false,
        _audioSpeedBtnRect:      null,
    },

    computed: {
        audioCurrentTimeDisplay() { return this._formatTime(this.audioCurrentTime); },
        audioDurationDisplay()    { return this._formatTime(this.audioDuration); },

        audioSpeedMenuStyle() {
            const r = this._audioSpeedBtnRect;
            if (!r) return {};
            const menuH = 208;
            const top   = r.top - menuH - 8;
            return {
                top:  (top < 8 ? r.bottom + 8 : top) + 'px',
                left: Math.round(r.left + r.width / 2) + 'px',
                transform: 'translateX(-50%)',
            };
        },
    },

    methods: {
        audioLog(event) {
            if (!window.damMediaDebug) return;
            try {
                this._audioRunId ||= `audio-${Date.now()}-${Math.random().toString(36).slice(2)}`;
                const key = 'mothership.media-preview.log';
                const line = JSON.stringify({ts: new Date().toISOString(), run_id: this._audioRunId, level: event.endsWith('error') ? 'error' : 'info', event, loc: 'dam/audio/player', ctx: {generation: this.audioGeneration, asset_id: this.previewData?.id || null}});
                sessionStorage.setItem(key, [...(sessionStorage.getItem(key) || '').split('\n').filter(Boolean).slice(-99), line].join('\n'));
            } catch { /* Optional diagnostics never block playback. */ }
        },
        audioResetState() {
            this.audioStopOnClose();
            this.audioError = '';
            this.audioIsPlaying = false; this.audioCurrentTime = 0;
            this.audioDuration = 0; this.audioVolume = 1; this.audioEnded = false;
            this.audioIsLooping = false; this.audioIsMuted = false; this.audioSpeed = 1;
            this.audioSeekTooltipVisible = false;
            this.audioSpeedOpen = false;
            this.audioStopViz();
            try { const sa = parseFloat(localStorage.getItem('dam_audio_volume')); if (Number.isFinite(sa)) this.audioVolume = Math.max(0, Math.min(1, sa)); } catch(_) {}
        },

        audioInitEl() {
            const el = this.$refs.audioEl;
            if (!el) return;
            this.audioGeneration++;
            this.audioError = '';
            this.audioIsLoading = false;
            this.audioIsPlaying = false;
            el.pause();
            el.src = this.previewData.mediaUrl;
            el.volume = this.audioVolume;
            el.muted = this.audioIsMuted;
            el.playbackRate = this.audioSpeed;
            el.loop = this.audioIsLooping;
            el.load();
            this._audioDrawRing();
        },

        audioStopOnClose() {
            this.audioGeneration++;
            this.audioLog('stopped');
            const el = this.$refs.audioEl;
            if (el) {
                el.pause();
                el.removeAttribute('src');
                el.load();
            }
            this.audioIsPlaying = false;
            this.audioIsLoading = false;
            this.audioSpeedOpen = false;
            this._audioSeekCleanup?.();
            this._audioSeekCleanup = null;
            this.audioStopViz();
        },

        async audioPlay() {
            const el = this.$refs.audioEl;
            if (!el) return;
            const generation = ++this.audioGeneration;
            this.audioLog('play_request');
            const source = el.src;
            this.audioError = '';
            this.audioIsLoading = true;
            const current = () => generation === this.audioGeneration && el === this.$refs.audioEl && el.src === source;
            try {
                await el.play();
                if (!current()) return;
                this.audioIsPlaying = !el.paused;
                this.audioLog('play_started');
                this.audioIsLoading = false;
            } catch (error) {
                if (!current()) return;
                this.audioIsPlaying = false;
                this.audioIsLoading = false;
                this.audioLog('play_error');
                this.audioError = error.name === 'NotAllowedError'
                    ? 'Playback was blocked. Press Play to try again.'
                    : 'This audio could not play. Retry or download the original file.';
            }
        },

        audioTogglePlay() {
            const el = this.$refs.audioEl;
            if (!el) return;
            if (this.audioIsLoading || !el.paused) {
                this.audioGeneration++;
                el.pause();
                this.audioIsPlaying = false;
                this.audioIsLoading = false;
                this.$nextTick(() => this._audioDrawRing());
                return;
            }
            if (this.audioEnded) { el.currentTime = 0; this.audioCurrentTime = 0; this.audioEnded = false; }
            if (el.error) this.audioInitEl();
            return this.audioPlay();
        },

        audioEventIsCurrent(event) {
            const el = event?.target || this.$refs.audioEl;
            return el === this.$refs.audioEl && Boolean(el?.getAttribute('src'));
        },

        audioOnPlaying(event) {
            if (!this.audioEventIsCurrent(event)) return;
            this.audioIsPlaying = !event.target.paused;
            this.audioIsLoading = false;
            this.audioError = '';
        },

        audioOnPause(event) {
            if (!this.audioEventIsCurrent(event)) return;
            this.audioIsPlaying = false;
        },

        audioOnWaiting(event) {
            if (this.audioEventIsCurrent(event)) this.audioIsLoading = true;
        },

        audioOnCanPlay(event) {
            if (this.audioEventIsCurrent(event)) this.audioIsLoading = false;
        },

        audioOnError(event) {
            if (!this.audioEventIsCurrent(event)) return;
            this.audioGeneration++;
            this.audioIsPlaying = false;
            this.audioIsLoading = false;
            this.audioLog('load_error');
            this.audioError = 'This audio format could not load. Retry or download the original file.';
        },

        audioSkip(sec) {
            const el = this.$refs.audioEl;
            if (!el || !Number.isFinite(el.duration)) return;
            el.currentTime = Math.max(0, Math.min(el.duration, el.currentTime + sec));
            this.audioCurrentTime = el.currentTime;
        },

        setAudioSpeed(rate) {
            this.audioSpeed = rate;
            if (this.$refs.audioEl) this.$refs.audioEl.playbackRate = rate;
        },

        audioToggleSpeedMenu() {
            if (!this.audioSpeedOpen) {
                this._audioSpeedBtnRect = this.$refs.audioSpeedBtn
                    ? this.$refs.audioSpeedBtn.getBoundingClientRect()
                    : null;
            }
            this.audioSpeedOpen = !this.audioSpeedOpen;
        },

        audioToggleLoop() {
            this.audioIsLooping = !this.audioIsLooping;
            if (this.$refs.audioEl) this.$refs.audioEl.loop = this.audioIsLooping;
        },

        audioToggleMute() {
            const el = this.$refs.audioEl;
            if (!el) return;
            this.audioIsMuted = !this.audioIsMuted;
            el.muted = this.audioIsMuted;
        },

        audioOnVolume(e) {
            const parsed = parseFloat(e.target.value);
            if (!Number.isFinite(parsed)) return;
            const v = Math.max(0, Math.min(1, parsed));
            this.audioVolume = v;
            if (this.$refs.audioEl) this.$refs.audioEl.volume = v;
            if (this.audioIsMuted && v > 0) { this.audioIsMuted = false; this.$refs.audioEl.muted = false; }
            try { localStorage.setItem('dam_audio_volume', v); } catch(_) {}
        },

        audioOnSeekDown(e) {
            e.preventDefault();
            this._audioSeekCleanup?.();
            this.audioIsSeeking = true;
            this._audioSeekFromEvent(e);
            const isTouch = e.type === 'touchstart';
            const moveEvt = isTouch ? 'touchmove' : 'mousemove';
            const endEvt  = isTouch ? 'touchend'  : 'mouseup';
            const move = (ev) => { if (this.audioIsSeeking) { ev.preventDefault(); this._audioSeekFromEvent(ev); } };
            const up   = () => {
                this.audioIsSeeking = false;
                window.removeEventListener(moveEvt, move);
                window.removeEventListener(endEvt,  up);
            };
            this._audioSeekCleanup = up;
            window.addEventListener(moveEvt, move, { passive: false });
            window.addEventListener(endEvt,  up);
        },

        _audioSeekFromEvent(e) {
            const container = this.$refs.audioSeekContainer;
            if (!container || !this.audioDuration) return;
            const rect    = container.getBoundingClientRect();
            const clientX = e.touches ? (e.touches[0] ?? e.changedTouches[0])?.clientX : e.clientX;
            const pct     = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            const t       = pct * this.audioDuration;
            this.audioCurrentTime = t;
            if (this.$refs.audioEl) this.$refs.audioEl.currentTime = t;
        },

        audioOnSeekHover(e) {
            if (!this.audioDuration) return;
            const container = this.$refs.audioSeekContainer;
            if (!container) return;
            const rect = container.getBoundingClientRect();
            const relX = Math.max(0, Math.min(rect.width, e.clientX - rect.left));
            this.audioSeekTooltip        = this._formatTime((relX / rect.width) * this.audioDuration);
            this.audioSeekTooltipX       = relX;
            this.audioSeekTooltipVisible = true;
        },

        audioOnSeekLeave() { this.audioSeekTooltipVisible = false; },

        audioOnTimeUpdate() {
            if (!this.$refs.audioEl || this.audioIsSeeking) return;
            this.audioCurrentTime = this.$refs.audioEl.currentTime;
        },

        audioOnLoadedMeta(event) {
            if (!this.audioEventIsCurrent(event)) return;
            const duration = this.$refs.audioEl.duration;
            this.audioDuration = Number.isFinite(duration) && duration > 0 ? duration : 0;
        },

        audioOnEnded(event) {
            if (!this.audioEventIsCurrent(event)) return;
            this.audioIsLoading = false;
            this.audioIsPlaying = false;
            this.audioEnded = true;
            this.$nextTick(() => this._audioDrawRing());
        },

        _audioDrawRing() {
            const canvas = this.$refs.visualizerCanvas;
            if (!canvas) return;
            const size   = 208;
            canvas.width  = size;
            canvas.height = size;
            const ctx    = canvas.getContext('2d');
            const isDark = document.documentElement.classList.contains('dark');
            const cx     = size / 2;
            const cy     = size / 2;
            const innerR = size * 0.33;
            const bins   = 128;
            const lineW  = Math.max(1.5, (2 * Math.PI * innerR / bins) * 0.68);
            const barH   = isDark ? 2.5 : 3.5;
            const light  = isDark ? 65 : 48;

            ctx.clearRect(0, 0, size, size);
            ctx.lineWidth  = lineW;
            ctx.lineCap    = 'round';
            ctx.shadowBlur = 0;

            for (let i = 0; i < bins; i++) {
                const angle = (i / bins) * Math.PI * 2 - Math.PI / 2;
                const cos   = Math.cos(angle);
                const sin   = Math.sin(angle);
                ctx.strokeStyle = `hsla(260, 78%, ${light}%, ${isDark ? 0.4 : 0.65})`;
                ctx.beginPath();
                ctx.moveTo(cx + cos * innerR,          cy + sin * innerR);
                ctx.lineTo(cx + cos * (innerR + barH), cy + sin * (innerR + barH));
                ctx.stroke();
            }
        },

        audioStopViz() {
            const canvas = this.$refs.visualizerCanvas;
            if (canvas) canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        },
    },
};
</script>
