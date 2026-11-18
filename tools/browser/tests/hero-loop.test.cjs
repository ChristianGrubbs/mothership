// The sign-in hero loop runs the real hero-loop.js against the real hero markup. The ship must travel home on screen
// in all three acts, never jumping or vanishing, and the loop must end on exactly the frame it starts from.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { launchBrowser } = require('../launch-browser.cjs');

const root = path.resolve(__dirname, '../../..');
const assets = path.join(root, 'packages/Mothership/Admin/src/Resources');
const modulePath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const playwrightTest = modulePath ? test : test.skip;

const LOOP_MS = 14900;
const START_DELAY_MS = 1300;
const AWAY_FROM_MS = 8300;
const HOME_MS = 12000;

const script = fs
    .readFileSync(path.join(assets, 'assets/js/hero-loop.js'), 'utf8')
    .replace('export function runHeroLoop', 'window.runHeroLoop = function runHeroLoop');

const svg = fs
    .readFileSync(path.join(assets, 'views/components/bridge/hero-mark.blade.php'), 'utf8')
    .replace(/\{\{--.*?--\}\}/s, '')
    .replace(/\{\{.*?\}\}/s, 'class="bridge-hero-mark"');

const css = `svg { width: 300px; height: 300px; margin: 300px; overflow: visible; }
    [data-part=ship] { transform-box: view-box; transform-origin: 519px 555px; }`;

// The sign-in panel as it clips the hero: full height on a tall screen, a 318 px strip above the form on a phone.
const panelCss = `body { margin: 0; }
    .bridge-signin-hero { position: fixed; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; }
    .bridge-hero-mark { flex: none; width: 300px; height: auto; overflow: visible; }
    .board { flex: none; height: 86px; }
    @media (max-height: 500px) { .bridge-hero-mark { width: 168px; } }
    [data-part=sky] { transform-box: view-box; transform-origin: 519px 555px; }`;
const panelBody = `<div class="bridge-signin-hero">${svg}<div class="board"></div></div>`;

async function withPage({ reducedMotion, viewport = { width: 900, height: 900 }, styles = css, body = svg }, run) {
    const browser = await launchBrowser({ playwrightPath: modulePath });

    try {
        const context = await browser.newContext({ viewport, reducedMotion });
        const page = await context.newPage();

        await page.setContent(`<!doctype html><html><head><style>${styles}</style></head><body>${body}</body></html>`);
        await page.addScriptTag({ content: script });
        await run(page);
    } finally {
        await browser.close();
    }
}

const startPaused = () => {
    window.runHeroLoop();
    window.loop = document.getAnimations();
    window.loop.forEach((animation) => animation.pause());

    return window.loop.length;
};

playwrightTest('flies the ship home on screen in every act', async () => {
    await withPage({ reducedMotion: 'no-preference' }, async (page) => {
        assert.ok(await page.evaluate(startPaused) > 0);

        for (const act of [0, 1, 2]) {
            const flight = await page.evaluate(({ from, to }) => {
                const ship = document.querySelector('[data-part="ship"]');
                const samples = [];

                for (let time = from; time <= to; time += 20) {
                    window.loop.forEach((animation) => { animation.currentTime = time; });

                    const box = ship.getBoundingClientRect();

                    samples.push({ time, x: box.x + box.width / 2, y: box.y + box.height / 2, opacity: Number(getComputedStyle(ship).opacity) });
                }

                return samples;
            }, { from: START_DELAY_MS + act * LOOP_MS + AWAY_FROM_MS, to: START_DELAY_MS + act * LOOP_MS + HOME_MS });

            flight.forEach((sample, index) => {
                assert.equal(sample.opacity, 1, `act ${act}: the ship is hidden at ${sample.time} ms`);

                if (index > 0) {
                    const jump = Math.hypot(sample.x - flight[index - 1].x, sample.y - flight[index - 1].y);

                    assert.ok(jump < 60, `act ${act}: the ship jumps ${Math.round(jump)} px at ${sample.time} ms`);
                }
            });
        }
    });
});

playwrightTest('ends the three acts on the frame they start from', async () => {
    await withPage({ reducedMotion: 'no-preference' }, async (page) => {
        await page.evaluate(startPaused);

        const frames = await page.evaluate(({ start, end }) => {
            const read = (time) => {
                window.loop.forEach((animation) => { animation.currentTime = time; });

                return window.loop.map((animation) => {
                    const style = getComputedStyle(animation.effect.target);
                    let seen = 1;

                    for (let element = animation.effect.target; element; element = element.parentElement) {
                        seen *= Number(getComputedStyle(element).opacity);
                    }

                    return seen === 0 ? 'unseen' : `${style.transform}|${seen.toFixed(2)}`;
                });
            };

            return { first: read(start), last: read(end) };
        }, { start: START_DELAY_MS, end: START_DELAY_MS + 3 * LOOP_MS - 0.01 });

        const numbers = (text) => (text.match(/-?\d+\.?\d*(e-?\d+)?/g) ?? []).map(Number);

        frames.first.forEach((frame, index) => {
            const [first, last] = [numbers(frame), numbers(frames.last[index])];

            assert.equal(first.length, last.length, `animation ${index}: ${frame} became ${frames.last[index]}`);
            first.forEach((value, place) => {
                assert.ok(Math.abs(value - last[place]) < 0.02, `animation ${index}: ${frame} became ${frames.last[index]}`);
            });
        });
    });
});

playwrightTest('keeps the still hero when the visitor prefers reduced motion', async () => {
    await withPage({ reducedMotion: 'reduce' }, async (page) => {
        assert.equal(await page.evaluate(startPaused), 0);
    });
});

const topClearance = (page) => page.evaluate(async ({ times }) => {
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

    const panel = document.querySelector('.bridge-signin-hero').getBoundingClientRect();

    return times.map(({ selector, time }) => {
        window.loop.forEach((animation) => { animation.currentTime = time; });

        return document.querySelector(selector).getBoundingClientRect().top - panel.top;
    });
}, {
    times: [
        { selector: '[data-nova="core"]', time: START_DELAY_MS + 9300 },
        { selector: '[data-hole="body"]', time: START_DELAY_MS + LOOP_MS + 9300 },
        { selector: '[data-moon="body"]', time: START_DELAY_MS + 2 * LOOP_MS + 9300 },
    ],
});

playwrightTest('keeps every mishap inside the panel, including after the panel shrinks', async () => {
    await withPage({ reducedMotion: 'no-preference', viewport: { width: 1440, height: 1080 }, styles: panelCss, body: panelBody }, async (page) => {
        await page.evaluate(startPaused);

        (await topClearance(page)).forEach((top) => assert.ok(top >= 0, `clipped ${Math.round(-top)} px on the tall panel`));

        await page.setViewportSize({ width: 390, height: 318 });

        (await topClearance(page)).forEach((top) => assert.ok(top >= 0, `clipped ${Math.round(-top)} px after shrinking`));
    });
});

playwrightTest('stops the loop when the visitor turns on reduced motion', async () => {
    await withPage({ reducedMotion: 'no-preference' }, async (page) => {
        await page.evaluate(() => window.runHeroLoop());
        assert.ok(await page.evaluate(() => document.getAnimations().length) > 0);

        await page.emulateMedia({ reducedMotion: 'reduce' });

        await page.waitForFunction(() => document.getAnimations().length === 0, null, { timeout: 2000 });
    });
});

playwrightTest('waits for the tab to be shown when the page loads hidden', async () => {
    await withPage({ reducedMotion: 'no-preference' }, async (page) => {
        const states = await page.evaluate(() => {
            Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
            window.runHeroLoop();

            return [...new Set(document.getAnimations().map((animation) => animation.playState))];
        });

        assert.deepEqual(states, ['paused']);
    });
});
