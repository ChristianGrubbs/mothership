{{-- P6 D26: the Embed button and dialog. Video and audio only; the token is shown once, at creation. --}}
@if (bouncer()->hasPermission('dam.asset.embed'))
    <v-embed-asset
        :asset-id="{{ $asset->id }}"
        initial-type="{{ $asset->file_type }}"
        initial-name="{{ $asset->display_name ?: $asset->file_name }}"
    ></v-embed-asset>
@endif

@pushOnce('scripts')
    <script type="text/x-template" id="v-embed-asset-template">
        <div v-if="embeddable" class="contents">
            <button
                type="button"
                class="transparent-button"
                :disabled="isLocked"
                :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked }"
                data-dam-embed-button
                @click="open"
            >
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5"/></svg>
                <span>@lang('dam::app.admin.dam.asset.edit.button.embed')</span>
            </button>

            {{-- z-[10020]: above the preview lightbox, as the share dialog. --}}
            <div v-if="showModal" class="relative z-[10020]">
                <v-modal :is-active="true" @toggle="onToggle">
                    <template v-slot:header="{ toggle }">
                        <div class="flex justify-between items-center gap-2.5 px-4 py-3 border-b dark:border-gray-800">
                            <p class="min-w-0 flex-1 truncate text-lg text-gray-800 dark:text-white font-bold" :title="title" data-dam-embed-title>
                                @{{ title }}
                            </p>
                            <button
                                type="button"
                                class="icon-cancel shrink-0 text-3xl cursor-pointer hover:bg-primary-50 dark:hover:bg-cherry-800 hover:rounded-md"
                                aria-label="@lang('dam::app.admin.dam.asset.embed.close')"
                                @click="toggle"
                            ></button>
                        </div>
                    </template>

                    <template v-slot:content>
                        <div class="flex flex-col gap-4 px-4 py-3 max-h-[calc(100vh-9rem)] overflow-y-auto" data-dam-embed-dialog>
                            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('dam::app.admin.dam.asset.embed.hint')</p>

                            {{-- The one time the code exists outside the visitor's browser: copy it now. --}}
                            <div
                                v-if="created"
                                class="flex flex-col gap-2 rounded-md border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/30 px-3 py-3"
                                data-dam-embed-created
                            >
                                <label class="text-sm font-semibold text-gray-800 dark:text-white" for="dam-embed-snippet">
                                    @lang('dam::app.admin.dam.asset.embed.snippet')
                                </label>
                                <textarea
                                    id="dam-embed-snippet"
                                    ref="snippet"
                                    readonly
                                    rows="4"
                                    class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-800 px-2.5 py-2 font-mono text-xs text-gray-800 dark:text-gray-100 break-all"
                                    data-dam-embed-snippet
                                    v-text="created.snippet"
                                    @focus="$event.target.select()"
                                ></textarea>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" class="primary-button" data-dam-embed-copy @click="copySnippet">
                                        @{{ copied ? copiedLabel : copyLabel }}
                                    </button>
                                    <p class="text-xs text-emerald-800 dark:text-emerald-300">@lang('dam::app.admin.dam.asset.embed.snippet-hint')</p>
                                </div>
                            </div>

                            <form class="flex flex-col gap-3" data-dam-embed-form @submit.prevent="create">
                                <label class="flex flex-col gap-1 text-sm font-medium text-gray-700 dark:text-gray-200" for="dam-embed-origins">
                                    @lang('dam::app.admin.dam.asset.embed.origins')
                                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.embed.origins-hint')</span>
                                </label>
                                <textarea
                                    id="dam-embed-origins"
                                    v-model="form.origins"
                                    rows="3"
                                    placeholder="https://example.com"
                                    class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-800 px-2.5 py-2 text-sm text-gray-800 dark:text-gray-100"
                                    :aria-invalid="errors.allowed_origins ? 'true' : 'false'"
                                    data-dam-embed-origins
                                ></textarea>
                                <p v-if="errors.allowed_origins" class="text-xs text-red-600" role="alert" data-dam-embed-error>@{{ errors.allowed_origins }}</p>

                                <div class="flex flex-wrap items-end gap-3">
                                    <label class="flex flex-col gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                                        @lang('dam::app.admin.dam.asset.embed.expiry')
                                        <select
                                            v-model="form.expiry"
                                            class="h-9 rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-800 px-2.5 py-1.5 text-sm text-gray-800 dark:text-gray-100"
                                            data-dam-embed-expiry
                                        >
                                            @foreach (['never', '30', '90', '365'] as $embedExpiry)
                                                <option value="{{ $embedExpiry }}">@lang('dam::app.admin.dam.asset.embed.expiry-options.'.$embedExpiry)</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <button type="submit" class="primary-button" :disabled="isCreating" data-dam-embed-create>
                                        @{{ isCreating ? creatingLabel : createLabel }}
                                    </button>
                                </div>
                            </form>

                            <div class="flex flex-col gap-2 border-t border-gray-200 dark:border-cherry-700 pt-3" data-dam-embed-list>
                                <p class="text-sm font-bold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.asset.embed.existing')</p>

                                <p v-if="isLoading" class="text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.embed.loading')</p>
                                <p v-else-if="! embeds.length" class="text-sm text-gray-500 dark:text-gray-400" data-dam-embed-none>@lang('dam::app.admin.dam.asset.embed.none')</p>

                                <ul v-else class="flex flex-col rounded-md border border-gray-200 dark:border-cherry-700">
                                    <li
                                        v-for="embed in embeds"
                                        :key="embed.id"
                                        class="flex flex-col gap-1 border-b border-gray-100 dark:border-cherry-800 px-3 py-2 last:border-b-0"
                                        :data-dam-embed-row="embed.id"
                                    >
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span
                                                class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4"
                                                :class="statusClass(embed.status)"
                                                :data-dam-embed-status="embed.status"
                                                v-text="statuses[embed.status] ?? embed.status"
                                            ></span>
                                            <span class="min-w-0 flex-1 break-words text-sm text-gray-800 dark:text-gray-100">
                                                @{{ embed.allowed_origins && embed.allowed_origins.length ? embed.allowed_origins.join(', ') : anySite }}
                                            </span>
                                            <template v-if="embed.can_revoke && embed.status === 'active'">
                                                <template v-if="confirming === embed.id">
                                                    <button type="button" class="secondary-button !text-red-600 dark:!text-red-500" :disabled="isRevoking" data-dam-embed-revoke-yes @click="revoke(embed)">@lang('dam::app.admin.dam.asset.embed.revoke')</button>
                                                    <button type="button" class="transparent-button" data-dam-embed-revoke-no @click="confirming = null">@lang('dam::app.admin.dam.asset.embed.cancel')</button>
                                                </template>
                                                <button v-else type="button" class="secondary-button !text-red-600 dark:!text-red-500" data-dam-embed-revoke @click="confirming = embed.id">@lang('dam::app.admin.dam.asset.embed.revoke')</button>
                                            </template>
                                        </div>
                                        <p v-if="confirming === embed.id" class="text-xs text-red-700 dark:text-red-400" data-dam-embed-revoke-confirm>@lang('dam::app.admin.dam.asset.embed.revoke-confirm')</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            @{{ createdLine(embed) }} · @{{ embed.expires_at ? expiresLine(embed) : noExpiry }}
                                        </p>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </template>
                </v-modal>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-embed-asset', {
            mixins: [window._damShownStackFile],
            template: '#v-embed-asset-template',

            props: {
                assetId: { type: Number, required: true },
                initialType: { type: String, default: '' },
                initialName: { type: String, default: '' },
            },

            data() {
                return {
                    // Follows the ‹ › chevrons through dam-asset-changed, as the Share button does.
                    currentAssetId: this.assetId,
                    fileType: this.initialType,
                    name: this.initialName,
                    isLocked: false,
                    showModal: false,
                    isLoading: false,
                    isCreating: false,
                    isRevoking: false,
                    confirming: null,
                    copied: false,
                    embeds: [],
                    created: null,
                    errors: {},
                    form: { origins: '', expiry: 'never' },
                    statuses: @js(trans('dam::app.admin.dam.asset.embed.statuses')),
                    anySite: @js(trans('dam::app.admin.dam.asset.embed.any-site')),
                    noExpiry: @js(trans('dam::app.admin.dam.asset.embed.no-expiry')),
                    copyLabel: @js(trans('dam::app.admin.dam.asset.embed.copy')),
                    copiedLabel: @js(trans('dam::app.admin.dam.asset.embed.copied')),
                    createLabel: @js(trans('dam::app.admin.dam.asset.embed.create')),
                    creatingLabel: @js(trans('dam::app.admin.dam.asset.embed.creating')),
                };
            },

            computed: {
                embeddable() {
                    return ['video', 'audio'].includes(this.fileType);
                },

                title() {
                    return @js(trans('dam::app.admin.dam.asset.embed.title', ['name' => ':name'])).replace(':name', this.name);
                },

                indexUrl() {
                    return @js(route('admin.dam.assets.embeds.index', ['asset' => '__ID__'])).replace('__ID__', this.currentAssetId);
                },
            },

            mounted() {
                this.onLockChange = (locked) => { this.isLocked = !! locked; };
                this.$emitter.on('dam-asset-action-locked', this.onLockChange);

                this.onAssetChange = (data) => {
                    if (! data?.asset?.id) return;

                    this.currentAssetId = data.asset.id;
                    this.fileType = data.asset.file_type ?? '';
                    this.name = data.asset.display_name || data.asset.file_name || '';
                    this.showModal = false;
                };
                this.$emitter.on('dam-asset-changed', this.onAssetChange);
            },

            unmounted() {
                this.$emitter.off('dam-asset-action-locked', this.onLockChange);
                this.$emitter.off('dam-asset-changed', this.onAssetChange);
            },

            methods: {
                open() {
                    if (this.isLocked || ! this.assertCoverShown()) return;

                    this.created = null;
                    this.errors = {};
                    this.confirming = null;
                    this.copied = false;
                    this.form = { origins: '', expiry: 'never' };
                    this.showModal = true;
                    this.load();
                },

                onToggle({ isActive }) {
                    if (! isActive) this.showModal = false;
                },

                load() {
                    this.isLoading = true;

                    this.$axios.get(this.indexUrl)
                        .then((response) => { this.embeds = response.data?.data ?? []; })
                        .catch(() => this.flash('error', @js(trans('dam::app.admin.dam.asset.embed.load-failed'))))
                        .finally(() => { this.isLoading = false; });
                },

                create() {
                    if (! this.assertCoverShown()) return;

                    this.isCreating = true;
                    this.errors = {};

                    const payload = { allowed_origins: this.form.origins };

                    if (this.form.expiry !== 'never') payload.expiry_days = Number(this.form.expiry);

                    this.$axios.post(this.indexUrl, payload)
                        .then((response) => {
                            const data = response.data?.data ?? {};

                            this.created = { url: data.url, snippet: data.snippet };
                            this.copied = false;
                            this.embeds = [data.embed, ...this.embeds.filter((embed) => embed.id !== data.embed?.id)];
                            this.form.origins = '';
                        })
                        .catch((error) => {
                            const errors = error.response?.data?.errors ?? {};

                            this.errors = Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, [].concat(messages)[0]]));

                            if (! Object.keys(this.errors).length) {
                                this.flash('error', error.response?.data?.message || @js(trans('dam::app.admin.dam.asset.embed.create-failed')));
                            }
                        })
                        .finally(() => { this.isCreating = false; });
                },

                revoke(embed) {
                    if (! this.assertCoverShown()) return;

                    this.isRevoking = true;

                    this.$axios.post(this.indexUrl + '/' + embed.id + '/revoke')
                        .then((response) => {
                            const updated = response.data?.data;

                            if (updated) this.embeds = this.embeds.map((row) => row.id === updated.id ? updated : row);
                            this.flash(response.data?.success ? 'success' : 'warning', response.data?.message);
                        })
                        .catch(() => this.flash('error', @js(trans('dam::app.admin.dam.asset.embed.revoke-failed'))))
                        .finally(() => {
                            this.isRevoking = false;
                            this.confirming = null;
                        });
                },

                copySnippet() {
                    const text = this.created?.snippet ?? '';
                    const done = () => {
                        this.copied = true;
                        setTimeout(() => { this.copied = false; }, 2000);
                    };

                    if (navigator.clipboard?.writeText) {
                        navigator.clipboard.writeText(text).then(done).catch(() => this.selectSnippet());
                    } else {
                        this.selectSnippet();
                    }
                },

                selectSnippet() {
                    this.$refs.snippet?.focus();
                    this.$refs.snippet?.select();
                },

                statusClass(status) {
                    return {
                        active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
                        revoked: 'bg-gray-200 text-gray-700 dark:bg-cherry-800 dark:text-gray-300',
                        expired: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
                    }[status] ?? 'bg-gray-100 text-gray-700';
                },

                formatDate(iso) {
                    return iso ? new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '';
                },

                createdLine(embed) {
                    const date = this.formatDate(embed.created_at);

                    return embed.creator_name
                        ? @js(trans('dam::app.admin.dam.asset.embed.created-by', ['date' => ':date', 'name' => ':name'])).replace(':date', date).replace(':name', embed.creator_name)
                        : @js(trans('dam::app.admin.dam.asset.embed.created-on', ['date' => ':date'])).replace(':date', date);
                },

                expiresLine(embed) {
                    return @js(trans('dam::app.admin.dam.asset.embed.expires-on', ['date' => ':date'])).replace(':date', this.formatDate(embed.expires_at));
                },

                flash(type, message) {
                    if (message) this.$emitter.emit('add-flash', { type, message });
                },
            },
        });
    </script>
@endPushOnce
