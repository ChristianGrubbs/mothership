<script type="module">
window._damImageViewer = {

    data: {
        imgZoom:       1,
        imgRotation:   0,
        imgPanX:       0,
        imgPanY:       0,
        imgIsDragging: false,
        imgFailed:     false,
        imgDragStartX: 0,
        imgDragStartY: 0,
        imgPanStartX:  0,
        imgPanStartY:  0,
    },

    computed: {
        imgTransformStyle() {
            return `translate(${this.imgPanX}px,${this.imgPanY}px) scale(${this.imgZoom}) rotate(${this.imgRotation}deg)`;
        },
        imgZoomPercent() {
            return Math.round(this.imgZoom * 100);
        },
    },

    methods: {
        /**
         * A stacked file's screen-sized copy that could not be made answers 404 rather than the original, so the
         * viewer swaps to the "Preview not available" fallback (with its download) instead of a broken image.
         */
        imgOnError() {
            if (! this.previewData?.variantFileId || ! this.previewData.playbackKind) {
                // Not a stacked copy: the file itself cannot be drawn, so show the "can't preview" panel.
                this.imgFailed = true;

                return;
            }

            this.previewData = { ...this.previewData, playbackKind: null, previewPath: '', playbackUrl: null };
        },

        imgResetState() {
            this.imgZoom = 1; this.imgRotation = 0;
            this.imgPanX = 0; this.imgPanY = 0; this.imgIsDragging = false; this.imgFailed = false;
        },

        imgMounted() {
            window.addEventListener('mousemove', this.imgOnMouseMove);
            window.addEventListener('mouseup',   this.imgOnMouseUp);

            const url = new URL(window.location.href);
            if (url.searchParams.has('_img')) {
                url.searchParams.delete('_img');
                history.replaceState(null, '', url.toString());
            }
        },

        imgBeforeUnmount() {
            window.removeEventListener('mousemove', this.imgOnMouseMove);
            window.removeEventListener('mouseup',   this.imgOnMouseUp);
        },

        imgZoomIn()      { this.imgZoom = Math.min(10,  parseFloat((this.imgZoom + 0.25).toFixed(2))); },
        imgZoomOut()     { this.imgZoom = Math.max(0.1, parseFloat((this.imgZoom - 0.25).toFixed(2))); },
        imgRotateRight() { this.imgRotation = (this.imgRotation + 90) % 360; },
        imgRotateLeft()  { this.imgRotation = (this.imgRotation - 90 + 360) % 360; },
        imgFitToScreen() { this.imgZoom = 1; this.imgPanX = 0; this.imgPanY = 0; },
        imgActualSize() {
            const img = this.$refs.previewImg;
            if (!img || !img.naturalWidth || !img.offsetWidth) {
                this.imgZoom = 1; this.imgPanX = 0; this.imgPanY = 0; return;
            }
            this.imgZoom = parseFloat(Math.max(0.1, Math.min(10, img.naturalWidth / img.offsetWidth)).toFixed(3));
            this.imgPanX = 0;
            this.imgPanY = 0;
        },
        imgReset()       { this.imgZoom = 1; this.imgRotation = 0; this.imgPanX = 0; this.imgPanY = 0; },

        imgOnWheel(e) {
            const factor = e.deltaY < 0 ? 1.1 : 0.9;
            this.imgZoom = Math.min(10, Math.max(0.1, parseFloat((this.imgZoom * factor).toFixed(3))));
        },

        imgOnMouseDown(e) {
            if (e.button !== 0) return;
            this.imgIsDragging = true;
            this.imgDragStartX = e.clientX; this.imgDragStartY = e.clientY;
            this.imgPanStartX  = this.imgPanX; this.imgPanStartY = this.imgPanY;
            e.preventDefault();
        },

        imgOnMouseMove(e) {
            if (!this.imgIsDragging) return;
            this.imgPanX = this.imgPanStartX + (e.clientX - this.imgDragStartX);
            this.imgPanY = this.imgPanStartY + (e.clientY - this.imgDragStartY);
        },

        imgOnMouseUp() { this.imgIsDragging = false; },
    },
};
</script>
