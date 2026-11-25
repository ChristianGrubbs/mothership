const assert = require('node:assert/strict');
const {test} = require('node:test');
const {bladeComponent, bladeFixture} = require('../fixtures/blade.cjs');
const {launchBrowser} = require('../launch-browser.cjs');

const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH && process.env.MOTHERSHIP_VUE_RUNTIME_PATH ? test : test.skip;

async function mount(page, view, canEdit = true) {
    const component = bladeComponent(view);
    const tag = view === 'explorer-list' ? 'v-dam-explorer-list' : 'v-dam-explorer-asset-card';
    const assetsProp = view === 'explorer-list' ? ':assets="assets" :selection="selection"' : 'v-for="asset in assets" :key="asset.id" :asset="asset"';
    const directive = bladeFixture('custom-thumbnail-drop.js');
    await page.setContent('<div id="app"></div>');
    await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    await page.addScriptTag({content: bladeFixture('explorer-marquee.js')});
    await page.evaluate(({component, tag, assetsProp, directive, canEdit}) => {
        const receivers = new Map();
        window.dropResult = {posts: [], uploads: [], targeted: false, handled: false, flashes: []};
        window.MothershipFavorites = {has: async () => false};
        const app = Vue.createApp({
            template: `<main><${tag} ${assetsProp} tab-id="fixture" /><div data-upload-background>Upload area</div></main>`,
            data: () => ({
                selection: {ids: []},
                assets: [
                    {id: 7, file_name: 'clip.mp4', extension: 'mp4', file_type: 'video', mime_type: 'video/mp4', path: '#old', has_thumbnail: true},
                    {id: 9, file_name: 'still.png', extension: 'png', file_type: 'image', mime_type: 'image/png', path: '#other', has_thumbnail: true},
                ],
            }),
            mounted() {
                // An earlier generated thumbnail must give way to a subsequently dropped custom thumbnail.
                this.$emitter.emit('dam:thumbnail-updated', {id: 7, url: '#generated'});
                this.$el.addEventListener('dragover', event => {
                    dropResult.targeted = !! event.damCustomThumbnailTargeted;
                });
                this.$el.addEventListener('drop', event => {
                    dropResult.handled = !! event.damCustomThumbnailHandled;
                    if (!dropResult.handled && event.dataTransfer.types.includes('Files')) {
                        dropResult.uploads.push(Array.from(event.dataTransfer.files, file => file.name));
                    }
                });
            },
        });
        app.config.globalProperties.$emitter = {
            on(name, handler) { receivers.set(name, [...(receivers.get(name) || []), handler]); },
            off() {},
            emit(name, payload) {
                if (name === 'add-flash') dropResult.flashes.push(payload.type);
                for (const handler of receivers.get(name) || []) handler(payload);
            },
        };
        app.config.globalProperties.$axios = {async post(url, form) {
            const file = form.get('thumbnail');
            const bytes = Array.from(new Uint8Array(await file.arrayBuffer()));
            dropResult.posts.push({url, name: file.name, type: file.type, bytes});
            return {data: {thumbnail_url: file.type === 'image/gif' ? URL.createObjectURL(file) : '#replacement'}};
        }};
        app.directive('dam-thumbnail', {mounted(el, binding) {
            const pixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            el.setAttribute('src', binding.value.startsWith('#') ? pixel + binding.value : binding.value);
        }});
        for (const name of new Set([...component.template.matchAll(/<(v-[a-z0-9-]+)/g)].map(match => match[1]))) {
            app.component(name, {template: '<div></div>'});
        }
        let definition;
        new Function('app', component.script)({component(_name, value) { definition = value; }});
        app.component(tag, {...definition, template: component.template});
        new Function('app', canEdit ? directive : directive.replace(/return true\b/, 'return false'))(app);
        app.mount('#app');
    }, {component, tag, assetsProp, directive, canEdit});
}

// A one-pixel GIF that loops between two palette indices.
const gifBytes = Array.from(Buffer.from(
    '47494638396101000100800000000000ffffff21ff0b4e45545343415045322e300301000000' +
    '21f904000a0000002c0000000001000100000202440100' +
    '21f904000a0000002c00000000010001000002024c01003b',
    'hex',
));
const gif = {name: 'cover.gif', type: 'image/gif', bytes: gifBytes};
const png = {name: 'cover.png', type: 'image/png'};
const fallbackBytes = Array.from(Buffer.from('test image bytes'));

async function drop(page, selector, files, internal = false) {
    return page.locator(selector).first().evaluate((element, {files, internal, fallbackBytes}) => {
        const transfer = new DataTransfer();
        for (const file of files) {
            transfer.items.add(new File([new Uint8Array(file.bytes || fallbackBytes)], file.name, {type: file.type}));
        }
        if (internal) transfer.setData('application/json', JSON.stringify({type: 'dam-asset', id: 12}));
        for (const type of ['dragenter', 'dragover', 'drop']) {
            element.dispatchEvent(new DragEvent(type, {bubbles: true, cancelable: true, dataTransfer: transfer}));
        }
        return {targeted: dropResult.targeted, handled: dropResult.handled};
    }, {files, internal, fallbackBytes});
}

browserTest('one immediate external image drop replaces the correct Library grid or list thumbnail', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    try {
        for (const [view, file] of [['explorer-asset-card', gif], ['explorer-list', png], ['explorer-list', {name: 'cover.jpg', type: ''}]]) {
            const page = await browser.newPage();
            await mount(page, view);
            const target = '[data-dam-asset-id="7"]';
            assert.match(await page.locator(`${target} img`).first().getAttribute('src'), /#generated$/);
            assert.deepEqual(await drop(page, `${target} img`, [file]), {targeted: true, handled: true}, view);
            const replacement = page.locator(`${target} img${file === gif ? '[src^="blob:"]' : '[src$="#replacement"]'}`);
            await replacement.waitFor();
            if (file === gif) await replacement.evaluate(image => image.decode());
            assert.match(await page.locator('[data-dam-asset-id="9"] img').first().getAttribute('src'), /#other$/);
            const result = await page.evaluate(() => dropResult);
            assert.deepEqual(result.uploads, []);
            assert.deepEqual(result.flashes, ['success']);
            assert.equal(result.posts.length, 1);
            assert.match(result.posts[0].url, /\/dam\/assets\/7\/custom-thumbnail$/);
            const {name, type, bytes} = result.posts[0];
            assert.deepEqual({name, type, bytes}, {...file, bytes: file.bytes || fallbackBytes});
            await page.close();
        }
    } finally { await browser.close(); }
});

browserTest('the thumbnail directive leaves folder uploads and internal drags alone', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    try {
        const page = await browser.newPage();
        await mount(page, 'explorer-list');
        const target = '[data-dam-asset-id="7"]';
        const inactive = {targeted: false, handled: false};
        assert.deepEqual(await drop(page, '[data-upload-background]', [gif]), inactive);
        assert.deepEqual(await drop(page, '[data-dam-asset-id="9"]', [gif]), inactive);
        assert.deepEqual(await drop(page, target, [gif, png]), inactive);
        assert.deepEqual(await drop(page, target, [{name: 'notes.pdf', type: 'application/pdf'}]), inactive);
        assert.deepEqual(await drop(page, target, [], true), inactive);
        assert.deepEqual(await page.evaluate(() => dropResult.uploads), [[gif.name], [gif.name], [gif.name, png.name], ['notes.pdf']]);
        assert.deepEqual(await page.evaluate(() => dropResult.posts), []);
        await page.close();

        const readOnly = await browser.newPage();
        await mount(readOnly, 'explorer-list', false);
        assert.deepEqual(await drop(readOnly, target, [gif]), inactive);
        assert.deepEqual(await readOnly.evaluate(() => dropResult.posts), []);
    } finally { await browser.close(); }
});
