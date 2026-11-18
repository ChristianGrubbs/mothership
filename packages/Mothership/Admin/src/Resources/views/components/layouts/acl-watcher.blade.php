@php
    $aclVersion = \Mothership\Admin\Http\Controllers\User\AclVersionController::fingerprint(auth()->guard('admin')->user());
@endphp

@pushOnce('scripts')
    <script type="module">
        (function () {
            if (window.__aclWatcherInstalled) {
                return;
            }

            window.__aclWatcherInstalled = true;

            const endpoint = "{{ route('admin.acl.version') }}";
            const booted = @json($aclVersion);

            if (! booted) {
                return;
            }

            const check = async () => {
                if (window.__unsavedBarCount > 0 || document.hidden) {
                    return;
                }

                try {
                    const response = await fetch(endpoint, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });

                    if (! response.ok) {
                        return;
                    }

                    const { version } = await response.json();

                    if (version && version !== booted) {
                        window.location.reload();
                    }
                } catch (e) {
                }
            };

            // Visible and in use: every 60 s, as before. Visible but untouched for 5 minutes: every 180 s
            // (the server enforces access on every request either way; this only decides when the page reloads).
            const CHECK_MS = 60000;
            const IDLE_CHECK_MS = 180000;
            const IDLE_AFTER_MS = 300000;
            let lastActivity = Date.now();
            let timer = null;

            const isIdle = () => Date.now() - lastActivity > IDLE_AFTER_MS;

            const schedule = () => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    check();
                    schedule();
                }, isIdle() ? IDLE_CHECK_MS : CHECK_MS);
            };

            ['pointerdown', 'keydown', 'wheel', 'touchstart', 'mousemove'].forEach((name) => {
                window.addEventListener(name, () => {
                    const wasIdle = isIdle();
                    lastActivity = Date.now();

                    if (wasIdle && ! document.hidden) {
                        check();
                        schedule();
                    }
                }, { passive: true });
            });

            schedule();

            document.addEventListener('visibilitychange', () => {
                if (! document.hidden) {
                    lastActivity = Date.now();
                    check();
                    schedule();
                }
            });
        })();
    </script>
@endPushOnce
