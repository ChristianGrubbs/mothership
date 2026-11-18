@once
    @push('scripts')
        <script type="text/x-template" id="v-dam-member-download-template">
            <span v-if="eligible">
                <button type="button" class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs" :title="labelClass ? @js(trans('dam::app.admin.dam.asset.edit.button.download_as')) : null" :aria-label="labelClass ? @js(trans('dam::app.admin.dam.asset.edit.button.download_as')) : null" @click="open" data-dam-member-download-as>
                    <span class="icon-dam-download text-lg" aria-hidden="true"></span>
                    <span :class="labelClass">@lang('dam::app.admin.dam.asset.edit.button.download_as')</span>
                </button>
                <teleport to="body">
                    <dialog ref="dialog" class="w-[min(28rem,calc(100vw-2rem))] rounded-xl border border-gray-200 bg-white p-0 text-gray-800 shadow-xl backdrop:bg-black/50 dark:border-gray-700 dark:bg-gray-900 dark:text-white" @keydown.stop @cancel="target = null">
                        <form @submit.prevent="download" class="flex flex-col gap-4 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="text-lg font-bold">@lang('dam::app.admin.dam.asset.edit.custom-download.title')</h2>
                                <button type="button" class="secondary-button !px-2 !py-1" @click="close" aria-label="@lang('dam::app.admin.dam.asset.stack-fan.collapse')">×</button>
                            </div>
                            <p class="break-all text-sm" data-dam-member-download-name>@{{ target?.file_name || target?.label }}</p>
                            <label class="flex flex-col gap-1 text-sm">
                                @lang('dam::app.admin.dam.asset.edit.custom-download.format')
                                <select v-model="format" class="rounded border border-gray-300 bg-white p-2 text-gray-800 dark:border-gray-600 dark:bg-gray-800 dark:text-white" data-dam-member-download-format>
                                    <option :value="target?.extension">@lang('dam::app.admin.dam.asset.edit.custom-download.extension-types.original')</option>
                                    <option v-for="option in formats" :key="option" :value="option">@{{ option.toUpperCase() }}</option>
                                </select>
                            </label>
                            <div v-if="format !== 'svg'" class="grid grid-cols-2 gap-3">
                                <label class="flex flex-col gap-1 text-sm">
                                    @lang('dam::app.admin.dam.asset.edit.custom-download.width')
                                    <input v-model="width" type="number" min="1" step="1" class="min-w-0 rounded border border-gray-300 bg-transparent p-2" data-dam-member-download-width>
                                </label>
                                <label class="flex flex-col gap-1 text-sm">
                                    @lang('dam::app.admin.dam.asset.edit.custom-download.height')
                                    <input v-model="height" type="number" min="1" step="1" class="min-w-0 rounded border border-gray-300 bg-transparent p-2" data-dam-member-download-height>
                                </label>
                            </div>
                            <button type="submit" class="primary-button self-end" data-dam-member-download-submit>@lang('dam::app.admin.dam.asset.edit.custom-download.download-btn')</button>
                        </form>
                    </dialog>
                </teleport>
            </span>
        </script>
        <script type="module">
            app.component('v-dam-member-download', {
                template: '#v-dam-member-download-template',
                props: { file: { type: Object, required: true }, labelClass: { type: String, default: '' } },
                data() { return { target: null, format: '', width: '', height: '' }; },
                computed: {
                    formats() { const extension = String(this.target?.extension || '').toLowerCase().replace('jpeg', 'jpg'); return ['jpg', 'png', 'webp'].filter(value => value !== extension); },
                    eligible() { return this.file?.id && this.file?.asset_id && (this.file.file_type === 'image' || String(this.file.mime_type || '').startsWith('image/') || String(this.file.extension).toLowerCase() === 'svg'); },
                },
                beforeUnmount() { this.$refs.dialog?.close(); },
                methods: {
                    open() {
                        this.target = { ...this.file };
                        this.format = this.target.extension;
                        this.width = '';
                        this.height = '';
                        this.$refs.dialog.showModal();
                    },
                    close() { this.$refs.dialog.close(); this.target = null; },
                    download() {
                        const file = this.target;
                        if (!file) return;
                        const url = new URL(@json(route('admin.dam.assets.custom_download', ':id')).replace(':id', file.asset_id), window.location.origin);
                        url.searchParams.set('file', file.id);
                        url.searchParams.set('format', this.format);
                        if (this.width) url.searchParams.set('width', this.width);
                        if (this.height) url.searchParams.set('height', this.height);
                        if (window.damDownload) window.damDownload.start({ url: url.href, name: file.file_name || file.label, thumb: file.thumbnail_url || '' });
                        else window.location.assign(url.href);
                        this.close();
                    },
                },
            });
        </script>
    @endpush
@endonce
