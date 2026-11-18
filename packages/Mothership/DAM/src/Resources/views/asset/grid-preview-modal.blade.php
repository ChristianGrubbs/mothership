
<v-dam-grid-preview-modal></v-dam-grid-preview-modal>

@once('dam-preview-nav-styles')
    @push('styles')
        <style>
            {{-- Touch devices never fire :hover, so keep the prev/next buttons permanently visible there --}}
            @media (hover: none) {
                .dam-preview-nav { opacity: 1; }
            }
        </style>
    @endpush
@endonce

@pushOnce('scripts')
    <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/preview-navigation.js')) !!}</script>
    <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/details-navigation.js')) !!}</script>
    @include('dam::components.shared.asset-favorites')
    @include('dam::asset.preview-modal.image.image-viewer-script')
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

    <script type="text/x-template" id="v-dam-grid-preview-modal-template">
        <div
            v-if="isLoading"
            class="fixed inset-0 z-[10010] flex items-center justify-center bg-white dark:bg-gray-900"
        >
            <svg class="animate-spin h-10 w-10 text-primary-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="#8A2BE2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>

        @include('dam::asset.preview-modal.viewer-modal', ['hasPreviewNavigation' => true, 'showFindSimilar' => $showFindSimilar ?? false])
    </script>

    <script type="module">
        app.component('v-dam-grid-preview-modal', {
            template: '#v-dam-grid-preview-modal-template',

            data() {
                return {
                    isOpen:    false,
                    isLoading: false,
                    favorite: false,
                    favoriteSelection: null,
                    _previewRequestGeneration: 0,

                    // The visible on-screen order (ids only) the lightbox was opened from, e.g.
                    // the current grid/list/gallery page. Empty when the caller had no ordered
                    // list (picker, field, context menu without one) — prev/next then fall back
                    // to previewData.previousAssetId/nextAssetId. Set only by an incoming
                    // 'dam-open-preview' event, never by internal prev/next navigation, so
                    // stepping through the order does not lose it.
                    previewSiblings: [],
                    previewOrigin: null,
                    // Where those siblings sit in the whole Library result, so "Open details" can show "n of total".
                    previewTotal: null,
                    previewOffset: null,

                    // The stacked file a fan card asked for (D5), and the stack's files it came from; both empty
                    // for every other open. Internal prev/next never sets them, so stepping away clears them.
                    ...window._damStackSwitch.data,

                    // The exact Library scope (a folder id, or "all") the lightbox was opened
                    // from — same lifecycle as previewSiblings above: set only by an incoming
                    // 'dam-open-preview' event, carried through internal prev/next navigation and
                    // into show() requests and the "Open details" handoff, never reset by them.
                    previewDirectoryId: null,
                    // Never locked in the Library; present so the shared viewer header can bind it.
                    isAssetLocked: false,

                    previewData: {
                        id:                    null,
                        file_name:             '',
                        extension:             '',
                        extension_upper:       '',
                        adobe:                 null,
                        file_type:             '',
                        asset_kind:            '',
                        mime_type:             '',
                        path:                  '',
                        width:                 '',
                        height:                '',
                        created_at:            '',
                        updated_at:            '',
                        previousAssetId:       null,
                        nextAssetId:           null,
                        mediaUrl:              '',
                        playbackUrl:           null,
                        playbackMime:          null,
                        playbackKind:          null,
                        playbackLabel:         null,
                        playbackIsProxy:       false,
                        originalUrl:           null,
                        captionUrl:            null,
                        captionLabel:          null,
                        filesUrl:              '',
                        previewPath:           '',
                        placeholderSvg:        '',
                        gameAsset:             null,
                        coverArtUrl:           null,
                        typeColor:             'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300',
                        fileSize:              '',
                        fileSizeBytes:         0,
                        downloadUrl:           '',
                        downloadCompressedUrl: '',
                        webMp4Url:             null,
                        editUrl:               '',
                        attachment_count:      null,
                        customThumbnailUrl:    null,
                        canSetThumbnail:       false,
                        thumbnailLocked:       false,
                    },

                    ...window._damInfoPanel.data,

                    displayDirectoryBreadcrumb: [],

                    showUrlTemplate: @js(route('admin.dam.assets.show', '__id__')),

                    ...window._damImageViewer.data,
                    ...window._damVideoPlayer.data,
                    ...window._damAudioPlayer.data,
                    ...window._damPanoViewer.data,
                };
            },

            watch: {
                isOpen(open) {
                    if (open) {
                        if ((this.previewData.extension || '').toLowerCase() === 'pdf' || this.previewData.adobe?.pdf) this.isInfoPanelOpen = false;
                        this.pdfInit();
                    } else this.pdfDestroy();
                    this.syncPreviewHistory(open);
                    this.trackPreviewOpened();
                },
                'previewData.id'() { this.trackPreviewOpened(); },
                'previewData.mediaUrl'() { this.pdfInit(); },
            },

            computed: {
                previewFolderName() {
                    const crumbs = this.displayDirectoryBreadcrumb || [];

                    return crumbs.length
                        ? crumbs[crumbs.length - 1].name
                        : @js(trans('dam::app.admin.dam.asset.edit.preview-modal.assets'));
                },

                // Closing the lightbox reveals the Library view it was opened from, so the back
                // label names that view: the browsed folder, or the search/"all files" results
                // ('all'). A bare-id caller (context menu, picker, field) sent no scope, so the
                // button just says it closes rather than naming the asset's own folder.
                previewBackLabel() {
                    if (this.previewOrigin?.kind === 'chooser') return 'Back to files';
                    if (this.previewOrigin?.kind === 'files') return 'Back to Files';
                    const scope = this.previewDirectoryId;
                    if (scope === 'all') return @js(trans('dam::app.admin.dam.asset.edit.preview-modal.back-to-results'));
                    if (scope == null) return @js(trans('dam::app.admin.dam.asset.edit.preview-modal.close'));

                    return @js(trans('dam::app.admin.dam.asset.edit.preview-modal.back-to')) + ' ' + this.previewFolderName;
                },

                // The arrows always move between Library items, however the preview was opened (the fan, its "Open
                // main file", a card, a neighbour); a stack's own files are reached through its strip (LIB-03).
                previewNeighbours() {
                    return window.MothershipPreviewNavigation.neighbours({
                        siblings:         this.previewSiblings,
                        currentId:        this.previewData.id,
                        previousAssetId:  this.previewData.previousAssetId,
                        nextAssetId:      this.previewData.nextAssetId,
                    });
                },

                ...window._damInfoPanel.computed,

                ...window._damImageViewer.computed,
                ...window._damVideoPlayer.computed,
                ...window._damAudioPlayer.computed,
                ...window._damPanoViewer.computed,
            },

            mounted() {
                this.$emitter.on('dam:stack-default-changed', this.refreshStackDefault);
                this.favoriteSelection = window.MothershipFavoriteState.selection(window.MothershipFavorites);
                // Capture the actual Library control before its click emits dam-open-preview, even on browsers
                // that leave activeElement on body after a mouse click. DOM references stay session-local.
                this._onPreviewOriginClick = (event) => {
                    const trigger = event.target?.closest?.('button, a[href], [tabindex], [role="button"]') || null;
                    this._previewClickTrigger = trigger;
                    clearTimeout(this._previewClickTriggerTimer);
                    // Native clicks checkpoint microtasks between capture and bubble listeners. Keep the trigger
                    // through the whole click task, then discard it before a later programmatic open.
                    this._previewClickTriggerTimer = setTimeout(() => { this._previewClickTrigger = null; }, 0);
                };
                document.addEventListener?.('click', this._onPreviewOriginClick, true);
                // mitt's emit(type, event?) carries exactly one payload, so an ordered caller
                // (grid/list/gallery) emits a single { id, siblings } object; a bare-id caller
                // (picker/field/context menu) emits the id itself, with no siblings known.
                this._onPreviewRequested = (payload) => {
                    const ordered = payload !== null && typeof payload === 'object';
                    // The control that opened the lightbox, so closing it hands focus back there; a step to another file keeps the first.
                    this.rememberPreviewOpener(ordered ? payload.id : payload);
                    this.previewOrigin = ordered && payload.origin ? payload.origin : {
                        kind: 'library', assetId: Number(ordered ? payload.id : payload), fileId: null,
                        trigger: this._previewClickTrigger || document.activeElement, scrollTop: window.scrollY || 0,
                    };
                    window._damGridPreviewActive = true;
                    this.$emitter.emit('dam-preview-layer', {open: true});
                    // A stack's fan names the file to show (D5); every other caller shows the asset's own.
                    this.previewVariant = ordered ? (payload.file ?? null) : null;
                    this.previewVariants = ordered && payload.file !== undefined ? (payload.variants ?? []) : [];
                    this.previewTotal = ordered ? (payload.total ?? null) : null;
                    this.previewOffset = ordered ? (payload.offset ?? null) : null;
                    this.openById(
                        ordered ? payload.id : payload,
                        ordered ? (payload.siblings ?? []) : [],
                        ordered ? (payload.directoryId ?? null) : null,
                    );
                };
                this.$emitter.on('dam-open-preview', this._onPreviewRequested);
                // A stack's fan dismissed while a chosen file was still loading: drop that request.
                this._onPreviewCancel = () => {
                    if (this.isOpen || ! this.isLoading) return;
                    this._previewRequestGeneration++;
                    this.isLoading = false;
                    window._damGridPreviewActive = false;
                    document.body.style.overflow = '';
                    this.$emitter.emit('dam-preview-layer', {open: false});
                    this.restorePreviewOrigin();
                };
                this.$emitter.on('dam-preview-cancel', this._onPreviewCancel);

                window.addEventListener('keydown', this.handleEscape);
                this.startPreviewFocusGuard();

                // An open preview is a history entry, so Back closes it instead of leaving the Library.
                if (history.state?.damPreview || history.state?.damPreviewOwner != null) {
                    const { damPreview, damPreviewOwner, ...rest } = history.state;
                    history.replaceState(Object.keys(rest).length ? rest : null, '', location.href);
                }
                this._retirePreviewHistory = (event) => {
                    // A background refresh of this listing may update its snapshot while our viewer loads or
                    // stays open. Only this entry's live owner can retain the marker; closed Forward cannot.
                    if (event?.detail?.sameViewReplace && (this.isOpen || this.isLoading)
                        && history.state?.damPreview === true
                        && history.state.damPreviewOwner === this._previewHistoryOwner
                        && location.href === this._previewHistoryUrl) {
                        event.detail.preserved = true;
                        return;
                    }
                    this._previewForwardClosed = false;
                    this._previewPushed = false;
                    this._previewClosingHistory = false;
                    this._previewHistoryUrl = null;
                };
                window.addEventListener('dam:preview-history-retired', this._retirePreviewHistory);
                this._onPreviewPopState = (event) => {
                    if (history.state?.damPreview) {
                        if (history.state.damPreviewOwner !== this._previewHistoryOwner
                            || location.href !== this._previewHistoryUrl
                            || this._previewForwardClosed || this.isOpen) return false;
                        // Forward retains the closed chooser. Keep ownership of this retained entry so
                        // repeated Back/Forward stays local; a foreign return to it still reaches the router.
                        this._previewForwardClosed = true;
                        return true;
                    }
                    // Returning from this viewer's same-page entry belongs to the viewer. Letting the global
                    // AJAX router handle it would replace the Library and discard the chooser just restored.
                    const ownsTransition = (this._previewPushed || this._previewClosingHistory || this._previewForwardClosed)
                        && location.href === this._previewHistoryUrl;
                    if (ownsTransition) event?.stopImmediatePropagation?.();
                    this._previewClosingHistory = false;
                    this._previewForwardClosed = false;
                    // Back pressed while a next/prev (or first) load is in flight: the entry is already gone, so drop
                    // the pending response rather than let it reopen the modal and push a new entry.
                    if (this.isLoading) {
                        this._previewRequestGeneration++;
                        this.isLoading = false;
                        this._previewPushed = false;
                        window._damGridPreviewActive = false;
                        document.body.style.overflow = '';
                        this.$emitter.emit('dam-preview-layer', {open: false});
                        this.restorePreviewOrigin();
                        return ownsTransition;
                    }
                    if (! this.isOpen) return ownsTransition;
                    this._previewPushed = false;
                    this.closePreview();
                    return ownsTransition;
                };
                if (window.mothership?.registerLocalHistoryHandler) {
                    this._unregisterPreviewHistory = window.mothership.registerLocalHistoryHandler(this._onPreviewPopState);
                } else {
                    // Hard loads mount page components before the AJAX router initializes.
                    const handlers = window.__mothershipLocalHistoryHandlers ||= new Set();
                    handlers.add(this._onPreviewPopState);
                    this._unregisterPreviewHistory = () => handlers.delete(this._onPreviewPopState);
                }

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
            },

            beforeUnmount() {
                this.$emitter.off('dam:stack-default-changed', this.refreshStackDefault);
                clearTimeout(this._previewClickTriggerTimer);
                this._previewClickTrigger = null;
                document.removeEventListener?.('click', this._onPreviewOriginClick, true);
                window._damGridPreviewActive = false;
                clearTimeout(this._previewTrackTimer);
                this._previewRequestGeneration++;
                this.favoriteSelection?.select(0);
                window.removeEventListener('keydown', this.handleEscape);
                this.stopPreviewFocusGuard();
                window.removeEventListener('dam:preview-history-retired', this._retirePreviewHistory);
                this._unregisterPreviewHistory?.();
                this.imgBeforeUnmount();
                this.videoBeforeUnmount();
                this.audioStopOnClose();
                this.pdfDestroy();
                this.resetTextPreview();
                this.panoBeforeUnmount();
                this.resetInfoPanel();
                if (this._onPreviewRequested) {
                    this.$emitter.off('dam-open-preview', this._onPreviewRequested);
                }
                if (this._onPreviewCancel) this.$emitter.off('dam-preview-cancel', this._onPreviewCancel);
                document.body.style.overflow = '';
            },

            methods: {
                ...window._damImageViewer.methods,
                ...window._damVideoPlayer.methods,
                ...window._damAudioPlayer.methods,
                ...window._damPdfViewer.methods,
                ...window._damInfoPanel.methods,
                ...window._damStackSwitch.methods,
                ...window._damPreviewFocus.methods,
                ...window._damPanoViewer.methods,

                // P1b usage analytics: a file counts as viewed once it has been on screen for a second.
                trackPreviewOpened() {
                    clearTimeout(this._previewTrackTimer);
                    const id = Number(this.previewData.id);

                    if (! this.isOpen || ! id) return;

                    this._previewTrackTimer = setTimeout(() => window.damTrack?.('asset.preview.opened', id, { visible_ms: 1000 }, 'modal'), 1000);
                },

                async loadFavorite() {
                    await this.favoriteSelection.load(this.previewData.id);
                    const state = this.favoriteSelection.snapshot();
                    if (Number(this.previewData.id) !== state.id) return;
                    this.favorite = state.favorite;
                    if (state.error) throw new Error(state.error);
                },

                async toggleFavorite() {
                    try {
                        await this.favoriteSelection.toggle();
                        const state = this.favoriteSelection.snapshot();
                        if (Number(this.previewData.id) !== state.id) return;
                        this.favorite = state.favorite;
                        if (state.error) throw new Error(state.error);
                    } catch (error) {
                        this.$emitter.emit('add-flash', {type:'error', message:error.response?.data?.message || error.message});
                    }
                },

                // Opening pushes one history entry and closing takes it back. Prev/next re-opens through a closed,
                // loading state (openById) and keeps the entry it has.
                syncPreviewHistory(open) {
                    if (location.href !== this._previewHistoryUrl) this._retirePreviewHistory?.();
                    const ownsCurrentEntry = history.state?.damPreview === true
                        && history.state.damPreviewOwner === this._previewHistoryOwner
                        && location.href === this._previewHistoryUrl;
                    if (open) {
                        this._previewForwardClosed = false;
                        if (! ownsCurrentEntry) {
                            this._previewHistoryUrl = location.href;
                            this._previewHistoryOwner = (window.__damPreviewHistorySequence = (window.__damPreviewHistorySequence || 0) + 1);
                            const { damPreview, damPreviewOwner, ...rest } = history.state ?? {};
                            history.pushState({ ...rest, damPreview: true, damPreviewOwner: this._previewHistoryOwner }, '', location.href);
                        }
                        this._previewPushed = true;
                    } else if (! this.isLoading && this._previewPushed) {
                        this._previewPushed = false;
                        if (ownsCurrentEntry) {
                            this._previewClosingHistory = true;
                            history.back();
                        }
                    }
                },

                _formatTime(s) {
                    if (!s || isNaN(s)) return '0:00';
                    const m = Math.floor(s / 60);
                    return `${m}:${Math.floor(s % 60).toString().padStart(2, '0')}`;
                },

                // `siblings`/`directoryId`, when passed, are the caller's visible on-screen order
                // and browsed scope and replace whatever was stored from a previous open; omit
                // both (internal prev/next calls) to keep navigating the order/scope already in
                // place.
                openById(id, siblings, directoryId) {
                    const requestGeneration = ++this._previewRequestGeneration;
                    // Internal prev/next passes no siblings: it shows a whole asset, never a stacked file.
                    if (! Array.isArray(siblings)) {
                        this.previewVariant = null;
                        this.previewVariants = [];
                    }
                    const variant = this.previewVariant;

                    if (Array.isArray(siblings)) {
                        this.previewSiblings = siblings;
                    }
                    if (directoryId !== undefined) {
                        this.previewDirectoryId = directoryId;
                    }

                    this.videoStopOnClose?.();
                    this.audioStopOnClose?.();
                    this.panoDestroy?.();
                    this.resetTextPreview();
                    this.pdfDestroy();
                    this.isOpen = false;
                    this.isLoading = true;
                    this.favorite = false;
                    this.favoriteSelection.select(id);
                    this.imgResetState();
                    this.videoResetState();
                    this.audioResetState();
                    this.resetInfoPanel();
                    this.panoResetState();
                    document.body.style.overflow = 'hidden';

                    const showUrl = this.previewDirectoryId
                        ? this.showUrlTemplate.replace('__id__', id) + '?directory_id=' + encodeURIComponent(this.previewDirectoryId)
                        : this.showUrlTemplate.replace('__id__', id);

                    this.$axios.get(showUrl)
                        .then(response => {
                            if (requestGeneration !== this._previewRequestGeneration) return;

                            const payload = response.data || {};
                            const asset   = payload.asset || {};

                            this.previewData = {
                                id:                    asset.id,
                                file_name:             asset.file_name,
                                display_name:          asset.display_name,
                                extension:             asset.extension || '',
                                extension_upper:       (asset.extension || '').toUpperCase(),
                                adobe:                 asset.adobe || null,
                                file_type:             asset.file_type,
                                asset_kind:            asset.asset_kind,
                                mime_type:             asset.mime_type,
                                path:                  asset.path,
                                width:                 payload.width ?? '',
                                height:                payload.height ?? '',
                                created_at:            payload.createdAtFormatted ?? '',
                                updated_at:            payload.updatedAtFormatted ?? '',
                                previousAssetId:       payload.previousAssetId ?? null,
                                nextAssetId:           payload.nextAssetId ?? null,
                                mediaUrl:              payload.mediaUrl || '',
                                playbackUrl:           payload.playbackSource?.url || null,
                                playbackMime:          payload.playbackSource?.mime || null,
                                playbackKind:          payload.playbackSource?.kind || null,
                                playbackLabel:         payload.playbackSource?.label || null,
                                playbackIsProxy:       !!payload.playbackSource?.is_proxy,
                                originalUrl:           payload.playbackSource?.original_url || null,
                                captionUrl:            payload.captionSource?.url || null,
                                captionLabel:          payload.captionSource?.label || null,
                                filesUrl:              payload.filesUrl || '',
                                previewPath:           payload.previewPath || '',
                                placeholderSvg:        payload.placeholderSvg || '',
                                gameAsset:             payload.gameAsset || null,
                                coverArtUrl:           payload.coverArtUrl || null,
                                typeColor:             payload.typeColor || 'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300',
                                fileSize:              payload.fileSize || '',
                                fileSizeBytes:         Number(asset.file_size) || 0,
                                downloadUrl:           payload.downloadUrl || '',
                                // The preparing-download card's picture; the thumbnail route takes the path encoded twice (see AssetHelper::pdfThumbnailUrl).
                                downloadThumb:         asset.path ? @js(route('admin.dam.file.thumbnail')) + '?path=' + encodeURIComponent(encodeURIComponent(asset.path)) : null,
                                downloadCompressedUrl: payload.downloadCompressedUrl || '',
                                webMp4Url:             payload.webMp4Url || null,
                                editUrl:               payload.editUrl || '',
                                attachment_count:      payload.attachmentCount ?? null,
                                customThumbnailUrl:    @json(route('admin.dam.assets.custom_thumbnail.store', ['id' => '__ASSET__'])).replace('__ASSET__', asset.id),
                                canSetThumbnail:       @json((bool) bouncer()->hasPermission('dam.asset.edit')),
                                thumbnailLocked:       false,
                            };

                            // The cover's own fields, kept so the strip can switch back to it without another request.
                            this._coverPreviewData = { ...this.previewData };

                            if (variant && Number(variant.asset_id) === Number(asset.id)) {
                                Object.assign(this.previewData, this.variantPreviewData(variant));
                            }

                            this.displayDirectoryBreadcrumb = payload.directoryBreadcrumb || [];
                            this.loadFavorite().catch(() => { this.favorite = false; });

                            this.openPreview();
                        })
                        .catch(error => {
                            if (requestGeneration !== this._previewRequestGeneration) return;
                            window._damGridPreviewActive = false;

                            document.body.style.overflow = '';
                            this.$emitter.emit('dam-preview-layer', {open: false});
                            this.restorePreviewOrigin();
                            this.$emitter.emit('add-flash', {
                                type:    'error',
                                message: error.response?.data?.message || error.message,
                            });
                        })
                        .finally(() => {
                            if (requestGeneration !== this._previewRequestGeneration) return;

                            this.isLoading = false;
                        });
                },

                // The details page of the asset on screen (the stack's own, while one of its files is shown), with the
                // marker that makes the page read the order onOpenDetailsClick stored. A method, not a computed: the
                // navigation helper is a window global Vue cannot see change, so it is looked up on every render.
                openDetailsHref() {
                    const url = this.previewData.editUrl;
                    const navigation = window.MothershipDetailsNavigation;

                    return url && navigation?.withFileContext
                        ? navigation.withFileContext({url, fileId: this.previewData.variantFileId ?? null, directoryId: this.previewDirectoryId, nav: true})
                        : (url && navigation ? navigation.withNavMarker(url, this.previewDirectoryId) : url);
                },

                // The lightbox's "Open details" button. Its :href is already the final URL (openDetailsHref), and
                // ajax navigation follows it exactly once; this only hands over the same visible order the
                // lightbox itself is walking, so the details page's chevrons continue from where it left off.
                // It must not navigate or preventDefault: navigation.js has already started the visit by now.
                onOpenDetailsClick() {
                    if (!this.previewData.editUrl || !window.MothershipDetailsNavigation) return;
                    const navigation = window.MothershipDetailsNavigation;
                    const fromDetails = window.location?.pathname === new URL(this.previewData.editUrl, window.location?.origin || 'https://origin.invalid').pathname;
                    // Reopening details from its viewer must keep the Library search/page snapshot.
                    if (fromDetails && navigation.readSiblings?.()?.siblings?.includes(Number(this.previewData.id))) return;
                    window.MothershipDetailsNavigation.writeSiblings({
                        siblings:    this.previewSiblings,
                        scopeLabel:  'lightbox',
                        directoryId: this.previewDirectoryId,
                        total:       this.previewTotal,
                        offset:      this.previewOffset,
                        returnUrl:   fromDetails ? null : undefined,
                    });
                },

                openPreview() {
                    this.isOpen = true;
                    this.loadInfoPanel();
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

                // isLoading is true for the whole span of an in-flight openById() request, so
                // checking it here doubles as the re-entrancy guard: a second arrow press or
                // click while the current asset is still loading is a no-op, not a queued jump.
                navigatePrev() {
                    if (this.isLoading) return;
                    const id = this.previewNeighbours.previousId;
                    if (id == null) return;
                    this.leaveChooserOrigin();
                    this.openById(id);
                },

                navigateNext() {
                    if (this.isLoading) return;
                    const id = this.previewNeighbours.nextId;
                    if (id == null) return;
                    this.leaveChooserOrigin();
                    this.openById(id);
                },

                leaveChooserOrigin() {
                    if (this.previewOrigin?.kind !== 'chooser') return;
                    this.$emitter.emit('dam-preview-leave-chooser');
                    this.previewOrigin = { ...this.previewOrigin, kind: 'library', trigger: null, fileId: null };
                },

                backToLibrary() {
                    this.leaveChooserOrigin();
                    this.closePreviewWithInfoReset();
                },

                restorePreviewOrigin() {
                    const origin = this.previewOrigin;
                    this.previewOrigin = null;
                    if (! window.MothershipPreviewNavigation.restoreOrigin({origin})) this.restorePreviewFocus();
                },

                closePreview() {
                    this.videoStopOnClose();
                    this.audioStopOnClose();
                    this.pdfDestroy();
                    this.panoDestroy();
                    this.isOpen = false;
                    window._damGridPreviewActive = false;
                    document.body.style.overflow = '';
                    this.resetTextPreview();
                    this.restorePreviewOrigin();
                    this.restoreInfoPane();
                    this.$emitter.emit('dam-preview-layer', {open: false});
                    // A stack's fan under the preview takes focus back to the file that was open.
                    this.$emitter.emit('dam-preview-closed', { id: this.previewData.id });
                },

                handleEscape(e) {
                    if (window.damShareDialogOpen?.() || window.damConfirmDialogOpen?.()) return;

                    // A control that already handled the key (the attachment strip's Alt+arrow reorder) owns it.
                    if (e.defaultPrevented) return;

                    // One Escape closes the lightbox, Info panel open or not; the panel's saved open/closed choice is left alone.
                    if (e.key === 'Escape' && this.isOpen) {
                        e.preventDefault();
                        e.stopPropagation?.();
                        this.closePreview();
                        return;
                    }

                    if (!this.isOpen) return;

                    if (e.key === 'Tab') { this.trapPreviewTab(e); return; }

                    const target = e.target;
                    const tag    = target && target.tagName;
                    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (target && target.isContentEditable)) return;

                    // Space on a focused button or link is that control's own click, not play/pause.
                    if (e.key === ' ' && (tag === 'BUTTON' || tag === 'A' || target?.getAttribute?.('role') === 'slider')) return;

                    const isVideoKey = this.$refs.videoEl && [' ', 'f', 'F', 'm', 'M', 'l', 'L', '+', '=', '-', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key);

                    switch (e.key) {
                        case ' ':
                            if (this.$refs.videoEl)      { e.preventDefault(); this.videoTogglePlay(); }
                            else if (this.$refs.audioEl) { e.preventDefault(); this.audioTogglePlay(); }
                            break;
                        case 'f': case 'F':
                            if (this.$refs.videoEl) { e.preventDefault(); this.videoToggleFullscreen(); }
                            break;
                        case 'm': case 'M':
                            if (this.$refs.videoEl)      this.videoToggleMute();
                            else if (this.$refs.audioEl) this.audioToggleMute();
                            break;
                        case 'ArrowLeft':
                            // Alt/Ctrl/Meta+arrow belongs to the focused control (browser history, strip reorder), not the lightbox.
                            if (e.altKey || e.ctrlKey || e.metaKey) break;
                            // Plain arrows always step to the neighbouring file, so holding one never gets stuck on a video
                            // or an audio file; Shift+arrow seeks 5 s there. The player's own seek bar keeps its arrows
                            // (a range input is skipped above, the audio slider stops them itself).
                            if (e.shiftKey && this.$refs.videoEl)      { e.preventDefault(); this.videoSkip(-5); }
                            else if (e.shiftKey && this.$refs.audioEl) { e.preventDefault(); this.audioSkip(-5); }
                            else { e.preventDefault(); this.previewVariants.length > 1 ? this.navigateMember({direction: -1}) : this.navigatePrev(); }
                            break;
                        case 'ArrowRight':
                            if (e.altKey || e.ctrlKey || e.metaKey) break;
                            if (e.shiftKey && this.$refs.videoEl)      { e.preventDefault(); this.videoSkip(5); }
                            else if (e.shiftKey && this.$refs.audioEl) { e.preventDefault(); this.audioSkip(5); }
                            else { e.preventDefault(); this.previewVariants.length > 1 ? this.navigateMember({direction: 1}) : this.navigateNext(); }
                            break;
                        case 'ArrowUp':
                            if (this.$refs.videoEl) {
                                e.preventDefault();
                                this.videoVolume = Math.min(1, Math.round((this.videoVolume + 0.1) * 10) / 10);
                                this.$refs.videoEl.volume = this.videoVolume;
                                if (this.videoIsMuted && this.videoVolume > 0) { this.videoIsMuted = false; this.$refs.videoEl.muted = false; }
                                try { localStorage.setItem('dam_video_volume', this.videoVolume); } catch (_) {}
                            } else if (this.$refs.audioEl) {
                                e.preventDefault();
                                this.audioVolume = Math.min(1, Math.round((this.audioVolume + 0.1) * 10) / 10);
                                this.$refs.audioEl.volume = this.audioVolume;
                                if (this.audioIsMuted && this.audioVolume > 0) { this.audioIsMuted = false; this.$refs.audioEl.muted = false; }
                                try { localStorage.setItem('dam_audio_volume', this.audioVolume); } catch (_) {}
                            }
                            break;
                        case 'ArrowDown':
                            if (this.$refs.videoEl) {
                                e.preventDefault();
                                this.videoVolume = Math.max(0, Math.round((this.videoVolume - 0.1) * 10) / 10);
                                this.$refs.videoEl.volume = this.videoVolume;
                                try { localStorage.setItem('dam_video_volume', this.videoVolume); } catch (_) {}
                            } else if (this.$refs.audioEl) {
                                e.preventDefault();
                                this.audioVolume = Math.max(0, Math.round((this.audioVolume - 0.1) * 10) / 10);
                                this.$refs.audioEl.volume = this.audioVolume;
                                try { localStorage.setItem('dam_audio_volume', this.audioVolume); } catch (_) {}
                            }
                            break;
                        case '+': case '=':
                            e.preventDefault();
                            if (this.$refs.videoEl) {
                                const vRates = [0.5, 0.75, 1, 1.25, 1.5, 2];
                                const vIdx   = vRates.indexOf(this.videoSpeed);
                                if (vIdx < vRates.length - 1) this.setVideoSpeed(vRates[vIdx + 1]);
                            } else if (this.$refs.audioEl) {
                                const aRates = [0.5, 0.75, 1, 1.25, 1.5, 2];
                                const aIdx   = aRates.indexOf(this.audioSpeed);
                                if (aIdx < aRates.length - 1) this.setAudioSpeed(aRates[aIdx + 1]);
                            } else {
                                this.imgZoomIn();
                            }
                            break;
                        case '-':
                            e.preventDefault();
                            if (this.$refs.videoEl) {
                                const vRates = [0.5, 0.75, 1, 1.25, 1.5, 2];
                                const vIdx   = vRates.indexOf(this.videoSpeed);
                                if (vIdx > 0) this.setVideoSpeed(vRates[vIdx - 1]);
                            } else if (this.$refs.audioEl) {
                                const aRates = [0.5, 0.75, 1, 1.25, 1.5, 2];
                                const aIdx   = aRates.indexOf(this.audioSpeed);
                                if (aIdx > 0) this.setAudioSpeed(aRates[aIdx - 1]);
                            } else {
                                this.imgZoomOut();
                            }
                            break;
                        case 'r': case 'R': this.imgRotateRight(); break;
                        case 'l': case 'L':
                            if (this.$refs.videoEl)      this.videoToggleLoop();
                            else if (this.$refs.audioEl) this.audioToggleLoop();
                            else                         this.imgRotateLeft();
                            break;
                        case '0': this.imgReset(); break;
                    }

                    if (isVideoKey) this.videoShowControls();
                },
            },
        });
    </script>
@endPushOnce
