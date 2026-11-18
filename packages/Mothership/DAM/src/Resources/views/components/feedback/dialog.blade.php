{{--
    Feedback & bug reports: the report dialog. Mounted once by each admin layout,
    outside the header (which the Creative Hub workspace hides), for every signed-in non-API person while
    dam.feedback.enabled is on. It opens from any [data-dam-feedback-open] control, the window event
    `dam:feedback:open` ({ app, appVersion }) and Alt+Shift+B. The z-[10006] wrapper keeps it above the hub pop-out.
--}}
@if ((bool) config('dam.feedback.enabled') && app(\Mothership\DAM\Services\Feedback\FeedbackAccess::class)->canSubmit(auth()->guard('admin')->user()))
@php
    /** @var \Mothership\User\Models\Admin $feedbackAdmin */
    $feedbackAdmin = auth()->guard('admin')->user();
@endphp

<div class="relative z-[10006]">
    <v-dam-feedback></v-dam-feedback>
</div>

@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-feedback-template">
        <div>
            <x-admin::modal ref="modal" type="medium" :viewport-fit="true" :label="trans('dam::app.admin.feedback.ui.title')" v-on:close="onClose">
                <x-slot:header>
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.feedback.ui.title')</h2>
                </x-slot>

                <x-slot:content class="!py-4">
                    <form id="dam-feedback-form" class="grid gap-4" novalidate v-on:submit.prevent="send" data-dam-feedback-dialog>
                        <div class="grid grid-cols-3 gap-1 rounded-lg bg-gray-100 p-1 dark:bg-cherry-800" role="radiogroup" aria-label="@lang('dam::app.admin.feedback.ui.type')">
                            <button
                                v-for="option in types"
                                :key="option.value"
                                type="button"
                                role="radio"
                                :aria-checked="type === option.value ? 'true' : 'false'"
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                                :class="type === option.value ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-900 dark:text-white' : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'"
                                v-on:click="type = option.value"
                                v-text="option.label"
                                :data-feedback-type="option.value"
                            ></button>
                        </div>

                        <div class="grid gap-1.5">
                            <label for="dam-feedback-title" class="text-sm font-medium text-gray-800 dark:text-white">@lang('dam::app.admin.feedback.ui.fieldTitle')</label>
                            <input id="dam-feedback-title" ref="title" v-model="title" type="text" maxlength="160" required autocomplete="off"
                                class="w-full rounded-md border px-3 py-2 text-sm text-gray-800 transition-colors focus:border-gray-400 dark:border-cherry-800 dark:bg-cherry-900 dark:text-white"
                                :aria-invalid="errors.title ? 'true' : 'false'" aria-describedby="dam-feedback-title-error">
                            <p id="dam-feedback-title-error" v-if="errors.title" class="text-xs text-red-600" v-text="errors.title"></p>
                        </div>

                        <div class="grid gap-1.5">
                            <label for="dam-feedback-description" class="text-sm font-medium text-gray-800 dark:text-white">
                                @lang('dam::app.admin.feedback.ui.fieldDescription')
                                <span class="font-normal text-gray-500 dark:text-gray-400">@lang('dam::app.admin.feedback.ui.fieldDescriptionHint')</span>
                            </label>
                            <textarea id="dam-feedback-description" v-model="description" rows="4" maxlength="5000" required
                                class="w-full rounded-md border px-3 py-2 text-sm text-gray-800 transition-colors focus:border-gray-400 dark:border-cherry-800 dark:bg-cherry-900 dark:text-white"
                                :aria-invalid="errors.description ? 'true' : 'false'" aria-describedby="dam-feedback-description-error"></textarea>
                            <p id="dam-feedback-description-error" v-if="errors.description" class="text-xs text-red-600" v-text="errors.description"></p>
                        </div>

                        <div v-if="type === 'bug'" class="grid gap-1.5">
                            <span id="dam-feedback-severity-label" class="text-sm font-medium text-gray-800 dark:text-white">@lang('dam::app.admin.feedback.ui.severity')</span>
                            <div class="flex flex-wrap gap-2" role="radiogroup" aria-labelledby="dam-feedback-severity-label">
                                <button
                                    v-for="option in severities"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="severity === option.value ? 'true' : 'false'"
                                    class="rounded-full border px-3 py-1 text-sm transition-colors"
                                    :class="severity === option.value ? 'border-gray-800 bg-gray-800 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-300 text-gray-700 hover:border-gray-500 dark:border-cherry-700 dark:text-gray-300'"
                                    v-on:click="severity = severity === option.value ? null : option.value"
                                    v-text="option.label"
                                ></button>
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <span class="text-sm font-medium text-gray-800 dark:text-white">
                                @lang('dam::app.admin.feedback.ui.screenshot')
                                <span class="font-normal text-gray-500 dark:text-gray-400">@lang('dam::app.admin.feedback.ui.optional')</span>
                            </span>

                            <div v-if="shot" class="flex gap-3 rounded-lg border p-2.5 dark:border-cherry-800" data-feedback-shot>
                                <button type="button" class="h-[72px] w-[112px] shrink-0 overflow-hidden rounded border bg-gray-100 dark:border-cherry-700 dark:bg-cherry-800" :aria-label="text.viewFull" v-on:click="viewFull = true">
                                    <img :src="shot.url" alt="" class="h-full w-full object-cover object-top">
                                </button>
                                <div class="grid min-w-0 content-start gap-0.5 text-sm">
                                    <b class="truncate font-medium text-gray-800 dark:text-white" v-text="shotLabel"></b>
                                    <span class="text-gray-500 dark:text-gray-400" v-text="shot.origin"></span>
                                    <div class="mt-1 flex flex-wrap gap-3">
                                        <button v-if="canCapture" type="button" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400" v-on:click="capture" v-text="text.retake"></button>
                                        <button type="button" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400" v-on:click="$refs.file.click()" v-text="text.replace"></button>
                                        <button type="button" class="text-sm text-gray-500 hover:underline dark:text-gray-400" v-on:click="discardShot" v-text="text.remove"></button>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-else
                                class="flex flex-wrap items-center gap-2 rounded-lg border border-dashed p-3 transition-colors dark:border-cherry-700"
                                :class="dropping ? 'border-gray-500 bg-gray-50 dark:bg-cherry-800' : 'border-gray-300'"
                                data-feedback-drop
                                v-on:dragover.prevent="dropping = true"
                                v-on:dragleave="dropping = false"
                                v-on:drop.prevent="onDrop"
                            >
                                <button v-if="canCapture" type="button" class="secondary-button" v-on:click="capture" data-feedback-capture v-text="text.capture"></button>
                                <button type="button" class="secondary-button" v-on:click="$refs.file.click()" v-text="text.upload"></button>
                                <span class="text-xs text-gray-500 dark:text-gray-400" v-text="text.pasteHint"></span>
                            </div>
                            <input ref="file" type="file" class="hidden" accept="image/png,image/jpeg,image/webp" v-on:change="onFile" data-feedback-file>
                            <p v-if="errors.screenshot" class="text-xs text-red-600" v-text="errors.screenshot"></p>
                        </div>

                        <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-cherry-800" data-feedback-context>
                            <div class="flex items-center justify-between">
                                <b class="font-medium text-gray-800 dark:text-white">@lang('dam::app.admin.feedback.ui.sentWith')</b>
                                <button type="button" class="text-xs text-gray-500 hover:underline dark:text-gray-400" :aria-expanded="showContext ? 'true' : 'false'" v-on:click="showContext = ! showContext" v-text="showContext ? text.hide : text.show"></button>
                            </div>
                            <dl v-if="showContext" class="mt-2 grid grid-cols-[96px_minmax(0,1fr)] gap-x-3 gap-y-1 text-[13px]">
                                <template v-for="row in contextRows" :key="row.label">
                                    <dt class="text-gray-500 dark:text-gray-400" v-text="row.label"></dt>
                                    <dd class="break-words text-gray-800 dark:text-gray-200" v-text="row.value"></dd>
                                </template>
                                <dt class="text-gray-500 dark:text-gray-400">@lang('dam::app.admin.feedback.ui.ctxConsole')</dt>
                                <dd class="flex flex-wrap items-center gap-x-3 text-gray-800 dark:text-gray-200">
                                    <span v-if="! consoleLines.length" v-text="text.consoleNone"></span>
                                    <template v-else>
                                        <span :class="sendConsole ? 'text-red-700 dark:text-red-300' : 'text-gray-400 line-through'" v-text="consoleSummary"></span>
                                        <button type="button" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400" :aria-expanded="showConsole ? 'true' : 'false'" v-on:click="showConsole = ! showConsole" v-text="showConsole ? text.hide : text.view"></button>
                                        <button type="button" class="text-xs text-gray-500 hover:underline dark:text-gray-400" v-on:click="sendConsole = ! sendConsole" data-feedback-console-toggle v-text="sendConsole ? text.dontSend : text.send"></button>
                                    </template>
                                </dd>
                            </dl>
                            <ol v-if="showContext && showConsole && consoleLines.length" class="mt-2 grid max-h-32 gap-1 overflow-y-auto rounded bg-white p-2 font-mono text-[11px] text-gray-700 dark:bg-cherry-900 dark:text-gray-300">
                                <li v-for="(line, index) in consoleLines" :key="index" class="break-all" v-text="line.kind + ': ' + line.message"></li>
                            </ol>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.feedback.ui.redactNote')</p>
                        </div>

                        <p v-if="errors.form" class="text-sm text-red-600" role="alert" v-text="errors.form"></p>
                    </form>
                </x-slot>

                <x-slot:footer class="!justify-between items-center gap-3">
                    <span class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.feedback.ui.visibility')</span>
                    <span class="flex gap-2">
                        <button type="button" class="transparent-button" v-on:click="$refs.modal.close()">@lang('dam::app.admin.feedback.ui.cancel')</button>
                        <button type="submit" form="dam-feedback-form" class="primary-button" :disabled="busy || processing" data-feedback-send v-text="busy ? text.sending : text.sendReport"></button>
                    </span>
                </x-slot>
            </x-admin::modal>

            <div v-if="viewFull && shot" class="fixed inset-0 z-[10010] flex items-center justify-center bg-black/80 p-6" role="dialog" aria-modal="true" :aria-label="text.viewFull" v-on:click.self="viewFull = false" v-on:keydown.esc.stop="viewFull = false" tabindex="-1" ref="full">
                <img :src="shot.url" alt="" class="max-h-full max-w-full rounded shadow-lg">
                <button type="button" class="absolute right-4 top-4 rounded-md bg-white/90 px-3 py-1 text-sm font-medium text-gray-900" v-on:click="viewFull = false" v-text="text.close"></button>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-dam-feedback', {
            template: '#v-dam-feedback-template',

            data() {
                return {
                    config: @js([
                        'storeUrl'  => route('admin.dam.feedback.store'),
                        'mineUrl'   => route('admin.dam.feedback.mine'),
                        'routeName' => request()->route()?->getName(),
                        'build'     => \Mothership\DAM\Services\Feedback\FeedbackLog::build(),
                        'you'       => $feedbackAdmin->name.' (#'.$feedbackAdmin->id.')',
                        'maxBytes'  => (int) config('dam.feedback.screenshot_max_bytes'),
                    ]),
                    text: @js(trans('dam::app.admin.feedback.ui')),
                    types: [
                        { value: 'bug', label: @js(trans('dam::app.admin.feedback.ui.typeBug')) },
                        { value: 'feedback', label: @js(trans('dam::app.admin.feedback.ui.typeFeedback')) },
                        { value: 'idea', label: @js(trans('dam::app.admin.feedback.ui.typeIdea')) },
                    ],
                    severities: [
                        { value: 'minor', label: @js(trans('dam::app.admin.feedback.ui.severityMinor')) },
                        { value: 'annoying', label: @js(trans('dam::app.admin.feedback.ui.severityAnnoying')) },
                        { value: 'blocking', label: @js(trans('dam::app.admin.feedback.ui.severityBlocking')) },
                    ],
                    type: 'bug',
                    severity: null,
                    title: '',
                    description: '',
                    shot: null,
                    hubApp: null,
                    hubAppVersion: null,
                    context: null,
                    consoleLines: [],
                    sendConsole: true,
                    showContext: true,
                    showConsole: false,
                    dropping: false,
                    viewFull: false,
                    busy: false,
                    // A screenshot being decoded or encoded; shotSeq tells a stale one (after a reset, Remove or a newer pick) to drop.
                    processing: false,
                    shotSeq: 0,
                    capturing: false,
                    errors: {},
                };
            },

            computed: {
                canCapture() {
                    return Boolean(navigator.mediaDevices?.getDisplayMedia);
                },

                shotLabel() {
                    if (! this.shot) return '';
                    const kb = Math.max(1, Math.round(this.shot.blob.size / 1024));

                    return `${this.shot.width} × ${this.shot.height} ${this.shot.blob.type.replace('image/', '').toUpperCase()}, ${kb >= 1024 ? (kb / 1024).toFixed(1) + ' MB' : kb + ' KB'}`;
                },

                consoleSummary() {
                    return this.fill(this.consoleLines.length === 1 ? this.text.consoleOne : this.text.consoleMany, { count: this.consoleLines.length });
                },

                contextRows() {
                    const c = this.context || {};
                    const rows = [
                        { label: this.text.ctxPage, value: this.pageUrl || '—' },
                        { label: this.text.ctxWhere, value: this.hubApp ? `${this.hubApp}${this.hubAppVersion ? ' ' + this.hubAppVersion : ''}` : (this.config.routeName || '—') },
                        { label: this.text.ctxBrowser, value: [c.browser, c.os, c.viewport ? `${c.viewport} @${c.dpr}x` : null].filter(Boolean).join(' · ') },
                        { label: this.text.ctxVersion, value: String(this.config.build).slice(0, 12) },
                        { label: this.text.ctxYou, value: `${this.config.you} · ${new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}` },
                    ];

                    return rows;
                },

                pageUrl() {
                    return window.MothershipFeedback?.redactUrl(window.location.href) ?? window.location.pathname;
                },
            },

            mounted() {
                this._onClick = (event) => {
                    const opener = event.target.closest?.('[data-dam-feedback-open]');
                    if (! opener) return;
                    event.preventDefault();
                    this.open({ app: opener.dataset.feedbackApp || null, appVersion: opener.dataset.feedbackAppVersion || null });
                };
                this._onEvent = (event) => this.open(event.detail || {});
                this._onKey = (event) => {
                    if (! event.altKey || ! event.shiftKey || event.ctrlKey || event.metaKey || event.code !== 'KeyB') return;
                    const target = event.target;
                    if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
                    event.preventDefault();
                    this.open({});
                };
                this._onPaste = (event) => {
                    if (! this.isOpen()) return;
                    const item = [...(event.clipboardData?.items || [])].find((entry) => entry.kind === 'file' && entry.type.startsWith('image/'));
                    if (! item) return;
                    event.preventDefault();
                    this.takeFile(item.getAsFile(), this.text.originPasted);
                };

                document.addEventListener('click', this._onClick);
                window.addEventListener('dam:feedback:open', this._onEvent);
                window.addEventListener('keydown', this._onKey);
                window.addEventListener('paste', this._onPaste);
            },

            beforeUnmount() {
                document.removeEventListener('click', this._onClick);
                window.removeEventListener('dam:feedback:open', this._onEvent);
                window.removeEventListener('keydown', this._onKey);
                window.removeEventListener('paste', this._onPaste);
                this.discardShot();
            },

            methods: {
                fill(template, values) {
                    return String(template).replace(/:(\w+)/g, (match, key) => (key in values ? values[key] : match));
                },

                isOpen() {
                    return Boolean(this.$refs.modal?.isOpen);
                },

                async open({ app = null, appVersion = null } = {}) {
                    if (this.isOpen()) return;
                    // The dialog lives outside the Creative Hub workspace, so it would be invisible in its full screen.
                    if (document.fullscreenElement) {
                        try { await document.exitFullscreen(); } catch (_) { /* already left */ }
                    }
                    // The shortcut inside the Creative Hub names the open Craft app, like its bar button does.
                    const hubButton = app === null ? document.querySelector('[data-dam-feedback-open][data-feedback-app]') : null;
                    app = app ?? hubButton?.dataset.feedbackApp ?? null;
                    appVersion = appVersion ?? hubButton?.dataset.feedbackAppVersion ?? null;
                    this.hubApp = app !== null && /^[a-z0-9][a-z0-9_-]*$/.test(String(app)) ? String(app) : null;
                    this.hubAppVersion = this.hubApp && appVersion ? String(appVersion).slice(0, 40) : null;
                    this.refreshContext();
                    this.errors = {};
                    this.$refs.modal.open();
                    this.$nextTick(() => this.$refs.title?.focus());
                },

                onClose() {
                    if (this.capturing) return;
                    this.viewFull = false;
                    this.dropping = false;
                },

                refreshContext() {
                    const helper = window.MothershipFeedback;
                    this.context = helper ? helper.collectContext(window, { includeConsole: false }) : {};
                    this.consoleLines = helper && window.__damFeedbackConsole ? window.__damFeedbackConsole.lines() : [];
                },

                reset() {
                    this.type = 'bug';
                    this.severity = null;
                    this.title = '';
                    this.description = '';
                    this.sendConsole = true;
                    this.showConsole = false;
                    this.errors = {};
                    this.discardShot();
                },

                discardShot() {
                    this.shotSeq += 1;
                    this.processing = false;
                    this.clearShot();
                },

                clearShot() {
                    if (this.shot) URL.revokeObjectURL(this.shot.url);
                    this.shot = null;
                    if (this.$refs.file) this.$refs.file.value = '';
                },

                async capture() {
                    this.errors = { ...this.errors, screenshot: null };
                    const seq = ++this.shotSeq;
                    this.capturing = true;
                    this.$refs.modal.close();
                    let stream = null;
                    try {
                        await new Promise((resolve) => setTimeout(resolve, 180));
                        stream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false, preferCurrentTab: true, selfBrowserSurface: 'include' });
                        const video = document.createElement('video');
                        video.muted = true;
                        video.playsInline = true;
                        video.srcObject = stream;
                        await video.play();
                        // A couple of frames so the picker's own overlay has gone.
                        await new Promise((resolve) => setTimeout(resolve, 250));
                        const canvas = document.createElement('canvas');
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        canvas.getContext('2d').drawImage(video, 0, 0);
                        this.processing = true;
                        await this.takeCanvas(canvas, this.text.originCaptured, seq);
                    } catch (error) {
                        if (seq === this.shotSeq && error?.name !== 'NotAllowedError' && error?.name !== 'AbortError') {
                            this.errors = { ...this.errors, screenshot: this.text.captureFailed };
                        }
                    } finally {
                        stream?.getTracks().forEach((track) => track.stop());
                        if (seq === this.shotSeq) this.processing = false;
                        this.capturing = false;
                        this.$refs.modal.open();
                    }
                },

                onFile(event) {
                    const file = event.target.files?.[0];
                    if (file) this.takeFile(file, this.text.originUploaded);
                },

                onDrop(event) {
                    this.dropping = false;
                    const file = [...(event.dataTransfer?.files || [])].find((entry) => entry.type.startsWith('image/'));
                    if (file) this.takeFile(file, this.text.originDropped);
                },

                async takeFile(file, origin) {
                    this.errors = { ...this.errors, screenshot: null };
                    if (! ['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > this.config.maxBytes) {
                        this.errors = { ...this.errors, screenshot: this.text.badImage };
                        return;
                    }
                    const seq = ++this.shotSeq;
                    this.processing = true;
                    try {
                        const bitmap = await createImageBitmap(file);
                        const canvas = document.createElement('canvas');
                        canvas.width = bitmap.width;
                        canvas.height = bitmap.height;
                        canvas.getContext('2d').drawImage(bitmap, 0, 0);
                        bitmap.close?.();
                        await this.takeCanvas(canvas, origin, seq);
                    } catch (_) {
                        if (seq === this.shotSeq) this.errors = { ...this.errors, screenshot: this.text.badImage };
                    } finally {
                        if (seq === this.shotSeq) this.processing = false;
                    }
                },

                // Re-encodes to at most 2560 px on the long edge (which also drops any metadata), WebP where the browser
                // can write it, else PNG, else JPEG when PNG would pass the size cap.
                async takeCanvas(source, origin, seq) {
                    const scale = Math.min(1, 2560 / Math.max(source.width, source.height));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.round(source.width * scale));
                    canvas.height = Math.max(1, Math.round(source.height * scale));
                    canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);

                    const encode = (type, quality) => new Promise((resolve) => canvas.toBlob(resolve, type, quality));
                    let blob = await encode('image/webp', 0.9);
                    if (! blob || blob.type !== 'image/webp') blob = await encode('image/png');
                    if (! blob || blob.size > this.config.maxBytes) blob = await encode('image/jpeg', 0.85);
                    if (seq !== this.shotSeq) return;
                    if (! blob || blob.size > this.config.maxBytes) {
                        this.errors = { ...this.errors, screenshot: this.text.badImage };
                        return;
                    }

                    this.clearShot();
                    this.shot = { blob, url: URL.createObjectURL(blob), width: canvas.width, height: canvas.height, origin };
                },

                async send() {
                    if (this.busy || this.processing) return;
                    this.errors = {};
                    if (! this.title.trim()) this.errors.title = this.text.required;
                    if (! this.description.trim()) this.errors.description = this.text.required;
                    if (this.errors.title || this.errors.description) return;

                    const context = { ...(this.context || {}), console: this.sendConsole ? this.consoleLines : [] };
                    if (this.hubAppVersion) context.app_version = this.hubAppVersion;

                    const body = new FormData();
                    body.append('type', this.type);
                    if (this.type === 'bug' && this.severity) body.append('severity', this.severity);
                    body.append('title', this.title.trim());
                    body.append('description', this.description.trim());
                    body.append('page_url', this.pageUrl);
                    if (this.config.routeName) body.append('route_name', this.config.routeName);
                    if (this.hubApp) body.append('hub_app', this.hubApp);
                    body.append('context', JSON.stringify(context));
                    if (this.shot) body.append('screenshot', this.shot.blob, `screenshot.${this.shot.blob.type.split('/')[1]}`);

                    this.busy = true;
                    try {
                        const { data } = await this.$axios.post(this.config.storeUrl, body, { headers: { Accept: 'application/json' } });
                        this.$emitter.emit('add-flash', { type: 'success', message: `${data.message} ${this.text.trackIt}` });
                        this.reset();
                        this.$refs.modal.close();
                    } catch (error) {
                        const status = error.response?.status;
                        const fields = error.response?.data?.errors || {};
                        for (const key of ['title', 'description', 'screenshot']) {
                            if (fields[key]) this.errors[key] = [].concat(fields[key])[0];
                        }
                        const other = Object.entries(fields).filter(([key]) => ! ['title', 'description', 'screenshot'].includes(key)).map(([, value]) => [].concat(value)[0]);
                        if (status === 429) this.errors.form = this.text.tooMany;
                        else if (status === 413) this.errors.form = this.text.tooLarge;
                        else if (other.length) this.errors.form = other.join(' ');
                        else if (status !== 422) this.errors.form = this.text.failed;
                    } finally {
                        this.busy = false;
                    }
                },
            },

            watch: {
                viewFull(value) {
                    if (value) this.$nextTick(() => this.$refs.full?.focus());
                },
            },
        });
    </script>
@endPushOnce
@endif
