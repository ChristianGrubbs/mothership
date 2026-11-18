import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  calculateNormalizationPlan, composeBridgeCanvasPlan, calculateRepairWidth, calculateFeatherWidth,
  calculateRestoredBridgeSize, buildPrompt, REPAIR_PRESETS, findMinimumErrorSeam, calculateSeamSearchWidth, rampFill,
  shoulderAlpha, SHOULDER_FRACTION, resolveGenerationSettings,
  resolveExportOptions, describeProviderOutput, checkUploadSize, PROVIDER_OUTPUT_PIXELS,
} from '../Resources/assets/sphereseam/sphereseam.js';

test('auto generation settings pick the smallest resolution that covers the bridge and a matching quality', () => {
  // Quality/resolution "Auto" (operator request 2026-09-10): derive both from the bridge that is
  // actually sent, so a small source never pays for 4K and a large one never loses detail.
  // The output is scaled back down to the bridge, so the tier gives 2x headroom over the longest side:
  // 374 px -> 1K, the proven 665 px foundry bridge -> 2K (the verified default), 1200 px -> 4K.
  assert.deepEqual(resolveGenerationSettings({ width: 374, height: 374 }, 'auto', 'auto'), { quality: 'low', resolution: '1k' });
  assert.deepEqual(resolveGenerationSettings({ width: 300, height: 533 }, 'auto', 'auto'), { quality: 'medium', resolution: '2k' });
  assert.deepEqual(resolveGenerationSettings({ width: 665, height: 665 }, 'auto', 'auto'), { quality: 'high', resolution: '2k' });
  assert.deepEqual(resolveGenerationSettings({ width: 1200, height: 1200 }, 'auto', 'auto'), { quality: 'high', resolution: '4k' });
  assert.deepEqual(resolveGenerationSettings({ width: 5000, height: 5000 }, 'auto', 'auto'), { quality: 'high', resolution: '4k' });
  // Explicit choices pass through untouched; only the auto side is derived.
  assert.deepEqual(resolveGenerationSettings({ width: 374, height: 374 }, 'high', 'auto'), { quality: 'high', resolution: '1k' });
  assert.deepEqual(resolveGenerationSettings({ width: 5000, height: 5000 }, 'auto', '1k'), { quality: 'high', resolution: '1k' });
  assert.throws(() => resolveGenerationSettings({ width: 374, height: 374 }, 'xhigh', 'auto'));
  assert.throws(() => resolveGenerationSettings({ width: 374, height: 374 }, 'auto', '8k'));
});

test('band blanking ramp interpolates each row from the left edge colour to the right edge colour', () => {
  const left = Float32Array.from([0, 0, 0, 100, 50, 0]), right = Float32Array.from([200, 0, 0, 100, 50, 200]);
  const out = rampFill(left, right, 5);
  assert.equal(out.length, 2 * 5 * 3);
  assert.deepEqual([...out.subarray(0, 3)], [0, 0, 0]);          // row 0, x=0 = left
  assert.deepEqual([...out.subarray(6, 9)], [100, 0, 0]);        // row 0, x=2 = midpoint
  assert.deepEqual([...out.subarray(12, 15)], [200, 0, 0]);      // row 0, x=4 = right
  assert.deepEqual([...out.subarray(27, 30)], [100, 50, 200]);   // row 1, x=4 = right
  assert.throws(() => rampFill(left, right.subarray(0, 3), 5));
});

test('minimum-error seam follows the cheapest 8-connected vertical path', () => {
  // 5 wide x 4 tall; zero cost along x = 0,1,2,3 (a diagonal), 10 everywhere else.
  const w = 5, h = 4, cost = new Float32Array(w * h).fill(10);
  [0, 1, 2, 3].forEach((x, y) => { cost[y * w + x] = 0; });
  assert.deepEqual([...findMinimumErrorSeam(cost, w, h)], [0, 1, 2, 3]);
  // a cheap straight column wins over a cheaper-looking but disconnected scatter
  const c2 = new Float32Array(w * h).fill(10);
  for (let y = 0; y < h; y++) c2[y * w + 2] = 1;
  c2[0 * w + 0] = 0; c2[3 * w + 4] = 0;
  assert.deepEqual([...findMinimumErrorSeam(c2, w, h)], [2, 2, 2, 2]);
  assert.throws(() => findMinimumErrorSeam(new Float32Array(3), 2, 2));
});

test('default square bridge covers a quarter of the panorama with a wide band and real context on both sides', () => {
  const plan = composeBridgeCanvasPlan({ width: 1330, height: 665 });
  const b = plan.bridge;
  assert.equal(b.ratio, '1:1');
  assert.deepEqual([b.width, b.height], [665, 665]);
  assert.equal(b.left.width + b.right.width, b.width);
  assert.equal(b.repair.width, calculateRepairWidth(665, 'wide'));
  assert.ok(b.repair.width >= 360 && b.repair.width <= 370);
  assert.ok(b.repair.x >= 140, 'left context strip is wide enough to anchor the model');
  assert.ok(b.width - b.repair.x - b.repair.width >= 140, 'right context strip is wide enough to anchor the model');
  assert.deepEqual(calculateRestoredBridgeSize(665, '1:1'), { width: 665, height: 665 });
  assert.deepEqual(calculateRestoredBridgeSize(665, '3:4'), { width: 499, height: 665 });
});

test('seam search zones stay inside the outer 70% of each shoulder and never overlap', () => {
  assert.equal(calculateSeamSearchWidth(332), 58);   // 332 * 0.25 * 0.7
  assert.equal(calculateSeamSearchWidth(10), 8);
  const band = calculateRepairWidth(665, 'wide');
  assert.ok(calculateSeamSearchWidth(band) <= Math.round(band * SHOULDER_FRACTION));
  assert.ok(calculateSeamSearchWidth(band) * 2 <= band);
});

test('shoulder alpha keeps the original at both band edges, is fully blank in the middle, and is symmetric', () => {
  const a = shoulderAlpha(332, 0.25);
  assert.equal(a.length, 332);
  assert.equal(a[0], 0); assert.equal(a[331], 0);
  assert.equal(a[83], 1); assert.equal(a[166], 1); assert.equal(a[248], 1);
  assert.ok(a[41] > 0.4 && a[41] < 0.6, 'smoothstep midpoint');
  assert.ok(a[20] < a[40] && a[40] < a[60], 'monotonic rise');
  for (let i = 0; i < 332; i++) assert.ok(Math.abs(a[i] - a[331 - i]) < 1e-6);
  assert.deepEqual([...shoulderAlpha(6, 0)], [1, 1, 1, 1, 1, 1]);
});

function pngSize(path) {
  const bytes = readFileSync(new URL(path, import.meta.url));
  assert.deepEqual([...bytes.subarray(1, 4)], [80, 78, 71]);
  return { width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20) };
}

test('foundry fixture squeezes horizontally to exact 2:1 by default (no padding bands)', () => {
  const size = pngSize('./fixtures/01-foundry-1568x665.png');
  assert.deepEqual(size, { width: 1568, height: 665 });
  const plan = calculateNormalizationPlan(size);
  assert.equal(plan.mode, 'squeeze');
  assert.deepEqual(plan.target, { width: 1330, height: 665 });
  assert.equal(plan.horizontalPadding, 0);
  assert.equal(plan.verticalPadding, 0);
  assert.ok(plan.horizontalScale < 1 && plan.verticalScale === 1);
  assert.deepEqual(plan.sourcePlacement, { x: 0, y: 0, width: 1330, height: 665 });
});

test('second fixture squeezes vertically to exact 2:1 by default', () => {
  const size = pngSize('./fixtures/02-second-1356x757.png');
  const plan = calculateNormalizationPlan(size);
  assert.deepEqual(plan.target, { width: 1356, height: 678 });
  assert.equal(plan.horizontalScale, 1);
  assert.ok(plan.verticalScale < 1);
});

test('pad mode keeps the legacy non-scaling behaviour', () => {
  const plan = calculateNormalizationPlan({ width: 1568, height: 665 }, 'pad');
  assert.equal(plan.mode, 'pad');
  assert.deepEqual(plan.target, { width: 1568, height: 784 });
  assert.equal(plan.verticalPadding, 119);
  assert.deepEqual(plan.sourcePlacement, { x: 0, y: 59, width: 1568, height: 665 });
  const second = calculateNormalizationPlan({ width: 1356, height: 757 }, 'pad');
  assert.deepEqual(second.target, { width: 1514, height: 757 });
  assert.equal(second.horizontalPadding, 158);
});

test('exact 2:1 input is unchanged in both modes', () => {
  for (const mode of ['squeeze', 'pad']) {
    const plan = calculateNormalizationPlan({ width: 1536, height: 768 }, mode);
    assert.equal(plan.changed, false);
    assert.deepEqual(plan.target, { width: 1536, height: 768 });
    assert.deepEqual(plan.sourcePlacement, { x: 0, y: 0, width: 1536, height: 768 });
  }
});

test('9:16 bridge is a rolled crop centred on the wrap seam with no gap', () => {
  const plan = composeBridgeCanvasPlan({ width: 1536, height: 768 }, 'balanced', '9:16');
  const b = plan.bridge;
  assert.equal(b.width, 432);
  assert.equal(b.height, 768);
  // left part = last 216 panorama columns, right part = first 216 columns, abutting.
  assert.deepEqual(b.left, { x: 0, width: 216, source: { x: 1320, width: 216 } });
  assert.deepEqual(b.right, { x: 216, width: 216, source: { x: 0, width: 216 } });
  assert.equal(b.seamX, 216);
  assert.equal(b.left.width + b.right.width, b.width);
});

test('repair band is centred, even, and leaves locked context on both sides', () => {
  const b = composeBridgeCanvasPlan({ width: 1536, height: 768 }, 'balanced', '9:16').bridge;
  assert.equal(b.repair.width, calculateRepairWidth(432, 'balanced'));
  assert.equal(b.repair.width, 172); // round(432*0.4)=173 → even 172
  assert.equal(b.repair.x, 130);
  assert.equal(b.lockedLeft.width, 130);
  assert.equal(b.lockedRight.width, 130);
  assert.equal(b.lockedLeft.width + b.repair.width + b.lockedRight.width, b.width);
  assert.ok(Math.abs(b.repair.fraction - REPAIR_PRESETS.balanced) < 0.01);
});

test('repair presets and custom widths are clamped to 10%..70% of the bridge', () => {
  assert.equal(calculateRepairWidth(432, 'narrow'), 108);
  assert.equal(calculateRepairWidth(432, 'wide'), 238);
  assert.equal(calculateRepairWidth(432, 'custom', 4), 42);   // 10% floor → even
  assert.equal(calculateRepairWidth(432, 'custom', 9999), 302); // 70% ceiling → even
  assert.equal(calculateRepairWidth(432, 'custom', 200), 200);
  assert.equal(calculateFeatherWidth(172), 34);
});

test('odd bridge widths split into a half and a half-plus-one', () => {
  const b = composeBridgeCanvasPlan({ width: 1568, height: 784 }, 'balanced', '9:16').bridge;
  assert.equal(b.width, 441);
  assert.equal(b.left.width, 220);
  assert.equal(b.right.width, 221);
  assert.equal(b.left.source.x, 1568 - 220);
});

test('a bridge wider than the panorama is rejected', () => {
  assert.throws(() => composeBridgeCanvasPlan({ width: 64, height: 32 }, 'balanced', '21:9'), /wider than the panorama/);
});

test('provider output restores to local bridge geometry before reinsertion', () => {
  assert.deepEqual(calculateRestoredBridgeSize(784, '9:16'), { width: 441, height: 784 });
  assert.deepEqual(calculateRestoredBridgeSize(784, '16:27'), { width: 465, height: 784 });
  assert.deepEqual(calculateRestoredBridgeSize(665, '9:16'), { width: 374, height: 665 });
});

test('prompt describes the rolled seam crop and confines edits to the central band', () => {
  const prompt = buildPrompt('foundry interior', 0.4);
  assert.match(prompt, /across the horizontal wrap seam/i);
  assert.match(prompt, /left part is the far-right edge/i);
  assert.match(prompt, /blurred vertical band .* about 40% of its width, is an unfinished placeholder/i);
  assert.match(prompt, /completed rather than replaced/i);
  assert.match(prompt, /add no new light source/i);
  assert.match(prompt, /Keep every pixel outside the band identical/i);
  assert.match(prompt, /Scene context: foundry interior\./);
  assert.doesNotMatch(prompt, /empty middle region/i);
});

// ---------------------------------------------------------------------------
// 2026-09-16 save/resolution fix set. These three helpers are pure on purpose:
// the editor used to compute all of this inline in editor.blade.php, where no
// suite could reach it, and that is where both resolution bugs lived.
// ---------------------------------------------------------------------------

test('a 4096 bridge asks the provider for 4K, so the repaired band is never upscaled into the panorama', () => {
  // B3: the generated band is drawn into the LOCAL bridge size. A 4096x4096 bridge fed by a 2K
  // provider result is a 2x upscale of the only pixels the operator paid for. The Auto rule
  // already gives 2x headroom (needed = longest * 2), but the editor evaluated it once against a
  // stale/empty bridge, so a full-size panorama could still be submitted at 1K.
  assert.deepEqual(resolveGenerationSettings({ width: 4096, height: 4096 }, 'auto', 'auto'), { quality: 'high', resolution: '4k' });
  assert.deepEqual(resolveGenerationSettings({ width: 2048, height: 2048 }, 'auto', 'auto'), { quality: 'high', resolution: '4k' });
  assert.deepEqual(resolveGenerationSettings({ width: 1024, height: 1024 }, 'auto', 'auto'), { quality: 'high', resolution: '2k' });
});

test('provider output note states the tier pixels and the band upscale the operator is paying for', () => {
  assert.deepEqual(PROVIDER_OUTPUT_PIXELS, { '1k': 1024, '2k': 2048, '4k': 4096 });

  const big = describeProviderOutput({ width: 4096, height: 4096 }, '4k');
  assert.equal(big.outputPixels, 4096);
  assert.equal(big.upscale, 1);
  assert.match(big.label, /Provider output ≈ 4096 px/);
  assert.match(big.label, /no band upscale/);

  // The exact case the operator hit: a full-size bridge answered at 2K.
  const upscaled = describeProviderOutput({ width: 4096, height: 4096 }, '2k');
  assert.equal(upscaled.outputPixels, 2048);
  assert.equal(upscaled.upscale, 2);
  assert.match(upscaled.label, /band upscale ×2/);

  // The proven 665 px foundry bridge at 2K is a downscale, never reported as an upscale.
  const small = describeProviderOutput({ width: 665, height: 665 }, '2k');
  assert.equal(small.upscale, 1);
  assert.match(small.label, /no band upscale/);

  assert.equal(describeProviderOutput({ width: 0, height: 0 }, '2k'), null);
  assert.equal(describeProviderOutput({ width: 665, height: 665 }, 'auto'), null);
});

test('the export select always offers the true original first and drops duplicate fixed widths', () => {
  // B2: the select only ever offered preparedWidth / 4096 / 8192. A 6000 px panorama matched no
  // option, the select rendered empty, and the operator's first click silently chose 4096.
  const original = resolveExportOptions(6000);
  assert.equal(original[0].value, 6000);
  assert.match(original[0].label, /Original 6000×3000/);
  assert.deepEqual(original.map(option => option.value), [6000, 4096, 8192]);

  // An 8192 panorama must not be listed twice, and "Original" must be the 8192 entry.
  const exact = resolveExportOptions(8192);
  assert.deepEqual(exact.map(option => option.value), [8192, 4096]);
  assert.match(exact[0].label, /Original 8192×4096/);
  assert.equal(exact.filter(option => option.value === 8192).length, 1);

  assert.deepEqual(resolveExportOptions(4096).map(option => option.value), [4096, 8192]);
  // Nothing prepared yet: no misleading "Original 4096" entry.
  assert.deepEqual(resolveExportOptions(0).map(option => option.value), [4096, 8192]);
  assert.deepEqual(resolveExportOptions(null).map(option => option.value), [4096, 8192]);
});

test('the upload pre-check names the real byte size and the real limit instead of "field is required"', () => {
  // A2: PHP silently drops a body over post_max_size, so Laravel reported the panorama as missing.
  const limit = 34 * 1024 * 1024;
  assert.deepEqual(checkUploadSize(1024, limit, 'normalized panorama'), { ok: true, message: '' });
  assert.deepEqual(checkUploadSize(limit, limit, 'normalized panorama'), { ok: true, message: '' });

  const over = checkUploadSize(41.3 * 1024 * 1024, limit, 'normalized panorama');
  assert.equal(over.ok, false);
  assert.match(over.message, /normalized panorama/);
  assert.match(over.message, /41\.3 MB/);
  assert.match(over.message, /34\.0 MB/);
  assert.doesNotMatch(over.message, /required/i);
});

test('the editor\'s Tailwind utilities are scanned and present in the committed admin bundle', async () => {
  // 2026-09-13: this package keeps its views at <package>/Resources (no src/), outside the Admin
  // theme's original content globs, so the three-column grid and sticky panes were never generated
  // and the editor rendered as one long column. Both halves must hold: the globs cover this path,
  // and the committed bundle production serves actually contains the utilities the template uses.
  const { createRequire } = await import('node:module');
  const config = createRequire(import.meta.url)('../../Admin/tailwind.config.js');
  assert.ok(config.content.includes('../*/Resources/**/*.blade.php'), 'package-root Resources views are scanned');
  assert.ok(config.content.includes('../*/Resources/**/*.js'), 'package-root Resources scripts are scanned');

  const root = new URL('../../../../', import.meta.url);
  const manifest = JSON.parse(readFileSync(new URL('public/themes/admin/default/build/manifest.json', root), 'utf8'));
  const css = readFileSync(new URL(`public/themes/admin/default/build/${manifest['src/Resources/assets/css/app.css'].file}`, root), 'utf8');
  const editor = readFileSync(new URL('../Resources/views/editor.blade.php', import.meta.url), 'utf8');
  for (const utility of ['xl:grid-cols-[330px_minmax(0,1fr)_300px]', 'xl:h-[calc(100vh-128px)]', 'min-h-[calc(100vh-96px)]', 'min-h-[520px]', 'max-h-[68vh]']) {
    assert.ok(editor.includes(utility), `template still uses ${utility}`);
    const selector = '.' + utility.replace(/[:[\]()%,.]/g, match => '\\' + match);
    assert.ok(css.includes(selector), `bundle contains ${utility}`);
  }
});
