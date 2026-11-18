<script>
    {{--
        One focus lifecycle for both lightbox consumers (v-dam-grid-preview-modal and v-asset-preview-modal):
        focus enters the dialog on open, Tab cycles inside it (the web-page iframe is a stop), focus that
        escapes the dialog (out of the iframe, say) is pulled back unless the Share dialog is up, and closing returns focus to the opener.
    --}}
    window._damPreviewFocus = {
        methods: {
            /** The lightbox dialog element, once it is on screen. */
            previewDialog() {
                return document.querySelector?.('[data-dam-preview-backdrop] [role="dialog"]') ?? null;
            },

            /** Remember the control that opened the lightbox; a step to another file keeps the first. */
            rememberPreviewOpener(assetId) {
                if (this.isOpen) return;
                this._previewOpener = document.activeElement;
                this._previewOpenerId = assetId;
            },

            /** Focus enters the dialog on open, and returns to it when a step to another file rebuilt the control that held it. */
            focusPreview() {
                const dialog = this.previewDialog();
                if (dialog && ! dialog.contains(document.activeElement)) dialog.focus({ preventScroll: true });
            },

            previewFocusable(dialog) {
                return Array.from(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex="-1"])'))
                    .filter(el => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden');
            },

            /** Tab and Shift+Tab cycle inside the open lightbox instead of walking out to the page behind it. */
            trapPreviewTab(e) {
                const dialog = this.previewDialog();
                if (! dialog) return;
                const focusable = this.previewFocusable(dialog);
                if (! focusable.length) { e.preventDefault(); dialog.focus(); return; }
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                const active = document.activeElement;
                if (! dialog.contains(active) || active === dialog) {
                    e.preventDefault();
                    (e.shiftKey ? last : first).focus();
                } else if (e.shiftKey && active === first) {
                    e.preventDefault();
                    last.focus();
                } else if (! e.shiftKey && active === last) {
                    e.preventDefault();
                    first.focus();
                }
            },

            /**
             * Keydowns inside the sandboxed page frame never reach this window, so Tab cannot be trapped there:
             * when focus leaves the frame past its last stop it lands outside the dialog, and this pulls it back.
             */
            startPreviewFocusGuard() {
                this._previewFocusGuard = (e) => {
                    // The Share dialog opens above the lightbox and owns focus while it is up; it hands focus back when it closes.
                    if (! this.isOpen || window.damShareDialogOpen?.() || window.damConfirmDialogOpen?.()) return;
                    const dialog = this.previewDialog();
                    if (! dialog || dialog.contains(e.target) || e.target === document.body) return;
                    const focusable = this.previewFocusable(dialog);
                    (focusable[0] ?? dialog).focus({ preventScroll: true });
                };
                document.addEventListener('focusin', this._previewFocusGuard);
            },

            stopPreviewFocusGuard() {
                if (this._previewFocusGuard) document.removeEventListener('focusin', this._previewFocusGuard);
                this._previewFocusGuard = null;
            },

            /** Closing hands focus back to the control that opened the lightbox, else to the card's visible, focusable control. */
            restorePreviewFocus() {
                const opener = this._previewOpener;
                this._previewOpener = null;
                let target = opener?.isConnected && opener !== document.body ? opener : null;
                if (! target && this._previewOpenerId != null) {
                    const card = document.querySelector?.(`[data-dam-asset-open][data-dam-asset-id="${this._previewOpenerId}"]`);
                    // In priority order (querySelectorAll alone would answer in document order): the open button, which opens a stack's
                    // main file too, then the stack's count button, then the actions menu trigger.
                    const usable = el => el.getAttribute('tabindex') !== '-1' && ! el.closest('[aria-hidden="true"]') && el.getClientRects().length > 0;
                    for (const selector of ['[data-dam-asset-open-button]', '[data-dam-stack-open]', '.dam-ctx-trigger']) {
                        target = Array.from(card?.querySelectorAll(selector) ?? []).find(usable) ?? null;
                        if (target) break;
                    }
                }
                target?.focus?.({ preventScroll: true });
            },
        },
    };
</script>
