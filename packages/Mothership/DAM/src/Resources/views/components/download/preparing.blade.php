{{--
    The "Preparing download…" card. A DAM download is a plain browser navigation, so nothing on screen moves until the
    server sends headers, and a large zip looks frozen. This shows the item (thumbnail and name) at once, starts the
    download in a hidden same-origin <iframe> (an error page never replaces the current page) and waits for the
    `dam_dl_<token>` cookie the download route sets with its headers (Http/Middleware/SignalDownloadStart).

    Framework-free: no Vue, no $emitter, so any page can include it (the admin layouts, later the public share pages).
    It defines window.damDownload once, however many times it is included.

      <a href="…" data-dam-download data-dam-download-name="menu.png" data-dam-download-thumb="…">   (a click is taken over;
          data-dam-download-folder shows the folder icon instead of the file icon)
      window.damDownload.start({ url, name, thumb })    the card and the download at once
      window.damDownload.start({ name, folder: true })  the card only (a preflight is still running); the handle then
          .go(url)       starts the download in the same card
          .fail(message) shows the error in the same card
          .close()       hides the card (the download, if started, goes on)

    In the app installed to an iPhone or iPad Home Screen there is no download manager and a file navigation replaces the
    app, so there go(url) hands the download to window.mothershipDeviceSave (assets/js/device-save.js, inlined below):
    it fetches the file and opens the share sheet from its own card.
--}}
@pushOnce('scripts')
    <style>
        .dam-dl-bar { display: block; height: 100%; width: 40%; border-radius: 9999px; animation: dam-dl-slide 1.2s ease-in-out infinite; }
        @keyframes dam-dl-slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }
        @media (prefers-reduced-motion: reduce) { .dam-dl-bar { animation: none; width: 100%; opacity: .5; } }
    </style>
    <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/device-save.js')) !!}</script>
    <script>
        (() => {
            if (window.damDownload) return;

            const text = {
                preparing: @js(trans('dam::app.admin.dam.download.preparing')),
                started: @js(trans('dam::app.admin.dam.download.started')),
                failed: @js(trans('dam::app.admin.dam.download.failed')),
                close: @js(trans('dam::app.admin.dam.download.close')),
            };
            const COOKIE = 'dam_dl_';
            const POLL_MS = 250;
            const HIDE_MS = 2000;
            const GIVE_UP_MS = 10 * 60 * 1000;
            const IFRAME_KEEP_MS = 60 * 1000;
            const MAX_VISIBLE = 3;
            const cards = [];
            let host = null;

            const el = (tag, attributes = {}, children = []) => {
                const node = document.createElement(tag);
                for (const [key, value] of Object.entries(attributes)) {
                    if (key === 'text') node.textContent = value;
                    else node.setAttribute(key, value);
                }
                children.forEach((child) => node.appendChild(child));

                return node;
            };
            const svg = (markup) => {
                const holder = document.createElement('span');
                holder.innerHTML = markup;

                return holder.firstElementChild;
            };
            const FILE = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>';
            const FOLDER = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
            const CROSS = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';

            const makeToken = () => {
                const letters = 'abcdefghijklmnopqrstuvwxyz0123456789';
                const bytes = new Uint8Array(16);
                if (window.crypto?.getRandomValues) window.crypto.getRandomValues(bytes);
                else bytes.forEach((_, index) => { bytes[index] = Math.floor(Math.random() * 256); });

                return Array.from(bytes, (byte) => letters[byte % letters.length]).join('');
            };
            const hasCookie = (name) => document.cookie.split(';').some((part) => part.trim().startsWith(name + '='));
            const expireCookie = (name) => {
                document.cookie = name + '=; Max-Age=0; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            };

            // The card stacks above the upload panel when that is on screen too (both sit bottom-right). It sits at z-index
            // 10030: above the image/video and collections viewers (10010) and their dialogs and menus (10011, 10020), below
            // the skip link (10050) and the flash group (10060).
            const place = () => {
                if (!host) return;
                const panel = document.querySelector('[data-dam-upload-panel]');
                const height = panel ? panel.getBoundingClientRect().height : 0;
                host.style.bottom = height > 0 ? 'calc(1rem + ' + Math.ceil(height) + 'px + .75rem)' : '1rem';
            };
            const ensureHost = () => {
                if (host && host.isConnected) return host;
                host = el('div', {
                    'data-dam-download-host': '',
                    style: 'position:fixed;bottom:1rem;inset-inline-end:1rem;z-index:10030;display:flex;flex-direction:column;align-items:flex-end;gap:.5rem;max-width:calc(100vw - 2rem);pointer-events:none;',
                });
                document.body.appendChild(host);

                return host;
            };
            // At most three cards show; an older one waits (still polling) and returns when a newer one goes.
            const layout = () => {
                const shown = cards.filter((card) => !card.closed);
                shown.forEach((card, index) => { card.node.style.display = index < shown.length - MAX_VISIBLE ? 'none' : ''; });
                place();
            };

            function start(options = {}) {
                const name = String(options.name ?? '');
                const closeLabel = text.close;
                const thumb = el('span', {
                    'data-dam-dl-thumb': '',
                    class: 'bg-gray-100 dark:bg-cherry-700 text-gray-500 dark:text-gray-300',
                    style: 'flex:none;display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:.375rem;overflow:hidden;',
                });
                const placeholder = () => { thumb.replaceChildren(svg(options.folder ? FOLDER : FILE)); };
                if (options.thumb) {
                    const image = el('img', { src: String(options.thumb), alt: '', class: 'object-contain', draggable: 'false', style: 'width:48px;height:48px;object-fit:contain;' });
                    image.addEventListener('error', placeholder);
                    thumb.appendChild(image);
                } else {
                    placeholder();
                }
                const nameLine = el('p', { 'data-dam-dl-name': '', class: 'text-sm font-medium text-gray-800 dark:text-gray-100 truncate', title: name, text: name });
                // Both live regions are inserted empty and filled afterwards: a screen reader announces a change inside a live
                // region it already knows, not a region that arrives carrying its text. A failure speaks through its own
                // `alert` region instead of the status region changing role.
                const status = el('p', { 'data-dam-dl-status': '', role: 'status', class: 'text-xs text-gray-600 dark:text-gray-300' });
                const alert = el('p', { 'data-dam-dl-alert': '', role: 'alert', class: 'text-xs text-red-600 dark:text-red-400' });
                const close = el('button', {
                    type: 'button',
                    'data-dam-dl-close': '',
                    'aria-label': closeLabel,
                    title: closeLabel,
                    class: 'p-1 rounded text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white transition-colors',
                    style: 'flex:none;',
                }, [svg(CROSS)]);
                const fill = el('span', { class: 'dam-dl-bar bg-primary-600 dark:bg-primary-500' });
                const bar = el('div', {
                    role: 'progressbar',
                    'aria-label': text.preparing,
                    'data-dam-dl-bar': '',
                    class: 'bg-gray-200 dark:bg-cherry-700',
                    style: 'height:4px;overflow:hidden;',
                }, [fill]);
                const node = el('div', {
                    'data-dam-download-card': '',
                    class: 'rounded-xl shadow-2xl overflow-hidden border border-gray-300 dark:border-cherry-600 bg-white dark:bg-cherry-800',
                    style: 'width:360px;max-width:calc(100vw - 2rem);flex-shrink:0;pointer-events:auto;',
                }, [
                    el('div', { style: 'display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;' }, [
                        thumb,
                        el('div', { style: 'min-width:0;flex:1;' }, [nameLine, status, alert]),
                        close,
                    ]),
                    bar,
                ]);

                const card = { node, closed: false, state: 'idle', token: null, iframe: null, poll: null, giveUp: null };
                cards.push(card);
                ensureHost().appendChild(node);
                layout();
                setTimeout(() => { if (card.state === 'idle' || card.state === 'preparing') status.textContent = text.preparing; }, 0);

                const dropIframe = () => { card.iframe?.remove(); card.iframe = null; };
                const stopWatching = () => {
                    clearInterval(card.poll);
                    clearTimeout(card.giveUp);
                    card.poll = card.giveUp = null;
                };
                const remove = () => {
                    card.closed = true;
                    node.remove();
                    const index = cards.indexOf(card);
                    if (index >= 0) cards.splice(index, 1);
                    layout();
                };
                const finish = () => {
                    stopWatching();
                    if (card.token) expireCookie(COOKIE + card.token);
                };
                const fail = (message) => {
                    if (card.state === 'started' || card.state === 'failed') return;
                    card.state = 'failed';
                    finish();
                    bar.style.display = 'none';
                    status.textContent = '';
                    alert.textContent = String(message || '') || text.failed;
                    alert.title = alert.textContent;
                    if (card.closed) dropIframe();
                    place();
                };
                const started = () => {
                    if (card.state !== 'preparing') return;
                    card.state = 'started';
                    finish();
                    bar.style.display = 'none';
                    status.textContent = text.started;
                    setTimeout(remove, HIDE_MS);
                    setTimeout(dropIframe, IFRAME_KEEP_MS);
                };
                const serverMessage = (frame) => {
                    try {
                        const doc = frame.contentDocument;
                        const body = (doc?.body?.innerText || doc?.body?.textContent || '').trim();
                        try {
                            const parsed = JSON.parse(body);
                            if (parsed && typeof parsed.message === 'string' && parsed.message) return parsed.message;
                        } catch (_) { /* not JSON: the page title is the next best thing */ }

                        return (doc?.title || '').trim();
                    } catch (_) {
                        return '';
                    }
                };
                const go = (url) => {
                    if (card.state !== 'idle') return;
                    // The installed iPhone and iPad app: this card goes and the save card (device-save.js) takes over.
                    if (window.mothershipDeviceSave?.active) {
                        hide();
                        window.mothershipDeviceSave.save({ url: String(url), name });

                        return;
                    }
                    // Build the URL before the card leaves idle: a bad one would otherwise strand a card with no poll or timer.
                    const token = makeToken();
                    let target;
                    try {
                        target = new URL(String(url), location.href);
                        target.searchParams.set('dl', token);
                    } catch (_) {
                        fail(text.failed);
                        return;
                    }
                    card.state = 'preparing';
                    card.token = token;
                    const frame = el('iframe', {
                        'data-dam-dl-frame': '',
                        tabindex: '-1',
                        'aria-hidden': 'true',
                        title: '',
                        style: 'position:absolute;width:0;height:0;border:0;visibility:hidden;',
                    });
                    card.iframe = frame;
                    // A page in the frame (not a download) means the server answered with an error.
                    frame.addEventListener('load', () => {
                        if (card.state !== 'preparing') return;
                        try {
                            if (frame.contentWindow.location.href === 'about:blank') return;
                        } catch (_) { /* another origin answered: it is a page, not a download */ }
                        // The download may have started and replaced the frame's document with its own load event.
                        if (hasCookie(COOKIE + card.token)) { started(); return; }
                        fail(serverMessage(frame));
                    });
                    frame.src = target.href;
                    document.body.appendChild(frame);
                    card.poll = setInterval(() => {
                        if (hasCookie(COOKIE + card.token)) started();
                        place();
                    }, POLL_MS);
                    // Ten minutes without a start is a failure the person should see, not a card that vanishes.
                    card.giveUp = setTimeout(() => { if (card.state === 'preparing') { fail(text.failed); dropIframe(); } }, GIVE_UP_MS);
                };
                const hide = () => {
                    // Only the card goes; a download that has begun, or is still being prepared, carries on.
                    if (card.state === 'failed' || card.state === 'idle') {
                        finish();
                        dropIframe();
                    }
                    remove();
                };
                close.addEventListener('click', hide);

                const handle = { go, fail, close: hide };
                if (options.url) go(options.url);

                return handle;
            }

            // On window, in the capture phase: the admin's AJAX page navigation (plugins/navigation.js) listens on document in
            // the capture phase and takes over every same-origin link, so a document-level (or bubble) listener never sees
            // the click first. Window capture runs before document capture; preventDefault then keeps that navigation out.
            window.addEventListener('click', (event) => {
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                const link = event.target instanceof Element ? event.target.closest('a[data-dam-download]') : null;
                if (!link || !link.href) return;
                // A link that opens elsewhere (target=_blank, a named frame) is the browser's to handle. `download` stays
                // allowed: the share pages keep it as the no-JS fallback next to data-dam-download.
                if (link.target && link.target !== '_self') return;
                event.preventDefault();
                start({
                    url: link.href,
                    name: link.getAttribute('data-dam-download-name') || link.getAttribute('download') || '',
                    thumb: link.getAttribute('data-dam-download-thumb') || '',
                    folder: link.hasAttribute('data-dam-download-folder'),
                });
            }, true);

            window.damDownload = { start };
        })();
    </script>
@endPushOnce
