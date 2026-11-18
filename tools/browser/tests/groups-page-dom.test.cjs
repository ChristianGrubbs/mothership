// The rebuilt Groups page (packages/Mothership/DAM/src/Resources/views/groups/index.blade.php, ADR 0010
// sharing slice 4), driven in a real browser against the shipped template and the shipped
// app.component('v-access-groups', {...}) registration — never a re-implementation of either. It covers
// load, editing and saving a group, and that Everyone renders with no edit or delete controls. The PHP
// side — who may list, save or delete a group, and access_roots' set-based query — is covered by
// packages/Mothership/DAM/tests/Feature/GroupControllerTest.php.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const pageFile = 'packages/Mothership/DAM/src/Resources/views/groups/index.blade.php';
const langFile = 'packages/Mothership/DAM/src/Resources/lang/en_US/app.php';

const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

/** One flat `'key' => [ ... ]` lang block, parsed out of the real en_US file rather than hand-copied. */
function langBlock(name) {
    const php = source(langFile);
    const start = php.indexOf(`'${name}' => [`);
    assert.ok(start !== -1, `the '${name}' lang block must exist in en_US/app.php`);
    const end = php.indexOf('],', start);
    assert.ok(end !== -1, `the '${name}' lang block must be closed`);

    const strings = {};
    for (const [, key, value] of php.slice(start, end).matchAll(/'([a-z0-9-]+)'\s*=>\s*'((?:[^'\\]|\\.)*)'/g)) {
        strings[key] = value.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
    }

    return strings;
}

const groupsStrings = langBlock('groups');
const accessStrings = langBlock('access');

for (const key of ['title', 'create', 'built-in-badge', 'save', 'saving', 'delete', 'delete-confirm', 'load-failed']) {
    assert.ok(groupsStrings[key], `dam::app.admin.dam.groups.${key} must exist`);
}

/**
 * Renders the Blade source as the server would: every @lang/@js(trans()) key under
 * dam::app.admin.dam.groups or dam::app.admin.dam.access becomes its real string, and each
 * `{{ route(...) }}`/`@json(route(...))` becomes a stub path.
 */
/** A string as Blade's @js renders it (Js::from): single-quoted, quotes hex-escaped, so it is safe inside an attribute. */
const bladeJs = (value) => `'${JSON.stringify(String(value)).slice(1, -1).replace(/\\"/g, '\\u0022').replace(/'/g, '\\u0027')}'`;

function blade(text) {
    return text
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        // `<x-admin::breadcrumbs :leaf="trans('...')" />` is a server-side Blade component: the server renders it to
        // markup before the browser sees the template, so the DOM harness renders a stand-in carrying the leaf label.
        .replace(/<x-admin::breadcrumbs :leaf="trans\('dam::app\.admin\.dam\.(groups|access)\.([a-z0-9-]+)'\)" \/>/g, (_, block, key) => {
            const strings = block === 'groups' ? groupsStrings : accessStrings;
            return `<nav data-breadcrumbs>${strings[key] ?? key}</nav>`;
        })
        .replace(/@js\(trans\('dam::app\.admin\.dam\.(groups|access)\.([a-z0-9-]+)'\)\)/g, (_, block, key) => {
            const strings = block === 'groups' ? groupsStrings : accessStrings;
            return bladeJs(strings[key] ?? key);
        })
        // English trans_choice: the first `|` form for one, the second otherwise.
        .replace(/@js\(trans_choice\('dam::app\.admin\.dam\.(groups|access)\.([a-z0-9-]+)', (\d+)(?:, \[[^\]]*\])?\)\)/g, (_, block, key, count) => {
            const forms = String((block === 'groups' ? groupsStrings : accessStrings)[key] ?? key).split('|');
            return bladeJs(forms[Number(count) === 1 ? 0 : forms.length - 1]);
        })
        .replace(/@lang\('dam::app\.admin\.dam\.groups\.([a-z0-9-]+)'\)/g, (_, key) => groupsStrings[key] ?? key)
        .replace(/@json\(route\('([^']+)'\)\)/g, (_, name) => JSON.stringify(`/fixture/${name}`))
        .replace(/@\{\{/g, '{{')
        .replace(/@@/g, '@');
}

function groupsPage() {
    const php = source(pageFile);
    const template = php.match(/<script\s+type="text\/x-template"\s+id="v-access-groups-template"\s*>([\s\S]*?)<\/script>/);
    assert.ok(template, 'v-access-groups-template must exist');
    const registration = php.match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(registration, "app.component('v-access-groups', ...) must exist");
    assert.match(registration[1], /app\.component\('v-access-groups'/);

    return { template: blade(template[1]), script: blade(registration[1]) };
}

const everyone = { id: 1, name: 'Everyone (all users)', system_key: 'everyone', is_system: true, read_only: true, member_count: 3, member_ids: [] };
const editors = { id: 2, name: 'Editors', system_key: null, is_system: false, read_only: false, member_count: 1, member_ids: [9] };
const users = [
    { id: 9, name: 'Sam Friend', email: 'sam@example.test', role_name: 'Editor', status: 1 },
    { id: 10, name: 'Ivy Idle', email: 'ivy@example.test', role_name: 'Editor', status: 0 },
];

/** Mounts the shipped template and component in Chromium behind a stubbed $axios. */
async function mount(browser, { groups = [everyone, editors], users: userList = users, responses = {}, listOutcomes = null } = {}) {
    const page = await browser.newPage();
    const requests = [];

    await page.exposeFunction('reportRequest', (method, url, body) => requests.push({ method, url, body }));
    await page.route('http://groups.test/**', (route) => route.fulfill({
        contentType: 'text/html',
        body: '<div id="app"><v-access-groups></v-access-groups></div>',
    }));
    await page.goto('http://groups.test/settings/groups');
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate(({ template, script, groups, users, responses, listOutcomes }) => {
        const node = document.createElement('script');
        node.type = 'text/x-template';
        node.id = 'v-access-groups-template';
        node.textContent = template;
        document.body.append(node);

        window.__emitted = [];
        const emitter = { on() {}, off() {}, emit: (event, payload) => window.__emitted.push({ event, payload }) };

        // Built here, in the page, rather than passed in from Node: a rejected promise cannot cross
        // page.evaluate's structured-clone boundary, and a Promise built in Node before that boundary
        // rejects there first, as an unhandled rejection the test never gets to see.
        const outcome = (spec) => spec?.reject ? Promise.reject(spec.reject) : Promise.resolve(spec?.resolve ?? { data: { deleted: true } });

        // One scripted outcome per /list call, oldest first, holding the last one once exhausted — the
        // error-then-Retry-succeeds case needs the first call to fail and the second to succeed.
        const remainingListOutcomes = Array.isArray(listOutcomes) ? listOutcomes.slice() : null;
        const nextListOutcome = () => {
            if (! remainingListOutcomes) return { resolve: { data: { groups, users } } };

            return remainingListOutcomes.length > 1 ? remainingListOutcomes.shift() : remainingListOutcomes[0];
        };

        // A parked list() request the test settles explicitly, for asserting the loading state before
        // anything has answered — window.__settleList(data) resolves it, window.__failList(message) rejects.
        let pendingListResolve = null;
        let pendingListReject = null;
        window.__settleList = (data) => pendingListResolve?.({ data });
        window.__failList = (message) => pendingListReject?.(new Error(message));

        const answer = (method) => (url, payload) => {
            window.reportRequest(method, url, payload ? JSON.stringify(payload) : null);

            if (method === 'get' && url.endsWith('/list')) {
                if (listOutcomes === 'pending') {
                    return new Promise((resolve, reject) => {
                        pendingListResolve = resolve;
                        pendingListReject = reject;
                    });
                }

                return outcome(nextListOutcome());
            }

            if (method === 'delete') {
                return outcome(responses.delete);
            }

            return responses.save
                ? outcome(responses.save)
                : Promise.resolve({ data: { data: { id: 99, name: payload?.name, member_ids: payload?.member_ids ?? [] } } });
        };

        const app = Vue.createApp({});
        app.config.globalProperties.$emitter = emitter;
        app.config.globalProperties.$axios = { get: answer('get'), put: answer('put'), post: answer('post'), delete: answer('delete') };

        new Function('app', script)(app);
        app.mount('#app');
    }, { ...groupsPage(), groups, users: userList, responses, listOutcomes });

    return { page, requests };
}

const browserTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;
const launch = () => require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

browserTest('loads the group list, with Everyone first and no edit or delete controls', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser);

        await page.locator('[data-group-row]').first().waitFor();

        const rows = await page.locator('[data-group-row]').evaluateAll((nodes) => nodes.map((node) => node.textContent.trim()));
        assert.match(rows[0], /Everyone/);
        assert.match(rows[0], /Built-in/);

        // Everyone's row is a read-only row, not a (greyed-out) button: it explains why and opens no editor.
        const everyoneRow = page.locator('[data-group-everyone]');
        assert.equal(await everyoneRow.evaluate((node) => node.tagName), 'DIV');
        assert.equal(await everyoneRow.getAttribute('title'), groupsStrings['everyone-read-only']);
        await everyoneRow.click({ force: true }).catch(() => {});
        assert.equal(await page.locator('form').count(), 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('edits and saves a group, sending a PUT with its name and members', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser);

        await page.locator('text=Editors').first().click();
        await page.locator('#group-name').waitFor();
        assert.equal(await page.locator('#group-name').inputValue(), 'Editors');

        await page.locator('#group-name').fill('Producers');
        await page.locator('[data-group-save]').click();

        const saved = requests.find((request) => request.method === 'put');
        assert.ok(saved, 'Save must send a PUT');
        assert.match(saved.url, /\/2$/);
        assert.deepEqual(JSON.parse(saved.body), { name: 'Producers', member_ids: [9] });

        const toasts = await page.evaluate(() => window.__emitted.filter((entry) => entry.event === 'add-flash'));
        assert.equal(toasts.length, 1);
        assert.equal(toasts[0].payload.type, 'success');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('marks an inactive admin in the member picker', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser);

        await page.locator('text=Editors').first().click();
        await page.locator('[data-group-member]').first().waitFor();

        const inactiveRow = page.locator('[data-group-member]', { hasText: 'Ivy Idle' });
        assert.match(await inactiveRow.textContent(), /Inactive/);

        const activeRow = page.locator('[data-group-member]', { hasText: 'Sam Friend' });
        assert.doesNotMatch(await activeRow.textContent(), /Inactive/);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a save that fails reports the error and keeps the draft open', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, {
            responses: { save: { reject: { response: { data: { message: 'Only the owner or a full admin can change who sees this item.' } } } } },
        });

        await page.locator('text=Editors').first().click();
        await page.locator('[data-group-save]').click();
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

        const toasts = await page.evaluate(() => window.__emitted.filter((entry) => entry.event === 'add-flash'));
        assert.equal(toasts.length, 1);
        assert.equal(toasts[0].payload.type, 'error');
        assert.equal(await page.locator('form').count(), 1, 'the draft should still be open after a failed save');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a failed load shows the error state with Retry, which recovers the list', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, {
            listOutcomes: [{ reject: new Error('network down') }, { resolve: { data: { groups: [everyone, editors], users } } }],
        });

        await page.locator('[data-groups-error]').waitFor();
        assert.equal(await page.locator('[data-group-row]').count(), 0);

        await page.locator('[data-groups-retry]').click();

        await page.locator('[data-group-row]').first().waitFor();
        assert.equal(await page.locator('[data-groups-error]').count(), 0);
        assert.equal(await page.locator('[data-group-row]').count(), 2);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('shows the empty state when no group matches the search', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser);

        await page.locator('[data-group-row]').first().waitFor();
        await page.locator('#group-search').fill('no such group anywhere');

        assert.equal(await page.locator('[data-group-row]').count(), 0);
        await page.locator('text=No groups yet, or none match your search.').waitFor();

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a read-only role group opens with its fields disabled and an explanation, and saves nothing', async () => {
    const browser = await launch();
    const roleGroup = { id: 3, name: 'Editors (from role)', system_key: 'role:7', is_system: false, read_only: true, member_count: 2, member_ids: [9] };

    try {
        const { page, requests } = await mount(browser, { groups: [everyone, editors, roleGroup] });

        await page.locator('text=Editors (from role)').first().click();
        await page.locator('[data-group-read-only-note]').waitFor();

        assert.match(await page.locator('[data-group-read-only-note]').textContent(), /role/i);
        assert.equal(await page.locator('#group-name').isDisabled(), true);
        assert.equal(await page.locator('#member-search').count(), 0, 'the read-only view offers no member search');
        assert.equal(await page.locator('[data-group-save]').count(), 0);
        assert.equal(await page.locator('[data-group-delete]').count(), 0);

        // Checkboxes still show current membership, disabled.
        const memberCheckbox = page.locator('[data-group-member] input[type="checkbox"]').first();
        assert.equal(await memberCheckbox.isDisabled(), true);

        assert.equal(requests.filter((request) => request.method === 'put' || request.method === 'delete').length, 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('shows the loading skeleton while the initial list request is pending', async () => {
    const browser = await launch();

    try {
        const { page } = await mount(browser, { listOutcomes: 'pending' });

        // Plain existence, not visibility: the skeleton is bare utility-class <div>s with no text, which
        // headless Chromium reports as a zero-size (so "hidden") box once the real stylesheet is not loaded.
        await page.waitForSelector('[data-groups-loading]', { state: 'attached' });
        assert.equal(await page.locator('[data-group-row]').count(), 0);
        assert.equal(await page.locator('[data-groups-error]').count(), 0);

        await page.evaluate((payload) => window.__settleList(payload), { groups: [everyone, editors], users });

        await page.locator('[data-group-row]').first().waitFor();
        assert.equal(await page.locator('[data-groups-loading]').count(), 0);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('deleting a group: cancelling the confirmation sends nothing', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser);
        page.once('dialog', (dialog) => dialog.dismiss());

        await page.locator('text=Editors').first().click();
        await page.locator('[data-group-delete]').waitFor();
        await page.locator('[data-group-delete]').click();
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

        assert.equal(requests.filter((request) => request.method === 'delete').length, 0);
        assert.equal(await page.locator('form').count(), 1, 'cancelling the confirmation must leave the draft open');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('deleting a group: confirming names the group, sends the delete, and the reload drops it from the list', async () => {
    const browser = await launch();

    try {
        // A second, different /list answer for the reload remove() triggers: Editors is gone, Everyone
        // stays. A test that only checked the DELETE and the toast would still pass if `await this.load()`
        // were removed from remove() entirely, leaving the deleted row on screen (round-3 re-review, finding 3).
        const { page, requests } = await mount(browser, {
            listOutcomes: [
                { resolve: { data: { groups: [everyone, editors], users } } },
                { resolve: { data: { groups: [everyone], users } } },
            ],
        });
        let confirmMessage = null;
        page.once('dialog', (dialog) => {
            confirmMessage = dialog.message();
            dialog.accept();
        });

        await page.locator('text=Editors').first().click();
        await page.locator('[data-group-delete]').click();

        assert.match(confirmMessage, /Editors/, 'the confirmation must name the group being deleted');

        const deleted = requests.find((request) => request.method === 'delete');
        assert.ok(deleted, 'confirming must send a DELETE');
        assert.match(deleted.url, /\/2$/);

        // The reload: a second /list request, and the deleted row gone from the DOM once it answers —
        // proof remove() actually reloaded rather than just closing the editor over stale data.
        await page.locator('text=Editors').first().waitFor({ state: 'detached' });
        assert.equal(requests.filter((request) => request.method === 'get' && request.url.endsWith('/list')).length, 2);
        assert.equal(await page.locator('[data-group-row]').count(), 1);
        assert.match(await page.locator('[data-group-row]').first().textContent(), /Everyone/);

        const toasts = await page.evaluate(() => window.__emitted.filter((entry) => entry.event === 'add-flash'));
        assert.equal(toasts.length, 1);
        assert.equal(toasts[0].payload.type, 'success');
        assert.equal(await page.locator('form').count(), 0, 'the editor should close after a successful delete');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('deleting a group: a failure reports the error and keeps the draft open', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, {
            responses: { delete: { reject: { response: { data: { message: 'Could not delete the group. Try again.' } } } } },
        });
        page.once('dialog', (dialog) => dialog.accept());

        await page.locator('text=Editors').first().click();
        await page.locator('[data-group-delete]').click();
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));

        assert.ok(requests.some((request) => request.method === 'delete'), 'confirming must still send a DELETE');

        const toasts = await page.evaluate(() => window.__emitted.filter((entry) => entry.event === 'add-flash'));
        assert.equal(toasts.length, 1);
        assert.equal(toasts[0].payload.type, 'error');
        assert.equal(await page.locator('form').count(), 1, 'a failed delete must leave the draft open');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('"Where this group has access" links point to the Library, by directory id', async () => {
    const browser = await launch();
    const groupWithRoots = {
        id: 5, name: 'Studio crew', system_key: null, is_system: false, read_only: false, member_count: 0, member_ids: [],
        access_roots: [{ id: 42, name: 'Season one', path: 'Season one' }, { id: 43, name: 'Archive', path: 'Archive/2026' }],
    };

    try {
        const { page } = await mount(browser, { groups: [everyone, groupWithRoots] });

        await page.locator('text=Studio crew').first().click();
        await page.locator('[data-group-access-roots]').waitFor();

        const links = page.locator('[data-group-access-root]');
        assert.equal(await links.count(), 2);
        assert.deepEqual(
            await links.evaluateAll((nodes) => nodes.map((node) => node.textContent.trim())),
            ['Season one', 'Archive/2026']
        );
        assert.match(await links.nth(0).getAttribute('href'), /admin\.dam\.index\?directory_id=42&scope=directory$/);
        assert.match(await links.nth(1).getAttribute('href'), /admin\.dam\.index\?directory_id=43&scope=directory$/);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a generated role group with read_only false (already under lists) is fully editable', async () => {
    const browser = await launch();
    const roleGroupUnderLists = {
        id: 6, name: 'Editors (from role)', system_key: 'role:9', is_system: false, read_only: false, member_count: 1, member_ids: [9],
        access_roots: [],
    };

    try {
        const { page, requests } = await mount(browser, { groups: [everyone, roleGroupUnderLists] });

        await page.locator('text=Editors (from role)').first().click();
        await page.locator('#group-name').waitFor();

        assert.equal(await page.locator('[data-group-read-only-note]').count(), 0);
        assert.equal(await page.locator('#group-name').isDisabled(), false);
        assert.equal(await page.locator('#member-search').count(), 1);
        assert.equal(await page.locator('[data-group-save]').count(), 1);
        assert.equal(await page.locator('[data-group-delete]').count(), 1);

        await page.locator('#group-name').fill('Editors (renamed)');
        await page.locator('[data-group-save]').click();

        const saved = requests.find((request) => request.method === 'put');
        assert.ok(saved, 'Save must send a PUT for a role group whose read_only is false');
        assert.match(saved.url, /\/6$/);
        assert.deepEqual(JSON.parse(saved.body), { name: 'Editors (renamed)', member_ids: [9] });

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a hostile group name and admin name render as text, never as markup', async () => {
    const browser = await launch();
    const hostileGroup = { id: 4, name: '<img src=x onerror="window.__pwnedGroup = true">', system_key: null, is_system: false, read_only: false, member_count: 1, member_ids: [11] };
    const hostileUser = { id: 11, name: '"><script>window.__pwnedUser = true;</script>', email: '"><script>window.__pwnedEmail = true;</script>', role_name: 'Editor', status: 1 };

    try {
        const { page } = await mount(browser, { groups: [everyone, hostileGroup], users: [hostileUser] });

        await page.locator('[data-group-row]').first().waitFor();

        // The row shows the raw name as text, not as an <img>.
        assert.equal(await page.locator('[data-group-row] img, [data-group-row] script').count(), 0);
        assert.match(
            (await page.locator('[data-group-row]', { hasText: 'onerror' }).textContent()),
            /onerror="window\.__pwnedGroup = true"/
        );

        await page.locator('[data-group-row]', { hasText: 'onerror' }).click();
        await page.locator('[data-group-member]').first().waitFor();

        assert.equal(await page.locator('[data-group-member] script').count(), 0);
        assert.match(await page.locator('[data-group-member]').textContent(), /window\.__pwnedUser = true/);

        assert.deepEqual(
            await page.evaluate(() => [window.__pwnedGroup, window.__pwnedUser, window.__pwnedEmail]),
            [undefined, undefined, undefined]
        );

        await page.close();
    } finally {
        await browser.close();
    }
});
