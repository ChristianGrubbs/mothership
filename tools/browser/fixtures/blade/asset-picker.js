
        app.component('v-dam-asset-picker', {
            template: '#v-dam-asset-picker-template',

            data() {
                return {
                    pendingAssign: null,
                    baseSrc: 'http:\/\/mothership.test\/admin\/dam\/picker',
                    pickerSrc: 'http:\/\/mothership.test\/admin\/dam\/picker',
                    pendingCancel: null,
                    maxPick: 0,
                    gridTimeoutMs: 0,
                    pendingFailed: null,
                    currentAssets: [],
                    pickerCurrentDirectory: null,
                    chooseOnly: false,
                    chooseTitle: null,
                    chooseLabel: null,
                    // { label, onClick } from the caller, shown beside Choose in choose-only mode; null for none.
                    extraAction: null,
                    foldersOpen: false,
                    canUploadAssets: true,
                };
            },

            mounted() {
                this.$emitter.on('dam-asset-picker:open', ({ ids, onAssign, choose, onlyExtensions, withStacks, max, onCancel, timeoutMs, onFailed, title, actionLabel, extraAction } = {}) => this.open(ids, onAssign, choose, { onlyExtensions, withStacks, max, onCancel, timeoutMs, onFailed, title, actionLabel, extraAction }));
                this.$emitter.on('change-datagrid', this.loadAssetValues);
                this.$emitter.on('current-directory', (dir) => {
                    this.pickerCurrentDirectory = dir;
                    this.foldersOpen = false;
                });
                this.$emitter.on('dam:uploads-refresh', this.onUploadsRefresh);
            },

            methods: {
                open(ids = [], onAssign = null, choose = false, { onlyExtensions = null, withStacks = false, max = 0, onCancel = null, timeoutMs = 0, onFailed = null, title = null, actionLabel = null, extraAction = null } = {}) {
                    // A request still waiting on this picker is superseded by this one: answer it (cancelled) first, so
                    // whoever asked is never left waiting. Callers that pass no onCancel never set one.
                    if (this.pendingCancel) this.onModalClosed();

                    // Whether the grid is already on screen before this open: a fresh mount loads for itself.
                    const gridMounted = Boolean(this.$refs.datagrid);

                    // Choose-only hides upload (button and drop) and offers Choose whatever the assign permission.
                    this.chooseOnly = choose === true && typeof onAssign === 'function';
                    // An optional heading and action name (Creative Hub Insert names its app); without them the picker says "Choose".
                    this.chooseTitle = typeof title === 'string' && title !== '' ? title : null;
                    this.chooseLabel = typeof actionLabel === 'string' && actionLabel !== '' ? actionLabel : null;
                    this.extraAction = extraAction && typeof extraAction.onClick === 'function' && typeof extraAction.label === 'string' && extraAction.label !== ''
                        ? { label: extraAction.label, onClick: extraAction.onClick }
                        : null;
                    this.pendingAssign = typeof onAssign === 'function' ? onAssign : null;
                    // Creative Hub Insert: a type filter, a count cap and a cancel callback.
                    this.pendingCancel = typeof onCancel === 'function' ? onCancel : null;
                    this.maxPick = Number(max) > 0 ? Number(max) : 0;
                    // The grid's own load is bounded too: set before get() below reads it.
                    this.gridTimeoutMs = Number(timeoutMs) > 0 ? Number(timeoutMs) : 0;
                    this.pendingFailed = typeof onFailed === 'function' ? onFailed : null;
                    this.currentAssets = (ids || []).map(id => (isNaN(id) ? id : Number(id)));

                    const src = new URL(this.baseSrc, window.location.origin);
                    if (Array.isArray(onlyExtensions) && onlyExtensions.length) src.searchParams.set('only_extensions', onlyExtensions.join(','));
                    // Creative Hub Insert: a stack is listed whatever its main file's type (PickerDataGrid); hub/files says which of its files fit.
                    if (withStacks === true && src.searchParams.has('only_extensions')) src.searchParams.set('with_stacks', '1');
                    // Compared in URL form, so a caller without a filter never reloads a grid that is already on the base list.
                    if (src.toString() !== new URL(this.pickerSrc, window.location.origin).toString()) {
                        this.pickerSrc = src.toString();
                        // A grid that mounts with this open boots with the new src; only one already mounted must reload.
                        if (gridMounted) this.$nextTick(() => this.$refs.datagrid?.get());
                    }

                    const datagrid = this.$refs.datagrid;
                    if (datagrid?.applied?.massActions) {
                        datagrid.applied.massActions.indices = [];
                    }

                    this.$refs.assetPickerModal.open();
                },

                // A grid load that failed (v-asset-picker load-failed: a timeout or any other error): say why, then close, which cancels the request.
                onGridFailed({ reason, error } = {}) {
                    const failed = this.pendingFailed;
                    this.pendingFailed = null;
                    if (failed) {
                        failed(reason || 'failed', error);
                        this.$refs.assetPickerModal.close();
                        this.onModalClosed();   // close() may not emit when already closing; onModalClosed answers once
                    }
                },

                onModalClosed() {
                    const cancel = this.pendingCancel;
                    this.pendingCancel = null;
                    if (cancel) {
                        this.pendingAssign = null;
                        cancel();
                    }
                },

                // The caller's extra action takes the request over: nothing waiting on this picker is cancelled or assigned,
                // and the action runs inside the click (it may open the browser's file dialog, which needs the gesture).
                runExtraAction() {
                    const action = this.extraAction;
                    if (! action) return;
                    this.extraAction = null;
                    this.pendingCancel = null;
                    this.pendingAssign = null;
                    this.pendingFailed = null;
                    action.onClick();
                    this.$refs.assetPickerModal.close();
                },

                openPreview(id) {
                    this.$emitter.emit('dam-open-preview', isNaN(id) ? id : Number(id));
                },

                saveAssets() {
                    const indices = this.$refs.datagrid?.applied?.massActions?.indices ?? [];

                    if (this.maxPick && indices.length > this.maxPick) {
                        const tooMany = this.maxPick === 1 ? 'Choose up to 1 file at a time.' : 'Choose up to :max files at a time.';
                        this.$emitter.emit('add-flash', { type: 'warning', message: tooMany.replace(':max', this.maxPick) });

                        return;
                    }

                    this.pendingCancel = null;
                    this.pendingFailed = null;

                    if (typeof this.pendingAssign === 'function') {
                        this.pendingAssign(indices.slice());
                        this.pendingAssign = null;
                    } else {
                        this.$emit('assign', indices.slice());
                    }

                    this.$refs.assetPickerModal.close();
                },

                loadAssetValues() {
                    if (this.currentAssets.length && this.$refs?.datagrid?.applied?.massActions?.indices) {
                        const selectedIndices = this.$refs.datagrid.applied.massActions.indices;

                        this.$refs.datagrid.applied.massActions.indices = [
                            ...this.currentAssets.filter(id => ! selectedIndices.includes(id)),
                            ...selectedIndices,
                        ];

                        this.currentAssets = [];

                        this.$refs.datagrid.setCurrentSelectionMode();
                    }
                },

                uploadToPicker(e) {
                    const files = e.target.files;
                    if (! files || ! files.length) { e.target.value = ''; return; }

                    const dirId = this.pickerCurrentDirectory?.id;
                    if (! dirId) {
                        this.$emitter.emit('add-flash', { type: 'warning', message: 'Select a directory to upload into.' });
                        e.target.value = '';
                        return;
                    }

                    const items = Array.from(files).map(file => ({
                        file,
                        relativePath: file.name,
                        preserveRoot: false,
                    }));

                    this.$emitter.emit('dam:enqueue-upload', { items, folderPaths: [], targetDirId: dirId });

                    e.target.value = '';
                },

                onUploadsRefresh({ directoryId, assetIds = [] } = {}) {
                    if (directoryId == null) {
                        return;
                    }

                    const currentId = this.pickerCurrentDirectory?.id;
                    const isCurrent = currentId != null && Number(currentId) === Number(directoryId);

                    if (! isCurrent) {
                        this.$emitter.emit('dam:reveal-directory', { id: directoryId });

                        return;
                    }

                    const datagrid = this.$refs.datagrid;
                    if (! datagrid?.get) {
                        return;
                    }

                    Promise.resolve(datagrid.get()).then(() => {
                        if (! assetIds.length || ! datagrid.applied?.massActions) {
                            return;
                        }

                        const indices = datagrid.applied.massActions.indices;
                        assetIds.forEach(id => { if (! indices.includes(id)) indices.push(id); });
                        datagrid.setCurrentSelectionMode();
                    });
                },
            },
        });
