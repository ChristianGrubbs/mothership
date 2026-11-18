import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// "Use Sandbox defaults" must follow the opt-in rule for paid tools: it ticks the free Home widgets and the free
// capabilities, and never StylePress, SphereSeam, Image Vectorization, the SphereSeam capability or public links.

const blade = readFileSync(new URL('../../src/Resources/views/admin/roles/dam-permissions-tab.blade.php', import.meta.url), 'utf8');

function presetSource() {
    const start = blade.indexOf('window.applySandboxRoleDefaults = function () {');
    assert.ok(start > 0, 'applySandboxRoleDefaults not found');
    const end = blade.indexOf('\n            };', start);
    return blade.slice(start, end + '\n            };'.length);
}

function box(value, { paid = false, disabled = false, checked = false } = {}) {
    return { value, checked, disabled, dataset: { widgetPaid: paid ? '1' : '0' }, dispatchEvent() {} };
}

function runPreset() {
    const capabilities = ['library', 'metadata', 'image_editor', 'public_shares', 'web_pages', 'delete_own', 'sphereseam'].map(value => box(value));
    const widgets = [
        box('recent'), box('looper'), box('rss'), box('trending'),
        box('stylepress', { paid: true, checked: true }), box('sphereseam', { paid: true, checked: true }), box('vectorization', { paid: true, checked: true }),
    ];
    const note = { textContent: '' };
    const window = { updateDamWidgetAccess() {} };
    const document = {
        querySelectorAll: selector => selector.includes('capability') ? capabilities : widgets,
        getElementById: id => (id === 'dam-preset-note' ? note : null),
    };
    vm.runInNewContext(`const DAM_CAPABILITY_LABELS = {}; const Event = function () {};\n${presetSource()}\nwindow.applySandboxRoleDefaults();`, { window, document });

    return { capabilities, widgets, note };
}

test('the Sandbox preset ticks free widgets and leaves every paid tool unticked', () => {
    const { widgets } = runPreset();
    const ticked = widgets.filter(input => input.checked).map(input => input.value);

    assert.deepEqual(ticked, ['recent', 'looper', 'rss', 'trending']);
});

test('the Sandbox preset leaves the SphereSeam capability and public links off', () => {
    const { capabilities } = runPreset();
    const ticked = capabilities.filter(input => input.checked).map(input => input.value);

    assert.deepEqual(ticked, ['library', 'metadata', 'image_editor', 'web_pages', 'delete_own']);
});
