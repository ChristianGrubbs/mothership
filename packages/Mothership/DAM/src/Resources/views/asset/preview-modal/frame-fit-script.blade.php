{{--
    Sizes every preview frame ([data-preview-frame], see $previewFrame in card.blade.php) to the space the user can really
    see below it, so the whole frame (image, header controls, zoom toolbar, video controls) shows without scrolling
    however tall the chrome above it is (wrapping breadcrumbs, title and tabs, the panorama status strip).

    The admin layout is a fixed-height shell (body h-screen overflow-hidden) whose #main-content scrolls, so the visible
    area is that element's clientHeight, not window.innerHeight. The scroller is the closest ancestor matching
    #main-content, else the window; finding it only reads the DOM. The helper never writes a scroll position.

    Desktop (`sm` breakpoint, 525px, and up):
    height = max(visibleHeight - restingTop - GAP, FLOOR, round(visibleHeight * SHARE)), where restingTop is the frame's
    offset inside the scroller's content with every scrollTop added back (the scroller's, any scrollable ancestor between,
    and window.scrollY for the window case), so the result never depends on a scroll position. When the chrome above leaves
    at least SHARE (55%) of the visible height the frame fits entirely on screen, as it always did. Taller chrome (a stack's
    file strip above the card) cannot push the preview below SHARE of the visible height: the page scrolls by the
    difference instead, so the current file stays the dominant inspection surface. FLOOR is the absolute minimum, for
    viewports too short even for that.

    Phones (below 525px): web, pano360 and video frames keep their plain 70vh class (the inline height is cleared). An
    image frame whose image has loaded and sits at its contain-fit rest state is sized to the image instead:
    height = clamp(chrome + round(stageWidth * naturalHeight / naturalWidth), FLOOR, 70% of window.innerHeight), where the
    stage is the element holding the <img> (the viewer's flex-1 area) and chrome = frame.clientHeight - stage.clientHeight
    is whatever the frame carries around it (the zoom toolbar), measured, never hard-coded. Rest state is read from the
    image's inline transform (the viewer's pan, zoom and rotation as one transform list; identity = at rest). Zoomed,
    panned, rotated, failed or not-yet-loaded images keep the 70vh class so zoom is never clamped to a fitted height; the
    fit is redone when the image loads, the transform changes, or the window resizes. A video frame whose metadata has
    loaded is sized to the clip the same way: height = clamp(round(frameWidth * videoHeight / videoWidth), VIDEO_FLOOR,
    70% of window.innerHeight), so a landscape clip no longer sits in a 70vh black box (operator, 2026-10-30: the preview
    should be seen on a phone without scrolling). VIDEO_FLOOR keeps room for the controls that overlay the clip.
--}}
<script>
    window._damPreviewFrameFit = (() => {
        const FLOOR = 200;
        const GAP = 20;
        const SHARE = 0.55;
        const PHONE_MAX_SHARE = 0.7;
        const VIDEO_FLOOR = 240;
        const DESKTOP = '(min-width: 525px)';

        function start(root) {
            let raf = 0;
            let observed = new Set();
            const resizeObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(() => schedule()) : null;
            const isPhone = () => ! window.matchMedia(DESKTOP).matches;
            // Child changes (a frame swap) refit; so does a transform change on an <img> (zoom, pan, rotate), but only on a
            // phone, where it decides whether the image-sized height applies. The helper's own height writes are ignored.
            const mutationObserver = typeof MutationObserver === 'function'
                ? new MutationObserver((records) => {
                    // Only a child change can add or swap observed elements; a style record just refits.
                    if (records.some((record) => record.type === 'childList')) watch();
                    if (records.some((record) => record.type === 'childList' || (record.target.tagName === 'IMG' && isPhone()))) schedule();
                })
                : null;

            const scrollsY = (el) => /^(auto|scroll|overlay)$/.test(getComputedStyle(el).overflowY);

            // The image at its contain-fit rest state: the viewer's inline transform (translate, scale, rotate) is identity,
            // or within floating-point noise of it (a zoom-out lands on 0.9999999 or 1.0000001, never exactly 1).
            function atRest(img) {
                try {
                    const matrix = new DOMMatrix(img.style.transform);
                    if (matrix.isIdentity) return true;
                    const scaled = (value) => value >= 0.999 && value <= 1.001;

                    return scaled(matrix.a) && scaled(matrix.d) && Math.abs(matrix.b) <= 0.001 && Math.abs(matrix.c) <= 0.001
                        && Math.abs(matrix.e) <= 0.5 && Math.abs(matrix.f) <= 0.5;
                } catch (error) { return false; }
            }

            // The height an image or video frame takes on a phone, or '' for the plain 70vh class.
            function phoneHeight(frame) {
                if (frame.dataset.previewFrame === 'video') {
                    const video = frame.querySelector('video');
                    if (! video?.videoWidth || ! video.videoHeight || ! frame.clientWidth) return '';
                    const wanted = Math.round(frame.clientWidth * video.videoHeight / video.videoWidth);

                    return Math.round(Math.max(VIDEO_FLOOR, Math.min(wanted, PHONE_MAX_SHARE * window.innerHeight))) + 'px';
                }
                if (frame.dataset.previewFrame !== 'image') return '';
                const img = frame.querySelector('img');
                const stage = img?.parentElement;
                if (! stage || ! img.complete || ! img.naturalWidth || ! img.naturalHeight || ! atRest(img)) return '';
                const stageWidth = stage.clientWidth;
                if (! stageWidth) return '';
                const chrome = frame.clientHeight - stage.clientHeight;
                const wanted = chrome + Math.round(stageWidth * img.naturalHeight / img.naturalWidth);

                return Math.round(Math.max(FLOOR, Math.min(wanted, PHONE_MAX_SHARE * window.innerHeight))) + 'px';
            }

            function setHeight(frame, height) {
                if (frame.style.height !== height) frame.style.height = height;
            }

            function fit() {
                raf = 0;
                const frames = Array.from(root.querySelectorAll('[data-preview-frame]'));
                if (! frames.length) return;
                if (isPhone()) {
                    frames.forEach((frame) => setHeight(frame, phoneHeight(frame)));
                    return;
                }
                const box = root.closest('#main-content');
                frames.forEach((frame) => {
                    let visible = window.innerHeight;
                    let top = frame.getBoundingClientRect().top;
                    if (box) {
                        visible = box.clientHeight;
                        top -= box.getBoundingClientRect().top + box.clientTop;
                    }
                    // Add back every scroll offset between the frame and the viewport edge it is measured from.
                    for (let el = frame.parentElement; el && el !== document.body && el !== document.documentElement; el = el.parentElement) {
                        if (el === box || scrollsY(el)) top += el.scrollTop;
                        if (el === box) break;
                    }
                    if (! box) top += window.scrollY;
                    setHeight(frame, Math.round(Math.max(visible - top - GAP, FLOOR, Math.round(visible * SHARE))) + 'px');
                });
            }

            function schedule() {
                if (! raf) raf = requestAnimationFrame(fit);
            }

            // Anything above the frame can change its top: watch each ancestor and the sibling before it.
            function watch() {
                if (! resizeObserver) return;
                for (let el = root; el && el !== document.documentElement; el = el.parentElement) {
                    for (const target of [el, el.previousElementSibling]) {
                        if (target && ! observed.has(target)) { observed.add(target); resizeObserver.observe(target); }
                    }
                }
            }

            window.addEventListener('resize', schedule);
            // An image's load event does not bubble: the capturing listener sees every image under root, including a
            // swapped-in one, with no per-image bookkeeping. A video's loadedmetadata is caught the same way.
            root.addEventListener('load', schedule, true);
            root.addEventListener('loadedmetadata', schedule, true);
            mutationObserver?.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] });
            watch();
            fit();
            schedule();

            return {
                refit: fit,
                stop() {
                    window.removeEventListener('resize', schedule);
                    root.removeEventListener('load', schedule, true);
                    root.removeEventListener('loadedmetadata', schedule, true);
                    resizeObserver?.disconnect();
                    mutationObserver?.disconnect();
                    observed = new Set();
                    if (raf) cancelAnimationFrame(raf);
                    raf = 0;
                },
            };
        }

        return { start, FLOOR, GAP, SHARE };
    })();
</script>
