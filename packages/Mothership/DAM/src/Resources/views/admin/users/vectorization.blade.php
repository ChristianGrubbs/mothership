<v-vectorization-allowance
    url="{{ route('admin.home.vectorization.allowance', ['admin' => $vectorizationAdminId]) }}"
    csrf="{{ csrf_token() }}"
></v-vectorization-allowance>

@pushOnce('scripts')
    <script type="text/x-template" id="v-vectorization-allowance-template">
        <section id="vectorization-allowance" class="mt-4 flex flex-col gap-3 rounded-lg bg-white p-4 box-shadow dark:bg-cherry-900">
            <h2 class="text-base font-semibold text-gray-800 dark:text-white">Image Vectorization</h2>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-gray-800 dark:text-white">
                Available vectorizations
                <input
                    class="w-full max-w-xs rounded-md border px-3 py-2.5 text-sm font-normal text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-600 dark:bg-cherry-900 dark:text-gray-300"
                    ref="balance"
                    data-balance
                    type="number"
                    min="0"
                    max="1000000"
                    step="1"
                    v-model="remaining"
                    :disabled="loading || saving || unlimited"
                >
            </label>
            <p class="text-sm text-gray-600 dark:text-gray-300" data-current role="status">@{{ status }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">No expiry. In-progress vectorizations are reserved separately; failures return their reservation.</p>
            <button type="button" class="secondary-button self-start whitespace-nowrap" data-save :disabled="loading || saving || unlimited" @click="save">
                Save vectorizations
            </button>
        </section>
    </script>

    <script type="module">
        app.component('v-vectorization-allowance', {
            template: '#v-vectorization-allowance-template',

            props: {
                url: {
                    type: String,
                    required: true,
                },

                csrf: {
                    type: String,
                    required: true,
                },
            },

            data() {
                return {
                    controller: new AbortController(),
                    loading: true,
                    saving: false,
                    unlimited: false,
                    remaining: '',
                    reserved: 0,
                    revision: 0,
                    status: 'Loading…',
                };
            },

            mounted() {
                this.load();
            },

            beforeUnmount() {
                this.controller.abort();
            },

            methods: {
                render(data) {
                    this.revision = data.revision;
                    this.remaining = data.remaining ?? '';
                    this.reserved = data.reserved;
                    this.unlimited = data.unlimited;
                    this.status = data.unlimited
                        ? 'Unlimited'
                        : `${data.remaining} available · ${data.reserved} in progress`;
                },

                async request(method = 'GET') {
                    const response = await fetch(this.url, {
                        method,
                        signal: this.controller.signal,
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                        body: method === 'PUT'
                            ? JSON.stringify({remaining: Number(this.remaining), revision: this.revision})
                            : undefined,
                    });
                    const data = await response.json();

                    if (! response.ok) {
                        throw Error(data.message || 'Unable to save balance.');
                    }

                    return data;
                },

                async load() {
                    this.loading = true;

                    try {
                        this.render(await this.request());

                        return true;
                    } catch (error) {
                        if (error.name !== 'AbortError') {
                            this.status = error.message;
                        }

                        return false;
                    } finally {
                        this.loading = false;
                    }
                },

                async save() {
                    if (! this.$refs.balance.reportValidity() || this.saving) {
                        return;
                    }

                    this.saving = true;

                    try {
                        this.render(await this.request('PUT'));
                    } catch (error) {
                        if (error.name !== 'AbortError') {
                            const message = error.message;

                            if (await this.load()) {
                                this.status = `${message} Current balance loaded; retry your change.`;
                            }
                        }
                    } finally {
                        this.saving = false;
                    }
                },
            },
        });
    </script>
@endPushOnce
