// The Docking entrance on the anonymous pages runs the real docking.js against a small page shaped like sign-in:
// the board plate must wait until the mark is visible, and a browser that refuses part of the flip must still show
// the finished board text.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { launchBrowser } = require('../launch-browser.cjs');

const root = path.resolve(__dirname, '../../..');
const modulePath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const playwrightTest = modulePath ? test : test.skip;

const docking = fs
    .readFileSync(path.join(root, 'packages/Mothership/Admin/src/Resources/assets/js/docking.js'), 'utf8')
    .replace('export function runDocking', 'window.runDocking = function runDocking');

const page = ({ patch }) => `<!doctype html><html class="dock-armed"><head><script>${patch}</script></head><body>
    <div><div id="mark" data-dock="mark">mark</div>
    <div id="plate" data-dock="rise" data-flap><div class="bridge-frow"><i>H</i><i>I</i></div></div></div>
    <div id="app">${'<p data-dock="rise">line</p>'.repeat(5)}</div>
</body></html>`;

async function withPage(run) {
    const browser = await launchBrowser({ playwrightPath: modulePath });

    try {
        await run(await browser.newPage());
    } finally {
        await browser.close();
    }
}

playwrightTest('keeps the board plate hidden until the mark has arrived', async () => {
    await withPage(async (tab) => {
        await tab.setContent(page({
            patch: `window.seen = []; const animate = Element.prototype.animate;
                Element.prototype.animate = function (...args) { const a = animate.apply(this, args); a.pause(); window.seen.push(a); return a; };`,
        }));
        await tab.addScriptTag({ content: docking });

        const samples = await tab.evaluate(() => {
            window.runDocking();

            const opacity = (id) => Number(getComputedStyle(document.getElementById(id)).opacity);
            const taken = [];

            for (let time = 0; time <= 1200; time += 20) {
                window.seen.forEach((animation) => { animation.currentTime = time; });
                taken.push({ time, mark: opacity('mark'), plate: opacity('plate') });
            }

            return taken;
        });

        const early = samples.filter((sample) => sample.plate > 0 && sample.mark < 0.99);

        assert.deepEqual(early, [], 'the plate must not show before the mark is fully visible');
        assert.equal(samples.at(-1).plate, 1);
    });
});

playwrightTest('shows the finished board when the browser refuses part of the flip', async () => {
    await withPage(async (tab) => {
        await tab.setContent(page({
            patch: `let strips = 0; const animate = Element.prototype.animate;
                Element.prototype.animate = function (...args) {
                    if (this.className === 'dock-strip' && ++strips === 2) { throw new Error('refused'); }
                    return animate.apply(this, args);
                };`,
        }));
        await tab.addScriptTag({ content: docking });

        const state = await tab.evaluate(() => {
            window.runDocking();

            return {
                text: [...document.querySelectorAll('.bridge-frow i')].map((cell) => cell.textContent).join(''),
                strips: document.querySelectorAll('.dock-strip').length,
                armed: document.documentElement.classList.contains('dock-armed'),
            };
        });

        assert.deepEqual(state, { text: 'HI', strips: 0, armed: false });
    });
});
