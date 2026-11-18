{{--
    A stack's files fanned out of its Library card (D5). Opened by `dam:open-stack-fan` with
    { asset, el, siblings, directoryId, total, offset, onDetails }: the cards fly out of the stack's thumbnail into a tray
    anchored on it, each naming its format, label and role. Choosing one opens that file in the preview;
    Escape, the backdrop or Close folds them back into the stack. More files than fit fold into a "+N more"
    card that switches the tray to a scrolling grid of all of them. Listing a stack's files needs
    `dam.asset.files.index`; the cards that open the fan check it, and a refusal still reads as one here.
--}}
@include('dam::components.shared.name-tail')
@include('dam::components.explorer.member-download')
@include('dam::components.shared.adobe-identity')
@pushOnce('scripts')
    <style>
        .dam-fan { position: fixed; inset: 0; z-index: 10005; } /* over the admin sidebar (10001), under the preview (10010) */
        .dam-fan__backdrop { position: absolute; inset: 0; touch-action: none; }
        /* The stack's own card stays lit inside the dimmed page: a ring plus a page-sized shadow around it. */
        .dam-fan__anchor { position: absolute; border-radius: 10px; box-shadow: 0 0 0 3px rgb(var(--c-primary-600, 37 99 235)), 0 0 0 200vmax rgba(3, 7, 18, .45); pointer-events: none; opacity: 0; transition: opacity .22s ease; }
        .dam-fan.is-expanded .dam-fan__anchor { opacity: 1; }
        .dam-fan__tray { position: absolute; }
        .dam-fan__panel { position: absolute; inset: 0; border-radius: 14px; background: rgba(255, 255, 255, .96); box-shadow: 0 18px 50px rgba(0, 0, 0, .28); opacity: 0; transform: scale(.98); transition: opacity .22s ease, transform .28s ease; }
        .dark .dam-fan__panel { background: rgba(17, 24, 39, .96); }
        .dam-fan.is-expanded .dam-fan__panel { opacity: 1; transform: none; }
        .dam-fan__caret { position: absolute; width: 16px; height: 16px; background: rgba(255, 255, 255, .96); transform: rotate(45deg); opacity: 0; transition: opacity .22s ease; box-shadow: -2px -2px 4px rgba(0, 0, 0, .06); }
        .dark .dam-fan__caret { background: rgba(17, 24, 39, .96); }
        .dam-fan.is-expanded .dam-fan__caret { opacity: 1; }
        .dam-fan__caret.is-top { top: -7px; }
        .dam-fan__caret.is-bottom { bottom: -7px; }
        .dam-fan__caret.is-left { left: -7px; }
        .dam-fan__caret.is-right { right: -7px; }
        .dam-fan__header { position: absolute; left: 12px; right: 12px; top: 8px; height: 40px; display: flex; align-items: center; gap: 8px; opacity: 0; transition: opacity .2s ease .08s; }
        .dam-fan.is-expanded .dam-fan__header { opacity: 1; }
        /* A phone has no room for the title beside three controls: the title and close share the first row, the actions take the second. */
        .dam-fan__header.is-narrow { flex-wrap: wrap; align-content: flex-start; }
        .dam-fan__header.is-narrow .dam-fan__title { flex: 1 1 calc(100% - 40px); }
        .dam-fan__header.is-narrow .dam-fan__close { order: 1; }
        .dam-fan__header.is-narrow .dam-fan__open-main, .dam-fan__header.is-narrow .dam-fan__link { order: 2; }
        .dam-fan__card { position: absolute; left: 0; top: 0; display: flex; flex-direction: column; text-align: left; border-radius: 10px; background: #fff; border: 1px solid rgb(209 213 219); box-shadow: 0 6px 16px rgba(0, 0, 0, .14); overflow: hidden; cursor: pointer; transform-origin: 0 0; transition: transform .38s cubic-bezier(.2, .8, .2, 1), opacity .22s ease, box-shadow .15s ease, border-color .15s ease; will-change: transform; }
        .dark .dam-fan__card { background: rgb(31 41 55); border-color: rgb(55 65 81); }
        .dam-fan__card:hover { box-shadow: 0 10px 24px rgba(0, 0, 0, .22); border-color: rgb(var(--c-primary-600, 37 99 235)); }
        .dam-fan__card:focus-visible { outline: 3px solid rgb(var(--c-primary-600, 37 99 235)); outline-offset: 2px; }
        .dam-fan__card[aria-selected="true"] { border-color: rgb(var(--c-primary-600, 37 99 235)); box-shadow: 0 0 0 2px rgb(var(--c-primary-600, 37 99 235)); }
        .dam-fan__selection { position: absolute; right: 8px; bottom: 8px; display: flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: rgb(var(--c-primary-600, 37 99 235)); color: white; font-size: 18px; font-weight: 700; box-shadow: 0 0 0 2px white; }
        .dam-fan.is-busy .dam-fan__card { pointer-events: none; }
        .dam-fan__thumb { position: relative; display: flex; align-items: center; justify-content: center; overflow: hidden; background: rgb(243 244 246); }
        .dark .dam-fan__thumb { background: rgb(17 24 39); }
        .dam-fan__thumb img { width: 100%; height: 100%; object-fit: contain; }
        .dam-fan__tile { display: inline-flex; align-items: center; gap: 6px; font-weight: 800; font-size: 20px; letter-spacing: .04em; color: #fff; padding: 6px 12px; border-radius: 8px; }
        .dam-fan__open-main { flex: none; display: inline-flex; align-items: center; height: 28px; padding: 0 10px; border-radius: 8px; font-size: 12px; font-weight: 700; color: #fff; background: rgb(var(--c-primary-600, 37 99 235)); }
        .dam-fan__open-main:hover { filter: brightness(.92); }
        .dam-fan__open-main:focus-visible { outline: 2px solid rgb(var(--c-primary-600, 37 99 235)); outline-offset: 2px; }
        .dam-fan__mark { width: 40px; height: 40px; border-radius: 8px; flex: none; }
        .dam-fan__chip-mark { width: 14px; height: 14px; border-radius: 3px; margin: 0 4px 0 -4px; vertical-align: -3px; }
        .dam-fan__chip { position: absolute; top: 6px; right: 6px; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; color: #fff; text-transform: uppercase; box-shadow: 0 1px 2px rgba(0, 0, 0, .2); }
        .dam-fan__cover { position: absolute; top: 6px; left: 6px; padding: 1px 6px; border-radius: 9999px; font-size: 10px; font-weight: 700; color: #fff; background: rgb(var(--c-primary-600, 37 99 235)); }
        .dam-fan__text { padding: 6px 8px 8px; min-width: 0; }
        .dam-fan__label { display: flex; align-items: flex-end; min-width: 0; font-size: 12px; font-weight: 600; line-height: 16px; color: rgb(31 41 55); }
        /* Two lines before the name is cut, and the cut keeps its ending (VISR-11: "Brochure PDF (6 pa…"). */
        .dam-fan__head { min-width: 0; overflow: hidden; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow-wrap: anywhere; }
        .dam-fan__tail { flex: none; white-space: pre; }
        /* A name that fits in two lines is shown whole: split, a short second head line leaves a gap before the tail. */
        .dam-fan__whole { min-width: 0; overflow: hidden; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow-wrap: anywhere; }
        .dam-fan__meta { display: block; font-size: 11px; line-height: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: rgb(107 114 128); }
        .dark .dam-fan__label { color: rgb(243 244 246); }
        .dark .dam-fan__meta { color: rgb(156 163 175); }
        .dam-fan__skeleton { background: linear-gradient(90deg, rgb(229 231 235) 0%, rgb(243 244 246) 50%, rgb(229 231 235) 100%); background-size: 200% 100%; animation: dam-fan-shimmer 1.2s linear infinite; }
        .dark .dam-fan__skeleton { background: rgb(55 65 81); }
        .dam-fan__more { align-items: center; justify-content: center; gap: 2px; font-weight: 700; color: rgb(var(--c-primary-600, 37 99 235)); background: rgb(239 246 255); border-style: dashed; }
        .dark .dam-fan__more { background: rgb(30 41 59); }
        .dam-fan__grid { position: absolute; left: 12px; right: 12px; bottom: 12px; overflow-y: auto; overscroll-behavior: contain; display: grid; gap: 12px; align-content: start; padding: 4px; }
        .dam-fan__grid .dam-fan__card { position: relative; transform: none !important; }
        /* Above every card (their z-index runs to 100), so the message and its button are never drawn under a tile. */
        .dam-fan__error { position: absolute; left: 12px; right: 12px; z-index: 110; padding: 8px 10px; border-radius: 8px; font-size: 12px; line-height: 16px; color: rgb(185 28 28); background: rgb(254 242 242); border: 1px solid rgb(254 202 202); }
        .dark .dam-fan__error { color: rgb(254 202 202); background: rgb(69 10 10); border-color: rgb(127 29 29); }
        .dam-fan__icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; color: rgb(75 85 99); }
        .dam-fan__icon-btn:hover { background: rgb(243 244 246); color: rgb(17 24 39); }
        .dark .dam-fan__icon-btn { color: rgb(209 213 219); }
        .dark .dam-fan__icon-btn:hover { background: rgb(55 65 81); color: #fff; }
        .dam-fan__icon-btn:focus-visible, .dam-fan__link:focus-visible { outline: 2px solid rgb(var(--c-primary-600, 37 99 235)); outline-offset: 2px; }
        .dam-stack-created { animation: dam-stack-created 2.4s ease-out; }
        @keyframes dam-stack-created { 0%, 40% { outline: 3px solid rgb(var(--c-primary-600, 37 99 235)); outline-offset: 3px; } 100% { outline: 3px solid rgb(var(--c-primary-600, 37 99 235) / 0); outline-offset: 3px; } }
        @keyframes dam-fan-shimmer { to { background-position: -200% 0; } }
        @media (prefers-reduced-motion: reduce) {
            .dam-fan__card, .dam-fan__panel, .dam-fan__caret, .dam-fan__anchor, .dam-fan__header { transition-duration: 0s !important; transition-delay: 0s !important; }
            .dam-fan__skeleton { animation: none; }
        }
    </style>

    <script type="text/x-template" id="v-dam-stack-fan-template">
        <teleport to="body">
            <div
                v-if="phase !== 'closed'"
                ref="root"
                class="dam-fan"
                :class="{ 'is-expanded': expanded, 'is-busy': phase !== 'open' }"
                role="dialog"
                :aria-modal="viewerOpen ? null : 'true'"
                :inert="viewerOpen"
                :aria-hidden="viewerOpen ? 'true' : null"
                :aria-label="title"
                data-dam-stack-fan
                :data-phase="phase"
                @keydown="onKeydown"
            >
                <div class="dam-fan__backdrop" @click="onBackdrop" @wheel.prevent @touchmove.prevent data-dam-stack-fan-backdrop></div>
                <div class="dam-fan__anchor" :style="anchorStyle" aria-hidden="true"></div>

                <div class="dam-fan__tray" :style="trayStyle">
                    <div class="dam-fan__panel" aria-hidden="true"></div>
                    <span v-if="layout.side" class="dam-fan__caret" :class="'is-' + layout.side" :style="caretStyle" aria-hidden="true"></span>

                    <div class="dam-fan__header" :class="{ 'is-narrow': layout.narrow }" :style="layout.narrow ? { height: (layout.headerH - 12) + 'px' } : null">
                        <div class="dam-fan__title min-w-0 flex-1">
                            <p class="flex min-w-0 text-sm font-bold text-gray-800 dark:text-white" :title="title" tabindex="-1" data-dam-stack-fan-heading><span class="min-w-0 truncate">@{{ nameParts(title).head }}</span><span class="shrink-0" style="white-space: pre;">@{{ nameParts(title).tail }}</span></p>
                            <p class="truncate text-[11px] text-gray-500 dark:text-gray-400" :title="subtitle" data-dam-stack-fan-subtitle>@{{ subtitle }}</p>
                        </div>
                        @if (bouncer()->hasPermission('dam.asset.view'))
                        <button
                            type="button"
                            class="dam-fan__open-main"
                            :title="t.openMainTitle"
                            data-dam-stack-fan-open-main
                            @click="openMain"
                        >@{{ t.openMain }}</button>
                        @endif
                        {{-- A button, not <a href="#">: ajax navigation takes over every admin link click before this handler runs. --}}
                        <button
                            v-if="onDetails"
                            type="button"
                            class="dam-fan__link shrink-0 rounded px-1 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"
                            data-dam-stack-fan-details
                            @click="openDetails"
                        >@lang('dam::app.admin.dam.asset.stack-fan.open-details')</button>
                        @if (bouncer()->hasPermission('dam.asset.files.index'))
                        <a :href="filesHref()" class="dam-fan__link shrink-0 px-1 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400" data-dam-stack-fan-files @click="onFilesClick">Files</a>
                        @endif
                        <button
                            type="button"
                            class="dam-fan__icon-btn dam-fan__close shrink-0"
                            :title="t.collapse"
                            :aria-label="t.collapse"
                            data-dam-stack-fan-close
                            @click="collapse()"
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>
                        </button>
                    </div>

                    <div v-if="defaultCandidate" class="absolute left-3 right-3 flex min-w-0 items-center gap-3 text-xs" :style="{ top: (layout.headerH - 40) + 'px', height: '32px' }" data-dam-stack-fan-selected-file>
                        <p class="flex min-w-0 flex-1 font-semibold" :title="defaultCandidate.file_name || defaultCandidate.label"><span class="min-w-0 truncate">@{{ nameParts(defaultCandidate.file_name || defaultCandidate.label).head }}</span><span class="shrink-0" style="white-space: pre;">@{{ nameParts(defaultCandidate.file_name || defaultCandidate.label).tail }}</span></p>
                        <span v-if="defaultCandidate.is_representative" class="shrink-0">@lang('dam::app.admin.dam.asset.files.representative')</span>
                        <button v-else-if="canPromote(defaultCandidate)" type="button" class="dam-fan__link shrink-0 font-semibold text-primary-600" :disabled="promoting" @click="promote(defaultCandidate)" data-dam-stack-fan-make-default>@lang('dam::app.admin.dam.asset.files.make-representative')</button>
                        @if (bouncer()->hasPermission('dam.asset.download'))
                            <v-dam-member-download :file="defaultCandidate" :key="'download-' + defaultCandidate.id"></v-dam-member-download>
                        @endif
                    </div>

                    <p v-if="error" role="alert" class="dam-fan__error" :style="{ top: (layout.headerH + 4) + 'px' }" data-dam-stack-fan-error>
                        @{{ error }}
                        <button v-if="! forbidden" type="button" class="ml-1 font-semibold underline" data-dam-stack-fan-retry @click="load(asset)">@lang('dam::app.admin.dam.asset.stack-fan.retry')</button>
                    </p>

                    <div
                        class="dam-fan__grid"
                        :style="gridStyle"
                        role="listbox"
                        :aria-label="t.listLabel"
                        aria-orientation="horizontal"
                        ref="list"
                    >
                        <button
                            v-for="(item, index) in visibleItems"
                            :key="item.key"
                            type="button"
                            role="option"
                            :aria-selected="item.file && Number(item.file.attachment_id) === Number(selectedAttachmentId)"
                            class="dam-fan__card"
                            :class="{ 'dam-fan__more': item.more, '!ring-2 !ring-primary-600': item.file && dropSlot === item.file.attachment_id, 'cursor-grab': canArrange(item.file) }"
                            :style="cardStyle(index)"
                            :tabindex="index === focusIndex ? 0 : -1"
                            :aria-label="item.aria"
                            :title="item.more ? '' : item.label"
                            :data-dam-stack-fan-item="item.more ? 'more' : (item.file ? item.file.id : 'loading')"
                            :draggable="canArrange(item.file)"
                            @dragstart="startDrag(item.file, $event)"
                            @dragover.prevent="dropSlot = canArrange(item.file) ? item.file.attachment_id : null"
                            @drop.prevent="dropOn(item.file)"
                            @dragend="draggingSlot = null; dropSlot = null"
                            @click="activate(index, $event)"
                            @focus="focusIndex = index"
                        >
                            <template v-if="item.more">
                                <span class="text-xl">@{{ item.label }}</span>
                                <span class="text-[11px] font-semibold">@{{ item.meta }}</span>
                            </template>
                            <template v-else>
                                <span class="dam-fan__thumb" :class="{ 'dam-fan__skeleton': ! item.file }" :style="{ height: layout.thumbH + 'px' }">
                                    <img v-if="item.thumb && ! failedThumbs[item.key]" :src="item.thumb" alt="" loading="lazy" decoding="async" draggable="false" @@error="failedThumbs[item.key] = true">
                                    <span v-else-if="item.file" class="dam-fan__tile" :style="{ background: item.color }" :title="item.adobe?.title || null"><img v-if="item.adobe" :src="adobeIcon(item.adobe)" alt="" class="dam-fan__mark" draggable="false"><svg v-if="item.model" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5 3.5 7v10L12 21.5 20.5 17V7z"></path><path d="M3.5 7 12 12l8.5-5M12 12v9.5"></path></svg>@{{ item.ext }}<span v-if="item.adobe" class="sr-only">@{{ item.adobe.app }}</span></span>
                                    <span v-if="item.file && item.thumb && ! failedThumbs[item.key]" class="dam-fan__chip" :style="{ background: item.color }" :title="item.adobe?.title || null"><img v-if="item.adobe" :src="adobeIcon(item.adobe)" alt="" class="dam-fan__chip-mark" draggable="false">@{{ item.ext }}<span v-if="item.adobe" class="sr-only">@{{ item.adobe.app }}</span></span>
                                    <span v-if="item.cover" class="dam-fan__cover">@lang('dam::app.admin.dam.asset.stack-fan.cover')</span>
                                    <span v-if="item.file && Number(item.file.attachment_id) === Number(selectedAttachmentId)" class="dam-fan__selection" aria-hidden="true" data-dam-stack-fan-selected>✓</span>
                                </span>
                                <span class="dam-fan__text">
                                    <span class="dam-fan__label" :class="{ 'dam-fan__skeleton rounded h-3 w-3/4': ! item.file }"><span v-if="! splitLabels[item.key]" :ref="(el) => measureLabel(item.key, el)" class="dam-fan__whole">@{{ item.label }}</span><template v-else><span class="dam-fan__head">@{{ nameParts(item.label).head }}</span><span class="dam-fan__tail">@{{ nameParts(item.label).tail }}</span></template></span>
                                    <span class="dam-fan__meta">@{{ item.meta }}</span>
                                </span>
                            </template>
                        </button>
                    </div>
                </div>

                <p class="sr-only" role="status" aria-live="polite">@{{ announcement }}</p>
            </div>
        </teleport>
    </script>

    <script type="module">
        app.component('v-dam-stack-fan', {
            template: '#v-dam-stack-fan-template',

            data() {
                return {
                    viewerOpen: false,
                    canEditDefault: false,
                    promoting: false,
                    draggingSlot: null,
                    dropSlot: null,
                    // closed → opening → open → closing → closed; `generation` voids timers from an earlier open; file loads carry their own token.
                    phase: 'closed',
                    expanded: false,
                    generation: 0,
                    asset: null,
                    anchorEl: null,
                    anchor: { left: 0, top: 0, width: 0, height: 0 },
                    spot: { left: 0, top: 0, width: 0, height: 0 },
                    siblings: [],
                    // Where that page sits in the whole result set, handed on so the details counter reads "n of total".
                    resultTotal: null,
                    resultOffset: null,
                    directoryId: null,
                    onDetails: null,
                    files: null,
                    error: '',
                    // The server refused the list (no dam.asset.files.index): no retry, the same answer would come back.
                    forbidden: false,
                    showAll: false,
                    focusIndex: 0,
                    lastVariantIndex: null,
                    selectedAttachmentId: null,
                    announcement: '',
                    viewport: { width: window.innerWidth, height: window.innerHeight },
                    cache: {},
                    failedThumbs: {},
                    splitLabels: {},
                    roleLabels: @js(trans('dam::app.admin.dam.asset.files.roles')),
                    modelExtensions: ['glb', 'gltf', '3mf', 'stl', 'obj', 'fbx', 'blend'],
                    bases: @js(trans('dam::app.admin.dam.asset.stack.bases')),
                    t: {
                        collapse: @js(trans('dam::app.admin.dam.asset.stack-fan.collapse')),
                        listLabel: @js(trans('dam::app.admin.dam.asset.stack-fan.list-label')),
                        filesCount: @js(trans('dam::app.admin.dam.asset.stack.files-count')),
                        more: @js(trans('dam::app.admin.dam.asset.stack-fan.more')),
                        showAll: @js(trans('dam::app.admin.dam.asset.stack-fan.show-all')),
                        loading: @js(trans('dam::app.admin.dam.asset.stack-fan.loading')),
                        loadFailed: @js(trans('dam::app.admin.dam.asset.stack-fan.load-failed')),
                        forbidden: @js(trans('dam::app.admin.dam.asset.stack-fan.forbidden')),
                        preview: @js(trans('dam::app.admin.dam.asset.stack-fan.preview-variant')),
                        cover: @js(trans('dam::app.admin.dam.asset.stack-fan.cover')),
                        openMain: @js(trans('dam::app.admin.dam.asset.stack-fan.open-main')),
                        openMainTitle: @js(trans('dam::app.admin.dam.asset.stack-fan.open-main-title')),
                        opened: @js(trans('dam::app.admin.dam.asset.stack-fan.opened')),
                        hint: @js(trans('dam::app.admin.dam.asset.stack-fan.keyboard-hint')),
                    },
                };
            },

            computed: {
                defaultCandidate() { return this.files?.find(file => Number(file.attachment_id) === Number(this.selectedAttachmentId)) || null; },
                reducedMotion() {
                    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
                },
                title() {
                    return this.asset ? (this.asset.display_name || this.asset.file_name || '') : '';
                },
                count() {
                    return this.files ? this.files.length : Math.max(2, Number(this.asset?.attachment_count || 0));
                },
                subtitle() {
                    const parts = [this.t.filesCount.replace(':count', () => this.count)];
                    if (this.asset?.stack_basis) parts.push(`${this.bases[this.asset.stack_basis] ?? this.asset.stack_basis} · ${this.asset.stack_key ?? ''}`);
                    parts.push(this.canEditDefault ? @js(trans('dam::app.admin.dam.asset.stack-fan.reorder-hint')) : this.t.hint);

                    return parts.join(' · ');
                },
                /** Every file as a card model; placeholders stand in (and fan out) while the list loads. */
                items() {
                    // A failed load stops the placeholders: the error stands alone instead of tiles that never fill.
                    if (! this.files && this.error) return [];
                    if (! this.files) {
                        return Array.from({ length: Math.min(this.count, 12) }, (_, index) => ({ key: `card-${index}`, file: null, label: '', meta: '', aria: this.t.loading }));
                    }

                    // Keys are positions, so the placeholder buttons (and the one holding focus) fill in place.
                    return this.files.map((file, index) => {
                        const ext = this.extension(file);
                        const cover = !! file.is_representative;
                        const role = cover ? this.t.cover : (this.roleLabels[file.role] || file.role || '');
                        const meta = [role, this.size(file.size)].filter(Boolean).join(' · ');
                        const label = file.label || file.file_name || '';

                        return {
                            key: `card-${index}`,
                            file,
                            cover,
                            ext: ext.toUpperCase(),
                            adobe: file.adobe || null,
                            color: this.color(file, ext),
                            model: this.modelExtensions.includes(ext),
                            label,
                            meta,
                            thumb: this.thumbnail(file, cover),
                            aria: this.t.preview.replace(':name', () => `${label}, ${ext.toUpperCase()}, ${meta}`),
                        };
                    });
                },
                layout() {
                    const M = 16, gap = 12, pad = 12;
                    const vw = this.viewport.width, vh = this.viewport.height;
                    const n = this.items.length;
                    const cardW = Math.max(118, Math.min(172, vw < 560 ? Math.floor((vw - 2 * M - 2 * pad - gap) / 2) : 172));
                    const thumbH = Math.round(cardW * 0.72);
                    const cardH = thumbH + 62;
                    const maxCols = Math.max(1, Math.min(6, Math.floor((vw - 2 * M - 2 * pad + gap) / (cardW + gap))));
                    const cols = Math.max(1, Math.min(n, maxCols));
                    const gridW = cols * cardW + (cols - 1) * gap;
                    const trayW = this.showAll
                        ? Math.min(vw - 2 * M, maxCols * (cardW + gap) - gap + 2 * pad + 8)
                        : Math.max(Math.min(320, vw - 2 * M), gridW + 2 * pad);
                    // One header row needs the title (at least 160px, so the name and its tail read) beside the buttons, measured
                    // at 12px bold in every locale (the strings are the same in all four): "Open main file" 106 + "Open details" 82
                    // + "Files" 35 + close 32 = 255px, plus four 8px gaps and the tray's 2 x 12px padding. 160 + 255 + 32 + 24 = 471.
                    // A tray narrower than that, or a phone, takes the two-row header.
                    const narrow = vw < 480 || trayW < 471;
                    const headerH = (narrow ? 84 : 52) + (this.defaultCandidate ? 44 : 0);
                    const maxRows = Math.max(1, Math.min(3, Math.floor((vh - 2 * M - 2 * pad - headerH + gap) / (cardH + gap))));
                    const capacity = maxCols * maxRows;
                    const overflow = n > capacity;
                    const slots = overflow ? capacity : n;
                    const rows = Math.max(1, Math.ceil(slots / cols));
                    // With no cards (the list failed), the tray only holds the header and the message.
                    const trayH = this.showAll
                        ? vh - 2 * M
                        : (n === 0 ? headerH + 56 : headerH + rows * cardH + (rows - 1) * gap + pad);
                    // Beside the stack when there is room, so its card stays in view: below, above, right, then left.
                    // Only when none fits does the tray sit over the card, its first row where the thumbnail was.
                    const a = this.spot.width ? this.spot : this.anchor, near = 12;
                    const clampX = (x) => Math.min(Math.max(M, x), vw - M - trayW);
                    const clampY = (y) => Math.min(Math.max(M, y), vh - M - trayH);
                    const centreX = clampX(a.left + a.width / 2 - trayW / 2);
                    const candidates = this.showAll ? [] : [
                        { side: 'top', left: centreX, top: a.top + a.height + near },
                        { side: 'bottom', left: centreX, top: a.top - near - trayH },
                        { side: 'left', left: a.left + a.width + near, top: clampY(a.top - headerH / 2) },
                        { side: 'right', left: a.left - near - trayW, top: clampY(a.top - headerH / 2) },
                    ];
                    const fits = (c) => c.left >= M && c.top >= M && c.left + trayW <= vw - M && c.top + trayH <= vh - M;
                    const place = candidates.find(fits) || { side: null, left: centreX, top: this.showAll ? M : clampY(a.top - headerH) };
                    const left = Math.round(place.left);
                    const top = Math.round(place.top);
                    // The caret on the tray's edge points at the stack it came from.
                    const caret = place.side === 'top' || place.side === 'bottom'
                        ? Math.min(Math.max(18, a.left + a.width / 2 - left), trayW - 18)
                        : Math.min(Math.max(18, a.top + Math.min(a.height, 120) / 2 - top), trayH - 18);

                    return { M, gap, pad, headerH, narrow, cardW, cardH, thumbH, cols, rows, overflow, shown: overflow ? capacity - 1 : n, gridW, trayW, trayH, left, top, side: place.side, caret };
                },
                visibleItems() {
                    return this.items;
                },
                trayStyle() {
                    const l = this.layout;

                    return { left: `${l.left}px`, top: `${l.top}px`, width: `${l.trayW}px`, height: `${l.trayH}px` };
                },
                gridStyle() {
                    return { top: `${this.layout.headerH}px`, gridTemplateColumns: `repeat(${this.layout.cols}, minmax(0, 1fr))` };
                },
                caretStyle() {
                    const along = `${this.layout.caret - 8}px`;

                    return this.layout.side === 'top' || this.layout.side === 'bottom' ? { left: along } : { top: along };
                },
                anchorStyle() {
                    const a = this.spot;

                    return { left: `${a.left}px`, top: `${a.top}px`, width: `${a.width}px`, height: `${a.height}px` };
                },
            },

            mounted() {
                this._onOpen = (payload = {}) => this.open(payload);
                this._onResize = () => { this.viewport = { width: window.innerWidth, height: window.innerHeight }; this.measureAnchor(); };
                this._onPreviewClosed = ({id} = {}) => {
                    this.viewerOpen = false;
                    if (Number(id) !== Number(this.asset?.id)) return;
                    if (this.phase === 'open' && this.lastVariantIndex !== null) this.$nextTick(() => this.focusCard(this.lastVariantIndex));
                };
                this._onFilesChanged = ({ assetId } = {}) => { delete this.cache[assetId]; };
                this._onMergeDone = ({ destinationId } = {}) => { delete this.cache[destinationId]; };
                // A 3D file just opened in the viewer was captured: its tile shows the picture without reloading the list.
                this._onFileThumbnail = ({ fileId, url } = {}) => {
                    for (const files of Object.values(this.cache)) {
                        for (const file of files) if (Number(file.id) === Number(fileId)) file.thumbnail_url = url;
                    }
                    for (const file of this.files || []) if (Number(file.id) === Number(fileId)) file.thumbnail_url = url;
                };
                // Escape folds the fan wherever focus is; a click on the tray's empty space leaves it on the page (LIB-18).
                this._onWindowKeydown = (event) => {
                    if (event.key !== 'Escape' || event.defaultPrevented || this.phase === 'closed' || this.phase === 'closing') return;
                    if (this.$refs.root?.contains(event.target) || document.querySelector('[data-dam-preview-backdrop]')) return;
                    event.preventDefault();
                    this.collapse();
                };
                window.addEventListener('keydown', this._onWindowKeydown);
                this.$emitter.on('dam:open-stack-fan', this._onOpen);
                this.$emitter.on('dam-preview-closed', this._onPreviewClosed);
                this._onPreviewLayer = ({open} = {}) => { this.viewerOpen = !! open; };
                this._onLeaveChooser = () => { this.collapse({instant: true}); };
                this.$emitter.on('dam-preview-layer', this._onPreviewLayer);
                this.$emitter.on('dam-preview-leave-chooser', this._onLeaveChooser);
                this.$emitter.on('dam-asset-files-updated', this._onFilesChanged);
                this.$emitter.on('dam:merge-done', this._onMergeDone);
                this.$emitter.on('dam:file-thumbnail-updated', this._onFileThumbnail);
                window.addEventListener('resize', this._onResize);
            },

            beforeUnmount() {
                window.removeEventListener('keydown', this._onWindowKeydown);
                this.$emitter.off('dam:open-stack-fan', this._onOpen);
                this.$emitter.off('dam-preview-closed', this._onPreviewClosed);
                this.$emitter.off('dam-preview-layer', this._onPreviewLayer);
                this.$emitter.off('dam-preview-leave-chooser', this._onLeaveChooser);
                this.$emitter.off('dam-asset-files-updated', this._onFilesChanged);
                this.$emitter.off('dam:merge-done', this._onMergeDone);
                this.$emitter.off('dam:file-thumbnail-updated', this._onFileThumbnail);
                window.removeEventListener('resize', this._onResize);
                // Nothing scheduled for this fan may run once it is gone (including a click queued while it opened).
                this.generation++;
                this._queuedMain = null;
                this.cancelScheduled();
            },

            methods: {
                canArrange(file) {
                    return this.canEditDefault && !this.promoting && file?.attachment_id && Number(file.asset_id) === Number(this.asset?.id);
                },
                startDrag(file, event) {
                    if (!this.canArrange(file)) { event.preventDefault(); return; }
                    this.draggingSlot = file.attachment_id;
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(file.attachment_id));
                },
                dropOn(file) {
                    const from = this.draggingSlot;
                    this.draggingSlot = null;
                    this.dropSlot = null;
                    if (this.canArrange(file) && from !== null) this.reorderSlots(from, file.attachment_id);
                },
                async reorderSlots(from, to) {
                    if (!this.canEditDefault || this.promoting || Number(from) === Number(to)) return;
                    const own = (this.files || []).filter(file => Number(file.asset_id) === Number(this.asset.id) && file.attachment_id);
                    const order = own.map(file => file.attachment_id);
                    const a = order.findIndex(id => Number(id) === Number(from));
                    const b = order.findIndex(id => Number(id) === Number(to));
                    if (a < 0 || b < 0) return;
                    order.splice(b, 0, ...order.splice(a, 1));
                    const assetId = this.asset.id;
                    this.promoting = true;
                    try {
                        const url = @json(route('admin.dam.assets.attachments.order', ['asset' => '__ASSET__'])).replace('__ASSET__', assetId);
                        const { data } = await this.$axios.put(url, { order, expected_files: own.map(file => file.id) });
                        this.cache[assetId] = data.files;
                        if (Number(this.asset?.id) === Number(assetId)) {
                            this.files = data.files;
                            Object.assign(this.asset, data.asset);
                            this.selectedAttachmentId = from;
                            this.focusIndex = this.files.findIndex(file => Number(file.attachment_id) === Number(from));
                            this.$nextTick(() => this.focusCard(this.focusIndex));
                            this.announcement = @js(trans('dam::app.admin.dam.asset.files.reordered'));
                        }
                        this.$emitter.emit('dam:stack-default-changed', { assetId, ...data });
                        this.$emitter.emit('dam-asset-files-updated', { assetId });
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || @js(trans('dam::app.admin.dam.asset.files.arrange-failed')) });
                        if (Number(this.asset?.id) === Number(assetId)) this.load(this.asset);
                    } finally { this.promoting = false; }
                },
                canPromote(file) {
                    return this.canEditDefault && file && Number(file.asset_id) === Number(this.asset?.id) && !! file.attachment_id && ! file.is_representative && ! ['proxy', 'thumbnail'].includes(file.role);
                },
                promote(file) {
                    if (! this.canPromote(file) || this.promoting) return;
                    const assetId = this.asset.id;
                    this.$emitter.emit('open-confirm-modal', {
                        zIndex: 10020,
                        title: @js(trans('dam::app.admin.dam.asset.files.make-representative-title')),
                        message: @js(trans('dam::app.admin.dam.asset.files.make-representative-confirm')).replace(':name', file.file_name || file.label || ''),
                        options: { btnAgree: @js(trans('dam::app.admin.dam.asset.files.make-representative-agree')), btnDisagree: @js(trans('dam::app.admin.dam.asset.files.cancel')), btnAgreeClass: 'primary-button', btnDisagreeClass: 'transparent-button' },
                        agree: () => Number(this.asset?.id) === Number(assetId) && this.phase === 'open' && this.promoteNow(file), disagree: () => {},
                    });
                },
                async promoteNow(file) {
                    if (! this.canPromote(file) || this.promoting) return;
                    const assetId = this.asset.id;
                    this.promoting = true;
                    try {
                        const url = @js(route('admin.dam.assets.attachments.promote', ['asset' => '__ASSET__', 'attachment' => '__SLOT__'])).replace('__ASSET__', assetId).replace('__SLOT__', file.attachment_id);
                        const { data } = await this.$axios.post(url);
                        this.cache[assetId] = data.files;
                        if (Number(this.asset?.id) === Number(assetId)) {
                            this.files = data.files;
                            Object.assign(this.asset, data.asset);
                            this.focusIndex = this.files.findIndex(member => Number(member.attachment_id) === Number(file.attachment_id));
                            this.lastVariantIndex = this.focusIndex;
                        }
                        this.$emitter.emit('dam:stack-default-changed', { assetId, ...data });
                        this.$emitter.emit('dam-asset-files-updated', { assetId });
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || @js(trans('dam::app.admin.dam.asset.files.arrange-failed')) });
                    } finally { this.promoting = false; }
                },
                /** Every timer and frame an opening or closing has pending; the generation check is the second line of defence. */
                cancelScheduled() {
                    clearTimeout(this._timer);
                    clearTimeout(this._expandTimer);
                    cancelAnimationFrame(this._frameOuter);
                    cancelAnimationFrame(this._frameInner);
                    this._timer = this._expandTimer = this._frameOuter = this._frameInner = null;
                },

                open({ asset, el, siblings = [], directoryId = null, total = null, offset = null, onDetails = null } = {}) {
                    if (! asset?.id) return;
                    const same = this.asset && Number(this.asset.id) === Number(asset.id);
                    // A second click on the same stack while it opens or is open changes nothing (no flicker).
                    if (same && (this.phase === 'opening' || this.phase === 'open')) return;

                    this.cancelScheduled();
                    this._queuedMain = null;
                    const generation = ++this.generation;
                    const reopening = same && this.phase === 'closing';

                    if (! reopening) {
                        this.asset = asset;
                        this.anchorEl = el || null;
                        this.siblings = siblings;
                        this.resultTotal = total;
                        this.resultOffset = offset;
                        this.directoryId = directoryId;
                        this.onDetails = onDetails;
                        this.files = this.cache[asset.id] || null;
                        this.canEditDefault = false;
                        this.error = '';
                        this.forbidden = false;
                        this.showAll = false;
                        this.failedThumbs = {};
                        this.splitLabels = {};
                        this.focusIndex = 0;
                        this.lastVariantIndex = null;
                        this.selectedAttachmentId = null;
                        this.returnFocus = document.activeElement;
                        this.viewport = { width: window.innerWidth, height: window.innerHeight };
                        this.measureAnchor();
                        this.expanded = false;
                        this.load(asset);
                    }

                    this.phase = 'opening';
                    // Render folded into the stack first, then expand so the transition runs. Frames stall in a
                    // hidden or throttled tab, so a timer stands in for them: whichever comes first expands it.
                    this.$nextTick(() => {
                        let started = false;
                        const expand = () => {
                            if (started || generation !== this.generation || ! this.$el?.isConnected) return;
                            started = true;
                            void this.$refs.root?.offsetWidth;
                            this.expanded = true;
                            this.focusCard(this.focusIndex);
                            this._timer = setTimeout(() => {
                                if (generation !== this.generation || ! this.$el?.isConnected) return;
                                this.phase = 'open';
                                this.announcement = this.t.opened.replace(':count', () => this.count).replace(':name', () => this.title);
                                // "Open main file" clicked while the fan was still opening runs now, not never.
                                if (this._queuedMain === generation) this.openMain();
                            }, this.settleMs());
                        };
                        this._frameOuter = requestAnimationFrame(() => { this._frameInner = requestAnimationFrame(expand); });
                        this._expandTimer = setTimeout(expand, 60);
                    });
                },

                /** How long the staggered fan takes to settle; clicks on cards are ignored until then. */
                settleMs() {
                    return this.reducedMotion ? 180 : 380 + this.stagger(this.visibleItems.length - 1) + 40;
                },

                stagger(index) {
                    return this.reducedMotion ? 0 : Math.min(index, 8) * 32;
                },

                collapse({ instant = false } = {}) {
                    if (this.phase === 'closed' || this.phase === 'closing') return;
                    this.cancelScheduled();
                    this._queuedMain = null;
                    const generation = ++this.generation;
                    // A file chosen a moment ago may still be loading its preview; it must not open after this.
                    this.$emitter.emit('dam-preview-cancel');
                    this.phase = 'closing';
                    this.showAll = false;
                    this.measureAnchor();
                    this.expanded = false;
                    const done = () => {
                        if (generation !== this.generation) return;
                        this.phase = 'closed';
                        const target = this.anchorEl?.isConnected ? this.anchorEl.querySelector('[data-dam-stack-open]') : null;
                        (target || (this.returnFocus?.isConnected ? this.returnFocus : null))?.focus?.({ preventScroll: true });
                    };
                    if (instant || this.reducedMotion) return done();
                    this._timer = setTimeout(done, 300 + this.stagger(this.visibleItems.length - 1));
                },

                onBackdrop() {
                    // The second click of a double-click lands here while the cards are still flying out.
                    if (this.phase === 'open') this.collapse();
                },

                async load(asset) {
                    // Only the newest request may apply: an older one for the same stack (closed and reopened,
                    // or retried) can answer last.
                    const token = this._loadToken = (this._loadToken || 0) + 1;
                    this.error = '';
                    this.forbidden = false;
                    try {
                        const url = @js(route('admin.dam.assets.files.index', ['asset' => '__ASSET__'])).replace('__ASSET__', asset.id);
                        const { data } = await this.$axios.get(url);
                        if (token !== this._loadToken) return;
                        this.canEditDefault = !! data.can_edit && data.can_edit_details !== false;
                        // The list also carries a capture group's sibling assets; a stack's own slots are what it holds.
                        const own = (data?.files || []).filter((file) => Number(file.asset_id) === Number(asset.id) && file.attachment_id && file.stack_member !== false);
                        this.cache[asset.id] = own;
                        if (Number(this.asset?.id) === Number(asset.id)) this.files = own;
                    } catch (error) {
                        if (token !== this._loadToken) return;
                        if (Number(this.asset?.id) !== Number(asset.id)) return;
                        this.forbidden = error.response?.status === 403;
                        this.error = this.forbidden ? this.t.forbidden : (error.response?.data?.message || this.t.loadFailed);
                    }
                },

                /** The thumbnail the cards fly out of and back into, and the whole card or row that stays lit. */
                measureAnchor() {
                    if (! this.anchorEl?.isConnected) return;
                    const rect = (el) => { const r = el.getBoundingClientRect(); return { left: r.left, top: r.top, width: r.width, height: r.height }; };
                    this.anchor = rect(this.anchorEl.querySelector('.image-card, [data-dam-stack-anchor]') || this.anchorEl);
                    this.spot = rect(this.anchorEl);
                },

                cardStyle(index) {
                    return { height: `${this.layout.cardH}px` };
                },

                activate(index, event = null) {
                    if (this.phase !== 'open') return;
                    const item = this.visibleItems[index];
                    if (! item) return;
                    if (! item.file) return;
                    this.lastVariantIndex = index;
                    this.selectedAttachmentId = item.file.attachment_id;
                    // The cover opens the stack's own preview (its playback copy, captions); any other file opens itself.
                    // The arrows then move through the Library around the stack, as from any card (LIB-03).
                    this.$emitter.emit('dam-open-preview', {
                        id: Number(this.asset.id),
                        siblings: this.librarySiblings(),
                        directoryId: this.directoryId,
                        total: this.resultTotal,
                        offset: this.resultOffset,
                        file: item.cover ? null : item.file,
                        variants: this.files,
                        origin: {kind: 'chooser', assetId: Number(this.asset.id), fileId: item.file.id,
                            trigger: event?.currentTarget || document.activeElement, scrollTop: window.scrollY || 0},
                    });
                },

                /** The stack's cover opens in the preview straight away; the fan folds away behind it. */
                openMain() {
                    // Still opening (about a second): remember the click for this opening and run it when the fan settles.
                    if (this.phase === 'opening' && this.asset) {
                        this._queuedMain = this.generation;
                        return;
                    }
                    this._queuedMain = null;
                    if (this.phase !== 'open' || ! this.asset) return;
                    const payload = { id: Number(this.asset.id), siblings: this.librarySiblings(), directoryId: this.directoryId, total: this.resultTotal, offset: this.resultOffset };
                    this.collapse({ instant: true });
                    this.$emitter.emit('dam-open-preview', payload);
                },

                /** The Library order the stack was opened from, so the preview's arrows step through it; the stack alone when none came. */
                librarySiblings() {
                    const ids = (this.siblings || []).map(Number).filter(Boolean);

                    return ids.includes(Number(this.asset.id)) ? ids : [Number(this.asset.id)];
                },

                openDetails() {
                    const handler = this.onDetails;
                    const item = this.visibleItems[this.focusIndex];
                    const navigation = window.MothershipDetailsNavigation;
                    if (item?.file && ! item.cover && navigation?.withFileContext) {
                        navigation.writeSiblings({
                            siblings: this.librarySiblings(), scopeLabel: 'chooser', directoryId: this.directoryId,
                            total: this.resultTotal, offset: this.resultOffset,
                        });
                        const url = navigation.withFileContext({
                            url: @js(route('admin.dam.assets.edit', '__ASSET__')).replace('__ASSET__', this.asset.id),
                            fileId: item.file.id, directoryId: this.directoryId, nav: true,
                        });
                        this.collapse({ instant: true });
                        this.$navigate(url);
                        return;
                    }
                    this.collapse({ instant: true });
                    handler?.();
                },

                filesHref() {
                    if (! this.asset?.id) return '';
                    const url = @js(route('admin.dam.assets.edit', '__ASSET__')).replace('__ASSET__', this.asset.id) + '?files';
                    const item = this.visibleItems[this.focusIndex];
                    const navigation = window.MothershipDetailsNavigation;
                    return navigation?.withFileContext ? navigation.withFileContext({
                        url, fileId: item?.file?.id ?? null, directoryId: this.directoryId, nav: true,
                    }) : url;
                },

                onFilesClick() {
                    window.MothershipDetailsNavigation?.writeSiblings({
                        siblings: this.librarySiblings(), scopeLabel: 'chooser', directoryId: this.directoryId,
                        total: this.resultTotal, offset: this.resultOffset,
                    });
                    this.collapse({instant: true});
                },

                focusCard(index) {
                    const cards = this.$refs.list?.querySelectorAll('.dam-fan__card');
                    if (! cards?.length) return;
                    const target = Math.max(0, Math.min(index, cards.length - 1));
                    this.focusIndex = target;
                    cards[target].focus({ preventScroll: true });
                    cards[target].scrollIntoView({block: 'nearest', inline: 'nearest'});
                },

                columnsNow() {
                    const cards = Array.from(this.$refs.list?.querySelectorAll('.dam-fan__card') || []);
                    const top = cards[0]?.offsetTop;

                    return Math.max(1, cards.filter((card) => card.offsetTop === top).length);
                },

                onKeydown(event) {
                    // The preview opened from here sits on top and owns its own keys.
                    if (this.viewerOpen || document.querySelector('[data-dam-preview-backdrop]')) return;
                    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName) || event.target?.isContentEditable) return;
                    const keys = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: this.columnsNow(), ArrowUp: -this.columnsNow() };

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        event.stopPropagation();
                        this.collapse();
                    } else if (keys[event.key] !== undefined && event.target.closest?.('.dam-fan__card')) {
                        event.preventDefault();
                        const target = this.visibleItems[this.focusIndex + keys[event.key]]?.file;
                        const current = this.visibleItems[this.focusIndex]?.file;
                        if (event.altKey && this.canArrange(current) && this.canArrange(target)) this.reorderSlots(current.attachment_id, target.attachment_id);
                        else this.focusCard(this.focusIndex + keys[event.key]);
                    } else if ((event.key === 'Home' || event.key === 'End') && event.target.closest?.('.dam-fan__card')) {
                        event.preventDefault();
                        this.focusCard(event.key === 'Home' ? 0 : this.visibleItems.length - 1);
                    } else if (event.key === 'Tab') {
                        this.trapFocus(event);
                    }
                },

                trapFocus(event) {
                    const focusable = Array.from(this.$refs.root?.querySelectorAll('a[href], button:not([disabled]):not([tabindex="-1"])') || []);
                    if (! focusable.length) return;
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                },

                adobeIcon(adobe) { return window.MothershipAdobe?.icon(adobe) || ''; },

                extension(file) {
                    // The server names each file's type (`glb`, `dng`), from its stored type and bytes (VISR-11).
                    if (file.extension) return String(file.extension).toLowerCase();
                    const name = String(file.label || file.file_name || '');
                    if (/\.[a-z0-9]{1,5}$/i.test(name)) return name.split('.').pop().toLowerCase();
                    const subtype = String(file.mime_type || '').split('/').pop().replace(/^x-/, '').split('+')[0];

                    return ({ plain: 'txt', jpeg: 'jpg', 'vnd.adobe.photoshop': 'psd', quicktime: 'mov', mpeg: 'mp3' })[subtype] || subtype.slice(0, 5) || '?';
                },

                color(file, ext) {
                    const mime = String(file.mime_type || '');
                    if (ext === 'pdf') return '#dc2626';
                    if (this.modelExtensions.includes(ext)) return '#0d9488';
                    if (mime.startsWith('video/') || mime.startsWith('audio/')) return '#4f46e5';
                    if (ext === 'svg') return '#7c3aed';
                    if (mime.startsWith('image/')) return '#059669';

                    return '#4b5563';
                },

                /**
                 * The Library card's thumbnail stands for the cover. Every other file shows the server's small cached
                 * copy (`thumbnail_url`, a raster or a DNG preview), never its original: a stack of drone photos would
                 * otherwise pull tens of megabytes to draw a row of tiles. An SVG is small enough to show itself.
                 */
                thumbnail(file, cover) {
                    if (cover && this.asset?.path) return this.asset.path;
                    if (file.thumbnail_url) return file.thumbnail_url;

                    return String(file.mime_type || '') === 'image/svg+xml' && Number(file.size || 0) < 2 * 1024 * 1024 ? file.url : null;
                },

                /** A long name keeps its ending, where one file of a stack differs from the next. */
                nameParts(name) {
                    return window.MothershipNameTail(name);
                },

                /** A whole name cut by its two-line clamp switches to head and tail, so the cut keeps its ending. */
                measureLabel(key, el) {
                    if (el && el.clientHeight > 0 && el.scrollHeight > el.clientHeight + 1) this.splitLabels[key] = true;
                },

                size(bytes) {
                    const value = Number(bytes || 0);
                    if (! value) return '';
                    if (value >= 1048576) return `${(value / 1048576).toFixed(1)} MB`;
                    if (value >= 1024) return `${Math.round(value / 1024)} KB`;

                    return `${value} B`;
                },
            },
        });
    </script>
@endPushOnce

<v-dam-stack-fan></v-dam-stack-fan>
