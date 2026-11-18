/**
 * One reading of a failed request for every screen.
 *
 * A failed, denied or expired request must never look like success, an empty
 * folder, stale results or a deleted folder. Screens used to read
 * `error.response.data.message` themselves, so a network failure threw a
 * TypeError (no `response`), a 419 showed "CSRF token mismatch." and an expired
 * session looked like an empty grid. This module owns that reading:
 *
 * - `describeRequestError(error)` turns an axios error, a fetch failure or a
 *   `{ status, data }` pair into `{ kind, status, message, retryable }` with
 *   plain wording. A server message is kept when it is written for people (a
 *   validation reason, a refusal); framework defaults and debug traces are not.
 * - The axios interceptor rewrites those framework messages in place, so every
 *   existing toast that shows `data.message` reads plainly, and it hands a 401,
 *   a 419 or a redirect to the sign-in page to the session notice.
 * - The session notice says the session expired and offers "Sign in again" in a
 *   new tab, so whatever the user typed on this page stays. When the tab comes
 *   back signed in, the page's CSRF tokens are refreshed and the user retries.
 *
 * Wording comes from `window.mothershipRequestText` (the layout renders it from
 * `admin::app.requests`); the English defaults below only cover a page that
 * renders without it.
 */
import axios from "axios";
import { readFailedSessionProbe, readSessionProbe, refusalKind } from "./session-probe";

const DEFAULT_TEXT = {
    network: "Couldn't reach the server. Check your connection and try again.",
    session: "Your session expired. Sign in again, then retry.",
    stale: "This page was out of date. Try again.",
    forbidden: "You don't have permission to do that.",
    "not-found": "This item no longer exists, or you no longer have access to it.",
    invalid: "Some of the details aren't valid. Check them and try again.",
    "too-large": "The file is too large to upload.",
    throttled: "Too many requests. Wait a moment and try again.",
    server: "Something went wrong on the server. Try again in a moment.",
    unavailable: "The Mothership is briefly unavailable. Try again in a moment.",
    "session-title": "Your session expired",
    "session-body": "Sign in again in a new tab. This page stays as it is, so nothing you typed is lost.",
    "session-sign-in": "Sign in again",
    "session-check": "I've signed in",
    "session-restored": "You're signed in again. Retry what you were doing.",
    "session-still-out": "You're still signed out. Sign in in the other tab, then come back.",
    dismiss: "Dismiss",
    retry: "Retry",
};

/**
 * Laravel's own response texts, plus the `{error, description}` titles the
 * Core handler renders. None of them tell a person what happened or what to do.
 */
const FRAMEWORK_MESSAGES = new Set([
    "",
    "Server Error",
    "Internal Server Error",
    "CSRF token mismatch.",
    "Page Expired",
    "This action is unauthorized.",
    "Unauthenticated.",
    "Unauthorized",
    "Forbidden",
    "Not Found",
    "Too Many Attempts.",
    "Too Many Requests",
    "Service Unavailable",
    "Bad Gateway",
    "Gateway Timeout",
    "Method Not Allowed",
    "The given data was invalid.",
]);

export function text(key) {
    return (window.mothershipRequestText && window.mothershipRequestText[key]) || DEFAULT_TEXT[key] || "";
}

function kindForStatus(status) {
    if (status === 401 || status === 419) return "session";
    if (status === 403) return "forbidden";
    if (status === 404 || status === 410) return "not-found";
    if (status === 413) return "too-large";
    if (status === 422 || status === 400 || status === 409) return "invalid";
    if (status === 429) return "throttled";
    if (status === 502 || status === 503 || status === 504) return "unavailable";
    if (status >= 500) return "server";

    return "server";
}

/** Retry can only help when the cause is outside the request itself. */
const RETRYABLE = new Set(["network", "session", "stale", "throttled", "server", "unavailable"]);

function firstValidationMessage(data) {
    const errors = data && typeof data === "object" ? data.errors : null;

    if (! errors || typeof errors !== "object") return "";

    for (const value of Object.values(errors)) {
        const message = Array.isArray(value) ? value[0] : value;

        if (typeof message === "string" && message.trim()) return message;
    }

    return "";
}

/**
 * The server's own message when it is meant for people, else "".
 *
 * Debug payloads (`exception`, `trace`) and 5xx bodies carry exception text,
 * never wording for a person, unless a controller wrote it on purpose (no
 * `exception` key).
 */
export function serverMessage(status, data) {
    if (! data || typeof data !== "object") return "";

    if (data.exception || data.trace) return status === 422 ? firstValidationMessage(data) : "";

    const message = typeof data.message === "string" ? data.message.trim() : "";

    if (status === 422 && (! message || FRAMEWORK_MESSAGES.has(message))) {
        const validation = firstValidationMessage(data);

        // No field errors: the Core handler's abort(422, reason) keeps the reason in `description`.
        return validation || (typeof data.description === "string" ? data.description.trim() : "");
    }

    if (message && ! FRAMEWORK_MESSAGES.has(message)) return message;

    // The Core handler's 422 abort keeps its reason in `description`; other statuses only have generic copy there.
    if (status === 422 && typeof data.description === "string") return data.description;

    return "";
}

/**
 * Describe a failed request.
 *
 * @param {*} error  an axios error, a fetch rejection, or `{ response: { status, data } }`
 * @returns {{ kind: string, status: number, message: string, retryable: boolean, cancelled: boolean }}
 */
export function describeRequestError(error, fallback = "") {
    if (axios.isCancel?.(error) || error?.name === "AbortError" || error?.code === "ERR_CANCELED") {
        return { kind: "cancelled", status: 0, message: "", retryable: true, cancelled: true };
    }

    const response = error?.response;
    const status = Number(response?.status || 0);

    if (! status) {
        return { kind: "network", status: 0, message: text("network"), retryable: true, cancelled: false };
    }

    // A 419 while the session is alive: the token rotated (another tab, a long-open page). The probe has
    // already refreshed it, so a plain retry works and "sign in again" would be wrong.
    const kind = error?.mothershipStaleToken ? "stale" : kindForStatus(status);
    // On the sign-in page a 401 is the answer to the credentials ("these details are wrong"), not a lapsed session.
    const own = (kind === "session" && ! isSignInPage()) || kind === "stale" ? "" : serverMessage(status, response.data);

    return {
        kind,
        status,
        message: own || (kind === "invalid" && fallback ? fallback : "") || text(kind) || fallback,
        retryable: RETRYABLE.has(kind),
        cancelled: false,
    };
}

export function requestErrorMessage(error, fallback = "") {
    return describeRequestError(error, fallback).message;
}

/* ------------------------------------------------------------------ */
/* Session notice                                                      */
/* ------------------------------------------------------------------ */

const sessionUrls = () => window.mothershipSession || {};

// A hung probe must not hold every 401/419 request open: the probe gives up, and the answer is "unknown".
const PROBE_TIMEOUT_MS = 10000;

let notice = null;
let probing = null;

function isSignInPage() {
    const login = sessionUrls().login;

    if (! login) return false;

    try {
        return new URL(login, window.location.href).pathname === window.location.pathname;
    } catch {
        return false;
    }
}

function landedOnSignIn(response) {
    const login = sessionUrls().login;
    const landed = response?.request?.responseURL;

    if (! login || ! landed) return false;

    try {
        return new URL(landed).pathname === new URL(login, window.location.href).pathname;
    } catch {
        return false;
    }
}

/**
 * Put a fresh CSRF token everywhere the page carries one, so a form rendered
 * before the session expired can be submitted after signing in again.
 */
function applyToken(token) {
    if (! token) return;

    document.querySelectorAll('input[name="_token"]').forEach(input => { input.value = token; });
    document.querySelectorAll('meta[name="csrf-token"]').forEach(meta => meta.setAttribute("content", token));
    document.querySelectorAll("[data-csrf]").forEach(element => { element.dataset.csrf = token; });

    if (axios.defaults.headers.common["X-CSRF-TOKEN"]) {
        axios.defaults.headers.common["X-CSRF-TOKEN"] = token;
    }
}

/**
 * Ask the server whether this browser is signed in. Resolves true (and
 * refreshes the page's tokens) or false. Never throws.
 */
export function probeSession() {
    const url = sessionUrls().token;

    if (! url) return Promise.resolve(null);

    if (! probing) {
        probing = axios.get(url, { headers: { Accept: "application/json" }, timeout: PROBE_TIMEOUT_MS, _mothershipSessionProbe: true })
            .then(response => {
                // Only the endpoint's own token payload means signed in; a 200 sign-in page does not.
                const { signedIn, token } = readSessionProbe(
                    { data: response.data, responseURL: response.request?.responseURL },
                    { login: sessionUrls().login, base: window.location.href },
                );

                if (signedIn) applyToken(token);

                return signedIn;
            })
            .catch(error => readFailedSessionProbe(error?.response?.status))
            .finally(() => { setTimeout(() => { probing = null; }, 0); });
    }

    return probing;
}

function renderNotice(state) {
    if (! document.body) return;

    if (! notice) {
        notice = document.createElement("div");
        notice.setAttribute("role", "alertdialog");
        notice.setAttribute("aria-live", "assertive");
        notice.dataset.sessionNotice = "true";
        notice.className = "fixed inset-x-0 top-3 z-[10080] mx-auto flex w-[min(560px,calc(100vw-24px))] flex-col gap-2 rounded-lg border border-amber-300 bg-white p-4 text-sm text-gray-800 shadow-lg dark:border-amber-500/60 dark:bg-cherry-900 dark:text-gray-100";
        document.body.appendChild(notice);
    }

    notice.dataset.state = state;
    notice.replaceChildren();

    const title = document.createElement("p");
    title.className = "font-semibold";

    const body = document.createElement("p");
    body.className = "text-gray-600 dark:text-gray-300";

    const actions = document.createElement("div");
    actions.className = "flex flex-wrap items-center gap-2";

    const button = (label, primary, onClick) => {
        const element = document.createElement("button");
        element.type = "button";
        element.textContent = label;
        element.className = primary ? "primary-button" : "secondary-button";
        element.addEventListener("click", onClick);
        actions.appendChild(element);

        return element;
    };

    if (state === "restored") {
        title.textContent = text("session-restored");
        notice.append(title);
        button(text("dismiss"), false, hideNotice);
        setTimeout(() => { if (notice?.dataset.state === "restored") hideNotice(); }, 6000);
    } else {
        title.textContent = text("session-title");
        body.textContent = state === "still-out" ? text("session-still-out") : text("session-body");
        notice.append(title, body);

        button(text("session-sign-in"), true, () => {
            window.open(sessionUrls().login, "_blank", "noopener");
        });

        button(text("session-check"), false, () => checkAndRestore());
    }

    notice.append(actions);
}

function hideNotice() {
    notice?.remove();
    notice = null;
}

function checkAndRestore() {
    return probeSession().then(signedIn => {
        if (signedIn === true) {
            renderNotice("restored");
            document.dispatchEvent(new CustomEvent("mothership:session:restored"));
        } else if (signedIn === false) {
            renderNotice("still-out");
        }

        return signedIn;
    });
}

/**
 * The server refused for lack of a session (401, 419 or a redirect to sign-in).
 * A 419 can also be a token rotated by another tab while the session is fine:
 * the probe tells them apart, refreshing the token in that case.
 */
export function sessionExpired() {
    if (isSignInPage()) return Promise.resolve(false);

    return probeSession().then(signedIn => {
        if (signedIn === false) {
            if (! notice || notice.dataset.state === "restored") renderNotice("expired");
            document.dispatchEvent(new CustomEvent("mothership:session:expired"));
        }

        return signedIn;
    });
}

// Coming back from the sign-in tab: check once, quietly, while the notice is up.
window.addEventListener("focus", () => {
    if (notice && notice.dataset.state !== "restored") checkAndRestore();
});

/* ------------------------------------------------------------------ */
/* axios                                                               */
/* ------------------------------------------------------------------ */

function rewriteMessage(error) {
    const response = error.response;

    if (! response) return;

    const described = describeRequestError(error);

    if (response.data && typeof response.data === "object" && ! Array.isArray(response.data)) {
        if (response.data.message !== described.message) {
            response.data.raw_message = response.data.message;
            response.data.message = described.message;
        }
    } else if (! (response.data instanceof Blob) && ! (response.data instanceof ArrayBuffer)) {
        // An HTML error page (or nothing) where JSON was expected.
        response.data = { message: described.message, raw: response.data };
    }
}

/**
 * Wait for the session answer, but stop waiting (with "unknown") the moment the caller cancels or its
 * deadline fires, so an upload's own timeout still settles while the probe is outstanding.
 */
function untilAborted(promise, signal) {
    if (! signal) return promise;

    if (signal.aborted) return Promise.resolve(null);

    return new Promise(resolve => {
        const onAbort = () => resolve(null);

        signal.addEventListener("abort", onAbort, { once: true });
        promise.then(resolve, () => resolve(null)).finally(() => signal.removeEventListener("abort", onAbort));
    });
}

export function installRequestErrorInterceptor(instance = axios) {
    if (instance.__mothershipRequestErrors) return;

    instance.__mothershipRequestErrors = true;

    instance.interceptors.response.use(
        (response) => {
            // XHR follows redirects, so a request that bounced to the sign-in page arrives as a 200 HTML page.
            if (! response.config?._mothershipSessionProbe && landedOnSignIn(response) && ! isSignInPage()) {
                const error = new axios.AxiosError("Session expired", "ERR_BAD_RESPONSE", response.config, response.request, {
                    ...response,
                    status: 401,
                    data: { message: text("session") },
                });

                sessionExpired();

                return Promise.reject(error);
            }

            return response;
        },
        (error) => {
            if (error?.config?._mothershipSessionProbe) return Promise.reject(error);

            const status = error?.response?.status;

            if (status === 401 || status === 419) {
                return untilAborted(sessionExpired(), error.config?.signal).then(signedIn => {
                    if (refusalKind(status, signedIn) === "stale") error.mothershipStaleToken = true;

                    rewriteMessage(error);

                    return Promise.reject(error);
                });
            }

            rewriteMessage(error);

            return Promise.reject(error);
        },
    );
}

window.mothership = window.mothership || {};
window.mothership.requestError = describeRequestError;
window.mothership.requestErrorMessage = requestErrorMessage;
window.mothership.requestErrorText = text;
window.mothership.sessionExpired = sessionExpired;
window.mothership.probeSession = probeSession;
