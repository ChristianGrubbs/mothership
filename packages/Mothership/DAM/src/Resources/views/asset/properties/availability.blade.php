{{-- P2 S3: the Availability panel. Editors change the state and window; everyone who sees the asset sees why it is not out for use. --}}
@php
    $availabilityState = \Mothership\DAM\Services\AssetAvailability::present($asset);
    $canEditAvailability = bouncer()->hasPermission('dam.asset.update') && empty($detailsNote);
@endphp

@if ($canEditAvailability || $availabilityState['unavailable'])
    <section
        class="mb-5 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-hidden"
        data-dam-availability-panel
    >
        <v-asset-availability
            :initial='@json($availabilityState)'
            :can-edit="{{ $canEditAvailability ? 'true' : 'false' }}"
        >
            <div class="px-5 py-4">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white">
                    @lang('dam::app.admin.dam.asset.availability.title')
                </h2>
            </div>
        </v-asset-availability>
    </section>
@endif

@pushOnce('scripts')
    <script type="text/x-template" id="v-asset-availability-template">
        <div>
            <div class="border-b border-gray-200 dark:border-cherry-700 px-5 py-4">
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white">
                        @lang('dam::app.admin.dam.asset.availability.title')
                    </h2>

                    <span
                        v-if="state.unavailable"
                        class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4 bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200"
                        :data-dam-availability-badge="state.unavailable"
                        v-text="badges[state.unavailable]"
                    ></span>
                </div>

                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @lang('dam::app.admin.dam.asset.availability.hint')
                </p>

                <!-- Why the badge: the state or the window, in words, so "Expired" beside State "Available" reads right. -->
                <p
                    v-if="state.unavailable"
                    class="mt-2 text-sm font-medium text-amber-800 dark:text-amber-300"
                    data-dam-availability-explain
                >
                    @{{ explain[state.unavailable] }}
                    <template v-if="! canEdit">@lang('dam::app.admin.dam.asset.availability.ask-editor')</template>
                </p>
            </div>

            <form
                v-if="canEdit"
                class="flex flex-col gap-3 px-5 py-4"
                @submit.prevent="save"
            >
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="flex flex-col gap-1 text-xs text-gray-600 dark:text-gray-300">
                    @lang('dam::app.admin.dam.asset.availability.state')
                    <select
                        v-model="form.availability"
                        class="h-9 rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-800 px-2.5 py-1.5 text-sm text-gray-800 dark:text-gray-100"
                        name="availability"
                    >
                        @foreach (\Mothership\DAM\Models\Asset::AVAILABILITY_STATES as $availabilityOption)
                            <option value="{{ $availabilityOption }}">@lang('dam::app.admin.dam.asset.availability.states.'.$availabilityOption)</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1 text-xs text-gray-600 dark:text-gray-300">
                    @lang('dam::app.admin.dam.asset.availability.from')
                    <input
                        v-model="form.available_from"
                        type="datetime-local"
                        name="available_from"
                        min="{{ \Mothership\DAM\Services\AssetAvailability::EARLIEST }}T00:00"
                        max="2099-12-31T23:59"
                        class="h-9 rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-800 px-2.5 py-1.5 text-sm text-gray-800 dark:text-gray-100"
                    >
                </label>

                <label class="flex flex-col gap-1 text-xs text-gray-600 dark:text-gray-300">
                    @lang('dam::app.admin.dam.asset.availability.until')
                    <input
                        v-model="form.available_until"
                        type="datetime-local"
                        name="available_until"
                        min="{{ \Mothership\DAM\Services\AssetAvailability::EARLIEST }}T00:00"
                        max="2099-12-31T23:59"
                        class="h-9 rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-800 px-2.5 py-1.5 text-sm text-gray-800 dark:text-gray-100"
                    >
                </label>

                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    @lang('dam::app.admin.dam.asset.availability.window-hint')
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="submit"
                        class="primary-button"
                        :disabled="saving"
                    >
                        @lang('dam::app.admin.dam.asset.availability.save')
                    </button>

                    <span
                        v-if="message"
                        class="text-xs"
                        :class="error ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400'"
                        role="status"
                        v-text="message"
                    ></span>
                </div>
            </form>

            <!-- Read-only (the owner): the window, in the viewer's time, so they know when the file opens or closes. -->
            <p
                v-else-if="state.available_from || state.available_until"
                class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200"
                data-dam-availability-window
            >
                <span class="text-xs text-gray-500 dark:text-gray-400" v-text="windowLabel"></span>
                @{{ windowText }}
            </p>
        </div>
    </script>

    <script type="module">
        app.component('v-asset-availability', {
            template: '#v-asset-availability-template',

            props: {
                initial: { type: Object, required: true },
                canEdit: { type: Boolean, default: false },
            },

            data() {
                return {
                    state: { ...this.initial },
                    form: this.formFrom(this.initial),
                    saving: false,
                    message: '',
                    error: false,
                    badges: @js(trans('dam::app.admin.dam.asset.availability.badges')),
                    explain: @js(trans('dam::app.admin.dam.asset.availability.explain')),
                };
            },

            computed: {
                // Both ends: "Window: A – B"; one end: "Available from: A" or "Available until: B".
                windowLabel() {
                    const { available_from: from, available_until: until } = this.state;

                    return (from && until ? @js(trans('dam::app.admin.dam.asset.availability.window'))
                        : from ? @js(trans('dam::app.admin.dam.asset.availability.from'))
                        : @js(trans('dam::app.admin.dam.asset.availability.until'))) + ':';
                },

                windowText() {
                    const show = (iso) => new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
                    const { available_from: from, available_until: until } = this.state;

                    return [from, until].filter(Boolean).map(show).join(' – ');
                },
            },

            methods: {
                // The inputs hold local wall-clock time; the server stores what the browser sends as an instant.
                toLocalInput(iso) {
                    if (! iso) return '';
                    const date = new Date(iso);
                    const pad = (n) => String(n).padStart(2, '0');

                    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
                },

                fromLocalInput(value) {
                    return value ? new Date(value).toISOString() : null;
                },

                formFrom(state) {
                    return {
                        availability: state.availability,
                        available_from: this.toLocalInput(state.available_from),
                        available_until: this.toLocalInput(state.available_until),
                    };
                },

                async save() {
                    this.saving = true;
                    this.message = '';
                    this.error = false;

                    try {
                        const { data } = await this.$axios.put(@js(route('admin.dam.assets.availability.update', ['asset' => $asset->id])), {
                            availability: this.form.availability,
                            available_from: this.fromLocalInput(this.form.available_from),
                            available_until: this.fromLocalInput(this.form.available_until),
                        });

                        this.state = data.availability;
                        this.form = this.formFrom(data.availability);
                        this.message = data.message;
                    } catch (e) {
                        this.error = true;
                        this.message = e.response?.data?.message || @js(trans('dam::app.admin.dam.asset.availability.failed'));
                    } finally {
                        this.saving = false;
                    }
                },
            },
        });
    </script>
@endPushOnce
