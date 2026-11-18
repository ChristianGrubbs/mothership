// Regression test for ajax navigation running a page's inline <script type="module"> (first hit by
// the since-removed QR Studio, 2026-10-09). The studio registered its Vue components from an inline module with a static
// `import`; run through Function() that is a syntax error, so after an in-app visit the studio
// stayed blank until a full reload. Extracts the real injectScripts()/runInlineModule() from
// navigation.js and runs them against a fake DOM.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
const source = fs.readFileSync(
    path.join(root, 'packages/Mothership/Admin/src/Resources/assets/js/plugins/navigation.js'),
    'utf8'
);

const pick = (name) => {
    const match = source.match(new RegExp(`(async )?function ${name}\\([^)]*\\) \\{[\\s\\S]*?\\n {4}\\}`));
    assert.ok(match, `${name} must exist in navigation.js`);
    return match[0];
};

function pageScript({ type = '', text = '', id = '' } = {}) {
    return { textContent: text, id, src: '', getAttribute: (name) => (name === 'type' ? type : null), cloneNode() { return { ...this }; } };
}

function harness({ moduleOutcome = 'evaluates' } = {}) {
    const appended = [];
    const ran = [];
    const window = {};
    const document = {
        createElement: () => ({ setAttribute() {}, type: '', textContent: '' }),
        querySelector: () => null,
        body: {
            appendChild(node) {
                appended.push(node);
                if (node.type !== 'module') return;
                // A browser evaluates the module asynchronously; the trailing callback is its last statement.
                setTimeout(() => {
                    if (moduleOutcome === 'evaluates') {
                        ran.push('module');
                        vm.runInContext(node.textContent.split('\n').pop(), context);
                    } else if (moduleOutcome === 'import-fails') {
                        node.onerror();
                    }
                }, 5);
            },
        },
    };
    const context = vm.createContext({
        window, document, console: { error() {} }, setTimeout, clearTimeout, Promise, JSON,
        Function: function (body) { return () => ran.push('classic:' + body); },
    });
    vm.runInContext(`
        let navToken = 0; let moduleSeq = 0; const MODULE_TIMEOUT_MS = 200;
        function isExecutableType(type) { return type === '' || type === 'text/javascript' || type === 'application/javascript' || type === 'module'; }
        ${pick('injectScripts')}
        ${pick('runInlineModule')}
        this.injectScripts = injectScripts;
    `, context);
    return { context, appended, ran, window };
}

test('an inline module runs as a real module after the classic scripts, and the visit waits for it', async () => {
    const { context, appended, ran, window } = harness();
    await context.injectScripts([
        pageScript({ type: 'module', text: "import { defineStudio } from '/files/studio';\napp.component('x', defineStudio());" }),
        pageScript({ text: 'window.__QR_I18N__ = {}' }),
    ]);

    const module = appended.find((node) => node.type === 'module');
    assert.ok(module, 'the module was not injected as <script type="module">');
    assert.match(module.textContent, /^import \{ defineStudio \}/, 'the module text must be kept intact');
    assert.deepEqual(ran, ['classic:window.__QR_I18N__ = {}', 'module'], 'module must run after classic scripts, never through Function()');
    assert.equal(Object.keys(window).filter((k) => k.startsWith('__ajaxNavModule_')).length, 0, 'the completion hook must be cleaned up');
});

test('a module whose imports fail settles the visit; one that never finishes forces a full page load', async () => {
    const failing = harness({ moduleOutcome: 'import-fails' });
    await failing.context.injectScripts([pageScript({ type: 'module', text: "import x from '/missing';" })]);

    // performVisit() turns a rejection into window.location.assign(url), so a module that would
    // otherwise finish late can never register components against an already-mounted app.
    const hanging = harness({ moduleOutcome: 'hangs' });
    const started = Date.now();
    await assert.rejects(
        hanging.context.injectScripts([pageScript({ type: 'module', text: "import x from '/slow';" })]),
        /did not finish/
    );
    assert.ok(Date.now() - started < 1000, 'the timeout must fire');
    assert.equal(Object.keys(hanging.window).filter((k) => k.startsWith('__ajaxNavModule_')).length, 0, 'the completion hook must be cleaned up');
});
