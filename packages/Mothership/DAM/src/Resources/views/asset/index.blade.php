<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.dam.index.title')
    </x-slot:title>

    {!! view_render_event('mothership.dam.admin.main.before') !!}

    <v-dam-main></v-dam-main>

    {{-- One page-level 3D thumbnail runner for BOTH Library modes (ADR 0006). --}}
    @include('dam::components.model-thumbnails.runner')

    @pushOnce('styles')
        <style>
            .dam-icon-library:focus-visible {
                outline: 2px solid rgb(var(--c-primary-600));
                outline-offset: 3px;
            }
        </style>
    @endPushOnce

    {!! view_render_event('mothership.dam.admin.main.after') !!}

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-dam-main-template"
        >
            <div class="{{ config('dam.explorer.enabled') ? 'flex flex-col min-w-0' : '' }}">
                {!! view_render_event('dam.admin.main.form.before') !!}
                    {{-- data-explorer-split: on a two-segment screen (book pose), public/pwa/device.css puts the Directory column on one side of the fold and the panel on the other. --}}
                    <div class="{{ config('dam.explorer.enabled') ? 'flex gap-2.5 max-md:flex-wrap items-start min-w-0' : 'flex gap-2.5 mt-3.5 max-lg:flex-wrap min-w-0' }}" data-explorer-split data-fold-split>
                        @php
                            $showTree      = !config('dam.explorer.enabled') || config('dam.explorer.show_tree');

                            // Web page collections are their own tree (ADR 0008), so the Library
                            // sidebar lists them as jump links instead of directory bookmarks.
                            $showWebCollections = config('dam.explorer.enabled') && config('dam.web_pages.enabled', true) && bouncer()->hasPermission('dam.pages');
                        @endphp
                        @if (config('dam.explorer.enabled'))
                        <div
                            v-show="drawerOpen"
                            @click="drawerOpen = false"
                            data-explorer-drawer-scrim
                            class="fixed inset-0 z-[1000] bg-black/40 md:hidden"
                            aria-hidden="true"
                        ></div>
                        @endif
                        <div
                            data-explorer-sidebar
                            class="flex flex-col gap-3 shrink-0 {{ config('dam.explorer.enabled')
                                ? 'md:static md:shrink md:min-w-[220px] md:w-[280px] md:max-w-full md:translate-x-0 md:bg-transparent md:dark:bg-transparent md:shadow-none md:p-0 md:overflow-visible max-md:fixed max-md:top-14 max-md:bottom-0 max-md:left-0 max-md:z-[1001] max-md:w-[280px] max-md:max-w-[85vw] max-md:bg-gray-50 dark:max-md:bg-cherry-900 max-md:shadow-2xl max-md:overflow-y-auto max-md:p-3 transition-transform duration-200'
                                : 'w-[280px] max-w-full max-lg:w-full' }}"
                            @if (config('dam.explorer.enabled'))
                            :class="[ drawerOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full' ]"
                            :style="{ width: isDesktopView ? sidebarWidth + 'px' : undefined, display: (!showSidebar && isDesktopView) ? 'none' : undefined, bottom: drawerBottom ? drawerBottom + 'px' : undefined }"
                            @endif
                        >

                            @if ($showTree)
                            <div class="flex flex-col gap-5 p-4 bg-white dark:bg-cherry-900 rounded-lg box-shadow">
                                {!! view_render_event('dam.admin.main.form.directory.before') !!}
                                <div class="flex flex-col gap-2">
                                    <div class="flex justify-between items-center gap-2">
                                        <p class="text-xl text-zinc-800 dark:text-slate-50 font-bold !leading-normal">
                                            @lang('dam::app.admin.dam.index.title')
                                        </p>
                                    </div>
                                    <p class="text-sm text-zinc-600 !leading-normal dark:text-slate-300">
                                        @lang('dam::app.admin.dam.index.description')
                                    </p>
                                </div>

                                <div class="dark:bg-cherry-700 border-b dark:border-cherry-800"></div>
                                @if (bouncer()->hasPermission('dam.directory.index'))
                                    <div class="flex flex-col gap-5">
                                        <p class="text-base text-zinc-800 dark:text-slate-50 font-bold !leading-normal">
                                            @lang('dam::app.admin.dam.index.directory.title')
                                        </p>
                                        <x-dam::tree.damdirectories />
                                    </div>
                                @endif
                                {!! view_render_event('dam.admin.main.form.directory.after') !!}
                            </div>
                            @endif

                            {{-- P4 organization: favorites, sections, labels, boards and pinned saved searches. --}}
                            @if (config('dam.explorer.enabled') && bouncer()->hasPermission('dam.asset.view'))
                                @include('dam::components.explorer.organize')
                            @endif

                            @if (filled($iconLibraryUrl = config('dam-home.icon_library_url')))
                            <a
                                href="{{ rtrim($iconLibraryUrl, '/') }}/"
                                target="_blank"
                                rel="noopener noreferrer"
                                data-dam-icon-library
                                    class="dam-icon-library group flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-4 box-shadow transition-colors hover:border-primary-400 hover:bg-primary-50 dark:border-cherry-800 dark:bg-cherry-900 dark:hover:border-primary-500 dark:hover:bg-cherry-800"
                                aria-label="{{ __('Open Icon Library in a popup') }}"
                            >
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-cherry-800 dark:text-primary-300" aria-hidden="true">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <rect x="3" y="3" width="7" height="7" rx="2" />
                                        <circle cx="17.5" cy="6.5" r="3.5" />
                                        <path d="m6.5 14 4 7h-8z" stroke-linejoin="round" />
                                        <rect x="14" y="14" width="7" height="7" rx="2" />
                                    </svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-zinc-800 dark:text-slate-50">{{ __('Icon Library') }}</span>
                                    <span class="mt-1 block text-xs text-zinc-500 dark:text-slate-300">{{ __('Browse icons') }}</span>
                                </span>
                                <svg class="shrink-0 text-zinc-400 group-hover:text-primary-600 dark:group-hover:text-primary-300" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                    <path d="M14 3h7v7m0-7L10 14M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </a>
                            @endif

                            @if ($showWebCollections)
                            <div class="flex flex-col gap-3 p-4 bg-white dark:bg-cherry-900 rounded-lg box-shadow" data-dam-web-collections>
                                <p class="text-base text-zinc-800 dark:text-slate-50 font-bold !leading-normal">
                                    @lang('dam::app.admin.explorer.web-collections.title')
                                </p>
                                <p class="text-xs text-zinc-500 !leading-normal dark:text-slate-300">
                                    @lang('dam::app.admin.explorer.web-collections.description')
                                </p>
                                <div class="dark:bg-cherry-700 border-b dark:border-cherry-800"></div>
                                <x-dam::explorer.web-collections />
                            </div>
                            @endif

                            @if (bouncer()->hasPermission('dam.asset.view'))
                            <div class="flex flex-col gap-3 p-4 bg-white dark:bg-cherry-900 rounded-lg box-shadow" data-dam-stack-suggestions>
                                <p class="text-base text-zinc-800 dark:text-slate-50 font-bold !leading-normal">
                                    @lang('dam::app.admin.dam.suggestions.title')
                                </p>
                                <p class="text-xs text-zinc-500 !leading-normal dark:text-slate-300">
                                    @lang('dam::app.admin.dam.suggestions.description')
                                </p>
                                <div class="dark:bg-cherry-700 border-b dark:border-cherry-800"></div>
                                <x-dam::explorer.stack-suggestions :can-edit="bouncer()->hasPermission('dam.asset.edit')" />
                            </div>
                            @endif

                        </div>

                        <div v-if="isDesktopView && showSidebar" class="dam-sidebar-resize max-md:hidden" role="separator" tabindex="0" aria-label="Directory sidebar width" aria-orientation="vertical" :aria-valuenow="sidebarWidth" aria-valuemin="220" aria-valuemax="560" @pointerdown.prevent="startSidebarResize" @pointermove="moveSidebarResize" @pointerup="endSidebarResize" @pointercancel="endSidebarResize" @keydown="resizeSidebarByKey"></div>

                        @if (config('dam.explorer.enabled') && !$showTree && bouncer()->hasPermission('dam.directory.index'))
                        <div class="hidden" aria-hidden="true">
                            <x-dam::tree.damdirectories :visible="false" />
                        </div>
                        @endif

                        <div class="flex flex-col gap-2 flex-1 {{ config('dam.explorer.enabled') ? 'md:min-w-[360px] lg:min-w-[400px]' : 'max-xl:flex-auto' }} min-w-0 p-4 bg-white dark:bg-cherry-900 rounded-lg box-shadow" data-explorer-panel>
                            {!! view_render_event('dam.admin.main.form.grid.before') !!}
                            @if (config('dam.explorer.enabled'))
                                <x-dam::explorer.index />
                            @else
                                <v-dam-upload
                                    :acl-bypass="{{ dam_acl_bypass() ? 'true' : 'false' }}"
                                    :accessible-ids='@json(dam_accessible_dir_ids())'
                                ></v-dam-upload>
                            @endif
                            {!! view_render_event('dam.admin.main.form.grid.after') !!}
                        </div>
                    </div>
                {!! view_render_event('dam.admin.main.form.after') !!}
                <v-dam-model-thumbnail-runner></v-dam-model-thumbnail-runner>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-main', {
                template: '#v-dam-main-template',

                data() {
                    let showSidebar = true;
                    try {
                        const s = localStorage.getItem('dam_show_sidebar');
                        if (s !== null) showSidebar = s !== 'false';
                    } catch {}
                    let isDesktopView = true;
                    try { isDesktopView = window.matchMedia('(min-width: 768px)').matches; } catch {}

                    let sidebarWidth = 280;
                    try { sidebarWidth = window.MothershipLibraryNavigation.sidebarWidth(localStorage.getItem('dam_sidebar_width')); } catch {}
                    return { showSidebar, drawerOpen: false, drawerBottom: 0, isDesktopView, sidebarWidth };
                },

                watch: {
                    drawerOpen(open) {
                        if (open) this.measureDrawerBottom();
                    },
                },

                mounted() {
                    // An internal "Copy link" to one file (admin.dam.assets.open) lands here with ?open_asset=<id>.
                    // The preview modal is a sibling mounted in this same pass, so its listener exists by the
                    // next tick; the existing preview opens, and the parameter leaves the address bar.
                    try {
                        const params = new URLSearchParams(window.location.search);
                        const openAsset = Number(params.get('open_asset'));
                        if (Number.isSafeInteger(openAsset) && openAsset > 0) {
                            setTimeout(() => this.$emitter.emit('dam-open-preview', { id: openAsset, siblings: [openAsset], directoryId: null }), 0);
                            params.delete('open_asset');
                            const query = params.toString();
                            window.history.replaceState(window.history.state, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash);
                        }
                    } catch {}

                    let dirId = null;

                    try { dirId = sessionStorage.getItem('dam_return_dir'); sessionStorage.removeItem('dam_return_dir'); } catch {}

                    if (! dirId) {
                        try {
                            const urlDirId = new URLSearchParams(window.location.search).get('filters[directory_id][]');
                            if (urlDirId) dirId = urlDirId;
                        } catch {}
                    }

                    if (! dirId && !(window.MothershipLibraryNavigation?.locationState?.(location.search)?.explicit ?? false)) {
                        try {
                            const datagrids = JSON.parse(localStorage.getItem('datagrids') || '[]');
                            const damSrc = "{{ route('admin.dam.assets.index') }}";
                            const entry = datagrids.find(d => d.src === damSrc + '?media_type=all') || datagrids.find(d => d.src === damSrc);
                            const col = entry?.applied?.filters?.columns?.find(c => c.index === 'directory_id');
                            if (col?.value?.[0]) dirId = String(col.value[0]);
                        } catch {}
                    }

                    if (dirId && window.MothershipLibraryNavigation?.locationState?.(location.search)?.scope !== 'all') {
                        this.$emitter.emit('dam:reveal-directory', { id: Number(dirId), silent: true });
                    }

                    this.$emitter.on('dam:toggle-sidebar', () => {
                        if (this.isDesktop()) {
                            this.showSidebar = !this.showSidebar;
                            try { localStorage.setItem('dam_show_sidebar', this.showSidebar); } catch {}
                            this.$emitter.emit('dam:sidebar-visibility-changed', this.showSidebar);
                        } else {
                            this.drawerOpen = !this.drawerOpen;
                        }
                    });

                    this._onSidebarKeydown = (e) => { if (e.key === 'Escape') this.drawerOpen = false; };
                    window.addEventListener('keydown', this._onSidebarKeydown);

                    this._onSidebarResize = () => { this.isDesktopView = this.isDesktop(); if (this.isDesktop()) this.drawerOpen = false; this.measureDrawerBottom(); };
                    window.addEventListener('resize', this._onSidebarResize);

                    this.$emitter.on('current-directory', () => { if (! this.isDesktop()) this.drawerOpen = false; });
                    this.$emitter.on('dam:all-assets', () => { if (!this.isDesktop()) this.drawerOpen = false; });
                    this.$emitter.on('dam:organize-open', () => { if (!this.isDesktop()) this.drawerOpen = false; }); // P4 organization
                },

                beforeUnmount() {
                    if (this._onSidebarKeydown) window.removeEventListener('keydown', this._onSidebarKeydown);
                    if (this._onSidebarResize) window.removeEventListener('resize', this._onSidebarResize);
                },

                methods: {
                    // The phone tab bar covers the bottom of the screen, so the open drawer stops where the tab bar starts.
                    measureDrawerBottom() {
                        const tabbar = this.isDesktop() ? null : document.querySelector('[data-bridge-tabbar]');
                        this.drawerBottom = tabbar ? Math.ceil(tabbar.getBoundingClientRect().height) : 0;
                    },

                    // From lg up the column may render narrower than its stored width, giving way to the asset panel, so a
                    // drag or a key press starts from what the user sees: the column just before the separator.
                    effectiveSidebarWidth(handle) {
                        const rendered = handle?.previousElementSibling?.getBoundingClientRect().width;
                        return rendered ? Math.min(this.sidebarWidth, Math.max(220, Math.round(rendered))) : this.sidebarWidth;
                    },
                    startSidebarResize(event) {
                        this._sidebarResize = { x: event.clientX, width: this.effectiveSidebarWidth(event.currentTarget) };
                        event.currentTarget.setPointerCapture(event.pointerId);
                    },
                    moveSidebarResize(event) {
                        if (!this._sidebarResize) return;
                        this.sidebarWidth = Math.min(560, Math.max(220, this._sidebarResize.width + event.clientX - this._sidebarResize.x));
                    },
                    endSidebarResize(event) {
                        this._sidebarResize = null;
                        if (event.currentTarget.hasPointerCapture?.(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
                        try { localStorage.setItem('dam_sidebar_width', String(this.sidebarWidth)); } catch {}
                    },
                    resizeSidebarByKey(event) {
                        const current = this.effectiveSidebarWidth(event.currentTarget);
                        const values = { ArrowLeft: current - 20, ArrowRight: current + 20, Home: 220, End: 560 };
                        if (!(event.key in values)) return;
                        event.preventDefault();
                        this.sidebarWidth = Math.min(560, Math.max(220, values[event.key]));
                        try { localStorage.setItem('dam_sidebar_width', String(this.sidebarWidth)); } catch {}
                    },

                    isDesktop() {
                        try { return window.matchMedia('(min-width: 768px)').matches; } catch { return true; }
                    },
                }
            })
        </script>
    @endPushOnce

    <x-dam::asset.drop-upload />

    {{-- Explorer supplies its own toolbar and grid. Render the classic component tree only when mounted. --}}
    @if (!config('dam.explorer.enabled'))
    @pushOnce('scripts')

        <script
            type="text/x-template"
            id="v-dam-upload-template"
        >
            <div>
                <v-dam-drop-upload
                    ref="dropUpload"
                    :current-directory="currentDirectory"
                    :can-upload="canUploadHere"
                    @refresh-datagrid="$refs.datagrid?.get()"
                >
                    <div class="flex flex-wrap justify-between items-center gap-2 w-full">
                        <v-dam-breadcrumb></v-dam-breadcrumb>
                        <div class="flex flex-wrap items-center gap-2">
                        @if (bouncer()->hasPermission('dam.asset.edit'))
                            <x-admin::dropdown position="bottom-right" :teleport="true">
                                <x-slot:toggle>
                                    <button
                                        type="button"
                                        class="secondary-button whitespace-nowrap"
                                        aria-label="Generate missing 3D thumbnails"
                                        data-dam-generate-model-thumbnails
                                    >
                                        <span class="max-sm:hidden">Generate missing 3D thumbnails</span>
                                        <span class="sm:hidden">3D thumbnails</span>
                                    </button>
                                </x-slot:toggle>

                                <x-slot:menu class="shadow-md !p-0 z-10">
                                    <li
                                        v-if="currentDirectory"
                                        class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                        @click="generateModelThumbnails(currentDirectory.id)"
                                    >
                                        In this folder
                                    </li>
                                    <li
                                        class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                        @click="generateModelThumbnails(null)"
                                    >
                                        In every folder I can reach
                                    </li>
                                </x-slot:menu>
                            </x-admin::dropdown>
                        @endif
                        @if (bouncer()->hasPermission('dam.asset.upload') && bouncer()->hasPermission('dam.directory.index'))
                            <div class="flex items-center gap-2" v-if="canUploadHere">

                                <input type="file"
                                    multiple="multiple"
                                    name="files[]"
                                    id="file-upload"
                                    class="hidden"
                                    :disabled="isUploading || treeBusy"
                                    @change="onFileChange"
                                />
                                <input type="file"
                                    webkitdirectory
                                    multiple="multiple"
                                    name="folder_files[]"
                                    id="folder-upload"
                                    class="hidden"
                                    :disabled="isUploading || isFolderUploading || treeBusy"
                                    @change="onFolderChange"
                                />

                                <x-admin::dropdown position="bottom-right" :teleport="true">
                                    <x-slot:toggle>
                                        <button
                                            type="button"
                                            class="primary-button flex items-center gap-x-1.5 whitespace-nowrap"
                                            :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isUploading || isFolderUploading || treeBusy }"
                                            :aria-disabled="isUploading || isFolderUploading || treeBusy"
                                        >
                                            <svg
                                                v-if="isUploading || isFolderUploading"
                                                class="align-center inline-block animate-spin h-5 w-5 text-white"
                                                xmlns="http://www.w3.org/2000/svg"
                                                fill="none"
                                                aria-hidden="true"
                                                viewBox="0 0 24 24"
                                            >
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="#ffffff" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span v-else class="text-2xl leading-none -mt-0.5">+</span>
                                            <span v-if="isUploading || isFolderUploading">@lang('dam::app.admin.dam.index.uploading')</span>
                                            <span v-else>@lang('dam::app.admin.dam.index.new')</span>
                                        </button>
                                    </x-slot:toggle>

                                    <x-slot:menu class="shadow-md !p-0 z-10">
                                        <li
                                            class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                            @click="triggerFileUpload"
                                        >
                                            <i class="icon-dam-upload text-base"></i>
                                            @lang('dam::app.admin.dam.index.directory.actions.upload-files')
                                        </li>
                                        <li
                                            class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                            @click="triggerFolderUpload"
                                        >
                                            <i class="icon-dam-add-folder text-base"></i>
                                            @lang('dam::app.admin.explorer.context.folder-upload')
                                        </li>
                                        @if (bouncer()->hasPermission('dam.directory.store'))
                                        <li
                                            class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300 border-t border-gray-100 dark:border-cherry-700"
                                            @click="createDirectory"
                                        >
                                            <i class="icon-dam-add-folder text-base"></i>
                                            @lang('dam::app.admin.dam.index.directory.actions.add-directory')
                                        </li>
                                        @endif
                                    </x-slot:menu>
                                </x-admin::dropdown>

                                <button
                                    v-if="isUploading || isFolderUploading"
                                    type="button"
                                    class="secondary-button"
                                    @click="isUploading ? cancelUpload() : cancelFolderUpload()"
                                >
                                    @lang('dam::app.admin.dam.index.cancel')
                                </button>
                            </div>
                        @endif
                        </div>
                    </div>

                    <div class="flex gap-2 flex-wrap my-3" role="group" aria-label="Media type">
                        <button v-for="chip in [{id:'all',label:'All'}, {id:'images',label:'Images'}, {id:'videos',label:'Videos'}, {id:'svgs',label:'SVGs'}, {id:'audio',label:'Audio'}, {id:'models',label:'3D'}]" :key="chip.id" type="button" :aria-pressed="mediaType === chip.id" @click="mediaType = chip.id" class="rounded-full border px-3 py-1 text-sm" :class="mediaType === chip.id ? 'bg-primary-600 text-white' : 'text-zinc-600 dark:text-white'">@{{ chip.label }}</button>
                    </div>

                    {!! view_render_event('mothership.admin.dam.assets.list.before') !!}

                    @if (bouncer()->hasPermission('dam.asset.view'))
                        <div
                            class="relative"
                            :class="{ 'pointer-events-none': isUploading || isFolderUploading || dropActiveCount > 0 || treeBusy }"
                            :aria-busy="isUploading || isFolderUploading || dropActiveCount > 0 || treeBusy"
                        >

                            <div
                                v-if="isUploading || isFolderUploading || treeBusy"
                                class="absolute inset-0 bg-white/60 dark:bg-cherry-900/60 z-[1] rounded-lg"
                                aria-hidden="true"
                            ></div>

                            <x-dam::datagrid.dam
                                ::src="datagridSource"
                                ::ignore-stored-state="explicitNavigation"
                                ref="datagrid"
                            />
                        </div>
                    @endif

                    {!! view_render_event('mothership.admin.dam.assets.list.after') !!}
                </v-dam-drop-upload>
            </div>
        </script>

    <script type="module">
        const damUploadCompleteMsg     = @js(trans('dam::app.admin.dam.index.upload-complete'));
        const damItemUploadCompleteMsg = @js(trans('dam::app.admin.dam.index.item-upload-complete'));
        const damUploadInProgressTitle   = @js(trans('dam::app.admin.dam.index.upload-in-progress-title'));
        const damUploadInProgressMessage = @js(trans('dam::app.admin.dam.index.upload-in-progress-message'));
        const damUploadLeaveBtn          = @js(trans('dam::app.admin.dam.index.upload-leave-page'));
        const damUploadStayBtn           = @js(trans('dam::app.admin.dam.index.upload-stay-page'));

        app.component('v-dam-upload', {
            template: '#v-dam-upload-template',

            props: {
                aclBypass: {
                    type: Boolean,
                    default: false,
                },
                accessibleIds: {
                    type: Array,
                    default: () => [],
                },
            },

            data() {
                return {
                    currentDirectory: null,
                    mediaType: window.MothershipLibraryNavigation?.locationState?.(location.search)?.mediaType ?? 'all',
                    explicitNavigation: window.MothershipLibraryNavigation?.locationState?.(location.search)?.explicit ?? false,
                    isUploading: false,
                    isFolderUploading: false,
                    treeBusy: false,
                    dropActiveCount: 0,
                    localAccessibleIds: [...(this.accessibleIds || [])],
                }
            },

            computed: {
                datagridSource() {
                    return "{{ route('admin.dam.assets.index') }}" + '?media_type=' + encodeURIComponent(this.mediaType);
                },
                canUploadHere() {
                    if (! this.currentDirectory) return false;
                    if (this.aclBypass) return true;

                    return this.localAccessibleIds.map(Number).includes(Number(this.currentDirectory.id));
                },
            },

            mounted() {
                this._navigationConfirmed = false;

                this._beforeUnloadHandler = (e) => {
                    if (this._navigationConfirmed) return;
                    if (this.dropActiveCount > 0 || this.isUploading || this.isFolderUploading) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                };
                window.addEventListener('beforeunload', this._beforeUnloadHandler);

                this._linkClickHandler = (e) => {
                    if (! this.dropActiveCount && ! this.isUploading && ! this.isFolderUploading) return;

                    const anchor = e.target.closest('a[href]');
                    if (! anchor) return;

                    const raw = anchor.getAttribute('href');
                    if (! raw || raw === '#' || raw.startsWith('javascript:')) return;

                    const url = new URL(anchor.href, window.location.href);
                    if (
                        url.origin === window.location.origin
                        && url.pathname === window.location.pathname
                        && url.search === window.location.search
                    ) return;

                    e.preventDefault();
                    e.stopPropagation();

                    const target = anchor.href;
                    this.$emitter.emit('open-confirm-modal', {
                        title: damUploadInProgressTitle,
                        message: damUploadInProgressMessage,
                        options: {
                            btnDisagree: damUploadStayBtn,
                            btnAgree: damUploadLeaveBtn,
                            btnAgreeClass: 'danger-button',
                            btnDisagreeClass: 'transparent-button',
                        },
                        agree: () => {
                            this._navigationConfirmed = true;
                            window.location.href = target;
                        },
                        disagree: () => {},
                    });
                };
                document.addEventListener('click', this._linkClickHandler, true);

                this.$emitter.on('current-directory', (data) => {
                    this.currentDirectory = data;
                });

                // The datagrid has no per-row `dam:thumbnail-updated` handling,
                // so reload it once per capture run that saved anything.
                this._onModelThumbnailsFinished = ({ done = 0 } = {}) => {
                    if (done > 0) this.$refs.datagrid?.get();
                };
                this.$emitter.on('dam:model-thumbnails-finished', this._onModelThumbnailsFinished);

                this.$emitter.on('dam:tree-busy', (busy) => {
                    this.treeBusy = !! busy;
                });

                this.$emitter.on('dam:directory-granted', (id) => {
                    const numId = Number(id);
                    if (! this.localAccessibleIds.map(Number).includes(numId)) {
                        this.localAccessibleIds.push(numId);
                    }
                });

                this.$emitter.on('dam:upload-files', (formData) => {
                    if (this.isUploading) return;
                    const files = formData.getAll('files[]');
                    const targetDirId = formData.get('directory_id') ?? this.currentDirectory?.id;
                    this.$refs.dropUpload?.enqueueUpload({
                        items: files.map(file => ({ file, relativePath: file.name, preserveRoot: false })),
                        folderPaths: [],
                        targetDirId,
                    });
                });

                this.$emitter.on('dam:folder-upload-start', () => {
                    this.isFolderUploading = true;
                });

                this.$emitter.on('dam:folder-upload-end', () => {
                    this.isFolderUploading = false;
                });

                this.$emitter.on('dam:drop-upload-active', (count) => {
                    this.dropActiveCount = count;
                });
            },

            watch: {
                isUploading(value) {
                    this.$emitter.emit('dam:grid-busy', !! value);
                },
            },

            methods: {
                onFileChange(e) {
                    e.preventDefault();

                    if (this.isUploading) {
                        e.target.value = null;
                        return;
                    }

                    const fileInput = Array.from(e.target.files ?? []);

                    if (fileInput.length > 0) {
                        this.$refs.dropUpload?.enqueueUpload({
                            items: fileInput.map(file => ({ file, relativePath: file.name, preserveRoot: false })),
                            folderPaths: [],
                            targetDirId: this.currentDirectory?.id,
                        });
                    }

                    e.target.value = null;
                },

                /** Backfill 3D thumbnails via the page-level runner (ADR 0006); null = every reachable folder. */
                generateModelThumbnails(directoryId) {
                    this.$emitter.emit('dam:generate-model-thumbnails', { directoryId });
                },

                triggerFileUpload() {
                    if (this.isUploading || this.isFolderUploading) return;
                    document.getElementById('file-upload')?.click();
                },

                triggerFolderUpload() {
                    if (this.isUploading || this.isFolderUploading) return;
                    document.getElementById('folder-upload')?.click();
                },

                onFolderChange(e) {
                    const files = Array.from(e.target.files ?? []);
                    if (! files.length) { e.target.value = ''; return; }

                    const targetDirId = this.currentDirectory?.id;
                    if (! targetDirId) { e.target.value = ''; return; }

                    const folderPaths = new Set();
                    files.forEach(f => {
                        const rel  = f.webkitRelativePath || f.name;
                        const segs = rel.split('/');
                        for (let i = 1; i < segs.length; i++) folderPaths.add(segs.slice(0, i).join('/'));
                    });

                    this.$refs.dropUpload?.enqueueUpload({
                        items: files.filter(f => f.size > 0)
                                    .map(f => ({ file: f, relativePath: f.webkitRelativePath || f.name, preserveRoot: true })),
                        folderPaths: [...folderPaths],
                        targetDirId,
                    });

                    e.target.value = '';
                },

                createDirectory() {
                    const id = this.currentDirectory?.id;
                    if (! id) return;

                    this.$emitter.emit('dam:open-create-dir', { item: { id } });
                },

                cancelFolderUpload() {
                    this.$emitter.emit('dam:cancel-folder-upload');
                },
            },

            beforeUnmount() {
                window.removeEventListener('beforeunload', this._beforeUnloadHandler);
                document.removeEventListener('click', this._linkClickHandler, true);
                this.$emitter.off('dam:model-thumbnails-finished', this._onModelThumbnailsFinished);
            },
        })
    </script>
    @endPushOnce

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-breadcrumb-template">
            <nav class="flex items-center gap-1 flex-wrap text-sm" aria-label="Directory breadcrumb">
                <template v-if="loading">
                    <div class="shimmer h-4 w-10 rounded"></div>
                    <span class="text-gray-500 dark:text-gray-400">/</span>
                    <div class="shimmer h-4 w-24 rounded"></div>
                    <span class="text-gray-500 dark:text-gray-400">/</span>
                    <div class="shimmer h-4 w-16 rounded"></div>
                </template>
                <template v-else>
                    <template v-for="(crumb, i) in crumbs" :key="crumb.id">
                        <span v-if="i > 0" class="text-gray-500 dark:text-gray-400">/</span>
                        <span
                            v-if="i === crumbs.length - 1"
                            class="px-1 py-0.5 text-primary-700 dark:text-primary-300 font-semibold"
                            aria-current="page"
                        >@{{ crumb.name }}</span>
                        <button
                            v-else
                            type="button"
                            class="px-1 py-0.5 rounded transition-colors text-gray-600 dark:text-gray-300 hover:text-primary-700 dark:hover:text-primary-400 hover:underline cursor-pointer"
                            @click="navigateTo(crumb)"
                        >@{{ crumb.name }}</button>
                    </template>
                    <span v-if="!crumbs.length" class="text-base text-gray-600 dark:text-gray-300 font-bold">@lang('dam::app.admin.dam.index.root')</span>
                </template>
            </nav>
        </script>

        <script type="module">
            app.component('v-dam-breadcrumb', {
                template: '#v-dam-breadcrumb-template',
                data() {
                    return { crumbs: [], loading: true };
                },
                mounted() {
                    this._onBreadcrumb = (crumbs) => {
                        this.loading = false;
                        this.crumbs = Array.isArray(crumbs) ? crumbs : [];
                    };
                    this.$emitter.on('current-directory-breadcrumb', this._onBreadcrumb);
                },
                beforeUnmount() {
                    if (this._onBreadcrumb) this.$emitter.off('current-directory-breadcrumb', this._onBreadcrumb);
                },
                methods: {
                    navigateTo(crumb) {
                        this.$emitter.emit('dam:reveal-directory', { id: crumb.id, silent: true });
                    },
                },
            });
        </script>
    @endPushOnce

    @endif

    {{-- Only the Library page has an explorer tab that answers Find similar. --}}
    {{-- Once: the batch dialog's watermark picker includes the picker modal, which would mount a second one. --}}
    @once('dam-grid-preview-modal')
        @include('dam::asset.grid-preview-modal', ['showFindSimilar' => true])
    @endonce

    @pushOnce('scripts')
        @include('dam::share.components.share-link-modal')
    @endPushOnce

    <v-share-link-modal></v-share-link-modal>

    @if (bouncer()->hasPermission('dam.upload_requests.create'))
        @pushOnce('scripts')
            @include('dam::upload-requests.modal')
        @endPushOnce

        <v-dam-upload-request-modal></v-dam-upload-request-modal>
    @endif

    <x-dam::tag.assign-modal />

    <x-dam::merge.dialog />

    {{-- D5: a stack's card fans its files out from here; choosing one opens it in the preview. --}}
    @if (config('dam.explorer.enabled'))
        <x-dam::explorer.stack-fan />
    @endif

    {{-- P4a (D9): the bulk "Edit fields" modal behind the Library's mass action. --}}
    @if (bouncer()->hasPermission('dam.asset.update'))
        @include('dam::components.metadata-fields.inputs')
        <v-dam-field-bulk-modal></v-dam-field-bulk-modal>
    @endif

    @if (bouncer()->hasPermission('dam.asset.process'))
        <x-dam::batch.process-modal />
    @endif

    {{-- Rename with AI, behind the Library's mass action of the same name. --}}
    @if (bouncer()->hasPermission('dam.asset.rename') && filled(config('ai-index.api')))
        @include('dam::components.explorer.ai-rename-modal')
        <v-dam-ai-rename-modal></v-dam-ai-rename-modal>
    @endif

    @if (bouncer()->hasPermission('dam.collections.update'))
        <x-dam::collection.add-modal />
    @endif
</x-admin::layouts>
