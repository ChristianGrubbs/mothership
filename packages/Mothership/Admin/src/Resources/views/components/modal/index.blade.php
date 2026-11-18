@props([
    'isActive'      => false,
    'noClass'       => false,
    'preventSubmit' => false,
    'viewportFit'   => false,
])

<v-modal
    is-active="{{ $isActive }}"
    @if ($noClass)
        :no-class="true"
    @endif
    @if ($preventSubmit)
        :prevent-submit="true"
    @endif
    @if ($viewportFit)
        :viewport-fit="true"
    @endif
    {{ $attributes }}
>
    @isset($toggle)
        <template v-slot:toggle>
            {{ $toggle }}
        </template>
    @endisset

    @isset($header)
        <template v-slot:header="{ toggle, isOpen, titleId }">
            <div {{ $header->attributes->merge(['class' => 'flex justify-between items-center gap-2.5 px-4 py-3 border-b dark:border-gray-800']) }}>
                {{-- Names the dialog (aria-labelledby). `contents` leaves the header's flex layout exactly as it was. --}}
                <div
                    :id="titleId"
                    class="contents"
                >
                    {{ $header }}
                </div>

                <button
                    type="button"
                    class="icon-cancel text-3xl cursor-pointer hover:bg-primary-50 dark:hover:bg-cherry-800 hover:rounded-md"
                    aria-label="@lang('admin::app.components.modal.close-btn')"
                    @click="toggle"
                >
                </button>
            </div>
        </template>
    @endisset

    @isset($content)
        <template v-slot:content="{ toggle, isOpen }">
            <div {{ $content->attributes->merge(['class' => 'px-4 py-2.5 border-b dark:border-gray-800']) }}>
                {{ $content }}
            </div>
        </template>
    @endisset

    @isset($footer)
        <template v-slot:footer>
            <div {{ $footer->attributes->merge(['class' => 'flex justify-end px-4 py-2.5']) }}>
                {{ $footer }}
            </div>
        </template>
    @endisset
</v-modal>

@pushOnce('scripts')
    <script type="text/x-template" id="v-modal-template">
        <div>
            <div @click="toggle">
                <slot name="toggle">
                </slot>
            </div>

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
                    class="fixed inset-0 bg-gray-500 bg-opacity-50 transition-opacity z-[10001]"
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
                    ref="layer"
                    class="fixed inset-0 z-[10002] transform transition overflow-y-auto"
                    data-unsaved-ignore
                    v-if="isOpen"
                >
                    <div :class="noClass ? '' : 'flex min-h-full items-end justify-center p-4 sm:items-center sm:p-0'">
                        <div
                            ref="modalContent"
                            role="dialog"
                            aria-modal="true"
                            :aria-labelledby="labelledBy"
                            :aria-label="labelledBy ? null : label"
                            tabindex="-1"
                            class="no-focus-ring"
                            :data-admin-modal-panel="noClass ? null : ''"
                            :style="viewportStyle"
                            :class="noClass
                                ? 'w-full h-full'
                                : ['w-full max-h-[96%] z-[999] absolute ltr:left-1/2 rtl:right-1/2 top-1/2 rounded-lg bg-white dark:bg-gray-900 box-shadow max-md:w-[90%] ltr:-translate-x-1/2 rtl:translate-x-1/2 -translate-y-1/2', modalSize, viewportFit ? 'flex flex-col overflow-hidden' : { 'overflow-y-auto': isOverflowing, 'overflow-hidden': clip }]"
                        >
                            <!-- Header Slot -->
                            <slot
                                name="header"
                                :toggle="toggle"
                                :isOpen="isOpen"
                                :titleId="titleId"
                            >
                            </slot>

                            <!-- Content Slot: with viewport-fit it is the only region that scrolls, so header and footer stay in view -->
                            <div
                                v-if="viewportFit"
                                class="min-h-0 flex-1 overflow-y-auto"
                            >
                                <slot
                                    name="content"
                                    :toggle="toggle"
                                    :isOpen="isOpen"
                                ></slot>
                            </div>

                            <slot
                                v-else
                                name="content"
                                :toggle="toggle"
                                :isOpen="isOpen"
                            ></slot>
                            
                            <!-- Footer Slot -->
                            <slot name="footer"></slot>
                        </div>
                    </div>
                </div>
            </transition>
        </div>
    </script>

    <script type="module">
        {{-- Open modals, oldest first. Only the newest one answers Tab, Escape and Enter, so a modal opened over another one owns the keyboard. --}}
        const openModals = [];

        const focusableSelector = 'a[href], area[href], button, input:not([type="hidden"]), select, textarea, iframe, summary, [contenteditable]:not([contenteditable="false"]), [tabindex]';

        app.component('v-modal', {
            template: '#v-modal-template',

            {{-- `label` names a dialog whose header doesn't render the title element (no header slot, or a custom one). --}}
            props: ['isActive', 'type', 'clip', 'noClass', 'preventSubmit', 'viewportFit', 'label'],

            data() {
                return {
                    isOpen: this.isActive,
                    isOverflowing: false,
                    viewportStyle: null,
                    modalType: this.type,
                    resizeObserver: null,
                    mutationObserver: null,
                    returnFocusTo: null,
                    labelledBy: null,
                    sizeMap: {
                        small: "max-w-[400px]",
                        medium: "max-w-[568px]",
                        large: "max-w-[900px]",
                        full: "max-w-[calc(100vw-100px)] max-md:max-w-none"
                    }
                };
            },

            computed: {
                modalSize() {
                    return this.sizeMap[this.modalType] || this.sizeMap.medium;
                },

                titleId() {
                    return `v-modal-${this.$.uid}-title`;
                },
            },

            mounted() {
                this.$emitter.on('modal-size-change', (size) => {
                    this.modalType = size;

                    this.$nextTick(() => {
                        this.checkOverflow();
                    });
                });

                this._onWindowResize = () => {
                    if (this.isOpen) {
                        this.checkOverflow();
                    }
                };

                window.addEventListener('resize', this._onWindowResize);
                window.addEventListener('orientationchange', this._onWindowResize);
                window.addEventListener('keydown', this.handleKeydown);

                {{-- A modal mounted already open (is-active) never passes through open(), so it takes focus here. --}}
                if (this.isOpen) {
                    this.rememberOpener();

                    this.trackViewport();

                    openModals.push(this);

                    this.$nextTick(() => {
                        this.syncLabel();
                        this.focusInitial();
                    });
                }
            },

            beforeUnmount() {
                if (this.isOpen) {
                    window.unlockBodyScroll();

                    this.forget();

                    this.restoreFocus();
                }

                this.cleanupObservers();

                this.untrackViewport();

                if (this._onWindowResize) {
                    window.removeEventListener('resize', this._onWindowResize);
                    window.removeEventListener('orientationchange', this._onWindowResize);
                }

                window.removeEventListener('keydown', this.handleKeydown);
            },

            methods: {
                toggle() {
                    if (this.isOpen) {
                        this.hide();
                    } else {
                        this.show();
                    }

                    this.$emit('toggle', { isActive: this.isOpen });

                    if (this.isOpen) {
                        this.afterShow();
                    }
                },

                open() {
                    this.show();

                    this.$emit('open', { isActive: this.isOpen });

                    this.afterShow();
                },

                close() {
                    this.hide();

                    this.$emit('close', { isActive: this.isOpen });
                },

                {{-- Escape closes the modal the way its header close control does, and also tells @close listeners. --}}
                dismiss() {
                    this.hide();

                    this.$emit('toggle', { isActive: this.isOpen });

                    this.$emit('close', { isActive: this.isOpen });
                },

                show() {
                    if (! this.isOpen) {
                        window.lockBodyScroll();

                        openModals.push(this);
                    }

                    this.rememberOpener();

                    this.isOpen = true;

                    this.trackViewport();
                },

                {{-- Called after the toggle/open listeners, so their own $nextTick focus (a rename field, say) lands first and is kept. --}}
                afterShow() {
                    this.$nextTick(() => {
                        this.checkOverflow();
                        this.syncLabel();
                        this.observeContentChanges();
                        this.focusInitial();
                    });
                },

                {{-- Focus goes back before the close listeners run, so a listener that opens another dialog can still move focus on. --}}
                hide() {
                    if (this.isOpen) {
                        window.unlockBodyScroll();

                        this.forget();
                    }

                    this.isOpen = false;

                    this.cleanupObservers();

                    this.untrackViewport();

                    this.restoreFocus();
                },

                forget() {
                    const index = openModals.indexOf(this);

                    if (index !== -1) {
                        openModals.splice(index, 1);
                    }
                },

                {{-- The control that opened the modal gets focus back on close. Opening again from inside keeps the original opener. --}}
                rememberOpener() {
                    const opener = document.activeElement;

                    if (! opener || opener === document.body) {
                        if (! this.isOpen) {
                            this.returnFocusTo = null;
                        }

                        return;
                    }

                    if (! this.isOpen || ! this.$refs.modalContent?.contains(opener)) {
                        this.returnFocusTo = opener;
                    }
                },

                restoreFocus() {
                    const target = this.returnFocusTo;

                    this.returnFocusTo = null;

                    if (! target?.isConnected || typeof target.focus !== 'function') {
                        return;
                    }

                    {{-- Closing a modal underneath must not pull focus out of the one still open above it. --}}
                    const active = document.activeElement;

                    if (openModals.some(modal => modal.$refs.modalContent?.contains(active))) {
                        return;
                    }

                    target.focus();
                },

                focusableControls() {
                    const dialog = this.$refs.modalContent;

                    if (! dialog) {
                        return [];
                    }

                    return [...dialog.querySelectorAll(focusableSelector)]
                        .filter(control => control.tabIndex >= 0 && ! control.disabled && control.getClientRects().length);
                },

                {{-- First focusable control, else the dialog itself. Focus a listener already put inside (a rename field, say) stays where it is. --}}
                focusInitial() {
                    const dialog = this.$refs.modalContent;

                    if (! dialog || dialog.contains(document.activeElement) || ! this.isTopmost()) {
                        return;
                    }

                    (this.focusableControls()[0] ?? dialog).focus();
                },

                {{-- Name the dialog by its header only when the header renders the title element; a custom header may not. --}}
                syncLabel() {
                    this.labelledBy = this.$refs.modalContent?.querySelector(`[id="${this.titleId}"]`) ? this.titleId : null;
                },

                {{--
                    Whether this modal owns the keyboard: it is the layer on top at the centre of the screen. A modal
                    opened over it, the confirm, or the DAM preview each cover that point with their own fixed overlay
                    at their own z-index, wherever they sit in the DOM, so such an element between the top hit and
                    this layer means something else is on top. A fixed element without a z-index is this modal's own
                    content (the full-screen image viewer in a no-class modal). A modal still fading out is skipped.
                --}}
                isTopmost() {
                    const layer = this.$refs.layer;

                    if (! this.isOpen || ! layer) {
                        return false;
                    }

                    if (! document.elementsFromPoint) {
                        return openModals[openModals.length - 1] === this;
                    }

                    const top = document.elementsFromPoint(window.innerWidth / 2, window.innerHeight / 2)
                        .find(element => ! element.closest('.modal-content-leave-active'));

                    let node = top;

                    while (node && node !== layer) {
                        const style = getComputedStyle(node);

                        if (style.position === 'fixed' && style.zIndex !== 'auto') {
                            return false;
                        }

                        node = node.parentElement;
                    }

                    return node === layer;
                },

                {{-- Tab and Shift+Tab cycle through the modal's controls instead of leaving for the page behind the overlay. --}}
                keepFocusInside(event) {
                    const dialog = this.$refs.modalContent;
                    const controls = this.focusableControls();

                    if (! dialog) {
                        return;
                    }

                    if (! controls.length) {
                        event.preventDefault();

                        dialog.focus();

                        return;
                    }

                    const first = controls[0];
                    const last = controls[controls.length - 1];
                    const active = document.activeElement;

                    if (! dialog.contains(active)) {
                        event.preventDefault();

                        (event.shiftKey ? last : first).focus();
                    } else if (event.shiftKey && (active === first || active === dialog)) {
                        event.preventDefault();

                        last.focus();
                    } else if (! event.shiftKey && active === last) {
                        event.preventDefault();

                        first.focus();
                    }
                },

                {{--
                    viewport-fit only. The software keyboard overlays the layout viewport on iOS, so a dialog centred
                    and capped by that viewport slides under the keys. While the modal is open, centre it on the
                    visual viewport instead: the fixed layer is laid out in layout-viewport space, which the visual
                    viewport pans and shrinks within. Without `visualViewport` the style stays null and the layout
                    viewport rules apply.
                --}}
                trackViewport() {
                    const viewport = window.visualViewport;

                    if (! this.viewportFit || ! viewport || this._viewport) {
                        return;
                    }

                    this._viewport = viewport;
                    this._onViewportChange = () => this.fitViewport();

                    viewport.addEventListener('resize', this._onViewportChange);
                    viewport.addEventListener('scroll', this._onViewportChange);
                    document.addEventListener('focusin', this._onViewportChange);

                    this.fitViewport();
                },

                untrackViewport() {
                    if (this._viewport) {
                        this._viewport.removeEventListener('resize', this._onViewportChange);
                        this._viewport.removeEventListener('scroll', this._onViewportChange);
                        document.removeEventListener('focusin', this._onViewportChange);
                    }

                    this._viewport = null;
                    this._onViewportChange = null;
                    this.viewportStyle = null;
                },

                fitViewport() {
                    const viewport = this._viewport;

                    if (! viewport) {
                        return;
                    }

                    this.viewportStyle = {
                        top: `${viewport.offsetTop + viewport.height / 2}px`,
                        maxHeight: `${viewport.height * 0.96}px`,
                    };

                    {{-- The scroller is now short, and iOS pans the page rather than it, so the focused field is brought into view here. --}}
                    this.$nextTick(() => {
                        const active = document.activeElement;

                        if (active && active !== document.body && this.$refs.modalContent?.contains(active)) {
                            active.scrollIntoView({ block: 'nearest' });
                        }
                    });
                },

                checkOverflow() {
                    const el = this.$refs.modalContent;

                    if (el) {
                        this.isOverflowing = el.scrollHeight > window.innerHeight * 0.96;
                    }
                },

                observeContentChanges() {
                    const el = this.$refs.modalContent;

                    if (! el) {
                        return;
                    }

                    this.cleanupObservers();

                    if (window.ResizeObserver) {
                        this.resizeObserver = new ResizeObserver(() => {
                            this.checkOverflow();
                        });

                        this.resizeObserver.observe(el);
                    }

                    this.mutationObserver = new MutationObserver(() => {
                        this.checkOverflow();
                    });

                    this.mutationObserver.observe(el, {
                        childList: true,
                        subtree: true,
                        characterData: true,
                    });
                },

                cleanupObservers() {
                    if (this.resizeObserver) {
                        this.resizeObserver.disconnect();
                        this.resizeObserver = null;
                    }

                    if (this.mutationObserver) {
                        this.mutationObserver.disconnect();
                        this.mutationObserver = null;
                    }
                },

                handleKeydown(event) {
                    if (! this.isTopmost()) {
                        return;
                    }

                    if (event.key === 'Tab') {
                        this.keepFocusInside(event);

                        return;
                    }

                    if (event.key === 'Escape') {
                        {{-- An open popup inside (a dropdown, a multiselect) takes this press and closes itself; the modal closes on the next one. --}}
                        if (
                            event.defaultPrevented
                            || event.dropdownEscapeHandled
                            || window.openDropdowns?.length
                            || this.$refs.modalContent?.querySelector('.multiselect--active')
                        ) {
                            return;
                        }

                        event.preventDefault();

                        this.dismiss();

                        return;
                    }

                    {{-- Enter in a field must not submit the form around the modal. Buttons and links still answer Enter, so the close control works from the keyboard. --}}
                    if (
                        event.key === 'Enter'
                        && this.preventSubmit
                        && ! event.target?.closest?.('button, a[href], textarea, [contenteditable]:not([contenteditable="false"])')
                    ) {
                        event.preventDefault();
                    }
                },
            }
        });
    </script>
@endPushOnce
