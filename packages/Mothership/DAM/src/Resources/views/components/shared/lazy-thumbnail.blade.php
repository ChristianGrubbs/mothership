@once('v-dam-lazy-thumbnail')
@push('scripts')
<script type="module">
    const pendingThumbnails = new Map();
    let thumbnailObserver = null;

    function stopThumbnail(el) {
        thumbnailObserver?.unobserve(el);
        pendingThumbnails.delete(el);

        if (!pendingThumbnails.size) {
            thumbnailObserver?.disconnect();
            thumbnailObserver = null;
        }
    }

    // Until the bytes arrive the card shows a neutral skeleton instead of a blank white tile.
    function settleThumbnail(event) {
        delete event.currentTarget.dataset.thumbState;
    }

    function queueThumbnail(el, src) {
        stopThumbnail(el);
        el.removeAttribute('src');

        if (!el.damThumbnailBound) {
            el.addEventListener('load', settleThumbnail);
            el.addEventListener('error', settleThumbnail);
            el.damThumbnailBound = true;
        }

        if (!src) {
            delete el.dataset.thumbState;
            return;
        }

        el.dataset.thumbState = 'loading';

        if (!('IntersectionObserver' in window)) {
            el.src = src;
            return;
        }

        if (!thumbnailObserver) {
            thumbnailObserver = new IntersectionObserver(entries => {
                for (const entry of entries) {
                    if (entry.isIntersecting && pendingThumbnails.has(entry.target)) {
                        entry.target.src = pendingThumbnails.get(entry.target);
                        stopThumbnail(entry.target);
                    }
                }
            }, { rootMargin: '160px' });
        }

        pendingThumbnails.set(el, src);
        thumbnailObserver.observe(el);
    }

    app.directive('dam-thumbnail', {
        mounted(el, binding) {
            queueThumbnail(el, binding.value);
        },
        updated(el, binding) {
            if (binding.value !== binding.oldValue) queueThumbnail(el, binding.value);
        },
        unmounted: stopThumbnail,
    });
</script>
@endpush
@endonce
