@include('dam::components.explorer.stack-line')
@include('dam::components.shared.adobe-identity')
@once('v-dam-explorer-asset-card')
@include('dam::components.shared.lazy-thumbnail')
@include('dam::components.shared.custom-thumbnail-drop')
@push('scripts')
@include('dam::components.shared.asset-favorites')
<script type="text/x-template" id="v-dam-explorer-asset-card-template">
    <div
        class="relative group rounded-lg border border-gray-300 dark:border-cherry-600 bg-white dark:bg-cherry-900 overflow-hidden transition-colors cursor-pointer"
        v-dam-custom-thumbnail-drop="asset"
        :class="{ 'outline outline-[3px] -outline-offset-1 outline-primary-600 dark:outline-primary-400 !bg-primary-50 dark:!bg-primary-900/40': isSelected, 'ring-2 ring-inset ring-primary-400 bg-primary-200 dark:bg-primary-900/60': isDropTarget, 'ring-1 ring-inset ring-primary-300': dragHint && ! isDropTarget }"
        :style="isStack ? 'box-shadow:0 1px 3px rgba(0,0,0,.08), 4px -4px 0 -1px rgb(var(--c-primary-100, 219 234 254)), 4px -4px 0 0 rgb(var(--c-primary-300, 147 197 253)), 8px -8px 0 -1px rgb(var(--c-primary-50, 239 246 255)), 8px -8px 0 0 rgb(var(--c-primary-200, 191 219 254));' : 'box-shadow:0 1px 3px rgba(0,0,0,.08);'"
        data-dam-asset-open
        :data-dam-asset-id="asset.id"
        :data-dam-stack="isStack ? attachmentCount : null"
        draggable="true"
        @dragstart="onDragStart"
        @dragend="onDragEnd"
        @if (bouncer()->hasPermission('dam.asset.edit'))
            @dragover="onStackDragOver"
            @dragleave="onStackDragLeave"
            @drop="onStackDrop"
        @endif
        @if (bouncer()->hasPermission('dam.asset.view'))
            @click="openAsset('preview')"
        @elseif (bouncer()->hasPermission('dam.asset.edit'))
            @click="openAsset('edit')"
        @endif
        @contextmenu.prevent.stop="$emit('ctx', { event: $event, asset })"
    >
        <div class="image-card relative overflow-hidden">
            {{-- While a file is being dragged, every stack says it takes drops; the one under the pointer says it louder. --}}
            <span
                v-if="isStack && (isDropTarget || dragHint)"
                class="pointer-events-none absolute inset-x-0 bottom-0 flex items-center justify-center px-2 py-1.5 text-center text-xs font-semibold"
                :class="isDropTarget ? 'bg-primary-600 text-white' : 'bg-white/90 text-primary-700 dark:bg-cherry-900/90 dark:text-primary-200'"
                style="z-index:3"
                data-dam-stack-drop-hint
            >@lang('dam::app.admin.dam.asset.merge.drop-hint')</span>
            <img
                :key="thumbnailSrc"
                v-dam-thumbnail="thumbnailSrc"
                decoding="async"
                :alt="(asset.file_name || asset.display_name)"
                class="w-full h-full object-cover object-center"
                draggable="false"
                @@error="onImgErr"
            />

            <!-- The keyboard's way in: one real button over the thumbnail, so Tab reaches the card and Enter or Space opens it like a click. A stack opens its main file here too; its count button below opens its files. -->
            @if (bouncer()->hasPermission('dam.asset.view'))
            <button
                type="button"
                class="absolute inset-0 h-full w-full cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-primary-600"
                style="z-index:1"
                :aria-label="previewLabel"
                data-dam-asset-open-button
            ></button>
            @elseif (bouncer()->hasPermission('dam.asset.edit'))
            <button
                type="button"
                class="absolute inset-0 h-full w-full cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-primary-600"
                style="z-index:1"
                :aria-label="editLabel"
                data-dam-asset-open-button
            ></button>
            @endif

            <div
                v-if="asset.extension || versionChips.length || asset.match?.page || asset.match?.ai"
                class="absolute top-1.5 right-1.5 flex flex-col items-end gap-0.5 pointer-events-none"
                style="z-index:2;"
            >
                <span
                    v-if="asset.extension"
                    class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase text-white shadow"
                    :title="asset.adobe?.title || null"
                    :class="{
                        'inline-flex items-center gap-1 !pl-0.5': asset.adobe,
                        'bg-primary-600': asset.file_type==='video'||asset.file_type==='audio',
                        'bg-red-600':    (asset.extension||'').toLowerCase()==='pdf',
                        'bg-teal-600':   ['glb','3mf','stl'].includes((asset.extension||'').toLowerCase()),
                        'bg-gray-600':   asset.file_type!=='video'&&asset.file_type!=='audio'&&!['pdf','glb','3mf','stl'].includes((asset.extension||'').toLowerCase()),
                    }"
                ><img v-if="asset.adobe" :src="adobeIcon(asset.adobe)" alt="" class="h-3.5 w-3.5 rounded-[3px]" draggable="false">@{{ (asset.extension||'').toUpperCase() }}<span v-if="asset.adobe" class="sr-only">@{{ asset.adobe.app }}</span></span>
                <span v-if="asset.match?.page" class="pointer-events-auto rounded bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 text-[10px] font-medium text-gray-600 dark:text-gray-300" :title="asset.match.snippet || ''" data-dam-match-page>@{{ matchPageLabel(asset) }}</span>
                <span v-else-if="asset.match?.ai" class="pointer-events-auto rounded bg-violet-100 dark:bg-violet-900/40 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700 dark:text-violet-200" title="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.ai-badge-hint') }}" data-dam-match-ai>@lang('dam::app.admin.dam.asset.edit.preview-modal.ai-badge')</span>
                <!-- Other versions attached under Related files: OPT, SVG, then other formats (AvailableVersions). -->
                <span
                    v-for="chip in versionChips"
                    :key="chip.kind + chip.label"
                    class="px-1 rounded font-bold uppercase text-white shadow pointer-events-auto"
                    style="font-size:9px;line-height:14px;"
                    :style="{ background: chipColor(chip) }"
                    :title="chipTitle(chip)"
                    data-dam-version-chip
                >@{{ chip.label }}</span>
            </div>

            <span
                v-if="asset.asset_kind === 'pano360'"
                class="absolute top-1.5 left-1.5 px-2 py-0.5 rounded-full bg-primary-600 text-[10px] font-bold text-white shadow"
                style="z-index:2;"
            >@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.grid-badge')</span>

            <button type="button" class="absolute top-1.5 left-1.5 w-7 h-7 rounded-full bg-black/60 text-white hover:bg-primary-600 max-[639px]:after:absolute max-[639px]:after:-inset-2.5 max-[639px]:after:content-['']" style="z-index:3" :aria-label="favorite ? 'Remove from favorites' : 'Add to favorites'" @click.stop="toggleFavorite">@{{ favorite ? '★' : '☆' }}</button>

            <!-- A stack opens into its files (D5) from its count. The card itself opens the main file, as every other card does. -->
            @if ((bouncer()->hasPermission('dam.asset.view') || bouncer()->hasPermission('dam.asset.edit')) && bouncer()->hasPermission('dam.asset.files.index'))
            <button
                v-if="isStack"
                type="button"
                class="absolute bottom-1.5 left-1.5 inline-flex items-center gap-1 rounded-full bg-black/70 px-2 py-0.5 text-[11px] font-semibold text-white shadow hover:bg-primary-600 max-[639px]:after:absolute max-[639px]:after:-inset-x-1 max-[639px]:after:-inset-y-3 max-[639px]:after:content-[''] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                style="z-index:3"
                :aria-label="stackOpenLabel"
                :title="stackOpenLabel"
                data-dam-stack-open
                @click.stop="openStack"
            >
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="3" width="13" height="13" rx="2"></rect><path d="M16 21H5a2 2 0 0 1-2-2V8"></path></svg>
                @{{ attachmentCount }}
            </button>
            @else
            <!-- R2: a role that may not list a stack's files still sees that the entry is one, read-only. -->
            <span
                v-if="isStack"
                class="absolute bottom-1.5 left-1.5 inline-flex items-center gap-1 rounded-full bg-black/70 px-2 py-0.5 text-[11px] font-semibold text-white shadow pointer-events-none"
                style="z-index:3"
                :title="stackIndicatorLabel"
                :aria-label="stackIndicatorLabel"
                role="img"
                data-dam-stack-indicator
            >
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="3" width="13" height="13" rx="2"></rect><path d="M16 21H5a2 2 0 0 1-2-2V8"></path></svg>
                @{{ attachmentCount }}
            </span>
            @endif

            <div
                v-if="asset.file_type==='video'||asset.file_type==='audio'"
                class="absolute inset-0 flex items-center justify-center pointer-events-none"
            >
                <span
                    class="flex items-center justify-center w-10 h-10 rounded-full bg-black/55 text-white text-xl shadow-lg"
                    :class="asset.file_type==='video' ? 'icon-play' : 'icon-information'"
                    aria-hidden="true"
                ></span>
            </div>


        </div>

        <div class="flex gap-1.5 items-center py-1.5 pl-1">
            {{-- The checkbox takes the row's padding around it, so a near miss selects instead of opening the asset. --}}
            <span
                class="z-10 -my-1.5 -ml-1 self-stretch transition-opacity"
                :class="{ '!opacity-100': anySelected || isSelected }"
                @click.stop
            >
                <label :for="`sel-card-asset-${asset.id}`" class="flex h-full items-center py-1.5 pl-1 pr-1 cursor-pointer">
                    <input
                        type="checkbox"
                        class="peer sr-only"
                        :aria-label="'{{ __('admin::app.components.datagrid.table.select-row') }}'.replace(':name', () => asset.display_name || asset.file_name)"
                        :id="`sel-card-asset-${asset.id}`"
                        :checked="isSelected"
                        @change="$emit('toggle-select')"
                    >
                    <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 peer-checked:icon-checkbox-check peer-checked:text-primary-700 rounded-md text-2xl"></span>
                </label>
            </span>
            <p class="flex-1 min-w-0 text-xs text-gray-600 dark:text-gray-300 truncate"><bdi>@{{ (asset.display_name || asset.file_name) }}</bdi></p>
            @include('dam::components.explorer.access-indicator', ['expression' => 'asset.access_mode'])

            <button
                type="button"
                class="dam-ctx-trigger relative shrink-0 ltr:mr-1 rtl:ml-1 w-6 h-6 max-[639px]:after:absolute max-[639px]:after:-inset-2.5 max-[639px]:after:content-[''] flex items-center justify-center rounded-md text-gray-400 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-cherry-800 hover:text-primary-700 dark:hover:text-primary-400 transition-opacity"
                :class="{ '!opacity-100': isSelected }"
                :title="'@lang('dam::app.admin.explorer.list.header.actions')'"
                :aria-label="actionsLabel"
                aria-haspopup="menu"
                @click.stop="$emit('ctx', { event: $event, asset })"
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <circle cx="12" cy="5" r="2"></circle>
                    <circle cx="12" cy="12" r="2"></circle>
                    <circle cx="12" cy="19" r="2"></circle>
                </svg>
            </button>
        </div>

        <!-- A stack's basis and key, and how many files it holds (D5); a single plain file shows nothing. -->
        <p
            v-if="stackLine"
            class="px-2 pb-1 -mt-0.5 text-[11px] leading-tight font-medium text-primary-700 dark:text-primary-300 truncate"
            :title="stackLine"
            data-dam-stack-line
        >@{{ stackLine }}</p>

        <!-- P4a (D9): up to five custom fields chosen for cards, in priority order: label, then value. -->
        <dl
            v-if="asset.card_fields?.length"
            class="px-2 pb-1.5 -mt-0.5 grid gap-y-0.5"
            data-dam-card-fields
        >
            <div
                v-for="field in asset.card_fields"
                :key="field.code"
                class="flex min-w-0 items-baseline gap-1 text-[11px] leading-tight"
                :title="`${field.label}: ${field.value}`"
                :data-dam-card-field="field.code"
            >
                <dt class="shrink-0 max-w-[45%] truncate text-gray-400 dark:text-gray-500">@{{ field.label }}</dt>
                <dd class="min-w-0 flex-1 truncate font-medium text-gray-700 dark:text-gray-200">@{{ field.value }}</dd>
            </div>
        </dl>

        <!-- P2 S3: why the file is not out for use, shown only to those who still see it. -->
        <p
            v-if="asset.unavailable_label"
            class="px-2 pb-1 -mt-0.5"
        >
            <span
                class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4 bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200"
                :data-dam-availability-badge="asset.unavailable"
            >@{{ asset.unavailable_label }}</span>
        </p>

        <!-- P6 D26: a video whose 1080p playback copy is queued, being made or failed. -->
        <p
            v-if="asset.proxy_status_label"
            class="px-2 pb-1 -mt-0.5"
        >
            <span
                class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4"
                :class="asset.proxy_status === 'failed' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200'"
                :data-dam-proxy-badge="asset.proxy_status"
            >@{{ asset.proxy_status_label }}</span>
        </p>

        <!-- The uploader, from created_by; a file with no owner shows nothing. -->
        <p
            v-if="asset.owner_name"
            class="px-2 pb-1.5 -mt-0.5 text-[11px] leading-tight text-gray-400 dark:text-gray-500 truncate"
            :title="`@lang('dam::app.admin.explorer.uploaded-by') ${asset.owner_name}`"
            data-dam-asset-owner
        >@{{ asset.owner_name }}</p>
    </div>
</script>

<script type="module">
app.component('v-dam-explorer-asset-card', {
    template: '#v-dam-explorer-asset-card-template',
    emits: ['preview', 'edit', 'delete', 'ctx', 'toggle-select', 'refresh', 'open-stack'],

    props: {
        asset: { type: Object, required: true },
        tabId: { type: String, required: true },
        isSelected: { type: Boolean, default: false },
        anySelected: { type: Boolean, default: false },
        selection: { type: Array, default: () => [] },
    },

    data() {
        return {
            favorite: false,
            isDropTarget: false,
            dragHint: false,
            thumbnailOverride: null,
            placeholders: {
                video:       '{{ mothership_asset('images/grid/video.svg', 'dam') }}',
                audio:       '{{ mothership_asset('images/grid/audio.svg', 'dam') }}',
                pdf:         '{{ mothership_asset('images/grid/file.svg', 'dam') }}',
                spreadsheet: '{{ mothership_asset('images/grid/sheet.svg', 'dam') }}',
                csv:         '{{ mothership_asset('images/grid/csv.svg', 'dam') }}',
                document:    '{{ mothership_asset('images/grid/file.svg', 'dam') }}',
                image:       '{{ mothership_asset('images/grid/image.svg', 'dam') }}',
            },
            fallback: '{{ mothership_asset('images/grid/unspecified.svg', 'dam') }}',
        };
    },

    computed: {
        thumbnailSrc() { return this.thumbnailOverride || this.asset.path; },
        attachmentCount() { return Number(this.asset.attachment_count || 0); },
        /** More than one file a person stacked: the card carries a count that opens into its files. */
        isStack() { return this.attachmentCount > 1; },
        assetName() { return this.asset.display_name || this.asset.file_name; },
        previewLabel() { return @js(trans('dam::app.admin.explorer.a11y.preview-file')).replace(':name', () => this.assetName); },
        editLabel() { return @js(trans('dam::app.admin.explorer.a11y.edit-file')).replace(':name', () => this.assetName); },
        actionsLabel() { return @js(trans('dam::app.admin.explorer.a11y.actions-for')).replace(':name', () => this.assetName); },
        stackIndicatorLabel() {
            return @js(trans('dam::app.admin.dam.asset.stack-fan.stack-indicator')).replace(':count', () => this.attachmentCount);
        },
        stackOpenLabel() {
            return @js(trans('dam::app.admin.dam.asset.stack-fan.open')).replace(':count', () => this.attachmentCount);
        },
        stackLine() {
            return window.MothershipStackLine ? window.MothershipStackLine(this.asset) : '';
        },
        /** At most three chips; the rest fold into one "+N" chip whose title names them. */
        versionChips() {
            const chips = Array.isArray(this.asset.versions) ? this.asset.versions : [];
            if (chips.length <= 3) return chips;
            const rest = chips.slice(2);
            return [...chips.slice(0, 2), { kind: 'more', label: `+${rest.length}`, rest: rest.map(c => c.label).join(', ') }];
        },
    },

    watch: {
        'asset.id'() { this.thumbnailOverride = null; },
    },

    async mounted() {
        this.$emitter.on('dam:thumbnail-updated', this.onThumbnailUpdated);
        this.$emitter.on('dam:asset-drag-state', this.onAssetDragState);
        try { this.favorite = await window.MothershipFavorites.has(this.asset.id); } catch (error) { this.$emitter.emit('add-flash',{type:'error',message:error.message}); }
    },

    beforeUnmount() {
        this.$emitter.off('dam:thumbnail-updated', this.onThumbnailUpdated);
        this.$emitter.off('dam:asset-drag-state', this.onAssetDragState);
    },

    methods: {
        adobeIcon(adobe) { return window.MothershipAdobe?.icon(adobe) || ''; },
        // Server-rendered label with its :page placeholder filled in; a function replacement keeps `$&` in a value literal.
        matchPageLabel(asset) {
            return "@lang('dam::app.admin.explorer.match-page')".replace(':page', () => asset?.match?.page);
        },
        /**
         * A thumbnail stored from this browser (a video frame, or a 3D model
         * captured client-side per ADR 0006) swaps the card image immediately;
         * :key on the <img> rebuilds the element so a previous load error's
         * fallback styling goes with it.
         */
        onThumbnailUpdated({ id, url } = {}) {
            if (Number(id) === Number(this.asset.id) && url) this.thumbnailOverride = url;
        },
        chipColor(chip) {
            return { optimized: '#059669', svg: '#7c3aed' }[chip.kind] ?? 'rgba(17,24,39,.75)';
        },
        chipTitle(chip) {
            if (chip.kind === 'optimized') return "@lang('dam::app.admin.dam.asset.files.version-chip.optimized')";
            if (chip.kind === 'svg') return "@lang('dam::app.admin.dam.asset.files.version-chip.svg')";
            if (chip.kind === 'more') return `${"@lang('dam::app.admin.dam.asset.files.versions-available')"}: ${chip.rest}`;
            return "@lang('dam::app.admin.dam.asset.files.version-chip.type')".replace(':type', chip.label);
        },
        async toggleFavorite() { try { this.favorite = await window.MothershipFavorites.toggle(this.asset.id); } catch (error) { this.$emitter.emit('add-flash',{type:'error',message:error.message}); } },
        openAsset(mode) {
            this.$emit(mode, this.asset.id);
        },
        openStack() {
            this.$emit('open-stack', { asset: this.asset, el: this.$el });
        },
        onImgErr(e) {
            e.target.src = this.placeholders[this.asset.file_type] ?? this.fallback;
            e.target.className = 'w-full h-full object-contain p-4';
        },
        /** Only a stack accepts a dropped asset (OS files and a card's own drag are left to their handlers). */
        onStackDragOver(e) {
            if (! this.isStack || this._dragging) return;
            const types = Array.from(e.dataTransfer?.types || []);
            if (types.includes('Files') || ! types.includes('application/json')) return;
            e.preventDefault();
            this.isDropTarget = true;
        },
        onStackDragLeave(e) {
            if (e.relatedTarget && this.$el.contains(e.relatedTarget)) return;
            this.isDropTarget = false;
        },
        onStackDrop(e) {
            this.isDropTarget = false;
            if (! this.isStack || this._dragging) return;
            const payload = window.MothershipFileOps.parseDropPayload(e.dataTransfer);
            if (payload?.type !== 'dam-asset') return;
            e.preventDefault();
            e.stopPropagation();
            this.$emitter.emit('dam:asset-drag-state', { active: false });
            this.$emit('stack-drop', { payload, asset: this.asset });
        },
        /** Any file dragged in the Library (a card or a list row) lights the "drop to add" hint on every stack but its own card. */
        onAssetDragState({ active } = {}) {
            this.dragHint = !! active && ! this._dragging && this.isStack;
        },
        onDragStart(e) {
            this._dragging = true;
            e.dataTransfer.setData('application/json', JSON.stringify({
                type:  'dam-asset',
                id:    this.asset.id,
                name:  (this.asset.display_name || this.asset.file_name),
                tabId: this.tabId,
                ...window.MothershipFileOps.selectionDragFields({ id: this.asset.id, type: 'asset', selection: this.selection }),
            }));

            const size = 96;
            const thumb = this.$el.querySelector('.image-card') ?? this.$el;
            const clone = thumb.cloneNode(true);
            const left = Math.min(Math.max(0, e.clientX - size / 2), window.innerWidth - size);
            const top = Math.min(Math.max(0, e.clientY - size / 2), window.innerHeight - size);
            clone.style.cssText = `position:fixed;top:${top}px;left:${left}px;pointer-events:none;width:${size}px;height:${size}px;overflow:hidden;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.12);`;
            document.body.appendChild(clone);
            e.dataTransfer.setDragImage(clone, size / 2, size / 2);
            setTimeout(() => clone.remove(), 0);
            requestAnimationFrame(() => { this.$el.style.opacity = '0.4'; });
            // After the drag image is cloned, so the hint is not in it.
            setTimeout(() => this.$emitter.emit('dam:asset-drag-state', { active: true }), 0);
        },
        onDragEnd(e) {
            this._dragging = false;
            this.$emitter.emit('dam:asset-drag-state', { active: false });
            e.currentTarget.style.opacity = '';
        },
    },
});
</script>
@endpush
@endonce
