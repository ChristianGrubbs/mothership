@props(['canEdit' => false])

<v-dam-stack-suggestions :can-edit="@js((bool) $canEdit)"></v-dam-stack-suggestions>

@once('v-dam-stack-suggestions')
@push('scripts')
{{-- P3b-2 (D5 suggestions): groups that look like one stack. Nothing changes until the viewer picks "Stack them", which
     opens the same Create stack review a selection does (nothing is stacked until it is confirmed there), or "Dismiss"
     (hidden for this viewer only). --}}
<script type="text/x-template" id="v-dam-stack-suggestions-template">
    <div class="flex flex-col gap-2" data-dam-stack-suggestions-list>
        <div v-if="loadState === 'idle' || loadState === 'failed'">
            <p v-if="loadState === 'failed'" class="text-xs text-red-600 dark:text-red-400 px-1 py-2" role="alert">@lang('dam::app.admin.dam.suggestions.load-failed')</p>
            <button type="button" class="secondary-button max-[639px]:!h-11" v-on:click="load">
                @lang('dam::app.admin.dam.suggestions.show')
            </button>
        </div>
        <p v-else-if="loadState === 'loading'" class="text-xs text-gray-400 dark:text-gray-500 px-1 py-2" role="status">@lang('dam::app.admin.dam.suggestions.loading')</p>
        <p v-else-if="! groups.length" class="text-xs text-gray-400 dark:text-gray-500 text-center px-2 py-3">@lang('dam::app.admin.dam.suggestions.empty')</p>

        <div v-else class="flex flex-col gap-2 max-h-[28rem] overflow-y-auto pr-1">
            <section
                v-for="group in groups"
                :key="group.kind + ':' + group.key"
                data-dam-stack-suggestion
                :data-kind="group.kind"
                class="rounded-md border border-gray-200 dark:border-cherry-800 p-2.5"
            >
                <h3 class="text-sm font-semibold text-zinc-800 dark:text-slate-50 break-words">@{{ group.title }}</h3>
                <ul class="mt-1.5 flex flex-col gap-0.5">
                    <li v-for="member in group.members" :key="member.id" class="min-w-0">
                        {{-- Shared head and tail give way first; the part that tells the files apart stays whole (see nameParts). --}}
                        <a :href="openUrl(member.id)" class="flex min-w-0 text-xs text-primary-700 hover:underline dark:text-primary-300 max-[639px]:min-h-11 max-[639px]:items-center" :title="member.name" :aria-label="member.name" data-dam-stack-suggestion-member>
                            <span v-if="nameParts(group, member).head" class="min-w-0 shrink truncate opacity-75">@{{ nameParts(group, member).head }}</span>
                            <span :class="nameParts(group, member).head || nameParts(group, member).tail ? 'max-w-full shrink-0 font-semibold' : 'min-w-0 truncate'">@{{ nameParts(group, member).mid }}</span>
                            <span v-if="nameParts(group, member).tail" class="truncate opacity-75" :class="nameParts(group, member).fixed ? 'shrink-0' : 'min-w-0 shrink'">@{{ nameParts(group, member).tail }}</span>
                        </a>
                    </li>
                    <li v-if="group.count > group.members.length" class="text-xs text-gray-500 dark:text-gray-400">@{{ more(group) }}</li>
                </ul>

                <div class="mt-2 flex flex-wrap gap-2">
                    <button v-if="canEdit" type="button" class="secondary-button !h-8 max-[639px]:!h-11 !px-2.5 !py-1 text-xs" @click="edit(group)">@lang('dam::app.admin.dam.suggestions.stack-them')</button>
                    <button type="button" class="transparent-button !h-8 max-[639px]:!h-11 !px-2.5 !py-1 text-xs" :disabled="busy" @click="dismiss(group)">@lang('dam::app.admin.dam.suggestions.dismiss')</button>
                </div>
            </section>
        </div>
    </div>
</script>

<script type="module">
app.component('v-dam-stack-suggestions', {
    template: '#v-dam-stack-suggestions-template',

    props: {
        canEdit: { type: Boolean, default: false },
    },

    data() {
        return {
            groups: [],
            loadState: 'idle',
            busy: false,
        };
    },

    mounted() {
        // The review answers when it stacks the group: the suggestion is done, and the listings show the new stack.
        this._onMergeDone = ({ context, reviewId } = {}) => {
            if (context !== 'suggestions') return;
            this.groups = this.groups.filter(g => `${g.kind}:${g.key}` !== reviewId);
            this.$emitter.emit('dam:uploads-refresh', {});
        };
        this.$emitter.on('dam:merge-done', this._onMergeDone);
        // A review closed without stacking (cancelled, or refused because the group changed) leaves what the panel showed possibly stale: show what is there now.
        this._onMergeClosed = ({ reviewId, merged } = {}) => {
            if (! merged && this.groups.some(g => g.kind === 'near_group' && `${g.kind}:${g.key}` === reviewId)) this.load();
        };
        this.$emitter.on('dam:merge-dialog-closed', this._onMergeClosed);
    },

    beforeUnmount() {
        this._suggestionsRequest?.abort();
        this.$emitter.off('dam:merge-done', this._onMergeDone);
        this.$emitter.off('dam:merge-dialog-closed', this._onMergeClosed);
    },

    methods: {
        async load() {
            if (this.loadState === 'loading') return;

            this.loadState = 'loading';
            const controller = new AbortController();
            this._suggestionsRequest = controller;
            try {
                const { data } = await this.$axios.get(@js(route('admin.dam.stack-suggestions.index')), { signal: controller.signal });
                if (controller.signal.aborted) return;
                this.groups = data.suggestions ?? [];
                this.loadState = 'ready';
            } catch {
                if (controller.signal.aborted) return;
                this.groups = [];
                this.loadState = 'failed';
            } finally {
                this._suggestionsRequest = null;
            }
        },

        openUrl(id) {
            return @js(route('admin.dam.assets.open', ['id' => '__ID__'])).replace('__ID__', encodeURIComponent(id));
        },

        /** A member name split into what the group's names share at the start, what tells it apart, and what they share at the end. */
        nameParts(group, member) {
            this._nameParts ??= new WeakMap();
            let parts = this._nameParts.get(group);

            if (! parts) {
                const list = group.entries ?? group.members;
                const names = list.map(item => String(item.name));
                let head = names[0];
                let tail = names[0];

                names.forEach(name => {
                    while (! name.startsWith(head)) head = head.slice(0, -1);
                    while (! name.endsWith(tail)) tail = tail.slice(1);
                });

                head = head.slice(0, Math.max(...['-', '_', '.', ' '].map(mark => head.lastIndexOf(mark))) + 1);
                const cut = tail.search(/[-_. ]/);
                tail = cut < 0 ? '' : tail.slice(cut);
                const split = names.every(name => name.length > head.length + tail.length);

                parts = new Map(list.map(item => {
                    const name = String(item.name);

                    return [item.id, split ? { head, mid: name.slice(head.length, name.length - tail.length), tail, fixed: tail.length <= 8 } : { head: '', mid: name, tail: '', fixed: false }];
                }));
                this._nameParts.set(group, parts);
            }

            return parts.get(member.id) ?? { head: '', mid: String(member.name), tail: '', fixed: false };
        },

        more(group) {
            return @js(trans('dam::app.admin.dam.suggestions.more')).replace(':count', group.count - group.members.length);
        },

        /** Opens the Create stack review on the group's files; stacking happens only once it is confirmed there. */
        edit(group) {
            this.$emitter.emit('dam:open-merge-dialog', {
                assets: (group.entries ?? group.members).map(entry => ({ id: entry.id, name: entry.name, extension: entry.extension || '' })),
                context: 'suggestions',
                reviewId: `${group.kind}:${group.key}`,
                // A near-duplicate group of three or more is confirmed only while it is still the set shown (the server answers 409 otherwise).
                review: group.kind === 'near_group' ? { group_key: group.key, members: (group.entries ?? group.members).map(entry => entry.id) } : undefined,
            });
        },

        remove(group) {
            this.groups = this.groups.filter(g => g !== group);
        },

        async dismiss(group) {
            this.busy = true;
            try {
                // A near-duplicate group names the files it was reviewed with; the server dismisses only that set and refuses a changed one.
                const members = group.kind === 'near_group' ? (group.entries ?? group.members).map(entry => entry.id) : undefined;
                await this.$axios.post(@js(route('admin.dam.stack-suggestions.dismiss')), { kind: group.kind, group_key: group.key, members });
                this.remove(group);
            } catch (e) {
                this.$emitter.emit('add-flash', { type: 'error', message: e.response?.data?.message || @js(trans('dam::app.admin.dam.suggestions.failed')) });
                // A changed group is no longer what was shown: reload what is there now.
                if (e.response?.status === 409) this.load();
            } finally {
                this.busy = false;
            }
        },
    },
});
</script>
@endpush
@endonce
