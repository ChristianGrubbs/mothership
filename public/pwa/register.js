(() => {
    if (window.mothershipPwa || !('serviceWorker' in navigator) || !window.isSecureContext) return;
    window.mothershipPwa = true;
    let installPrompt;
    const root = document.documentElement;
    const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const clear = () => { installPrompt = null; delete root.dataset.pwaInstallable; };
    const track = (name, props = {}) => {
        try { window.damTrack?.(name, 0, props, 'pwa'); } catch (_) { /* Analytics cannot affect installation. */ }
    };
    const opened = () => { if (standalone()) track('pwa.standalone.opened'); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', opened, { once: true });
    else opened();

    addEventListener('beforeinstallprompt', event => {
        if (standalone()) return;
        event.preventDefault();
        installPrompt = event;
        root.dataset.pwaInstallable = '';
        track('pwa.installable');
    });
    addEventListener('appinstalled', () => { clear(); track('pwa.installed'); });

    // Safari on iPhone and iPad never fires beforeinstallprompt; it exposes navigator.standalone instead (false in a
    // browser tab), so the account menu explains Add to Home Screen there.
    if (navigator.standalone === false) root.dataset.pwaIosInstallable = '';

    // A Home Screen app has no browser chrome to show a lost connection, and the worker serves only page loads, so
    // say so while offline: requests made from an open page fail until the network returns.
    let banner = null;
    const connection = () => {
        if (navigator.onLine !== false) { banner?.remove(); banner = null; return; }
        if (banner || !document.body) return;
        banner = document.createElement('div');
        banner.dataset.pwaOfflineBanner = '';
        banner.setAttribute('role', 'status');
        banner.textContent = document.querySelector('meta[name="pwa-offline-message"]')?.content || 'Offline';
        document.body.append(banner);
    };
    addEventListener('offline', connection);
    addEventListener('online', connection);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', connection, { once: true });
    else connection();
    document.addEventListener('click', async event => {
        if (!event.target.closest('[data-pwa-install]') || !installPrompt) {
            const help = event.target.closest('[data-pwa-ios-install]');
            const text = help && document.getElementById(help.getAttribute('aria-controls'));
            if (text) { text.hidden = !text.hidden; help.setAttribute('aria-expanded', String(!text.hidden)); }
            return;
        }
        event.preventDefault();
        const prompt = installPrompt;
        clear();
        try {
            const shown = prompt.prompt();
            track('pwa.install.prompted');
            await shown;
            const choice = await prompt.userChoice;
            if (['accepted', 'dismissed'].includes(choice?.outcome)) track('pwa.install.choice', { choice: choice.outcome });
        } catch (_) { /* The browser may withdraw eligibility. */ }
    });

    navigator.serviceWorker.register('/mothership-sw.js', { scope: '/', updateViaCache: 'none' }).then(registration => {
        let reported = false;
        const updateReady = () => {
            if (!reported && registration.waiting && navigator.serviceWorker.controller) { reported = true; track('pwa.update.ready'); }
        };
        updateReady();
        registration.addEventListener('updatefound', () => {
            registration.installing?.addEventListener('statechange', updateReady);
        });
        // Check for updated connection help without reloading an open editor or caching old application bundles.
        addEventListener('online', () => registration.update().catch(() => {}));
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') registration.update().catch(() => {});
        });
    }).catch(() => { /* Installation is optional; the connected application remains usable. */ });
})();
