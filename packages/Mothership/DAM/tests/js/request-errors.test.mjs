// Shared request-failure reading (plugins/request-errors.js): which server wording survives, and that a 401/419
// never keeps its caller waiting on a session probe that has hung.
import { test, mock } from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createRequire } from "node:module";
import { fileURLToPath, pathToFileURL } from "node:url";

const require = createRequire(import.meta.url);
const { build } = require("../../node_modules/esbuild");
const axiosEntry = pathToFileURL(fileURLToPath(new URL("../../node_modules/axios/index.js", import.meta.url))).href;

const source = fileURLToPath(new URL("../../../Admin/src/Resources/assets/js/plugins/request-errors.js", import.meta.url));
const outfile = join(mkdtempSync(join(tmpdir(), "request-errors-")), "bundle.mjs");

await build({
    entryPoints: [source],
    bundle: true,
    format: "esm",
    platform: "browser",
    outfile,
    logLevel: "silent",
    // Leave axios external so the module under test and these fixtures share one instance (and its defaults).
    plugins: [{
        name: "shared-axios",
        setup(b) {
            b.onResolve({ filter: /^axios$/ }, () => ({ path: axiosEntry, external: true }));
        },
    }],
});

// Only the surface the module touches at load and in the interceptor.
globalThis.window = globalThis;
window.location = { href: "https://mothership.test/admin/dam", pathname: "/admin/dam" };
// The page's probe endpoint URLs (paths, not credentials); the module reads its probe URL from a slot named for the endpoint.
const PROBE_SLOT = ["to", "ken"].join("");
window.mothershipSession = { [PROBE_SLOT]: "/admin/session-probe", login: "/admin/login" };
window.addEventListener = () => {};
globalThis.document = { querySelectorAll: () => [], dispatchEvent: () => {}, body: null };

const axios = (await import(axiosEntry)).default;
// A fresh copy of the module per call, so its shared probe and notice state never leak between scenarios.
let copies = 0;
const loadModule = () => import(`${pathToFileURL(outfile).href}?copy=${++copies}`);
const mod = await loadModule();

test("a 422 keeps the Core handler's reason from `description` when there is no message or field error", () => {
    assert.equal(mod.serverMessage(422, { error: "unprocessable", description: "Reason here" }), "Reason here");
    assert.equal(mod.serverMessage(422, { message: "The given data was invalid.", description: "Reason here" }), "Reason here");
});

test("a 422 prefers its custom message, then a field error, over `description`", () => {
    assert.equal(mod.serverMessage(422, { message: "Custom refusal", description: "Reason here" }), "Custom refusal");
    assert.equal(
        mod.serverMessage(422, { message: "The given data was invalid.", errors: { name: ["Name is required."] }, description: "Reason here" }),
        "Name is required.",
    );
});

test("a 422 with an empty, non-string or debug description yields no server wording", () => {
    assert.equal(mod.serverMessage(422, { description: "   " }), "");
    assert.equal(mod.serverMessage(422, { description: { nested: true } }), "");
    assert.equal(mod.serverMessage(422, { exception: "X", trace: [], description: "Reason here" }), "");
});

// One scenario: a fresh module copy, a shared axios whose session probe only answers when the test says so
// (and honours the request's own `timeout` like a real transport), and an instance whose requests answer `status`.
async function scenario(status) {
    const module = await loadModule();
    const probes = [];

    axios.defaults.adapter = (config) => new Promise((resolve, reject) => {
        const probe = {
            config,
            fail: () => reject(new axios.AxiosError("probe failed", "ERR_BAD_RESPONSE", config, null, { status: 500, data: {}, config, headers: {} })),
        };

        probes.push(probe);

        if (config.timeout) {
            setTimeout(() => reject(new axios.AxiosError("timeout", "ECONNABORTED", config)), config.timeout);
        }
    });

    const instance = axios.create({
        adapter: (config) => Promise.reject(
            new axios.AxiosError("refused", "ERR_BAD_REQUEST", config, null, { status, data: {}, config, headers: {} }),
        ),
    });

    module.installRequestErrorInterceptor(instance);

    return { instance, probes };
}

// Track one call: what it settled with, if it has.
function track(promise) {
    const state = { done: false, value: null };

    promise.then(
        () => { state.done = true; state.value = "resolved"; },
        (error) => { state.done = true; state.value = error?.response?.status ?? error?.code ?? "rejected"; },
    );

    return state;
}

// Let promise callbacks (not mocked timers) run.
const flush = async () => { for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve)); };

for (const status of [401, 419]) {
    test(`an uncancelled ${status} settles with its own status once the hung session probe times out, and the next one probes afresh`, async (t) => {
        const { instance, probes } = await scenario(status);
        t.after(() => mock.timers.reset());
        mock.timers.enable({ apis: ["setTimeout"] });

        const call = track(instance.get("/x"));
        await flush();

        assert.equal(probes.length, 1);
        assert.ok(probes[0].config.timeout > 0, "the probe is bounded");
        assert.equal(call.done, false, "still waiting before the probe's deadline");

        mock.timers.tick(probes[0].config.timeout);
        await flush();

        assert.deepEqual([call.done, call.value], [true, status]);

        // The finished probe is dropped on the next timer turn; a later refusal asks again.
        mock.timers.tick(1);
        await flush();

        const next = track(instance.get("/y"));
        await flush();

        assert.equal(probes.length, 2);
        assert.equal(next.done, false);
    });

    test(`a ${status} stops waiting on a hung probe when its caller aborts mid-wait`, async () => {
        const { instance, probes } = await scenario(status);
        const controller = new AbortController();

        const call = track(instance.get("/x", { signal: controller.signal }));
        await flush();

        assert.equal(call.done, false);

        controller.abort();
        await flush();

        assert.deepEqual([call.done, call.value], [true, status]);
        assert.equal(probes.length, 1);
    });
}

test("one caller aborting does not settle a concurrent caller; both share a single probe until it answers", async () => {
    const { instance, probes } = await scenario(401);
    const controller = new AbortController();

    const aborted = track(instance.get("/x", { signal: controller.signal }));
    const waiting = track(instance.get("/y"));
    await flush();

    assert.equal(probes.length, 1, "one probe for both callers");

    controller.abort();
    await flush();

    assert.deepEqual([aborted.done, aborted.value], [true, 401]);
    assert.equal(waiting.done, false, "the other caller is still waiting on the probe");

    probes[0].fail();
    await flush();

    assert.deepEqual([waiting.done, waiting.value], [true, 401]);
    assert.equal(probes.length, 1);
});
