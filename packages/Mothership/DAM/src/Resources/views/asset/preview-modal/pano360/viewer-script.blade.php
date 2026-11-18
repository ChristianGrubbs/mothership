<script type="module">
// Three.js comes from this package's own bundle (see assets/js/app.js), not a
// CDN — the DAM is reachable on a private network where cdnjs is not.
const loadDamThree = () => window.loadDamThree();

const PANORAMA_ACTIVE_STATUSES = ['queued', 'running'];

const PANORAMA_POLL_MS = 1500;

/**
 * Delays for the bounded retry after a failed state read. A dropped request,
 * a restarting php-fpm or a brief 500 must not permanently end the poll loop:
 * the attempt would stay "running" forever and the viewer would never open.
 */
const PANORAMA_POLL_RETRY_MS = [1500, 5000, 15000];

/** Statuses where retrying is pointless — the session, not the server, is the problem. */
const PANORAMA_FATAL_POLL_STATUSES = [401, 403, 419];

/**
 * MIME types whose canPlayType() verdict is not usable as-is.
 *
 * Chrome answers '' for video/quicktime even for a .mov it decodes perfectly,
 * because it judges the container name rather than the tracks. The server only
 * admits a MOV as a direct candidate when it is H.264, so the honest question
 * to ask the browser is whether it can play that codec in ISO-BMFF.
 */
const PANORAMA_MIME_PROBES = {
    'video/quicktime': 'video/mp4; codecs="avc1.42E01E, mp4a.40.2"',
};

/**
 * MediaError codes this viewer acts on.
 *
 * DECODE means the bytes arrived and the decoder gave up — an unambiguous
 * "this browser cannot play this source". SRC_NOT_SUPPORTED is ambiguous:
 * Chrome raises it for a genuinely unsupported codec AND for a resource it
 * could not fetch or open at all (404, 5xx, or a session-expired 302 handing
 * back HTML on the authenticated media route). Acting on that blindly would
 * discard a good proxy over a network blip and — now that the API honours
 * browser_rejected — queue a real ffmpeg job for an asset that never needed
 * one, so it is resolved with a reachability probe first.
 */
const MEDIA_ERR_DECODE = 3;

const MEDIA_ERR_SRC_NOT_SUPPORTED = 4;

window._damPanoViewer = {
    data: {
        panoFlatView: false,
        panoError: null,
        panoLongitude: 0,
        panoLatitude: 0,
        panoFov: 70,
        panoLoading: false,
        // iPhone Safari has no element fullscreen; the viewer then covers the viewport itself (see panoToggleFullscreen).
        panoPseudoFullscreen: false,
        // Touch-primary devices get the pinch hint; the desktop keeps the wheel one.
        panoCoarsePointer: typeof window !== 'undefined' && !!window.matchMedia?.('(pointer: coarse)').matches,
        _panoRuntime: null,
        _panoToken: 0,

        /**
         * Build/progress workflow state. Defaults live here, beside the
         * viewer, because every component that spreads this object renders
         * the shared preview templates — a component without a server-seeded
         * panorama payload (the grid quick-look modal) still has to evaluate
         * the same markup without tripping over undefined properties.
         */
        panoramaState: null,
        panoramaUrls: {},
        panoramaPollTimer: null,
        panoramaBusy: false,
        panoramaError: null,

        /**
         * A playback URL this browser refused — canPlayType() came back empty,
         * or the <video> element raised a decode/unsupported-source error.
         *
         * Kept so a poll tick cannot re-adopt it, which would flap the viewer
         * between "playing" and "cannot play" every 1.5s, and so the Build
         * button can be offered for a source the server still believes is
         * directly playable. Keyed by URL, so a newly built proxy — a
         * different URL — is never blocked by it.
         *
         * Deliberately not underscore-prefixed: Vue 3 does not proxy data keys
         * starting with _ or $ onto the instance, so a computed reading it
         * would never re-evaluate.
         */
        panoramaRejectedUrl: null,

        /** Consecutive failed state reads, for the bounded poll retry. */
        panoramaPollAttempt: 0,

        /**
         * The source could not be fetched. Distinct from a rejection: the
         * playback is kept so it can be retried, and no build hint is armed.
         */
        panoramaLoadFailed: false,
    },

    computed: {
        panoramaRendition() {
            return this.panoramaState?.rendition || null;
        },

        panoramaIsActive() {
            return PANORAMA_ACTIVE_STATUSES.includes(this.panoramaRendition?.status);
        },

        panoramaProgressLabel() {
            const progress = Math.max(0, Math.min(100, Math.round(this.panoramaRendition?.progress ?? 0)));

            return @js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.processing')).replace(':progress', progress);
        },

        /**
         * Whether the status panel has anything to say at all: only panorama
         * assets get build controls, and a plain image or document must not
         * grow an extra empty strip above its preview.
         */
        panoramaVisible() {
            // An override stays on show, so "Never 360" can be undone from the same control (G1-14).
            // The build workflow acts on the stack's cover, so it steps aside while another of its files is on show.
            if (this.previewData?.variantFileId) return false;

            return !!this.panoramaState?.classification?.is_panorama || this.panoramaMode !== 'auto';
        },

        /** What the 360 badge says is playing: the stitched proxy, or the original once someone switched to it. */
        panoramaBadgeLabel() {
            return this.previewData?.playbackIsProxy && this.videoQuality !== 'original'
                ? @js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.badge'))
                : @js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.badge-original'));
        },

        panoramaCanOpen() {
            return ['pano360-video', 'pano360-image'].includes(this.previewData?.playbackKind);
        },

        panoramaProcessingUnavailable() {
            return this.panoramaVisible && !this.panoramaState?.processing_enabled
                && !this.panoramaCanOpen
                && (!!this.panoramaState?.classification?.requires_proxy || this.panoramaSourceRejected);
        },

        /** This browser has refused the source the server handed it. */
        panoramaSourceRejected() {
            return this.panoramaRejectedUrl !== null;
        },

        /**
         * can_build is true whenever a proxy is missing, including on a server
         * with processing switched off — offering the button there would post
         * a request the API answers with 409. Both flags have to hold.
         *
         * The second clause is the case can_build structurally cannot cover:
         * for a direct_candidate the server sets requires_proxy false, so
         * can_build and can_rebuild are both false — and a browser that just
         * refused that source would be left with a message and no button.
         * The build request carries browser_rejected so the API accepts it.
         */
        panoramaCanBuild() {
            if (! this.panoramaState?.processing_enabled || this.panoramaCanOpen || this.panoramaIsActive) {
                return false;
            }

            return !!this.panoramaState?.actions?.can_build
                || (this.panoramaSourceRejected && !!this.panoramaState?.classification?.direct_candidate);
        },

        panoramaCanRebuild() {
            return !!this.panoramaState?.processing_enabled
                && !!this.panoramaState?.actions?.can_rebuild
                && !this.panoramaLoadFailed
                && this.panoramaCanOpen;
        },

        panoramaCanCancel() {
            return !!this.panoramaState?.actions?.can_cancel;
        },

        panoramaCanRetry() {
            return !!this.panoramaState?.processing_enabled
                && !!this.panoramaState?.actions?.can_retry;
        },

        panoramaCanEdit() {
            return !!this.panoramaState?.can_edit;
        },

        panoramaMode() {
            return this.panoramaState?.classification?.mode || 'auto';
        },
    },

    methods: {
        panoMounted() {},

        panoBeforeUnmount() {
            this.panoDestroy();
            this.panoramaStopPoll();
            this.panoramaInvalidate();
        },

        /**
         * Another asset is taking over this viewer (or it is going away): answers to requests made for the last one
         * must not be applied to the new one, whether they succeed or fail. Every request remembers the generation it
         * started in and drops its answer if that has moved on.
         */
        panoramaInvalidate() {
            this._panoramaGeneration = (this._panoramaGeneration || 0) + 1;
            this.panoramaStopPoll();
        },

        panoResetState() {
            this.panoDestroy();
            // Re-arm rather than stop: resetting the viewer must not abandon
            // an attempt that is still queued or running server-side.
            this.schedulePanoramaPoll();
            this.panoFlatView = false;
            this.panoError = null;
            this.panoLongitude = 0;
            this.panoLatitude = 0;
            this.panoFov = 70;
            this.panoLoading = false;
            this.panoPseudoFullscreen = false;
            this.panoramaBusy = false;
            this.panoramaError = null;
            this.panoramaPollAttempt = 0;
            this.panoramaLoadFailed = false;
        },

        /**
         * The server can only read container metadata, and an MP4/MOV whose
         * codec metadata is missing reads as "playable" — a stripped-metadata
         * HEVC file therefore reaches the page as a direct source the browser
         * cannot decode. canPlayType() is the only authority on that, so ask
         * it before handing the file to Three.js: an empty verdict clears the
         * raw playback so the status panel offers Build (or the fallback)
         * instead of a permanently black sphere.
         */
        panoramaVerifyDirectPlayback() {
            if (this.previewData?.playbackKind !== 'pano360-video') return true;

            const mime = this.previewData?.playbackMime;

            if (!mime || typeof document === 'undefined') return true;

            const container = mime.toLowerCase().split(';')[0].trim();
            const probeMime = PANORAMA_MIME_PROBES[container] || mime;
            const probe = document.createElement('video');

            if (typeof probe.canPlayType !== 'function' || probe.canPlayType(probeMime) !== '') return true;

            this.panoramaRejectDirectSource();

            return false;
        },

        /**
         * canPlayType() only judges the declared type, and the server declares
         * the container. A file whose codec metadata was stripped therefore
         * passes the probe and still fails to decode — the <video> element's
         * error event is the only place that shows up, so it routes here too.
         *
         * A decode failure is conclusive. An unsupported-source failure is
         * not: it is equally what Chrome reports when the bytes never arrived,
         * so the resource is asked for one byte before its codec is blamed.
         */
        async panoOnVideoError(event) {
            if (this.previewData?.playbackKind !== 'pano360-video') return;

            const code = event?.target?.error?.code;

            if (code === MEDIA_ERR_DECODE) {
                this.panoramaRejectDirectSource();

                return;
            }

            if (code !== MEDIA_ERR_SRC_NOT_SUPPORTED) return;

            const url = this.previewData.playbackUrl;
            const reachable = await this.panoramaSourceReachable(url);

            // The user may have navigated or a poll may have swapped the
            // source while that round trip was in flight.
            if (this.previewData?.playbackUrl !== url) return;

            if (reachable) {
                this.panoramaRejectDirectSource();
            } else {
                this.panoramaFlagLoadFailure();
            }
        },

        /**
         * One byte, through the page's own session, is enough to separate
         * "served but undecodable" from "never arrived" — but only if the
         * probe refuses to be fooled by a login page.
         *
         * The media route is authenticated, and Laravel answers an expired
         * session on a plain web request with a 302 to /admin/login. A default
         * fetch() follows that redirect, lands on a 200 HTML page and reports
         * ok — which would blame the codec, discard a good source and arm the
         * build hint for an asset that only needed a fresh login. So:
         *
         * - redirect: 'manual', so a 3xx comes back as an opaque response
         *   (type 'opaqueredirect', status 0) instead of being followed;
         * - X-Requested-With, which is what window.axios sends on every other
         *   request from this file, so Laravel answers 401 rather than
         *   redirecting in the first place;
         * - and a media-looking Content-Type, because a 200 that hands back
         *   text/html is not a playable source no matter how ok it looks.
         *
         * Everything ambiguous resolves to "unreachable". That biases toward
         * the retry state over a rejection, which is the cheap mistake: a
         * needless retry costs a click, a needless rejection costs a real
         * ffmpeg run for an asset that never wanted one.
         */
        async panoramaSourceReachable(url) {
            if (! url || typeof fetch !== 'function') return false;

            try {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    redirect: 'manual',
                    headers: {
                        'Range': 'bytes=0-0',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.type === 'opaqueredirect' || response.redirected || ! response.ok) {
                    return false;
                }

                const contentType = (response.headers.get('content-type') || '')
                    .toLowerCase().split(';')[0].trim();

                return contentType.startsWith('video/') || contentType === 'application/octet-stream';
            } catch (error) {
                return false;
            }
        },

        /**
         * The source could not be loaded, but nothing says it is unplayable —
         * so the playback stays in place for a retry, no rejection memo is
         * recorded, and no build hint is offered. Queueing a conversion for a
         * proxy that merely 404'd once would waste a real ffmpeg run.
         */
        panoramaFlagLoadFailure() {
            this.panoDestroy();

            this.panoramaLoadFailed = true;
            this.panoramaError = @js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.load-failed'));
        },

        panoramaRetryPlayback() {
            this.panoramaLoadFailed = false;
            this.panoramaError = null;

            this.$refs.videoEl?.load();
            this.$nextTick(() => this.panoInit());
        },

        /**
         * Drop a source this browser cannot play and remember its URL, so the
         * status panel offers Build (carrying browser_rejected) instead of a
         * permanently black sphere, and no poll tick re-adopts it.
         */
        panoramaRejectDirectSource() {
            if (! this.previewData?.playbackUrl) return;

            this.panoramaRejectedUrl = this.previewData.playbackUrl;
            this.panoramaLoadFailed = false;

            this.panoDestroy();

            Object.assign(this.previewData, {
                playbackUrl: null,
                playbackMime: null,
                playbackKind: null,
                playbackLabel: null,
            });

            this.panoramaError = @js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.unplayable-source'));
        },

        async loadPanoramaState() {
            const url = this.panoramaUrls?.state;

            if (!url) return;

            const generation = this._panoramaGeneration || 0;
            const assetId = Number(this.previewData?.id);
            const startedAfter = this._panoramaSettled || 0;

            try {
                const { data } = await window.axios.get(url);

                if (generation !== (this._panoramaGeneration || 0)) return;

                this.panoramaPollAttempt = 0;
                this.applyPanoramaState(data);

                // A request made for this asset in an earlier view has an answer nobody will apply, and this read may have been
                // answered before the server committed it. Read once more when it settles (once per request, not per read).
                const settledAt = this._panoramaSettledAt?.[assetId] || 0;
                if (settledAt > startedAfter && settledAt > (this._panoramaRereadAfter?.[assetId] || 0)) {
                    (this._panoramaRereadAfter ||= {})[assetId] = settledAt;
                    this.loadPanoramaState();
                }
                for (const pending of this._panoramaPending?.[assetId] || []) {
                    if (pending.generation === generation || pending.reread) continue;
                    pending.reread = true;
                    pending.promise.then(() => { if (generation === (this._panoramaGeneration || 0)) this.loadPanoramaState(); });
                }
            } catch (error) {
                if (generation !== (this._panoramaGeneration || 0)) return;

                this.panoramaHandlePollFailure(error);
            }
        },

        /**
         * A dropped request or a restarting backend must not permanently end
         * the loop — the attempt would sit at "running" forever and the viewer
         * would never open on its own. Retry with backoff, and only give up
         * (and only then say so) once the retries are spent. A session-level
         * status is not worth retrying: no amount of waiting fixes a 401.
         */
        panoramaHandlePollFailure(error) {
            const fail = (message) => {
                this.panoramaStopPoll();
                this.panoramaError = message
                    || @js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.request-failed'));
            };

            if (PANORAMA_FATAL_POLL_STATUSES.includes(error?.response?.status)) {
                fail(error.response?.data?.message);

                return;
            }

            const delay = PANORAMA_POLL_RETRY_MS[this.panoramaPollAttempt];

            this.panoramaPollAttempt += 1;

            if (delay === undefined) {
                fail(error?.response?.data?.message);

                return;
            }

            clearTimeout(this.panoramaPollTimer);
            this.panoramaPollTimer = setTimeout(() => this.loadPanoramaState(), delay);
        },

        applyPanoramaState(state) {
            this.panoramaState = state || null;

            let playback = state?.playback || null;

            if (playback?.url && playback.url === this.panoramaRejectedUrl) {
                playback = null;
            }

            // The poll belongs to the stack's cover. While one of its other files is on screen, the answer is kept on the
            // cover's own fields (what the viewer returns to) and the player is left alone; writing it into previewData
            // would put the cover's playback source under the alternate file's name.
            const alternate = !! this.previewData?.variantFileId && !! this._coverPreviewData;
            const target = alternate ? this._coverPreviewData : (this.previewData || {});
            const current = target;

            // Only touch the player when the source actually changed —
            // otherwise every poll tick would tear down and rebuild a
            // perfectly good WebGL context. The kind is part of "changed":
            // switching an asset to force_flat keeps the same URL and only
            // demotes pano360-video to video.
            const changed = (playback?.url ?? null) !== (current.playbackUrl ?? null)
                || (playback?.kind ?? null) !== (current.playbackKind ?? null);

            if (changed) {
                if (! alternate) this.panoDestroy();

                const answered = {
                    playbackUrl: playback?.url ?? null,
                    playbackMime: playback?.mime ?? null,
                    playbackKind: playback?.kind ?? null,
                    playbackLabel: playback?.label ?? null,
                };

                Object.assign(target, answered);

                // The copy kept for returning to the cover after a stacked file is the same entry; a poll that finishes
                // while the cover is shown must reach it too, or switching away and back restores the stale source.
                if (! alternate && this._coverPreviewData) Object.assign(this._coverPreviewData, answered);

                // panoInit() returns immediately for a non-panoramic kind, so
                // this both enters and stays out of the viewer correctly. A switch of file or entry before the tick
                // makes it stale, so it runs only in the generation that asked.
                const generation = this._panoramaGeneration || 0;
                if (! alternate) this.$nextTick(() => { if (generation === (this._panoramaGeneration || 0)) this.panoInit(); });
            }

            this.schedulePanoramaPoll();
        },

        schedulePanoramaPoll() {
            clearTimeout(this.panoramaPollTimer);

            if (PANORAMA_ACTIVE_STATUSES.includes(this.panoramaState?.rendition?.status)) {
                this.panoramaPollTimer = setTimeout(() => this.loadPanoramaState(), PANORAMA_POLL_MS);
            }
        },

        panoramaStopPoll() {
            clearTimeout(this.panoramaPollTimer);
            this.panoramaPollTimer = null;
        },

        /**
         * The mutation endpoints answer with just the rendition (or the asset,
         * for a mode change), never a whole state — so the returned row is
         * merged in for an immediate status change and the authoritative
         * state comes from one follow-up read, which also arms the poll.
         */
        async panoramaRequest(action, payload = {}) {
            const url = this.panoramaUrls?.[action];

            // Building, cancelling or changing the mode acts on the cover; a host that can show another stacked file says no.
            if (!url || this.panoramaBusy || (this.assertCoverShown && !this.assertCoverShown())) return;

            const generation = this._panoramaGeneration || 0;

            this.panoramaBusy = true;
            this.panoramaError = null;

            try {
                // Kept apart from the generation, and per asset: after a switch away and back the reply below is dropped, but the
                // return read (loadPanoramaState) still has to know a request for this asset was in flight. A request for
                // one asset never touches another asset's record.
                const request = action === 'mode' ? window.axios.patch(url, payload) : window.axios.post(url, payload);
                const assetId = Number(this.previewData?.id);
                const pending = { generation, reread: false, promise: null };
                ((this._panoramaPending ||= {})[assetId] ||= new Set()).add(pending);
                const settle = () => {
                    this._panoramaPending[assetId].delete(pending);
                    // Only a request whose view was left matters to a later read; one settling in its own view is read by itself.
                    if (pending.generation !== (this._panoramaGeneration || 0)) (this._panoramaSettledAt ||= {})[assetId] = this._panoramaSettled = (this._panoramaSettled || 0) + 1;
                };
                pending.promise = Promise.resolve(request).then(settle, settle);

                const response = await request;

                if (generation !== (this._panoramaGeneration || 0)) return;

                if (response.data?.rendition) {
                    this.panoramaState = {
                        ...(this.panoramaState || {}),
                        rendition: { ...(this.panoramaState?.rendition || {}), ...response.data.rendition },
                    };
                }

                await this.loadPanoramaState();
            } catch (error) {
                if (generation !== (this._panoramaGeneration || 0)) return;

                this.panoramaError = error.response?.data?.message
                    || @js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.request-failed'));
            } finally {
                // A request for the previous asset must not unlock the new one's buttons mid-request.
                if (generation === (this._panoramaGeneration || 0)) this.panoramaBusy = false;
            }
        },

        /**
         * The hint travels with build and rebuild so the API accepts a proxy
         * for a source it classified as directly playable but this browser
         * refused — see PanoramaPreviewService::request().
         */
        panoramaBuildPayload() {
            return this.panoramaSourceRejected ? { browser_rejected: true } : {};
        },

        buildPanoramaPreview() { return this.panoramaRequest('build', this.panoramaBuildPayload()); },
        rebuildPanoramaPreview() { return this.panoramaRequest('rebuild', this.panoramaBuildPayload()); },
        retryPanoramaPreview() { return this.panoramaRequest('retry'); },
        cancelPanoramaPreview() { return this.panoramaRequest('cancel'); },
        setPanoramaMode(mode) { return this.panoramaRequest('mode', { mode }); },

        async panoInit() {
            if (!['pano360-video', 'pano360-image'].includes(this.previewData?.playbackKind) || this.panoFlatView) {
                return;
            }

            if (!this.panoramaVerifyDirectPlayback()) {
                return;
            }

            const token = ++this._panoToken;
            let runtime = null;

            if (this.previewData.playbackKind === 'pano360-image') this.panoLoading = true;

            try {
                const THREE = await loadDamThree();

                if (token !== this._panoToken || this.panoFlatView) {
                    if (token === this._panoToken) this.panoLoading = false;
                    return;
                }

                await this.$nextTick();

                // The viewer may have moved on (another file or entry, a flat view, a close) while this waited.
                if (token !== this._panoToken || this.panoFlatView || ! ['pano360-video', 'pano360-image'].includes(this.previewData?.playbackKind)) {
                    if (token === this._panoToken) this.panoLoading = false;
                    return;
                }

                const canvas = this.$refs.panoCanvas;
                const container = this.$refs.panoContainer;
                const isVideo = this.previewData.playbackKind === 'pano360-video';
                const video = isVideo ? this.$refs.videoEl : null;

                if (!canvas || !container || (isVideo && !video) || (!isVideo && !this.previewData.playbackUrl)) {
                    if (token === this._panoToken) this.panoLoading = false;
                    return;
                }

                this.panoDestroy(false);

                runtime = {
                    token,
                    renderer: null,
                    geometry: null,
                    material: null,
                    texture: null,
                    resizeObserver: null,
                    canvas,
                    pointerDown: null,
                    pointerMove: null,
                    pointerUp: null,
                    wheel: null,
                    disposed: false,
                };
                this._panoRuntime = runtime;

                const renderer = runtime.renderer = new THREE.WebGLRenderer({ canvas, antialias: true });
                const scene = new THREE.Scene();
                const camera = new THREE.PerspectiveCamera(this.panoFov, 1, 0.1, 300);
                const geometry = runtime.geometry = new THREE.SphereGeometry(100, 64, 40);
                geometry.scale(-1, 1, 1);

                let texture;

                if (isVideo) {
                    texture = new THREE.VideoTexture(video);
                } else {
                    // The template's hidden <img> already requests this URL; build the
                    // texture from it instead of downloading the panorama a second time.
                    texture = await this.panoTextureFromImage(THREE, this.$refs.panoImg);
                    if (token === this._panoToken) this.panoLoading = false;

                    if (runtime.disposed) {
                        texture.dispose();
                        return;
                    }
                }

                runtime.texture = texture;
                texture.colorSpace = THREE.SRGBColorSpace;

                // Vue returns a reactive proxy for objects stored in data(),
                // so raw runtime identity is never a safe current-init check.
                if (token !== this._panoToken || this.panoFlatView || this._panoRuntime?.token !== token) {
                    if (this._panoRuntime?.token === token) this._panoRuntime = null;
                    this.panoDisposeRuntime(runtime);
                    return;
                }

                const material = runtime.material = new THREE.MeshBasicMaterial({ map: texture });
                const sphere = new THREE.Mesh(geometry, material);
                scene.add(sphere);

                const { pinchFov } = await window.loadDamPanoPinch();

                if (runtime.disposed) return;

                let dragging = false;
                let previousX = 0;
                let previousY = 0;
                // Active pointers by id: one drags, two pinch.
                const pointers = new Map();
                let pinchStart = null;
                const pointerDistance = () => {
                    const [a, b] = [...pointers.values()];
                    return Math.hypot(a.x - b.x, a.y - b.y);
                };

                const resize = () => {
                    const width = Math.max(1, container.clientWidth);
                    const height = Math.max(1, container.clientHeight);
                    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
                    renderer.setSize(width, height, false);
                    camera.aspect = width / height;
                    camera.updateProjectionMatrix();
                };
                const pointerDown = runtime.pointerDown = (event) => {
                    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
                    canvas.setPointerCapture?.(event.pointerId);

                    if (pointers.size === 2) {
                        dragging = false;
                        pinchStart = { fov: this.panoFov, distance: pointerDistance() };
                        return;
                    }

                    dragging = true;
                    previousX = event.clientX;
                    previousY = event.clientY;
                };
                const pointerMove = runtime.pointerMove = (event) => {
                    if (pointers.has(event.pointerId)) {
                        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
                    }

                    if (pinchStart && pointers.size === 2) {
                        this.panoFov = pinchFov({ startFov: pinchStart.fov, startDistance: pinchStart.distance, distance: pointerDistance() });
                        camera.fov = this.panoFov;
                        camera.updateProjectionMatrix();
                        return;
                    }

                    if (!dragging) return;
                    this.panoLongitude -= (event.clientX - previousX) * 0.12;
                    this.panoLatitude += (event.clientY - previousY) * 0.12;
                    this.panoLatitude = Math.max(-85, Math.min(85, this.panoLatitude));
                    previousX = event.clientX;
                    previousY = event.clientY;
                };
                const pointerUp = runtime.pointerUp = (event) => {
                    pointers.delete(event.pointerId);
                    dragging = false;
                    if (pointers.size < 2) pinchStart = null;
                };
                const wheel = runtime.wheel = (event) => {
                    event.preventDefault();
                    this.panoFov = Math.max(30, Math.min(90, this.panoFov + event.deltaY * 0.04));
                    camera.fov = this.panoFov;
                    camera.updateProjectionMatrix();
                };

                canvas.addEventListener('pointerdown', pointerDown);
                canvas.addEventListener('pointermove', pointerMove);
                canvas.addEventListener('pointerup', pointerUp);
                canvas.addEventListener('pointercancel', pointerUp);
                canvas.addEventListener('wheel', wheel, { passive: false });

                const resizeObserver = runtime.resizeObserver = new ResizeObserver(resize);
                resizeObserver.observe(container);
                resize();

                const target = new THREE.Vector3();
                renderer.setAnimationLoop(() => {
                    const phi = THREE.MathUtils.degToRad(90 - this.panoLatitude);
                    const theta = THREE.MathUtils.degToRad(this.panoLongitude);
                    target.setFromSphericalCoords(1, phi, theta);
                    camera.lookAt(target);
                    renderer.render(scene, camera);
                });
            } catch (error) {
                if (token === this._panoToken) this.panoLoading = false;

                if (runtime) {
                    if (this._panoRuntime?.token === token) this._panoRuntime = null;
                    this.panoDisposeRuntime(runtime);
                }

                if (token === this._panoToken) {
                    this.panoFallback();
                }
            }
        },

        /**
         * A texture built from the template's own <img>, so the panorama is fetched
         * once. Resolves when that element has decoded, rejects when it failed.
         */
        panoTextureFromImage(THREE, image) {
            return new Promise((resolve, reject) => {
                if (!image) return reject(new Error('panorama image element missing'));

                const done = () => {
                    const texture = new THREE.Texture(image);
                    texture.needsUpdate = true;
                    resolve(texture);
                };

                if (image.complete) {
                    return image.naturalWidth > 0 ? done() : reject(new Error('panorama image failed to load'));
                }

                image.addEventListener('load', done, { once: true });
                image.addEventListener('error', () => reject(new Error('panorama image failed to load')), { once: true });
            });
        },

        panoDisposeRuntime(runtime) {
            if (!runtime || runtime.disposed) return;

            runtime.disposed = true;
            runtime.renderer?.setAnimationLoop(null);
            runtime.resizeObserver?.disconnect();

            if (runtime.canvas) {
                if (runtime.pointerDown) runtime.canvas.removeEventListener('pointerdown', runtime.pointerDown);
                if (runtime.pointerMove) runtime.canvas.removeEventListener('pointermove', runtime.pointerMove);
                if (runtime.pointerUp) {
                    runtime.canvas.removeEventListener('pointerup', runtime.pointerUp);
                    runtime.canvas.removeEventListener('pointercancel', runtime.pointerUp);
                }
                if (runtime.wheel) runtime.canvas.removeEventListener('wheel', runtime.wheel);
            }

            runtime.texture?.dispose();
            runtime.material?.dispose();
            runtime.geometry?.dispose();
            runtime.renderer?.dispose();
        },

        panoFallback() {
            this.panoDestroy(false);
            this.panoFlatView = true;
            this.panoError = @js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.three-failed'));
            this.$nextTick(() => this.videoApplyCaptionMode());
        },

        panoDestroy(invalidate = true) {
            if (invalidate) this._panoToken++;
            if (invalidate) this.panoLoading = false;

            const runtime = this._panoRuntime;
            if (!runtime) return;

            this._panoRuntime = null;
            this.panoDisposeRuntime(runtime);
        },

        panoToggleFlat() {
            this.panoFlatView = !this.panoFlatView;
            this.panoError = null;

            if (this.panoFlatView) {
                this.panoDestroy();
            } else {
                this.$nextTick(() => this.panoInit());
            }

            this.$nextTick(() => this.videoApplyCaptionMode());
        },

        panoSeek(event) {
            const video = this.$refs.videoEl;
            if (!video) return;
            video.currentTime = Number(event.target.value);
            this.videoCurrentTime = video.currentTime;
        },

        /*
         * iPhone Safari has no element fullscreen (requestFullscreen and its webkit prefix are both undefined there,
         * and the sphere is a canvas, so the native video player is no substitute). The viewer then covers the
         * viewport as a fixed 100dvh layer; its ResizeObserver resizes the renderer either way.
         */
        panoToggleFullscreen() {
            const container = this.$refs.panoContainer;
            if (!container) return;
            if (this.panoPseudoFullscreen) { this.panoPseudoFullscreen = false; return; }
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                return;
            }
            const request = container.requestFullscreen || container.webkitRequestFullscreen;
            if (!request) { this.panoPseudoFullscreen = true; return; }
            Promise.resolve(request.call(container)).catch(() => { this.panoPseudoFullscreen = true; });
        },
    },
};
</script>
