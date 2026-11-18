@php

    $playbackSource = $asset->playbackSource();
    $captionSource = $asset->captionSource();

    /**
     * The edit view hands this template the controller's panorama payload;
     * a caller that renders the modal on its own (a test, or any view that
     * only has the asset) gets the same payload built here, from the one
     * service method that owns both the state and the action URLs.
     */
    $panoramaPreview = $panoramaPreview
        ?? app(\Mothership\DAM\Services\PanoramaPreviewService::class)->payload($asset, $playbackSource);

    $mediaUrl = $playbackSource['url'] ?? \Mothership\DAM\Helpers\AssetHelper::getPreviewUrl($asset->path);

    $placeholderSvg = match($asset->file_type) {
        'video'    => asset('storage/dam/preview/video.svg'),
        'audio'    => asset('storage/dam/preview/audio.svg'),
        'document' => asset('storage/dam/preview/file.svg'),
        default    => asset('storage/dam/preview/unspecified.svg'),
    };

    // An Adobe file with no picture shows its application's mark (inline: the storage placeholders need vendor:publish).
    $adobeTile = \Mothership\DAM\Support\AdobeFormats::key($asset->extension, $asset->mime_type);
    $placeholderSvg = $adobeTile === null ? $placeholderSvg : \Mothership\DAM\Support\AdobeFormats::tiles()[$adobeTile];

    $coverArtUrl = ($asset->file_type === 'audio' && ! empty($asset->meta_data['cover_art_path']))
        ? route('admin.dam.file.cover-art', $asset->id)
        : null;

    $typeColor = 'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300';

    $bytes    = (int) ($asset->file_size ?? 0);
    $fileSize = $bytes >= 1048576
        ? number_format($bytes / 1048576, 2) . ' MB'
        : ($bytes >= 1024 ? number_format($bytes / 1024, 1) . ' KB' : ($bytes > 0 ? $bytes . ' B' : null));

    $initialPreviewData = [
        'file_name'            => $asset->file_name,
        'display_name'         => $asset->display_name,
        'extension'            => $asset->extension,
        'extension_upper'      => strtoupper($asset->extension ?? ''),
        'file_type'            => $asset->file_type,
        'asset_kind'           => $asset->asset_kind,
        'mime_type'            => $asset->mime_type,
        'adobe'                => \Mothership\DAM\Support\AdobeFormats::present(extension: $asset->extension, mime: $asset->mime_type),
        'path'                 => $asset->path,
        'width'                => $asset->width ?? '',
        'height'               => $asset->height ?? '',
        'created_at'           => $asset->created_at?->format('d M Y, H:i T'),
        'updated_at'           => $asset->updated_at?->format('d M Y, H:i T'),
        'id'                   => $asset->id,
        'modelThumbnailUrl'    => \Mothership\DAM\Services\ModelThumbnailService::cardUrl($asset),
        'pdfThumbnailUrl'      => \Mothership\DAM\Helpers\AssetHelper::pdfThumbnailUrl($asset),
        'gameAsset'            => \Mothership\DAM\Helpers\AssetHelper::gameAssetPreview($asset),
        'mediaUrl'             => $mediaUrl,
        'playbackUrl'          => $playbackSource['url'] ?? null,
        'playbackMime'         => $playbackSource['mime'] ?? null,
        'playbackKind'         => $playbackSource['kind'] ?? null,
        'playbackLabel'        => $playbackSource['label'] ?? null,
        'playbackIsProxy'      => (bool) ($playbackSource['is_proxy'] ?? false),
        'originalUrl'          => $playbackSource['original_url'] ?? null,
        'captionUrl'           => $captionSource['url'] ?? null,
        'captionLabel'         => $captionSource['label'] ?? null,
        'filesUrl'             => route('admin.dam.assets.edit', $asset->id).'?files',
        'previewPath'          => $asset->previewPath,
        'placeholderSvg'       => $placeholderSvg,
        'coverArtUrl'          => $coverArtUrl,
        'typeColor'            => $typeColor,
        'fileSize'             => $fileSize,
        'fileSizeBytes'        => $bytes,
        'downloadUrl'          => route('admin.dam.assets.download', $asset->id),
        // The preparing-download card's picture (components/download/preparing.blade.php): the thumbnail route, which serves a type placeholder when there is no picture.
        'downloadThumb'        => $asset->path ? route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]) : null,
        'downloadCompressedUrl'=> route('admin.dam.assets.download_compressed', $asset->id),
        // P6 D26: "Web MP4 (1080p)" is offered only while the published proxy is what plays.
        'webMp4Url'            => ($playbackSource['is_proxy'] ?? false) ? route('admin.dam.assets.download', ['id' => $asset->id, 'variant' => \Mothership\DAM\Services\VideoProxyStatus::WEB_MP4_VARIANT]) : null,
        'editUrl'              => route('admin.dam.assets.edit', $asset->id),
        'attachment_count'     => $stackFileCount ?? $asset->stackFileCount(),
        'customThumbnailUrl'   => route('admin.dam.assets.custom_thumbnail.store', $asset->id),
        'canSetThumbnail'      => (bool) bouncer()->hasPermission('dam.asset.edit'),
        // What the editor may be offered for this asset (Support\ImageEditing): the original (PNG, JPEG, WebP) or, for any other asset, its real thumbnail.
        ...\Mothership\DAM\Support\ImageEditing::previewFields($asset),
    ];
    $imageEditorLabels = array_replace(trans('dam::image-editor'), [
        'Close image editor' => trans('dam::app.admin.dam.asset.edit.image-editor.close'),
        'Adjustments' => trans('dam::app.admin.dam.asset.edit.image-editor.adjustments'),
        'brightness' => trans('dam::app.admin.dam.asset.edit.image-editor.brightness'),
        'contrast' => trans('dam::app.admin.dam.asset.edit.image-editor.contrast'),
        'width' => trans('dam::app.admin.dam.asset.edit.image-editor.width-px'),
        'height' => trans('dam::app.admin.dam.asset.edit.image-editor.height-px'),
    ]);
    $imageEditorLoadError = $imageEditorLabels['Image editor could not load. Reload the page and try again.'];
@endphp

<script
    type="text/x-template"
    id="v-asset-preview-modal-template"
>
    <div class="flex min-w-0 max-w-full flex-col items-center gap-2 w-full">
        <p v-if="memberPending" role="status" data-dam-member-loading>Loading selected file…</p>
        <p v-else-if="memberUnavailable" role="alert" data-dam-member-unavailable>Selected file is unavailable.</p>
        <div v-show="! memberPending && ! memberUnavailable" class="min-w-0 max-w-full w-full">
            @include('dam::asset.preview-modal.card')
        </div>
        {{-- The stack's files directly under the inline preview (operator, 2026-10-30: above it a phone pushed the asset
             below the first screen). From 1024px, where the card scrolls on its own and sits beside Details, it stays above the preview (lg:order-first). A click shows that file here. A role that may not list
             them gets the strip's own note. The open viewer carries its own strip. --}}
        <div v-if="previewData.id && ! isOpen" class="min-w-0 max-w-full w-full lg:order-first" data-dam-inline-stack>
            <v-dam-attachment-strip
                :key="'inline-strip-' + previewData.id"
                :asset-id="Number(previewData.id)"
                :count-hint="previewData.attachment_count ?? null"
                :active-file-id="previewData.variantFileId ?? null"
                can-switch
                @open-file="switchStackedFile"
            ></v-dam-attachment-strip>
        </div>
        @include('dam::asset.preview-modal.viewer-modal', ['fromDetailsPage' => true])
        @include('dam::asset.preview-modal.info-modal')
        @include('dam::asset.preview-modal.editor-modal')
    </div>
</script>

@include('dam::asset.preview-modal.image.image-viewer-script')
@include('dam::asset.preview-modal.conversion')
@include('dam::asset.preview-modal.optimization')
@include('dam::asset.preview-modal.export')
@include('dam::asset.preview-modal.video.video-player-script')
@include('dam::asset.preview-modal.audio.audio-player-script')
@include('dam::asset.preview-modal.files.pdf-viewer-script')
@include('dam::asset.preview-modal.info-panel-script')
@include('dam::asset.preview-modal.focus-script')
@include('dam::asset.preview-modal.pano360.viewer-script')
@include('dam::asset.preview-modal.model.viewer-script')
@include('dam::asset.preview-modal.web.frame-script')
@include('dam::asset.preview-modal.game-asset.viewer-script')
@include('dam::asset.preview-modal.attachments.strip-script')
@include('dam::components.shared.adobe-identity')
@include('dam::asset.preview-modal.frame-fit-script')

<script type="module">

    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = '{{ csrf_token() }}';

    app.component('v-asset-preview-modal', {
        // assertCoverShown() for the cover-changing entry points here (the editor, the panorama build); this component
        // is the source of the shown file, so its stackFileShown (below) reads its own previewData.
        mixins: [window._damShownStackFile],
        template: '#v-asset-preview-modal-template',

        data() {
            return {
                isOpen:     false,
                memberPending: @json(request()->has('file')),
                memberUnavailable: false,
                infoHover:  false,
                infoPopoverStyle: null,
                isInfoOpen: false,
                isEditOpen: false,
                // What the open editor changes: the image itself, or (for a 3D model, PDF, video) its thumbnail.
                editTarget: 'original',
                // Mirrors the asset page's lock (dam-asset-action-locked) so Share in the header disables with it.
                isAssetLocked: false,

                previewData: @json($initialPreviewData),

                // A stacked file chosen from the strip; empty while the stack's own cover shows.
                ...window._damStackSwitch.data,
                stackInline: true,

                ...window._damInfoPanel.data,

                displayDirectoryBreadcrumb: @json($directoryAncestors->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->toArray()),

                ...window._damImageViewer.data,

                ...window._damVideoPlayer.data,

                ...window._damAudioPlayer.data,

                ...window._damPanoViewer.data,

                // After the viewer spread, so the server's payload wins over
                // the shared defaults it declares.
                panoramaState: @json($panoramaPreview['state']),
                panoramaUrls: @json($panoramaPreview['urls']),
                panoramaPollTimer: null,
                panoramaBusy: false,
                panoramaError: null,
            };
        },

        watch: {
            isOpen(open) {
                if (open) {
                    if ((this.previewData.extension || '').toLowerCase() === 'pdf' || this.previewData.adobe?.pdf) this.isInfoPanelOpen = false;
                    this.pdfInit();
                } else this.pdfDestroy();
            },
            'previewData.mediaUrl'() { this.pdfInit(); },
            async isEditOpen(open) {
                if (!open) { this._editorLoad = null; this._unmountImageEditor?.(); this._unmountImageEditor = null; return; }
                // The editor opens on the file on show: the cover, or a stacked file by its entry and slot. A thumbnail is
                // only ever the cover's.
                const target = this.editorTarget();
                if (this.editTarget === 'thumbnail' && ! this.assertCoverShown()) { this.isEditOpen = false; return; }
                // Only a file the server marked editable opens, whoever set the flag.
                if (this.editTarget !== 'thumbnail' && ! this.previewData.canEditOriginal) { this.isEditOpen = false; return; }
                // Only the latest load may mount or report: a close, reopen or unmount while the bundle loads supersedes it.
                const load = this._editorLoad = { key: target.key };
                await this.$nextTick();
                try {
                    const { mountEditor, mountThumbnailEditor } = await window.loadDamImageEditor();
                    if (this._editorLoad !== load || !this.isEditOpen) return;
                    // The wait is long enough for the page to have moved to another file or entry, or for the host to be gone:
                    // then nothing mounts, and the button works again.
                    if (this.editorTarget().key !== load.key || (this.editTarget !== 'thumbnail' && ! this.previewData.canEditOriginal) || ! this.$refs.imageEditorHost) {
                        this._editorLoad = null;
                        this.isEditOpen = false;
                        return;
                    }
                    const common = {
                        assetId: target.assetId,
                        fileName: target.fileName,
                        labels: @js($imageEditorLabels),
                        onClose: () => { this.isEditOpen = false; },
                        onSaved: () => { this.isEditOpen = false; window.location.reload(); },
                        // A stacked file's requests name its slot; the resource URLs the server hands back already do.
                        ...(target.attachmentId ? { http: this.slotHttp(target.attachmentId) } : {}),
                    };
                    this._unmountImageEditor = this.editTarget === 'thumbnail'
                        ? mountThumbnailEditor(this.$refs.imageEditorHost, { ...common, thumbnailUrl: this.previewData.thumbnailEditUrl, endpoint: this.previewData.customThumbnailUrl })
                        : mountEditor(this.$refs.imageEditorHost, { ...common, endpoint: @js(route('admin.dam.assets.editor.document', ['id' => '__ASSET__'])) });
                } catch (error) {
                    if (this._editorLoad !== load) return;
                    this.isEditOpen = false; window.alert(@js($imageEditorLoadError));
                }
            },
        },

        computed: {
            stackFileShown() {
                return !! this.previewData?.variantFileId;
            },

            /** The hover popover only ever renders with a name to show; an empty box is worse than none. */
            infoPopoverVisible() {
                return this.infoHover && !! this.infoPopoverStyle && !! (this.previewData.display_name || this.previewData.file_name);
            },

            // Opened from this asset's details page: closing returns to the page itself.
            previewBackLabel() {
                return @js(trans('dam::app.admin.dam.asset.edit.preview-modal.close'));
            },

            ...window._damInfoPanel.computed,

            ...window._damImageViewer.computed,
            ...window._damVideoPlayer.computed,
            ...window._damAudioPlayer.computed,
            ...window._damPanoViewer.computed,
        },

        methods: {
            /** The file the image editor works on: the entry, the stacked file's slot (null for an entry's main file) and its name. */
            editorTarget() {
                const assetId = Number(this.previewData.variantAssetId || this.previewData.id);
                const attachmentId = this.previewData.variantAttachmentId ? Number(this.previewData.variantAttachmentId) : null;
                const fileName = this.previewData.variantFileId ? (this.previewData.variantName || this.previewData.file_name) : this.previewData.file_name;

                return { assetId, attachmentId, fileName, key: `${assetId}:${attachmentId || 'main'}:${this.editTarget}` };
            },

            /** window.axios with every request naming the stacked file's slot (`attachment`), unless its URL already does. */
            slotHttp(attachmentId) {
                const withSlot = (url) => /[?&]attachment=/.test(url) ? url : `${url}${url.includes('?') ? '&' : '?'}attachment=${attachmentId}`;

                return {
                    get: (url, config) => window.axios.get(withSlot(url), config),
                    post: (url, data, config) => window.axios.post(withSlot(url), data, config),
                };
            },

            adobeIcon(adobe) { return window.MothershipAdobe?.icon(adobe) || ''; },
            async restoreRequestedMember() {
                const raw = new URLSearchParams(window.location.search).get('file');
                if (raw === null) return;
                const fileId = Number(raw);
                const assetId = Number(this.previewData.id);
                this.memberPending = true;
                try {
                    if (! Number.isSafeInteger(fileId) || fileId <= 0) throw new Error('Unavailable member');
                    const {data} = await this.$axios.get(@js(route('admin.dam.assets.files.index', ['asset' => '__ASSET__'])).replace('__ASSET__', assetId));
                    if (Number(this.previewData.id) !== assetId || new URLSearchParams(window.location.search).get('file') !== raw) return;
                    const files = (data?.files || []).filter((file) => Number(file.asset_id) === assetId && file.attachment_id && file.stack_member !== false);
                    const file = files.find((file) => Number(file.id) === fileId);
                    if (! file) throw new Error('Unavailable member');
                    this.memberUnavailable = false;
                    if (! this.switchStackedFile(file, files)) throw new Error('Member could not be shown');
                } catch (_) {
                    if (Number(this.previewData.id) === assetId && new URLSearchParams(window.location.search).get('file') === raw) this.memberUnavailable = true;
                } finally {
                    if (Number(this.previewData.id) === assetId) this.memberPending = false;
                }
            },

            openPreview() {
                if (this.memberPending || this.memberUnavailable) return;
                this.imgResetState();
                this.videoResetState();
                this.audioResetState();
                this.panoResetState();
                this.rememberPreviewOpener(this.previewData?.id);
                this.rememberStackCover();
                this.isOpen = true;
                this.syncPreviewHistory(true);
                this.loadInfoPanel();
                document.body.style.overflow = 'hidden';
                this.$nextTick(() => {
                    if (!this.isOpen) return;
                    this.focusPreview();
                    this.videoInitEl();
                    this.audioInitEl();
                    this.panoInit();
                    this.autoplayMedia();
                    this.loadTextPreview();
                });
            },

            // Opening the lightbox pushes one history entry for this page and closing takes it back, so browser Back
            // (Command+[ on a Mac) closes the preview instead of leaving the details page. Same owner lifecycle as the
            // Library lightbox (grid-preview-modal.blade.php). The entry belongs to the page, not to one url: picking
            // another stacked file rewrites ?file= in place (replaceMemberContext), and that keeps the entry owned.
            previewEntryBase() {
                return location.origin + location.pathname;
            },

            previewOwnsHistoryEntry() {
                return history.state?.damPreview === true
                    && history.state.damPreviewOwner === this._previewHistoryOwner
                    && this.previewEntryBase() === this._previewHistoryBase;
            },

            // A marker with no live owner (a remount or hard reload landed on an abandoned entry) is dropped; every
            // other piece of history metadata stays.
            retireStalePreviewMarker() {
                if (! (history.state?.damPreview || history.state?.damPreviewOwner != null)) return;
                const { damPreview, damPreviewOwner, ...rest } = history.state;
                history.replaceState(Object.keys(rest).length ? rest : null, '', location.href);
            },

            syncPreviewHistory(open) {
                if (this.previewEntryBase() !== this._previewHistoryBase) {
                    this._previewPushed = false;
                    this._previewClosingHistory = false;
                    this._previewForwardClosed = false;
                    this._previewReopenPending = false;
                    this._previewHistoryBase = null;
                }
                if (open) {
                    // The close's Back is still queued: the entry it removes cannot back the reopened viewer, so
                    // establish a fresh one when that traversal lands (onPreviewPopState).
                    if (this._previewClosingHistory) { this._previewReopenPending = true; return; }
                    this._previewForwardClosed = false;
                    if (! this.previewOwnsHistoryEntry()) {
                        this._previewHistoryBase = this.previewEntryBase();
                        this._previewHistoryOwner = (window.__damPreviewHistorySequence = (window.__damPreviewHistorySequence || 0) + 1);
                        const { damPreview, damPreviewOwner, ...rest } = history.state ?? {};
                        history.pushState({ ...rest, damPreview: true, damPreviewOwner: this._previewHistoryOwner }, '', location.href);
                    }
                    this._previewPushed = true;
                } else {
                    this._previewReopenPending = false;
                    if (! this._previewPushed) return;
                    this._previewPushed = false;
                    if (this.previewOwnsHistoryEntry()) {
                        this._previewClosingHistory = true;
                        history.back();
                    }
                }
            },

            // Returns true when the transition belongs to this lightbox, so the AJAX router leaves the page alone.
            onPreviewPopState(event) {
                if (history.state?.damPreview) {
                    // Forward onto the closed entry stays local; a foreign preview entry still reaches the router.
                    if (! this.previewOwnsHistoryEntry() || this._previewForwardClosed || this.isOpen) return false;
                    this._previewForwardClosed = true;
                    return true;
                }
                const ownsTransition = !! (this._previewPushed || this._previewClosingHistory || this._previewForwardClosed)
                    && this.previewEntryBase() === this._previewHistoryBase;
                if (ownsTransition) event?.stopImmediatePropagation?.();
                this._previewClosingHistory = false;
                this._previewForwardClosed = false;
                if (ownsTransition) {
                    // The entry we landed on predates any file picked while the preview was open.
                    this.replaceMemberContext?.();
                    if (this._previewReopenPending) {
                        this._previewReopenPending = false;
                        if (this.isOpen) this.syncPreviewHistory(true);
                        return true;
                    }
                }
                if (this.isOpen) {
                    this._previewPushed = false;
                    this.closePreview();
                }
                return ownsTransition;
            },

            startPreviewHistory() {
                this.retireStalePreviewMarker();
                this._onPreviewPopStateBound = (event) => this.onPreviewPopState(event);
                if (window.mothership?.registerLocalHistoryHandler) {
                    this._unregisterPreviewHistory = window.mothership.registerLocalHistoryHandler(this._onPreviewPopStateBound);
                } else {
                    // Hard loads mount page components before the AJAX router initializes.
                    const handlers = window.__mothershipLocalHistoryHandlers ||= new Set();
                    handlers.add(this._onPreviewPopStateBound);
                    this._unregisterPreviewHistory = () => handlers.delete(this._onPreviewPopStateBound);
                }
            },

            autoplayMedia() {
                const tryPlay = (el) => {
                    if (!el) return;
                    const p = el.play();
                    if (p && typeof p.catch === 'function') {
                        p.catch(() => { el.muted = true; el.play().catch(() => {}); });
                    }
                };

                if (['video', 'pano360-video'].includes(this.previewData?.playbackKind)) {
                    tryPlay(this.$refs.videoEl);
                    this.videoIsPlaying = true;
                }
                // Audio never autoplays: sound the user did not ask for, while stepping through previews, is hostile.
            },

            closePreview() {
                this.resetTextPreview();
                this.videoStopOnClose();
                this.audioStopOnClose();
                this.pdfDestroy();
                this.panoDestroy();
                this.isOpen = false;
                document.body.style.overflow = '';
                this.syncPreviewHistory(false);
                this.restorePreviewFocus();
                // The next open starts on the stack's own cover, not the file last viewed.
                this.restoreStackCover();
                this.$nextTick(() => { if (!this.isOpen) this.audioInitEl(); });
            },

            handleEscape(e) {
                if (e.defaultPrevented || window._damGridPreviewActive) return;
                if (window.damShareDialogOpen?.() || window.damConfirmDialogOpen?.()) return;
                if (e.key === 'Escape' && this.isInfoOpen) { this.isInfoOpen = false; return; }
                if (this.isEditOpen) return;
                if (e.key === 'Tab' && this.isOpen) { this.trapPreviewTab(e); return; }

                const target = e.target;
                const tag = target && target.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (target && target.isContentEditable)) {
                    return;
                }

                if (!this.isOpen && !this.$refs.videoEl && !this.$refs.audioEl) return;
                const isVideoKey = this.$refs.videoEl && [' ', 'f', 'F', 'm', 'M', 'l', 'L', '+', '=', '-', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key);
                switch (e.key) {
                    case 'Escape': e.preventDefault(); e.stopPropagation?.(); this.closePreview(); break;
                    case ' ':
                        if (this.$refs.videoEl) { e.preventDefault(); this.videoTogglePlay(); }
                        else if (this.$refs.audioEl) { e.preventDefault(); this.audioTogglePlay(); }
                        break;
                    case 'f': case 'F':
                        if (this.$refs.videoEl) { e.preventDefault(); this.videoToggleFullscreen(); }
                        break;
                    case 'm': case 'M':
                        if (this.$refs.videoEl) { this.videoToggleMute(); }
                        else if (this.$refs.audioEl) { this.audioToggleMute(); }
                        break;
                    case 'ArrowLeft':
                        if (e.altKey || e.ctrlKey || e.metaKey) break;
                        if (this.isOpen && ! e.shiftKey && this.previewVariants.length > 1) { e.preventDefault(); this.navigateMember({direction: -1}); break; }
                        if (this.$refs.videoEl) { e.preventDefault(); this.videoSkip(-5); }
                        else if (this.$refs.audioEl) { e.preventDefault(); this.audioSkip(-5); }
                        break;
                    case 'ArrowRight':
                        if (e.altKey || e.ctrlKey || e.metaKey) break;
                        if (this.isOpen && ! e.shiftKey && this.previewVariants.length > 1) { e.preventDefault(); this.navigateMember({direction: 1}); break; }
                        if (this.$refs.videoEl) { e.preventDefault(); this.videoSkip(5); }
                        else if (this.$refs.audioEl) { e.preventDefault(); this.audioSkip(5); }
                        break;
                    case 'ArrowUp':
                        if (this.$refs.videoEl) {
                            e.preventDefault();
                            this.videoVolume = Math.min(1, Math.round((this.videoVolume + 0.1) * 10) / 10);
                            this.$refs.videoEl.volume = this.videoVolume;
                            if (this.videoIsMuted && this.videoVolume > 0) { this.videoIsMuted = false; this.$refs.videoEl.muted = false; }
                            try { localStorage.setItem('dam_video_volume', this.videoVolume); } catch(_) {}
                        } else if (this.$refs.audioEl) {
                            e.preventDefault();
                            this.audioVolume = Math.min(1, Math.round((this.audioVolume + 0.1) * 10) / 10);
                            this.$refs.audioEl.volume = this.audioVolume;
                            if (this.audioIsMuted && this.audioVolume > 0) { this.audioIsMuted = false; this.$refs.audioEl.muted = false; }
                            try { localStorage.setItem('dam_audio_volume', this.audioVolume); } catch(_) {}
                        }
                        break;
                    case 'ArrowDown':
                        if (this.$refs.videoEl) {
                            e.preventDefault();
                            this.videoVolume = Math.max(0, Math.round((this.videoVolume - 0.1) * 10) / 10);
                            this.$refs.videoEl.volume = this.videoVolume;
                            try { localStorage.setItem('dam_video_volume', this.videoVolume); } catch(_) {}
                        } else if (this.$refs.audioEl) {
                            e.preventDefault();
                            this.audioVolume = Math.max(0, Math.round((this.audioVolume - 0.1) * 10) / 10);
                            this.$refs.audioEl.volume = this.audioVolume;
                            try { localStorage.setItem('dam_audio_volume', this.audioVolume); } catch(_) {}
                        }
                        break;
                    case '+': case '=':
                        e.preventDefault();
                        if (this.$refs.videoEl) {
                            const rates = [0.5, 0.75, 1, 1.25, 1.5, 2];
                            const idx = rates.indexOf(this.videoSpeed);
                            if (idx < rates.length - 1) this.setVideoSpeed(rates[idx + 1]);
                        } else if (this.$refs.audioEl) {
                            const rates = [0.5, 0.75, 1, 1.25, 1.5, 2];
                            const idx = rates.indexOf(this.audioSpeed);
                            if (idx < rates.length - 1) this.setAudioSpeed(rates[idx + 1]);
                        } else { this.imgZoomIn(); }
                        break;
                    case '-':
                        e.preventDefault();
                        if (this.$refs.videoEl) {
                            const rates = [0.5, 0.75, 1, 1.25, 1.5, 2];
                            const idx = rates.indexOf(this.videoSpeed);
                            if (idx > 0) this.setVideoSpeed(rates[idx - 1]);
                        } else if (this.$refs.audioEl) {
                            const rates = [0.5, 0.75, 1, 1.25, 1.5, 2];
                            const idx = rates.indexOf(this.audioSpeed);
                            if (idx > 0) this.setAudioSpeed(rates[idx - 1]);
                        } else { this.imgZoomOut(); }
                        break;
                    case 'r': case 'R': this.imgRotateRight(); break;
                    case 'l': case 'L':
                        if (this.$refs.videoEl) this.videoToggleLoop();
                        else if (this.$refs.audioEl) this.audioToggleLoop();
                        else this.imgRotateLeft();
                        break;
                    case '0':           this.imgReset(); break;
                }
                if (isVideoKey) this.videoShowControls();
            },

            /**
             * The popover is teleported to <body> and positioned from the
             * button's viewport rect: the card lives inside an overflow
             * container that would otherwise clip an absolutely positioned box.
             */
            showInfoPopover(event) {
                const rect = event?.currentTarget?.getBoundingClientRect?.();
                if (! rect) return;
                const width = 224;
                const left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8));
                this.infoPopoverStyle = { position: 'fixed', top: `${rect.bottom + 4}px`, left: `${left}px`, width: `${width}px` };
                this.infoHover = true;
            },

            hideInfoPopover() {
                this.infoHover = false;
            },

            /**
             * A 3D thumbnail captured in this browser (viewer-script) swaps the
             * Preview-tab card image without a reload, using the revisioned URL
             * the server answered the capture with.
             */
            onModelThumbnailUpdated(data) {
                if (! data?.url || Number(data.id) !== Number(this.previewData.id)) return;

                // The capture is the cover's picture: it also goes into the copy kept for coming back to the cover,
                // and is not shown under another stacked file's name.
                if (this._coverPreviewData) this._coverPreviewData.modelThumbnailUrl = data.url;
                if (! this.previewData.variantFileId) this.previewData.modelThumbnailUrl = data.url;
            },

            onDamAssetChanged(data) {
                // The edit page also emits this event for a position-only
                // update (counter correction after mount). That payload carries
                // no asset, and replacing previewData from it would blank every
                // field the card and its info popover show.
                if (! data?.asset?.id) return;

                this.closePreview();
                this.isInfoOpen = false;
                this.isEditOpen = false;
                this.imgResetState();
                this.videoResetState();
                this.audioResetState();
                this.resetInfoPanel();
                // Polls and build requests still out for the last entry must not land on this one.
                this.panoramaInvalidate();
                this.panoResetState();
                // Another entry: nothing of the last one's chosen file carries over.
                this.previewVariant = null;
                this.previewVariants = [];
                this.memberPending = false;
                this.memberUnavailable = false;

                this.previewData = {
                    file_name:             data.asset.file_name,
                    display_name:          data.asset.display_name,
                    extension:             data.asset.extension,
                    extension_upper:       (data.asset.extension || '').toUpperCase(),
                    adobe:                 data.asset.adobe ?? null,
                    modelThumbnailUrl:     data.modelThumbnailUrl ?? null,
                    pdfThumbnailUrl:       data.pdfThumbnailUrl ?? null,
                    gameAsset:             data.gameAsset ?? null,
                    file_type:             data.asset.file_type,
                    asset_kind:            data.asset.asset_kind,
                    mime_type:             data.asset.mime_type,
                    path:                  data.asset.path,
                    width:                 data.width ?? '',
                    height:                data.height ?? '',
                    created_at:            data.createdAtFormatted ?? '',
                    updated_at:            data.updatedAtFormatted ?? '',
                    id:                    data.asset.id,
                    mediaUrl:              data.mediaUrl,
                    playbackUrl:           data.playbackSource?.url || null,
                    playbackMime:          data.playbackSource?.mime || null,
                    playbackKind:          data.playbackSource?.kind || null,
                    playbackLabel:         data.playbackSource?.label || null,
                    playbackIsProxy:       !!data.playbackSource?.is_proxy,
                    originalUrl:           data.playbackSource?.original_url || null,
                    captionUrl:            data.captionSource?.url || null,
                    captionLabel:          data.captionSource?.label || null,
                    filesUrl:              data.filesUrl || '',
                    previewPath:           data.previewPath,
                    placeholderSvg:        data.placeholderSvg,
                    coverArtUrl:           data.coverArtUrl,
                    typeColor:             data.typeColor || this.previewData.typeColor,
                    fileSize:              data.fileSize ?? null,
                    fileSizeBytes:         Number(data.asset.file_size) || 0,
                    downloadUrl:           data.downloadUrl,
                    // The thumbnail route takes the path encoded twice (see AssetHelper::pdfThumbnailUrl).
                    downloadThumb:         (data.asset.path ? @js(route('admin.dam.file.thumbnail')) + '?path=' + encodeURIComponent(encodeURIComponent(data.asset.path)) : null),
                    downloadCompressedUrl: data.downloadCompressedUrl,
                    webMp4Url:             data.webMp4Url ?? null,
                    editUrl:               data.editUrl,
                    attachment_count:      data.attachmentCount ?? null,
                    customThumbnailUrl:    @json(route('admin.dam.assets.custom_thumbnail.store', ['id' => '__ASSET__'])).replace('__ASSET__', data.asset.id),
                    canSetThumbnail:       @json((bool) bouncer()->hasPermission('dam.asset.edit')),
                    mayUseEditor:          !! data.imageEditing?.mayUseEditor,
                    canEditOriginal:       !! data.imageEditing?.canEditOriginal,
                    canEditThumbnail:      !! data.imageEditing?.canEditThumbnail,
                    thumbnailEditUrl:      data.imageEditing?.thumbnailEditUrl ?? null,
                };

                this.displayDirectoryBreadcrumb = data.directoryBreadcrumb ?? [];
                // The strip's way back to this entry's own cover.
                this.rememberStackCover();

                // show() ships the same panorama payload the edit view was
                // seeded with, so previous/next navigation carries the build
                // workflow (and any in-flight attempt) with it.
                this.panoramaUrls = data.panoramaPreview?.urls ?? {};
                this.panoramaState = data.panoramaPreview?.state ?? null;
                this.panoramaError = null;
                this.schedulePanoramaPoll();

                this.$nextTick(() => {
                    if (this.$refs.videoEl) {
                        this.videoInitEl();
                        this.$refs.videoEl.load();
                    }
                    if (this.$refs.audioEl) {
                        this.audioInitEl();
                    }
                    this.panoInit();
                });
            },

            ...window._damImageViewer.methods,

            ...window._damVideoPlayer.methods,

            ...window._damAudioPlayer.methods,
            ...window._damPdfViewer.methods,

            ...window._damInfoPanel.methods,
            ...window._damStackSwitch.methods,
            ...window._damPreviewFocus.methods,
            previewDialog() {
                if (window._damGridPreviewActive) return null;
                return window._damPreviewFocus.methods.previewDialog.call(this);
            },
            ...window._damPanoViewer.methods,

            _formatTime(s) {
                if (!s || isNaN(s)) return '0:00';
                const m = Math.floor(s / 60);
                return `${m}:${Math.floor(s % 60).toString().padStart(2, '0')}`;
            },
        },

        mounted() {
            this.$emitter.on('dam:stack-default-changed', this.refreshStackDefault);
            window.addEventListener('keydown', this.handleEscape);
            this.startPreviewFocusGuard();
            this._onAssetLock = (locked) => { this.isAssetLocked = !! locked; };
            this.$emitter.on('dam-asset-action-locked', this._onAssetLock);
            this._previewFit = window._damPreviewFrameFit?.start(this.$el) ?? null;
            this.rememberStackCover();
            this.restoreRequestedMember();
            this.imgMounted();
            this.videoMounted();
            this.panoMounted();

            this.imgResetState();
            this.videoResetState();
            this.audioResetState();
            this.panoResetState();
            this.$nextTick(() => {
                this.videoInitEl();
                this.audioInitEl();
            });

            this._onAssetChange = (data) => this.onDamAssetChanged(data);
            this.$emitter.on('dam-asset-changed', this._onAssetChange);

            this._onThumbnailUpdated = (data) => this.onModelThumbnailUpdated(data);
            this.$emitter.on('dam:thumbnail-updated', this._onThumbnailUpdated);

            this._onPreviewRequested = () => this.openPreview();
            this.$emitter.on('open-asset-preview', this._onPreviewRequested);

            // The header's "Back to cover" works from the cover's own fields, with no file list needed.
            this._onBackToCover = () => this.showStackCover();
            this.$emitter.on('dam:stack-back-to-cover', this._onBackToCover);

            this.startPreviewHistory();

            // An attempt queued before this page load keeps reporting into
            // this component without anyone pressing Build.
            this.schedulePanoramaPoll();
        },

        beforeUnmount() {
            this.$emitter.off('dam:stack-default-changed', this.refreshStackDefault);
            this.resetTextPreview();
            clearTimeout(this.panoramaPollTimer);
            this._previewFit?.stop();
            this._previewFit = null;
            window.removeEventListener('keydown', this.handleEscape);
            this.stopPreviewFocusGuard();
            this._unregisterPreviewHistory?.();
            this.$emitter.off('dam-asset-action-locked', this._onAssetLock);
            this.imgBeforeUnmount();
            this.videoBeforeUnmount();
            this.audioStopOnClose();
            this.pdfDestroy();
            this.panoBeforeUnmount();
            // Editor.vue is its own app teleported to <body>, so it outlives this modal unless unmounted here;
            // dropping the load token stops a load still in flight from mounting or alerting afterwards.
            this._editorLoad = null;
            this._unmountImageEditor?.();
            this._unmountImageEditor = null;
            if (this._onAssetChange) {
                this.$emitter.off('dam-asset-changed', this._onAssetChange);
            }
            if (this._onThumbnailUpdated) {
                this.$emitter.off('dam:thumbnail-updated', this._onThumbnailUpdated);
            }
            if (this._onPreviewRequested) {
                this.$emitter.off('open-asset-preview', this._onPreviewRequested);
            }
            if (this._onBackToCover) {
                this.$emitter.off('dam:stack-back-to-cover', this._onBackToCover);
            }
            document.body.style.overflow = '';
        },
    });
</script>
