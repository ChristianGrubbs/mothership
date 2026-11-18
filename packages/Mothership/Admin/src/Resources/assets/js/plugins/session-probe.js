/**
 * How the session notice reads its "am I signed in?" probe (GET admin.session.token).
 *
 * Kept free of imports so it runs, and is tested, without a browser or axios.
 *
 * Only the token endpoint's own JSON answer means "signed in". A 2xx alone does not: XHR follows redirects,
 * so a probe bounced to the sign-in page (or anywhere else) arrives as a 200 HTML page, and reading that as
 * signed in told someone whose permissions were revoked that their session was restored.
 */

// Laravel's CSRF token is 40 alphanumerics; anything shorter or shaped differently is not our payload.
const TOKEN_PATTERN = /^[A-Za-z0-9]{20,255}$/;

function samePath(a, b, base) {
    try {
        return new URL(a, base).pathname === new URL(b, base).pathname;
    } catch {
        return false;
    }
}

/**
 * Read a probe that came back 2xx.
 *
 * @param {{ data: unknown, responseURL?: string }} response
 * @param {{ login?: string, base: string }} urls
 * @returns {{ signedIn: boolean, token: string|null }}
 */
export function readSessionProbe(response, { login, base }) {
    if (login && response?.responseURL && samePath(response.responseURL, login, base)) {
        return { signedIn: false, token: null };
    }

    const data = response?.data;
    const token = data && typeof data === "object" && ! Array.isArray(data) ? data.token : null;

    if (typeof token !== "string" || ! TOKEN_PATTERN.test(token)) {
        return { signedIn: false, token: null };
    }

    return { signedIn: true, token };
}

/**
 * Read a probe that failed: refused for lack of a session means signed out; anything else (offline, a 500)
 * leaves the answer unknown, so the page neither claims a restore nor a sign-out.
 *
 * @param {number|undefined} status
 * @returns {boolean|null}
 */
export function readFailedSessionProbe(status) {
    return status === 401 || status === 419 ? false : null;
}

/**
 * What a 401/419 meant once the probe answered: a 419 while still signed in is a rotated token (retry works),
 * everything else is a lapsed session.
 *
 * @param {number} status
 * @param {boolean|null} signedIn
 * @returns {"stale"|"session"}
 */
export function refusalKind(status, signedIn) {
    return status === 419 && signedIn === true ? "stale" : "session";
}
