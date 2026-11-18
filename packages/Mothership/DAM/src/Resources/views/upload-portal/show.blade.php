{{--
    P5a (D1): the guest upload portal. Write-only: the guest sees the request's title, instructions and limits, drops or
    picks files, and sees only the names this visit sent. Nothing here lists, previews or downloads a library file, and
    no folder structure is kept (every file lands in the request's intake folder, chosen by the server).
    Files go up on the Library's own chunk protocol (resumable, checksummed per chunk) through this link's routes.
--}}
@php
    $limits = array_values(array_filter([
        $uploadRequest->max_files ? trans('dam::app.upload-portal.limits.files', ['count' => $uploadRequest->max_files]) : null,
        $uploadRequest->max_bytes_per_file ? trans('dam::app.upload-portal.limits.size', ['size' => \Illuminate\Support\Number::fileSize((int) $uploadRequest->max_bytes_per_file, precision: 0, maxPrecision: 1)]) : null,
        $uploadRequest->allowed_types ? trans('dam::app.upload-portal.limits.types', ['types' => implode(', ', $uploadRequest->allowed_types)]) : null,
    ]));
    $routes = [
        'initialize' => route('dam.upload_portal.initialize', ['token' => $token]),
        'status'     => route('dam.upload_portal.status', ['token' => $token, 'uuid' => '00000000-0000-0000-0000-000000000000']),
        'store'      => route('dam.upload_portal.store', ['token' => $token, 'uuid' => '00000000-0000-0000-0000-000000000000', 'index' => 0]),
        'complete'   => route('dam.upload_portal.complete', ['token' => $token, 'uuid' => '00000000-0000-0000-0000-000000000000']),
        'cancel'     => route('dam.upload_portal.cancel', ['token' => $token, 'uuid' => '00000000-0000-0000-0000-000000000000']),
    ];
@endphp
<x-admin::layouts.anonymous>
    <x-slot:title>
        {{ $uploadRequest->title }}
    </x-slot:title>

    @push('meta')
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
    @endpush

    @push('styles')
        @mothershipVite(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'], 'dam')
    @endpush

    <div class="min-h-screen bg-gray-50 dark:bg-cherry-950">
        <header class="border-b border-gray-200 bg-white px-4 py-3 dark:border-cherry-800 dark:bg-cherry-900 sm:px-6">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4">
                @include('dam::upload-portal.partials.brand')
                <span class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.upload-portal.badge')</span>
            </div>
        </header>

        <main class="mx-auto flex max-w-3xl flex-col gap-5 px-4 py-6 sm:px-6 sm:py-10">
            <section class="flex flex-col gap-2" data-dock="rise">
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-slate-50 break-words" data-dam-portal-title>{{ $uploadRequest->title }}</h1>
                @if ($uploadRequest->instructions)
                    <p class="whitespace-pre-line text-sm text-gray-700 dark:text-slate-300" data-dam-portal-instructions>{{ $uploadRequest->instructions }}</p>
                @endif
                @if ($limits || $uploadRequest->expires_at)
                    <p class="text-xs text-gray-500 dark:text-slate-400" data-dam-portal-limits>
                        {{ implode(' · ', $limits) }}@if ($limits && $uploadRequest->expires_at) · @endif
                        @if ($uploadRequest->expires_at)
                            {{-- Server date (UTC) without JavaScript; the portal rewrites it in the guest's own time zone. --}}
                            <time datetime="{{ $uploadRequest->expires_at->toIso8601String() }}" data-dam-portal-expiry>@lang('dam::app.upload-portal.limits.until', ['date' => $uploadRequest->expires_at->toFormattedDayDateString()])</time>
                        @endif
                    </p>
                @endif
            </section>

            <v-dam-upload-portal></v-dam-upload-portal>

            <p class="text-xs leading-relaxed text-gray-500 dark:text-slate-400" data-dam-portal-privacy>
                @lang('dam::app.upload-portal.privacy', ['days' => (int) config('dam.upload_requests.identity_retention_days')])
            </p>
        </main>
    </div>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-upload-portal-template">
            <div class="flex flex-col gap-5">
                <details class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-cherry-800 dark:bg-cherry-900" data-dam-portal-identity>
                    <summary class="block min-h-[44px] cursor-pointer py-3 text-sm font-medium text-gray-700 dark:text-slate-200">@lang('dam::app.upload-portal.identity.toggle')</summary>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1 text-xs font-medium text-gray-600 dark:text-slate-300">
                            @lang('dam::app.upload-portal.identity.name')
                            <input v-model="guestName" type="text" maxlength="191" autocomplete="name" class="min-h-[44px] rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-slate-200" />
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-gray-600 dark:text-slate-300">
                            @lang('dam::app.upload-portal.identity.email')
                            <input v-model="guestEmail" type="email" maxlength="191" autocomplete="email" class="min-h-[44px] rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-slate-200" />
                        </label>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.upload-portal.identity.hint')</p>
                </details>

                <div
                    class="flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors"
                    :class="dragging ? 'border-primary-500 bg-primary-50 dark:bg-cherry-800' : 'border-gray-300 bg-white dark:border-cherry-700 dark:bg-cherry-900'"
                    data-dam-portal-dropzone
                    @dragenter.prevent="dragging = true"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <span class="icon-import text-4xl text-primary-600 dark:text-primary-400" aria-hidden="true"></span>
                    <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.upload-portal.drop.title')</p>
                    <p class="text-sm text-gray-500 dark:text-slate-400">@lang('dam::app.upload-portal.drop.or')</p>
                    <button type="button" class="primary-button min-h-[44px]" data-dam-portal-pick @click="$refs.picker.click()">@lang('dam::app.upload-portal.drop.pick')</button>
                    <input ref="picker" type="file" multiple class="hidden" accept="{{ $accept }}" data-dam-portal-input @change="onPick" />
                </div>

                <p v-if="notice" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200" role="status" data-dam-portal-notice v-text="notice"></p>

                <section v-if="items.length" class="rounded-lg border border-gray-200 bg-white dark:border-cherry-800 dark:bg-cherry-900" data-dam-portal-queue>
                    <header class="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-2.5 dark:border-cherry-800">
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-white" data-dam-portal-queue-title v-text="queueTitle"></h2>
                        <span class="text-xs text-gray-500 dark:text-slate-400" v-text="queueSummary"></span>
                    </header>
                    <ul>
                        <li v-for="item in items" :key="item.key" class="flex flex-col gap-1.5 border-b border-gray-100 px-4 py-2.5 last:border-b-0 dark:border-cherry-800" :data-dam-portal-item="item.state">
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate text-sm text-gray-800 dark:text-white" :title="item.name" v-text="item.name"></span>
                                <span class="flex shrink-0 items-center gap-2">
                                    <span class="text-xs" :class="item.state === 'failed' ? 'text-red-600 dark:text-red-400' : (item.state === 'done' ? 'text-green-700 dark:text-green-400' : 'text-gray-500 dark:text-slate-400')" v-text="itemLabel(item)"></span>
                                    <button v-if="['queued', 'uploading'].includes(item.state)" type="button" class="text-xs text-gray-500 underline hover:text-gray-800 dark:text-slate-400" @click="cancel(item)">@lang('dam::app.upload-portal.queue.cancel')</button>
                                    <button v-if="item.state === 'failed' && item.retryable" type="button" class="text-xs text-primary-700 underline dark:text-primary-300" @click="retry(item)">@lang('dam::app.upload-portal.queue.retry')</button>
                                </span>
                            </div>
                            <div v-if="['uploading', 'finishing'].includes(item.state)" class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-cherry-800" role="progressbar" :aria-valuenow="Math.round(item.progress * 100)" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-primary-600 transition-all" :style="{ width: Math.max(2, Math.round(item.progress * 100)) + '%' }"></div>
                            </div>
                            <p v-if="item.state === 'failed' && item.error" class="text-xs text-red-600 dark:text-red-400" v-text="item.error"></p>
                        </li>
                    </ul>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white dark:border-cherry-800 dark:bg-cherry-900" data-dam-portal-sent>
                    <header class="border-b border-gray-200 px-4 py-2.5 dark:border-cherry-800">
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-white">@lang('dam::app.upload-portal.sent.title')</h2>
                    </header>
                    <p v-if="! sent.length" class="px-4 py-4 text-sm text-gray-500 dark:text-slate-400" data-dam-portal-sent-empty>@lang('dam::app.upload-portal.sent.empty')</p>
                    <ul v-else>
                        <li v-for="file in sent" :key="file.uuid" class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2 text-sm last:border-b-0 dark:border-cherry-800">
                            <span class="min-w-0 truncate text-gray-800 dark:text-white" :title="file.name" v-text="file.name"></span>
                            <span class="shrink-0 text-xs text-green-700 dark:text-green-400">@lang('dam::app.upload-portal.sent.received')</span>
                        </li>
                    </ul>
                </section>
            </div>
        </script>

        <script type="module">
            const ROUTES = @js($routes);
            const ZERO = '00000000-0000-0000-0000-000000000000';
            const LIMITS = @js([
                'max_files' => $uploadRequest->max_files,
                'max_bytes' => $uploadRequest->max_bytes_per_file,
                'types'     => $uploadRequest->allowed_types ?? [],
            ]);
            const MESSAGES = @js([
                'queued'       => trans('dam::app.upload-portal.queue.queued'),
                'uploading'    => trans('dam::app.upload-portal.queue.uploading'),
                'finishing'    => trans('dam::app.upload-portal.queue.finishing'),
                'done'         => trans('dam::app.upload-portal.queue.done'),
                'failed'       => trans('dam::app.upload-portal.queue.failed'),
                'cancelled'    => trans('dam::app.upload-portal.queue.cancelled'),
                'summary'      => trans('dam::app.upload-portal.queue.summary'),
                'title'        => trans('dam::app.upload-portal.queue.title'),
                'title-done'   => trans('dam::app.upload-portal.queue.title-done'),
                'empty-file'   => trans('dam::app.upload-portal.errors.empty-file'),
                'too-large'    => trans('dam::app.upload-portal.errors.file-too-large'),
                'wrong-type'   => trans('dam::app.upload-portal.errors.type-not-allowed'),
                'full'         => trans('dam::app.upload-portal.errors.request-full'),
                'folders'      => trans('dam::app.upload-portal.drop.folders-skipped'),
                'generic'      => trans('dam::app.upload-portal.errors.generic'),
                'unavailable'  => trans('dam::app.upload-portal.unavailable.message'),
                'leave'        => trans('dam::app.upload-portal.queue.leave'),
                'until'        => trans('dam::app.upload-portal.limits.until'),
            ]);
            const CONCURRENCY = 2;

            app.component('v-dam-upload-portal', {
                template: '#v-dam-upload-portal-template',

                data() {
                    return {
                        items: [],
                        sent: @js(array_values(array_filter($visitFiles, fn ($file) => $file['state'] === 'completed'))),
                        guestName: '',
                        guestEmail: '',
                        dragging: false,
                        notice: '',
                        nextKey: 1,
                        running: 0,
                    };
                },

                computed: {
                    // "Uploading" only while something is still going up; afterwards the header says it is finished.
                    queueTitle() {
                        return this.items.some(i => ['queued', 'uploading', 'finishing'].includes(i.state)) ? MESSAGES.title : MESSAGES['title-done'];
                    },

                    queueSummary() {
                        const done = this.items.filter(i => i.state === 'done').length;

                        return MESSAGES.summary.replace(':done', done).replace(':total', this.items.filter(i => i.state !== 'cancelled').length);
                    },
                },

                mounted() {
                    // The expiry in the guest's own time zone, as the staff dialog shows it to the requester.
                    const expiry = document.querySelector('[data-dam-portal-expiry]');
                    const expiresAt = expiry ? new Date(expiry.getAttribute('datetime')) : null;

                    if (expiresAt && ! Number.isNaN(expiresAt.getTime())) {
                        expiry.textContent = MESSAGES.until.replace(':date', expiresAt.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }));
                    }

                    window.addEventListener('beforeunload', this.guardLeave = (event) => {
                        if (this.items.some(i => ['queued', 'uploading', 'finishing'].includes(i.state))) {
                            event.preventDefault();
                            event.returnValue = MESSAGES.leave;
                        }
                    });
                },

                unmounted() {
                    window.removeEventListener('beforeunload', this.guardLeave);
                },

                methods: {
                    onPick(event) {
                        this.add([...event.target.files]);
                        event.target.value = '';
                    },

                    onDrop(event) {
                        this.dragging = false;
                        const files = [];
                        let folders = 0;

                        for (const item of [...(event.dataTransfer?.items ?? [])]) {
                            const entry = item.webkitGetAsEntry?.();

                            if (entry?.isDirectory) {
                                folders++;
                                continue;
                            }

                            const file = item.getAsFile?.();
                            if (file) files.push(file);
                        }

                        if (! event.dataTransfer?.items?.length) files.push(...(event.dataTransfer?.files ?? []));

                        this.notice = folders ? MESSAGES.folders : '';
                        this.add(files);
                    },

                    add(files) {
                        // A finished item is already in `sent`: counting both would count each sent file twice.
                        const taken = this.sent.length + this.items.filter(i => ! ['cancelled', 'failed', 'done'].includes(i.state)).length;
                        let room = LIMITS.max_files ? Math.max(0, LIMITS.max_files - taken) : Infinity;

                        for (const file of files) {
                            const item = { key: this.nextKey++, file, name: file.name, size: file.size, state: 'queued', progress: 0, error: '', retryable: false, uuid: null, controller: null };

                            if (file.size === 0) {
                                Object.assign(item, { state: 'failed', error: MESSAGES['empty-file'] });
                            } else if (LIMITS.max_bytes && file.size > LIMITS.max_bytes) {
                                Object.assign(item, { state: 'failed', error: MESSAGES['too-large'] });
                            } else if (! this.typeAllowed(file)) {
                                Object.assign(item, { state: 'failed', error: MESSAGES['wrong-type'] });
                            } else if (room <= 0) {
                                Object.assign(item, { state: 'failed', error: MESSAGES.full });
                            } else {
                                room--;
                            }

                            this.items.push(item);
                        }

                        this.pump();
                    },

                    typeAllowed(file) {
                        if (! LIMITS.types.length) return true;

                        const ext = (file.name.split('.').length > 1 ? file.name.split('.').pop() : '').toLowerCase();
                        const mime = (file.type || '').toLowerCase();

                        return LIMITS.types.some(type => type.includes('/')
                            ? (type.endsWith('/*') ? mime.startsWith(type.slice(0, -1)) : mime === type)
                            : ext === type);
                    },

                    pump() {
                        while (this.running < CONCURRENCY) {
                            const item = this.items.find(i => i.state === 'queued');
                            if (! item) return;

                            this.running++;
                            this.upload(item).finally(() => { this.running--; this.pump(); });
                        }
                    },

                    url(name, uuid, index = 0) {
                        return ROUTES[name].replace(ZERO, uuid).replace(/\/chunks\/0$/, `/chunks/${index}`);
                    },

                    async upload(item) {
                        item.state = 'uploading';
                        item.error = '';
                        item.controller = new AbortController();
                        const signal = item.controller.signal;

                        try {
                            const uploader = await window.loadDamChunkUploader();
                            const fingerprint = await uploader.fingerprintFile(item.file);
                            const { data } = await this.$axios.post(ROUTES.initialize, {
                                original_name: item.file.name,
                                mime_type: item.file.type || null,
                                size: item.file.size,
                                last_modified: item.file.lastModified,
                                fingerprint,
                                guest_name: this.guestName.trim() || null,
                                guest_email: this.guestEmail.trim() || null,
                            }, { signal });
                            const client = new uploader.ChunkUploadClient({
                                http: this.$axios,
                                chunkSize: data.upload.chunk_size,
                                routes: {
                                    store: (id, index) => this.url('store', id, index),
                                    complete: id => this.url('complete', id),
                                    status: id => this.url('status', id),
                                    cancel: id => this.url('cancel', id),
                                },
                                onProgress: ({ acceptedBytes, totalBytes, state }) => {
                                    item.progress = totalBytes ? Math.min(1, acceptedBytes / totalBytes) : 0;
                                    if (state && state !== 'receiving') item.state = 'finishing';
                                },
                            });

                            item.uuid = data.upload.uuid;
                            item.progress = item.size ? data.upload.received_bytes / item.size : 0;

                            await client.uploadMissing({ file: item.file, upload: data.upload, signal });
                            item.state = 'finishing';
                            item.progress = 1;
                            await client.complete({ uploadId: item.uuid, signal });

                            item.state = 'done';
                            this.sent.push({ uuid: item.uuid, name: item.name, state: 'completed' });
                        } catch (error) {
                            if (signal.aborted) {
                                item.state = 'cancelled';

                                return;
                            }

                            const code = error?.response?.data?.code ?? error?.code;
                            const status = Number(error?.response?.status ?? 0);
                            // Only the portal's own refusals (a code and a translated message) are shown; a server
                            // error's text, which may carry debug details, never reaches the guest.
                            // The chunk client reports a 5xx or a dropped connection as network_error and anything
                            // unrecognised as upload_failed, carrying the server's text: both show the generic message.
                            const ownRefusal = status < 500 && code && ! ['network_error', 'upload_failed'].includes(code);
                            const message = ownRefusal ? (error?.response?.data?.message ?? error?.message ?? null) : null;

                            item.state = 'failed';
                            item.retryable = code !== 'request_unavailable' && (error?.retryable === true || code === 'network_error' || code === 'rate_limited' || status >= 500);
                            item.error = code === 'request_unavailable' ? MESSAGES.unavailable : (message || MESSAGES.generic);
                        }
                    },

                    cancel(item) {
                        if (item.state === 'queued') {
                            item.state = 'cancelled';

                            return;
                        }

                        item.controller?.abort();

                        if (item.uuid) this.$axios.delete(this.url('cancel', item.uuid)).catch(() => {});
                    },

                    retry(item) {
                        item.state = 'queued';
                        this.pump();
                    },

                    itemLabel(item) {
                        if (item.state === 'uploading') return `${MESSAGES.uploading} ${Math.round(item.progress * 100)}%`;

                        return MESSAGES[item.state] ?? item.state;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts.anonymous>
