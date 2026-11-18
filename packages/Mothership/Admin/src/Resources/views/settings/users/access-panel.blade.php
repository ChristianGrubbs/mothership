{{-- Settings > Users: who is waiting for access and who is preapproved, in The Bridge's tokens. Included only for a role with All permissions. --}}
@php
    $accessCardClass = 'p-5 md:p-6 rounded-[18px] bg-[var(--card)] shadow-[var(--lift)]';
    $accessTitleClass = 'flex items-center gap-2 text-[17px] font-semibold tracking-[-0.025em] text-[color:var(--ink)]';
    $accessHintClass = 'mt-1 text-[13.5px] text-[color:var(--ink-2)]';
    $accessEmptyClass = 'mt-4 py-3 px-4 rounded-ctl bg-[var(--well)] text-[13.5px] text-[color:var(--ink-2)]';
    $accessListClass = 'mt-4 border-t border-[color:var(--line)]';
    $accessRowClass = 'grid gap-3 py-4 border-b last:border-b-0 border-[color:var(--line)]';
    $accessPrimaryTextClass = 'text-[15px] font-semibold text-[color:var(--ink)] break-words';
    $accessSecondaryTextClass = 'text-[13.5px] text-[color:var(--ink-2)] break-words';
    $accessLabelClass = 'bridge-mono block mb-1.5 text-[color:var(--ink-2)]';
    $accessFieldClass = 'w-full h-10 rounded-ctl border-0 bg-[var(--well)] text-[13.5px] text-[color:var(--ink)] placeholder:text-[color:var(--ink-2)]';
    $accessInputClass = $accessFieldClass.' px-3';
    $accessSelectClass = $accessFieldClass.' pl-2 pr-3';
    $accessErrorClass = 'mt-1.5 text-[13.5px] text-[color:var(--alert-text)]';
    $accessNoteClass = 'text-[13.5px] text-[color:var(--ink-2)]';
    $accessLampClass = 'inline-block shrink-0 w-2 h-2 rounded-full bg-[var(--alert)]';
    $accessActionClass = 'disabled:opacity-50 disabled:cursor-not-allowed';
    $accessTtlHours = max(1, (int) config('admin.auth.access_requests.ttl_hours', 24));
@endphp

<script
    type="text/x-template"
    id="v-user-access-template"
>
    <div
        class="grid gap-4 mb-4"
        data-user-access
    >
        <div
            v-if="loading && ! loaded"
            class="{{ $accessCardClass }} {{ $accessNoteClass }}"
            role="status"
        >
            @lang('admin::app.user-access.common.loading')
        </div>

        <div
            v-else-if="loadFailed && ! loaded"
            class="{{ $accessCardClass }} {{ $accessNoteClass }} flex flex-wrap items-center justify-between gap-3"
            role="alert"
        >
            <span>@lang('admin::app.user-access.common.load-failed')</span>

            <button
                type="button"
                class="secondary-button"
                @click="load"
            >
                @lang('admin::app.user-access.common.retry')
            </button>
        </div>

        <template v-else>
            <section
                class="{{ $accessCardClass }}"
                aria-labelledby="user-access-waiting-title"
                data-access-waiting
            >
                <h2
                    id="user-access-waiting-title"
                    class="{{ $accessTitleClass }}"
                >
                    <span
                        v-if="requests.length"
                        class="{{ $accessLampClass }}"
                        aria-hidden="true"
                    ></span>

                    @lang('admin::app.user-access.requests.title')

                    <span
                        v-if="requests.length"
                        class="bridge-mono text-[color:var(--ink-2)]"
                        v-text="requests.length"
                    ></span>
                </h2>

                <p class="{{ $accessHintClass }}">
                    @lang('admin::app.user-access.requests.hint', ['hours' => $accessTtlHours])
                </p>

                <p
                    v-if="! requests.length"
                    class="{{ $accessEmptyClass }}"
                >
                    @lang('admin::app.user-access.requests.empty')
                </p>

                <ul
                    v-else
                    class="{{ $accessListClass }}"
                >
                    <li
                        v-for="request in requests"
                        :key="request.id"
                        class="{{ $accessRowClass }} xl:grid-cols-[minmax(0,2fr)_minmax(0,1.1fr)_minmax(0,1.3fr)_auto] xl:items-center"
                        :data-access-request="request.id"
                    >
                        <div class="min-w-0">
                            <p
                                class="{{ $accessPrimaryTextClass }}"
                                v-text="request.email"
                            ></p>

                            <p
                                v-if="request.name"
                                class="{{ $accessSecondaryTextClass }}"
                                v-text="request.name"
                            ></p>
                        </div>

                        <div class="grid gap-1 min-w-0">
                            <p
                                class="bridge-mono text-[color:var(--ink-2)]"
                                v-text="request.provider"
                            ></p>

                            <p
                                class="bridge-mono flex items-center gap-1.5"
                                :class="request.email_verified ? 'text-[color:var(--ink-2)]' : 'text-[color:var(--alert-text)]'"
                            >
                                <span
                                    v-if="! request.email_verified"
                                    class="{{ $accessLampClass }}"
                                    aria-hidden="true"
                                ></span>

                                <span v-text="request.email_verified ? strings.verified : strings.unverified"></span>
                            </p>
                        </div>

                        <dl class="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-3 gap-y-1">
                            <dt class="bridge-mono text-[color:var(--ink-2)]">@lang('admin::app.user-access.requests.requested')</dt>
                            <dd
                                class="text-[13.5px] text-[color:var(--ink)]"
                                :title="absoluteTime(request.requested_at)"
                                v-text="relativeTime(request.requested_at)"
                            ></dd>

                            <dt class="bridge-mono text-[color:var(--ink-2)]">@lang('admin::app.user-access.requests.expires')</dt>
                            <dd
                                class="text-[13.5px] text-[color:var(--ink)]"
                                :title="absoluteTime(request.expires_at)"
                                v-text="relativeTime(request.expires_at)"
                            ></dd>
                        </dl>

                        <div class="grid grid-cols-2 gap-1 sm:flex sm:flex-wrap sm:items-center xl:justify-end">
                            <button
                                type="button"
                                class="secondary-button col-span-2 justify-center {{ $accessActionClass }}"
                                :disabled="busy || ! canApprove(request)"
                                data-access-action="approve"
                                @click="openDecision('approve', request)"
                            >
                                @lang('admin::app.user-access.common.approve')
                            </button>

                            <button
                                type="button"
                                class="transparent-button justify-center {{ $accessActionClass }}"
                                :disabled="busy || ! request.email_verified"
                                data-access-action="link"
                                @click="openDecision('link', request)"
                            >
                                @lang('admin::app.user-access.common.link')
                            </button>

                            <button
                                type="button"
                                class="transparent-button justify-center {{ $accessActionClass }}"
                                :disabled="busy"
                                data-access-action="deny"
                                @click="confirmDeny(request)"
                            >
                                @lang('admin::app.user-access.common.deny')
                            </button>
                        </div>

                        <p
                            v-if="requestNote(request)"
                            class="{{ $accessNoteClass }} xl:col-span-4"
                            v-text="requestNote(request)"
                        ></p>
                    </li>
                </ul>
            </section>

            <section
                class="{{ $accessCardClass }}"
                aria-labelledby="user-access-preapproved-title"
                data-access-preapproved
            >
                <h2
                    id="user-access-preapproved-title"
                    class="{{ $accessTitleClass }}"
                >
                    @lang('admin::app.user-access.preapprovals.title')

                    <span
                        v-if="preapprovals.length"
                        class="bridge-mono text-[color:var(--ink-2)]"
                        v-text="preapprovals.length"
                    ></span>
                </h2>

                <p class="{{ $accessHintClass }}">
                    @lang('admin::app.user-access.preapprovals.hint')
                </p>

                <form
                    class="grid gap-3 mt-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] md:items-start"
                    novalidate
                    @submit.prevent="addPreapproval"
                >
                    <div class="min-w-0">
                        <label
                            for="user-access-email"
                            class="{{ $accessLabelClass }}"
                        >
                            @lang('admin::app.user-access.preapprovals.email')
                        </label>

                        <input
                            id="user-access-email"
                            type="email"
                            class="{{ $accessInputClass }}"
                            autocomplete="off"
                            placeholder="@lang('admin::app.user-access.preapprovals.email-holder')"
                            v-model.trim="entry.email"
                            :aria-invalid="entryErrors.email ? 'true' : null"
                            aria-describedby="user-access-email-error"
                        />

                        <p
                            id="user-access-email-error"
                            v-if="entryErrors.email"
                            class="{{ $accessErrorClass }}"
                            role="alert"
                            v-text="entryErrors.email"
                        ></p>
                    </div>

                    <div class="min-w-0">
                        <label
                            for="user-access-role"
                            class="{{ $accessLabelClass }}"
                        >
                            @lang('admin::app.user-access.preapprovals.role')
                        </label>

                        <select
                            id="user-access-role"
                            class="{{ $accessSelectClass }}"
                            v-model="entry.role_id"
                            :aria-invalid="entryErrors.role_id ? 'true' : null"
                            aria-describedby="user-access-role-error"
                        >
                            <option value="">@lang('admin::app.user-access.preapprovals.role-choose')</option>

                            <option
                                v-for="role in roles"
                                :key="role.id"
                                :value="role.id"
                                v-text="role.name"
                            ></option>
                        </select>

                        <p
                            id="user-access-role-error"
                            v-if="entryErrors.role_id"
                            class="{{ $accessErrorClass }}"
                            role="alert"
                            v-text="entryErrors.role_id"
                        ></p>
                    </div>

                    <button
                        type="submit"
                        class="secondary-button justify-center h-10 md:mt-6 {{ $accessActionClass }}"
                        :disabled="busy"
                    >
                        @lang('admin::app.user-access.preapprovals.add')
                    </button>
                </form>

                <p
                    v-if="! preapprovals.length"
                    class="{{ $accessEmptyClass }}"
                >
                    @lang('admin::app.user-access.preapprovals.empty')
                </p>

                <ul
                    v-else
                    class="{{ $accessListClass }}"
                >
                    <li
                        v-for="preapproval in preapprovals"
                        :key="preapproval.id"
                        class="{{ $accessRowClass }} md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] md:items-center"
                        :data-access-preapproval="preapproval.id"
                    >
                        <div class="min-w-0">
                            <p
                                class="{{ $accessPrimaryTextClass }}"
                                v-text="preapproval.email"
                            ></p>

                            <p
                                v-if="preapproval.created_by"
                                class="{{ $accessSecondaryTextClass }}"
                                v-text="addedBy(preapproval)"
                            ></p>
                        </div>

                        <p
                            class="bridge-mono text-[color:var(--ink-2)] break-words"
                            v-text="preapproval.role"
                        ></p>

                        <button
                            type="button"
                            class="transparent-button justify-self-start md:justify-self-end {{ $accessActionClass }}"
                            :disabled="busy"
                            data-access-action="remove"
                            @click="confirmRemove(preapproval)"
                        >
                            @lang('admin::app.user-access.common.remove')
                        </button>

                        <p
                            v-if="preapproval.has_account"
                            class="{{ $accessNoteClass }} md:col-span-3"
                        >
                            @lang('admin::app.user-access.preapprovals.account-note')
                        </p>
                    </li>
                </ul>
            </section>
        </template>

        <x-admin::modal ref="decisionModal">
            <x-slot:header>
                <p
                    class="text-[17px] font-semibold tracking-[-0.025em] text-[color:var(--ink)]"
                    v-text="decision.mode === 'link' ? strings.linkTitle : strings.approveTitle"
                ></p>
            </x-slot>

            <x-slot:content>
                <p
                    class="mb-4 {{ $accessPrimaryTextClass }}"
                    v-text="decision.request?.email"
                ></p>

                <label
                    for="user-access-decision"
                    class="{{ $accessLabelClass }}"
                    v-text="decision.mode === 'link' ? strings.account : strings.role"
                ></label>

                <select
                    id="user-access-decision"
                    class="{{ $accessSelectClass }}"
                    v-model="decision.choice"
                >
                    <option
                        value=""
                        v-text="decision.mode === 'link' ? strings.accountChoose : strings.roleChoose"
                    ></option>

                    <option
                        v-for="option in decisionOptions"
                        :key="option.id"
                        :value="option.id"
                        v-text="option.label"
                    ></option>
                </select>

                <p
                    class="mt-2 {{ $accessNoteClass }}"
                    v-text="decision.mode === 'link' ? strings.linkHelp : strings.approveHelp"
                ></p>
            </x-slot>

            <x-slot:footer>
                <button
                    type="button"
                    class="primary-button {{ $accessActionClass }}"
                    :disabled="busy || ! decision.choice"
                    data-access-action="confirm-decision"
                    @click="submitDecision"
                    v-text="decision.mode === 'link' ? strings.link : strings.approve"
                ></button>
            </x-slot>
        </x-admin::modal>
    </div>
</script>

<script type="module">
    app.component('v-user-access', {
        template: '#v-user-access-template',

        emits: ['changed'],

        data() {
            return {
                loading: true,
                loaded: false,
                loadFailed: false,
                busy: false,

                requests: [],
                preapprovals: [],
                roles: [],
                accounts: [],

                entry: { email: '', role_id: '' },
                entryErrors: {},

                decision: { mode: 'approve', request: null, choice: '' },

                strings: {
                    verified: @json(trans('admin::app.user-access.requests.verified')),
                    unverified: @json(trans('admin::app.user-access.requests.unverified')),
                    unverifiedNote: @json(trans('admin::app.user-access.requests.unverified-note')),
                    accountNote: @json(trans('admin::app.user-access.requests.account-note')),
                    approve: @json(trans('admin::app.user-access.common.approve')),
                    link: @json(trans('admin::app.user-access.common.link')),
                    deny: @json(trans('admin::app.user-access.common.deny')),
                    remove: @json(trans('admin::app.user-access.common.remove')),
                    cancel: @json(trans('admin::app.user-access.common.cancel')),
                    failed: @json(trans('admin::app.user-access.common.failed')),
                    approveTitle: @json(trans('admin::app.user-access.requests.approve-title')),
                    approveHelp: @json(trans('admin::app.user-access.requests.approve-help')),
                    linkTitle: @json(trans('admin::app.user-access.requests.link-title')),
                    linkHelp: @json(trans('admin::app.user-access.requests.link-help')),
                    role: @json(trans('admin::app.user-access.requests.role')),
                    roleChoose: @json(trans('admin::app.user-access.requests.role-choose')),
                    account: @json(trans('admin::app.user-access.requests.account')),
                    accountChoose: @json(trans('admin::app.user-access.requests.account-choose')),
                    denyTitle: @json(trans('admin::app.user-access.requests.deny-title')),
                    denyMessage: @json(trans('admin::app.user-access.requests.deny-message')),
                    removeTitle: @json(trans('admin::app.user-access.preapprovals.remove-title')),
                    removeMessage: @json(trans('admin::app.user-access.preapprovals.remove-message')),
                    addedBy: @json(trans('admin::app.user-access.preapprovals.added-by')),
                },

                urls: {
                    overview: @json(route('admin.settings.users.access.index')),
                    preapprove: @json(route('admin.settings.users.access.preapprove')),
                    approve: @json(route('admin.settings.users.access.approve', ['id' => '__ID__'])),
                    link: @json(route('admin.settings.users.access.link', ['id' => '__ID__'])),
                    deny: @json(route('admin.settings.users.access.deny', ['id' => '__ID__'])),
                    removePreapproval: @json(route('admin.settings.users.access.preapprovals.remove', ['id' => '__ID__'])),
                },
            };
        },

        computed: {
            decisionOptions() {
                if (this.decision.mode === 'link') {
                    return this.accounts.map((account) => ({ id: account.id, label: `${account.name} (${account.email})` }));
                }

                return this.roles.map((role) => ({ id: role.id, label: role.name }));
            },
        },

        mounted() {
            this.load();
        },

        methods: {
            load() {
                this.loading = true;
                this.loadFailed = false;

                return this.$axios.get(this.urls.overview)
                    .then((response) => {
                        this.requests = response.data.requests;
                        this.preapprovals = response.data.preapprovals;
                        this.roles = response.data.roles;
                        this.accounts = response.data.accounts;
                        this.loaded = true;
                    })
                    .catch(() => {
                        this.loadFailed = true;
                    })
                    .finally(() => {
                        this.loading = false;
                    });
            },

            canApprove(request) {
                return request.email_verified && ! request.has_account;
            },

            requestNote(request) {
                if (! request.email_verified) {
                    return this.strings.unverifiedNote;
                }

                return request.has_account ? this.strings.accountNote : '';
            },

            addedBy(preapproval) {
                return this.strings.addedBy.replace(':name', preapproval.created_by);
            },

            urlFor(name, id) {
                return this.urls[name].replace('__ID__', id);
            },

            openDecision(mode, request) {
                this.decision = {
                    mode,
                    request,
                    choice: mode === 'link' ? (request.account_id ?? '') : '',
                };

                this.$refs.decisionModal.open();
            },

            submitDecision() {
                const { mode, request, choice } = this.decision;

                const payload = mode === 'link' ? { admin_id: choice } : { role_id: choice };

                this.send(() => this.$axios.post(this.urlFor(mode, request.id), payload))
                    .finally(() => this.$refs.decisionModal.close());
            },

            confirmDeny(request) {
                this.$emitter.emit('open-confirm-modal', {
                    title: this.strings.denyTitle,
                    message: this.strings.denyMessage,
                    options: this.confirmOptions(this.strings.deny),
                    agree: () => this.send(() => this.$axios.delete(this.urlFor('deny', request.id))),
                });
            },

            confirmRemove(preapproval) {
                this.$emitter.emit('open-confirm-modal', {
                    title: this.strings.removeTitle,
                    message: this.strings.removeMessage,
                    options: this.confirmOptions(this.strings.remove),
                    agree: () => this.send(() => this.$axios.delete(this.urlFor('removePreapproval', preapproval.id))),
                });
            },

            confirmOptions(agreeLabel) {
                return {
                    btnDisagree: this.strings.cancel,
                    btnAgree: agreeLabel,
                    btnAgreeClass: 'danger-button',
                    btnDisagreeClass: 'transparent-button',
                };
            },

            addPreapproval() {
                this.entryErrors = {};

                this.send(() => this.$axios.post(this.urls.preapprove, this.entry), { fieldErrors: true })
                    .then((succeeded) => {
                        if (succeeded) {
                            this.entry = { email: '', role_id: '' };
                        }
                    });
            },

            {{-- Runs one decision, says how it went and re-reads the lists, which a refused decision has usually outdated too. Resolves to whether it succeeded. --}}
            send(request, { fieldErrors = false } = {}) {
                this.busy = true;

                return request()
                    .then((response) => {
                        this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });

                        this.$emit('changed');

                        return true;
                    })
                    .catch((error) => {
                        const errors = error.response?.data?.errors;

                        if (fieldErrors && error.response?.status === 422 && errors) {
                            this.entryErrors = Object.fromEntries(
                                Object.entries(errors).map(([field, messages]) => [field, [].concat(messages)[0]])
                            );

                            return false;
                        }

                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error.response?.data?.message ?? this.strings.failed,
                        });

                        return false;
                    })
                    .finally(() => {
                        this.busy = false;

                        this.load();
                    });
            },

            absoluteTime(value) {
                return value ? new Date(value).toLocaleString() : '';
            },

            relativeTime(value) {
                if (! value) {
                    return '';
                }

                const seconds = (new Date(value).getTime() - Date.now()) / 1000;

                {{-- The page's lang attribute is the app locale (en_US); Intl needs the hyphenated tag. --}}
                const locale = document.documentElement.lang.replace('_', '-') || undefined;

                const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

                for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]]) {
                    if (Math.abs(seconds) >= size) {
                        return formatter.format(Math.trunc(seconds / size), unit);
                    }
                }

                return formatter.format(0, 'second');
            },
        },
    });
</script>
