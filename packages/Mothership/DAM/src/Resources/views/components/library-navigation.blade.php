@once
    {{-- Include from the document head so Vue cannot discard these scoped styles. --}}
        <style>
            /* The header search is the Bridge command field now: its styles live with the shell in the admin app.css (.bridge-cmd). */
            .dam-search-fields { display:flex; gap:8px; width:100%; align-items:center; }
            .dam-iconvault-dialog { padding:0; border:1px solid #777; border-radius:12px; width:96vw; height:94dvh; max-width:1800px; max-height:96dvh; background:#fff; color:#171923; }
            .dam-iconvault-dialog::backdrop { background:#10121ccc; }
            .dam-iconvault-dialog.is-expanded { width:100vw; height:100dvh; max-width:100vw; max-height:100dvh; border-radius:0; }
            .dam-iconvault-shell { height:100%; display:flex; flex-direction:column; }
            .dam-iconvault-bar { display:flex; flex-wrap:wrap; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid #ddd; }
            .dam-iconvault-bar h2 { font-weight:700; }
            .dam-iconvault-bar [role=status] { flex:1; font-size:12px; min-width:100px; }
            .dam-iconvault-bar button,.dam-iconvault-bar a { border:1px solid #888; border-radius:6px; padding:5px 9px; font-size:13px; }
            .dam-iconvault-shell iframe { flex:1; width:100%; min-height:0; border:0; background:#fff; }
            .dam-iconvault-shell iframe[hidden],.dam-iconvault-fallback[hidden] { display:none; }
            .dam-iconvault-fallback { flex:1; min-height:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; padding:24px; text-align:center; overflow:auto; }
            .dam-iconvault-fallback-title { font-size:18px; font-weight:700; line-height:1.3; }
            .dam-iconvault-fallback-actions { display:flex; flex-wrap:wrap; justify-content:center; gap:12px; margin-top:8px; }
            .dam-iconvault-fallback-actions a,.dam-iconvault-fallback-actions button { display:inline-flex; align-items:center; min-height:44px; border:1px solid #888; border-radius:8px; padding:10px 16px; font-size:15px; }
            .dam-iconvault-fallback-actions a { background:#2D5BE3; border-color:#2D5BE3; color:#fff; font-weight:600; }
            .dam-directory-label { white-space:normal; overflow-wrap:anywhere; flex:1; min-width:0; text-align:left; }
            .dam-sidebar-resize { width:8px; cursor:col-resize; touch-action:none; align-self:stretch; flex-shrink:0; border-radius:4px; }
            .dam-sidebar-resize:hover,.dam-sidebar-resize:focus-visible { background:#2D5BE355; outline:2px solid #2D5BE3; }
            /* Thumbnail skeleton: a neutral tile, shimmering while v-dam-thumbnail is still fetching. */
            .image-card > img[data-thumb-state=loading] { opacity:0; }
            .image-card:has(> img[data-thumb-state=loading]) { background-color:#f1f2f4; background-image:linear-gradient(100deg,transparent 35%,#ffffff99 50%,transparent 65%); background-size:200% 100%; animation:dam-thumb-shimmer 1.3s linear infinite; }
            .dark .image-card:has(> img[data-thumb-state=loading]) { background-color:#26282d; background-image:linear-gradient(100deg,transparent 35%,#ffffff14 50%,transparent 65%); }
            @keyframes dam-thumb-shimmer { from { background-position:150% 0; } to { background-position:-50% 0; } }
            @media(prefers-reduced-motion:reduce) { .image-card:has(> img[data-thumb-state=loading]) { animation:none; } }
        </style>
    @push('scripts')
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/library-navigation.js')) !!}</script>
        <script>
            (() => {
                const nav = window.MothershipLibraryNavigation;
                nav.userId = @json(auth('admin')->id());
                // The anonymous layout (sign-in, after sign-out) forgets every account's explorer state.
                if (nav.userId === null) { try { nav.clearExplorerState(localStorage, sessionStorage); } catch (e) {} }
                else { try { nav.dropLegacyExplorerState(localStorage); } catch (e) {} }
            })();
        </script>
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/library-file-ops.js')) !!}</script>
        @if (filled(config('dam-home.icon_library_url')))
            <script>window.MothershipIconvaultUrl = @json(rtrim(config('dam-home.icon_library_url'), '/').'/');</script>
            <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/iconvault-dialog.js')) !!}</script>
        @endif
        <script>
            if (!window.__mothershipHeaderSearch) {
                window.__mothershipHeaderSearch = true;
                document.addEventListener('click', event => {
                    const toggle = event.target.closest('[data-asset-search-toggle]');
                    if (toggle) {
                        const form = toggle.closest('form');
                        const open = form.classList.toggle('is-open');
                        toggle.setAttribute('aria-expanded', String(open));
                        if (open) form.querySelector('input[type=search]').focus();
                    }
                    const clear = event.target.closest('[data-asset-search-clear]');
                    if (clear) { const form = clear.closest('form'); form.querySelector('input[type=search]').value = ''; form.requestSubmit(); }
                });
                document.addEventListener('keydown', event => {
                    const form = event.target.closest('[data-asset-search]');
                    if (form && event.key === 'Escape') { form.classList.remove('is-open'); const toggle = form.querySelector('[data-asset-search-toggle]'); toggle.setAttribute('aria-expanded','false'); if (getComputedStyle(toggle).display !== 'none') toggle.focus(); }
                });
                // ⌘K (Ctrl+K off the Mac) jumps to the command field from anywhere, opening it first on a phone.
                const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
                document.documentElement.toggleAttribute('data-not-mac', !isMac);
                document.addEventListener('keydown', event => {
                    if (event.key.toLowerCase() !== 'k' || !(isMac ? event.metaKey : event.ctrlKey) || event.altKey || event.shiftKey) return;
                    const input = document.getElementById('dam-header-query');
                    if (!input) return;
                    event.preventDefault();
                    const form = input.closest('[data-asset-search]'); const toggle = form?.querySelector('[data-asset-search-toggle]');
                    if (toggle && getComputedStyle(toggle).display !== 'none') { form.classList.add('is-open'); toggle.setAttribute('aria-expanded','true'); }
                    input.focus(); input.select();
                });
            }
        </script>
    @endpush
@endonce
