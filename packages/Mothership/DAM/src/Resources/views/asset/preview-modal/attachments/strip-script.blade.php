@once
@include('dam::components.shared.name-tail')
<script>
    // Viewing one file of a stack inside a preview. Shared by the Library lightbox and the details page's viewer, which
    // spread `data` and `methods` into their components. The host supplies previewData, isOpen and its viewer reset methods.
    window._damStackSwitch = {
        data: {
            isLoading: false,
            // The stacked file a fan card or the strip asked for (D5), and the stack's files it came from; both
            // empty when the stack's own cover is showing.
            previewVariant: null,
            previewVariants: [],
            // True where the host also shows the stack inline, outside any modal (the details page's Preview tab): a
            // chosen file then stays on show when a viewer opened over it closes.
            stackInline: false,
            textState: 'idle', textContent: '', textError: '', textTruncated: false, textCopied: false,
        },

        methods: {
            defaultFileLabel() {
                if (this.previewData?.variantFileId) return '';
                const members = this.previewVariants.filter(file => Number(file.asset_id) === Number(this.previewData?.id));
                if (members.length <= 1 && Number(this.previewData?.attachment_count || 0) <= 1) return '';
                const cover = members.find(file => file.is_representative);
                const name = cover?.file_name || cover?.label || this.previewData?.file_name || '';
                const stackTitle = this.previewData?.display_name || this.previewData?.file_name || '';
                return name && name !== stackTitle ? name : '';
            },
            refreshStackDefault({ assetId, files, asset } = {}) {
                if (Number(this.previewData?.id) !== Number(assetId) || ! Array.isArray(files) || ! this._coverPreviewData) return;
                const oldCover = this.previewVariants.find(file => file.is_representative);
                const viewedId = this.previewData.variantFileId || oldCover?.id;
                const viewedSlot = this.previewVariants.find(file => Number(file.id) === Number(viewedId))?.attachment_id;
                this.previewVariants = files;
                const cover = files.find(file => file.is_representative && Number(file.asset_id) === Number(assetId));
                const viewed = files.find(file => Number(file.attachment_id) === Number(viewedSlot)) || files.find(file => Number(file.id) === Number(viewedId));
                if (! cover || ! viewed) return;
                this._coverPreviewData = { ...this._coverPreviewData, ...asset, ...this.variantPreviewData(cover),
                    width: cover.width || '', height: cover.height || '',
                    variantFileId: null, variantAssetId: null, variantAttachmentId: null, variantName: '', variantLabel: '' };
                this.previewVariant = viewed.is_representative ? null : viewed;
                this.resetInfoPanel?.();
                this.renderStackFile();
                this.loadInfoPanel?.();
            },
            memberNavigation() {
                return window.MothershipPreviewNavigation?.memberNeighbours({
                    files: this.previewVariants, assetId: this.previewData.id, fileId: this.previewData.variantFileId,
                }) || {previous: null, next: null, position: null, total: 0};
            },

            navigateMember({direction}) {
                if (this.isLoading || ! [-1, 1].includes(direction)) return;
                const next = this.memberNavigation()[direction < 0 ? 'previous' : 'next'];
                if (next) this.switchStackedFile(next, this.previewVariants);
            },

            resetTextPreview() {
                this._textAbort?.abort();
                this.textState = 'idle';
                this.textContent = '';
                this.textError = '';
                this.textTruncated = false;
                this.textCopied = false;
            },

            loadTextPreview() {
                if (! this.previewVariant || ! this.isTextFile(String(this.previewData.mime_type || ''), [this.previewVariant.label, this.previewVariant.file_name])) return;
                this.loadText(this.previewVariant);
            },
            isTextFile(mime, names) {
                const textMimes = ['application/json', 'application/xml', 'application/x-subrip', 'application/javascript', 'application/x-yaml', 'application/yaml', 'application/toml', 'application/x-ndjson'];
                const textExtensions = ['txt', 'md', 'markdown', 'json', 'jsonl', 'xml', 'srt', 'vtt', 'csv', 'tsv', 'yaml', 'yml', 'toml', 'log', 'prompt', 'ini', 'cfg', 'conf', 'lrf', 'gpx', 'kml', 'html', 'htm', 'css', 'js', 'py', 'php', 'sh'];
                if (mime.startsWith('text/') || textMimes.includes(mime)) return true;
                if (mime !== '' && mime !== 'application/octet-stream') return false;
                // Either identifying name may carry the extension (a display label may omit it).
                return (Array.isArray(names) ? names : [names]).filter(Boolean).some((name) => {
                    const base = String(name).toLowerCase();
                    return base.includes('.') && textExtensions.includes(base.split('.').pop());
                });
            },
            async loadText(file) {
                const limit = 1024 * 1024;
                this.textState = 'loading';
                const controller = new AbortController();
                this._textAbort?.abort();
                this._textAbort = controller;
                try {
                    // Stream at most `limit` bytes whether or not the server honours Range, then stop the download.
                    const response = await fetch(file.url, { credentials: 'same-origin', signal: controller.signal, headers: { Range: `bytes=0-${limit - 1}`, Accept: 'text/plain, */*' } });
                    if (! response.ok) throw new Error(`HTTP ${response.status}`);
                    const contentRange = /\/(\d+)\s*$/.exec(response.headers.get('Content-Range') || '');
                    const total = contentRange ? Number(contentRange[1]) : (Number(response.headers.get('Content-Length')) || Number(file.size || 0));
                    const chunks = []; let received = 0;
                    const reader = response.body.getReader();
                    while (received < limit) {
                        const { value, done } = await reader.read();
                        if (done) break;
                        chunks.push(value); received += value.byteLength;
                    }
                    reader.cancel().catch(() => {});
                    if (controller.signal.aborted || this._textAbort !== controller || this.previewData?.variantFileId !== file.id) return;
                    const bytes = new Uint8Array(Math.min(received, limit)); let offset = 0;
                    for (const chunk of chunks) { const slice = chunk.subarray(0, Math.max(0, Math.min(chunk.byteLength, limit - offset))); bytes.set(slice, offset); offset += slice.byteLength; if (offset >= limit) break; }
                    this.textContent = new TextDecoder('utf-8').decode(bytes);
                    this.textTruncated = total > limit || received > limit;
                    this.textState = 'ready';
                } catch (error) {
                    if (controller.signal.aborted || this._textAbort !== controller || this.previewData?.variantFileId !== file.id) return;
                    this.textError = `Unable to load text${error?.message ? ` (${error.message})` : ''}.`;
                    this.textState = 'error';
                }
            },
            async copyText() {
                try {
                    await navigator.clipboard.writeText(this.textContent);
                    this.textCopied = true;
                    setTimeout(() => { this.textCopied = false; }, 1500);
                } catch (e) {
                    this.textError = 'Clipboard unavailable.';
                }
            },

            // Remember the cover's own fields at open, so the strip can switch back to it without another request.
            rememberStackCover() {
                if (! this.previewData?.variantFileId) this._coverPreviewData = { ...this.previewData };
            },

            // The Info pane closed to make room on a phone comes back as the person last left it, for the next open.
            restoreInfoPane() {
                if (! this._infoClosedForPhone) return;
                this._infoClosedForPhone = false;
                if (window._damSavedInfoOpen) this.isInfoPanelOpen = window._damSavedInfoOpen();
            },

            // "Open Files tab": the details page's Files tab, with the stacked file on show picked out there (`file`).
            filesTabHref() {
                const url = this.previewData?.filesUrl || '';
                const fileId = this.previewData?.variantFileId ?? null;
                const navigation = window.MothershipDetailsNavigation;
                if (! navigation?.withFileContext) return url;
                const params = new URLSearchParams(window.location?.search || '');
                return navigation.withFileContext({
                    url, fileId,
                    directoryId: this.stackInline ? params.get('directory_id') : this.previewDirectoryId,
                    nav: this.stackInline ? params.get('nav') === '1' : true,
                });
            },

            // Closing returns the viewer to the cover, so the next open starts on it.
            restoreStackCover() {
                this.restoreInfoPane();
                if (this.stackInline || ! this.previewData?.variantFileId || ! this._coverPreviewData) return;
                this.previewVariant = null;
                this.previewData = { ...this._coverPreviewData };
            },

            /**
             * Show another file of the stack already open, from its strip. The header and strip stay
             * where they are (so a half-made download selection survives); only the viewer area is rebuilt, from the
             * cover's fields kept at open time plus the chosen file's own, with no request and no loading screen.
             */
            switchStackedFile(file, files) {
                if (this.isLoading || ! (this.isOpen || this.stackInline) || ! file || ! this._coverPreviewData) return false;
                this.memberUnavailable = false;
                if (Array.isArray(files) && files.length > 1) this.previewVariants = files;
                if (file.is_representative) {
                    if (! this.previewData.variantFileId) {
                        if (this.stackInline) this.replaceMemberContext();
                        return true;
                    }
                    this.previewVariant = null;
                } else {
                    if (Number(this.previewData.variantFileId) === Number(file.id)) return true;
                    this.previewVariant = file;
                }

                this.renderStackFile();
                return true;
            },

            /**
             * "Back to cover": the cover again, from the fields kept at open. It needs no file list, so it works when the
             * strip could not load one; it is also what the strip's cover tile does.
             */
            showStackCover() {
                if (! this.previewData?.variantFileId || ! this._coverPreviewData) return;
                this.previewVariant = null;
                this.renderStackFile();
            },

            /** Rebuilds the viewer area for `previewVariant` (a stacked file, or the cover when it is null). */
            renderStackFile() {
                // Below 768px the Info pane covers the whole viewer, so the new file would change out of sight. Close it for
                // this view only; the saved open/closed choice is left alone.
                if (this.isInfoPanelOpen && window.matchMedia?.('(max-width: 767px)').matches) {
                    this.isInfoPanelOpen = false;
                    this._infoClosedForPhone = true;
                    this.resetInfoPanel?.();
                }

                this.resetTextPreview();
                this.videoStopOnClose();
                this.audioStopOnClose();
                this.pdfDestroy();
                this.panoDestroy();
                this.imgResetState();
                this.videoResetState();
                this.audioResetState();
                // A poll answer still out was asked for the other file's view of the panorama; drop it (the reset below
                // re-arms the poll if an attempt is still running).
                this.panoramaInvalidate?.();
                this.panoResetState();
                // An image editor still loading was asked for the other file; it must not open over this one.
                this._editorLoad = null;
                this.isEditOpen = false;

                this.previewData = this.previewVariant
                    ? { ...this._coverPreviewData, ...this.variantPreviewData(this.previewVariant) }
                    : { ...this._coverPreviewData };

                if (this.stackInline) this.replaceMemberContext();

                // The details page's Details card follows the file on show (no listener elsewhere).
                if (this.stackInline) this.$emitter?.emit('dam:stack-file-shown', {
                    assetId: Number(this.previewData.id),
                    file: this.previewVariant ? {
                        id: this.previewData.variantFileId, name: this.previewData.variantName, extension: this.previewData.extension, mime: this.previewData.mime_type, adobe: this.previewData.adobe || null,
                        assetId: this.previewData.variantAssetId, attachmentId: this.previewData.variantAttachmentId,
                        type: this.previewData.file_type, size: this.previewData.fileSize,
                        width: this.previewData.variantWidth, height: this.previewData.variantHeight,
                    } : null,
                });

                // The cover again: always ask the server where its panorama stands. A build started before the switch
                // (whose answer was dropped on the way out) is found, and polled, whatever the last state said.
                if (! this.previewVariant) this.loadPanoramaState?.();

                this.$nextTick(() => {
                    if (! (this.isOpen || this.stackInline)) return;
                    this.videoInitEl();
                    // Changing <source src> never reloads a <video> that is already on screen, and the inline card keeps one
                    // element across files: without this the second video of a stack kept showing the first (feedback #6).
                    this.$refs?.videoEl?.load?.();
                    this.audioInitEl();
                    this.panoInit();
                    // Only a viewer that is open plays on its own; the inline card waits to be pressed.
                    if (this.isOpen) { this.autoplayMedia(); this.loadTextPreview(); }
                });
            },

            // Selecting thumbnails changes the current view, not the browser's Back stack.
            replaceMemberContext() {
                const navigation = window.MothershipDetailsNavigation;
                if (! navigation?.withFileContext || ! window.location?.href) return;
                const fileId = this.previewData.variantFileId ?? null;
                const url = navigation.withFileContext({url: window.location.href, fileId});
                window.history.replaceState(window.history.state, '', url);
                for (const tab of document.querySelectorAll('[data-dam-tab]')) {
                    tab.href = navigation.withFileContext({url: tab.href, fileId});
                }
            },

            /**
             * The viewer fields for one stacked file (D5): its own bytes, type and size in place of the
             * representative's. Playback copies, captions and panoramas belong to the representative only.
             */
            variantPreviewData(file) {
                const mime = String(file.mime_type || '').toLowerCase();
                const name = String(file.label || file.file_name || '');
                // The stored type decides the viewer; a label is editable text. Its suffix only names a
                // file whose type was stored as generic bytes.
                const known = {
                    'image/jpeg': 'jpg', 'image/svg+xml': 'svg', 'image/vnd.adobe.photoshop': 'psd',
                    'video/quicktime': 'mov', 'video/mpeg': 'mpg', 'audio/mpeg': 'mp3', 'text/plain': 'txt',
                    'model/gltf-binary': 'glb', 'model/gltf+json': 'gltf', 'model/stl': 'stl', 'model/3mf': '3mf',
                    'application/vnd.ms-pki.stl': 'stl', 'application/vnd.ms-package.3dmanufacturing-3dmodel+xml': '3mf',
                };
                const generic = ! mime || mime === 'application/octet-stream' || mime === 'binary/octet-stream';
                const subtype = mime.split('/').pop().replace(/^x-/, '').split('+')[0];
                const fromName = /\.[a-z0-9]{1,5}$/i.test(name) ? name.split('.').pop().toLowerCase() : '';
                // The server names the type (`dng` for a RAW stored as TIFF); the guess below is for an older payload.
                const extension = file.extension || (generic ? fromName : (known[mime] || subtype));
                const type = mime.startsWith('image/') ? 'image' : mime.startsWith('video/') ? 'video' : mime.startsWith('audio/') ? 'audio' : 'document';
                // A still or a DNG shows its screen-sized copy (`preview_url`), never its original (PROD-03, PROD-13).
                const browserImage = /^image\/(png|jpe?g|gif|webp|avif|svg\+xml|bmp)$/.test(mime) || !! file.preview_url;
                const imageUrl = file.preview_url || file.url;
                const cover = this.previewVariants.find((other) => other.is_representative && Number(other.asset_id) === Number(file.asset_id));
                const bytes = Number(file.size || 0);
                const size = bytes >= 1048576 ? `${(bytes / 1048576).toFixed(2)} MB` : bytes >= 1024 ? `${(bytes / 1024).toFixed(1)} KB` : (bytes ? `${bytes} B` : '');
                const roles = @json(trans('dam::app.admin.dam.asset.files.roles'));
                const playback = type === 'video' ? (file.playback || null) : null;
                const playbackProcessing = playback?.state === 'processing';

                return {
                    variantFileId:   file.id,
                    // The file on show by its entry and slot: a capture-group companion is its own entry, and its main file
                    // needs no slot.
                    variantAssetId:      Number(file.asset_id || 0) || null,
                    variantAttachmentId: file.is_representative ? null : (file.attachment_id ?? null),
                    variantName:     name,
                    // The info panel names the cover by its own file, which can differ from the entry's name (G1-10).
                    coverName:       cover?.label || null,
                    variantLabel:    [name, roles[file.role] || file.role].filter(Boolean).join(' · '),
                    variantNameHead: window.MothershipNameTail(name).head,
                    variantNameTail: window.MothershipNameTail(name).tail,
                    variantRole:     file.is_representative ? '' : (roles[file.role] || file.role || ''),
                    extension,
                    extension_upper: extension.toUpperCase(),
                    adobe:           file.adobe || null,
                    // A file with no picture of its own shows its application's mark, not the cover's placeholder.
                    ...(file.adobe ? { placeholderSvg: window.MothershipAdobe?.tile(file.adobe) || this._coverPreviewData?.placeholderSvg } : {}),
                    file_type:       type,
                    asset_kind:      '',
                    mime_type:       mime,
                    mediaUrl:        file.url,
                    previewPath:     browserImage ? imageUrl : '',
                    // A video plays the source the server chose for this very file: its own preview proxy, its own bytes when a
                    // browser can decode them, or nothing yet ("processing"). Never the cover's proxy (feedback #6). An older
                    // payload without `playback` keeps playing the file's own bytes.
                    playbackUrl:     type === 'video' ? (playback ? playback.url : file.url) : (browserImage ? imageUrl : null),
                    playbackMime:    type === 'video' ? (playback ? playback.mime : mime) : null,
                    playbackKind:    type === 'video' && ! playbackProcessing ? 'video' : (type !== 'video' && browserImage ? 'image' : null),
                    playbackProcessing,
                    playbackLabel:   null,
                    playbackIsProxy: !! playback?.is_proxy,
                    originalUrl:     null,
                    captionUrl:      null,
                    captionLabel:    null,
                    coverArtUrl:     null,
                    webMp4Url:       null,
                    // The cover's Blender/Unity preview describes the cover's bytes, not this file's.
                    gameAsset:       null,
                    // The cover's pictures, folder path and poster control belong to the cover; none of them describes or
                    // acts on this file, so a PDF or model alternate must not show the cover's thumbnail under its name.
                    modelThumbnailUrl: null,
                    pdfThumbnailUrl:   null,
                    path:              '',
                    width:           '',
                    height:          '',
                    fileSize:        size,
                    fileSizeBytes:   bytes,
                    // The Info pane reads these for the file shown, in place of the cover's facts.
                    variantWidth:    Number(file.width) || null,
                    variantHeight:   Number(file.height) || null,
                    variantSha256:   file.sha256 || null,
                    downloadUrl:     file.download_url || file.url,
                    // The file's own thumbnail for the preparing-download card (the cover's does not describe it).
                    downloadThumb:   file.thumbnail_url || null,
                    // The ZIP route packs the stack's cover, not this file, so it is not offered here.
                    downloadCompressedUrl: '',
                    // "Use frame as thumbnail" sets the stack card's picture, which is the cover's: another file of the
                    // stack never writes it. The player shows the control disabled, with the reason, for an editor.
                    customThumbnailUrl:    file.is_representative ? this._coverPreviewData?.customThumbnailUrl ?? null : null,
                    canSetThumbnail:       !! file.is_representative && !! this._coverPreviewData?.canSetThumbnail,
                    // Any file but the cover itself opens the image editor on its own bytes when the editor may be used here
                    // and its stored type is one it reads (ImageEditing::memberEditable decides again on the server); its
                    // thumbnail is never edited. The cover keeps the answers the server gave for it.
                    ...(file.is_representative && Number(file.asset_id) === Number(this._coverPreviewData?.id) ? {} : {
                        canEditOriginal:  !! this._coverPreviewData?.mayUseEditor && ['png', 'jpg', 'jpeg', 'webp'].includes(extension)
                            && (generic || /^image\/(png|x-png|jpe?g|pjpeg|webp)$/.test(mime)),
                        canEditThumbnail: false,
                    }),
                    thumbnailLocked:       ! file.is_representative && !! this._coverPreviewData?.canSetThumbnail,
                };
            },
        },
    };
</script>
<style>
    .dam-strip__item { width: 8.5rem; }
    .dam-strip__thumb { position: relative; display: flex; height: 4.5rem; align-items: center; justify-content: center; overflow: hidden; border-radius: .25rem; background: #fff; }
    .dark .dam-strip__thumb { background: rgb(17 24 39); }
    .dam-strip__thumb img { max-height: 100%; max-width: 100%; object-fit: contain; }
    .dam-strip__overmark { position: absolute; right: .25rem; bottom: .25rem; width: 1.125rem; height: 1.125rem; border-radius: .25rem; box-shadow: 0 0 0 1px rgba(255, 255, 255, .85), 0 1px 2px rgba(0, 0, 0, .3); }
    .dam-strip__mark { width: 1.75rem; height: 1.75rem; border-radius: .375rem; flex: none; }
    .dam-strip__type { display: inline-flex; align-items: center; gap: .25rem; font-size: .75rem; font-weight: 700; text-transform: uppercase; color: rgb(107 114 128); }
    .dam-strip__name { display: flex; align-items: flex-end; min-width: 0; margin-top: .25rem; font-size: 11px; font-weight: 600; line-height: .875rem; color: rgb(55 65 81); }
    .dark .dam-strip__name { color: rgb(229 231 235); }
    /* Two lines before a name is cut, and the cut keeps its ending (VISR-11). */
    .dam-strip__head { min-width: 0; overflow: hidden; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow-wrap: anywhere; }
    .dam-strip__tail { flex: none; white-space: pre; }
    /* A name that fits in two lines is shown whole: split, a short second head line leaves a gap before the tail. */
    .dam-strip__whole { min-width: 0; overflow: hidden; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow-wrap: anywhere; }
    .dam-strip__item[data-viewing] { border-color: rgb(var(--c-primary-600, 37 99 235)); box-shadow: 0 0 0 2px rgb(var(--c-primary-600, 37 99 235)); background: #fff; }
    .dark .dam-strip__item[data-viewing] { background: rgb(31 41 55); }
    .dam-strip__item[data-selected]:not([data-viewing]) { border-color: rgb(var(--c-primary-600, 37 99 235)); }
    .dam-strip__check { position: absolute; left: .25rem; top: .25rem; z-index: 1; display: flex; height: 1.25rem; width: 1.25rem; align-items: center; justify-content: center; border-radius: .25rem; border: 1px solid rgb(156 163 175); background: rgb(255 255 255 / .92); cursor: pointer; opacity: 0; transition: opacity .12s; }
    .dam-strip__check input { height: .875rem; width: .875rem; cursor: pointer; accent-color: rgb(var(--c-primary-600, 37 99 235)); }
    .dam-strip__item:hover .dam-strip__check, .dam-strip__item:focus-within .dam-strip__check, .dam-strip__item[data-selected] .dam-strip__check, .dam-strip--picking .dam-strip__check { opacity: 1; }
    @media (hover: none) { .dam-strip__check { opacity: 1; } }
    /* A short screen (a phone on its side) gives the picture the height: smaller thumbnails, one-line names, no hint. */
    @media (max-height: 760px) {
        .dam-strip__thumb { height: 3rem; }
        .dam-strip__head, .dam-strip__whole { -webkit-line-clamp: 1; }
        [data-dam-strip-hint] { display: none; }
    }
    /* In the viewer on a phone on its side (the Duo's outer display in landscape is 368px tall) the strip is one row:
       its count and downloads stacked at the left, then small thumbnails with one-line names. */
    @media (max-height: 500px) {
        [data-dam-preview-dialog] [data-dam-attachment-strip] { display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; column-gap: .75rem; padding-top: .25rem; padding-bottom: .25rem; }
        [data-dam-preview-dialog] [data-dam-strip-head] { flex-direction: column; align-items: flex-start; margin-bottom: 0; }
        [data-dam-preview-dialog] [data-dam-strip-downloads] { margin-left: 0; flex-direction: column; align-items: flex-start; gap: .25rem; }
        [data-dam-preview-dialog] .dam-strip__item { width: 6.5rem; }
        [data-dam-preview-dialog] .dam-strip__thumb { height: 2.5rem; }
        [data-dam-preview-dialog] [data-dam-strip-role] { display: none; }
    }
    .dam-strip__badge { position: absolute; right: .25rem; top: .25rem; border-radius: .25rem; background: rgb(var(--c-primary-600, 37 99 235)); padding: 0 .25rem; font-size: 10px; font-weight: 600; line-height: 1rem; color: #fff; }
</style>
<script type="text/x-template" id="v-dam-attachment-strip-template">
    <div v-if="own.length > 1" class="shrink-0 border-t border-gray-200 bg-white px-4 py-2 dark:border-gray-700 dark:bg-gray-900" data-dam-attachment-strip>
        <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" data-dam-strip-head>
            <span class="font-semibold text-gray-700 dark:text-gray-200">@{{ countLabel }}</span>
            <span class="text-gray-500 dark:text-gray-400 max-sm:hidden" data-dam-strip-hint>@{{ hint }}</span>
            @if (bouncer()->hasPermission('dam.asset.download_compressed'))
                <span class="ml-auto flex items-center gap-3" data-dam-strip-downloads>
                    <a v-if="selected.length" :href="zipUrl(selected)" data-dam-download :data-dam-download-name="zipName(selected)" :data-dam-download-thumb="zipThumb(selected)" class="font-semibold text-primary-600 hover:underline dark:text-primary-400" data-dam-strip-download-selected>@{{ selectedLabel }}</a>
                    <a :href="zipUrl([])" data-dam-download :data-dam-download-name="zipName([])" :data-dam-download-thumb="zipThumb([])" class="font-semibold text-primary-600 hover:underline dark:text-primary-400" data-dam-strip-download-all>@lang('dam::app.admin.dam.asset.files.download-all')</a>
                </span>
            @endif
        </div>
        <ol class="flex gap-2 overflow-x-auto pb-1" :class="{ 'dam-strip--picking': selected.length }" role="listbox" aria-multiselectable="true" :aria-label="countLabel">
            <li
                v-for="(file, index) in own"
                :key="file.attachment_id || file.id"
                ref="items"
                role="option"
                :tabindex="index === focusIndex ? 0 : -1"
                @focus="focusIndex = index"
                @keydown="onListKeydown($event, index)"
                :aria-selected="selected.includes(file.id)"
                :aria-current="isViewing(file) ? 'true' : null"
                :aria-label="itemLabel(file)"
                :draggable="canEdit"
                class="dam-strip__item group relative flex shrink-0 cursor-pointer flex-col rounded-lg border border-gray-200 bg-gray-50 p-1.5 text-left outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-gray-700 dark:bg-gray-800"
                :class="dragging === file.attachment_id ? 'opacity-50' : ''"
                :data-viewing="isViewing(file) ? '' : null"
                :data-selected="selected.includes(file.id) ? '' : null"
                data-dam-strip-item
                @click="activate(file)"
                @keydown.space.prevent="toggle(file)"
                @keydown.enter.prevent="canSwitch ? activate(file) : openFile(file)"
                @keydown.alt.left.prevent="move(index, index - 1)"
                @keydown.alt.right.prevent="move(index, index + 1)"
                @dragstart="dragging = file.attachment_id"
                @dragend="dragging = null"
                @dragover.prevent
                @drop.prevent="dropOn(index)"
            >
                <span class="dam-strip__thumb">
                    <img v-if="file.thumbnail_url && ! broken[file.id]" :src="file.thumbnail_url" alt="" loading="lazy" draggable="false" v-on:error="broken[file.id] = true">
                    <img v-if="file.adobe && file.thumbnail_url && ! broken[file.id]" :src="adobeIcon(file.adobe)" alt="" class="dam-strip__overmark" draggable="false" :title="file.adobe.title" data-dam-strip-adobe-mark>
                    <span v-if="! file.thumbnail_url || broken[file.id]" class="dam-strip__type" :title="file.adobe?.title || null">
                        <img v-if="file.adobe" :src="adobeIcon(file.adobe)" alt="" class="dam-strip__mark" draggable="false">
                        <svg v-if="isModel(file)" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5 3.5 7v10L12 21.5 20.5 17V7z"></path><path d="M3.5 7 12 12l8.5-5M12 12v9.5"></path></svg>
                        @{{ extension(file) }}
                        <span v-if="file.adobe" class="sr-only">@{{ file.adobe.app }}</span>
                    </span>
                    <span v-if="isViewing(file)" class="dam-strip__badge" data-dam-strip-viewing>@lang('dam::app.admin.dam.asset.stack-fan.viewing')</span>
                    @if (bouncer()->hasPermission('dam.asset.download_compressed'))
                        <label class="dam-strip__check" :title="selectLabel(file)" @click.stop>
                            <input type="checkbox" tabindex="-1" :checked="selected.includes(file.id)" :aria-label="selectLabel(file)" data-dam-strip-check @change="toggle(file)">
                        </label>
                    @endif
                </span>
                <span class="dam-strip__name" :title="file.label || file.file_name"><span v-if="! splitNames[file.id]" :ref="(el) => measureName(file.id, el)" class="dam-strip__whole">@{{ file.label || file.file_name }}</span><template v-else><span class="dam-strip__head">@{{ nameParts(file).head }}</span><span class="dam-strip__tail">@{{ nameParts(file).tail }}</span></template></span>
                <span class="truncate text-[11px] text-gray-500 dark:text-gray-400" data-dam-strip-role>
                    <template v-if="file.is_representative">@lang('dam::app.admin.dam.asset.files.representative')</template>
                    <template v-else>@{{ roleLabels[file.role] || file.role }}</template>
                </span>
                <button v-if="isViewing(file) && canPromote(file)" type="button" class="text-xs font-semibold text-primary-600" :disabled="saving" @click.stop="promote(file)" @keydown.stop data-dam-strip-make-default>@lang('dam::app.admin.dam.asset.files.make-representative')</button>
            </li>
        </ol>
        <p class="sr-only" role="status" aria-live="polite">@{{ announcement }}</p>
    </div>
    {{-- R2: a stack the role may not list says so, rather than showing nothing. --}}
    <p v-else-if="noAccess" class="shrink-0 border-t border-gray-200 bg-white px-4 py-2 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" role="note" data-dam-strip-no-access>@lang('dam::app.admin.dam.asset.files.no-access')</p>
</script>

<script type="module">
    // P3a S4: a stack's files under the preview. Select some to download as a zip, or all of them; people
    // who can edit the asset reorder by dragging or with Alt+Left/Right, and the order is saved at once.
    app.component('v-dam-attachment-strip', {
        template: '#v-dam-attachment-strip-template',

        props: {
            assetId: { type: Number, required: true },
            // The card's attachment_count when known; one file or none means there is no strip to fetch.
            countHint: { type: Number, default: null },
            // The stacked file the preview is showing (D5); none means the representative.
            activeFileId: { type: Number, default: null },
            // True where the host preview can switch to another stacked file (the Library lightbox); elsewhere a
            // click keeps its older meaning, choosing the file for the zip.
            canSwitch: { type: Boolean, default: false },
        },

        emits: ['open-file', 'files-loaded'],

        data() {
            return {
                files: [], focusIndex: 0, canEdit: false, selected: [], dragging: null, saving: false, announcement: '', broken: {}, splitNames: {},
                roleLabels: @json(trans('dam::app.admin.dam.asset.files.roles')),
                hints: {
                    view: @json(trans('dam::app.admin.dam.asset.files.strip-hint')),
                    select: @json(trans('dam::app.admin.dam.asset.files.strip-select-hint')),
                    reorder: @json(trans('dam::app.admin.dam.asset.files.strip-reorder-hint')),
                },
                labels: {
                    select: @json(trans('dam::app.admin.dam.asset.files.select-file')),
                    view: @json(trans('dam::app.admin.dam.asset.files.view-file')),
                },
                modelExtensions: ['glb', 'gltf', '3mf', 'stl', 'obj', 'fbx', 'blend'],
            };
        },

        computed: {
            own() {
                // A generated proxy or thumbnail is listed by the endpoint but is not a file of the stack (feedback #6).
                return this.files.filter((file) => Number(file.asset_id) === Number(this.assetId) && file.attachment_id && file.stack_member !== false);
            },
            // More than one file, but this role may not list them (R2).
            noAccess() {
                return ! @json(bouncer()->hasPermission('dam.asset.files.index')) && this.countHint !== null && this.countHint > 1;
            },
            hint() {
                return [this.canSwitch ? this.hints.view : this.hints.select, this.canEdit ? this.hints.reorder : ''].filter(Boolean).join(' ');
            },
            countLabel() {
                return "@lang('dam::app.admin.dam.asset.stack.files-count')".replace(':count', this.own.length);
            },
            selectedLabel() {
                return "@lang('dam::app.admin.dam.asset.files.download-selected')".replace(':count', this.selected.length);
            },
        },

        watch: {
            assetId() { this.splitNames = {}; },
            // A long strip scrolls sideways; the file now on screen comes into view.
            activeFileId() { this.revealViewing(); },
            files() { this.revealViewing(); },
        },

        mounted() {
            // A 3D file just opened in the viewer was captured: its tile shows the picture without reloading the list.
            this._onFileThumbnail = ({ fileId, url } = {}) => {
                for (const file of this.files) {
                    if (Number(file.id) !== Number(fileId)) continue;
                    file.thumbnail_url = url;
                    delete this.broken[file.id];
                }
            };
            this.$emitter.on('dam:file-thumbnail-updated', this._onFileThumbnail);
            if (this.countHint !== null && this.countHint <= 1) return;
            // Listing a stack's files needs dam.asset.files.index; without it there is no strip to ask for (R2).
            if (! @json(bouncer()->hasPermission('dam.asset.files.index'))) return;
            this.load();
        },

        beforeUnmount() {
            this.$emitter.off('dam:file-thumbnail-updated', this._onFileThumbnail);
        },

        methods: {
            canPromote(file) {
                return this.canEdit && Number(file.asset_id) === Number(this.assetId) && !! file.attachment_id && ! file.is_representative && ! ['proxy', 'thumbnail'].includes(file.role);
            },
            promote(file) {
                if (! this.canPromote(file) || this.saving) return;
                const assetId = this.assetId;
                this.$emitter.emit('open-confirm-modal', {
                    zIndex: 10020,
                    title: @js(trans('dam::app.admin.dam.asset.files.make-representative-title')),
                    message: @js(trans('dam::app.admin.dam.asset.files.make-representative-confirm')).replace(':name', file.file_name || file.label || ''),
                    options: { btnAgree: @js(trans('dam::app.admin.dam.asset.files.make-representative-agree')), btnDisagree: @js(trans('dam::app.admin.dam.asset.files.cancel')), btnAgreeClass: 'primary-button', btnDisagreeClass: 'transparent-button' },
                    agree: () => Number(this.assetId) === Number(assetId) && this.promoteNow(file), disagree: () => {},
                });
            },
            async promoteNow(file) {
                if (! this.canPromote(file) || this.saving) return;
                const assetId = this.assetId;
                this.saving = true;
                try {
                    const url = @js(route('admin.dam.assets.attachments.promote', ['asset' => '__ASSET__', 'attachment' => '__SLOT__'])).replace('__ASSET__', assetId).replace('__SLOT__', file.attachment_id);
                    const { data } = await this.$axios.post(url);
                    if (Number(this.assetId) === Number(assetId)) this.files = data.files;
                    this.$emitter.emit('dam:stack-default-changed', { assetId, ...data });
                    this.$emitter.emit('dam-asset-files-updated', { assetId });
                } catch (error) {
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || @js(trans('dam::app.admin.dam.asset.files.arrange-failed')) });
                } finally { this.saving = false; }
            },
            onListKeydown(event, index) {
                if (event.altKey || event.ctrlKey || event.metaKey || event.target?.tagName === 'INPUT') return;
                const last = this.own.length - 1;
                const target = {ArrowLeft: Math.max(0, index - 1), ArrowRight: Math.min(last, index + 1), Home: 0, End: last}[event.key];
                if (target === undefined) return;
                event.preventDefault();
                event.stopPropagation();
                this.focusIndex = target;
                this.$refs.items?.[target]?.focus({preventScroll: true});
                this.$refs.items?.[target]?.scrollIntoView?.({block: 'nearest', inline: 'nearest'});
            },
            async load() {
                try {
                    const url = "{{ route('admin.dam.assets.files.index', ['asset' => '__ASSET__']) }}".replace('__ASSET__', this.assetId);
                    const response = await this.$axios.get(url);
                    this.files = response.data?.files || [];
                    this.$emit('files-loaded', this.own);
                    this.canEdit = !! response.data?.can_edit && response.data?.can_edit_details !== false;
                } catch (_) {
                    // A refetch that fails (after a reorder, say) keeps the last list that worked; the strip is empty only
                    // when it never had one.
                }
            },
            zipUrl(ids) {
                const url = new URL("{{ route('admin.dam.file.asset-files-zip', ['asset' => '__ASSET__']) }}".replace('__ASSET__', this.assetId), window.location.origin);
                ids.forEach((id) => url.searchParams.append('files[]', id));
                return url.toString();
            },
            // The preparing-download card names the zip by its first file: the first one selected, else the stack's first.
            zipFirst(ids) {
                return ids.length ? this.own.find((file) => file.id === ids[0]) : this.own[0];
            },
            zipName(ids) {
                const name = this.zipFirst(ids)?.file_name || this.zipFirst(ids)?.label || '';
                const count = ids.length || this.own.length;

                return count > 1 ? @json(trans('dam::app.admin.dam.download.files')).replace(':name', () => name).replace(':count', () => String(count)) : name;
            },
            zipThumb(ids) {
                return this.zipFirst(ids)?.thumbnail_url || '';
            },
            isModel(file) {
                return this.modelExtensions.includes(String(this.extension(file)).toLowerCase());
            },
            nameParts(file) {
                return window.MothershipNameTail(file.label || file.file_name || '');
            },
            // A whole name cut by its two-line clamp switches to head and tail, so the cut keeps its ending. The tile is
            // measured after the render: a strip mounting with its viewer is not on the page yet when the ref is set.
            measureName(id, el) {
                if (! el) return;
                this.$nextTick(() => {
                    if (el.isConnected && el.clientHeight > 0 && el.scrollHeight > el.clientHeight + 1) this.splitNames[id] = true;
                });
            },
            itemLabel(file) {
                return this.labels.view.replace(':name', file.label || file.file_name || '');
            },
            selectLabel(file) {
                return this.labels.select.replace(':name', file.label || file.file_name || '');
            },
            revealViewing() {
                this.$nextTick(() => {
                    const index = this.own.findIndex((file) => this.isViewing(file));
                    if (index >= 0) this.focusIndex = index;
                    this.revealInLane(this.$refs.items?.[index]);
                });
            },
            // Scroll only the strip's own lane to the viewed file. scrollIntoView also scrolled the page, which on a phone
            // (strip below the preview) pulled the preview off the first screen on load (operator, 2026-10-30).
            revealInLane(item) {
                const strip = item?.closest('[data-dam-attachment-strip]');
                for (let lane = item?.parentElement; lane && strip?.contains(lane); lane = lane.parentElement) {
                    const style = getComputedStyle(lane), box = lane.getBoundingClientRect(), rect = item.getBoundingClientRect();
                    if (/auto|scroll/.test(style.overflowX) && lane.scrollWidth > lane.clientWidth) lane.scrollLeft += rect.left < box.left ? rect.left - box.left : Math.max(0, rect.right - box.right);
                    if (/auto|scroll/.test(style.overflowY) && lane.scrollHeight > lane.clientHeight) lane.scrollTop += rect.top < box.top ? rect.top - box.top : Math.max(0, rect.bottom - box.bottom);
                }
            },
            adobeIcon(adobe) { return window.MothershipAdobe?.icon(adobe) || ''; },
            extension(file) {
                // The server names each file's type (`glb`, not "gltf-"; `dng` for a RAW stored as TIFF) (VISR-11).
                if (file.extension) return file.extension;
                const name = String(file.label || '');
                if (/\.[a-z0-9]{1,5}$/i.test(name)) return name.split('.').pop();
                // A label without an extension: name the type from its MIME subtype (text/plain is TXT, image/svg+xml is SVG).
                const subtype = String(file.mime_type || '').split('/').pop().replace(/^x-/, '').split('+')[0];
                return ({ plain: 'txt', jpeg: 'jpg', 'vnd.adobe.photoshop': 'psd', quicktime: 'mov', mpeg: 'mp3' })[subtype] || subtype.slice(0, 5) || '?';
            },
            isViewing(file) {
                return this.activeFileId ? Number(file.id) === Number(this.activeFileId) : !! file.is_representative;
            },
            toggle(file) {
                this.selected = this.selected.includes(file.id) ? this.selected.filter((id) => id !== file.id) : [...this.selected, file.id];
            },
            openFile(file) {
                window.open(file.url, '_blank', 'noopener');
            },
            /** A click or Enter views the file where the host can show it; elsewhere it keeps choosing for the zip / opens a tab. */
            activate(file) {
                if (! this.canSwitch) return this.toggle(file);
                if (this.isViewing(file)) return;
                this.announcement = this.labels.view.replace(':name', file.label || file.file_name || '');
                this.$emit('open-file', file, this.own);
            },
            dropOn(index) {
                const from = this.own.findIndex((file) => file.attachment_id === this.dragging);
                this.dragging = null;
                if (from >= 0) this.move(from, index);
            },
            async move(from, to) {
                if (!this.canEdit || this.saving || to < 0 || to >= this.own.length || from === to) return;
                const order = this.own.map((file) => file.attachment_id);
                const [moved] = order.splice(from, 1);
                order.splice(to, 0, moved);
                this.saving = true;
                try {
                    const url = "{{ route('admin.dam.assets.attachments.order', ['asset' => '__ASSET__']) }}".replace('__ASSET__', this.assetId);
                    const response = await this.$axios.put(url, { order, expected_files: this.own.map(file => file.id) });
                    this.files = response.data?.files || this.files;
                    this.$emitter.emit('dam:stack-default-changed', { assetId: this.assetId, ...response.data });
                    this.announcement = "@lang('dam::app.admin.dam.asset.files.reordered')";
                    this.$nextTick(() => this.$el.querySelectorAll?.('[data-dam-strip-item]')[to]?.focus());
                    this.$emitter.emit('dam-asset-files-updated', { assetId: this.assetId });
                } catch (error) {
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || "@lang('dam::app.admin.dam.asset.files.arrange-failed')" });
                    this.load();
                } finally {
                    this.saving = false;
                }
            },
        },
    });
</script>
@endonce
