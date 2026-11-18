<x-admin::layouts>
    <x-slot:title>{{ $collection->name }}</x-slot>

    <v-dam-collection></v-dam-collection>

    @if ($manages)
        @include('dam::collections.form')
    @endif

    @pushOnce('scripts')
    <script type="text/x-template" id="v-dam-collection-template">
        <div class="mx-auto max-w-6xl pb-10">
            <x-admin::breadcrumbs :leaf="$collection->name" :parents="[['label' => trans('dam::app.admin.dam.collections.title'), 'url' => route('admin.dam.collections.index')]]" />

            <div v-if="loading" class="mt-4 space-y-4" data-collection-loading>
                <div class="h-8 w-1/3 animate-pulse rounded bg-gray-200 dark:bg-cherry-800"></div>
                <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4"><div v-for="n in 4" :key="n" class="aspect-square animate-pulse rounded-lg bg-gray-100 dark:bg-cherry-800"></div></div>
            </div>

            <x-admin::load-error
                v-else-if="error"
                class="mt-6"
                :title="trans('dam::app.admin.dam.collections.load-one-failed')"
                message="error.message"
                retryable="error.retryable"
                retry="load()"
            />

            <template v-else>
                <header class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="break-words text-2xl font-semibold text-gray-800 dark:text-white" data-collection-title>@{{ collection.name }}</h1>
                        <p v-if="collection.description" class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-slate-300">@{{ collection.description }}</p>
                        <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            <span class="rounded-full px-2 py-0.5 font-semibold" :class="collection.audience === 'public' ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200' : 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-200'">@{{ audienceLabel }}</span>
                            <span v-if="collection.state !== 'active'" class="rounded-full bg-gray-200 px-2 py-0.5 font-semibold text-gray-700 dark:bg-cherry-800 dark:text-slate-300" data-collection-state>@{{ stateLabel }}</span>
                            <span class="text-gray-600 dark:text-slate-300">@{{ countLabel(collection.can_manage && collection.state !== 'active' ? collection.total_members : collection.member_count) }}</span>
                            <span v-if="collection.expires_at" class="text-gray-500 dark:text-slate-400">· @{{ expiresLabel }}</span>
                            <span v-if="collection.owner && ! collection.can_manage" class="text-gray-500 dark:text-slate-400">· @{{ sharedByLabel }}</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <a v-if="collection.member_count > 0 && collection.state === 'active'" :href="collection.zip_url" data-dam-download :data-dam-download-name="collection.name" :data-dam-download-thumb="members[0]?.thumbnail_url" data-dam-download-folder class="secondary-button" data-collection-zip>
                            @lang('dam::app.admin.dam.collections.show.download-all')
                        </a>
                        <button v-if="collection.can_manage" type="button" class="primary-button" data-collection-edit :aria-expanded="editing ? 'true' : 'false'" @click="editing = ! editing">
                            @lang('dam::app.admin.dam.collections.show.edit')
                        </button>
                    </div>
                </header>

                <div v-if="editing" class="mt-6">
                    <v-dam-collection-form :initial="collection" @saved="onSaved" @cancel="editing = false" @deleted="onDeleted"></v-dam-collection-form>
                </div>

                <p v-if="collection.can_manage && collection.state !== 'active'" class="mt-4 rounded bg-gray-100 p-3 text-sm text-gray-700 dark:bg-cherry-800 dark:text-slate-200" data-collection-closed-note>
                    @lang('dam::app.admin.dam.collections.show.closed-note')
                </p>

                <div class="mt-6 grid grid-cols-1 gap-6" :class="collection.can_manage ? 'lg:grid-cols-[minmax(0,1fr)_20rem]' : ''">
                    <section class="min-w-0" :aria-label="@js(trans('dam::app.admin.dam.collections.show.members'))" data-collection-members>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.collections.show.members')</h2>
                            <p v-if="collection.can_manage && members.length > 1" class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.show.reorder-hint')</p>
                        </div>
                        <p class="sr-only" aria-live="polite" data-collection-announce>@{{ announcement }}</p>

                        <p v-if="! members.length" class="mt-3 rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-cherry-700 dark:text-slate-400">
                            @{{ collection.can_manage ? @js(trans('dam::app.admin.dam.collections.show.empty-manager')) : @js(trans('dam::app.admin.dam.collections.show.empty')) }}
                        </p>

                        <ol v-else class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                            <li
                                v-for="(member, index) in members"
                                :key="member.id"
                                :data-collection-member="member.id"
                                :data-servable="member.servable ? 'true' : 'false'"
                                class="flex min-w-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-cherry-800 dark:bg-cherry-900"
                            >
                                <button
                                    v-if="member.servable"
                                    type="button"
                                    class="relative block aspect-square w-full bg-gray-50 dark:bg-cherry-800"
                                    :aria-label="openLabel(member)"
                                    @click="open(index)"
                                >
                                    <img :src="member.thumbnail_url" alt="" loading="lazy" class="h-full w-full object-contain">
                                    <span v-if="collection.can_manage" class="absolute left-2 top-2 rounded bg-white/90 px-1.5 text-xs font-semibold text-gray-700 dark:bg-cherry-900/90 dark:text-slate-200">@{{ index + 1 }}</span>
                                </button>
                                <div v-else class="relative flex aspect-square w-full items-center justify-center bg-gray-100 p-3 text-center text-xs text-gray-500 dark:bg-cherry-800 dark:text-slate-400">
                                    @{{ member.reason === 'collection_closed' ? @js(trans('dam::app.admin.dam.collections.show.collection-closed')) : @js(trans('dam::app.admin.dam.collections.show.not-served')) }}
                                    <span class="absolute left-2 top-2 rounded bg-white/90 px-1.5 text-xs font-semibold text-gray-700 dark:bg-cherry-900/90 dark:text-slate-200">@{{ index + 1 }}</span>
                                </div>

                                <div class="flex min-w-0 flex-1 flex-col gap-2 p-2">
                                    <p class="truncate text-sm font-medium text-gray-800 dark:text-white" :title="member.name || ''">@{{ member.name || @js(trans('dam::app.admin.dam.collections.show.unnamed')) }}</p>
                                    <div class="mt-auto flex flex-wrap items-center gap-1">
                                        <a v-if="member.servable && collection.state === 'active'" :href="member.download_url" data-dam-download :data-dam-download-name="member.name" :data-dam-download-thumb="member.thumbnail_url" class="rounded px-1.5 py-1 text-xs text-primary-700 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-cherry-800" :aria-label="downloadLabel(member)">
                                            @lang('dam::app.admin.dam.collections.show.download')
                                        </a>
                                        <template v-if="collection.can_manage">
                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-gray-700 hover:bg-gray-100 disabled:opacity-40 dark:text-slate-200 dark:hover:bg-cherry-800" :disabled="busy || index === 0" :aria-label="moveLabel('up', member)" :ref="el => setRef('up', member.id, el)" data-collection-move-up @click="move(index, -1, 'up')">↑</button>
                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-gray-700 hover:bg-gray-100 disabled:opacity-40 dark:text-slate-200 dark:hover:bg-cherry-800" :disabled="busy || index === members.length - 1" :aria-label="moveLabel('down', member)" :ref="el => setRef('down', member.id, el)" data-collection-move-down @click="move(index, 1, 'down')">↓</button>
                                            <button type="button" class="ml-auto rounded px-1.5 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-40 dark:hover:bg-cherry-800" :disabled="busy" :aria-label="removeLabel(member)" data-collection-remove @click="remove(member)">
                                                @lang('dam::app.admin.dam.collections.show.remove')
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </li>
                        </ol>
                    </section>

                    <aside v-if="collection.can_manage" class="flex flex-col gap-4" data-collection-sharing>
                        <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-cherry-800 dark:bg-cherry-900">
                            <h2 class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.collections.show.who')</h2>
                            <template v-if="collection.audience === 'internal'">
                                <ul v-if="principals.length" class="mt-2 flex flex-wrap gap-2" data-collection-audience-list>
                                    <li v-for="p in principals" :key="p.key" class="rounded-full bg-gray-100 px-3 py-0.5 text-sm text-gray-700 dark:bg-cherry-800 dark:text-slate-200">@{{ p.name }}</li>
                                </ul>
                                <p v-else class="mt-2 text-sm text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.show.only-you')</p>
                            </template>
                            <p v-else class="mt-2 text-sm text-gray-600 dark:text-slate-300">@lang('dam::app.admin.dam.collections.audience.public-help')</p>

                            <div v-if="collection.slug_url" class="mt-4" data-collection-slug-url>
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.show.address')</p>
                                <div class="mt-1 flex items-center gap-2">
                                    <code class="min-w-0 flex-1 truncate rounded bg-gray-50 px-2 py-1 text-xs text-gray-700 dark:bg-cherry-800 dark:text-slate-200">@{{ collection.slug_url }}</code>
                                    <button type="button" class="secondary-button !px-2 !py-1 text-xs" @click="copy(collection.slug_url)">@lang('dam::app.admin.dam.collections.show.copy')</button>
                                </div>
                                <p v-if="collection.audience !== 'public'" class="mt-1 text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.form.slug-help-internal')</p>
                            </div>
                        </section>

                        <section v-if="collection.audience === 'public'" class="rounded-lg border border-gray-200 bg-white p-4 dark:border-cherry-800 dark:bg-cherry-900" data-collection-link>
                            <h2 class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.collections.link.title')</h2>

                            <template v-if="link && link.status === 'active'">
                                <div v-if="link.public_url" class="mt-2 flex items-center gap-2">
                                    <code class="min-w-0 flex-1 truncate rounded bg-gray-50 px-2 py-1 text-xs text-gray-700 dark:bg-cherry-800 dark:text-slate-200" data-collection-link-url>@{{ link.public_url }}</code>
                                    <button type="button" class="secondary-button !px-2 !py-1 text-xs" @click="copy(link.public_url)">@lang('dam::app.admin.dam.collections.show.copy')</button>
                                </div>
                                <p v-else class="mt-2 text-xs text-gray-500 dark:text-slate-400" data-collection-link-hidden>@lang('dam::app.admin.dam.collections.link.url-hidden')</p>
                                <p class="mt-2 text-xs text-gray-500 dark:text-slate-400">@{{ linkMeta }}</p>

                                <form class="mt-3 space-y-2 border-t border-gray-100 pt-3 text-sm dark:border-cherry-800" @submit.prevent="saveLinkOptions">
                                    <label class="flex items-center gap-2"><input type="checkbox" v-model="linkDraft.allow_download"> @lang('dam::app.admin.dam.collections.link.allow-download')</label>
                                    <label class="flex items-center gap-2"><input type="checkbox" v-model="linkDraft.requires_creator_access"> @lang('dam::app.admin.dam.collections.link.creator-access')</label>
                                    <label class="block">
                                        <span class="text-xs text-gray-600 dark:text-slate-300">@{{ link.has_password ? @js(trans('dam::app.admin.dam.collections.link.password-change')) : @js(trans('dam::app.admin.dam.collections.link.password')) }}</span>
                                        <input type="password" v-model="linkDraft.password" autocomplete="new-password" minlength="4" maxlength="128" class="mt-1 w-full rounded border border-gray-300 p-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200">
                                    </label>
                                    <label v-if="link.has_password" class="flex items-center gap-2"><input type="checkbox" v-model="linkDraft.remove_password"> @lang('dam::app.admin.dam.collections.link.password-remove')</label>
                                    <div class="flex flex-wrap gap-2 pt-1">
                                        <button type="submit" class="secondary-button !py-1 text-xs" :disabled="busy">@lang('dam::app.admin.dam.collections.link.save')</button>
                                        <button type="button" class="secondary-button !py-1 text-xs" :disabled="busy" data-collection-link-reissue @click="reissue">@lang('dam::app.admin.dam.collections.link.reissue')</button>
                                        <button type="button" class="!py-1 text-xs text-red-600 hover:underline" :disabled="busy" data-collection-link-revoke @click="revoke">@lang('dam::app.admin.dam.collections.link.revoke')</button>
                                    </div>
                                </form>
                            </template>

                            <form v-else class="mt-2 space-y-2 text-sm" @submit.prevent="createLink" data-collection-link-create>
                                <p v-if="link" class="text-xs text-gray-500 dark:text-slate-400">@{{ linkMeta }}</p>
                                <p v-else class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.collections.link.none')</p>
                                <label class="block">
                                    <span class="text-xs text-gray-600 dark:text-slate-300">@lang('dam::app.admin.dam.collections.link.expiry')</span>
                                    <select v-model="newLink.expiry" class="mt-1 w-full rounded border border-gray-300 p-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200">
                                        <option value="7">@lang('dam::app.admin.dam.collections.link.days-7')</option>
                                        <option value="30">@lang('dam::app.admin.dam.collections.link.days-30')</option>
                                        <option value="90">@lang('dam::app.admin.dam.collections.link.days-90')</option>
                                        <option value="none">@lang('dam::app.admin.dam.collections.link.no-expiry')</option>
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-xs text-gray-600 dark:text-slate-300">@lang('dam::app.admin.dam.collections.link.password')</span>
                                    <input type="password" v-model="newLink.password" autocomplete="new-password" minlength="4" maxlength="128" class="mt-1 w-full rounded border border-gray-300 p-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800 dark:text-slate-200">
                                </label>
                                <label class="flex items-center gap-2"><input type="checkbox" v-model="newLink.allow_download"> @lang('dam::app.admin.dam.collections.link.allow-download')</label>
                                <label class="flex items-center gap-2"><input type="checkbox" v-model="newLink.requires_creator_access"> @lang('dam::app.admin.dam.collections.link.creator-access')</label>
                                <button type="submit" class="primary-button !py-1.5 text-sm" :disabled="busy" data-collection-link-submit>@lang('dam::app.admin.dam.collections.link.create')</button>
                            </form>
                        </section>
                    </aside>
                </div>
            </template>

            <div
                v-if="viewer !== null && members[viewer]"
                class="fixed inset-0 z-[10010] flex flex-col bg-black/90"
                role="dialog"
                aria-modal="true"
                :aria-label="members[viewer].name"
                data-collection-viewer
                @keydown.esc="close"
                @keydown.left="step(-1)"
                @keydown.right="step(1)"
                tabindex="-1"
                ref="viewer"
            >
                <div class="flex items-center justify-between gap-3 p-3 text-white">
                    <p class="min-w-0 truncate text-sm">@{{ members[viewer].name }} · @{{ viewer + 1 }} / @{{ members.length }}</p>
                    <div class="flex shrink-0 items-center gap-2">
                        <a :href="members[viewer].download_url" data-dam-download :data-dam-download-name="members[viewer].name" :data-dam-download-thumb="members[viewer].thumbnail_url" class="rounded bg-white/10 px-3 py-1 text-sm hover:bg-white/20">@lang('dam::app.admin.dam.collections.show.download')</a>
                        <button type="button" class="rounded bg-white/10 px-3 py-1 text-sm hover:bg-white/20" ref="closeViewer" @click="close">@lang('dam::app.admin.dam.collections.show.close')</button>
                    </div>
                </div>
                <div class="flex min-h-0 flex-1 items-center justify-between gap-2 px-2 pb-4">
                    <button type="button" class="rounded bg-white/10 px-3 py-6 text-white hover:bg-white/20 disabled:opacity-30" :disabled="viewer === 0" :aria-label="@js(trans('dam::app.admin.dam.collections.show.previous'))" @click="step(-1)">‹</button>
                    <div class="flex h-full min-w-0 flex-1 items-center justify-center">
                        <video v-if="members[viewer].file_type === 'video'" :src="members[viewer].playback_url" controls class="max-h-full max-w-full"></video>
                        <audio v-else-if="members[viewer].file_type === 'audio'" :src="members[viewer].preview_url" controls></audio>
                        <img v-else-if="members[viewer].file_type === 'image'" :src="members[viewer].preview_url" :alt="members[viewer].name" class="max-h-full max-w-full object-contain">
                        <img v-else :src="members[viewer].thumbnail_url" :alt="members[viewer].name" class="max-h-full max-w-full object-contain">
                    </div>
                    <button type="button" class="rounded bg-white/10 px-3 py-6 text-white hover:bg-white/20 disabled:opacity-30" :disabled="viewer === members.length - 1" :aria-label="@js(trans('dam::app.admin.dam.collections.show.next'))" @click="step(1)">›</button>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
    app.component('v-dam-collection', {
        template: '#v-dam-collection-template',
        data() {
            return {
                dataUrl: @json(route('admin.dam.collections.data', $collection->id)),
                collection: null,
                members: [],
                loading: true,
                error: null,
                editing: false,
                busy: false,
                announcement: '',
                viewer: null,
                opener: null,
                refs: {},
                linkDraft: {},
                newLink: { expiry: '30', password: '', allow_download: true, requires_creator_access: false },
            };
        },
        computed: {
            link() {
                return this.collection?.link ?? null;
            },
            principals() {
                const a = this.collection?.audience_principals ?? { users: [], groups: [] };

                return [...a.groups.map(g => ({ key: 'g' + g.id, name: g.name })), ...a.users.map(u => ({ key: 'u' + u.id, name: u.name }))];
            },
            audienceLabel() {
                return this.collection.audience === 'public' ? @js(trans('dam::app.admin.dam.collections.audience.public')) : @js(trans('dam::app.admin.dam.collections.audience.internal'));
            },
            stateLabel() {
                return ({ expired: @js(trans('dam::app.admin.dam.collections.state.expired')), archived: @js(trans('dam::app.admin.dam.collections.state.archived')) })[this.collection.state] ?? '';
            },
            expiresLabel() {
                const date = new Date(this.collection.expires_at).toLocaleDateString();

                return (this.collection.state === 'expired' ? @js(trans('dam::app.admin.dam.collections.expired-on')) : @js(trans('dam::app.admin.dam.collections.expires-on'))).replace(':date', date);
            },
            sharedByLabel() {
                return @js(trans('dam::app.admin.dam.collections.shared-by')).replace(':name', this.collection.owner.name);
            },
            linkMeta() {
                if (! this.link) return '';
                const status = ({ active: @js(trans('dam::app.admin.dam.collections.link.status-active')), expired: @js(trans('dam::app.admin.dam.collections.link.status-expired')), revoked: @js(trans('dam::app.admin.dam.collections.link.status-revoked')) })[this.link.status];
                const expiry = this.link.expires_at ? @js(trans('dam::app.admin.dam.collections.link.until')).replace(':date', new Date(this.link.expires_at).toLocaleDateString()) : @js(trans('dam::app.admin.dam.collections.link.no-expiry'));
                const views = @js(trans('dam::app.admin.dam.collections.link.views')).replace(':count', this.link.view_count);

                return [status, expiry, views].join(' · ');
            },
        },
        mounted() {
            this.load();
        },
        methods: {
            async load() {
                this.loading = true;
                this.error = null;
                try {
                    const { data } = await this.$axios.get(this.dataUrl);
                    this.apply(data);
                } catch (e) {
                    this.error = e.response?.status === 410
                        ? { message: @js(trans('dam::app.admin.dam.collections.closed.message')), retryable: false }
                        : (globalThis.mothership?.requestError?.(e) ?? { message: e?.response?.data?.message ?? '', retryable: true });
                } finally {
                    this.loading = false;
                }
            },
            apply(data) {
                this.collection = data.collection;
                this.members = data.members;
                this.resetLinkDraft();
            },
            resetLinkDraft() {
                this.linkDraft = { allow_download: this.link?.allow_download ?? true, requires_creator_access: this.link?.requires_creator_access ?? false, password: '', remove_password: false };
            },
            setRef(dir, id, el) {
                if (el) this.refs[dir + id] = el;
            },
            countLabel(count) {
                return (count === 1 ? @js(trans_choice('dam::app.admin.dam.collections.files-count', 1, ['count' => ':count'])) : @js(trans_choice('dam::app.admin.dam.collections.files-count', 2, ['count' => ':count']))).replace(':count', count);
            },
            nameOf(member) {
                return member.name || @js(trans('dam::app.admin.dam.collections.show.unnamed'));
            },
            openLabel(member) {
                return @js(trans('dam::app.admin.dam.collections.show.open')).replace(':name', this.nameOf(member));
            },
            downloadLabel(member) {
                return @js(trans('dam::app.admin.dam.collections.show.download-named')).replace(':name', this.nameOf(member));
            },
            moveLabel(dir, member) {
                return (dir === 'up' ? @js(trans('dam::app.admin.dam.collections.show.move-up')) : @js(trans('dam::app.admin.dam.collections.show.move-down'))).replace(':name', this.nameOf(member));
            },
            removeLabel(member) {
                return @js(trans('dam::app.admin.dam.collections.show.remove-named')).replace(':name', this.nameOf(member));
            },
            flash(type, message) {
                this.$emitter.emit('add-flash', { type, message });
            },
            failed(e) {
                this.flash('error', e.response?.data?.message || @js(trans('dam::app.admin.dam.collections.action-failed')));
            },
            async move(index, delta, dir) {
                const target = index + delta;
                if (this.busy || target < 0 || target >= this.members.length) return;

                const previous = [...this.members];
                const member = this.members[index];
                const next = [...this.members];
                next.splice(index, 1);
                next.splice(target, 0, member);
                this.members = next;
                this.busy = true;

                try {
                    await this.$axios.put(this.collection.order_url, { asset_ids: next.map(m => m.id) });
                    this.announcement = @js(trans('dam::app.admin.dam.collections.show.moved')).replace(':name', this.nameOf(member)).replace(':position', target + 1).replace(':total', next.length);
                } catch (e) {
                    this.members = previous;
                    this.failed(e);
                    await this.load();
                } finally {
                    this.busy = false;
                    // Keep focus on the control just used; at an end it moves to the other direction.
                    this.$nextTick(() => {
                        const atEnd = (dir === 'up' && target === 0) || (dir === 'down' && target === this.members.length - 1);
                        (this.refs[(atEnd ? (dir === 'up' ? 'down' : 'up') : dir) + member.id])?.focus();
                    });
                }
            },
            async remove(member) {
                if (this.busy) return;
                if (! window.confirm(@js(trans('dam::app.admin.dam.collections.show.remove-confirm')).replace(':name', this.nameOf(member)))) return;

                this.busy = true;
                try {
                    const { data } = await this.$axios.delete(@json(route('admin.dam.collections.members.destroy', ['id' => $collection->id, 'assetId' => '__ID__'])).replace('__ID__', member.id));
                    this.flash('success', data.message);
                    this.announcement = data.message;
                    await this.load();
                } catch (e) {
                    this.failed(e);
                } finally {
                    this.busy = false;
                }
            },
            onSaved() {
                this.editing = false;
                this.load();
            },
            onDeleted() {
                window.location.href = @json(route('admin.dam.collections.index'));
            },
            async copy(text) {
                try {
                    await navigator.clipboard.writeText(text);
                    this.flash('success', @js(trans('dam::app.admin.dam.collections.show.copied')));
                } catch (e) {
                    window.prompt(@js(trans('dam::app.admin.dam.collections.show.copy')), text);
                }
            },
            async createLink() {
                if (this.busy) return;
                this.busy = true;
                const body = {
                    allow_download: this.newLink.allow_download,
                    requires_creator_access: this.newLink.requires_creator_access,
                    ...(this.newLink.password ? { password: this.newLink.password } : {}),
                    ...(this.newLink.expiry === 'none' ? { no_expiry: true } : { expiry_days: Number(this.newLink.expiry) }),
                };
                try {
                    const { data } = await this.$axios.post(this.collection.link_url, body);
                    this.flash('success', data.message);
                    this.newLink.password = '';
                    await this.load();
                } catch (e) {
                    this.failed(e);
                } finally {
                    this.busy = false;
                }
            },
            async saveLinkOptions() {
                if (this.busy || ! this.link) return;
                this.busy = true;
                const body = {
                    allow_download: this.linkDraft.allow_download,
                    requires_creator_access: this.linkDraft.requires_creator_access,
                    ...(this.linkDraft.remove_password ? { remove_password: true } : (this.linkDraft.password ? { password: this.linkDraft.password } : {})),
                };
                try {
                    const { data } = await this.$axios.patch(this.link.update_url, body);
                    this.flash('success', data.message);
                    await this.load();
                } catch (e) {
                    this.failed(e);
                } finally {
                    this.busy = false;
                }
            },
            async reissue() {
                if (this.busy || ! this.link || ! window.confirm(@js(trans('dam::app.admin.dam.collections.link.reissue-confirm')))) return;
                this.busy = true;
                try {
                    const { data } = await this.$axios.post(this.link.reissue_url);
                    this.flash('success', data.message);
                    await this.load();
                } catch (e) {
                    this.failed(e);
                } finally {
                    this.busy = false;
                }
            },
            async revoke() {
                if (this.busy || ! this.link || ! window.confirm(@js(trans('dam::app.admin.dam.collections.link.revoke-confirm')))) return;
                this.busy = true;
                try {
                    const { data } = await this.$axios.patch(this.link.revoke_url);
                    this.flash('success', data.message);
                    await this.load();
                } catch (e) {
                    this.failed(e);
                } finally {
                    this.busy = false;
                }
            },
            open(index) {
                this.opener = document.activeElement;
                this.viewer = index;
                this.$nextTick(() => this.$refs.closeViewer?.focus());
            },
            step(delta) {
                const next = this.viewer + delta;
                if (next >= 0 && next < this.members.length && this.members[next].servable) this.viewer = next;
            },
            close() {
                this.viewer = null;
                this.$nextTick(() => this.opener?.focus());
            },
        },
    });
    </script>
    @endPushOnce
</x-admin::layouts>
