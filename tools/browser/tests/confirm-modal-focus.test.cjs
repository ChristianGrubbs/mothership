// The shared confirm dialog (packages/Mothership/Admin/src/Resources/views/components/modal/confirm.blade.php)
// backs every datagrid row action that asks first (Delete, Revoke, Reissue link). A keyboard user who
// opened it from a row action used to be left on the page behind the overlay: focus never entered the
// dialog, Escape did nothing, and closing it dropped focus on <body>. Now focus starts on the safe
// choice, Tab stays inside, Escape cancels, and either answer hands focus back to the control that
// opened it.
//
// This mounts the real, unmodified component the same way dropdown-menu-keyboard.test.cjs does.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const confirmFile = 'packages/Mothership/Admin/src/Resources/views/components/modal/confirm.blade.php';

// Blade drops `{{-- --}}` comments, resolves @lang() and turns `@{{` back into `{{`.
function blade(text) {
    return text
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@lang\('admin::app\.components\.modal\.(?:confirm|delete)\.agree-btn'\)/g, 'Agree')
        .replace(/@lang\('admin::app\.components\.modal\.(?:confirm|delete)\.disagree-btn'\)/g, 'Disagree')
        .replace(/@lang\('[^']*'\)/g, 'Translation')
        .replace(/@\{\{/g, '{{');
}

function extractConfirmComponent() {
    const php = fs.readFileSync(path.join(root, confirmFile), 'utf8');

    const template = php.match(/<script\s+type="text\/x-template"\s+id="v-modal-confirm-template"\s*>([\s\S]*?)<\/script>/);
    assert.ok(template, 'v-modal-confirm-template must exist in modal/confirm.blade.php');

    const registration = php.match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(registration, "app.component('v-modal-confirm', ...) must exist in modal/confirm.blade.php");

    return { template: blade(template[1]), registration: blade(registration[1]) };
}

async function mount(page) {
    const { template, registration } = extractConfirmComponent();

    await page.setContent(`
        <main id="app">
            <button type="button" id="before">Before</button>
            <button type="button" id="trigger" @click="ask">Revoke</button>
            <button type="button" id="other" @click="ask">Delete</button>
            <button type="button" id="chained" @click="askChained">Reissue</button>
            <v-modal-confirm></v-modal-confirm>
        </main>
    `);
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate((template) => {
        const script = document.createElement('script');
        script.type = 'text/x-template';
        script.id = 'v-modal-confirm-template';
        script.textContent = template;
        document.body.append(script);

        window.lockBodyScroll = () => {};
        window.unlockBodyScroll = () => {};
        window.answers = [];

        const listeners = {};
        const emitter = {
            on: (event, handler) => (listeners[event] ??= []).push(handler),
            emit: (event, payload) => (listeners[event] ?? []).forEach((handler) => handler(payload)),
        };

        window.app = Vue.createApp({
            methods: {
                ask() {
                    emitter.emit('open-confirm-modal', {
                        title: 'Revoke link',
                        message: 'The link stops working.',
                        agree: () => window.answers.push('agree'),
                        disagree: () => window.answers.push('disagree'),
                    });
                },
                askChained() {
                    emitter.emit('open-confirm-modal', {
                        title: 'Reissue link',
                        message: 'The current URL stops working.',
                        agree: () => {
                            window.answers.push('agree-first');
                            this.ask();
                        },
                        disagree: () => window.answers.push('disagree-first'),
                    });
                },
            },
        });
        window.app.config.globalProperties.$emitter = emitter;
    }, template);

    await page.addScriptTag({ content: registration });
    await page.evaluate(() => app.mount('#app'));
}

const active = (page) => page.evaluate(() => document.activeElement.id || document.activeElement.textContent.trim() || document.activeElement.tagName);
const answers = (page) => page.evaluate(() => window.answers);
const dialog = (page) => page.locator('[role="alertdialog"]');
const settle = (page) => page.waitForTimeout(100);

const vueTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

async function withPage(fn) {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await mount(page);
        await fn(page);
    } finally {
        await browser.close();
    }
}

vueTest('opening the confirm dialog from the keyboard moves focus inside and keeps it there', () => withPage(async (page) => {
    await page.focus('#trigger');
    await page.keyboard.press('Enter');
    await settle(page);

    assert.equal(await dialog(page).count(), 1);
    assert.equal(await dialog(page).getAttribute('aria-modal'), 'true');

    const labelledBy = await dialog(page).getAttribute('aria-labelledby');
    const describedBy = await dialog(page).getAttribute('aria-describedby');
    assert.equal((await page.locator(`[id="${labelledBy}"]`).textContent()).trim(), 'Revoke link');
    assert.equal((await page.locator(`[id="${describedBy}"]`).textContent()).trim(), 'The link stops working.');

    assert.equal(await active(page), 'Disagree', 'focus starts on the safe choice');

    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'Agree');
    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'Disagree', 'Tab from the last button wraps to the first');
    await page.keyboard.press('Shift+Tab');
    assert.equal(await active(page), 'Agree', 'Shift+Tab from the first button wraps to the last');
}));

vueTest('Escape cancels and hands focus back to the control that opened the dialog', () => withPage(async (page) => {
    await page.focus('#trigger');
    await page.keyboard.press('Space');
    await settle(page);

    await page.keyboard.press('Escape');
    await settle(page);

    assert.equal(await dialog(page).count(), 0);
    assert.deepEqual(await answers(page), ['disagree']);
    assert.equal(await active(page), 'trigger');
}));

vueTest('either answer hands focus back to the control that opened the dialog', () => withPage(async (page) => {
    await page.focus('#trigger');
    await page.keyboard.press('Enter');
    await settle(page);
    await page.keyboard.press('Enter');
    await settle(page);

    assert.deepEqual(await answers(page), ['disagree'], 'Enter on the initial focus cancels, never confirms');
    assert.equal(await active(page), 'trigger');

    await page.keyboard.press('Enter');
    await settle(page);
    await page.keyboard.press('Tab');
    await page.keyboard.press('Enter');
    await settle(page);

    assert.deepEqual(await answers(page), ['disagree', 'agree']);
    assert.equal(await active(page), 'trigger');
}));

vueTest('a trigger removed while the dialog is open is not refocused', () => withPage(async (page) => {
    await page.focus('#trigger');
    await page.keyboard.press('Enter');
    await settle(page);

    await page.evaluate(() => document.getElementById('trigger').remove());
    await page.keyboard.press('Escape');
    await settle(page);

    assert.deepEqual(await answers(page), ['disagree']);
    assert.equal(await page.evaluate(() => document.activeElement === document.body), true);
}));

vueTest('a replacement opened from another control hands focus back to that control', () => withPage(async (page) => {
    await page.focus('#trigger');
    await page.keyboard.press('Enter');
    await settle(page);

    // A second request arrives while the first dialog is open (another control fires the emitter).
    await page.evaluate(() => {
        document.getElementById('other').focus();
        document.getElementById('other').click();
    });
    await settle(page);
    await page.keyboard.press('Escape');
    await settle(page);

    assert.deepEqual(await answers(page), ['disagree']);
    assert.equal(await active(page), 'other');
}));

vueTest('a confirmation chained from an answer still returns focus to the original opener', () => withPage(async (page) => {
    await page.focus('#chained');
    await page.keyboard.press('Enter');
    await settle(page);
    await page.keyboard.press('Tab');
    await page.keyboard.press('Enter');
    await settle(page);

    assert.equal(await dialog(page).count(), 1, 'the chained confirmation is open');
    assert.equal(await active(page), 'Disagree');

    await page.keyboard.press('Escape');
    await settle(page);

    assert.deepEqual(await answers(page), ['agree-first', 'disagree']);
    assert.equal(await active(page), 'chained');
}));

vueTest('a message cut short by the screen joins the Tab cycle, and a whole one stays out of it', () => withPage(async (page) => {
    await page.focus('#trigger');
    await page.keyboard.press('Enter');
    await settle(page);

    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'Disagree', 'a message that fits is not a stop');

    const describedBy = await dialog(page).getAttribute('aria-describedby');

    await page.evaluate((id) => {
        const message = document.getElementById(id);

        message.style.height = '8px';
        message.style.overflowY = 'auto';
    }, describedBy);

    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'Agree');
    await page.keyboard.press('Tab');
    assert.equal(await active(page), describedBy, 'Tab from the last button reaches the clipped message');
    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'Disagree');
    await page.keyboard.press('Shift+Tab');
    assert.equal(await active(page), describedBy);
    await page.keyboard.press('Shift+Tab');
    assert.equal(await active(page), 'Agree', 'Shift+Tab from the message wraps to the last button');
}));

vueTest('a replacement requested while focus is inside the dialog keeps the original opener', () => withPage(async (page) => {
    await page.focus('#trigger');
    await page.keyboard.press('Enter');
    await settle(page);
    assert.equal(await active(page), 'Disagree');

    // A second request arrives while a dialog button still holds focus (click() does not move focus).
    await page.evaluate(() => document.getElementById('other').click());
    await settle(page);
    assert.equal(await active(page), 'Disagree');

    await page.keyboard.press('Escape');
    await settle(page);

    assert.deepEqual(await answers(page), ['disagree']);
    assert.equal(await active(page), 'trigger');
}));
