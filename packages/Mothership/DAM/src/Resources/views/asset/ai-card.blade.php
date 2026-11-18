{{--
    The asset page's AI card: the AI description, its open tag suggestions (one at a time or all at once), and a button
    that asks the AI for them when the file has none yet. Emits `tags-changed` after an accept so the Tags box follows.
--}}
@pushOnce('scripts')
<script type="text/x-template" id="v-dam-ai-card-template">
    {{-- The card holds its place while its first reply is out (a loading line), so its arrival does not push the
         Details card down: that late insert was the asset page's largest layout shift (PostHog review 2026-10-27). --}}
    <div v-if="! loaded || card.ai || card.available || eligibility" data-dam-ai-card :data-dam-ai-state="eligibility?.state" :aria-busy="! loaded">
        <x-admin::accordion>
            <x-slot:header>
                <p class="flex items-center gap-2 p-2.5 text-base font-semibold text-gray-800 dark:text-white">
                    @lang('dam::app.admin.dam.asset.edit.ai.title')
                    <span class="rounded bg-violet-100 px-1 py-0.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-900/40 dark:text-violet-200">@lang('dam::app.admin.dam.asset.edit.preview-modal.ai-badge')</span>
                </p>
            </x-slot>

            <x-slot:content class="grid gap-3">
                {{-- A stacked file on show: this card is about that file, named in full; the cover's own card returns with the cover. --}}
                <p v-if="memberFile" class="text-xs font-medium text-gray-600 dark:text-gray-300" style="overflow-wrap:anywhere" data-dam-ai-subject>@{{ labels.subject.replace(':name', memberFile.name) }}</p>
                <p v-if="! loaded" class="text-xs text-gray-500 dark:text-gray-400" data-dam-ai-loading>@lang('dam::app.admin.dam.asset.edit.ai.loading')</p>
                <template v-else-if="! ai?.caption">
                    <p v-if="working" class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400" data-dam-ai-working>
                        <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-violet-400 border-t-transparent" aria-hidden="true"></span>
                        @{{ waitingOnService ? eligibility.message : (stalled ? labels.queued : labels.creating) }}
                    </p>
                    {{-- The server's one reading of this file: unsupported type, waiting for a preview, service down, failed. --}}
                    <p v-else-if="notice" class="text-xs text-red-600" data-dam-ai-notice>@{{ notice }}</p>
                    <p v-else-if="eligibility?.message" class="text-xs text-gray-500 dark:text-gray-400" data-dam-ai-message>@{{ eligibility.message }}</p>
                    <p v-else-if="attempted" class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.edit.ai.failed')</p>
                    <p v-else class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.edit.ai.none')</p>

                    <button
                        v-if="canCreate"
                        type="button"
                        class="secondary-button w-full justify-center"
                        :disabled="busy"
                        data-dam-ai-describe
                        @click="describe"
                    >✨ @{{ eligibility?.state === 'failed' || attempted ? labels.retry : (memberFile ? labels.memberCreate : labels.create) }}</button>
                </template>

                <p v-if="ai?.caption" class="text-sm leading-relaxed text-gray-700 dark:text-gray-200" data-dam-ai-caption>@{{ ai.caption }}</p>

                <div v-if="ai?.tags?.length" class="grid gap-2">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.edit.preview-modal.ai-tags')</h3>
                        <button
                            v-if="canReview"
                            type="button"
                            class="text-xs font-semibold text-primary-600 hover:underline disabled:opacity-50 dark:text-primary-300"
                            :disabled="busy"
                            data-dam-ai-accept-all
                            @click="acceptAll"
                        >@lang('dam::app.admin.dam.asset.edit.ai.accept-all')</button>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="tag in ai.tags" :key="tag.term" class="inline-flex items-center gap-1 rounded-full border border-dashed border-violet-300 px-2.5 py-1 text-xs font-medium text-violet-700 dark:border-violet-700 dark:text-violet-200" data-dam-ai-tag>
                            @{{ tag.label }}
                            <template v-if="canReview">
                                <button type="button" class="hover:text-green-700" :disabled="busy" :aria-label="labels.accept + ' ' + tag.label" :title="labels.accept" data-dam-ai-accept @click="review(tag.term, 'accept')">✓</button>
                                <button type="button" class="hover:text-red-700" :disabled="busy" :aria-label="labels.dismiss + ' ' + tag.label" :title="labels.dismiss" data-dam-ai-dismiss @click="review(tag.term, 'dismiss')">×</button>
                            </template>
                        </span>
                    </div>
                </div>

                <div v-if="ai?.keywords?.length">
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.edit.preview-modal.ai-suggestions')</h3>
                    <p class="text-xs text-gray-600 dark:text-gray-300">@{{ ai.keywords.join(', ') }}</p>
                </div>
            </x-slot>
        </x-admin::accordion>
    </div>
</script>

<script type="module">
    app.component('v-dam-ai-card', {
        mixins: [window._damShownStackFile],
        template: '#v-dam-ai-card-template',
        props: { assetId: { type: [Number, String], required: true } },
        emits: ['tags-changed'],
        data() {
            return {
                card: { ai: null, available: null, eligibility: null, can_review: false },
                loaded: false,
                busy: false,
                working: false,
                attempted: false,
                stalled: false,
                pollTimer: null,
                pollStarted: 0,
                followStarted: 0,
                // A preview wait is on (it may be paused by a hidden tab, with no timer set); `notice` says why a wait ended for good.
                following: false,
                notice: '',
                // Bumped when the page swaps assets or the card unmounts; a reply for an older generation is dropped.
                gen: 0,
                labels: {
                    create: @js(trans('dam::app.admin.dam.asset.edit.ai.create')),
                    creating: @js(trans('dam::app.admin.dam.asset.edit.ai.creating')),
                    queued: @js(trans('dam::app.admin.dam.asset.edit.ai.queued')),
                    retry: @js(trans('dam::app.admin.dam.asset.edit.ai.retry')),
                    accept: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.ai-accept')),
                    dismiss: @js(trans('dam::app.admin.dam.asset.edit.preview-modal.ai-dismiss')),
                    acceptedAll: @js(trans('dam::app.admin.dam.asset.edit.ai.accepted-all')),
                    unavailable: @js(trans('dam::app.admin.dam.asset.edit.ai.unavailable')),
                    gone: @js(trans('dam::app.admin.dam.asset.edit.ai.gone')),
                    noAccess: @js(trans('dam::app.admin.dam.asset.edit.ai.no-access')),
                    subject: @js(trans('dam::app.admin.dam.asset.edit.ai.member.subject')),
                    memberCreate: @js(trans('dam::app.admin.dam.asset.edit.ai.member.create')),
                },
            };
        },
        computed: {
            ai() { return this.card.ai; },
            /** The run is waiting for its next try because the AI service is not answering (the server's reading, not a guess). */
            waitingOnService() { return this.eligibility?.state === 'service_unavailable' && !! this.eligibility?.will_retry; },
            eligibility() { return this.card.eligibility ?? null; },
            /** Only a file the server says can be described (or may be tried once more), for a person who may tag, and not while a run is on. */
            canCreate() { return (!! this.eligibility?.can_generate || !! this.eligibility?.can_retry) && (this.memberFile ? !! this.card.can_review : this.canReview) && ! this.working; },
            /** Describing and reviewing tags change the cover's record, so they wait while another stacked file is on show. */
            canReview() { return !! this.card.can_review && ! this.stackFileShown; },
            /** The stacked file on show (id and full name), or null while the cover is. Its description is its own: saved per file, never the cover's. */
            memberFileId() { return this.shownFile?.id ?? null; },
            memberFile() { return this.shownFile?.id ? { id: this.shownFile.id, name: this.shownFile.name } : null; },
        },
        watch: {
            // Another stacked file, or the cover again: the card asks about that one. reset() orphans every reply still out for the old one.
            memberFileId() { this.follow(); },
            // The details page swaps assets in place (prev/next), so the card follows its id.
            assetId() {
                this.reset();
                this.card = { ai: null, available: false, eligibility: null, can_review: false };
                this.notice = '';
                this.loaded = false;
                this.attempted = false;
                this.load();
            },
        },
        mounted() {
            // A tab in the background does not poll; it asks again as soon as it is shown.
            this.onVisible = () => { if (! document.hidden && this.following && ! this.pollTimer) this.followPreview({ delay: 0 }); };
            document.addEventListener?.('visibilitychange', this.onVisible);
            this.load();
        },
        beforeUnmount() {
            document.removeEventListener?.('visibilitychange', this.onVisible);
            this.reset();
        },
        methods: {
            /** The card's file changed (a member selected, or back to the cover): forget the old one and load the new one. */
            follow() {
                this.reset();
                this.card = { ai: null, available: false, eligibility: null, can_review: false };
                this.notice = '';
                this.loaded = false;
                this.attempted = false;
                this.load();
            },
            url(name) {
                if (this.memberFile && (name === 'show' || name === 'describe')) {
                    return @js(route('admin.dam.assets.ai-tags.member', ['id' => '__id__', 'fileId' => '__file__']))
                        .replace('__id__', this.assetId).replace('__file__', this.memberFile.id) + (name === 'describe' ? '/describe' : '');
                }

                const urls = {
                    show: @js(route('admin.dam.assets.ai-tags.show', '__id__')),
                    describe: @js(route('admin.dam.assets.ai-tags.describe', '__id__')),
                    acceptAll: @js(route('admin.dam.assets.ai-tags.accept-all', '__id__')),
                    accept: @js(route('admin.dam.assets.ai-tags.accept', '__id__')),
                    dismiss: @js(route('admin.dam.assets.ai-tags.dismiss', '__id__')),
                };

                return urls[name].replace('__id__', this.assetId);
            },
            /** Stops polling and orphans every request still in flight. */
            reset() {
                this.gen += 1;
                this.stopPolling();
                this.busy = false;
            },
            load() {
                const gen = this.gen;

                return this.$axios.get(this.url('show')).then(({ data }) => {
                    if (gen !== this.gen) return;
                    this.card = data;
                    this.loaded = true;
                    // A run queued or underway (the upload's own, another tab, the nightly reconcile) is shown as one.
                    if (! data.ai?.caption && ! this.pollTimer && ! this.following) this.followState(data);
                }).catch(() => { if (gen === this.gen) this.loaded = true; });
            },
            /** Whether a run is waiting or going: the eligibility says queued or running, or (older payload) the row does. */
            isActive(data) {
                const state = data?.eligibility?.state;

                // Waiting out a service that is not answering is still a run to follow: it ends in a result or Try again.
                if (state) return state === 'queued' || state === 'running' || (state === 'service_unavailable' && !! data.eligibility.will_retry);

                return data?.ai?.status === 'pending' || data?.ai?.status === 'running';
            },
            /** Whether the file is waiting for its preview (a DNG, HEIC, TIFF or 3D file whose picture a worker is still making). */
            isAwaitingPreview(data) {
                return data?.eligibility?.state === 'awaiting_preview';
            },
            /** Starts whichever wait the state calls for: a run to follow, or a preview to wait for. */
            followState(data) {
                if (this.isActive(data)) this.startPolling();
                else if (this.isAwaitingPreview(data)) this.followPreview();
            },
            /**
             * Waits for a preview quietly (no "Creating…" line: nothing has been asked for yet) and lets the card change on its
             * own: to Create once the preview exists, or into the run when the describe starts by itself. Slower than a run:
             * every 10 s, then every 30 s after two minutes. Ends on the same terms as the run poll: a caption, or any state
             * that is neither a run nor still waiting. Shares the poll timer, so reset() and stopPolling() end it too.
             */
            followPreview({ delay = null } = {}) {
                const gen = this.gen;
                this.following = true;
                this.followStarted = this.followStarted || Date.now();

                // The timer id stays set while a request is out, so a tab shown meanwhile does not start a second wait.
                this.pollTimer = null;

                // Hidden: no timer and no request. The visibility handler picks it up again.
                if (document.hidden) return;

                this.pollTimer = setTimeout(() => {
                    // Went to the background after this was scheduled: ask nothing now, resume when shown.
                    if (document.hidden) {
                        this.pollTimer = null;

                        return;
                    }

                    this.$axios.get(this.url('show')).then(({ data }) => {
                        // Stopped, or the asset changed, while the request was out.
                        if (gen !== this.gen || ! this.following) return;
                        this.card = data;

                        if (data.ai?.caption) {
                            this.stopPolling();
                        } else if (this.isActive(data)) {
                            this.stopPolling();
                            this.startPolling();
                        } else if (this.isAwaitingPreview(data)) {
                            this.followPreview();
                        } else {
                            this.stopPolling();
                        }
                    }).catch(error => {
                        if (gen !== this.gen || ! this.following) return;
                        // A transient failure (network, 5xx) keeps the wait and its backoff.
                        if (! this.gone(error)) this.followPreview();
                    });
                }, delay ?? (Date.now() - this.followStarted < 120000 ? 10000 : 30000));
            },
            /** A reply that says the wait can never end well: the file is gone or the person lost access. Stops and says so. */
            gone(error) {
                const status = error?.response?.status;

                if (status !== 401 && status !== 403 && status !== 404) return false;

                this.stopPolling();
                this.notice = status === 404 ? this.labels.gone : this.labels.noAccess;

                return true;
            },
            describe() {
                // A stacked file on show is described in its own right; every other change here still waits for the cover.
                if (! this.memberFile && ! this.assertCoverShown()) return;
                // The stacked file's description is made inside this request: show the "being created" line meanwhile, not a Create button that did nothing.
                if (this.memberFile) { this.notice = ''; this.working = true; }

                const gen = this.gen;
                this.busy = true;
                // A file the AI found no picture in is tried once more only when a person asks for that.
                const retry = !! this.eligibility?.can_retry && ! this.eligibility?.can_generate;

                this.$axios.post(this.url('describe'), retry ? { retry: true } : {})
                    .then(({ data }) => {
                        if (gen !== this.gen) return;
                        this.card = data;
                        this.attempted = true;
                        // A stacked file is described in the request itself: its answer already carries the saved description.
                        if (! this.memberFile) this.startPolling();
                    })
                    .catch(error => {
                        if (gen !== this.gen) return;
                        // A refusal (this type cannot be described, or its preview is still coming) carries the fresh state.
                        if (error.response?.data?.eligibility) this.card = { ...this.card, ...error.response.data };
                        if (! this.pollTimer && ! this.following && this.isAwaitingPreview(this.card)) this.followPreview();
                        // The stacked file's description failed: nothing was saved, and the card keeps its Try again button.
                        if (this.memberFile) { this.attempted = true; this.notice = error.response?.data?.message || this.labels.unavailable; }
                        this.flash('error', error.response?.data?.message || this.labels.unavailable);
                    })
                    .finally(() => { if (gen === this.gen) { this.busy = false; if (this.memberFile) this.working = false; } });
            },
            startPolling() {
                this.working = true;
                this.stalled = false;
                this.pollStarted = Date.now();
                this.poll();
            },
            poll() {
                const gen = this.gen;
                const elapsed = Date.now() - this.pollStarted;
                // A queued job can sit behind the Library's backlog; after a minute say so and ask less often. Only the
                // job's own terminal state ends the wait: a file that is still being described is not a failure.
                this.stalled = elapsed > 60000;

                this.pollTimer = setTimeout(() => {
                    this.$axios.get(this.url('show')).then(({ data }) => {
                        if (gen !== this.gen || ! this.working) return;
                        this.card = data;
                        // Only the job's own end (a caption, failed, done) or a state that is no longer a run ends the wait.
                        if (data.ai?.caption || ! this.isActive(data)) {
                            this.stopPolling();
                        } else {
                            this.poll();
                        }
                    }).catch(error => { if (gen === this.gen && this.working && ! this.gone(error)) this.poll(); });
                }, elapsed < 60000 ? 4000 : (elapsed < 600000 ? 10000 : 30000));
            },
            stopPolling() {
                clearTimeout(this.pollTimer);
                this.pollTimer = null;
                this.followStarted = 0;
                this.following = false;
                this.working = false;
            },
            review(term, action) {
                if (! this.assertCoverShown()) return;

                const gen = this.gen;
                this.busy = true;
                this.$axios.post(this.url(action), { term })
                    .then(({ data }) => {
                        if (gen !== this.gen) return;
                        this.card = { ...this.card, ai: data.ai };
                        if (action === 'accept') this.$emit('tags-changed', null);
                    })
                    .catch(error => { if (gen === this.gen) this.flash('error', error.response?.data?.message); })
                    .finally(() => { if (gen === this.gen) this.busy = false; });
            },
            acceptAll() {
                if (! this.assertCoverShown()) return;

                const gen = this.gen;
                this.busy = true;
                this.$axios.post(this.url('acceptAll'))
                    .then(({ data }) => {
                        if (gen !== this.gen) return;
                        this.card = { ai: data.ai, available: data.available, eligibility: data.eligibility, can_review: data.can_review };
                        this.$emit('tags-changed', data.tags);
                        this.flash(data.done === data.total ? 'success' : 'warning',
                            this.labels.acceptedAll.replace(':done', data.done).replace(':total', data.total));
                    })
                    .catch(error => { if (gen === this.gen) this.flash('error', error.response?.data?.message); })
                    .finally(() => { if (gen === this.gen) this.busy = false; });
            },
            flash(type, message) {
                if (message) this.$emitter.emit('add-flash', { type, message });
            },
        },
    });
</script>
@endPushOnce
