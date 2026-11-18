// System quick-view polling cadence: unchanged while the user is active, slower while a visible card sits idle,
// silent while the tab is hidden, and refreshed at once when the user comes back.
import { test, mock } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const source = readFileSync(fileURLToPath(new URL("../../src/Resources/assets/js/home-system-quickview.js", import.meta.url)), "utf8");

// Any property read or call on the card answers another stub, so skeleton()/paint() run without a DOM.
const stub = () => new Proxy(function () {}, {
    get: (_t, key) => (key === Symbol.toPrimitive ? () => "" : stub()),
    apply: () => stub(),
    set: () => true,
});

function harness() {
    const listeners = { window: {}, document: {} };
    const document = {
        hidden: false,
        addEventListener: (name, fn) => { listeners.document[name] = fn; },
        removeEventListener: (name) => { delete listeners.document[name]; },
    };
    const window = {
        addEventListener: (name, fn) => { listeners.window[name] = fn; },
        removeEventListener: (name) => { delete listeners.window[name]; },
    };
    vm.runInNewContext(source, { window, document, setInterval, clearInterval, Date, Number, String, Math, Object });

    let requests = 0;
    const view = window.MothershipSystemQuickView.create({
        root: document,
        request: async () => { requests += 1; return { metrics: { available: false }, presence: { available: false, users: [] } }; },
        intervalMs: 10000,
        now: () => Date.now(),
    });

    return { view, document, listeners, requests: () => requests };
}

// The mock clock fires an interval once per tick, so time is advanced in one-second steps.
const advance = (ms) => { for (let spent = 0; spent < ms; spent += 1000) mock.timers.tick(1000); };

test("polls at the base interval while the user is active", async () => {
    mock.timers.enable({ apis: ["setInterval", "Date"] });
    try {
        const { view, listeners, requests } = harness();
        view.mount(stub());
        assert.equal(requests(), 1);

        for (let i = 0; i < 5; i += 1) {
            advance(10000);
            listeners.window.pointerdown();
        }
        assert.equal(requests(), 6);
        view.unmount();
    } finally {
        mock.timers.reset();
    }
});

test("slows to the idle interval once the card sits untouched, then refreshes on the first input", async () => {
    mock.timers.enable({ apis: ["setInterval", "Date"] });
    try {
        const { view, listeners, requests } = harness();
        view.mount(stub());

        // 120 s idle at 10 s cadence is 12 polls (+ the mount one); afterwards a poll costs 30 s.
        advance(130000);
        const atIdle = requests();
        advance(90000);
        assert.equal(requests() - atIdle, 3, "three idle polls in 90 s");

        const beforeReturn = requests();
        listeners.window.keydown();
        assert.equal(requests(), beforeReturn + 1, "the first input after idling refreshes at once");

        advance(10000);
        assert.equal(requests(), beforeReturn + 2, "and the base cadence is back");
        view.unmount();
    } finally {
        mock.timers.reset();
    }
});

test("a hidden tab never requests, and showing it again refreshes", async () => {
    mock.timers.enable({ apis: ["setInterval", "Date"] });
    try {
        const { view, document, listeners, requests } = harness();
        view.mount(stub());
        const mounted = requests();

        document.hidden = true;
        advance(600000);
        assert.equal(requests(), mounted);

        document.hidden = false;
        listeners.document.visibilitychange();
        assert.equal(requests(), mounted + 1);
        view.unmount();
        assert.equal(Object.keys(listeners.window).length, 0, "unmount removes the activity listeners");
    } finally {
        mock.timers.reset();
    }
});
