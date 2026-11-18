// Scope-aware guard for the StylePress tool page (home/stylepress.blade.php).
// Regression: PR139 (e97eb746) deleted `showError` while seven call sites stayed, so every Enhance
// validation failure and every server error died as a silent ReferenceError. The inline script must
// reference no free identifier beyond the browser platform: every helper it calls, passes as a handler
// or reads must resolve to a declaration in the script's own scopes. Resolution is done by Vue's
// scope-tracking identifier walker over the Babel AST (both bundled in the DAM package's
// @vue/compiler-sfc), so a deleted helper, a handler-only helper, or a helper masked by a same-named
// parameter elsewhere all surface as a free identifier. No hand-kept helper list.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
const bladePath = path.join(root, 'packages/Mothership/DAM/src/Resources/views/home/stylepress.blade.php');
const { babelParse, walkIdentifiers } = require(path.join(root, 'packages/Mothership/DAM/node_modules/@vue/compiler-sfc'));

// Browser platform globals the page may reference freely; everything else must be declared in the script.
const PLATFORM = new Set(['undefined', 'globalThis', 'window', 'document', 'location', 'history', 'navigator', 'localStorage',
    'sessionStorage', 'console', 'Event', 'CustomEvent', 'String', 'Number', 'Boolean', 'Error', 'Array', 'Object', 'Map', 'Set',
    'Date', 'JSON', 'Math', 'Promise', 'FormData', 'URL', 'URLSearchParams', 'IntersectionObserver', 'MutationObserver',
    'AbortController', 'Blob', 'fetch', 'confirm', 'alert', 'prompt', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval',
    'requestAnimationFrame', 'encodeURIComponent', 'decodeURIComponent', 'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'Symbol',
    'RegExp', 'structuredClone']);

function inlineScript(source = fs.readFileSync(bladePath, 'utf8')) {
    // Blade echoes sit inside JavaScript string literals on this page; a placeholder keeps the script parseable.
    return [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((m) => m[1]).join('\n')
        .replace(/\{!![\s\S]*?!!\}|\{\{[\s\S]*?\}\}/g, 'BLADE');
}

// Free identifiers: referenced identifiers that no enclosing function, block, parameter or catch clause binds.
function freeIdentifiers(source) {
    const ast = babelParse(inlineScript(source), { sourceType: 'script', errorRecovery: false });
    const free = new Set();
    walkIdentifiers(ast.program, (id) => free.add(id.name));
    return free;
}

function undeclared(source) {
    return [...freeIdentifiers(source)].filter((name) => !PLATFORM.has(name)).sort();
}

test('the StylePress inline script references no free identifier beyond the browser platform', () => {
    const free = freeIdentifiers();
    assert.ok(free.has('document') && free.has('fetch'), 'the walker sees the page (document and fetch are free)');
    assert.deepEqual(undeclared(), [], 'referenced but never declared');
});

test('the guard catches deleted, handler-only and parameter-masked helpers', () => {
    const source = fs.readFileSync(bladePath, 'utf8');
    const removed = source.replace(/function showError\(error\)\{[^}]*\}\n?/, '');
    assert.notEqual(removed, source, 'fixture removed showError');
    assert.deepEqual(undeclared(removed), ['showError']);
    // detail() has same-named parameters elsewhere; a flat declaration set would miss its deletion.
    const detailRenamed = source.replace(/function detail\(\)\{/, 'function detailRenamed(){');
    assert.notEqual(detailRenamed, source, 'fixture renamed detail');
    assert.deepEqual(undeclared(detailRenamed), ['detail']);
    // openAssetBrowser is only ever assigned as an onclick handler, never called directly.
    const handlerRenamed = source.replace(/function openAssetBrowser\(/, 'function openAssetBrowserRenamed(');
    assert.notEqual(handlerRenamed, source, 'fixture renamed openAssetBrowser');
    assert.deepEqual(undeclared(handlerRenamed), ['openAssetBrowser']);
});

// Regression: the same PR139 also dropped the file input's change handler, so an uploaded reference was never
// attached and Restyle kept reporting that it needed a source image. Find the handler structurally rather than
// by text so a reformat cannot fake it, then drive it against a fake input to prove each file is posted.
function uploadHandlerSource(source = fs.readFileSync(bladePath, 'utf8')) {
    const script = inlineScript(source);
    const ast = babelParse(script, { sourceType: 'script', errorRecovery: false });
    let found = null;
    // The page script is one IIFE, so the assignment sits several scopes deep: walk every node.
    (function visit(node) {
        if (found || !node || typeof node.type !== 'string') return;
        if (node.type === 'AssignmentExpression') {
            const { left } = node;
            const isUploadTarget = left.type === 'MemberExpression' && left.property.name === 'onchange'
                && left.object.type === 'CallExpression' && left.object.callee.name === '$'
                && left.object.arguments[0]?.value === 'sp-upload';
            if (isUploadTarget) { found = script.slice(node.right.start, node.right.end); return; }
        }
        for (const value of Object.values(node)) {
            if (Array.isArray(value)) value.forEach(visit);
            else if (value && typeof value.type === 'string') visit(value);
        }
    })(ast.program);
    return found;
}

test('the reference file input has a change handler that attaches every chosen file', async () => {
    const handler = uploadHandlerSource();
    assert.ok(handler, "$('sp-upload').onchange is assigned in the inline script");

    const posted = [];
    const context = {
        FormData: class { constructor() { this.entries = []; } append(name, value) { this.entries.push([name, value]); } },
        addReference: async (form) => { posted.push(form.entries); },
        showError: (error) => { throw error; },
    };
    const onchange = vm.runInNewContext(`(${handler})`, context);
    const input = { files: ['first.png', 'second.webp'], value: 'C:\\fakepath\\second.webp' };
    await onchange({ target: input });
    assert.deepEqual(posted, [[['image', 'first.png']], [['image', 'second.webp']]]);
    assert.equal(input.value, '', 'the input is cleared so the same file can be chosen again');

    const source = fs.readFileSync(bladePath, 'utf8');
    const removed = source.replace(/\$\('sp-upload'\)\.onchange=async e=>\{[\s\S]*?\}\};\n?/, '');
    assert.notEqual(removed, source, 'fixture removed the upload handler');
    assert.equal(uploadHandlerSource(removed), null, 'the guard notices the missing handler');
});

// Home closes a tool without asking while the tool has reported no work. The bridge reports typing, form
// controls and drops, but an image picked in the Library browser is all buttons and a fetch: without its own
// message, a request with attached images would be closed without a question.
test('attaching a reference image tells Home that the tool holds work', async () => {
    const lines = inlineScript().split('\n');
    const reportWork = lines.find((line) => line.startsWith('function reportWork('));
    const addReference = lines.find((line) => line.startsWith('async function addReference('));
    assert.ok(reportWork && addReference, 'both functions are declared, one per line');

    const sent = [];
    const parent = { postMessage: (message, origin) => sent.push([message, origin]) };
    const context = {
        window: { parent }, location: { origin: 'https://mothership.test' },
        references: [], addedAssets: new Set(),
        post: async () => ({ id: 'ref-1', url: '/reference.png', name: 'reference.png' }),
        invalidateEnhancement() {}, renderReferences() {},
    };
    vm.runInNewContext(`${reportWork}\n${addReference}\nthis.addReference = addReference;`, context);
    // The Library path: an asset id and no file input anywhere.
    await context.addReference({}, 41);
    // The message was built in the script's own realm, so compare it by value.
    assert.deepEqual(JSON.parse(JSON.stringify(sent)), [[{ protocol: 'mothership-widget/v1', type: 'dirty', payload: {} }, 'https://mothership.test']]);

    // Opened on its own page, not inside Home, there is nobody to tell.
    context.window.parent = context.window;
    await context.addReference({}, 42);
    assert.equal(sent.length, 1);
});

test('showError reveals #sp-result with the error message, or the string itself for non-Error values', () => {
    const definition = inlineScript().match(/function showError\(error\)\{[^}]*\}/);
    assert.ok(definition, 'showError is declared');
    const box = { hidden: true, textContent: '' };
    const context = { $: (id) => { assert.equal(id, 'sp-result'); return box; } };
    vm.runInNewContext(`${definition[0]}; this.showError = showError;`, context);
    context.showError(new Error('Describe what you want to create first.'));
    assert.equal(box.hidden, false);
    assert.equal(box.textContent, 'Describe what you want to create first.');
    box.hidden = true;
    context.showError('plain failure');
    assert.equal(box.hidden, false);
    assert.equal(box.textContent, 'plain failure');
});
