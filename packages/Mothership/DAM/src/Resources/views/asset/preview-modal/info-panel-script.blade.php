{{-- P4a (D9): the typed field inputs the Details editor uses. --}}
@include('dam::components.metadata-fields.inputs')
<script>
    // The Info pane's remembered state: the saved choice, or open on a wide screen when none was made.
    window._damSavedInfoOpen = () => {
        try {
            const saved = localStorage.getItem('dam_preview_info_open');

            if (saved !== null) {
                return saved === 'true';
            }
        } catch (_) {}

        return window.matchMedia?.('(min-width: 1024px)').matches ?? false;
    };

    window._damInfoPanel = {
        data: {
            isInfoPanelOpen: window._damSavedInfoOpen(),
            isInfoLoading: false,
            infoData:      null,
            infoError:     '',
            infoAssetId:   null,
            _infoRequestGeneration: 0,
            infoUrlTemplate: @js(route('admin.dam.assets.info', '__id__')),
        },

        computed: {
            /** Accepting or dismissing AI tags changes the cover's record, so it is offered only with the cover on show. */
            infoCanReviewAi() {
                return !! this.infoData?.ai?.can_review && ! this.previewData?.variantFileId;
            },

            infoBuiltinFields() {
                const info = this.infoData || {};
                const shown = this.previewData?.variantFileId ? this.previewData : null;
                const stackLabel = (label) => this.infoStackLabel(label);

                // A stacked file on show: its own name, type, size and pixel size. The cover's duration, codec and
                // aspect ratio would be wrong for it, so they are left out; title and dates stay, marked as the stack's.
                const fileRows = shown ? [
                    { label: @js(trans('dam::app.admin.dam.asset.edit.file-name')), value: shown.variantName },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.file-type')), value: shown.adobe?.app || shown.file_type },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.mime')), value: shown.mime_type },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.extension')), value: shown.extension_upper },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.size')), value: shown.fileSize },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.dimensions')), value: shown.variantWidth && shown.variantHeight ? `${shown.variantWidth} × ${shown.variantHeight} px` : null },
                ] : [
                    { label: @js(trans('dam::app.admin.dam.asset.edit.file-name')), value: info.file_name },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.asset-title')), value: info.title },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.file-type')), value: info.adobe?.app || info.file_type },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.asset-kind')), value: info.asset_kind },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.mime')), value: info.mime_type },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.extension')), value: info.extension?.toUpperCase() },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.size')), value: info.file_size_human },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.aspect-ratio')), value: info.aspect_ratio?.display || 'Unknown' },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.dimensions')), value: info.dimensions?.display },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.duration')), value: info.duration?.display },
                    { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.codec')), value: info.codec },
                ];
                const stackRows = [
                    ...(shown ? [{ label: stackLabel(@js(trans('dam::app.admin.dam.asset.edit.preview-modal.asset-title'))), value: info.title }] : []),
                    { label: stackLabel(@js(trans('dam::app.admin.dam.asset.edit.created-at'))), value: info.created_at },
                    { label: stackLabel(@js(trans('dam::app.admin.dam.asset.edit.updated-at'))), value: info.updated_at },
                    { label: stackLabel(@js(trans('dam::app.admin.dam.asset.edit.preview-modal.uploaded-by'))), value: info.uploaded_by },
                    { label: stackLabel(@js(trans('dam::app.admin.dam.asset.edit.preview-modal.created-by'))), value: info.created_by },
                    { label: stackLabel(@js(trans('dam::app.admin.dam.asset.edit.preview-modal.original-created-at'))), value: info.legacy_creation_date },
                ];

                const checksum = { label: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.sha256')), value: shown ? shown.variantSha256 : info.original?.sha256 };

                return [...fileRows, ...stackRows, checksum];
            },
        },

        methods: {
            // A heading or field that belongs to the whole stack says so while one of its other files is on show.
            infoStackLabel(label) {
                return this.previewData?.variantFileId
                    ? @js(trans('dam::app.admin.dam.asset.stack-fan.info-stack-label')).replace(':label', label)
                    : label;
            },

            toggleInfoPanel() {
                this.isInfoPanelOpen = ! this.isInfoPanelOpen;

                try {
                    localStorage.setItem('dam_preview_info_open', String(this.isInfoPanelOpen));
                } catch (_) {}

                if (this.isInfoPanelOpen) {
                    this.loadInfoPanel();
                } else {
                    this.resetInfoPanel();
                }
            },

            loadInfoPanel() {
                const assetId = this.previewData?.id;

                if (! this.isInfoPanelOpen || ! assetId || this.infoAssetId === assetId) {
                    return;
                }

                const requestGeneration = ++this._infoRequestGeneration;
                this.isInfoLoading = true;
                this.infoError = '';

                this.$axios.get(this.infoUrlTemplate.replace('__id__', assetId))
                    .then(({ data }) => {
                        if (! this.isCurrentInfoRequest(requestGeneration, assetId)) return;

                        this.infoData = data;
                        this.infoAssetId = assetId;
                    })
                    .catch(error => {
                        if (! this.isCurrentInfoRequest(requestGeneration, assetId)) return;

                        this.infoData = null;
                        this.infoAssetId = null;
                        this.infoError = error.response?.data?.message
                            || @js(trans('dam::app.admin.dam.asset.edit.preview-modal.info-load-failed'));
                    })
                    .finally(() => {
                        if (! this.isCurrentInfoRequest(requestGeneration, assetId)) return;

                        this.isInfoLoading = false;
                    });
            },

            isCurrentInfoRequest(requestGeneration, assetId) {
                return requestGeneration === this._infoRequestGeneration
                    && this.isInfoPanelOpen
                    && this.previewData?.id === assetId;
            },

            resetInfoPanel() {
                this._infoRequestGeneration++;
                this.isInfoLoading = false;
                this.infoData = null;
                this.infoError = '';
                this.infoAssetId = null;
            },

            closePreviewWithInfoReset() {
                this.resetInfoPanel();
                this.closePreview();
            },

            findSimilarFromPreview() {
                const detail = {
                    id: this.previewData?.id,
                    name: this.previewData?.display_name || this.previewData?.file_name || this.infoData?.file_name || '',
                };

                this.closePreviewWithInfoReset();
                this.$emitter.emit('dam:find-similar', detail);
            },

            // Accept turns an AI suggestion into a real tag; dismiss drops it. Either way the panel shows the
            // server's fresh `ai` block, and an accept also refreshes the human tag list without a loading flash.
            reviewAiTag(term, action) {
                const assetId = this.infoAssetId ?? this.previewData?.id;

                if (! assetId) return;
                // The shared one-line guard: a host that can show a stacked file says no while it is on show.
                if (this.assertCoverShown ? ! this.assertCoverShown() : this.previewData?.variantFileId) return;

                const url = ({
                    'accept': @js(route('admin.dam.assets.ai-tags.accept', '__id__')),
                    'dismiss': @js(route('admin.dam.assets.ai-tags.dismiss', '__id__')),
                    'accept-all': @js(route('admin.dam.assets.ai-tags.accept-all', '__id__')),
                })[action].replace('__id__', assetId);

                this.$axios.post(url, term === null ? {} : { term })
                    .then(({ data }) => {
                        if (this.previewData?.id !== assetId) return;

                        this.infoData = { ...this.infoData, ai: data.ai };

                        if (action === 'dismiss') return;

                        this.$axios.get(this.infoUrlTemplate.replace('__id__', assetId))
                            .then(({ data: fresh }) => {
                                if (this.previewData?.id === assetId) this.infoData = fresh;
                            })
                            .catch(() => {});
                    })
                    .catch(() => {});
            },

            // P4a (D9): the Details editor saved; show the server's values and what is still missing.
            onInfoFieldsSaved({ fields, missing_required }) {
                if (! this.infoData) return;
                this.infoData = { ...this.infoData, fields, missing_required };
            },

            formatInfoValue(value) {
                if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) return '—';
                if (typeof value === 'boolean') return value ? 'Yes' : 'No';
                if (Array.isArray(value)) return value.join(', ');
                if (value && typeof value === 'object') {
                    return JSON.stringify(value);
                }

                return String(value);
            },
        },
    };
</script>
