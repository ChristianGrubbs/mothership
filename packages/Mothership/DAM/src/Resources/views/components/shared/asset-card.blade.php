@once('v-dam-asset-card')
@include('dam::components.shared.lazy-thumbnail')
@push('scripts')
@include('dam::components.shared.asset-favorites')
<script type="text/x-template" id="v-dam-asset-card-template">
    <div
        class="image-card relative overflow-hidden rounded-lg border border-gray-200 dark:border-cherry-700 bg-white dark:bg-cherry-900 transition-colors group{{ bouncer()->hasPermission('dam.asset.view') ? ' cursor-pointer' : '' }}"
        :draggable="draggable"
        @if (bouncer()->hasPermission('dam.asset.edit'))
        @click="$emit('edit')"
        @elseif (bouncer()->hasPermission('dam.asset.view'))
        @click="$emit('preview')"
        @endif
        @dragstart="draggable && $emit('dragstart', $event)"
        @dragend="draggable && $emit('dragend', $event)"
        @contextmenu.prevent.stop="$emit('contextmenu', $event)"
    >
        <img
            v-dam-thumbnail="assetSrc"
            decoding="async"
            :alt="asset.display_name || asset.file_name"
            class="w-full h-full"
            :class="asset.file_type === 'image' ? 'object-cover object-center' : 'object-contain p-4 sm:p-6'"
            v-on:error="onImgErr($event)"
        />

        <span
            v-if="asset.extension"
            class="absolute top-1.5 right-1.5 z-10 px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide text-white shadow-md"
            :class="badgeClass"
            v-text="(asset.extension || '').toUpperCase()"
        ></span>

        <button type="button" class="absolute top-1.5 left-1.5 z-20 w-7 h-7 rounded-full bg-black/60 text-white hover:bg-primary-600" :aria-label="favorite ? 'Remove from favorites' : 'Add to favorites'" :title="favorite ? 'Remove from favorites' : 'Add to favorites'" @click.stop="toggleFavorite">@{{ favorite ? '★' : '☆' }}</button>

        <span
            v-if="asset.is_panorama"
            class="absolute top-1.5 left-1.5 z-10 px-2 py-0.5 rounded-full bg-primary-600 text-[10px] font-bold text-white shadow-md"
        >@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.grid-badge')</span>

        <!-- P2 S3: why the file is not out for use, shown only to those who still see it. -->
        <span
            v-if="asset.unavailable_label"
            class="absolute bottom-1.5 left-1.5 z-10 rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4 bg-amber-100 text-amber-800 shadow-md dark:bg-amber-900 dark:text-amber-200"
            :data-dam-availability-badge="asset.unavailable"
            v-text="asset.unavailable_label"
        ></span>

        <!-- P6 D26: a video whose 1080p playback copy is queued, being made or failed. -->
        <span
            v-if="asset.proxy_status_label"
            class="absolute bottom-1.5 right-1.5 z-10 rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4 shadow-md"
            :class="asset.proxy_status === 'failed' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200'"
            :data-dam-proxy-badge="asset.proxy_status"
            v-text="asset.proxy_status_label"
        ></span>

        <div
            v-if="asset.file_type === 'video' || asset.file_type === 'audio'"
            class="absolute inset-0 flex items-center justify-center pointer-events-none"
        >
            <span
                class="flex items-center justify-center w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-black/55 text-white text-xl sm:text-2xl shadow-lg"
                :class="asset.file_type === 'video' ? 'icon-play' : 'icon-information'"
                aria-hidden="true"
            ></span>
        </div>


    </div>
</script>

<script type="module">
app.component('v-dam-asset-card', {
    template: '#v-dam-asset-card-template',
    emits: ['preview', 'edit', 'delete', 'dragstart', 'dragend', 'contextmenu'],

    props: {
        asset:     { type: Object, required: true },
        draggable: { type: Boolean, default: false },
    },

    data() {
        return {
            favorite: false,
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

    async mounted() { try { this.favorite = await window.MothershipFavorites.has(this.asset.id); } catch (error) { this.$emitter?.emit('add-flash',{type:'error',message:error.message}); } },

    computed: {
        assetSrc() {
            return this.asset.path || this.placeholders[this.asset.file_type] || this.fallback;
        },

        badgeClass() {
            const ext = (this.asset.extension || '').toLowerCase();
            if (ext === 'pdf') return 'bg-red-600';
            if (['glb', '3mf', 'stl'].includes(ext)) return 'bg-teal-600';
            if (this.asset.file_type === 'video' || this.asset.file_type === 'audio') return 'bg-primary-600';
            return 'bg-gray-600';
        },
    },

    methods: {
        async toggleFavorite() { try { this.favorite = await window.MothershipFavorites.toggle(this.asset.id); } catch (error) { this.$emitter?.emit('add-flash',{type:'error',message:error.message}); } },
        onImgErr(event) {
            event.target.src = this.placeholders[this.asset.file_type] ?? this.fallback;
            event.target.classList.remove('object-cover', 'object-center');
            event.target.classList.add('object-contain', 'p-4');
        },
    },
});
</script>
@endpush
@endonce
