'use strict';

// Keyboard contract of the asset-details preview (preview-modal.blade.php): with the Info panel open, one
// Escape closes the preview, and while the Share dialog is up Escape belongs to Share alone.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal.blade.php'), 'utf8');

function definition() {
    let script = source.slice(source.lastIndexOf('<script type="module">') + '<script type="module">'.length).replace(/<\/script>[\s\S]*$/, '');
    // Blade calls (@js, @json) and {{ }} echoes are server output the handler never reads: stand in an empty string.
    for (;;) {
        const match = /@(js|json)\(/.exec(script);
        if (!match) break;
        let depth = 1, quote = null, escape = false, end = match.index + match[0].length;
        for (; end < script.length && depth; end++) {
            const ch = script[end];
            if (escape) { escape = false; continue; }
            if (ch === '\\') { escape = true; continue; }
            if (quote) { if (ch === quote) quote = null; continue; }
            if (ch === "'" || ch === '"') { quote = ch; continue; }
            if (ch === '(') depth++;
            if (ch === ')') depth--;
        }
        script = script.slice(0, match.index) + "''" + script.slice(end);
    }
    script = script.replace(/\{\{[^}]*\}\}/g, 'x');

    const mixin = (methods = {}, data = {}) => ({data, computed: {}, methods});
    let component;
    const context = {
        app: {component: (name, value) => { component = value; }},
        window: {
            axios: {defaults: {headers: {common: {}}}},
            _damInfoPanel: mixin({}, {isInfoPanelOpen: true}),
            _damStackSwitch: mixin(),
            _damPreviewFocus: mixin({trapPreviewTab() {}, rememberPreviewOpener() {}, focusPreview() {}, restorePreviewFocus() {}, startPreviewFocusGuard() {}, stopPreviewFocusGuard() {}}),
            _damImageViewer: mixin(), _damVideoPlayer: mixin(), _damAudioPlayer: mixin(), _damPdfViewer: mixin(), _damPanoViewer: mixin(),
        },
        document: {body: {style: {}}},
    };
    vm.createContext(context);
    vm.runInContext(script, context);

    return {component, context};
}

function mount(overrides = {}) {
    const {component, context} = definition();
    let closed = 0;
    const state = {
        ...component.methods,
        isOpen: true, isInfoOpen: false, isEditOpen: false, isInfoPanelOpen: true,
        $refs: {},
        closePreview() { closed++; this.isOpen = false; },
        toggleInfoPanel() { this.isInfoPanelOpen = !this.isInfoPanelOpen; },
        ...overrides,
    };

    return {state, context, methods: component.methods, closed: () => closed};
}

const escape = () => ({key: 'Escape', target: {tagName: 'DIV'}, preventDefault() {}});

test('one Escape closes the details preview even with the Info panel open, and leaves the panel setting alone', () => {
    const {state, closed} = mount();
    state.handleEscape(escape());
    assert.equal(closed(), 1);
    assert.equal(state.isInfoPanelOpen, true);
});

test('while the Share dialog is open the preview ignores Escape (Share closes itself)', () => {
    const {state, context, closed} = mount();
    context.window.damShareDialogOpen = () => true;
    state.handleEscape(escape());
    assert.equal(closed(), 0);
    assert.equal(state.isOpen, true);

    context.window.damShareDialogOpen = () => false;
    state.handleEscape(escape());
    assert.equal(closed(), 1);
});

// Browser Back (Command+[ on a Mac) closes the details preview instead of leaving the page: opening owns one
// same-url history entry, and the local history handler keeps the AJAX router from visiting the page again.
function history() {
    const entries = [{state: null, href: 'http://app.test/admin/dam/assets/edit/2'}];
    let index = 0, backs = 0;
    const api = {
        get state() { return entries[index].state; },
        pushState(state, _title, href) { entries.splice(index + 1); entries.push({state, href}); index++; },
        back() { backs++; },
    };
    return {
        api, backs: () => backs,
        // The browser's reaction to Back/Forward: move the entry, then dispatch popstate to the handler.
        go(delta) { index += delta; },
        get href() { return entries[index].href; },
    };
}

function mountHistory() {
    const {state, context, methods} = mount({
        isOpen: false,
        resetTextPreview() {}, videoStopOnClose() {}, audioStopOnClose() {}, pdfDestroy() {}, panoDestroy() {},
        restorePreviewFocus() {}, restoreStackCover() {}, $nextTick() {},
    });
    state.closePreview = methods.closePreview;
    const h = history();
    // The methods read history/location as globals of the template's context.
    context.history = h.api;
    Object.defineProperty(context, 'location', {get: () => { const url = new URL(h.href); return {href: h.href, origin: url.origin, pathname: url.pathname}; }});
    let stopped = 0;
    const popstate = (delta) => {
        h.go(delta);
        return state.onPreviewPopState({stopImmediatePropagation() { stopped++; }});
    };
    const open = () => { state.isOpen = true; state.syncPreviewHistory(true); };

    return {state, h, popstate, open, stopped: () => stopped};
}

test('opening the details preview pushes one entry and closing takes it back', () => {
    const {state, h, popstate, open, stopped} = mountHistory();
    open();
    assert.equal(h.api.state.damPreview, true);
    state.closePreview();
    assert.equal(h.backs(), 1);
    assert.equal(popstate(-1), true, 'the return from the preview entry is the lightbox\'s, not a page visit');
    assert.equal(stopped(), 1);
});

test('browser Back with the details preview open closes it and stays on the page', () => {
    const {state, h, popstate, open} = mountHistory();
    open();
    assert.equal(popstate(-1), true);
    assert.equal(state.isOpen, false);
    assert.equal(h.backs(), 0, 'the entry is already gone; closing must not go back again');
});

test('Forward onto the closed preview entry stays local and Back from it is still the lightbox\'s', () => {
    const {state, popstate, open} = mountHistory();
    open();
    popstate(-1);
    assert.equal(popstate(1), true);
    assert.equal(state.isOpen, false);
    assert.equal(popstate(-1), true);
});

test('Back with no preview open is left to the router', () => {
    const {popstate} = mountHistory();
    assert.equal(popstate(0), false);
});
