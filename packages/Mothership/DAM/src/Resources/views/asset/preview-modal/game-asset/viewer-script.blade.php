@once
{{-- Blender files and Unity assets (no renderer, ADR 0006): the thumbnail the file embeds and the facts GenerateGameAssetThumbnail read, or for a Unity text asset its first 256 KB. `preview` is AssetHelper::gameAssetPreview(). Every string from the file is rendered as text, never as markup. --}}
<script type="text/x-template" id="v-dam-game-asset-preview-template">
    <div class="flex h-full w-full flex-col gap-4 p-4 lg:flex-row" data-dam-game-asset>
        <template v-if="preview.kind === 'unity-yaml'">
            <div class="flex min-h-0 w-full flex-1 flex-col gap-2">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">@{{ labels['unity-text'] }} · @{{ fileName }}</p>
                <p v-if="text.state === 'loading'" class="text-sm text-gray-500 dark:text-gray-400">@{{ labels['text-loading'] }}</p>
                <p v-else-if="text.state === 'binary'" class="text-sm text-gray-500 dark:text-gray-400">@{{ labels['text-binary'] }}</p>
                <p v-else-if="text.state === 'failed'" class="text-sm text-red-600">@{{ labels['text-failed'] }}</p>
                <template v-else>
                    <pre class="min-h-0 flex-1 overflow-auto rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800 dark:bg-gray-900 dark:text-gray-200" style="max-height: 70vh; white-space: pre;" data-dam-unity-text>@{{ text.body }}</pre>
                    <p v-if="text.truncated" class="text-xs text-gray-500 dark:text-gray-400">@{{ labels['text-truncated'].replace(':size', Math.round(text.limit / 1024)) }}</p>
                </template>
            </div>
        </template>

        <template v-else>
            <div class="flex min-h-0 flex-1 items-center justify-center">
                <img
                    :src="preview.thumbnailUrl"
                    :alt="fileName"
                    class="w-full max-w-lg object-contain"
                    style="max-height: 70vh;"
                    data-dam-game-asset-thumbnail
                    v-on:load="onThumbnailSettled"
                    v-on:error="onThumbnailSettled"
                />
            </div>

            <aside class="w-full shrink-0 overflow-auto rounded-lg border border-gray-200 p-4 text-sm dark:border-gray-800 lg:w-80" style="max-height: 70vh;">
                <p class="font-semibold text-gray-800 dark:text-white">@{{ preview.kind === 'blender' ? labels['blender-title'] : labels['unity-package'] }}</p>

                <p v-if="! facts.read" class="mt-2 text-gray-500 dark:text-gray-400">@{{ labels.reading }}</p>
                <p v-else-if="facts.readable === false" class="mt-2 text-gray-500 dark:text-gray-400">@{{ labels.unreadable.replace(':type', preview.kind === 'blender' ? labels['blender-title'] : labels['unity-package']) }}</p>

                <template v-else-if="preview.kind === 'blender'">
                    <p v-if="facts.blenderVersion" class="mt-1 text-gray-600 dark:text-gray-300" data-dam-blender-version>@{{ labels['blender-version'].replace(':version', facts.blenderVersion) }}</p>
                    <dl v-if="Object.keys(facts.counts || {}).length" class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1">
                        <template v-for="(count, key) in facts.counts" :key="key">
                            <dt class="text-gray-500 dark:text-gray-400">@{{ labels[key] || key }}</dt>
                            <dd class="text-right font-medium text-gray-800 dark:text-gray-200">@{{ count }}</dd>
                        </template>
                    </dl>
                </template>

                <template v-else>
                    <p class="mt-3 font-medium text-gray-700 dark:text-gray-200">@{{ labels.contents }} (@{{ facts.assetCount ?? facts.assets.length }})</p>
                    <p v-if="facts.truncated" class="text-xs text-gray-500 dark:text-gray-400">@{{ labels['contents-shown'].replace(':shown', facts.assets.length).replace(':total', facts.assetCount) }}</p>
                    <ul class="mt-2 space-y-1" data-dam-unity-contents>
                        <li v-for="item in facts.assets" :key="item.path" class="flex items-start gap-2 break-all text-gray-700 dark:text-gray-300">
                            <span class="flex-1">@{{ item.path }}</span>
                            <span v-if="item.has_preview" class="shrink-0 rounded bg-gray-100 px-1.5 text-[10px] text-gray-500 dark:bg-gray-800 dark:text-gray-400" :title="labels['has-preview']">PNG</span>
                        </li>
                    </ul>
                </template>
            </aside>
        </template>
    </div>
</script>
<script type="module">
    app.component('v-dam-game-asset-preview', {
        template: '#v-dam-game-asset-preview-template',
        props: {
            preview: { type: Object, required: true },
            assetId: { type: [Number, String], default: null },
            fileName: { type: String, default: '' },
        },
        data() {
            return {
                labels: @json(trans('dam::app.admin.dam.asset.edit.preview-modal.game-asset')),
                infoUrlTemplate: @json(route('admin.dam.assets.info', ['id' => '__ID__'])),
                // The facts shown; refreshed from the info endpoint when the file had not been read yet.
                facts: { ...this.preview },
                text: { state: 'idle', body: '', truncated: false, limit: 0 },
                generation: 0,
                factsGeneration: 0,
            };
        },
        mounted() { this.loadText(); },
        watch: {
            'preview.textUrl'() { this.loadText(); },
            preview(value) { this.factsGeneration++; this.facts = { ...value }; },
        },
        methods: {
            // An unread file is read by the thumbnail request itself (FileController::thumbnail() runs
            // GenerateGameAssetThumbnail), so once the image settles its facts exist: fetch them, retrying
            // at most three times (1 s, 2 s, 4 s) in case a queued run is still finishing.
            onThumbnailSettled() {
                if (this.facts.read || ! this.assetId || this.preview.kind === 'unity-yaml') return;
                this.refreshFacts(0, this.factsGeneration);
            },
            async refreshFacts(attempt, gen) {
                try {
                    const response = await fetch(this.infoUrlTemplate.replace('__ID__', this.assetId), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    const body = response.ok ? await response.json() : null;
                    if (gen !== this.factsGeneration) return;
                    if (body?.game_asset?.read) { this.facts = { ...this.facts, ...body.game_asset }; return; }
                } catch (error) { if (gen !== this.factsGeneration) return; }
                if (attempt < 3) setTimeout(() => this.refreshFacts(attempt + 1, gen), 1000 * 2 ** attempt);
            },
            async loadText() {
                // A newer asset in the same modal drops a stale in-flight response.
                const gen = ++this.generation;
                if (this.preview.kind !== 'unity-yaml' || ! this.preview.textUrl) return;
                this.text = { state: 'loading', body: '', truncated: false, limit: 0 };
                try {
                    const response = await fetch(this.preview.textUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    const body = response.ok ? await response.json() : null;
                    if (gen !== this.generation) return;
                    if (! body) { this.text.state = 'failed'; return; }
                    this.text = body.binary
                        ? { state: 'binary', body: '', truncated: false, limit: 0 }
                        : { state: 'ready', body: body.text || '', truncated: !! body.truncated, limit: body.limit_bytes || 0 };
                } catch (error) { if (gen === this.generation) this.text.state = 'failed'; }
            },
        },
    });
</script>
@endonce
