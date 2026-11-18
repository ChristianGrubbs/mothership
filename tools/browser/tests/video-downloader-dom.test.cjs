const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const widget = path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/widgets/video-downloader');
const modulePath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const run = modulePath ? test : test.skip;
const origin = 'http://video.test';
const LIMIT = 250 * 1024 * 1024;

const job = (id, status, extra = {}) => ({
    id, status, title: 'Clip ' + id, label: '480p video (MP4)', choice: 'v480', url: 'https://example.test/' + id,
    percent: status === 'done' ? 100 : 40, downloaded: 400, total: 1000, size: status === 'done' ? 300000 : null, filename: id + '.mp4',
    error: status === 'failed' ? 'That did not work.' : null, expires_at: Math.floor(Date.now() / 1000) + 86400,
    can_optimize: false, optimize: false, optimized: null, ...extra,
});

// Serves the tool from disk at the path Home serves it from, and answers its API from `studio`, a made-up job list.
// `app` makes the page look like the installed iPhone or iPad app, with a share sheet that only records what it is given.
// `framed` puts the tool inside a page that stands in for Home and keeps what the tool tells it about holding work.
async function openTool(browser, { app = false, framed = false, jobs = [] } = {}) {
    const studio = { jobs, held: null, next: null };
    // A file kept back until the test lets it through stands in for a slow connection.
    studio.hold = id => { let open; const gate = new Promise(resolve => { open = resolve; }); studio.held = { id, gate, open }; };
    studio.letThrough = () => { studio.held.open(); studio.held = null; };
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    page.setDefaultTimeout(5000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(origin + '/**', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname.startsWith('/admin/home/video/')) {
            const at = url.pathname.replace('/admin/home/video/', '');
            if (at.endsWith('/file')) {
                if (studio.held && at.split('/')[1] === studio.held.id) await studio.held.gate;
                return route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(300000) });
            }
            if (at === 'jobs' && request.method() === 'POST') {
                studio.jobs = [studio.next.job, ...studio.jobs.filter(candidate => candidate.id !== studio.next.replaces)];
                return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ job: studio.next.job }) });
            }
            if (request.method() === 'DELETE') {
                return route.fulfill(studio.refusesRemoval ? { status: 502, contentType: 'application/json', body: '{"message":"The studio did not answer."}' }
                    : { contentType: 'application/json', body: '{"ok":true}' });
            }
            if (at === 'jobs') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ jobs: studio.jobs, optimize: true, optimize_max: LIMIT, now: Date.now() / 1000 }) });
            return route.fulfill({ status: 404, body: '' });
        }
        if (url.pathname === '/home.html') {
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><meta charset="utf-8"><script>
                window.__told = [];
                // The two facts Home's Close tool goes by (docs/manual/developer/iframe-tools.md): whether the tool
                // said it holds no work, and whether it has reported work since. A Home that asks only about
                // reported work asks when both say so.
                let noWork = false, reported = false;
                window.__asksBeforeClosing = () => !noWork && reported;
                addEventListener('message', event => {
                    const data = event.data;
                    if (event.source !== frames[0] || event.origin !== location.origin || data?.protocol !== 'mothership-widget/v1') return;
                    if (data.type === 'ready' && typeof data.payload?.holdsWork === 'boolean') {
                        window.__told.push(data.payload.holdsWork);
                        noWork = !data.payload.holdsWork;
                        if (noWork) reported = false;
                    }
                    if (data.type === 'dirty' && !noWork) reported = true;
                });
            </script><iframe src="/admin/home/tools/video-downloader/index.html" style="width:100%;height:700px;border:0"></iframe>` });
        }
        const file = path.join(widget, path.basename(url.pathname));
        const type = { '.html': 'text/html', '.css': 'text/css', '.js': 'application/javascript' }[path.extname(file)];
        return url.pathname.startsWith('/admin/home/tools/video-downloader/') && type && fs.existsSync(file)
            ? route.fulfill({ contentType: `${type}; charset=utf-8`, body: fs.readFileSync(file) }) : route.fulfill({ status: 404, body: '' });
    });
    if (app) {
        // 'dock' is the site added to the Mac Dock: installed as well, but with no touch screen and a download manager.
        await page.addInitScript(touch => {
            Object.defineProperty(Navigator.prototype, 'standalone', { get: () => true, configurable: true });
            if (touch) Object.defineProperty(Navigator.prototype, 'maxTouchPoints', { get: () => 5, configurable: true });
            Navigator.prototype.canShare = data => !!(data && data.files && data.files.length);
            // The sheet answers only when the file was saved or sent; closing it without doing either is a refusal.
            Navigator.prototype.share = data => {
                (window.__shared = window.__shared || []).push(data.files[0].name);
                return window.top.__sheetClosedUnsaved ? Promise.reject(Object.assign(new Error('closed'), { name: 'AbortError' })) : Promise.resolve();
            };
        }, app !== 'dock');
    }
    await page.goto(origin + (framed ? '/home.html' : '/admin/home/tools/video-downloader/index.html'));
    const tool = framed ? page.frameLocator('iframe') : page;
    const row = (id, state = '') => tool.locator(`[data-jobs] li[data-id="${id}"]${state}`);
    if (jobs.length) await row(jobs[0].id).waitFor();
    // The list catches up the way it does when the person comes back to the tool.
    const refresh = () => Promise.all([
        page.waitForResponse(response => response.url() === origin + '/admin/home/video/jobs' && response.request().method() === 'GET'),
        page.evaluate(() => { document.dispatchEvent(new Event('visibilitychange')); }),
    ]);
    // "Try again" on a failed row is the tool's own way of starting a download in this window.
    const startHere = async (failedId, id) => {
        studio.next = { replaces: failedId, job: job(id, 'running') };
        await row(failedId).locator('button[data-act="retry"]').click();
        await row(id).waitFor();
    };
    const control = id => row(id).locator('.job-actions > *').first().innerText();
    const saving = async id => ((await row(id).getAttribute('class')).match(/is-save-(\w+)/) || [])[1] || null;
    return { page, errors, studio, row, refresh, startHere, control, saving };
}

const launch = () => require(modulePath).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

run('Video Downloader in the installed app: only a tap replaces a file that is on its way or ready to save', async () => {
    const browser = await launch();
    try {
        const { page, errors, studio, row, refresh, startHere, control, saving } = await openTool(browser, { app: true, jobs: [job('c0', 'failed'), job('b0', 'failed'), job('a', 'done')] });
        await startHere('b0', 'b');
        await startHere('c0', 'c');

        // The person asks for A; it is still on its way when B, started in this window, finishes.
        studio.hold('a');
        await row('a').locator('button[data-act="save"]').click();
        await row('a', '.is-save-fetching').waitFor();
        studio.jobs = [job('c', 'running'), job('b', 'done'), job('a', 'done')];
        await refresh();
        await row('b', '.is-done').waitFor();
        assert.equal(await saving('a'), 'fetching');
        assert.equal(await saving('b'), null);
        assert.equal(await control('b'), 'Save to this device');

        // A arrives and goes to the share sheet, because a tap asked for it.
        studio.letThrough();
        await row('a', '.is-save-ready').waitFor();
        assert.equal(await control('a'), 'Save video');
        assert.deepEqual(await page.evaluate(() => window.__shared), ['a.mp4']);

        // C finishes while A waits to be saved: A stays ready.
        studio.jobs = [job('c', 'done'), job('b', 'done'), job('a', 'done')];
        await refresh();
        await row('c', '.is-done').waitFor();
        assert.equal(await saving('a'), 'ready');
        assert.equal(await control('c'), 'Save to this device');

        // A tap on B replaces A on purpose: one file is held at a time.
        await row('b').locator('button[data-act="save"]').click();
        await row('b', '.is-save-ready').waitFor();
        assert.equal(await saving('a'), null);
        assert.equal(await control('a'), 'Save to this device');
        assert.deepEqual(await page.evaluate(() => window.__shared), ['a.mp4', 'b.mp4']);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

run('Video Downloader in the installed app: two downloads finishing together leave one ready, without opening the share sheet', async () => {
    const browser = await launch();
    try {
        const { page, errors, studio, row, refresh, startHere, control, saving } = await openTool(browser, { app: true, jobs: [job('d0', 'failed'), job('c0', 'failed')] });
        await startHere('c0', 'c');
        await startHere('d0', 'd');
        studio.jobs = [job('d', 'done'), job('c', 'done')];
        await refresh();
        await row('d', '.is-save-ready').waitFor();
        assert.equal(await control('d'), 'Save video');
        assert.equal(await saving('c'), null);
        assert.equal(await control('c'), 'Save to this device');
        assert.equal(await page.evaluate(() => window.__shared), undefined);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

// The frame Home gives the tool on the iPhone Duo, from the sizes Safari reports for it: the outer display with the
// phone closed, the inner display with it open. Opening or closing the phone reaches a page as a resize.
const CLOSED = { width: 382, height: 523 }, OPEN = { width: 669, height: 810 };

run('Video Downloader on a folding phone: a row with three actions fits the closed screen, and a save survives opening and closing', async () => {
    const browser = await launch();
    try {
        const { page, errors, studio, row, control, saving } = await openTool(browser, { app: true, jobs: [job('a', 'done', { can_optimize: true })] });
        const sideways = () => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        // Save, Optimize and Remove side by side are wider than a narrow phone: the lesser two drop below the first.
        for (const narrow of [{ width: 320, height: 568 }, CLOSED]) {
            await page.setViewportSize(narrow);
            assert.equal(await sideways(), 0, `nothing sticks out sideways at ${narrow.width} px`);
        }

        studio.hold('a');
        await row('a').locator('button[data-act="save"]').click();
        await row('a', '.is-save-fetching').waitFor();
        await page.setViewportSize(OPEN);
        assert.equal(await saving('a'), 'fetching');
        await page.setViewportSize(CLOSED);
        assert.equal(await saving('a'), 'fetching');

        studio.letThrough();
        await row('a', '.is-save-ready').waitFor();
        await page.setViewportSize(OPEN);
        assert.equal(await saving('a'), 'ready');
        assert.equal(await control('a'), 'Save video');
        assert.equal(await sideways(), 0);
        assert.deepEqual(await page.evaluate(() => window.__shared), ['a.mp4']);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

run('Video Downloader tells Home it holds work only while a file is on this device and not yet saved', async () => {
    const browser = await launch();
    try {
        const { page, errors, studio, row } = await openTool(browser, { app: true, framed: true, jobs: [job('a', 'done')] });
        const told = () => page.evaluate(() => window.__told);
        const asks = () => page.evaluate(() => window.__asksBeforeClosing());
        // Downloads live on the studio: with nothing fetched, Home may close the tool without asking.
        await page.waitForFunction(() => window.__told.length === 1);
        assert.deepEqual(await told(), [false]);
        assert.equal(await asks(), false);

        // A file on its way to this device is lost by closing, so Home has to ask, whichever rule it closes by.
        studio.hold('a');
        await row('a').locator('button[data-act="save"]').click();
        await row('a', '.is-save-fetching').waitFor();
        assert.deepEqual(await told(), [false, true]);
        assert.equal(await asks(), true);

        // The file arrives and the share sheet is closed without saving: it is still only here.
        await page.evaluate(() => { window.__sheetClosedUnsaved = true; });
        studio.letThrough();
        await row('a', '.is-save-ready').waitFor();
        assert.deepEqual(await told(), [false, true]);
        assert.equal(await asks(), true);

        // A removal the studio refuses leaves the file where it was, so Save video below still has it.
        studio.refusesRemoval = true;
        await Promise.all([
            page.waitForResponse(response => response.request().method() === 'DELETE'),
            row('a').locator('button[data-act="remove"]').click(),
        ]);
        studio.refusesRemoval = false;
        assert.deepEqual(await told(), [false, true]);
        assert.equal(await asks(), true);

        // Saved from the sheet: nothing is left to lose.
        await page.evaluate(() => { window.__sheetClosedUnsaved = false; });
        await row('a').locator('button[data-act="share"]').click();
        await page.waitForFunction(() => window.__told.length === 3);
        assert.deepEqual(await told(), [false, true, false]);
        assert.equal(await asks(), false);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

run('Video Downloader says what happens over the limit, and a browser or a Mac Dock app keeps the plain file link', async () => {
    const browser = await launch();
    try {
        const { errors, row } = await openTool(browser, { jobs: [
            job('going', 'running', { optimize: true, percent: 33, downloaded: 100 * 1048576, total: 300 * 1048576 }),
            job('over', 'done', { optimize: true, optimized: 'too_large', size: 300 * 1048576 }),
            // Kept as it was under an earlier, lower limit: the limit of today does not explain it, so it is not named.
            job('earlier', 'done', { optimize: true, optimized: 'too_large', size: 57 * 1048576 }),
        ] });
        const said = async id => row(id).locator('.job-status').innerText();
        assert.match(await said('going'), /Downloading, 33% · 100 MB of 300 MB · over 250 MB, so it will be saved as it is$/);
        assert.match(await said('over'), /Ready · 300 MB · too large to make smaller \(over 250 MB\) · kept until /);
        assert.match(await said('earlier'), /Ready · 57 MB · too large to make smaller · kept until /);
        const link = row('over').locator('a.outline');
        assert.equal(await link.innerText(), 'Save to this device');
        assert.equal(await link.getAttribute('href'), origin + '/admin/home/video/jobs/over/file');
        assert.equal(await link.getAttribute('download'), 'over.mp4');
        assert.equal(await row('over').locator('button[data-act="optimize"]').count(), 0);
        assert.deepEqual(errors, []);

        // The site in the Mac Dock is installed too, but it downloads as a browser does: the link, not the share sheet.
        const dock = await openTool(browser, { app: 'dock', jobs: [job('done', 'done')] });
        assert.equal(await dock.row('done').locator('a.outline').getAttribute('download'), 'done.mp4');
        assert.equal(await dock.row('done').locator('button[data-act="save"]').count(), 0);
        assert.deepEqual(dock.errors, []);
    } finally { await browser.close(); }
});
