import { HEADERS, META, NAV_EVENTS } from '../constants';

export default function initAjaxNavigation() {
    if (window.__ajaxNavInitialised) {
        return;
    }

    window.__ajaxNavInitialised = true;

    const navigationGuards = [];
    const localHistoryHandlers = window.__mothershipLocalHistoryHandlers ||= new Set();

    let navigating = false;

    let navToken = 0;

    let moduleSeq = 0;

    /** How long a visit waits for one page module (its imports included) before mounting anyway. */
    const MODULE_TIMEOUT_MS = 10000;

    window.mothership = window.mothership || {};
    window.mothership.visit = (url) => visit(url, true);

    window.mothership.registerNavigationGuard = (guard) => {
        if (typeof guard === 'function' && ! navigationGuards.includes(guard)) {
            navigationGuards.push(guard);
        }
    };

    // A page may own a same-document history entry (for example, a preview over its chooser).
    // Consult its owner before starting a page visit; registration retains other owners and returns cleanup.
    window.mothership.registerLocalHistoryHandler = (handler) => {
        if (typeof handler !== 'function') return () => {};
        localHistoryHandlers.add(handler);

        return () => localHistoryHandlers.delete(handler);
    };

    // Guards registered before this init (inline page scripts run ahead of the module) are drained first, so no link
    // is interceptable while a guard is still missing.
    (window.__mothershipNavGuardQueue || []).forEach((guard) => window.mothership.registerNavigationGuard(guard));

    window.__mothershipNavGuardQueue = [];

    document.addEventListener('click', onDocumentClick, true);

    window.addEventListener('popstate', (event) => {
        if ([...localHistoryHandlers].some(handler => handler(event) === true)) return;
        visit(window.location.href, false);
    });

    function onDocumentClick(event) {
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
        ) {
            return;
        }

        const link = event.target.closest ? event.target.closest('a[href]') : null;

        if (! link || ! isEligible(link)) {
            return;
        }

        event.preventDefault();

        visit(link.href, true);
    }

    function isEligible(link) {
        if (link.target && link.target !== '' && link.target !== '_self') {
            return false;
        }

        if (link.hasAttribute('download') || link.dataset.noAjaxNav !== undefined) {
            return false;
        }

        // A bare "#" (or "#something") is a placeholder or in-page anchor, never a page to load; its resolved URL
        // is the current page, so without this it would reload the page it sits on.
        if ((link.getAttribute('href') || '').trim().startsWith('#')) {
            return false;
        }

        const url = new URL(link.href, window.location.origin);

        if (url.origin !== window.location.origin) {
            return false;
        }

        if (url.pathname === window.location.pathname && url.hash) {
            return false;
        }

        return url.pathname.startsWith(adminPrefix());
    }

    function adminPrefix() {
        const meta = document.querySelector('meta[name="' + META.ADMIN_URL + '"]');

        const path = new URL((meta && meta.getAttribute('content')) || 'admin', window.location.origin).pathname;

        const marker = '/' + (path.split('/').filter(Boolean).pop() || 'admin') + '/';

        const here = window.location.pathname + '/';

        const at = here.indexOf(marker);

        return at !== -1 ? here.slice(0, at + marker.length) : '/' + path.replace(/^\/+|\/+$/g, '') + '/';
    }

    async function visit(url, push) {
        if (navigating) {
            return;
        }

        navigating = true;

        try {
            if (! (await guardsAllow(url))) {
                return;
            }

            await performVisit(url, push);
        } finally {
            navigating = false;
        }
    }

    async function guardsAllow(url) {
        for (const guard of navigationGuards) {
            try {
                if ((await guard(url)) === false) {
                    return false;
                }
            } catch (error) {
                console.error(error);
            }
        }

        return true;
    }

    /**
     * The history entry is pushed right after the #app swap — before the
     * page's scripts run and the app mounts — so anything reading
     * window.location during boot (e.g. datagrid URL filters) sees the
     * destination URL, not the page being left.
     */
    async function performVisit(url, push) {
        const before = dispatch(NAV_EVENTS.BEFORE, { url }, true);

        if (before.defaultPrevented) {
            window.location.assign(url);

            return;
        }

        toggleProgress(true);

        try {
            const response = await fetch(url, {
                headers: { [HEADERS.AJAX_NAV]: 'true' },
                credentials: 'same-origin',
                redirect: 'follow',
            });

            if (! response.ok) {
                throw new Error('Request failed with status ' + response.status);
            }

            const finalUrl = response.url || url;

            const html = await response.text();

            const doc = new DOMParser().parseFromString(html, 'text/html');

            const nextApp = doc.querySelector('#app');

            if (! nextApp) {
                throw new Error('Missing #app in response');
            }

            const scripts = collectPageScripts(doc);

            const styles = collectPageStyles(doc);

            if (window.app && typeof window.app.unmount === 'function') {
                window.app.unmount();
            }

            document.querySelector('#app').replaceWith(nextApp);

            if (push) {
                window.history.pushState({ ajaxNav: true }, '', finalUrl);
            }

            removePageScripts();

            replaceInjectedStyles(styles);

            reconcileHeadStylesheets(doc);

            window.createAdminApp();

            await injectScripts(scripts);

            window.app.mount('#app');

            document.title = doc.title;

            syncDocumentLocale(doc);

            (document.getElementById('main-content') ?? window).scrollTo(0, 0);

            dispatch(NAV_EVENTS.SUCCESS, { url });
        } catch (error) {
            dispatch(NAV_EVENTS.ERROR, { url, error });

            window.location.assign(url);

            return;
        } finally {
            toggleProgress(false);
        }
    }

    /**
     * Carry the swapped page's language and writing direction onto the live
     * document. They live on <html>, outside the #app subtree a visit replaces,
     * so a locale change would otherwise keep the previous lang and direction
     * until the user reloaded by hand.
     */
    function syncDocumentLocale(doc) {
        const next = doc.documentElement;

        if (! next) {
            return;
        }

        if (next.lang && next.lang !== document.documentElement.lang) {
            document.documentElement.lang = next.lang;

            if (typeof window.mothershipSyncValidationLocale === 'function') {
                window.mothershipSyncValidationLocale();
            }
        }

        const dir = next.getAttribute('dir');

        if (dir && dir !== document.documentElement.getAttribute('dir')) {
            document.documentElement.setAttribute('dir', dir);
        }
    }

    function dispatch(name, detail, cancelable = false) {
        const event = new CustomEvent(name, { detail, cancelable, bubbles: true });

        document.dispatchEvent(event);

        return event;
    }

    function collectPageScripts(doc) {
        return [...doc.body.querySelectorAll('script')].filter((script) => {
            return ! script.src || ! /assets\/app-.*\.js/.test(script.src);
        });
    }

    function isExecutableType(type) {
        return type === ''
            || type === 'text/javascript'
            || type === 'application/javascript'
            || type === 'module';
    }

    /**
     * A visit swaps only #app and re-injects the destination's inline <style> tags, but the
     * layout's <head> stylesheet <link>s live outside #app and are never touched. Pages built on
     * different layouts carry different head links (e.g. the DAM index adds the SIGNAL ticker's
     * stylesheet; asset-edit pages do not), so navigating from a page that lacks a link to one
     * that needs it left that page unstyled — the ticker rendering as a tall, unstyled block.
     * Copy across any destination head stylesheet not already live. Additive only: links are
     * never removed, so a stylesheet a previous page needed keeps applying.
     */
    function reconcileHeadStylesheets(doc) {
        const absolute = (href) => {
            try {
                return new URL(href, window.location.href).href;
            } catch (error) {
                return href;
            }
        };

        const live = new Set(
            [...document.querySelectorAll('link[rel~="stylesheet"][href]')]
                .map((link) => absolute(link.getAttribute('href')))
        );

        [...doc.querySelectorAll('head link[rel~="stylesheet"][href]')].forEach((original) => {
            const href = absolute(original.getAttribute('href'));

            if (live.has(href)) {
                return;
            }

            const clone = document.createElement('link');

            clone.rel = 'stylesheet';
            clone.href = href;

            const media = original.getAttribute('media');

            if (media) {
                clone.media = media;
            }

            clone.setAttribute('data-ajax-nav-stylesheet', '');

            document.head.appendChild(clone);

            live.add(href);
        });
    }

    /**
     * The destination's inline <style> tags to inject: those in its body, plus those its views pushed
     * into the layout's <head> (@stack('styles'), e.g. the preview viewer's phone and iPhone Duo layout).
     * Without the head ones, a page reached by a visit rather than a full load lost that CSS: the
     * Library viewer on a phone had no layout and no picture. A head <style> whose text a live,
     * non-injected <style> already holds (the first page's own head) is skipped, so it is not doubled.
     */
    function collectPageStyles(doc) {
        const live = new Set(
            [...document.head.querySelectorAll('style:not([data-ajax-nav-style])')]
                .map((style) => style.textContent)
        );

        const head = [...doc.head.querySelectorAll('style')]
            .filter((style) => ! live.has(style.textContent));

        return [...head, ...doc.body.querySelectorAll('style')];
    }

    function replaceInjectedStyles(styles) {
        document.querySelectorAll('style[data-ajax-nav-style]').forEach((node) => node.remove());

        styles.forEach((original) => {
            const style = original.cloneNode(true);

            style.setAttribute('data-ajax-nav-style', '');

            document.head.appendChild(style);
        });
    }

    function removePageScripts() {
        [...document.body.querySelectorAll('script')].forEach((node) => {
            if (node.closest('#app') || node.src) {
                return;
            }

            node.remove();
        });
    }

    async function injectScripts(scripts) {
        const pending = [];

        const token = 'nav' + (++navToken);

        const idMap = {};

        scripts.forEach((original) => {
            const type = (original.getAttribute('type') || '').toLowerCase();

            // The analytics config is a destination-scoped singleton read by the persistent
            // collector/replay modules. removePageScripts already removed the previous value;
            // absence on the destination must remain absence (for opt-out/auth boundaries).
            if (! isExecutableType(type) && original.id && original.id !== 'dam-t') {
                idMap[original.id] = original.id + '-' + token;
            }
        });

        const remapReferences = (text) => {
            return Object.keys(idMap)
                .sort((a, b) => b.length - a.length)
                .reduce((out, oldId) => {
                    const escaped = oldId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

                    return out
                        .replace(new RegExp('#' + escaped + '(?![\\w-])', 'g'), '#' + idMap[oldId])
                        .replace(new RegExp('(getElementById\\(\\s*[\'"])' + escaped + '([\'"])', 'g'), '$1' + idMap[oldId] + '$2');
                }, text);
        };

        scripts.forEach((original) => {
            const type = (original.getAttribute('type') || '').toLowerCase();

            if (! isExecutableType(type)) {
                const clone = original.cloneNode(true);

                if (clone.id && idMap[clone.id]) {
                    clone.id = idMap[clone.id];
                }

                document.body.appendChild(clone);

                return;
            }

            if (original.src) {
                if (document.querySelector('script[src="' + original.src + '"]')) {
                    return;
                }

                pending.push(new Promise((resolve) => {
                    const script = document.createElement('script');
                    script.src = original.src;

                    if (type) {
                        script.type = type;
                    }

                    script.onload = resolve;
                    script.onerror = resolve;

                    document.body.appendChild(script);
                }));
            }
        });

        await Promise.all(pending);

        // Classic inline scripts run now, in order; inline modules run after them, as a page load
        // defers them. A module cannot run through Function() (its `import` is a syntax error
        // there), so it gets a real <script type="module"> and the mount waits for it.
        const modules = [];

        scripts.forEach((original) => {
            const type = (original.getAttribute('type') || '').toLowerCase();

            if (! isExecutableType(type) || original.src) {
                return;
            }

            if (type === 'module') {
                modules.push(original);

                return;
            }

            try {
                new Function(remapReferences(original.textContent))();
            } catch (error) {
                console.error('Ajax navigation: a page script failed to run', error);
            }
        });

        for (const original of modules) {
            await runInlineModule(remapReferences(original.textContent), token);
        }
    }

    /**
     * Run one inline module and settle once it has evaluated or failed to load its imports. A module
     * still pending after the timeout rejects, so the visit falls back to a full page load rather than
     * letting it finish late against an app that has already mounted, or the next page's.
     */
    function runInlineModule(text, token) {
        return new Promise((resolve, reject) => {
            const key = '__ajaxNavModule_' + token + '_' + (++moduleSeq);
            let timer = null;
            const finish = (error = null) => {
                clearTimeout(timer);
                delete window[key];
                error ? reject(error) : resolve();
            };

            window[key] = finish;

            const script = document.createElement('script');
            script.type = 'module';
            script.setAttribute('data-ajax-nav-module', '');
            script.textContent = text + '\n;window[' + JSON.stringify(key) + ']?.();';
            script.onerror = () => {
                console.error('Ajax navigation: a page module failed to load');
                finish();
            };
            timer = setTimeout(() => {
                finish(new Error('Ajax navigation: a page module did not finish'));
            }, MODULE_TIMEOUT_MS);

            document.body.appendChild(script);
        });
    }

    function toggleProgress(active) {
        let bar = document.getElementById('ajax-nav-progress');

        if (active) {
            if (! bar) {
                bar = document.createElement('div');
                bar.id = 'ajax-nav-progress';
                bar.style.cssText = 'position:fixed;top:0;left:0;height:3px;width:0;'
                    + 'background:#7c3aed;z-index:100000;transition:width .2s ease,opacity .3s ease;';
                document.body.appendChild(bar);
            }

            bar.style.opacity = '1';
            bar.style.width = '80%';
        } else if (bar) {
            bar.style.width = '100%';
            bar.style.opacity = '0';
        }
    }
}
