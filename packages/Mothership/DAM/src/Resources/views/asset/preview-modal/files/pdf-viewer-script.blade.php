<script type="module">
window._damPdfViewer = {
    methods: {
        async pdfInit() {
            this.pdfDestroy();
            if (!this.isOpen || !((this.previewData.extension || '').toLowerCase() === 'pdf' || this.previewData.adobe?.pdf)) return;
            const generation = this._pdfGeneration;
            const source = this.previewData.mediaUrl;
            await this.$nextTick();
            const host = this.$refs.pdfHost;
            if (!host) return;
            try {
                const { mountPdfViewer } = await window.loadDamPdfViewer();
                if (generation !== this._pdfGeneration || !this.isOpen || source !== this.previewData.mediaUrl || host !== this.$refs.pdfHost) return;
                this._pdfRuntime = mountPdfViewer(host, { url: source, fileName: this.previewData.display_name || this.previewData.file_name });
            } catch (error) {
                if (generation !== this._pdfGeneration || !this.isOpen) return;
                const notice = host.querySelector('[data-pdf-error]');
                host.querySelector('[data-pdf-loading]').hidden = true;
                notice.hidden = false;
                notice.textContent = 'PDF controls could not load. Open the original PDF to continue.';
            }
        },
        pdfDestroy() {
            this._pdfGeneration = (this._pdfGeneration || 0) + 1;
            this._pdfRuntime?.destroy();
            this._pdfRuntime = null;
        },
    },
};
</script>
