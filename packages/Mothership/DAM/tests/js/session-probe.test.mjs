// The session notice's "am I signed in?" probe: only the token endpoint's own JSON answer means signed in.
import { test } from "node:test";
import assert from "node:assert/strict";

import {
    readFailedSessionProbe,
    readSessionProbe,
    refusalKind,
} from "../../../Admin/src/Resources/assets/js/plugins/session-probe.js";

const urls = { login: "/admin/login", base: "https://mothership.test/admin/dam" };
const TOKEN = "a".repeat(40);

test("revoked permissions: the server signs the session out and answers the probe 401, so the page stays signed out", () => {
    assert.equal(readFailedSessionProbe(401), false);
    assert.equal(refusalKind(401, false), "session");
});

test("a probe redirected to the sign-in page arrives as 200 HTML and is not a restored session", () => {
    const probe = readSessionProbe(
        { data: "<!DOCTYPE html><html><title>Sign in</title></html>", responseURL: "https://mothership.test/admin/login" },
        urls,
    );

    assert.deepEqual(probe, { signedIn: false, token: null });
    assert.equal(refusalKind(419, probe.signedIn), "session");
});

test("a 2xx without the token payload is not signed in, wherever it landed", () => {
    for (const data of ["<html></html>", {}, { token: "" }, { token: 42 }, { token: "<script>" }, null, [TOKEN]]) {
        assert.equal(readSessionProbe({ data, responseURL: "https://mothership.test/admin/session/token" }, urls).signedIn, false, JSON.stringify(data));
    }
});

test("a sign-in landing page is refused even if it happens to carry a token field", () => {
    assert.equal(readSessionProbe({ data: { token: TOKEN }, responseURL: "https://mothership.test/admin/login?x=1" }, urls).signedIn, false);
});

test("genuine expiry: 401 and 419 probes mean signed out; offline or a 500 stays unknown", () => {
    assert.equal(readFailedSessionProbe(401), false);
    assert.equal(readFailedSessionProbe(419), false);
    assert.equal(readFailedSessionProbe(undefined), null);
    assert.equal(readFailedSessionProbe(500), null);
});

test("authenticated 419: the probe returns a fresh token, so the refusal is a rotated token and a retry works", () => {
    const probe = readSessionProbe({ data: { token: TOKEN }, responseURL: "https://mothership.test/admin/session/token" }, urls);

    assert.deepEqual(probe, { signedIn: true, token: TOKEN });
    assert.equal(refusalKind(419, probe.signedIn), "stale");
    assert.equal(refusalKind(401, probe.signedIn), "session");
});
