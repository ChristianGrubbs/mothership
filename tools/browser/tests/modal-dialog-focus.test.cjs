// The shared modal (packages/Mothership/Admin/src/Resources/views/components/modal/index.blade.php, used as
// <x-admin::modal> across the admin, for example the Shared Links "Edit" dialog) left a keyboard user on
// the page behind its overlay: focus never entered it, Tab walked out to the page, Escape did nothing,
// closing dropped focus on <body>, and the header close control was a <span> Tab could not reach. Now it
// is a labelled role="dialog": focus starts on its first control, Tab stays inside, Escape closes it, and
// closing hands focus back to the control that opened it. A modal opened over another, or an alert dialog
// (the confirm) or another overlay over a modal, owns the keyboard until it closes.
//
// This renders the component's real Blade markup (header, content and footer slots, close control) and
// mounts the real, unmodified Vue component with the committed admin stylesheet, the same way
// confirm-modal-focus.test.cjs and dropdown-menu-keyboard.test.cjs do.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const modalFile = 'packages/Mothership/Admin/src/Resources/views/components/modal/index.blade.php';
const confirmFile = 'packages/Mothership/Admin/src/Resources/views/components/modal/confirm.blade.php';
const imageViewerFile = 'packages/Mothership/Admin/src/Resources/views/components/media/image-viewer.blade.php';
const adminManifestFile = 'public/themes/admin/default/build/manifest.json';

// Blade drops `{{-- --}}` comments, resolves @lang() and turns `@{{` back into `{{`.
function blade(text) {
    return text
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@lang\('admin::app\.components\.modal\.close-btn'\)/g, 'Close')
        .replace(/@lang\('[^']*'\)/g, 'Translation')
        .replace(/@\{\{/g, '{{');
}

const php = source(modalFile);

function extractComponent(file, id) {
    const text = source(file);

    const template = text.match(new RegExp(`<script\\s+type="text\\/x-template"\\s+id="${id}"\\s*>([\\s\\S]*?)<\\/script>`));
    assert.ok(template, `${id} must exist in ${file}`);

    const registration = text.match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(registration, `the component registration must exist in ${file}`);

    return { id, template: blade(template[1]), registration: blade(registration[1]) };
}

// What <x-admin::modal> renders for one call site: the Blade slot wrappers (header with its close
// control, content, footer) around the markup a page passes in.
function renderModal({ attributes, preventSubmit = false, toggle = '', header, content, footer }) {
    const slot = (name, inner) => {
        const block = php.match(new RegExp(`@isset\\(\\$${name}\\)([\\s\\S]*?)@endisset`));
        assert.ok(block, `the ${name} slot must exist in modal/index.blade.php`);

        return block[1]
            .replace(new RegExp(`\\{\\{ \\$${name}->attributes->merge\\(\\['class' => '([^']*)'\\]\\) \\}\\}`), 'class="$1"')
            .replace(new RegExp(`\\{\\{ \\$${name} \\}\\}`), inner);
    };

    return blade(`
        <v-modal ${preventSubmit ? ':prevent-submit="true"' : ''} ${attributes}>
            ${toggle ? slot('toggle', toggle) : ''}
            ${header ? slot('header', header) : ''}
            ${slot('content', content)}
            ${footer ? slot('footer', footer) : ''}
        </v-modal>
    `);
}

function resolveBuiltAdminStylesheetPath() {
    const manifest = JSON.parse(source(adminManifestFile));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));
    assert.ok(entry, 'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root, 'public/themes/admin/default/build', entry.file);
}

// Content taller than any test viewport, with a field at each end, so only a scrolling region can show it all.
function tallDialog(prefix) {
    return {
        header: `<p id="${prefix}-title" class="text-lg font-bold">Report</p>`,
        content: `<input type="text" id="${prefix}-first">${'<p style="height: 60px">Filler</p>'.repeat(30)}<input type="text" id="${prefix}-last">`,
        footer: `<button type="button" id="${prefix}-save" class="primary-button">Send</button>`,
    };
}

// The Shared Links shape (opened by a row action through a ref, with a label its header title overrides),
// a toggle-slot modal inside a form with prevent-submit, a modal stacked over it, the real confirm, a
// modal whose @open listener opens another, a direct v-modal with its own header, a type="full" modal,
// and an overlay shaped like the DAM asset preview (its own fixed z-[10010] layer, rendered inside the
// asset picker's modal, with a window Escape handler that does not consume the key).
const fixture = `
    <button type="button" id="before">Before</button>
    <button type="button" id="row-edit" @click="$refs.shareModal.toggle()">Edit</button>

    ${renderModal({
        attributes: 'ref="shareModal" label="Share settings" @toggle="events.push(\'share-toggle:\' + $event.isActive)" @close="events.push(\'share-close\')"',
        header: '<p class="text-lg font-bold">Edit share</p>',
        content: '<input type="text" id="share-name" value="Brochure">',
        footer: '<button type="button" id="share-save" class="primary-button">Save</button>',
    })}

    <form id="outer-form" @submit.prevent="events.push('submitted')">
        ${renderModal({
            attributes: 'ref="addModal" @close="events.push(\'add-close\')"',
            preventSubmit: true,
            toggle: '<button type="button" id="add-open">Add attributes</button>',
            header: '<p class="text-lg font-bold">Add attributes</p>',
            content: `
                <input type="text" id="add-search">
                <button type="button" id="add-more" @click="$refs.stackedModal.open()">More options</button>
                <button type="button" id="add-alert" @click="askConfirm">Remove</button>
                <button type="button" id="add-preview" @click="previewOpen = true">Preview</button>
                <div v-if="previewOpen" id="preview" style="position: fixed; inset: 0; z-index: 10010; background: #fff">
                    <button type="button" id="preview-next">Next</button>
                    <button type="button" id="preview-close" @click="previewOpen = false">Close preview</button>
                </div>
            `,
            footer: '<button type="button" id="add-save" @click="events.push(\'add-save\')">Add</button>',
        })}
    </form>

    <button type="button" id="chain-open" @click="$refs.chainModal.open()">Chain</button>

    ${renderModal({
        attributes: 'ref="chainModal" @open="$refs.stackedModal.open()"',
        header: '<p class="text-lg font-bold">Chained</p>',
        content: '<input type="text" id="chain-field">',
    })}

    ${renderModal({
        attributes: 'ref="stackedModal"',
        header: '<p class="text-lg font-bold">More options</p>',
        content: '<input type="text" id="stacked-field">',
    })}

    <v-modal ref="customModal">
        <template v-slot:header="{ toggle }">
            <div class="flex justify-between"><p id="custom-title">Custom header</p><button type="button" @click="toggle">x</button></div>
        </template>
    </v-modal>

    ${renderModal({
        attributes: 'ref="fullModal" type="full"',
        header: '<p class="text-lg font-bold">Assign assets</p>',
        content: '<p>Grid</p>',
    })}

    <button type="button" id="viewer-open" @click="$refs.viewerModal.open()">View full size</button>

    ${renderModal({
        attributes: 'ref="viewerModal" :no-class="true" label="Preview image"',
        content: '<v-image-viewer src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" file-name="photo.jpg"></v-image-viewer>',
    })}

    <button type="button" id="fit-open" @click="$refs.fitModal.open()">Fit</button>
    <button type="button" id="plain-open" @click="$refs.plainModal.open()">Plain</button>

    ${renderModal({ attributes: 'ref="fitModal" :viewport-fit="true"', ...tallDialog('fit') })}
    ${renderModal({ attributes: 'ref="plainModal"', ...tallDialog('plain') })}

    <v-modal-confirm></v-modal-confirm>
    <button type="button" id="after">After</button>
`;

async function mount(page) {
    const components = [
        extractComponent(modalFile, 'v-modal-template'),
        extractComponent(confirmFile, 'v-modal-confirm-template'),
        extractComponent(imageViewerFile, 'v-image-viewer-template'),
    ];

    await page.setContent(`<main id="app">${fixture}</main>`);
    await page.addStyleTag({ path: resolveBuiltAdminStylesheetPath() });
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate((components) => {
        for (const { id, template } of components) {
            const script = document.createElement('script');
            script.type = 'text/x-template';
            script.id = id;
            script.textContent = template;
            document.body.append(script);
        }

        window.lockBodyScroll = () => {};
        window.unlockBodyScroll = () => {};

        const listeners = {};
        const emitter = {
            on: (event, handler) => (listeners[event] ??= []).push(handler),
            emit: (event, payload) => (listeners[event] ?? []).forEach((handler) => handler(payload)),
        };

        window.app = Vue.createApp({
            data: () => ({ events: [], previewOpen: false }),
            mounted() {
                // The DAM preview's own Escape handler, registered on window like grid-preview-modal's.
                window.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && this.previewOpen) {
                        this.previewOpen = false;
                        this.events.push('preview-escape');
                    }
                });
            },
            methods: {
                askConfirm() {
                    emitter.emit('open-confirm-modal', {
                        title: 'Remove attribute',
                        message: 'It leaves the family.',
                        options: { btnDisagree: 'Disagree', btnAgree: 'Agree', btnAgreeClass: 'primary-button', btnDisagreeClass: 'transparent-button' },
                        agree: () => this.events.push('confirm-agree'),
                        disagree: () => this.events.push('confirm-disagree'),
                    });
                },
            },
        });
        window.app.config.globalProperties.$emitter = emitter;
    }, components.map(({ id, template }) => ({ id, template })));

    for (const { registration } of components) {
        await page.addScriptTag({ content: registration });
    }

    await page.evaluate(() => { window.vm = app.mount('#app'); });
}

const active = (page) => page.evaluate(() => document.activeElement.id || document.activeElement.getAttribute('aria-label') || document.activeElement.tagName);
const events = (page) => page.evaluate(() => [...window.vm.events]);
const dialogs = (page) => page.locator('[role="dialog"]');
const settle = (page) => page.waitForTimeout(100);

// A closing modal stays in the DOM for its leave transition, so wait for the count instead of sampling it.
const expectDialogs = (page, count) => page.waitForFunction((count) => document.querySelectorAll('[role="dialog"]').length === count, count, { timeout: 2000 });

const vueTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

async function withPage(fn, viewport = { width: 1440, height: 900 }, prepare = null) {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage({ viewport });
        await prepare?.(page);
        await mount(page);
        await fn(page);
    } finally {
        await browser.close();
    }
}

vueTest('a modal opened from the keyboard is a labelled dialog that takes focus and keeps it', () => withPage(async (page) => {
    await page.focus('#row-edit');
    await page.keyboard.press('Enter');
    await settle(page);

    const dialog = dialogs(page);
    assert.equal(await dialog.count(), 1);
    assert.equal(await dialog.getAttribute('aria-modal'), 'true');

    const labelledBy = await dialog.getAttribute('aria-labelledby');
    assert.equal((await page.locator(`[id="${labelledBy}"]`).textContent()).trim(), 'Edit share');

    const close = dialog.locator('button.icon-cancel');
    assert.equal(await close.getAttribute('type'), 'button');
    assert.equal(await close.getAttribute('aria-label'), 'Close');
    assert.equal(await active(page), 'Close', 'focus starts on the first control: the header close button');

    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'share-name');
    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'share-save');
    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'Close', 'Tab from the last control wraps to the first');
    await page.keyboard.press('Shift+Tab');
    assert.equal(await active(page), 'share-save', 'Shift+Tab from the first control wraps to the last');

    // A click on the panel's background drops focus out of the controls; Tab still comes back inside.
    await page.evaluate(() => document.activeElement.blur());
    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'Close');
}));

vueTest('Escape closes the modal and hands focus back to the control that opened it', () => withPage(async (page) => {
    await page.focus('#row-edit');
    await page.keyboard.press('Enter');
    await settle(page);
    await page.keyboard.press('Tab');

    await page.keyboard.press('Escape');
    await settle(page);

    await expectDialogs(page, 0);
    assert.deepEqual(await events(page), ['share-toggle:true', 'share-toggle:false', 'share-close'], 'Escape tells @toggle and @close listeners');
    assert.equal(await active(page), 'row-edit');

    await page.keyboard.press('Escape');
    assert.equal((await events(page)).length, 3, 'Escape with nothing open does nothing');
}));

vueTest('the close button closes from the keyboard and returns focus; a removed opener is not refocused', () => withPage(async (page) => {
    await page.focus('#row-edit');
    await page.keyboard.press('Enter');
    await settle(page);
    await expectDialogs(page, 1);
    assert.equal(await active(page), 'Close');
    await page.keyboard.press('Enter');
    await settle(page);

    await expectDialogs(page, 0);
    assert.equal(await active(page), 'row-edit');

    await page.keyboard.press('Space');
    await settle(page);
    await page.evaluate(() => document.getElementById('row-edit').remove());
    await page.keyboard.press('Escape');
    await settle(page);
    await expectDialogs(page, 0);

    assert.equal(await page.evaluate(() => document.activeElement === document.body), true);
}));

vueTest('prevent-submit still blocks Enter in a field but lets buttons answer Enter', () => withPage(async (page) => {
    await page.focus('#add-open');
    await page.keyboard.press('Enter');
    await settle(page);

    assert.equal(await active(page), 'Close');

    await page.focus('#add-search');
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await events(page), [], 'Enter in the field does not submit the form around the modal');

    await page.focus('#add-save');
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await events(page), ['add-save']);

    await page.focus('[role="dialog"] button.icon-cancel');
    await page.keyboard.press('Enter');
    await settle(page);
    await expectDialogs(page, 0);
    assert.equal(await active(page), 'add-open');
}));

vueTest('a modal opened over another owns the keyboard, and each close returns focus one level', () => withPage(async (page) => {
    await page.focus('#add-open');
    await page.keyboard.press('Enter');
    await settle(page);
    await page.focus('#add-more');
    await page.keyboard.press('Enter');
    await settle(page);

    await expectDialogs(page, 2);
    assert.equal(await active(page), 'Close');
    assert.equal(await page.evaluate(() => window.vm.$refs.stackedModal.$refs.modalContent.contains(document.activeElement)), true);

    await page.keyboard.press('Tab');
    assert.equal(await active(page), 'stacked-field');
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => window.vm.$refs.stackedModal.$refs.modalContent.contains(document.activeElement)), true, 'Tab stays in the top modal');

    await page.keyboard.press('Escape');
    await settle(page);
    await expectDialogs(page, 1);
    assert.equal(await active(page), 'add-more');
    assert.deepEqual(await events(page), [], 'the modal underneath did not close');

    await page.keyboard.press('Escape');
    await settle(page);
    await expectDialogs(page, 0);
    assert.deepEqual(await events(page), ['add-close']);
    assert.equal(await active(page), 'add-open');
}));

// The confirm on main moves no focus and marks no role yet (PR #160 adds both); it only covers the
// screen. The modal under it must still let go of Tab, Escape and Enter.
vueTest('the real confirm over a modal keeps Tab, Escape and Enter', () => withPage(async (page) => {
    await page.focus('#add-open');
    await page.keyboard.press('Enter');
    await settle(page);
    await page.focus('#add-alert');
    await page.keyboard.press('Enter');
    await settle(page);

    const confirmButton = (label) => page.locator('button', { hasText: label }).last();
    const inModal = () => page.evaluate(() => window.vm.$refs.addModal.$refs.modalContent.contains(document.activeElement));

    await confirmButton('Agree').focus();
    await page.keyboard.press('Tab');
    assert.equal(await inModal(), false, 'the modal does not pull Tab back into itself');

    await page.keyboard.press('Escape');
    await settle(page);
    assert.equal(await page.evaluate(() => window.vm.$refs.addModal.isOpen), true, 'Escape does not close the modal under the confirm');
    assert.deepEqual(await events(page), ['confirm-disagree'], 'Escape answers the confirm as Disagree');

    await page.focus('#add-alert');
    await page.keyboard.press('Enter');
    await settle(page);
    await confirmButton('Disagree').focus();
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await events(page), ['confirm-disagree', 'confirm-disagree'], 'prevent-submit does not swallow Enter on the confirm');
    assert.equal(await page.evaluate(() => window.vm.$refs.addModal.isOpen), true);
}));

vueTest('an overlay opened from inside a modal (the DAM preview) takes the keyboard and one Escape', () => withPage(async (page) => {
    await page.focus('#add-open');
    await page.keyboard.press('Enter');
    await settle(page);
    await page.focus('#add-preview');
    await page.keyboard.press('Enter');
    await settle(page);

    await page.focus('#preview-close');
    await page.keyboard.press('Tab');
    assert.notEqual(await active(page), 'Close', 'Tab is not redirected to the modal behind the overlay');
    await page.focus('#preview-next');
    await page.keyboard.press('Shift+Tab');
    assert.notEqual(await active(page), 'add-save', 'Shift+Tab is not redirected either');

    await page.keyboard.press('Escape');
    await settle(page);
    assert.deepEqual(await events(page), ['preview-escape']);
    assert.equal(await page.evaluate(() => window.vm.$refs.addModal.isOpen), true, 'one Escape closes the overlay and leaves the modal open');

    await page.focus('#add-search');
    await page.keyboard.press('Escape');
    await expectDialogs(page, 0);
}));

vueTest('a modal opened by another modal\'s open listener keeps the initial focus', () => withPage(async (page) => {
    await page.focus('#chain-open');
    await page.keyboard.press('Enter');
    await settle(page);

    await expectDialogs(page, 2);
    assert.equal(await page.evaluate(() => window.vm.$refs.stackedModal.$refs.modalContent.contains(document.activeElement)), true, 'the lower modal does not take focus back');

    // A second open() on the lower, already open modal does not move focus either.
    await page.evaluate(() => window.vm.$refs.chainModal.open());
    await settle(page);
    assert.equal(await page.evaluate(() => window.vm.$refs.stackedModal.$refs.modalContent.contains(document.activeElement)), true);
}));

// The full-size image preview (components/media/image.blade.php) is a no-class modal whose content, the
// image viewer, is itself a fixed full-screen panel. That is the modal's own content, not a layer over it.
vueTest('a no-class modal whose content is the fixed image viewer still takes focus and keeps Tab', () => withPage(async (page) => {
    await page.focus('#viewer-open');
    await page.keyboard.press('Enter');
    await settle(page);

    const inViewer = () => page.evaluate(() => window.vm.$refs.viewerModal.$refs.modalContent.contains(document.activeElement));
    assert.equal(await inViewer(), true, 'focus moves into the viewer');

    const first = await active(page);
    await page.keyboard.press('Shift+Tab');
    assert.equal(await inViewer(), true, 'Shift+Tab from the first control wraps to the last');
    await page.keyboard.press('Tab');
    assert.equal(await active(page), first, 'Tab from the last control wraps to the first');

    for (let i = 0; i < 12; i++) {
        await page.keyboard.press('Tab');
        assert.equal(await inViewer(), true, 'Tab never leaves the viewer');
    }

    await page.keyboard.press('Escape');
    await expectDialogs(page, 0);
    assert.equal(await active(page), 'viewer-open');
}));

vueTest('a custom header without the title id leaves the dialog without a dangling label', () => withPage(async (page) => {
    await page.evaluate(() => window.vm.$refs.customModal.open());
    await settle(page);

    await expectDialogs(page, 1);
    assert.equal(await dialogs(page).getAttribute('aria-labelledby'), null);
    assert.equal(await dialogs(page).getAttribute('aria-label'), null, 'no label prop, no empty aria-label');
}));

// A modal with no header slot (the product picker, the full-size image previews) has no title element to
// point aria-labelledby at, so its `label` prop names it. A header title, when there is one, still wins.
for (const viewport of [{ width: 1440, height: 900 }, { width: 375, height: 812 }]) {
    vueTest(`a headerless modal is named by its label, a header title still wins, at ${viewport.width}px`, () => withPage(async (page) => {
        await page.focus('#viewer-open');
        await page.keyboard.press('Enter');
        await settle(page);

        await expectDialogs(page, 1);
        assert.equal(await dialogs(page).getAttribute('aria-labelledby'), null);
        assert.equal(await dialogs(page).getAttribute('aria-label'), 'Preview image');
        assert.equal(await page.getByRole('dialog', { name: 'Preview image', exact: true }).count(), 1, 'the accessible name is the label');

        await page.keyboard.press('Escape');
        await expectDialogs(page, 0);

        await page.focus('#row-edit');
        await page.keyboard.press('Enter');
        await settle(page);

        await expectDialogs(page, 1);
        assert.equal(await dialogs(page).getAttribute('aria-label'), null, 'aria-label stays off while the header title names the dialog');
        assert.equal(await page.getByRole('dialog', { name: 'Edit share', exact: true }).count(), 1);
    }, viewport));
}

vueTest('a popup open inside the modal takes the first Escape', () => withPage(async (page) => {
    await page.focus('#row-edit');
    await page.keyboard.press('Enter');
    await settle(page);
    await expectDialogs(page, 1);

    await page.evaluate(() => { window.openDropdowns = [{}]; });
    await page.keyboard.press('Escape');
    await settle(page);
    assert.equal(await page.evaluate(() => window.vm.$refs.shareModal.isOpen), true, 'the open dropdown takes the first Escape');

    await page.evaluate(() => { window.openDropdowns = []; });
    await page.keyboard.press('Escape');
    await settle(page);
    await expectDialogs(page, 0);
}));

vueTest('a type="full" modal keeps the phone width the picker relies on', () => withPage(async (page) => {
    await page.evaluate(() => window.vm.$refs.fullModal.open());
    await settle(page);

    // 90% of the overlay layer (375px less any scrollbar), not the 275px that calc(100vw - 100px) leaves.
    const { width, layer } = await page.evaluate(() => ({
        width: window.vm.$refs.fullModal.$refs.modalContent.getBoundingClientRect().width,
        layer: window.vm.$refs.fullModal.$refs.layer.clientWidth,
    }));
    assert.ok(Math.abs(width - layer * 0.9) < 1, `max-md:max-w-none lets the 90% width apply at 375px (got ${width}px of ${layer}px)`);
}, { width: 375, height: 812 }));

for (const viewport of [{ width: 1440, height: 900 }, { width: 375, height: 812 }]) {
    vueTest(`the close button looks exactly like the old span at ${viewport.width}px`, () => withPage(async (page) => {
        await page.focus('#row-edit');
        await page.keyboard.press('Enter');
        await settle(page);

        const looks = await page.evaluate(() => {
            const button = document.querySelector('[role="dialog"] button.icon-cancel');
            const span = document.createElement('span');
            span.className = button.className;
            button.after(span);

            const props = ['width', 'height', 'font-family', 'font-size', 'line-height', 'color', 'background-color', 'border-top-width', 'padding-top', 'padding-left', 'cursor'];
            const read = (el) => {
                const style = getComputedStyle(el);
                const before = getComputedStyle(el, '::before');
                const box = el.getBoundingClientRect();

                return {
                    box: [Math.round(box.width), Math.round(box.height)],
                    style: Object.fromEntries(props.map((prop) => [prop, style.getPropertyValue(prop)])),
                    glyph: before.content,
                };
            };

            const result = { button: read(button), span: read(span) };
            span.remove();

            return result;
        });

        assert.deepEqual(looks.button.box, looks.span.box);
        assert.deepEqual(looks.button.style, looks.span.style);
        assert.equal(looks.button.glyph, looks.span.glyph);
        assert.notEqual(looks.button.glyph, 'none');
    }, viewport));
}

// viewport-fit (the feedback dialog, for the iOS keyboard): header and footer stay put while only the content
// scrolls, and the dialog follows window.visualViewport while it is open. Chrome has no software keyboard, so a
// fake visualViewport stands in; it counts the listeners the modal adds and releases.
const fakeVisualViewport = () => {
    const live = { resize: 0, scroll: 0, focusin: 0 };
    const viewport = new EventTarget();
    Object.assign(viewport, { height: window.innerHeight, offsetTop: 0 });

    const count = (target) => {
        const add = target.addEventListener.bind(target);
        const remove = target.removeEventListener.bind(target);
        target.addEventListener = (type, ...rest) => { if (type in live) live[type] += 1; return add(type, ...rest); };
        target.removeEventListener = (type, ...rest) => { if (type in live) live[type] -= 1; return remove(type, ...rest); };
    };
    count(viewport);
    count(document);

    Object.defineProperty(window, 'visualViewport', { value: viewport, configurable: true });
    window.fakeViewport = { viewport, live };
};

const withoutVisualViewport = () => Object.defineProperty(window, 'visualViewport', { value: undefined, configurable: true });

// Moves the fake viewport, then waits one frame: the dialog has to have caught up by then.
const moveViewport = (page, { height, offsetTop }) => page.evaluate(async ({ height, offsetTop }) => {
    const { viewport } = window.fakeViewport;
    const resized = height !== viewport.height;
    Object.assign(viewport, { height, offsetTop });
    viewport.dispatchEvent(new Event(resized ? 'resize' : 'scroll'));
    await new Promise(requestAnimationFrame);
}, { height, offsetTop });

const listeners = (page) => page.evaluate(() => ({ ...window.fakeViewport.live }));

const openTall = async (page, prefix) => {
    await page.focus(`#${prefix}-open`);
    await page.keyboard.press('Enter');
    await settle(page);
    // The enter transition (md:scale-95 to scale-100, 300ms) outlasts settle on a slow runner; measure only once it ends.
    await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running'), null, { timeout: 2000 });
};

// Where the header title, the footer button and the dialog sit, and how far the scroller has gone.
const tallGeometry = (page, prefix) => page.evaluate((prefix) => {
    const box = (el) => { const { top, bottom } = el.getBoundingClientRect(); return { top: Math.round(top), bottom: Math.round(bottom) }; };
    const dialog = document.querySelector('[role="dialog"]');
    const scroller = dialog.querySelector('.min-h-0.flex-1') ?? dialog;

    return {
        title: box(document.getElementById(`${prefix}-title`)),
        save: box(document.getElementById(`${prefix}-save`)),
        dialog: box(dialog),
        scrollTop: scroller.scrollTop,
        scrollable: scroller.scrollHeight > scroller.clientHeight,
        style: dialog.getAttribute('style'),
        innerHeight: window.innerHeight,
    };
}, prefix);

const scrollToEnd = (page) => page.evaluate(() => {
    const dialog = document.querySelector('[role="dialog"]');
    const scroller = dialog.querySelector('.min-h-0.flex-1') ?? dialog;
    scroller.scrollTop = scroller.scrollHeight;
});

const sizes = [{ width: 1440, height: 900 }, { width: 375, height: 812 }];

for (const viewport of sizes) {
    vueTest(`viewport-fit keeps the header and footer in place while the content scrolls, at ${viewport.width}px`, () => withPage(async (page) => {
        await openTall(page, 'fit');
        const top = await tallGeometry(page, 'fit');
        assert.ok(top.scrollable, 'the content is taller than the dialog');
        assert.ok(top.dialog.top >= 0 && top.dialog.bottom <= top.innerHeight, 'the dialog fits the viewport');
        assert.ok(top.save.bottom <= top.dialog.bottom, 'the footer is inside the dialog at the top of the content');

        await scrollToEnd(page);
        const end = await tallGeometry(page, 'fit');
        assert.ok(end.scrollTop > 0, 'the content scrolled');
        assert.deepEqual({ title: end.title, save: end.save, dialog: end.dialog }, { title: top.title, save: top.save, dialog: top.dialog }, 'header, footer and dialog did not move');

        // The same content in a default modal scrolls as one piece: the header leaves with it.
        await page.keyboard.press('Escape');
        await expectDialogs(page, 0);
        await openTall(page, 'plain');
        const before = await tallGeometry(page, 'plain');
        await scrollToEnd(page);
        const after = await tallGeometry(page, 'plain');
        assert.ok(after.title.top < before.title.top, 'a default modal still scrolls its header away');
    }, viewport));

    for (const prefix of ['fit', 'plain']) {
        vueTest(`the ${prefix} modal keeps Tab, Shift+Tab and Escape inside and returns focus, at ${viewport.width}px`, () => withPage(async (page) => {
            await openTall(page, prefix);
            assert.equal(await active(page), 'Close');

            for (const id of [`${prefix}-first`, `${prefix}-last`, `${prefix}-save`, 'Close']) {
                await page.keyboard.press('Tab');
                assert.equal(await active(page), id);
            }

            await page.keyboard.press('Shift+Tab');
            assert.equal(await active(page), `${prefix}-save`, 'Shift+Tab wraps backwards from the first control');

            await page.keyboard.press('Escape');
            await expectDialogs(page, 0);
            assert.equal(await active(page), `${prefix}-open`);
        }, viewport));
    }

    vueTest(`viewport-fit follows the visual viewport on every change and releases it on close and unmount, at ${viewport.width}px`, () => withPage(async (page) => {
        assert.deepEqual(await listeners(page), { resize: 0, scroll: 0, focusin: 0 }, 'nothing is tracked while the modal is closed');

        await openTall(page, 'fit');
        assert.deepEqual(await listeners(page), { resize: 1, scroll: 1, focusin: 1 });

        // A keyboard-sized, panned viewport: the dialog sits inside that band, footer included.
        await moveViewport(page, { height: 300, offsetTop: 100 });
        let geometry = await tallGeometry(page, 'fit');
        assert.match(geometry.style, /top: 250px/);
        assert.match(geometry.style, /max-height: 288px/);
        assert.ok(geometry.dialog.top >= 100 && geometry.dialog.bottom <= 400, `the dialog is inside the band (${geometry.dialog.top}-${geometry.dialog.bottom})`);
        assert.ok(geometry.save.bottom <= 400 && geometry.title.top >= 100, 'header and footer are inside the band');

        // Panned only: the same height, a new offset.
        await moveViewport(page, { height: 300, offsetTop: 160 });
        geometry = await tallGeometry(page, 'fit');
        assert.match(geometry.style, /top: 310px/);
        assert.ok(geometry.dialog.top >= 160 && geometry.dialog.bottom <= 460);

        // Hidden: released, and a later change reaches nothing.
        await page.keyboard.press('Escape');
        await expectDialogs(page, 0);
        assert.deepEqual(await listeners(page), { resize: 0, scroll: 0, focusin: 0 }, 'hide releases the listeners');

        // Reopened is tracked again, and an unmounted modal releases them too.
        await openTall(page, 'fit');
        assert.deepEqual(await listeners(page), { resize: 1, scroll: 1, focusin: 1 });
        await page.evaluate(() => window.app.unmount());
        assert.deepEqual(await listeners(page), { resize: 0, scroll: 0, focusin: 0 }, 'unmount releases the listeners');
    }, viewport, (page) => page.evaluate(fakeVisualViewport)));

    vueTest(`viewport-fit without visualViewport keeps the pinned layout on the layout viewport, at ${viewport.width}px`, () => withPage(async (page) => {
        await openTall(page, 'fit');
        await page.waitForTimeout(400); // the open transition has finished
        const top = await tallGeometry(page, 'fit');
        assert.equal(top.style, null, 'no inline positioning without a visual viewport');
        assert.ok(top.dialog.top >= 0 && top.dialog.bottom <= top.innerHeight);
        assert.ok(Math.abs((top.dialog.top + top.dialog.bottom) / 2 - top.innerHeight / 2) <= 1, 'centred on the layout viewport');

        await scrollToEnd(page);
        const end = await tallGeometry(page, 'fit');
        assert.deepEqual({ title: end.title, save: end.save }, { title: top.title, save: top.save });
        assert.ok(end.scrollTop > 0);
    }, viewport, (page) => page.evaluate(withoutVisualViewport)));
}
