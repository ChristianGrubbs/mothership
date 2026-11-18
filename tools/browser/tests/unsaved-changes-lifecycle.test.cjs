'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../../..');
const source = fs.readFileSync(path.join(root, 'packages/Mothership/Admin/src/Resources/views/components/form/unsaved-changes.blade.php'), 'utf8');
const script = source.match(/<script type="module">([\s\S]*?)<\/script>/)[1].split('        if (! window.__unsavedNavGuardInstalled)')[0].replace(/@lang\('[^']+'\)/g, 'Label');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;

async function withForm(run) {
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    try {
        const page = await browser.newPage(), errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent('<div id="app"></div>');
        await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(root,'packages/Mothership/DAM/node_modules/vue/dist/vue.global.js')});
        await page.evaluate(() => { window.app = {component: (_name, definition) => { window.guardDefinition = definition; }}; });
        await page.addScriptTag({content: script});
        await page.evaluate(() => {
            const app = Vue.createApp({template: '<v-unsaved-changes v-if="show" ref="guard"><form><div data-control-group data-dirty-section><input name="title" value="Original"></div></form></v-unsaved-changes><p v-else>Next tab</p>', data: () => ({show:true})});
            app.component('v-unsaved-changes', {...window.guardDefinition, template:'<div ref="root"><slot></slot></div>'});
            app.config.globalProperties.$emitter = {on() {}, off() {}, emit() {}};
            window.host = app.mount('#app');
            window.guard = window.host.$refs.guard;
        });
        await run(page, errors);
    } finally { await browser.close(); }
}

browserTest('AJAX unmount cancels pending dirty recompute without detached-root errors', () => withForm(async (page, errors) => {
    await page.locator('input[name="title"]').fill('Changed');
    await page.evaluate(() => { window.host.show = false; });
    await page.getByText('Next tab', {exact:true}).waitFor();
    await page.waitForTimeout(800);
    assert.deepEqual(errors, []);
    assert.equal(await page.evaluate(() => document.body.classList.contains('unsaved-bar-open')), false);
}));

browserTest('an untouched form can unmount before its delayed baseline snapshot', () => withForm(async (page, errors) => {
    await page.evaluate(() => { window.host.show = false; });
    await page.getByText('Next tab', {exact:true}).waitFor();
    await page.waitForTimeout(800);
    assert.deepEqual(errors, []);
    const detached = await page.evaluate(() => ({controls:window.guard.controls(), serialized:window.guard.serializeForm()}));
    assert.deepEqual(detached, {controls:[],serialized:{}});
}));

browserTest('active forms retain dirty detection and before-unload protection until the edit is reverted', () => withForm(async (page, errors) => {
    await page.locator('input[name="title"]').fill('Changed');
    await page.waitForFunction(() => window.guard.isDirty);
    assert.equal(await page.evaluate(() => window.guard.beforeUnloadBound), true);
    assert.equal(await page.evaluate(() => document.body.classList.contains('unsaved-bar-open')), true);
    await page.locator('input[name="title"]').fill('Original');
    await page.waitForFunction(() => ! window.guard.isDirty);
    assert.equal(await page.evaluate(() => window.guard.beforeUnloadBound), false);
    assert.equal(await page.evaluate(() => document.body.classList.contains('unsaved-bar-open')), false);
    assert.deepEqual(errors, []);
}));
