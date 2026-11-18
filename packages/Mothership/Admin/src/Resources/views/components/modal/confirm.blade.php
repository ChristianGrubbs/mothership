<v-modal-confirm ref="confirmModal"></v-modal-confirm>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-modal-confirm-template"
    >
        <div>
            <transition
                tag="div"
                name="modal-overlay"
                enter-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    class="fixed inset-0 bg-gray-500 bg-opacity-50 transition-opacity z-[10002]"
                    :style="{ zIndex }"
                    v-show="isOpen"
                ></div>
            </transition>

            <transition
                tag="div"
                name="modal-content"
                enter-class="ease-out duration-300"
                enter-from-class="opacity-0 translate-y-4 md:translate-y-0 md:scale-95"
                enter-to-class="opacity-100 translate-y-0 md:scale-100"
                leave-class="ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0 md:scale-100"
                leave-to-class="opacity-0 translate-y-4 md:translate-y-0 md:scale-95"
            >
                <div
                    class="fixed inset-0 z-[10002] transform transition overflow-y-auto"
                    :style="{ zIndex }"
                    v-if="isOpen"
                >
                    <div class="flex min-h-full items-end justify-center p-5 sm:items-center sm:p-0">
                        <div
                            ref="dialog"
                            class="w-full max-w-[400px] z-[999] absolute left-1/2 top-1/2 rounded-lg bg-white dark:bg-cherry-800 box-shadow max-md:w-[90%] -translate-x-1/2 -translate-y-1/2"
                            role="alertdialog"
                            aria-modal="true"
                            :aria-labelledby="`${idPrefix}-title`"
                            :aria-describedby="`${idPrefix}-message`"
                            @keydown.esc.stop.prevent="disagree"
                            @keydown.tab="keepFocusInside"
                        >
                            <div
                                :id="`${idPrefix}-title`"
                                class="flex justify-between items-center gap-2.5 px-4 py-3 border-b dark:border-cherry-700 text-lg text-gray-800 dark:text-white font-bold"
                            >
                                @{{ title }}
                            </div>

                            <div
                                :id="`${idPrefix}-message`"
                                class="px-4 py-3 text-gray-600 dark:text-gray-300 text-left"
                            >
                                @{{ message }}
                            </div>

                            <div class="flex gap-2.5 justify-end px-4 py-2.5">
                                <button type="button" ref="disagreeButton" :class="options.btnDisagreeClass" @click="disagree">
                                    @{{ options.btnDisagree }}
                                </button>

                                <button type="button" :class="options.btnAgreeClass" @click="agree">
                                    @{{ options.btnAgree }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </div>
    </script>

    <script type="module">
        app.component('v-modal-confirm', {
            template: '#v-modal-confirm-template',

            data() {
                return {
                    isOpen: false,
                    zIndex: 10002,

                    title: '',

                    message: '',

                    options: {
                        btnDisagree: '',
                        btnAgree: '',
                        btnAgreeClass: '',
                        btnDisagreeClass: '',
                    },

                    agreeCallback: null,

                    disagreeCallback: null,

                    returnFocusTo: null,
                };
            },

            computed: {
                idPrefix() {
                    return `v-modal-confirm-${this.$.uid}`;
                },
            },

            created() {
                this._stackedConfirmOpen = () => this.isOpen && Number(this.zIndex) > 10010;
                window.damConfirmDialogOpen = this._stackedConfirmOpen;
                this.registerGlobalEvents();
            },

            beforeUnmount() {
                if (window.damConfirmDialogOpen === this._stackedConfirmOpen) delete window.damConfirmDialogOpen;
                if (this.isOpen) {
                    window.unlockBodyScroll();
                }
            },

            methods: {
                open({
                    zIndex = 10002,
                    title = "@lang('admin::app.components.modal.confirm.title')",
                    message = "@lang('admin::app.components.modal.confirm.message')",
                    options = {
                        btnDisagree: "@lang('admin::app.components.modal.confirm.disagree-btn')",
                        btnAgree: "@lang('admin::app.components.modal.confirm.agree-btn')",
                        btnAgreeClass: 'primary-button',
                        btnDisagreeClass: 'transparent-button',
                    },
                    agree = () => {},
                    disagree = () => {},
                }) {
                    if (! this.isOpen) {
                        window.lockBodyScroll();
                    }

                    {{--
                        Remember the control that opened the dialog (a row action, say), so closing hands focus
                        back to it. A replacement opened from inside the dialog keeps the original opener.
                    --}}
                    const opener = document.activeElement;

                    if (! this.isOpen || (opener && opener !== document.body && ! this.$refs.dialog?.contains(opener))) {
                        this.returnFocusTo = opener && opener !== document.body ? opener : null;
                    }

                    this.isOpen = true;
                    this.zIndex = zIndex;

                    this.title = title;

                    this.message = message;

                    this.options = options;

                    this.agreeCallback = agree;

                    this.disagreeCallback = disagree;

                    {{-- Focus starts on the safe choice, so Enter alone never confirms. --}}
                    this.$nextTick(() => this.$refs.disagreeButton?.focus());
                },

                openDelete({
                    title = "@lang('admin::app.components.modal.delete.title')",
                    message = "@lang('admin::app.components.modal.delete.message')",
                    options = {
                        btnDisagree: "@lang('admin::app.components.modal.delete.disagree-btn')",
                        btnAgree: "@lang('admin::app.components.modal.delete.agree-btn')",
                        btnAgreeClass: 'danger-button',
                        btnDisagreeClass: 'transparent-button',
                    },
                    agree = () => {},
                    disagree = () => {},
                }) {
                    this.open({title, message, options, agree, disagree});
                },

                disagree() {
                    {{-- A dialog that is already closing (leave transition) answers nothing a second time. --}}
                    if (! this.isOpen) {
                        return;
                    }

                    window.unlockBodyScroll();

                    this.isOpen = false;

                    this.restoreFocus();

                    this.disagreeCallback();
                },

                agree() {
                    {{-- A dialog that is already closing (leave transition) answers nothing a second time. --}}
                    if (! this.isOpen) {
                        return;
                    }

                    window.unlockBodyScroll();

                    this.isOpen = false;

                    this.restoreFocus();

                    this.agreeCallback();
                },

                {{-- Runs before the callback, so a callback that opens another dialog can still move focus on. --}}
                restoreFocus() {
                    const target = this.returnFocusTo;

                    this.returnFocusTo = null;

                    if (target?.isConnected && typeof target.focus === 'function') {
                        target.focus();
                    }
                },

                {{-- Tab and Shift+Tab cycle between the dialog's buttons instead of leaving for the page behind the overlay. --}}
                keepFocusInside(event) {
                    const controls = [...(this.$refs.dialog?.querySelectorAll('button:not([disabled])') ?? [])];

                    if (! controls.length) {
                        return;
                    }

                    const first = controls[0];
                    const last = controls[controls.length - 1];

                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();

                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();

                        first.focus();
                    }
                },

                registerGlobalEvents() {
                    this.$emitter.on('open-confirm-modal', this.open);

                    this.$emitter.on('open-delete-modal', this.openDelete);
                },
            }
        });
    </script>
@endPushOnce
