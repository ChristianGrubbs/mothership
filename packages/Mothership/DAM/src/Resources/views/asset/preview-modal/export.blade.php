@once
{{-- P6a (D28, D17, D27): the viewer's Export menu. Each entry opens the shared Process dialog on this one file with
     its recipe chosen (a still and a GIF start at the player's current time), or, for a video, the Clip & watermark
     panel; either way the work runs as a one-item batch and shows in the same progress panel. A menu of <li>
     wrapping buttons, so the dropdown's keyboard menu pattern (PR #147) applies. Teleported: the header cluster
     sits at the card's right edge, and a teleported panel is clamped to the viewport. --}}
<script type="text/x-template" id="v-dam-asset-export-template">
    <x-admin::dropdown position="bottom-right" :teleport="true">
        <x-slot:toggle>
            <button
                type="button"
                data-dam-export
                class="rounded-lg border border-gray-300 dark:border-gray-600 px-2.5 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800"
            >@{{ t.export }} ▾</button>
        </x-slot>

        <x-slot:menu class="!py-1 min-w-[220px]">
            <li v-for="entry in entries" :key="entry.key">
                <button
                    type="button"
                    class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-cherry-800"
                    :class="entry.separated ? 'border-t border-gray-100 dark:border-gray-700' : ''"
                    :data-dam-export-item="entry.key"
                    @click="pick(entry)"
                >@{{ entry.label }}</button>
            </li>
        </x-slot>
    </x-admin::dropdown>
</script>
<script type="module">
app.component('v-dam-asset-export', {
    template: '#v-dam-asset-export-template',
    props: {
        assetId: { type: Number, required: true },
        fileType: { type: String, required: true },
        src: { type: String, default: '' },
        currentTime: { type: Function, default: () => 0 },
    },
    data() {
        return { t: @js(trans('dam::app.admin.batch')) };
    },
    computed: {
        entries() {
            const recipe = (key, extra = {}) => ({ key, recipe: key, label: this.t.recipes[key] + '…', ...extra });

            if (this.fileType === 'video') {
                return [
                    recipe('gif', { at: 'start' }),
                    { key: 'still', recipe: 'still', label: this.t.viewer['still-now'] + '…', at: 'time' },
                    recipe('audio'),
                    { key: 'audio_mp3', recipe: 'audio', label: this.t.viewer['export-mp3'] + '…', params: { format: 'mp3' } },
                    recipe('waveform'),
                    recipe('web_mp4'),
                    recipe('convert'),
                    recipe('resize'),
                    { key: 'clip', label: this.t.viewer['clip-watermark'], separated: true },
                ];
            }

            if (this.fileType === 'image') {
                return [recipe('convert'), recipe('resize'), recipe('watermark')];
            }

            return this.fileType === 'audio' ? [recipe('audio'), recipe('waveform')] : [];
        },
    },
    methods: {
        now() {
            const time = Number(this.currentTime()) || 0;

            return Math.round(time * 10) / 10;
        },
        pick(entry) {
            if (entry.key === 'clip') {
                this.$emitter.emit('dam:open-batch-clip', { assetId: this.assetId, src: this.src, currentTime: this.now() });

                return;
            }

            this.$emitter.emit('dam:open-batch-process', {
                assetIds: [this.assetId],
                recipe: entry.recipe,
                params: { ...(entry.params || {}), ...(entry.at ? { [entry.at]: this.now() } : {}) },
                lockRecipe: true,
            });
        },
    },
});
</script>
@endonce
