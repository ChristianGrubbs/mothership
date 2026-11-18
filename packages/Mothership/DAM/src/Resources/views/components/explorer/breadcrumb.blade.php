@once('v-dam-explorer-breadcrumb')
@push('scripts')
<script type="text/x-template" id="v-dam-explorer-breadcrumb-template">
    <div class="flex items-center gap-2 flex-1 min-w-[min(100%,15rem)]">

        <template v-if="loading">
            <div class="flex items-center gap-0.5 shrink-0">
                <div class="shimmer w-7 h-7 rounded-md"></div>
                <div class="shimmer w-7 h-7 rounded-md"></div>
            </div>
            <div class="flex items-center gap-1 flex-1">
                <div class="shimmer h-5 w-16 rounded"></div>
                <div class="shimmer h-5 w-24 rounded"></div>
                <div class="shimmer h-5 w-32 rounded"></div>
            </div>
        </template>

        <template v-else>
        <div class="flex items-center gap-0.5 shrink-0">
            <button
                type="button"
                class="w-7 h-7 flex items-center justify-center rounded-md transition-colors"
                :class="canGoBack ? 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer' : 'text-gray-300 dark:text-gray-600 cursor-not-allowed'"
                :disabled="!canGoBack"
                @click="$emit('back')"
                title="@lang('dam::app.admin.explorer.toolbar.back')"
                aria-label="@lang('dam::app.admin.explorer.toolbar.back')"
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
            </button>
            <button
                type="button"
                class="w-7 h-7 flex items-center justify-center rounded-md transition-colors"
                :class="canGoForward ? 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer' : 'text-gray-300 dark:text-gray-600 cursor-not-allowed'"
                :disabled="!canGoForward"
                @click="$emit('forward')"
                title="@lang('dam::app.admin.explorer.toolbar.forward')"
                aria-label="@lang('dam::app.admin.explorer.toolbar.forward')"
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
            </button>
        </div>

        {{-- VIS-10: a trail of more than three levels keeps the first and the last two; the levels between sit behind an ellipsis menu. --}}
        <nav ref="trail" class="flex items-center gap-1 text-sm flex-1 flex-nowrap min-w-0 overflow-x-auto whitespace-nowrap">
            <template v-for="item in visibleCrumbs" :key="item.ellipsis ? 'ellipsis' : (item.crumb.id ?? item.index)">
                <span v-if="item.index > 0" class="text-gray-500 dark:text-gray-400">/</span>
                {{-- The shared dropdown gives the keyboard flow: Enter or Space opens it, arrows move, Escape closes, focus returns to the ellipsis. --}}
                <x-admin::dropdown v-if="item.ellipsis" position="bottom-left" :teleport="true" class="shrink-0" data-breadcrumb-overflow-dropdown>
                    <x-slot:toggle>
                        <button
                            type="button"
                            class="px-1.5 py-0.5 rounded shrink-0 text-gray-500 dark:text-gray-300 hover:text-primary-700 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer"
                            :aria-label="hiddenCrumbs.map(c => c.name).join(' / ')"
                            :title="hiddenCrumbs.map(c => c.name).join(' / ')"
                            data-breadcrumb-overflow
                            @dragover="openOverflowForDrag($event)"
                        >&hellip;</button>
                    </x-slot:toggle>

                    <x-slot:menu class="shadow-md !p-0 z-10 max-w-[80vw]">
                        <li v-for="crumb in hiddenCrumbs" :key="crumb.id ?? crumb.name" class="list-none">
                            <button
                                type="button"
                                class="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-cherry-800 truncate"
                                :class="crumbDropTarget === crumb.id ? 'bg-primary-100 dark:bg-primary-900/40 ring-1 ring-primary-400' : ''"
                                :title="crumb.name"
                                data-breadcrumb-hidden
                                @click="chooseHidden(crumb)"                                @dragover.prevent="onDragOver($event, crumb)"
                                @dragleave="onDragLeave($event, crumb)"
                                @drop.prevent="onHiddenDrop($event, crumb)"
                            >@{{ crumb.name }}</button>
                        </li>
                    </x-slot:menu>
                </x-admin::dropdown>
                <button
                    v-else
                    type="button"
                    class="px-1 py-0.5 rounded transition-colors truncate"
                    :class="[item.index === breadcrumbs.length - 1 ? 'min-w-[6rem] max-w-[60vw] min-[640px]:max-w-[480px]' : 'shrink-0 max-w-[80px] min-[640px]:max-w-[120px]', item.index === breadcrumbs.length - 1
                        ? 'text-primary-700 dark:text-primary-300 font-semibold cursor-default'
                        : crumbDropTarget === item.crumb.id
                            ? 'text-primary-700 dark:text-primary-300 cursor-pointer bg-primary-100 dark:bg-primary-900/40 ring-1 ring-primary-400'
                            : 'text-gray-500 dark:text-gray-300 hover:text-primary-700 hover:underline cursor-pointer']"
                    :title="item.crumb.name"
                    @click="item.index < breadcrumbs.length - 1 ? $emit('navigate', item.crumb) : null"                    @dragover.prevent="onDragOver($event, item.crumb)"
                    @dragleave="onDragLeave($event, item.crumb)"
                    @drop.prevent="onDrop($event, item.crumb)"
                >@{{ item.crumb.name }}</button>
            </template>
        </nav>

        </template>

    </div>
</script>

<script type="module">
app.component('v-dam-explorer-breadcrumb', {
    template: '#v-dam-explorer-breadcrumb-template',
    emits: ['back', 'forward', 'navigate', 'drop'],

    props: {
        breadcrumbs:  { type: Array,   default: () => [] },
        canGoBack:    { type: Boolean, default: false },
        canGoForward: { type: Boolean, default: false },
        currentDirId: { type: Number,  default: null },
        // True only until the tab's first fetch settles. A later fetch (back, forward, a crumb, per page,
        // page) must keep the trail mounted: swapping it for the shimmer destroyed the focused back
        // button or crumb, and keyboard focus fell to <body>.
        loading:      { type: Boolean, default: false },
    },

    data() {
        return {
            crumbDropTarget: null,
            springTimer: null,
        };
    },

    computed: {
        // [{ crumb, index }] to draw, with one { ellipsis: true } entry standing in for the levels left out.
        visibleCrumbs() {
            const all = this.breadcrumbs.map((crumb, index) => ({ crumb, index }));

            if (all.length <= 3) return all;

            return [all[0], { ellipsis: true, index: 1 }, ...all.slice(-2)];
        },

        hiddenCrumbs() {
            return this.breadcrumbs.length <= 3 ? [] : this.breadcrumbs.slice(1, -2);
        },
    },

    watch: {
        // The menu item that was chosen is gone once the trail changes, so focus goes to the trail itself instead of dropping onto the page.
        breadcrumbs() {
            if (! this._refocus) return;

            this.$nextTick(() => {
                if (! this._refocus) return;
                this._refocus = false;
                (this.$el.querySelector('[data-breadcrumb-overflow]') || [...this.$el.querySelectorAll('nav button')].pop())?.focus();
            });
        },
    },

    mounted() { this.scrollToCurrent(); },

    updated() { this.scrollToCurrent(); },

    methods: {
        chooseHidden(crumb) {
            this._refocus = true;
            this.$emit('navigate', crumb);
        },

        // The levels left out sit behind the ellipsis; dragging over it opens the menu so each one stays a drop target.
        openOverflowForDrag(e) {
            if (! e.dataTransfer?.types?.includes('application/json')) return;
            e.preventDefault();
            const toggle = e.currentTarget;
            if (toggle.getAttribute('aria-expanded') === 'true') return;
            toggle.dispatchEvent(new MouseEvent('click', { bubbles: true, detail: 1 }));
        },

        onHiddenDrop(e, crumb) {
            this.onDrop(e, crumb);
            const toggle = this.$el.querySelector('[data-breadcrumb-overflow]');
            if (toggle?.getAttribute('aria-expanded') === 'true') toggle.dispatchEvent(new MouseEvent('click', { bubbles: true, detail: 1 }));
        },

        // Keep the current (last) folder in view when the trail overflows, e.g. on a phone.
        scrollToCurrent() {
            const trail = this.$refs.trail;
            if (trail) trail.scrollLeft = trail.scrollWidth;
        },

        onDragOver(e, crumb) {
            if (! e.dataTransfer.types.includes('application/json')) return;
            this.crumbDropTarget = crumb.id;
            if (crumb.id === this.currentDirId) return;
            if (this.springTimer?.id === crumb.id) return;
            clearTimeout(this.springTimer?.timerId);
            const timerId = setTimeout(() => {
                if (this.crumbDropTarget === crumb.id) {
                    this.$emit('navigate', crumb);
                    this.springTimer = null;
                }
            }, 700);
            this.springTimer = { id: crumb.id, timerId };
        },

        onDragLeave(e, crumb) {
            if (this.crumbDropTarget === crumb.id && ! e.currentTarget.contains(e.relatedTarget)) {
                this.crumbDropTarget = null;
                clearTimeout(this.springTimer?.timerId);
                this.springTimer = null;
            }
        },

        onDrop(e, crumb) {
            this.crumbDropTarget = null;
            clearTimeout(this.springTimer?.timerId);
            this.springTimer = null;
            if (e.dataTransfer?.types?.includes('Files')) return;
            let payload;
            try { payload = JSON.parse(e.dataTransfer.getData('application/json')); } catch { return; }
            if (! payload?.type) return;
            this.$emit('drop', { payload, targetDir: crumb });
        },
    },
});
</script>
@endpush
@endonce
