// The avatar dropdown (packages/Mothership/Admin/src/Resources/views/components/layouts/header/index.blade.php)
// now carries the account's name/role, My Account, the Settings and Configuration sections
// (AdminServiceProvider::buildAccountMenu()) and Log out, instead of just My Account/Log out under a
// Mothership logo. This exercises the real, unmodified v-dropdown component (packages/Mothership/Admin/src/
// Resources/views/components/dropdown/index.blade.php) — the shared toggle/open/close mechanism the
// header relies on — against a fixture built from the header's rendered markup. Both come from
// tools/browser/fixtures/blade.cjs, where Laravel has rendered the views (Node cannot run Blade); the
// PHP/Blade side — which sections appear, gating, labels — is covered by
// packages/Mothership/Admin/tests/Feature/AvatarAccountMenuTest.php.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { bladeComponent, bladeFixture } = require('../fixtures/blade.cjs');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const adminManifestFile = 'public/themes/admin/default/build/manifest.json';

// The real #v-dropdown-template markup and the real app.component('v-dropdown', {...}) registration,
// as the shared component renders them, so this test tracks the shipped toggle/open/close behaviour
// rather than a re-implementation of it.
function dropdownComponent() {
    const { template, script } = bladeComponent('dropdown');

    return { template, registration: script };
}

// Reads every class the account-menu fixture needs out of the header's rendered avatar dropdown — the
// toggle button, the content wrapper, the name/role block and its two truncate lines, the My Account
// and Log out row/link pairs, and a section's row/heading/entry-link classes — so the fixture renders
// with the shipped classes rather than a hand-typed guess at them. A hand-typed guess is exactly what
// let a prior version of this test pass for the wrong reason: with no classes on the section/link
// markup, links rendered inline and stacked their widths along one line, producing an overflow that
// had nothing to do with the real layout (grid section containers, padded block links).
//
// Also reads the class the component's root tag carries ({{ $attributes->merge(['class' => 'relative']) }};
// the header passes none of its own, so that is the root's complete class list in production). The
// panel inside is `position: absolute`, so it anchors to whichever ancestor is a positioning context:
// without this class on the fixture's own <v-dropdown> root, the panel anchors to the viewport instead
// of to this wrapper the way it does in production, and a viewport-wide panel can pass at x=0 in an
// unpositioned fixture and still start off-screen once anchored to the real (inset) wrapper.
//
// Runs in the page: the slots are <template>s, whose content only a real HTML parser exposes. Each
// lookup fails with its own message, so a markup change this can no longer follow fails loudly here
// instead of silently producing an unfaithful fixture.
function readAccountMenuClasses(html) {
    const need = (element, what) => {
        if (! element) {
            throw new Error(`${what} must exist in the rendered avatar dropdown`);
        }

        return element;
    };

    const parsed = document.createElement('template');
    parsed.innerHTML = html;
    const dropdown = need(parsed.content.querySelector('v-dropdown'), 'the <v-dropdown> root');
    const slot = (name) => need(dropdown.querySelector(`:scope > template[v-slot\\:${name}]`), `the ${name} slot`).content;

    const toggle = need(slot('toggle').querySelector('button[data-account-avatar]'), 'the initials toggle button');
    const content = need(slot('content').querySelector('[data-account-menu]'), "the account menu's content wrapper");
    const rows = [...content.querySelectorAll(':scope > div')];

    const nameRow = need(rows.find((row) => row.querySelectorAll(':scope > p').length === 2 && ! row.querySelector('a')), 'the name/role block with its two <p> lines');
    const [name, role] = nameRow.querySelectorAll(':scope > p');

    // My Account is the menu's first link; its row is a link-only row before the sections.
    const myAccountLink = need(content.querySelector(':scope > div > a'), 'the My Account link');

    const sectionRow = need(rows.find((row) => row.querySelector(':scope > p + a')), 'a section row (heading, then entry links)');

    // The hidden logout <v-form> posts to the same URL the visible Log out link points at.
    const logoutForm = need(content.querySelector(':scope > #adminLogout'), 'the hidden logout form');
    const logoutLink = need(rows.map((row) => row.querySelector(':scope > a')).find((link) => link && link.getAttribute('href') === logoutForm.getAttribute('action')), 'the Log out link');

    return {
        wrapperClass: dropdown.getAttribute('class'),
        toggleClass: toggle.getAttribute('class'),
        contentClass: content.getAttribute('class'),
        nameRowClass: nameRow.getAttribute('class'),
        nameClass: name.getAttribute('class'),
        roleClass: role.getAttribute('class'),
        myAccountRowClass: myAccountLink.parentElement.getAttribute('class'),
        myAccountLinkClass: myAccountLink.getAttribute('class'),
        sectionRowClass: sectionRow.getAttribute('class'),
        sectionHeadingClass: sectionRow.querySelector(':scope > p').getAttribute('class'),
        entryLinkClass: sectionRow.querySelector(':scope > a').getAttribute('class'),
        logoutRowClass: logoutLink.parentElement.getAttribute('class'),
        logoutLinkClass: logoutLink.getAttribute('class'),
    };
}

// Resolves the committed admin stylesheet the same way SelectDropdownStackingTest.php's
// builtStylesheet() does in PHP: the manifest entry whose built file is the assets/app-*.css Vite
// emits for src/Resources/assets/css/app.css.
function resolveBuiltAdminStylesheetPath() {
    const manifest = JSON.parse(source(adminManifestFile));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));
    assert.ok(entry, 'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root, 'public/themes/admin/default/build', entry.file);
}

// An overflow-prone but realistic full name (51 characters): the name row is `w-max` plus `truncate`,
// and `truncate` alone does not cap a flex child's width — without an outer constraint on the panel
// (see header/index.blade.php's account-menu content class), a name this long grows the panel itself,
// not just its own line.
const REALISTIC_LONG_ACCOUNT_NAME = 'Bartholomew Alexander Constantinos Pemberton-Smythe';

// A stand-in for the header's rendered avatar dropdown: a full-access admin (both Settings and
// Configuration sections populated) built entirely from the real classes readAccountMenuClasses()
// reads out of the rendered header, down to the row/link/heading level — not a re-typed
// approximation of them. wrapperClass is the dropdown component's root class there, and belongs on
// the fixture's <v-dropdown> root the same way.
function accountMenuFixture(classes, wrapperClass) {
    const {
        toggleClass, contentClass, nameRowClass, nameClass, roleClass,
        myAccountRowClass, myAccountLinkClass, sectionRowClass, sectionHeadingClass,
        entryLinkClass, logoutRowClass, logoutLinkClass,
    } = classes;

    const section = (heading, entries) => `
                <div class="${sectionRowClass}">
                    <p class="${sectionHeadingClass}">${heading}</p>
                    ${entries.map(([href, label]) => `<a class="${entryLinkClass}" href="${href}">${label}</a>`).join('\n                    ')}
                </div>`;

    return `
    <v-dropdown position="bottom-right" class="${wrapperClass}">
        <template v-slot:toggle>
            <button class="${toggleClass}" aria-label="Account">${REALISTIC_LONG_ACCOUNT_NAME.charAt(0)}</button>
        </template>
        <template v-slot:content>
            <div data-account-menu class="${contentClass}">
                <div class="${nameRowClass}">
                    <p class="${nameClass}">${REALISTIC_LONG_ACCOUNT_NAME}</p>
                    <p class="${roleClass}">Administrator</p>
                </div>
                <div class="${myAccountRowClass}">
                    <a class="${myAccountLinkClass}" href="/admin/account/edit">My Account</a>
                </div>
                ${section('Settings', [
                    ['/admin/settings/users', 'Users'],
                    ['/admin/settings/roles', 'Roles'],
                    ['/admin/settings/groups', 'Groups'],
                    ['/admin/settings/channels', 'Channels'],
                ])}
                ${section('Configuration', [
                    ['/admin/dam/configuration', 'Library'],
                    ['/admin/configuration/integrations', 'Integrations'],
                    ['/admin/configuration/webhook', 'Webhooks'],
                    ['/admin/configuration/system/information', 'System information'],
                    ['/admin/settings/system', 'System settings'],
                ])}
                <div class="${logoutRowClass}">
                    <a class="${logoutLinkClass}" href="/admin/session">Log out</a>
                </div>
            </div>
        </template>
    </v-dropdown>
`;
}

// The header lays its right-hand icon group (dark mode, notifications, avatar) out with
// `flex justify-between items-center px-4` — the toggle sits hard against the viewport's right edge,
// which is exactly what makes an overflow at phone width possible in the first place. Mirroring that
// with a plain flex wrapper (rather than mounting the dropdown alone, centred by default) is what
// makes the phone-width case below a meaningful test instead of one that could never fail.
//
// Loads the real committed admin stylesheet (not a re-implementation of its rules) so the extracted
// classes — the component's `absolute`/`w-max`/positioning classes included — actually take visual
// effect; see the notes above readAccountMenuClasses() and accountMenuFixture().
async function mountAccountMenuDropdown(page) {
    const { template, registration } = dropdownComponent();
    const { wrapperClass, ...classes } = await page.evaluate(readAccountMenuClasses, bladeFixture('account-menu.html'));

    await page.setContent(`<header style="display:flex;justify-content:flex-end;padding:0 16px;"><main id="app">${accountMenuFixture(classes, wrapperClass)}</main></header>`);
    await page.addStyleTag({ path: resolveBuiltAdminStylesheetPath() });
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate((template) => {
        const script = document.createElement('script');
        script.type = 'text/x-template';
        script.id = 'v-dropdown-template';
        script.textContent = template;
        document.body.append(script);

        window.app = Vue.createApp({});
    }, template);

    await page.addScriptTag({ content: registration });
    await page.evaluate(() => app.mount('#app'));

    return { toggle: page.getByRole('button', { name: 'Account' }), panel: page.locator('[data-account-menu]') };
}

// The dropdown's own <transition> (dropdown/index.blade.php's enter-active-class="transition ease-out
// duration-100", scale-95/opacity-0 to scale-100/opacity-100) animates the panel's transform and
// opacity over 100ms on open. Playwright's waitFor({state:'visible'}) resolves as soon as the element
// has a non-empty box — it does not wait for that transition to finish — so a boundingBox() read right
// after it can land mid-animation, when the transform is still scaling up from ~0.95: this is what
// first made the phone-width case below look like an 8px left-edge overflow that a settled read does
// not reproduce. Waiting for the wrapper's own transitionend (with a timeout fallback in case
// prefers-reduced-motion or similar skips the transition and no event ever fires) makes every geometry
// read below reflect the panel's final, settled box instead of an in-flight frame of it.
async function waitForDropdownTransition(panel) {
    await panel.evaluate((el) => new Promise((resolve) => {
        let done = false;
        const finish = () => {
            if (!done) {
                done = true;
                resolve();
            }
        };
        el.parentElement.addEventListener('transitionend', finish, { once: true });
        setTimeout(finish, 250);
    }));
}

const vueTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

vueTest('avatar dropdown shows the Settings and Configuration sections and opens/closes by keyboard', async () => {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage();
        const { toggle, panel } = await mountAccountMenuDropdown(page);

        // Closed on load: the account links exist in the DOM (Vue slot content) but the panel is
        // not shown until the toggle is activated.
        await assert.rejects(panel.waitFor({ state: 'visible', timeout: 300 }));

        // Keyboard open: focusing the (native) toggle button and pressing Enter fires its click
        // handler via ordinary browser button semantics — no bespoke key handling is required.
        await toggle.focus();
        await page.keyboard.press('Enter');
        await panel.waitFor({ state: 'visible' });

        assert.equal(await panel.getByText('Settings', { exact: true }).isVisible(), true);
        assert.equal(await panel.getByText('Configuration', { exact: true }).isVisible(), true);

        for (const name of ['Users', 'Roles', 'Groups', 'Channels']) {
            assert.equal(await panel.getByRole('link', { name, exact: true }).isVisible(), true);
        }

        for (const name of ['Library', 'Integrations', 'Webhooks', 'System information', 'System settings']) {
            assert.equal(await panel.getByRole('link', { name, exact: true }).isVisible(), true);
        }

        // Keyboard close: the toggle is a plain click-driven boolean flip (v-dropdown has no
        // separate Escape handler), so pressing Enter again on the still-focused toggle closes it —
        // an all-keyboard open/close round trip.
        await toggle.focus();
        await page.keyboard.press('Enter');
        await panel.waitFor({ state: 'hidden' });

        // Re-open, then confirm the existing click-outside-to-close behaviour still holds with the
        // new content.
        await toggle.click();
        await panel.waitFor({ state: 'visible' });
        await page.mouse.click(5, 5);
        await panel.waitFor({ state: 'hidden' });
    } finally {
        await browser.close();
    }
});

// The dropdown is not teleported (no `:teleport="true"` — see the header's usage), so its panel is
// `position: absolute; right: 0` against its own toggle wrapper, which the header pins to the far
// right of the screen at any width, with no viewport clamping of its own. A long account name and the
// full Configuration section together are the more overflow-prone case to check at a narrow viewport.
vueTest('avatar dropdown panel stays inside the viewport at phone width', async () => {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        const { toggle, panel } = await mountAccountMenuDropdown(page);

        await toggle.click();
        await panel.waitFor({ state: 'visible' });
        await waitForDropdownTransition(panel);

        const viewport = page.viewportSize();
        const panelBox = await panel.boundingBox();

        assert.ok(panelBox, 'the panel must have a layout box once open');
        assert.ok(panelBox.x >= 0, `panel left edge (${panelBox.x}) must not overflow past the left of the viewport`);
        assert.ok(panelBox.y >= 0, `panel top edge (${panelBox.y}) must not overflow above the viewport`);
        assert.ok(panelBox.x + panelBox.width <= viewport.width, `panel right edge (${panelBox.x + panelBox.width}) must stay within the ${viewport.width}px viewport`);
        assert.ok(panelBox.y + panelBox.height <= viewport.height, `panel bottom edge (${panelBox.y + panelBox.height}) must stay within the ${viewport.height}px viewport`);

        // Whether the full entry list actually needs the panel's own overflow-auto scrolling at this
        // viewport size is a fact about the current content, not a requirement either way — recorded
        // here rather than asserted, since a taller or shorter account menu is legitimate either way.
        const scrollMetrics = await panel.evaluate((el) => ({ scrollHeight: el.scrollHeight, clientHeight: el.clientHeight }));
        const isScrollable = scrollMetrics.scrollHeight > scrollMetrics.clientHeight;
        console.log(`panel scrollHeight=${scrollMetrics.scrollHeight} clientHeight=${scrollMetrics.clientHeight} (${isScrollable ? 'scrollable' : 'not scrollable'} at 390x844)`);

        // Every entry, scrolled into its panel's own scroll container unconditionally (Playwright's
        // isVisible() does not account for scroll-container clipping, so it cannot tell a genuinely
        // off-screen entry from one merely below an unscrolled fold) — then checked by bounding box
        // against the panel's own visible bounds, which does.
        for (const name of ['My Account', 'Users', 'Roles', 'Groups', 'Channels', 'Library', 'Integrations', 'Webhooks', 'System information', 'System settings', 'Log out']) {
            const entry = panel.getByRole('link', { name, exact: true });

            await entry.scrollIntoViewIfNeeded();

            const entryBox = await entry.boundingBox();
            assert.ok(entryBox, `${name} must have a layout box after being scrolled into view`);
            assert.ok(entryBox.x >= panelBox.x - 1, `${name} left edge (${entryBox.x}) must lie within the panel's visible left edge (${panelBox.x})`);
            assert.ok(entryBox.y >= panelBox.y - 1, `${name} top edge (${entryBox.y}) must lie within the panel's visible top edge (${panelBox.y})`);
            assert.ok(entryBox.x + entryBox.width <= panelBox.x + panelBox.width + 1, `${name} right edge (${entryBox.x + entryBox.width}) must lie within the panel's visible right edge (${panelBox.x + panelBox.width})`);
            assert.ok(entryBox.y + entryBox.height <= panelBox.y + panelBox.height + 1, `${name} bottom edge (${entryBox.y + entryBox.height}) must lie within the panel's visible bottom edge (${panelBox.y + panelBox.height})`);
        }
    } finally {
        await browser.close();
    }
});

// At 390x844 the full menu fits under max-h-[calc(100vh-130px)] (714px) without clipping, so the panel
// scrollIntoViewIfNeeded() loop above never actually exercises scrolling — it proves entries sit within
// the panel's bounds, not that a genuinely off-screen entry gets pulled into view. A short viewport
// forces the cap below the menu's natural height (662px at this width, from the "not scrollable at
// 390x844" log line above) so the panel's own overflow-auto genuinely has to scroll.
vueTest('avatar dropdown scrolls the first and last entry into view when the menu overflows its max height', async () => {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 480 } });
        const { toggle, panel } = await mountAccountMenuDropdown(page);

        await toggle.click();
        await panel.waitFor({ state: 'visible' });
        await waitForDropdownTransition(panel);

        const panelBox = await panel.boundingBox();
        assert.ok(panelBox, 'the panel must have a layout box once open');

        const scrollMetrics = await panel.evaluate((el) => ({ scrollHeight: el.scrollHeight, clientHeight: el.clientHeight }));
        assert.ok(
            scrollMetrics.scrollHeight > scrollMetrics.clientHeight,
            `the panel must actually overflow max-h-[calc(100vh-130px)] at this viewport for this test to exercise scrolling (scrollHeight=${scrollMetrics.scrollHeight}, clientHeight=${scrollMetrics.clientHeight})`,
        );

        // The first entry (My Account, initially in view) and the last (Log out, initially clipped
        // below the fold) — scrolling each into its own scroll container in turn and checking it lands
        // inside the panel's own (unmoving — only its content scrolls) visible bounds.
        for (const name of ['My Account', 'Log out']) {
            const entry = panel.getByRole('link', { name, exact: true });

            await entry.scrollIntoViewIfNeeded();

            const entryBox = await entry.boundingBox();
            assert.ok(entryBox, `${name} must have a layout box after being scrolled into view`);
            assert.ok(entryBox.x >= panelBox.x - 1, `${name} left edge (${entryBox.x}) must lie within the panel's visible left edge (${panelBox.x})`);
            assert.ok(entryBox.y >= panelBox.y - 1, `${name} top edge (${entryBox.y}) must lie within the panel's visible top edge (${panelBox.y})`);
            assert.ok(entryBox.x + entryBox.width <= panelBox.x + panelBox.width + 1, `${name} right edge (${entryBox.x + entryBox.width}) must lie within the panel's visible right edge (${panelBox.x + panelBox.width})`);
            assert.ok(entryBox.y + entryBox.height <= panelBox.y + panelBox.height + 1, `${name} bottom edge (${entryBox.y + entryBox.height}) must lie within the panel's visible bottom edge (${panelBox.y + panelBox.height})`);
        }
    } finally {
        await browser.close();
    }
});
