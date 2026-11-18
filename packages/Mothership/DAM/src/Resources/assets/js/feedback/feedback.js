// Feedback & bug reports. Inlined into the admin layout's <head> while
// dam.feedback.enabled is on, so the console buffer starts before any app script; tested by
// packages/Mothership/DAM/tests/js/feedback.test.mjs. The server re-redacts everything (FeedbackRedactor): this copy only
// makes the preview the person sees match what is sent.
(() => {
    'use strict';

    const MASK = '[redacted]';
    const SENSITIVE_KEY = /token|secret|pass|key|sig|auth|code|state|session|cookie|share|expires|^t$|^s$/i;
    const MAX_LINES = 20;
    const MAX_MESSAGE = 500;

    const redactQuery = (query) => query.split('&').map((pair) => {
        const at = pair.indexOf('=');
        if (at < 0) {
            return pair;
        }
        let name = pair.slice(0, at);
        try {
            name = decodeURIComponent(name.replace(/\+/g, ' '));
        } catch (_) {
            // A malformed escape keeps its raw name.
        }

        return SENSITIVE_KEY.test(name) ? `${pair.slice(0, at)}=${MASK}` : `${pair.slice(0, at)}=${redactValue(pair.slice(at + 1))}`;
    }).join('&');

    // An ordinary key's value stays as sent unless its decoded text holds something the text rules mask (an opaque
    // token, an encoded secret=value); then the whole value is masked, so the query's boundaries stay intact.
    const redactValue = (raw) => {
        let decoded = raw;
        try {
            decoded = decodeURIComponent(raw.replace(/\+/g, ' '));
        } catch (_) {
            // A malformed escape is checked as it is.
        }

        return redactText(decoded, decoded.length + 1) === decoded ? raw : MASK;
    };

    const redactText = (text, max = MAX_MESSAGE) => {
        let out = String(text ?? '');
        // A Cookie or Set-Cookie header is masked to the end of its line: its later pairs have ordinary names.
        out = out.replace(/\b((?:set-)?cookie\s*:\s*)[^\r\n]*/gi, `$1${MASK}`);
        out = out.replace(/\b(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]+/gi, `$1 ${MASK}`);
        out = out.replace(/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/g, MASK);
        // A quoted value is masked through its closing quote, escapes included.
        out = out.replace(/\b([A-Za-z0-9_.-]*(?:token|secret|pass(?:word)?|api[_-]?key|sig(?:nature)?|auth|session|cookie|xsrf|csrf)[A-Za-z0-9_.-]*)(["']?\s*[=:]\s*)("(?:[^"\\]|\\.)*"?|'(?:[^'\\]|\\.)*'?|[^\s"'&,;}]+)/gi,
            (_, name, sep, value) => {
                const quote = value[0] === '"' || value[0] === "'" ? value[0] : '';

                return `${name}${sep}${quote}${MASK}${quote}`;
            });
        out = out.replace(/\b[A-Za-z0-9_-]{32,}\b/g, MASK);
        out = out.replace(/((?:\b[a-z][a-z0-9+.-]*:)?\/\/)[^\s/?#@"'<>]+@/gi, `$1${MASK}@`);
        // Every URL-like token (any run holding a / or ?: absolute, root-, path-, dot- and query-relative alike) gets
        // the query rules and loses its fragment.
        out = out.replace(/[^\s"'<>]*[/?#][^\s"'<>]*/g, (match) => {
            if (!/[/?]/.test(match) && !match.slice(match.indexOf('#')).includes('=')) {
                return match;
            }
            const url = match.replace(/#.*$/s, '');
            const at = url.indexOf('?');

            return at < 0 ? url : `${url.slice(0, at)}?${redactQuery(url.slice(at + 1))}`;
        });

        return out.slice(0, max);
    };

    // Only the path and a cleaned query: no origin, credentials or fragment.
    const redactUrl = (href, max = 2048) => {
        try {
            const url = new URL(String(href), 'https://invalid.local');
            const query = url.search.length > 1 ? `?${redactQuery(url.search.slice(1))}` : '';

            return `${redactText(url.pathname, max)}${query}`.slice(0, max);
        } catch (_) {
            return null;
        }
    };

    // What one console argument may contribute: strings and an Error's name and message, never an object's fields
    // (an axios error carries its request config, headers and body).
    const projectArg = (arg) => {
        if (typeof arg === 'string') {
            return arg;
        }
        if (arg instanceof Error || (arg && typeof arg === 'object' && typeof arg.message === 'string' && typeof arg.name === 'string')) {
            return `${arg.name}: ${arg.message}`;
        }
        if (typeof arg === 'number' || typeof arg === 'boolean' || arg === null || arg === undefined) {
            return String(arg);
        }

        return '[object]';
    };

    const line = (kind, message, source, lineNo) => {
        const out = { ts: new Date().toISOString(), kind, message: redactText(message) };
        if (typeof source === 'string' && source !== '') {
            out.source = redactUrl(source, 300);
        }
        if (Number.isInteger(lineNo)) {
            out.line = lineNo;
        }

        return out;
    };

    const installConsoleBuffer = (win) => {
        if (win.__damFeedbackConsole) {
            return win.__damFeedbackConsole;
        }
        const lines = [];
        const push = (entry) => {
            lines.push(entry);
            if (lines.length > MAX_LINES) {
                lines.shift();
            }
        };

        win.addEventListener?.('error', (event) => {
            push(line('error', event.error ? projectArg(event.error) : String(event.message ?? 'Script error'), event.filename, event.lineno));
        });
        win.addEventListener?.('unhandledrejection', (event) => push(line('unhandledrejection', projectArg(event.reason))));

        const original = win.console?.error;
        if (typeof original === 'function') {
            win.console.error = function (...args) {
                try {
                    push(line('console', args.map(projectArg).join(' ')));
                } catch (_) {
                    // Collecting never breaks logging.
                }

                return original.apply(this, args);
            };
        }

        win.__damFeedbackConsole = { lines: () => lines.slice(), clear: () => lines.splice(0, lines.length) };

        return win.__damFeedbackConsole;
    };

    const describeBrowser = (ua = '') => {
        const browser = (/Edg\/(\d+)/.exec(ua) && `Edge ${RegExp.$1}`)
            || (/Firefox\/(\d+)/.exec(ua) && `Firefox ${RegExp.$1}`)
            || (/Chrome\/(\d+)/.exec(ua) && `Chrome ${RegExp.$1}`)
            || (/Version\/(\d+)[.\d]* .*Safari/.exec(ua) && `Safari ${RegExp.$1}`)
            || 'Unknown browser';
        const os = (/iPhone|iPad/.test(ua) && 'iOS')
            || (/Android/.test(ua) && 'Android')
            || (/Mac OS X/.test(ua) && 'macOS')
            || (/Windows/.test(ua) && 'Windows')
            || (/CrOS/.test(ua) && 'ChromeOS')
            || (/Linux/.test(ua) && 'Linux')
            || 'Unknown OS';

        return { browser, os };
    };

    // The context the dialog shows and sends. The server adds the reporter, the build and its own time.
    const collectContext = (win, { includeConsole = true } = {}) => {
        const { browser, os } = describeBrowser(win.navigator?.userAgent ?? '');

        return {
            browser,
            os,
            viewport: `${win.innerWidth ?? 0}x${win.innerHeight ?? 0}`,
            dpr: String(win.devicePixelRatio ?? 1),
            language: String(win.navigator?.language ?? ''),
            timezone: (() => {
                try {
                    return Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
                } catch (_) {
                    return '';
                }
            })(),
            client_time: new Date().toISOString(),
            console: includeConsole && win.__damFeedbackConsole ? win.__damFeedbackConsole.lines() : [],
        };
    };

    const api = { MASK, redactText, redactUrl, projectArg, installConsoleBuffer, describeBrowser, collectContext };

    if (typeof window !== 'undefined') {
        window.MothershipFeedback = api;
        installConsoleBuffer(window);
    }
})();
