import { onCLS, onFCP, onINP, onLCP, onTTFB } from "web-vitals";
import { errorDetails } from "./error-details.js";

/*
 * The usage collector (P1b, roadmap §3.6.6). Official web-vitals replaces custom timing proxies. It reads only its own
 * config (#dam-t) and what a page hands window.damTrack: an event name from the server's allow-list, a subject id,
 * typed props, a surface. Never text, a URL or a DOM node; the server re-derives who sent it.
 *
 * Batches go out every f seconds or b events, and on page hide by beacon. The queue holds q events and takes r a
 * minute; anything over is dropped, as is a batch the server refuses. Nothing is retried.
 */
(() => {
    const config = document.getElementById("dam-t");

    if (!config || window.damTrack) {
        return;
    }

    const c = JSON.parse(config.textContent);
    const KEY = "dam.t";
    const queue = [];
    let timer = 0;
    let windowStart = 0;
    let used = 0;
    let errors = 0;
    let memory;

    const uuid = () => crypto.randomUUID
        ? crypto.randomUUID()
        : "10000000-1000-4000-8000-100000000000".replace(/[018]/g, x =>
            (x ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (x / 4)))).toString(16));

    // A session (§3.6.2): one tab, ended by 30 minutes idle, a new login (marker) or midnight.
    const session = bump => {
        let s = memory;

        try {
            s = JSON.parse(sessionStorage.getItem(KEY)) || s;
        } catch (e) {}

        const now = Date.now();
        const day = new Date().toDateString();

        if (!s || s.m !== c.m || s.d !== day || now - s.a > 18e5) {
            s = { i: uuid(), q: 0, m: c.m, d: day };
        }

        if (bump) {
            s.a = now;
            s.q++;
        }

        memory = s;

        try {
            sessionStorage.setItem(KEY, JSON.stringify(s));
        } catch (e) {}

        // The app's own requests carry the session, so the server's events join it.
        if (window.axios) {
            window.axios.defaults.headers.common["X-Dam-Sid"] = s.i;
        }

        return s;
    };

    const flush = beacon => {
        clearTimeout(timer);
        timer = 0;

        while (queue.length) {
            const body = JSON.stringify({ t: c.t, e: queue.splice(0, c.b) });

            if (beacon && navigator.sendBeacon && navigator.sendBeacon(c.e, body)) {
                continue;
            }

            fetch(c.e, { method: "POST", body, keepalive: true, credentials: "same-origin", headers: { "Content-Type": "application/json" } })
                .then(response => {
                    const token = response.headers.get("X-Dam-T");

                    if (token) {
                        c.t = token;
                    }
                })
                .catch(() => {});
        }
    };

    // Coarse device context on every event: a class and a width band, never exact sizes or a user agent.
    const context = () => {
        const w = innerWidth;
        const coarse = matchMedia("(pointer: coarse)").matches;

        return {
            dc: coarse ? (Math.min(screen.width, screen.height) < 600 ? "phone" : "tablet") : "desktop",
            vw: w < 640 ? "lt640" : w < 1024 ? "640-1023" : w < 1440 ? "1024-1439" : w < 1920 ? "1440-1919" : "ge1920",
        };
    };

    const track = (name, subject, props, surface, duration) => {
        const now = Date.now();

        if (!c.n.includes(name)) {
            return;
        }

        if (now - windowStart > 6e4) {
            windowStart = now;
            used = 0;
        }

        if (++used > c.r || queue.length >= c.q) {
            return;
        }

        const s = session(true);
        const event = { id: uuid(), n: name, ts: now, sid: s.i, seq: s.q, p: props || {}, x: context() };
        const replay = window.damReplay?.sessionId?.();
        if (replay) event.rs = replay;

        if (subject) {
            event.s = subject;
        }

        if (surface || c.u) {
            event.sf = surface || c.u;
        }

        if (duration != null) {
            event.d = Math.round(duration);
        }

        queue.push(event);
        queue.length >= c.b ? flush() : (timer ||= setTimeout(flush, c.f * 1e3));
    };

    window.damTrack = track;
    session(false);

    // The route denominator: this document, each admin SPA visit and each back-forward-cache restore. The route is
    // re-read from the config the page shows now; a page without one (an opt-out boundary) stops route views until a
    // configured page is shown again, so nothing stale is ever sent for it.
    let route = c.o;
    const shown = () => {
        try {
            return JSON.parse(document.getElementById("dam-t")?.textContent || "null")?.o || null;
        } catch (e) {
            return null;
        }
    };
    // A link page's view token comes back with it: the server counts the link's view only then, so a mail scanner
    // that fetches the page without running it is never counted.
    const viewed = navigation => route && track("route.viewed", 0, c.k ? { route, navigation, view_token: c.k } : { route, navigation });
    viewed("document");
    document.addEventListener("mothership:navigate:success", () => {
        route = shown();
        viewed("spa");
    });
    addEventListener("pageshow", event => {
        if (event.persisted) {
            route = shown();
            viewed("restore");
        }
    });

    const error = kind => event => errors++ < 5 && track("client.error.raised", 0, {
        kind, route: c.o, ...errorDetails({ event, kind, origin: location.origin, pathname: location.pathname }),
    });
    addEventListener("error", error("error"));
    addEventListener("unhandledrejection", error("rejection"));

    // A link page's file counts as viewed after a second on screen (§3.6.4); its token ties the view to the visit.
    if (c.a) {
        let shown;
        const view = () => {
            if (document.visibilityState === "visible" && !shown) {
                shown = setTimeout(() => track("asset.preview.opened", c.a, { view_token: c.k, visible_ms: 1000 }), 1000);
            }
        };

        view();
        addEventListener("visibilitychange", view);
    }

    // Lifecycle snapshots: CLS/INP may change after returning from hidden. Reports
    // select the highest revision per metric_id before calculating percentiles.
    // An unchanged hide emits nothing; a BFCache restore has new metric IDs.
    // SPA changes belong to the initial document route, never the callback route.
    // Only scalar metrics leave the library: no entries, targets, URLs or text.
    if (Math.random() < c.v) {
        const route = c.o;
        const reported = new Map();
        // A history reload (not a BFCache restore, which is timed from pageshow) can be timed from long before it
        // was asked for: one phone read TTFB 78 s. Only its interaction metrics are kept.
        const loadTimings = new Set(["TTFB", "FCP", "LCP"]);
        const report = ({ name, value, id, navigationType }) => {
            if (!Number.isFinite(value) || value < 0) return;
            if (navigationType === "back-forward" && loadTimings.has(name)) return;
            const previous = reported.get(id);
            const rounded = Math.round(value * (name === "CLS" ? 1000 : 1));
            if (previous?.value === rounded) return;
            const revision = (previous?.revision || 0) + 1;
            reported.set(id, { value: rounded, revision });
            track("client.web_vitals.sampled", 0, {
                metric: name,
                value: rounded,
                route,
                metric_id: id,
                metric_revision: revision,
                measurement: "web-vitals-6.2.3",
                navigation_type: navigationType,
            });
            // Library callbacks may run after our hide listener (including an
            // INP idle task). Flush that final callback while the page is hidden.
            if (document.visibilityState === "hidden") flush(true);
        };
        onCLS(report);
        onFCP(report);
        onINP(report);
        onLCP(report);
        onTTFB(report);
    }

    addEventListener("visibilitychange", () => {
        if (document.visibilityState !== "hidden") {
            return;
        }

        flush(true);
    });
})();
