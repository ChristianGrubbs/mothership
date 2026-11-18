// The Share dialog's "Who can see this" section (packages/Mothership/DAM/src/Resources/views/share/components/
// share-link-modal.blade.php, ADR 0010 sharing slice 3), driven in a real browser against the shipped
// template and the shipped app.component('v-share-link-modal', {...}) registration — never a
// re-implementation of either. It covers the three modes, the picker's chips, a folder's cascade note and
// the read-only view a non-owner gets, plus the fact that the section renders nothing while the access model
// is `grants`. The public-link half below the section is covered where it shares the section's per-opening
// guard: a share list or a link write that answers once another item's dialog is open never reaches it.
//
// Blade cannot run here, so the two server-side branches this file needs are evaluated the way
// dam-explorer-asset-card-open.test.cjs evaluates its permission chains: `@if (dam_access_lists())` is
// resolved for both values of the flag, and every `@lang`/`@js(trans())` key under
// dam::app.admin.dam.access is resolved against the real en_US lang file, so a renamed key fails here
// instead of shipping an empty label. The PHP side — who may read or set a list — is covered by
// packages/Mothership/DAM/tests/Feature/Access/AccessControllerTest.php.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const modalFile = 'packages/Mothership/DAM/src/Resources/views/share/components/share-link-modal.blade.php';
const langFile = 'packages/Mothership/DAM/src/Resources/lang/en_US/app.php';

const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

/**
 * The real strings behind dam::app.admin.dam.access.*, read out of the canonical lang file's flat
 * `'access' => [ 'key' => 'value', ... ]` block. Parsed rather than hand-copied so this test fails when a
 * key is renamed or dropped, which is exactly what `mothership:translations:check` cannot see from a Blade file.
 */
function accessStrings() {
    const php = source(langFile);
    const start = php.indexOf("'access' => [");
    assert.ok(start !== -1, "the 'access' lang block must exist in en_US/app.php");
    const end = php.indexOf('],', start);
    assert.ok(end !== -1, "the 'access' lang block must be closed");

    const strings = {};
    for (const [, key, value] of php.slice(start, end).matchAll(/'([a-z0-9-]+)'\s*=>\s*'((?:[^'\\]|\\.)*)'/g)) {
        strings[key] = value.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
    }

    for (const key of ['heading', 'people-heading', 'has-access', 'mode-private', 'mode-people', 'mode-everyone', 'cascade-note', 'read-only', 'owner-other', 'owner-you', 'save', 'members-count', 'remove', 'summary-private', 'summary-people', 'summary-everyone', 'summary-admins', 'role-group', 'copy-link', 'public-confirm', 'public-warning', 'public-exists']) {
        assert.ok(strings[key], `dam::app.admin.dam.access.${key} must exist`);
    }

    return strings;
}

const strings = accessStrings();

/**
 * Renders the Blade source the way the server would for an access model of `lists` or `grants`: the one
 * `@if (dam_access_lists())` branch is evaluated, every access lang key becomes its real string, every other
 * translation becomes an inert label, and each `{{ route(...) }}` becomes a stub path that keeps the
 * __type/__id placeholders the component's own .replace() calls consume.
 */
function blade(text, { lists }) {
    const out = [];
    let emitting = true;
    let inside = false;

    for (const line of text.split('\n')) {
        if (/^\s*@if \(dam_access_lists\(\)\)\s*$/.test(line)) {
            assert.equal(inside, false, 'the dam_access_lists() branch in the modal is not nested');
            inside = true;
            emitting = lists;
            continue;
        }

        if (inside && /^\s*@endif\s*$/.test(line)) {
            inside = false;
            emitting = true;
            continue;
        }

        if (emitting) out.push(line);
    }

    assert.equal(inside, false, 'the dam_access_lists() branch must be closed');

    return out.join('\n')
        // Blade comments would be a Vue mustache or a JS syntax error once inlined.
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@js\(dam_access_lists\(\)\)/g, lists ? 'true' : 'false')
        .replace(/@js\(trans\('dam::app\.admin\.dam\.access\.([a-z0-9-]+)'\)\)/g, (_, key) => JSON.stringify(strings[key] ?? key))
        .replace(/@lang\('dam::app\.admin\.dam\.access\.([a-z0-9-]+)'\)/g, (_, key) => strings[key] ?? key)
        // English trans_choice: the first `|` form for one, the second otherwise.
        .replace(/@js\(trans_choice\('dam::app\.admin\.dam\.access\.([a-z0-9-]+)', (\d+)(?:, \[[^\]]*\])?\)\)/g, (_, key, count) => {
            const forms = String(strings[key] ?? key).split('|');
            return JSON.stringify(forms[Number(count) === 1 ? 0 : forms.length - 1]);
        })
        .replace(/@js\(trans\('[^']*'\)\)/g, '"Translation"')
        .replace(/@lang\('[^']*'\)/g, 'Translation')
        .replace(/\{\{\s*route\('admin\.dam\.assets\.index'[^}]*\}\}/g, '/library')
        .replace(/\{\{\s*route\('admin\.dam\.assets\.open'[^}]*\}\}/g, '/assets/open/__id')
        .replace(/\{\{\s*route\('([^']+)'[^}]*\}\}/g, (_, name) => `/fixture/${name}?type=__type&id=__id`)
        .replace(/@\{\{/g, '{{')
        .replace(/@@/g, '@');
}

function modal({ lists }) {
    const php = source(modalFile);
    const template = php.match(/<script\s+type="text\/x-template"\s+id="v-share-link-modal-template"\s*>([\s\S]*?)<\/script>/);
    assert.ok(template, 'v-share-link-modal-template must exist');
    const registration = php.match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(registration, "app.component('v-share-link-modal', ...) must exist");
    assert.match(registration[1], /app\.component\('v-share-link-modal'/);

    return { template: blade(template[1], { lists }), script: blade(registration[1], { lists }) };
}

const owner = { id: 4, name: 'Dana Owner' };
const folderDescribe = {
    type: 'directory',
    id: 7,
    name: 'Campus photos',
    owner,
    mode: 'private',
    users: [],
    groups: [],
    can_edit: true,
    owned_by_viewer: true,
    cascade: { folders: 3, files: 12, owned_by_others: 2 },
};
const principals = {
    users: [{ id: 9, name: 'Sam Friend', email: 'sam@example.test' }],
    groups: [{ id: 2, name: 'Everyone', member_count: 14 }],
};

/**
 * Mounts the shipped template and component in Chromium behind a stubbed $axios, and reports every request
 * it makes to Node so the payload the Save button sends can be asserted rather than inferred.
 */
async function mount(browser, { lists = true, describe = folderDescribe, open = { targetType: 'directory', targetId: 7 }, defer = [], shares = [] } = {}) {
    const page = await browser.newPage();
    const requests = [];

    await page.exposeFunction('reportRequest', (method, url, body) => requests.push({ method, url, body }));
    await page.route('http://share.test/**', (route) => route.fulfill({
        contentType: 'text/html',
        body: '<div id="app"><v-share-link-modal></v-share-link-modal></div>',
    }));
    await page.goto('http://share.test/library');
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate(({ template, script, describe, principals, defer, shares }) => {
        const node = document.createElement('script');
        node.type = 'text/x-template';
        node.id = 'v-share-link-modal-template';
        node.textContent = template;
        document.body.append(node);

        const listeners = {};
        const emitter = {
            on(event, handler) { (listeners[event] ??= []).push(handler); },
            off(event, handler) { listeners[event] = (listeners[event] ?? []).filter((h) => h !== handler); },
            emit(event, payload) {
                window.__emitted.push({ event, payload });
                (listeners[event] ?? []).forEach((handler) => handler(payload));
            },
        };

        // Every request of a deferred kind is parked here, in the order the dialog made it, so a test can
        // answer them in any order — or reject one — and watch what the dialog does with a late reply.
        window.__pending = [];
        window.__emitted = [];
        window.__settle = (index, data) => window.__pending[index].resolve({ data });
        window.__fail = (index, message) => window.__pending[index].reject({ response: { data: { message } } });
        window.__parked = () => window.__pending.map((entry) => entry.kind);

        const deferred = defer === true ? ['describe', 'save', 'principals'] : (defer || []);
        const park = (kind, url) => new Promise((resolve, reject) => window.__pending.push({ kind, url, resolve, reject }));

        const answer = (method) => (url, payload) => {
            window.reportRequest(method, url, payload ? JSON.stringify(payload) : null);

            if (url.includes('access/principals') || url.includes('access.principals')) {
                return deferred.includes('principals')
                    ? park('principals', url)
                    : Promise.resolve({ data: principals });
            }

            // The public-link section answers at once unless a case parks it: `shares` is an item's list of
            // links, and `link-write` a create, save, reauthorize or revoke of one.
            if (url.includes('shared-links')) {
                const kind = method === 'get' ? 'shares' : 'link-write';

                return deferred.includes(kind) ? park(kind, url) : Promise.resolve({ data: { shares } });
            }

            if (url.includes('revoke_public_links')) {
                return Promise.resolve({ data: { public_links: { direct: 0, inherited: [] } } });
            }

            if (method === 'get') {
                return deferred.includes('describe') ? park('describe', url) : Promise.resolve({ data: describe });
            }

            return deferred.includes('save')
                ? park('save', url)
                : Promise.resolve({ data: { saved: true, message: 'Sharing updated.' } });
        };

        const app = Vue.createApp({});
        app.config.globalProperties.$emitter = emitter;
        app.config.globalProperties.$axios = {
            // The component calls get() with a params object for the picker and without one elsewhere.
            get: (url, config) => answer('get')(config?.params?.q ? `${url}?q=${config.params.q}` : url),
            put: answer('put'),
            post: answer('post'),
            patch: answer('patch'),
        };
        // The shared shell components the dialog renders inside; only their slots matter here.
        app.component('v-modal', { template: '<div><slot name="header" :toggle="close"></slot><slot name="content"></slot></div>', methods: { close() {} } });
        app.component('v-multiselect', {
            template: '<div></div>',
            props: ['modelValue', 'options'],
            emits: ['update:modelValue'],
            mounted() { window.__pickExpiry = (value) => this.$emit('update:modelValue', this.options.find((option) => option.value === value)); },
        });

        new Function('app', script)(app);
        app.mount('#app');
        window.__openShareModal = (payload) => emitter.emit('open-share-modal', payload);
    }, { ...modal({ lists }), describe, principals, open, defer, shares });

    if (open !== null) {
        await page.evaluate((payload) => window.__openShareModal(payload), open);
    }

    return { page, requests };
}

const browserTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;
const launch = () => require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

browserTest('a folder owner gets the three modes, the cascade note and a working picker', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser);

        await page.locator('[data-dam-access-section]').waitFor();

        // The three modes, in the order the spec lists them, each one selectable.
        assert.deepEqual(
            await page.locator('[data-dam-access-mode]').evaluateAll((nodes) => nodes.map((node) => node.dataset.damAccessMode)),
            ['private', 'people', 'everyone']
        );
        assert.equal(await page.locator('[data-dam-access-mode="private"]').isChecked(), true);

        // describe() supplies the header and the owner line.
        await assert.doesNotReject(page.locator('text=Campus photos').first().waitFor());
        assert.equal((await page.locator('[data-dam-access-owner]').textContent()).trim(), strings['owner-you']);

        // "Applies to N folders and M files, including K owned by other people."
        const note = (await page.locator('[data-dam-access-cascade]').textContent()).trim();
        assert.equal(note, strings['cascade-note'].replace(':folders', '3').replace(':files', '12').replace(':owned', '2'));
        assert.ok(! note.includes(':'), `the cascade note left a placeholder unfilled: ${note}`);

        // The picker appears only for the middle mode.
        assert.equal(await page.locator('[data-dam-access-picker]').count(), 0);
        await page.locator('[data-dam-access-mode="people"]').check();
        await page.locator('[data-dam-access-picker]').waitFor();

        await page.locator('[data-dam-access-search]').fill('sam');
        await page.locator('[data-dam-access-add="user-9"]').click();
        assert.deepEqual(
            await page.locator('[data-dam-access-chip]').evaluateAll((nodes) => nodes.map((node) => node.textContent.trim())),
            ['Sam Friend']
        );
        // Adding clears the box, so the matches list closes again.
        assert.equal(await page.locator('[data-dam-access-search]').inputValue(), '');

        // A group chip too, and Everyone came back first from the endpoint.
        await page.locator('[data-dam-access-search]').fill('every');
        await page.locator('[data-dam-access-add="group-2"]').click();
        assert.deepEqual(
            await page.locator('[data-dam-access-chip]').evaluateAll((nodes) => nodes.map((node) => node.textContent.trim())),
            ['Everyone', 'Sam Friend']
        );

        await page.locator('[data-dam-access-chip-remove="user-9"]').click();
        assert.deepEqual(
            await page.locator('[data-dam-access-chip]').evaluateAll((nodes) => nodes.map((node) => node.textContent.trim())),
            ['Everyone']
        );

        await page.locator('[data-dam-access-save]').click();

        const saved = requests.find((request) => request.method === 'put');
        assert.ok(saved, 'Save must send a PUT');
        assert.match(saved.url, /access\.update\?type=directory&id=7$/);
        assert.deepEqual(JSON.parse(saved.body), { mode: 'people', users: [], groups: [2] });

        // The public-link section is its own, closed one; Copy link is always there.
        assert.equal(await page.locator('[data-dam-public-toggle]').count(), 1);
        assert.equal(await page.locator('[data-dam-copy-link]').count(), 1);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('an editor sees who already has access, and the picker marks those people', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, {
            describe: {
                ...folderDescribe,
                people: [
                    { id: 4, name: 'Dana Owner', email: 'dana@example.test', reasons: ['Owner'] },
                    { id: 9, name: 'Sam Friend', email: 'sam@example.test', reasons: ['via group Studio crew', 'Direct'] },
                ],
            },
        });

        await page.locator('[data-dam-access-people]').waitFor();
        assert.ok((await page.locator('[data-dam-access-people]').textContent()).includes(strings['people-heading']));
        assert.deepEqual(
            await page.locator('[data-dam-access-person]').evaluateAll((nodes) => nodes.map((node) => node.dataset.damAccessPerson)),
            ['4', '9']
        );
        assert.ok((await page.locator('[data-dam-access-person="9"]').textContent()).includes('via group Studio crew'));

        // A user who already has access still shows in the picker, with why instead of an email address.
        await page.locator('[data-dam-access-mode="people"]').check();
        await page.locator('[data-dam-access-search]').fill('sam');
        const option = (await page.locator('[data-dam-access-add="user-9"]').textContent()).replace(/\s+/g, ' ').trim();
        assert.ok(option.includes(strings['has-access'].replace(':reason', 'via group Studio crew')), option);
        assert.ok(! option.includes('sam@example.test'), option);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a hostile name, email or reason in the people list is shown as text, never as markup', async () => {
    const browser = await launch();

    try {
        const hostile = '<img src=x onerror="window.__pwned=1">';
        const { page } = await mount(browser, {
            describe: {
                ...folderDescribe,
                people: [{ id: 9, name: hostile, email: hostile, reasons: [hostile] }],
            },
        });

        await page.locator('[data-dam-access-person="9"]').waitFor();
        assert.equal(await page.locator('[data-dam-access-people] img').count(), 0);
        assert.ok((await page.locator('[data-dam-access-person="9"]').textContent()).includes(hostile));
        assert.equal(await page.evaluate(() => window.__pwned), undefined);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a describe without people renders no people list', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser);

        await page.locator('[data-dam-access-section]').waitFor();
        await page.locator('[data-dam-access-mode]').first().waitFor();
        assert.equal(await page.locator('[data-dam-access-people]').count(), 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('picking Everyone or Only me sends no principals of its own', async () => {
    const browser = await launch();

    try {
        for (const [mode, expected] of [['everyone', 'everyone'], ['private', 'private']]) {
            const { page, requests } = await mount(browser);
            await page.locator(`[data-dam-access-mode="${mode}"]`).check();
            await page.locator('[data-dam-access-save]').click();

            const saved = requests.find((request) => request.method === 'put');
            assert.deepEqual(JSON.parse(saved.body), { mode: expected, users: [], groups: [] });
            await page.close();
        }
    } finally {
        await browser.close();
    }
});

browserTest('a non-owner sees the list read-only, named after its owner', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, {
            describe: {
                type: 'asset',
                id: 31,
                name: 'poster.png',
                owner,
                mode: 'people',
                users: [{ id: 9, name: 'Sam Friend', email: 'sam@example.test' }],
                groups: [{ id: 5, name: 'Studio crew', member_count: 3 }],
                can_edit: false,
                owned_by_viewer: false,
            },
            open: { targetType: 'asset', targetId: 31 },
        });

        await page.locator('[data-dam-access-read-only]').waitFor();

        assert.equal(
            (await page.locator('[data-dam-access-owner]').textContent()).trim(),
            strings['owner-other'].replace(':owner', owner.name)
        );
        assert.equal((await page.locator('[data-dam-access-current]').textContent()).trim(), strings['mode-people']);
        assert.deepEqual(
            await page.locator('[data-dam-access-chip]').evaluateAll((nodes) => nodes.map((node) => node.textContent.trim())),
            ['Studio crew', 'Sam Friend']
        );

        // Nothing to change it with, and no chip carries a remove control.
        assert.equal(await page.locator('[data-dam-access-mode]').count(), 0);
        assert.equal(await page.locator('[data-dam-access-save]').count(), 0);
        assert.equal(await page.locator('[data-dam-access-chip-remove]').count(), 0);
        assert.equal(requests.filter((request) => request.method === 'put').length, 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('under grants the dialog has no access section and asks the access endpoints nothing', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, { lists: false });

        // The public-link modal itself still opens; only the sharing section is gone.
        await page.locator('[data-dam-access-section]').waitFor({ state: 'detached' });
        assert.equal(await page.locator('[data-dam-access-mode]').count(), 0);
        assert.equal(await page.locator('[data-dam-access-owner]').count(), 0);
        assert.equal(requests.filter((request) => request.url.includes('access')).length, 0);
        assert.ok(requests.some((request) => request.url.includes('shared-links')), 'the public-link section still loads');

        await page.close();
    } finally {
        await browser.close();
    }
});

const assetDescribe = {
    type: 'asset',
    id: 31,
    name: 'poster.png',
    owner,
    mode: 'everyone',
    users: [],
    groups: [{ id: 2, name: 'Everyone', member_count: 14 }],
    can_edit: true,
    owned_by_viewer: true,
};

browserTest('a describe that arrives after a second dialog has opened is ignored', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, { defer: ['describe'], open: null });

        // A, then B, with neither describe answered yet.
        await page.evaluate(() => window.__openShareModal({ targetType: 'directory', targetId: 7 }));
        await page.evaluate(() => window.__openShareModal({ targetType: 'asset', targetId: 31 }));
        assert.deepEqual(await page.evaluate(() => window.__parked()), ['describe', 'describe']);

        // B answers first, then A — the reordering the reviewer's probe used.
        await page.evaluate((payloads) => {
            window.__settle(1, payloads.asset);
            window.__settle(0, payloads.folder);
        }, { folder: folderDescribe, asset: assetDescribe });

        await page.locator('[data-dam-access-section]').waitFor();
        // One round trip per settled promise, so both callbacks have run before the assertions.
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

        // B's item, B's mode, B's owner line — none of A's.
        await page.locator('text=poster.png').first().waitFor();
        assert.equal(await page.locator('text=Campus photos').count(), 0, "the first dialog's name overwrote the second");
        assert.equal(await page.locator('[data-dam-access-mode="everyone"]').isChecked(), true);
        // A is a folder and would have brought a cascade note with it.
        assert.equal(await page.locator('[data-dam-access-cascade]').count(), 0, "the first dialog's cascade note leaked into the second");

        // And the save that follows is B's, in both its URL and its payload.
        await page.locator('[data-dam-access-save]').click();
        const saved = requests.find((request) => request.method === 'put');
        assert.match(saved.url, /access\.update\?type=asset&id=31$/);
        assert.deepEqual(JSON.parse(saved.body), { mode: 'everyone', users: [], groups: [] });

        await page.close();
    } finally {
        await browser.close();
    }
});

/** Opens dialog A and answers its describe, leaving it fully rendered and ready to save. */
async function openA(page) {
    await page.evaluate(() => window.__openShareModal({ targetType: 'directory', targetId: 7 }));
    await page.evaluate((payload) => window.__settle(0, payload), folderDescribe);
    await page.locator('[data-dam-access-save]').waitFor();
}

/** The loading row B shows while its own describe is still in flight. */
const bStillLoading = async (page) => ({
    loading: await page.locator('[data-dam-access-section]').locator('text=' + strings.loading).count(),
    modes: await page.locator('[data-dam-access-mode]').count(),
    save: await page.locator('[data-dam-access-save]').count(),
    owner: await page.locator('[data-dam-access-owner]').count(),
});

browserTest('a save that lands after another dialog opens reports its own item and touches nothing else', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, { defer: ['describe', 'save'], open: null });

        await openA(page);

        // Save A, and park its PUT.
        await page.locator('[data-dam-access-save]').click();
        assert.deepEqual(await page.evaluate(() => window.__parked()), ['describe', 'save']);

        // B opens while A's save is still in flight; B's own describe stays pending.
        await page.evaluate(() => window.__openShareModal({ targetType: 'asset', targetId: 31 }));
        assert.deepEqual(await page.evaluate(() => window.__parked()), ['describe', 'save', 'describe']);

        const before = await page.evaluate(() => window.__emitted.length);
        const loadingBefore = await bStillLoading(page);

        // A's save finally answers.
        await page.evaluate(() => window.__settle(1, { saved: true, message: 'Sharing updated.' }));
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

        const after = await page.evaluate((from) => window.__emitted.slice(from), before);

        // The listing still hears about A — the write landed, whatever is on screen now.
        assert.deepEqual(
            after.filter((entry) => entry.event === 'dam:access-updated').map((entry) => entry.payload),
            [{ targetType: 'directory', targetId: 7, mode: 'private' }]
        );

        // But nothing else from A: no toast over B, and no read-back that would have gone to B's target.
        assert.deepEqual(after.filter((entry) => entry.event === 'add-flash'), []);
        assert.deepEqual(await page.evaluate(() => window.__parked()), ['describe', 'save', 'describe'], "A's save reloaded a describe");

        // B is exactly as it was: still loading, nothing rendered, nothing saveable.
        assert.deepEqual(await bStillLoading(page), loadingBefore);
        assert.equal(loadingBefore.loading, 1);
        assert.equal(loadingBefore.modes, 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a save that fails after another dialog opens reports no error over it', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, { defer: ['describe', 'save'], open: null });

        await openA(page);
        await page.locator('[data-dam-access-save]').click();
        await page.evaluate(() => window.__openShareModal({ targetType: 'asset', targetId: 31 }));

        const before = await page.evaluate(() => window.__emitted.length);
        const loadingBefore = await bStillLoading(page);

        // A's save is refused once B is on screen.
        await page.evaluate(() => window.__fail(1, 'Only the owner or a full admin can change who sees this item.'));
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

        const after = await page.evaluate((from) => window.__emitted.slice(from), before);

        // No error toast against B's dialog, and a failed write is never reported to the listing.
        assert.deepEqual(after.filter((entry) => entry.event === 'add-flash'), []);
        assert.deepEqual(after.filter((entry) => entry.event === 'dam:access-updated'), []);
        assert.deepEqual(await bStillLoading(page), loadingBefore);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest("a late save never clears the next dialog's own saving state", async () => {
    const browser = await launch();

    // Settled and rejected both run saveAccess()'s finally, which is the guard under test here: without it,
    // A's completion clears isSavingAccess while B's own PUT is still in flight, and B's button comes back to
    // life mid-save (Codex re-review of PR #69, finding 1).
    try {
        for (const outcome of ['settled', 'rejected']) {
            const { page } = await mount(browser, { defer: ['describe', 'save'], open: null });

            await openA(page);
            await page.locator('[data-dam-access-save]').click();

            // B opens, answers, and starts a save of its own.
            await page.evaluate(() => window.__openShareModal({ targetType: 'asset', targetId: 31 }));
            await page.evaluate((payload) => window.__settle(2, payload), assetDescribe);
            await page.locator('[data-dam-access-save]').waitFor();
            assert.equal(await page.locator('[data-dam-access-save]').isDisabled(), false, `${outcome}: B should be saveable before it saves`);

            await page.locator('[data-dam-access-save]').click();
            assert.deepEqual(await page.evaluate(() => window.__parked()), ['describe', 'save', 'describe', 'save'], outcome);

            const saving = page.locator('[data-dam-access-save]');
            assert.equal(await saving.isDisabled(), true, `${outcome}: B's own save should disable its button`);
            assert.equal((await saving.textContent()).trim(), strings.saving, outcome);

            // A's save answers, late.
            outcome === 'settled'
                ? await page.evaluate(() => window.__settle(1, { saved: true, message: 'Sharing updated.' }))
                : await page.evaluate(() => window.__fail(1, 'Failed to update sharing.'));
            await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

            // B is still saving: its button must not have come back while its own request is outstanding.
            assert.equal(await saving.isDisabled(), true, `${outcome}: A's late save re-enabled B's button`);
            assert.equal((await saving.textContent()).trim(), strings.saving, `${outcome}: A's late save ended B's saving state`);

            // B's own answer is what ends it. A successful save then reads the item back, so the dialog goes
            // into its loading row before the button returns — settle that read-back too and the cycle closes
            // with an enabled button, which is what proves B's saving state was B's all along.
            await page.evaluate(() => window.__settle(3, { saved: true, message: 'Sharing updated.' }));
            await page.waitForFunction(() => window.__parked().length === 5);
            assert.deepEqual(await page.evaluate(() => window.__parked()), ['describe', 'save', 'describe', 'save', 'describe'], outcome);
            assert.equal(await page.locator('[data-dam-access-save]').count(), 0, `${outcome}: the read-back should replace the button with the loading row`);

            await page.evaluate((payload) => window.__settle(4, payload), assetDescribe);
            await page.locator('[data-dam-access-save]').waitFor();
            assert.equal(await saving.isDisabled(), false, outcome);
            assert.equal((await saving.textContent()).trim(), strings.save, outcome);

            await page.close();
        }
    } finally {
        await browser.close();
    }
});

browserTest('a describe that completes late leaves the pending dialog still loading', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, { defer: ['describe'], open: null });

        // Both pending: A first, then B.
        await page.evaluate(() => window.__openShareModal({ targetType: 'directory', targetId: 7 }));
        await page.evaluate(() => window.__openShareModal({ targetType: 'asset', targetId: 31 }));

        const before = await page.evaluate(() => window.__emitted.length);

        // Only the stale one answers. Its finally() must not end B's loading state.
        await page.evaluate((payload) => window.__settle(0, payload), folderDescribe);
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

        assert.deepEqual(await bStillLoading(page), { loading: 1, modes: 0, save: 0, owner: 0 });
        assert.deepEqual(await page.evaluate((from) => window.__emitted.slice(from), before), []);

        // B's own answer then renders B, and nothing of A's survives.
        await page.evaluate((payload) => window.__settle(1, payload), assetDescribe);
        await page.locator('[data-dam-access-mode="everyone"]').waitFor();
        assert.equal(await page.locator('text=Campus photos').count(), 0);
        assert.equal(await page.locator('[data-dam-access-cascade]').count(), 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

/** Every parked request of one kind, newest last, as indexes into window.__pending. */
const parkedIndexes = (page, kind) => page.evaluate(
    (wanted) => window.__parked().flatMap((entry, index) => (entry === wanted ? [index] : [])),
    kind
);

browserTest('picker matches from an earlier dialog never reach the next one', async () => {
    const browser = await launch();
    // Both dialogs search the same word, so the component's query-text check passes for the stale reply too
    // and only the generation guard can keep it out. The payloads differ so a leak is visible.
    const stale = { users: [{ id: 99, name: 'Stale Person', email: 'stale@example.test' }], groups: [] };
    const fresh = { users: [{ id: 9, name: 'Sam Friend', email: 'sam@example.test' }], groups: [] };

    try {
        const { page } = await mount(browser, { defer: ['describe', 'principals'], open: null });

        await openA(page);

        // A's picker searches and its answer is parked.
        await page.locator('[data-dam-access-mode="people"]').check();
        await page.locator('[data-dam-access-search]').fill('sam');
        await page.waitForFunction(() => window.__parked().includes('principals'));
        const [staleIndex] = await parkedIndexes(page, 'principals');

        // B opens, answers, and is searching the same word.
        await page.evaluate(() => window.__openShareModal({ targetType: 'asset', targetId: 31 }));
        const bDescribe = (await parkedIndexes(page, 'describe')).pop();
        await page.evaluate(([index, payload]) => window.__settle(index, payload), [bDescribe, { ...assetDescribe, mode: 'people', users: [], groups: [] }]);
        await page.locator('[data-dam-access-picker]').waitFor();
        await page.locator('[data-dam-access-search]').fill('sam');
        await page.waitForFunction((known) => window.__parked().filter((kind) => kind === 'principals').length > known, 1);

        // A's matches arrive now, with B's box holding the very same term.
        await page.evaluate(([index, payload]) => window.__settle(index, payload), [staleIndex, stale]);
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

        assert.equal(await page.locator('[data-dam-access-add="user-99"]').count(), 0, "A's matches leaked into B");

        // B's own matches render, and only those.
        const freshIndex = (await parkedIndexes(page, 'principals')).pop();
        await page.evaluate(([index, payload]) => window.__settle(index, payload), [freshIndex, fresh]);
        await page.locator('[data-dam-access-add="user-9"]').waitFor();
        assert.equal(await page.locator('[data-dam-access-add="user-99"]').count(), 0);
        assert.equal(await page.locator('[data-dam-access-chip]').count(), 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

/**
 * A link as the share endpoints present it to a viewer who may change it (its creator, its target's owner or a
 * full admin, with the ACL key: ShareController::presentShare's can_manage / can_delete, UX eval R14). Each item's
 * URL is its own, so one shown on the wrong item is visible.
 */
const shareLink = (id, status = 'active') => ({
    id,
    name: null,
    status,
    public_url: `https://share.test/s/token-${id}`,
    expires_at: null,
    update_url: `/fixture/shared-links/${id}`,
    reauthorize_url: `/fixture/shared-links/${id}/reauthorize`,
    can_manage: true,
    can_delete: true,
});
const folderLink = shareLink(501);
const assetLink = shareLink(502);

/** The links the public-link half shows, each as "<status> <url>". */
const shownLinks = (page) => page.locator('[data-dam-share-url]').evaluateAll(
    (nodes) => nodes.map((node) => `${node.dataset.damShareUrl} ${node.value}`)
);

browserTest('a share list that arrives after a second dialog has opened is ignored', async () => {
    const browser = await launch();
    // The folder's list as a second opening of it reads it: another link, so the two reads can be told apart.
    const reread = shareLink(503);
    const cases = [
        // Under `grants` as well, today's default, where the public link is all the dialog holds.
        { lists: false, second: { targetType: 'asset', targetId: 31 }, secondLink: assetLink },
        { lists: true, second: { targetType: 'asset', targetId: 31 }, secondLink: assetLink },
        // And the same folder opened again. Closing the dialog only hides the shell modal, so a reopen is
        // exactly this: a second opening.
        { lists: false, second: { targetType: 'directory', targetId: 7 }, secondLink: reread },
    ];

    try {
        for (const { lists, second, secondLink } of cases) {
            const label = `lists=${lists}, then ${second.targetType} ${second.targetId}`;
            const { page } = await mount(browser, { lists, defer: ['shares'], open: null });

            // The folder, then the second opening, with neither list answered yet.
            await page.evaluate(() => window.__openShareModal({ targetType: 'directory', targetId: 7 }));
            await page.evaluate((payload) => window.__openShareModal(payload), second);
            assert.deepEqual(await page.evaluate(() => window.__parked()), ['shares', 'shares'], label);

            // The second answers first, then the first.
            await page.evaluate(([first, latest]) => {
                window.__settle(1, { shares: [latest] });
                window.__settle(0, { shares: [first] });
            }, [folderLink, secondLink]);
            await page.locator('[data-dam-share-url]').waitFor();
            await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

            // The second opening's link, not the first's: Copy would otherwise hand out the first item's URL.
            assert.deepEqual(await shownLinks(page), [`active ${secondLink.public_url}`], label);

            await page.close();
        }
    } finally {
        await browser.close();
    }
});

browserTest('two openings in one tick load only the second item', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, { defer: ['shares', 'describe'], open: null });

        // Both emitted before either opening's $nextTick callback can run.
        await page.evaluate(() => {
            window.__openShareModal({ targetType: 'directory', targetId: 7 });
            window.__openShareModal({ targetType: 'asset', targetId: 31 });
        });

        // One list and one describe, both B's. Two of each would all pass the guards: the first to finish would
        // end B's loading state early, and the other could still overwrite whatever B did in between.
        assert.deepEqual(await page.evaluate(() => window.__parked()), ['shares', 'describe']);
        for (const url of await page.evaluate(() => window.__pending.map((entry) => entry.url))) {
            assert.match(url, /type=asset&id=31$/);
        }

        // And they render B.
        await page.evaluate(([link, describe]) => {
            window.__settle(0, { shares: [link] });
            window.__settle(1, describe);
        }, [assetLink, assetDescribe]);
        await page.locator('[data-dam-share-url]').waitFor();
        assert.deepEqual(await shownLinks(page), [`active ${assetLink.public_url}`]);
        await page.locator('text=poster.png').first().waitFor();

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a share list that completes late leaves the next dialog still loading', async () => {
    const browser = await launch();

    try {
        for (const outcome of ['settled', 'rejected']) {
            const { page } = await mount(browser, { lists: false, defer: ['shares'], open: null });

            // Both pending: A first, then B.
            await page.evaluate(() => window.__openShareModal({ targetType: 'directory', targetId: 7 }));
            await page.evaluate(() => window.__openShareModal({ targetType: 'asset', targetId: 31 }));

            const before = await page.evaluate(() => window.__emitted.length);

            // Only A's list answers. Its finally() must not end B's loading state, nor its failure show over B.
            outcome === 'settled'
                ? await page.evaluate((link) => window.__settle(0, { shares: [link] }), folderLink)
                : await page.evaluate(() => window.__fail(0, 'Failed to load existing share links.'));
            await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

            // Still loading, so there is no Generate button to press while B's own list is on its way.
            assert.equal(await page.locator('[data-dam-share-loading]').count(), 1, `${outcome}: A's list ended B's loading state`);
            assert.equal(await page.locator('[data-dam-share-create]').count(), 0, outcome);
            assert.deepEqual(await shownLinks(page), [], outcome);
            assert.deepEqual(await page.evaluate((from) => window.__emitted.slice(from), before), [], `${outcome}: A's list raised a toast over B`);

            // B's own answer then renders B.
            await page.evaluate((link) => window.__settle(1, { shares: [link] }), assetLink);
            await page.locator('[data-dam-share-url]').waitFor();
            assert.deepEqual(await shownLinks(page), [`active ${assetLink.public_url}`], outcome);
            assert.equal(await page.locator('[data-dam-share-loading]').count(), 0, outcome);

            await page.close();
        }
    } finally {
        await browser.close();
    }
});

/**
 * Each public-link write, set up from the link state that offers it: what both items' lists hold, whether its
 * whether it needs the opt-in, that button, what the endpoint answers when the write succeeds, and the links
 * the item then shows.
 */
const linkWrites = {
    create: {
        shares: () => [],
        button: '[data-dam-share-create]',
        confirm: true,
        answer: (link) => ({ success: true, share: link }),
        after: (link) => [link],
    },
    save: {
        shares: (link) => [link],
        button: '[data-dam-share-save]',
        answer: (link) => ({ success: true, share: { ...link, name: 'Renamed' }, message: 'Share link updated.' }),
        after: (link) => [link],
    },
    reauthorize: {
        shares: (link) => [{ ...link, status: 'revoked' }],
        button: '[data-dam-share-reauthorize]',
        confirm: true,
        answer: (link) => ({ success: true, share: link, message: 'Share link reauthorized.' }),
        after: (link) => [link],
    },
    revoke: {
        shares: (link) => [link],
        button: '[data-dam-share-revoke]',
        answer: () => ({ success: true, message: 'Share link revoked.' }),
        // A revoke answers without the link, so the dialog reads its item's list back instead.
        after: (link) => [{ ...link, status: 'revoked' }],
        readsBack: true,
    },
};

/** Opens an item's dialog, answers its parked list (at $index) with $shares, and returns $write's button. */
async function openForWrite(page, open, index, shares, write) {
    await page.evaluate((payload) => window.__openShareModal(payload), open);
    await page.evaluate(([at, data]) => window.__settle(at, data), [index, { shares }]);

    // Creating or re-enabling a public link is behind the section's opt-in; the section itself opens at once
    // when a link already exists and on its heading otherwise.
    if (write.confirm) {
        if (await page.locator('[data-dam-public-body]').count() === 0) {
            await page.locator('[data-dam-public-toggle]').click();
        }
        await page.locator('[data-dam-public-confirm]').check();
    }

    const button = page.locator(write.button);
    await button.waitFor();

    return button;
}

browserTest('a link write that answers after another dialog opens leaves that dialog alone', async () => {
    const browser = await launch();

    try {
        for (const [name, write] of Object.entries(linkWrites)) {
            for (const outcome of ['settled', 'rejected']) {
                const label = `${name}, ${outcome}`;
                const { page } = await mount(browser, { lists: false, defer: ['shares', 'link-write'], open: null });

                // A sends the write, and its answer is parked.
                await (await openForWrite(page, { targetType: 'directory', targetId: 7 }, 0, write.shares(folderLink), write)).click();
                assert.deepEqual(await page.evaluate(() => window.__parked()), ['shares', 'link-write'], label);

                // B opens on the same kind of link. A's write in flight does not hold B's button, so B sends its own.
                const button = await openForWrite(page, { targetType: 'asset', targetId: 31 }, 2, write.shares(assetLink), write);
                assert.equal(await button.isDisabled(), false, `${label}: A's write in flight disabled B's button`);
                await button.click();
                assert.equal(await button.isDisabled(), true, `${label}: B's own write should disable its button`);

                const shown = await shownLinks(page);
                assert.deepEqual(shown, write.shares(assetLink).map((link) => `${link.status} ${link.public_url}`), label);
                const before = await page.evaluate(() => window.__emitted.length);

                // A's write answers, late.
                outcome === 'settled'
                    ? await page.evaluate(([at, data]) => window.__settle(at, data), [1, write.answer(folderLink)])
                    : await page.evaluate(() => window.__fail(1, 'Failed to update share link.'));
                await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

                // None of it reaches B: not A's link, not a toast, not a reload of B's list, and not the end of
                // B's own write, which is still in flight.
                assert.deepEqual(await shownLinks(page), shown, `${label}: A's answer changed the link B shows`);
                assert.deepEqual(await page.evaluate((from) => window.__emitted.slice(from), before), [], `${label}: A's answer raised a toast over B`);
                assert.deepEqual(await page.evaluate(() => window.__parked()), ['shares', 'link-write', 'shares', 'link-write'], `${label}: A's answer reloaded B's list`);
                assert.equal(await button.isDisabled(), true, `${label}: A's answer ended B's own write`);

                // Then B's own write answers the same way, and all of that is B's: its link, its toast, its button.
                const mark = await page.evaluate(() => window.__emitted.length);

                if (outcome === 'settled') {
                    await page.evaluate(([at, data]) => window.__settle(at, data), [3, write.answer(assetLink)]);

                    if (write.readsBack) {
                        await page.waitForFunction(() => window.__parked().length === 5);
                        assert.equal(await page.evaluate(() => window.__parked()[4]), 'shares', label);
                        await page.evaluate(([at, data]) => window.__settle(at, data), [4, { shares: write.after(assetLink) }]);
                    }
                } else {
                    await page.evaluate(() => window.__fail(3, 'Failed to update share link.'));
                }
                await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

                const expected = outcome === 'settled'
                    ? write.after(assetLink).map((link) => `${link.status} ${link.public_url}`)
                    : shown;
                assert.deepEqual(await shownLinks(page), expected, `${label}: B's own answer`);
                assert.deepEqual(
                    await page.evaluate((from) => window.__emitted.slice(from).map((entry) => `${entry.event} ${entry.payload?.type}`), mark),
                    [`add-flash ${outcome === 'settled' ? 'success' : 'error'}`],
                    `${label}: B's own answer should raise its own toast`
                );

                // A failed write leaves its button where it was, and free again.
                if (outcome === 'rejected') {
                    assert.equal(await button.isDisabled(), false, `${label}: B's own answer should release its button`);
                }

                await page.close();
            }
        }
    } finally {
        await browser.close();
    }
});

browserTest('a save reports the item it was for, even once another dialog has been opened', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, { describe: assetDescribe, open: { targetType: 'asset', targetId: 31 } });

        await page.locator('[data-dam-access-save]').waitFor();
        await page.locator('[data-dam-access-save]').click();
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

        // The listing is told which item changed and how, so the explorer can read its indicators again.
        const updates = await page.evaluate(() => window.__emitted.filter((entry) => entry.event === 'dam:access-updated'));
        assert.deepEqual(updates.map((entry) => entry.payload), [{ targetType: 'asset', targetId: 31, mode: 'everyone' }]);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a hostile name or email is shown as text, never as markup', async () => {
    const browser = await launch();
    const hostileName = '<img src=x onerror="window.__pwned = true">';
    const hostileGroup = '"><script>window.__pwnedGroup = true;</script>';

    try {
        const { page } = await mount(browser, {
            describe: {
                ...assetDescribe,
                mode: 'people',
                users: [{ id: 9, name: hostileName, email: '"><script>window.__pwnedEmail = true;</script>' }],
                groups: [{ id: 5, name: hostileGroup, member_count: 1 }],
            },
            open: { targetType: 'asset', targetId: 31 },
        });

        await page.locator('[data-dam-access-picker]').waitFor();

        // The chips carry the raw strings as text, character for character.
        assert.deepEqual(
            await page.locator('[data-dam-access-chip]').evaluateAll((nodes) => nodes.map((node) => node.textContent.trim())),
            [hostileGroup, hostileName]
        );

        // Nothing was parsed as markup, and nothing ran.
        assert.equal(await page.locator('[data-dam-access-section] img, [data-dam-access-section] script').count(), 0);
        assert.deepEqual(
            await page.evaluate(() => [window.__pwned, window.__pwnedGroup, window.__pwnedEmail]),
            [undefined, undefined, undefined]
        );

        // The remove control's aria-label interpolates the same name and is still an attribute, not markup.
        const label = await page.locator('[data-dam-access-chip-remove="user-9"]').getAttribute('aria-label');
        assert.ok(label.includes(hostileName), `the remove label lost the name: ${label}`);

        await page.close();
    } finally {
        await browser.close();
    }
});

const tabFile = 'packages/Mothership/DAM/src/Resources/views/components/explorer/tab.blade.php';

/** Keywords a regex literal may follow, where the character before the slash is an ordinary letter. */
const REGEX_KEYWORDS = new Set(['return', 'throw', 'case', 'typeof', 'delete', 'void', 'in', 'of', 'new', 'instanceof', 'yield', 'await']);

/**
 * Index of the `}` that closes the `{` at $open, counting only braces that are code. When $comments is
 * given, every comment span the walk passes is pushed onto it as [from, to).
 *
 * tab.blade.php already carries every hazard a byte counter gets wrong: a line comment reading "a cascade's
 * descendants", a block comment quoting `dam_explorer_active_dir` in backticks, template literals with
 * nested ${} expressions, and Blade `{{ route(...) }}` inside JavaScript strings. Counting raw bytes could
 * stop early or swallow the rest of the file, breaking this test on an unrelated edit (Codex re-review of
 * PR #69, round 3 finding 3).
 *
 * Each frame is one nesting level: ordinary code, or the inside of a template literal. A `${` opens a fresh
 * code frame, and the `}` that takes that frame below zero closes it again.
 */
function matchingBrace(text, open, label, comments = null) {
    assert.equal(text[open], '{', `${label}: expected a { at offset ${open}`);

    const frames = [{ template: false, depth: 0 }];
    let index = open;
    // The last significant code character, and the whole word when that character ended one: `return /}/`
    // is a regex, so the character alone is not enough to tell one from a division (round 4 finding 2).
    let previous = '';
    let previousWord = '';

    const startsRegex = () => previous === ''
        || REGEX_KEYWORDS.has(previousWord)
        || ! /[\w$)\]'"`]/.test(previous);

    while (index < text.length) {
        const frame = frames[frames.length - 1];
        const char = text[index];

        if (frame.template) {
            if (char === '\\') { index += 2; continue; }
            if (char === '`') { frames.pop(); index += 1; continue; }
            if (char === '$' && text[index + 1] === '{') { frames.push({ template: false, depth: 0 }); index += 2; continue; }
            index += 1;
            continue;
        }

        if (text.startsWith('//', index)) {
            const newline = text.indexOf('\n', index);
            assert.notEqual(newline, -1, `${label}: a // comment runs to the end of the file`);
            comments?.push([index, newline + 1]);
            index = newline + 1;
            continue;
        }

        if (text.startsWith('/*', index)) {
            const close = text.indexOf('*/', index + 2);
            assert.notEqual(close, -1, `${label}: an unterminated /* comment`);
            comments?.push([index, close + 2]);
            index = close + 2;
            continue;
        }

        if (char === '"' || char === "'") { index = skipQuoted(text, index, label); previous = char; previousWord = ''; continue; }
        if (char === '`') { frames.push({ template: true, depth: 0 }); index += 1; previous = '`'; previousWord = ''; continue; }
        if (char === '/' && startsRegex()) { index = skipRegex(text, index, label); previous = '/'; previousWord = ''; continue; }

        // Identifiers and keywords are taken whole, so `increment / 2` stays a division while `return /}/`
        // does not: only a complete word can be a keyword.
        if (/[A-Za-z_$]/.test(char)) {
            let end = index;
            while (end < text.length && /[\w$]/.test(text[end])) end += 1;
            previousWord = text.slice(index, end);
            previous = text[end - 1];
            index = end;
            continue;
        }

        if (char === '{') { frame.depth += 1; previous = '{'; previousWord = ''; index += 1; continue; }

        if (char === '}') {
            frame.depth -= 1;

            if (frames.length === 1 && frame.depth === 0) return index;
            if (frames.length > 1 && frame.depth < 0) { frames.pop(); index += 1; continue; }

            previous = '}';
            previousWord = '';
            index += 1;
            continue;
        }

        if (! /\s/.test(char)) { previous = char; previousWord = ''; }
        index += 1;
    }

    return assert.fail(`${label}: the block opening at offset ${open} is never closed. The brace scanner in this test may need updating for new syntax in tab.blade.php.`);
}

/** Index just past the closing quote of the string starting at $start. */
function skipQuoted(text, start, label) {
    const quote = text[start];

    for (let index = start + 1; index < text.length; index++) {
        if (text[index] === '\\') { index += 1; continue; }
        if (text[index] === quote) return index + 1;
        // An unescaped newline cannot appear in a '' or "" string, so this is a mis-parse, not a long string.
        if (text[index] === '\n') break;
    }

    return assert.fail(`${label}: an unterminated ${quote} string near offset ${start}`);
}

/** Index just past a regex literal's closing slash and flags. */
function skipRegex(text, start, label) {
    let inClass = false;

    for (let index = start + 1; index < text.length; index++) {
        const char = text[index];

        if (char === '\\') { index += 1; continue; }
        if (char === '[') { inClass = true; continue; }
        if (char === ']') { inClass = false; continue; }
        if (char === '\n') break;

        if (char === '/' && ! inClass) {
            let end = index + 1;
            while (/[a-z]/i.test(text[end] ?? '')) end += 1;

            return end;
        }
    }

    return assert.fail(`${label}: an unterminated regex literal near offset ${start}`);
}

/**
 * The `{ ... }` block that follows $header, braces counted by matchingBrace().
 *
 * `block` is the shipped text. `code` is the same block with each comment replaced by a newline, so a
 * pattern cannot match a statement production has commented out — which would otherwise pass vacuously,
 * the fixture running as live code a line the browser never would (round 4 finding 1). The spans come from
 * matchingBrace() itself, so the two views cannot disagree about what a comment is.
 */
function bracedBlock(text, header, label) {
    const at = text.indexOf(header);
    assert.notEqual(at, -1, `${label}: tab.blade.php no longer contains ${JSON.stringify(header.trim())}`);

    const open = text.indexOf('{', at);
    const comments = [];
    const close = matchingBrace(text, open, label, comments);

    let code = '';
    let cursor = open;

    for (const [from, to] of comments) {
        // A newline, not nothing: blanking a line comment outright would join the tokens around it.
        code += `${text.slice(cursor, from)}\n`;
        cursor = to;
    }

    return { block: text.slice(open, close + 1), code: code + text.slice(cursor, close + 1) };
}

/*
 * The scanner's own cases. Each source's outer block opens at 0 and closes on its last character, so a
 * scanner that mistook a regex for a division — or a comment, string or template literal for code — would
 * return the wrong offset (round 4 finding 2).
 */
test('the brace scanner tells regex literals from division, and code from the rest', () => {
    const cases = {
        'a regex after return':        '{ return /}/; }',
        'a regex after throw':         '{ throw /}/; }',
        'a regex after case':          '{ switch (x) { case /}/.source: break; } }',
        'a regex after typeof':        '{ typeof /}/; }',
        'a regex after an operator':   '{ const a = b || /}/.test(c); }',
        'a regex with a class':        '{ return /[/}]+/g.source; }',
        'division by an identifier':   '{ const a = b / c; }',
        'division by a group':         '{ const a = (b + c) / 2; }',
        'division by a call':          '{ const a = f(b) / 2; }',
        'division after a word ending in a keyword': '{ const increment = 2; const a = increment / 2; }',
        'a comment holding a brace':   '{ // a } in prose\n const a = 1; }',
        'a block comment holding one': '{ /* } and a ` backtick */ const a = 1; }',
        "an apostrophe in a comment":  "{ // a cascade's descendants }\n const a = 1; }",
        'a string holding a brace':    '{ const a = "}"; }',
        'a template with an expression': '{ const a = `x${ { y: 1 } }z`; }',
        'a nested block':              '{ if (a) { b(); } }',
    };

    for (const [label, source] of Object.entries(cases)) {
        assert.equal(matchingBrace(source, 0, label), source.length - 1, `${label}: ${JSON.stringify(source)}`);
    }
});

test('the scanner reports an unclosed block rather than running off the end', () => {
    assert.throws(
        () => matchingBrace('{ const a = 1;', 0, 'unclosed'),
        /is never closed/,
        'an unclosed block must name itself'
    );
});

test('a commented-out statement is absent from a block\'s code view', () => {
    const source = "wrapper() {\n    live();\n    // dead();\n}";
    const { block, code } = bracedBlock(source, 'wrapper() {', 'wrapper()');

    assert.match(block, /dead\(\);/, 'the shipped text keeps its comments');
    assert.doesNotMatch(code, /dead\(\);/, 'the code view must drop a commented-out statement');
    assert.match(code, /live\(\);/, 'the code view must keep live code');
});

/**
 * The explorer tab's own fetch(), and the one line of its mounted() that binds fetch() to
 * dam:access-updated. Both are the shipped source: if either moves or goes, the extraction fails here
 * rather than the fixture quietly testing itself.
 *
 * The rest of the tab (~1,600 lines of selection, drag, tabs, filters and child components) is not mounted;
 * only the four collaborators fetch() calls are stubbed, and none of them touches the listing it assigns.
 */
function explorerFetch() {
    const tab = source(tabFile);

    // Scoped to mounted(): a dead copy in a comment, in another method or in a teardown hook would satisfy a
    // whole-file search while the explorer no longer binds anything when it mounts (finding 2).
    const mounted = bracedBlock(tab, '\n    mounted() {', 'mounted()');
    const listener = mounted.code.match(/this\.\$emitter\.on\('dam:access-updated'[^\n]*\);/);
    assert.ok(
        listener,
        'tab.blade.php must bind dam:access-updated in live code inside mounted(); a binding that is commented out, or in any other hook, never runs when the explorer mounts'
    );

    const body = blade(bracedBlock(tab, '\n        fetch() {', 'fetch()').block, { lists: true });

    // Loud validation of the slice itself: a mis-parse that happened to balance would otherwise surface as a
    // puzzling browser error instead of naming the extractor (finding 3).
    try {
        new Function(`return function fetch() ${body}`);
    } catch (error) {
        assert.fail(`fetch(): the extracted body does not compile — ${error.message}. The brace scanner in this test may need updating for new syntax in tab.blade.php.`);
    }

    // And of its substance: a truncation that still compiles must not pass for the real method.
    assert.match(body, /this\.dirs\s*=\s*data\.directories/, 'fetch(): the extracted body no longer assigns the listing it fetched');
    assert.match(body, /admin\.dam\.explorer\.index/, 'fetch(): the extracted body no longer calls the listing endpoint');

    return { body, listener: listener[0] };
}

const listing = (mode) => ({
    directories: [
        { id: 8, name: 'Season one', access_mode: mode },
        { id: 9, name: 'Stills', access_mode: mode },
    ],
    assets: [{ id: 41, file_name: 'poster.png', access_mode: mode }],
    meta: { directory_id: 7 },
    breadcrumb: [{ id: 7, name: 'Campus photos' }],
});

browserTest('a sharing save refreshes the listing, and the cascaded rows change with it', async () => {
    // Extracted before the browser starts: an extraction failure must fail the test, not leak a Chromium
    // that keeps the Node runner alive.
    const { body, listener } = explorerFetch();
    const browser = await launch();

    try {
        const page = await browser.newPage();
        const requests = [];
        await page.exposeFunction('reportListing', (url, params) => requests.push({ url, params: JSON.parse(params) }));
        await page.route('http://explorer.test/**', (route) => route.fulfill({ contentType: 'text/html', body: '<div id="app"></div>' }));
        await page.goto('http://explorer.test/library');
        await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

        await page.evaluate(({ body, listener, indicator, first }) => {
            const listeners = {};
            const emitter = {
                on(event, handler) { (listeners[event] ??= []).push(handler); },
                off() {},
                emit(event, payload) { (listeners[event] ?? []).forEach((handler) => handler(payload)); },
            };

            // Whatever the next listing request should answer with; the test swaps it between fetches.
            window.__listing = first;

            const app = Vue.createApp({
                emits: ['tab-label-change'],
                data: () => ({
                    currentDirId: 7, scope: 'directory', loading: false,
                    dirs: [], assets: [], meta: {}, breadcrumb: [], navHistory: [], navIdx: -1,
                    applied: { filters: { columns: [] } },
                    page: 1, perPage: 50, sortBy: 'created_at', sortOrder: 'desc',
                    search: '', mediaType: 'all', includeSubfolders: false,
                    _fetchRequestId: 0, _recoveringToRoot: false,
                }),
                methods: {
                    // The shipped body, verbatim.
                    fetch: new Function('return function fetch() ' + body)(),
                    // fetch() calls these four and none of them touches dirs/assets/meta.
                    restoreSelection() {}, computeSelectionMode() {}, sync() {}, loadRoot() {},
                },
                mounted() {
                    new Function('eval(arguments[0])').call(this, listener);
                },
                template: `
                    <div>
                        <div v-for="dir in dirs" :key="'d' + dir.id" :data-row-dir="dir.id">
                            {{ dir.name }}${indicator.dir}
                        </div>
                        <div v-for="asset in assets" :key="'a' + asset.id" :data-row-asset="asset.id">
                            {{ asset.file_name }}${indicator.asset}
                        </div>
                    </div>`,
            });

            app.config.globalProperties.$emitter = emitter;
            app.config.globalProperties.$axios = {
                get: (url, config) => {
                    window.reportListing(url, JSON.stringify(config?.params ?? {}));

                    return Promise.resolve({ data: window.__listing });
                },
            };

            window.__vm = app.mount('#app');
            window.__emitAccessUpdated = (payload) => emitter.emit('dam:access-updated', payload);
        }, { body, listener, indicator: { dir: accessIndicator('dir.access_mode'), asset: accessIndicator('asset.access_mode') }, first: listing('private') });

        // The page as it stands before anyone shares anything.
        await page.evaluate(() => window.__vm.fetch());
        await page.locator('[data-row-dir="8"] [data-dam-access-indicator]').waitFor();

        const modeOf = (selector) => page.locator(`${selector} [data-dam-access-indicator]`).getAttribute('data-dam-access-indicator');

        assert.equal(await modeOf('[data-row-dir="8"]'), 'private');
        assert.equal(await modeOf('[data-row-dir="9"]'), 'private');
        assert.equal(await modeOf('[data-row-asset="41"]'), 'private');
        assert.equal(requests.length, 1);

        // The owner sets the parent folder to Everyone. The save's event is all the explorer hears; the
        // cascaded descendants are only in the listing it goes back for.
        await page.evaluate((next) => { window.__listing = next; }, listing('everyone'));
        await page.evaluate(() => window.__emitAccessUpdated({ targetType: 'directory', targetId: 7, mode: 'everyone' }));

        await page.locator('[data-row-dir="8"] [data-dam-access-indicator="everyone"]').waitFor();

        // Both descendant folders and the file inside them now read as shared, from one refresh.
        assert.equal(await modeOf('[data-row-dir="8"]'), 'everyone');
        assert.equal(await modeOf('[data-row-dir="9"]'), 'everyone');
        assert.equal(await modeOf('[data-row-asset="41"]'), 'everyone');

        // It really went back to the listing endpoint, for this folder.
        assert.equal(requests.length, 2);
        assert.match(requests[1].url, /admin\.dam\.explorer\.index/);
        assert.equal(requests[1].params.directory_id, 7);
        assert.equal(requests[1].params.scope, 'directory');

        // And a listing that drops access_mode — the `grants` model — clears the indicators outright.
        await page.evaluate(() => {
            window.__listing = {
                directories: [{ id: 8, name: 'Season one' }, { id: 9, name: 'Stills' }],
                assets: [{ id: 41, file_name: 'poster.png' }],
                meta: { directory_id: 7 }, breadcrumb: [],
            };
            window.__emitAccessUpdated({ targetType: 'directory', targetId: 7, mode: 'private' });
        });
        await page.waitForFunction(() => document.querySelectorAll('[data-dam-access-indicator]').length === 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

/**
 * The shipped indicator partial, with its one Blade variable resolved to a JavaScript expression exactly as
 * the three call sites pass it (`@include(..., ['expression' => 'asset.access_mode'])`).
 */
function accessIndicator(expression) {
    return source('packages/Mothership/DAM/src/Resources/views/components/explorer/access-indicator.blade.php')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@php\([^\n]*\)\s*/g, '')
        .replace(/\{\{\s*\$mode\s*\}\}/g, expression)
        .replace(/@lang\('dam::app\.admin\.dam\.access\.([a-z0-9-]+)'\)/g, (_, key) => strings[key] ?? key);
}

browserTest('the sharing indicator follows the access_mode a refreshed listing brings back', async () => {
    const browser = await launch();

    try {
        const page = await browser.newPage();
        await page.route('http://indicator.test/**', (route) => route.fulfill({
            contentType: 'text/html',
            body: '<div id="app"><span id="cell"></span></div>',
        }));
        await page.goto('http://indicator.test/library');
        await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

        await page.evaluate((template) => {
            const app = Vue.createApp({
                data: () => ({ asset: { id: 41, access_mode: 'private' } }),
                template: `<span id="cell">${template}</span>`,
            });
            window.__vm = app.mount('#app');
        }, accessIndicator('asset.access_mode'));

        const shown = () => page.locator('[data-dam-access-indicator]');

        // A private item: the lock, labelled for a screen reader.
        assert.equal(await shown().getAttribute('data-dam-access-indicator'), 'private');
        assert.equal(await shown().getAttribute('aria-label'), strings['indicator-private']);

        // The save reported everyone, the listing came back with it, and the cell now reads as the globe.
        await page.evaluate(() => { window.__vm.asset.access_mode = 'everyone'; });
        assert.equal(await shown().getAttribute('data-dam-access-indicator'), 'everyone');
        assert.equal(await shown().getAttribute('aria-label'), strings['indicator-everyone']);

        await page.evaluate(() => { window.__vm.asset.access_mode = 'people'; });
        assert.equal(await shown().getAttribute('data-dam-access-indicator'), 'people');
        assert.equal(await shown().getAttribute('aria-label'), strings['indicator-people']);

        // Under `grants` the listing sends no access_mode at all, and the cell stays empty.
        await page.evaluate(() => { delete window.__vm.asset.access_mode; });
        assert.equal(await shown().count(), 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

// --- The one clear dialog: answer first, an obvious internal Copy link, and a public link that is opt-in. ---

const writes = (requests) => requests.filter((request) => ['post', 'put', 'patch', 'delete'].includes(request.method));

/** Records what Copy hands the clipboard, so a test can read the address that would have been copied. */
const captureClipboard = (page) => page.evaluate(() => {
    window.__copied = [];
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: (text) => { window.__copied.push(text); return Promise.resolve(); } } });
});

browserTest('opening the dialog and copying the internal link write nothing', async () => {
    const browser = await launch();

    try {
        for (const [open, describe, expected] of [
            [{ targetType: 'directory', targetId: 7 }, folderDescribe, '/library?directory_id=7'],
            [{ targetType: 'asset', targetId: 31 }, assetDescribe, '/assets/open/31'],
        ]) {
            const { page, requests } = await mount(browser, { open, describe });
            await page.locator('[data-dam-copy-link]').waitFor();
            await captureClipboard(page);

            await page.locator('[data-dam-copy-link]').click();
            await page.waitForFunction(() => window.__copied.length === 1);

            assert.equal(await page.evaluate(() => window.__copied[0]), expected);
            assert.deepEqual(writes(requests), [], `${open.targetType}: opening and copying must not write`);
            assert.match(
                (await page.locator('[data-dam-copy-link-block]').textContent()),
                new RegExp(strings['copy-link-hint'].replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
            );

            await page.close();
        }
    } finally {
        await browser.close();
    }
});

browserTest('the title truncates and the summary answers who can open it, with the owner named', async () => {
    const browser = await launch();

    try {
        const cases = [
            [{ ...folderDescribe, mode: 'private' }, strings['summary-private']],
            [{ ...folderDescribe, mode: 'everyone' }, strings['summary-everyone']],
            [{ ...folderDescribe, mode: 'people', users: [{ id: 9, name: 'Sam Friend', email: 's@x.test' }], groups: [{ id: 3, name: 'Editors (from role)', member_count: 4 }] }, strings['summary-people'].split('|')[1].replace(':count', '2')],
        ];

        for (const [describe, sentence] of cases) {
            const { page } = await mount(browser, { describe: { ...describe, owned_by_viewer: false, owner } });
            await page.locator('[data-dam-access-summary]').waitFor();

            assert.equal((await page.locator('[data-dam-access-summary]').textContent()).trim(), sentence);
            assert.equal((await page.locator('[data-dam-access-admins]').textContent()).trim(), strings['summary-admins']);
            assert.equal((await page.locator('[data-dam-access-owner]').textContent()).trim(), 'Owner: Dana Owner');

            const title = page.locator('[data-dam-share-title]');
            assert.match(await title.getAttribute('class'), /min-w-0 flex-1 truncate/);
            assert.equal(await title.getAttribute('title'), 'Campus photos');

            await page.close();
        }

        // A generated role group reads as the audience it is, in the chips as well.
        const { page } = await mount(browser, {
            describe: { ...folderDescribe, mode: 'people', users: [], groups: [{ id: 3, name: 'Editors (from role)', system_key: 'role:5', member_count: 4 }, { id: 4, name: 'Legacy (from role)', system_key: null, member_count: 1 }] },
        });
        await page.locator('[data-dam-access-chip]').first().waitFor();
        assert.deepEqual(
            await page.locator('[data-dam-access-chip]').evaluateAll((nodes) => nodes.map((node) => node.textContent.trim())),
            [strings['role-group'].replace(':role', 'Editors'), 'Legacy (from role)']
        );
        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('Create public link waits for the opt-in and sends it with a 30-day expiry', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser);
        await page.locator('[data-dam-public-toggle]').waitFor();

        // Closed until asked for.
        assert.equal(await page.locator('[data-dam-public-body]').count(), 0);
        await page.locator('[data-dam-public-toggle]').click();

        const create = page.locator('[data-dam-share-create]');
        assert.equal(await create.isDisabled(), true);
        await page.locator('[data-dam-public-confirm]').check();
        assert.equal(await create.isDisabled(), false);

        await create.click();
        await page.waitForFunction(() => window.__pending !== undefined);

        const post = requests.find((request) => request.method === 'post' && request.url.includes('shared-links'));
        assert.ok(post, 'Create must POST to the shared-links store');
        const body = JSON.parse(post.body);
        assert.equal(body.public_link_confirmed, true);
        assert.equal('no_expiry' in body, false);
        assert.equal(body.expiry_days, 30);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('Only me with live public links warns, and Revoke them asks first and never fires on its own', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, {
            describe: {
                ...folderDescribe,
                mode: 'private',
                public_links: { direct: 2, inherited: [{ folder_id: 3, folder_name: 'Archive', count: 1 }, { folder_id: 4, folder_name: null, count: 1 }] },
            },
        });

        const warning = page.locator('[data-dam-public-warning]');
        await warning.waitFor();
        assert.match(await warning.textContent(), /This is set to Only me, but 4 public links still let anyone open it\./);
        assert.deepEqual(
            await page.locator('[data-dam-public-inherited]').evaluateAll((nodes) => nodes.map((node) => node.textContent.trim())),
            ['Shared through public link on folder Archive', 'Shared through public link on a folder you cannot open']
        );
        assert.deepEqual(writes(requests), [], 'the warning must not revoke anything by itself');

        // The first press only asks; declining writes nothing.
        await page.locator('[data-dam-public-revoke-all]').click();
        await page.locator('[data-dam-public-revoke-confirm]').waitFor();
        await page.locator('[data-dam-public-revoke-no]').click();
        assert.deepEqual(writes(requests), []);

        await page.locator('[data-dam-public-revoke-all]').click();
        await page.locator('[data-dam-public-revoke-yes]').click();
        await page.waitForFunction(() => document.querySelector('[data-dam-public-warning]') === null);

        const revoke = writes(requests);
        assert.equal(revoke.length, 1);
        assert.match(revoke[0].url, /access\.revoke_public_links\?type=directory&id=7$/);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('no warning for a shared item, and a viewer who cannot use the link sees only that it exists', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, {
            describe: { ...folderDescribe, mode: 'everyone', public_links: { direct: 1, inherited: [] } },
            shares: [{ ...shareLink(601), public_url: null, token: null, created_by: 4 }],
        });
        await page.locator('[data-dam-public-exists]').waitFor();

        assert.equal(await page.locator('[data-dam-public-warning]').count(), 0);
        assert.equal((await page.locator('[data-dam-public-exists]').textContent()).trim(), strings['public-exists'].replace(':owner', 'Dana Owner'));
        assert.equal(await page.locator('[data-dam-share-url]').count(), 0);
        assert.equal(await page.locator('[data-dam-share-copy-public]').count(), 0);
        assert.equal(await page.locator('[data-dam-share-revoke]').count(), 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a visible public link has its own Copy public link and re-enabling needs the opt-in', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, { shares: [shareLink(701)] });
        await page.locator('[data-dam-share-copy-public]').waitFor();
        await captureClipboard(page);
        await page.locator('[data-dam-share-copy-public]').click();
        await page.waitForFunction(() => window.__copied.length === 1);
        assert.equal(await page.evaluate(() => window.__copied[0]), 'https://share.test/s/token-701');
        assert.deepEqual(writes(requests), []);
        await page.close();

        const revoked = await mount(browser, { shares: [shareLink(702, 'revoked')] });
        const reauthorize = revoked.page.locator('[data-dam-share-reauthorize]');
        await reauthorize.waitFor();
        assert.equal(await reauthorize.isDisabled(), true);
        await revoked.page.locator('[data-dam-public-confirm]').check();
        await reauthorize.click();
        await revoked.page.waitForFunction(() => window.__pending !== undefined);
        const patch = revoked.requests.find((request) => request.method === 'patch');
        const body = JSON.parse(patch.body);
        assert.equal(body.public_link_confirmed, true);
        assert.equal(body.expiry_days, 30, 'the expiry is sent explicitly');
        await revoked.page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a visible link the viewer may not change offers Copy only: no Save, Reissue, Revoke or Re-enable', async () => {
    const browser = await launch();

    try {
        // can_manage false: another person's link on a target this viewer can see (R14). The server refuses every
        // write anyway; the dialog must not offer one.
        const active = await mount(browser, { shares: [{ ...shareLink(711), can_manage: false, can_delete: false }] });
        await active.page.locator('[data-dam-share-copy-public]').waitFor();
        for (const control of ['[data-dam-share-save]', '[data-dam-share-reissue]', '[data-dam-share-revoke]', '[data-dam-share-reauthorize]']) {
            assert.equal(await active.page.locator(control).count(), 0, `${control} offered on a link the viewer may not change`);
        }
        assert.deepEqual(writes(active.requests), []);
        await active.page.close();

        const revoked = await mount(browser, { shares: [{ ...shareLink(712, 'revoked'), can_manage: false, can_delete: false }] });
        await revoked.page.locator('[data-dam-share-url]').first().waitFor();
        for (const control of ['[data-dam-share-save]', '[data-dam-share-reauthorize]']) {
            assert.equal(await revoked.page.locator(control).count(), 0, `${control} offered on a revoked link the viewer may not change`);
        }
        await revoked.page.close();
    } finally {
        await browser.close();
    }
});

browserTest('saving an existing link by name alone leaves its expiry untouched', async () => {
    const browser = await launch();

    try {
        // 200 days out matches no select option, so a rewrite would visibly change it.
        const link = { ...shareLink(801), expires_at: new Date(Date.now() + 200 * 86_400_000).toISOString() };
        const { page, requests } = await mount(browser, { shares: [link] });
        await page.locator('[data-dam-share-save]').waitFor();
        await page.locator('[data-dam-public-body] input[type=text]:not([readonly]):not([data-dam-share-slug])').fill('Renamed');
        await page.locator('[data-dam-share-save]').click();
        await page.waitForFunction(() => window.__pending !== undefined);

        const patch = requests.find((request) => request.method === 'patch');
        assert.deepEqual(JSON.parse(patch.body), { name: 'Renamed' });
        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('an expired link whose URL is hidden offers the normal create flow, not a dead end', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, {
            shares: [{ ...shareLink(901, 'expired'), public_url: null, token: null, created_by: 4 }],
        });
        await page.locator('[data-dam-share-create]').waitFor();
        assert.equal(await page.locator('[data-dam-public-exists]').count(), 0);
        assert.equal(await page.locator('[data-dam-share-create]').isDisabled(), true);
        await page.locator('[data-dam-public-confirm]').check();
        await page.locator('[data-dam-share-create]').click();
        await page.waitForFunction(() => window.__pending !== undefined);
        assert.equal(JSON.parse(requests.find((r) => r.method === 'post' && r.url.includes('shared-links')).body).public_link_confirmed, true);
        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('changing an expired link to a later expiry, or to no expiry, needs the opt-in', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, { shares: [{ ...shareLink(902, 'expired'), expires_at: new Date(Date.now() - 86_400_000).toISOString() }] });
        await page.locator('[data-dam-share-save]').waitFor();
        // A name-only save of an expired link needs nothing extra.
        assert.equal(await page.locator('[data-dam-share-save]').isDisabled(), false);


        // Picking no expiry (or a later date on an inactive link) is gated by the section's opt-in.
        await page.evaluate(() => window.__pickExpiry(''));
        assert.equal(await page.locator('[data-dam-share-save]').isDisabled(), true);
        await page.locator('[data-dam-public-confirm]').check();
        assert.equal(await page.locator('[data-dam-share-save]').isDisabled(), false);
        await page.locator('[data-dam-share-save]').click();
        await page.waitForFunction(() => window.__pending !== undefined);
        const body = JSON.parse(requests.find((request) => request.method === 'patch').body);
        assert.equal(body.public_link_confirmed, true);
        assert.equal(body.no_expiry, 1);
        await page.close();
    } finally {
        await browser.close();
    }
});

// P2 S5b: the link options (password, download-off, creator access). The server side (hashing, never returning the
// password, who may change them) is covered by packages/Mothership/DAM/tests/Feature/ShareAccessOptionsTest.php.
browserTest('a new link sends its password and options, and Create waits for a password once one is asked for', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser);
        await page.locator('[data-dam-public-toggle]').click();
        await page.locator('[data-dam-public-confirm]').check();

        const create = page.locator('[data-dam-share-create]');
        // Defaults: no password, downloads on, creator access off.
        assert.equal(await page.locator('[data-dam-share-password-toggle]').isChecked(), false);
        assert.equal(await page.locator('[data-dam-share-allow-download]').isChecked(), true);
        assert.equal(await page.locator('[data-dam-share-creator-access]').isChecked(), false);
        assert.equal(await create.isDisabled(), false);

        await page.locator('[data-dam-share-password-toggle]').check();
        assert.equal(await create.isDisabled(), true, 'a password box ticked but empty must not create an open link');
        await page.locator('[data-dam-share-password]').fill('abc');
        assert.equal(await page.locator('[data-dam-share-password-error]').count(), 1);
        assert.equal(await create.isDisabled(), true);
        await page.locator('[data-dam-share-password]').fill('abcd-secret');
        assert.equal(await page.locator('[data-dam-share-password-error]').count(), 0);
        assert.equal(await create.isDisabled(), false);

        await page.locator('[data-dam-share-allow-download]').uncheck();
        await page.locator('[data-dam-share-creator-access]').check();
        await create.click();
        await page.waitForFunction(() => window.__pending !== undefined);

        const body = JSON.parse(requests.find((request) => request.method === 'post' && request.url.includes('shared-links')).body);
        assert.equal(body.password, 'abcd-secret');
        assert.equal(body.allow_download, 0);
        assert.equal(body.requires_creator_access, 1);
        assert.equal('remove_password' in body, false);
        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('an existing link keeps its password on a blank save, clears it only when unticked, and sends only changed options', async () => {
    const browser = await launch();

    try {
        const link = { ...shareLink(811), has_password: true, allow_download: true, requires_creator_access: false };
        const { page, requests } = await mount(browser, { shares: [link] });
        await page.locator('[data-dam-share-save]').waitFor();

        assert.equal(await page.locator('[data-dam-share-password-toggle]').isChecked(), true);
        assert.equal(await page.locator('[data-dam-share-password]').inputValue(), '', 'the password is never shown back');
        assert.equal(await page.locator('[data-dam-share-save]').isDisabled(), false);

        await page.locator('[data-dam-share-save]').click();
        await page.waitForFunction(() => window.__pending !== undefined);
        assert.deepEqual(JSON.parse(requests.filter((request) => request.method === 'patch').at(-1).body), { name: null });

        await page.locator('[data-dam-share-allow-download]').uncheck();
        await page.locator('[data-dam-share-password-toggle]').uncheck();
        await page.locator('[data-dam-share-save]').click();
        const patches = () => requests.filter((request) => request.method === 'patch');
        for (let i = 0; i < 100 && patches().length < 2; i++) await page.waitForTimeout(20);
        assert.equal(patches().length, 2);
        assert.deepEqual(JSON.parse(patches().at(-1).body), { name: null, allow_download: 0, remove_password: 1 });
        await page.close();
    } finally {
        await browser.close();
    }
});
