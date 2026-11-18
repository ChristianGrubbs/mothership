// SphereSeam browser geometry.
//
// Design (2026-09-10 revision, after the failed foundry proof):
//   1. Normalize to exact 2:1 by squeezing the over-long axis ("squeeze", default)
//      or by padding ("pad"). Nothing is ever cropped.
//   2. Build the seam bridge as a ROLLED CROP: the panorama's far-right columns on the
//      left, its far-left columns on the right, abutting with no gap. The bridge is a
//      plain image with one visible vertical discontinuity down its centre.
//   3. Only a centred "repair band" is meant to change. Before upload the band is
//      BLANKED to a structureless tonal ramp (blankRepairBand): reference-guided models
//      otherwise copy the hard centre discontinuity instead of removing it. Higgsfield's
//      GPT Image models expose no mask role, so the band is a prompt instruction.
//   4. After generation, every pixel OUTSIDE the repair band is restored from the
//      original bridge (immutable). The generated band is joined to those locked
//      pixels along a minimum-error cut and the bridge is un-rolled into the panorama.

export const REPAIR_PRESETS = { narrow: 0.25, balanced: 0.4, wide: 0.55 };

export const QUALITY_OPTIONS = ['auto', 'low', 'medium', 'high'];
export const RESOLUTION_OPTIONS = ['auto', '1k', '2k', '4k'];

// "Auto" quality/resolution (operator request 2026-09-10). Both derive from the bridge that is
// actually sent to Higgsfield. The generated bridge is scaled back down to the local bridge, so
// the output tier only needs 2x headroom over the bridge's longest side: a 374 px 9:16 bridge
// runs at 1K, the proven 665 px square foundry bridge at 2K (the verified default), 1200 px+ at 4K.
// Quality follows the amount of real detail in the bridge: tiny bridges gain nothing from high.
export function resolveGenerationSettings(bridgeSize, quality = 'auto', resolution = 'auto') {
  if (!QUALITY_OPTIONS.includes(quality)) throw new Error(`Unsupported quality "${quality}".`);
  if (!RESOLUTION_OPTIONS.includes(resolution)) throw new Error(`Unsupported resolution "${resolution}".`);
  const longest = Math.max(1, Math.round(Math.max(bridgeSize.width || 0, bridgeSize.height || 0)));
  if (resolution === 'auto') {
    const needed = longest * 2;
    resolution = needed <= 1024 ? '1k' : needed <= 2048 ? '2k' : '4k';
  }
  if (quality === 'auto') quality = longest < 400 ? 'low' : longest < 600 ? 'medium' : 'high';
  return { quality, resolution };
}

// Pixels each provider tier actually returns on the longest side. The generated bridge is drawn
// back into the LOCAL bridge size (reinsertGeneratedBridge), so anything the tier does not cover
// is an upscale of the only pixels the operator paid for. Surfaced in the editor next to the
// resolution control so that trade is visible before submission, not after (2026-09-16).
export const PROVIDER_OUTPUT_PIXELS = { '1k': 1024, '2k': 2048, '4k': 4096 };

export function describeProviderOutput(bridgeSize, resolution) {
  const outputPixels = PROVIDER_OUTPUT_PIXELS[resolution];
  const longest = Math.max(Math.round(bridgeSize?.width || 0), Math.round(bridgeSize?.height || 0));
  if (!outputPixels || longest < 1) return null;
  // Below 1 the provider result is downscaled into the bridge, which costs no detail.
  const raw = longest / outputPixels;
  const upscale = raw <= 1 ? 1 : Math.round(raw * 10) / 10;
  return {
    outputPixels,
    upscale,
    label: `Provider output ≈ ${outputPixels} px for a ${longest} px bridge · ${upscale === 1 ? 'no band upscale' : `band upscale ×${upscale}`}.`,
  };
}

// Export widths the editor offers. The prepared panorama's own width is always first and always
// present: before 2026-09-16 the select offered a stale preparedWidth plus a hardcoded 4096/8192,
// so a panorama of any other width matched no option and the first click silently downscaled it.
export function resolveExportOptions(preparedWidth, fixed = [4096, 8192]) {
  const original = Math.round(Number(preparedWidth) || 0);
  const options = [];
  if (original >= 2) options.push({ value: original, label: `Original ${original}×${Math.round(original / 2)}` });
  for (const width of fixed) {
    if (width === original) continue;
    options.push({ value: width, label: `${width}×${Math.round(width / 2)}` });
  }
  return options;
}

// Client-side upload guard. PHP discards a body over post_max_size before Laravel sees it, so an
// oversized panorama surfaced as "The panorama field is required" — a message that named neither
// the size nor the limit. Checked against the same ceiling the server validates.
export function checkUploadSize(byteLength, limitBytes, label) {
  const bytes = Number(byteLength) || 0;
  const limit = Number(limitBytes) || 0;
  if (bytes <= limit) return { ok: true, message: '' };
  const mb = value => (value / (1024 * 1024)).toFixed(1);
  return {
    ok: false,
    message: `The ${label} encodes to ${mb(bytes)} MB, over this server's ${mb(limit)} MB upload limit. Export it locally instead, or prepare a smaller panorama.`,
  };
}

export function calculateNormalizationPlan(source, mode = 'squeeze') {
  assertSize(source);
  const wide = source.width > source.height * 2;
  let target;
  if (mode === 'pad') {
    target = wide
      ? { width: Math.ceil(source.width / 2) * 2, height: Math.ceil(source.width / 2) }
      : { width: source.height * 2, height: source.height };
  } else {
    // squeeze: keep the axis that is already short enough, shrink the over-long one.
    target = wide
      ? { width: source.height * 2, height: source.height }
      : { width: Math.floor(source.width / 2) * 2, height: Math.floor(source.width / 2) };
  }
  const changed = target.width !== source.width || target.height !== source.height;
  const scaled = mode !== 'pad' && changed;
  const placement = scaled
    ? { x: 0, y: 0, width: target.width, height: target.height }
    : { x: Math.floor((target.width - source.width) / 2), y: Math.floor((target.height - source.height) / 2), width: source.width, height: source.height };
  return {
    mode: mode === 'pad' ? 'pad' : 'squeeze',
    source, target, sourcePlacement: placement, changed,
    horizontalPadding: scaled ? 0 : target.width - source.width,
    verticalPadding: scaled ? 0 : target.height - source.height,
    horizontalScale: scaled ? target.width / source.width : 1,
    verticalScale: scaled ? target.height / source.height : 1,
  };
}

// Bridge = rolled crop of `ratio` centred on the wrap seam. Left part is the panorama's
// last `leftWidth` columns, right part is its first `rightWidth` columns.
export function composeBridgeCanvasPlan(size, repair = 'wide', ratio = '1:1', customRepairWidth) {
  assertSize(size);
  const [rw, rh] = String(ratio).split(':').map(Number);
  if (!(rw > 0) || !(rh > 0)) throw new Error(`Unsupported bridge ratio ${ratio}.`);
  const width = Math.max(2, Math.round(size.height * rw / rh));
  if (width > size.width) throw new Error('The seam bridge is wider than the panorama.');
  const leftWidth = Math.floor(width / 2), rightWidth = width - leftWidth;
  const repairWidth = calculateRepairWidth(width, repair, customRepairWidth);
  const repairX = Math.floor((width - repairWidth) / 2);
  return {
    panoramaSize: size,
    bridge: {
      width, height: size.height, ratio,
      left: { x: 0, width: leftWidth, source: { x: size.width - leftWidth, width: leftWidth } },
      right: { x: leftWidth, width: rightWidth, source: { x: 0, width: rightWidth } },
      seamX: leftWidth,
      repair: { x: repairX, width: repairWidth, fraction: repairWidth / width },
      lockedLeft: { x: 0, width: repairX },
      lockedRight: { x: repairX + repairWidth, width: width - repairX - repairWidth },
    },
  };
}

export function calculateRepairWidth(bridgeWidth, repair = 'balanced', customRepairWidth) {
  const requested = repair === 'custom' && Number.isFinite(customRepairWidth)
    ? Math.round(customRepairWidth)
    : Math.round(bridgeWidth * (REPAIR_PRESETS[repair] ?? REPAIR_PRESETS.balanced));
  // Never let the band eat the locked context: 10% .. 70% of the bridge, and even.
  const min = Math.max(16, Math.round(bridgeWidth * 0.1)), max = Math.round(bridgeWidth * 0.7);
  return Math.floor(clamp(requested, min, max) / 2) * 2;
}

export function calculateFeatherWidth(repairWidth) { return Math.max(6, Math.round(repairWidth * 0.2)); }

export function buildPrompt(scene = '', repairFraction = REPAIR_PRESETS.wide) {
  const percent = Math.round(repairFraction * 100);
  return [
    `The soft blurred vertical band in the centre of this image, about ${percent}% of its width, is an unfinished placeholder that must be painted in.`,
    'The image is a crop taken across the horizontal wrap seam of a 360° equirectangular VR panorama.',
    'Its left part is the far-right edge of the panorama and its right part is the far-left edge, so the band is where the panorama wraps around.',
    scene.trim() ? `Scene context: ${scene.trim()}.` : 'Infer the environment only from what is visible.',
    'Continue the visible objects, structures, bodies, and surfaces naturally into the placeholder; preserve their geometry, perspective, and occlusion, so anything partly visible at either edge of the band is completed rather than replaced.',
    'Paint plausible content in the middle so the left and right sides become one continuous scene.',
    'Maintain the surrounding exposure and illumination exactly; add no new light source, window, flame, or lamp. Match haze, object scale, lens distortion, texture grain, and material continuity.',
    'Keep every pixel outside the band identical to the input: same framing, same crop, same aspect ratio, no zoom, no rotation, no mirroring, no restyling.',
    'No visible vertical edges or seams anywhere. Do not add text, logos, UI marks, new people, or new focal objects.',
  ].join(' ');
}

export function preparePanorama(image, options = {}) {
  const mode = options.normalization === 'pad' ? 'pad' : 'squeeze';
  const fill = options.fill || 'edge';
  const plan = calculateNormalizationPlan({ width: image.naturalWidth || image.width, height: image.naturalHeight || image.height }, mode);
  const panorama = canvas(plan.target.width, plan.target.height);
  const ctx = context(panorama);
  const p = plan.sourcePlacement;
  if (mode === 'pad' && fill === 'edge' && plan.changed) {
    if (p.x > 0) ctx.drawImage(image, 0, 0, 1, plan.source.height, 0, p.y, p.x, plan.source.height);
    const right = p.x + plan.source.width;
    if (right < plan.target.width) ctx.drawImage(image, plan.source.width - 1, 0, 1, plan.source.height, right, p.y, plan.target.width - right, plan.source.height);
    if (p.y > 0) ctx.drawImage(image, 0, 0, plan.source.width, 1, p.x, 0, plan.source.width, p.y);
    const bottom = p.y + plan.source.height;
    if (bottom < plan.target.height) ctx.drawImage(image, 0, plan.source.height - 1, plan.source.width, 1, p.x, bottom, plan.source.width, plan.target.height - bottom);
  }
  ctx.drawImage(image, 0, 0, plan.source.width, plan.source.height, p.x, p.y, p.width, p.height);
  const bridgePlan = composeBridgeCanvasPlan(plan.target, options.repair || 'wide', options.ratio || '1:1', options.customRepairWidth);
  const bridge = composeBridge(panorama, bridgePlan);
  const bridgeInput = blankRepairBand(bridge, bridgePlan);
  return { panorama, bridge, bridgeInput, bridgeGuide: createBridgeGuide(bridge, bridgePlan), normalization: plan, bridgePlan };
}

// What is actually sent to the model. Verified 2026-09-10 (runs n2, n3, g6 vs t1-t5):
// every reference-guided model, masked or not, copies the hard centre discontinuity
// when the band still contains it. Replacing the band with a structureless tonal ramp
// (blurred edge colours from each side, interpolated across) removes the thing to copy,
// so the model has to invent a connecting scene. The original bridge is kept locally
// for reinsertion; only this version leaves the browser.
//
// Shoulders (review with GPT Astra, 2026-09-10 1:05 PM): a hard-edged blank erased every
// trace of objects cut off at the band edge (half a gear, a worker's back), so the model
// invented unrelated architecture and the cut landed on an amputated silhouette. The outer
// SHOULDER_FRACTION of the band on each side now fades original→ramp with a smoothstep,
// so cut-off objects stay visible for continuation while the middle stays fully blank and
// still carries no seam to copy. The seam cut is confined to those shoulders.
export const SHOULDER_FRACTION = 0.25;

export function blankRepairBand(bridge, bridgePlan, options = {}) {
  const r = bridgePlan.bridge.repair, w = bridge.width, h = bridge.height;
  const sample = Math.max(4, Math.min(options.sampleWidth ?? 24, r.x, w - r.x - r.width));
  const src = context(bridge).getImageData(0, 0, w, h).data;
  const left = columnMeans(src, w, h, r.x - sample, sample), right = columnMeans(src, w, h, r.x + r.width, sample);
  const ramp = rampFill(left, right, r.width);
  const band = canvas(r.width, h), bctx = context(band), image = bctx.createImageData(r.width, h);
  for (let y = 0; y < h; y++) for (let x = 0; x < r.width; x++) {
    const p = (y * r.width + x) * 4, q = (y * r.width + x) * 3;
    image.data[p] = ramp[q]; image.data[p + 1] = ramp[q + 1]; image.data[p + 2] = ramp[q + 2]; image.data[p + 3] = 255;
  }
  bctx.putImageData(image, 0, 0);
  // Blur the ramp itself (not the composited result), then composite over the original
  // with the shoulder alpha so the outside pixels and the shoulder edges stay exact.
  const blurred = canvas(r.width, h), blctx = context(blurred);
  blctx.filter = `blur(${options.blur ?? 20}px)`; blctx.drawImage(band, 0, 0); blctx.filter = 'none';
  const rampData = blctx.getImageData(0, 0, r.width, h).data;
  const alpha = shoulderAlpha(r.width, options.shoulder ?? SHOULDER_FRACTION);
  const out = canvas(w, h), ctx = context(out); ctx.drawImage(bridge, 0, 0);
  const patch = ctx.getImageData(r.x, 0, r.width, h), d = patch.data;
  for (let y = 0; y < h; y++) for (let x = 0; x < r.width; x++) {
    const p = (y * r.width + x) * 4, a = alpha[x];
    d[p] += (rampData[p] - d[p]) * a; d[p + 1] += (rampData[p + 1] - d[p + 1]) * a; d[p + 2] += (rampData[p + 2] - d[p + 2]) * a; d[p + 3] = 255;
  }
  ctx.putImageData(patch, r.x, 0);
  return out;
}

// Pure: per-column weight of the ramp inside the band. 0 at both band edges (original
// shows through), smoothstep up to 1 at the inner edge of each shoulder, 1 across the middle.
export function shoulderAlpha(width, fraction = SHOULDER_FRACTION) {
  const f = Math.max(0, Math.min(0.5, fraction)), fw = Math.round(width * f), out = new Float32Array(width).fill(1);
  for (let i = 0; i < fw; i++) {
    const t = fw <= 1 ? 1 : i / (fw - 1), s = t * t * (3 - 2 * t);
    out[i] = s; out[width - 1 - i] = s;
  }
  return out;
}

// Per-row mean colour of a column window, smoothed vertically with a 25-row box.
export function columnMeans(data, width, height, x0, count) {
  const raw = new Float32Array(height * 3);
  for (let y = 0; y < height; y++) for (let i = 0; i < count; i++) {
    const p = (y * width + x0 + i) * 4;
    raw[y * 3] += data[p] / count; raw[y * 3 + 1] += data[p + 1] / count; raw[y * 3 + 2] += data[p + 2] / count;
  }
  const out = new Float32Array(height * 3), k = 12;
  for (let y = 0; y < height; y++) {
    const lo = Math.max(0, y - k), hi = Math.min(height - 1, y + k);
    for (let c = 0; c < 3; c++) { let s = 0; for (let j = lo; j <= hi; j++) s += raw[j * 3 + c]; out[y * 3 + c] = s / (hi - lo + 1); }
  }
  return out;
}

// Pure: row-major RGB (Uint8ClampedArray, height*width*3) interpolating left→right per row.
export function rampFill(left, right, width) {
  const height = left.length / 3;
  if (!(width >= 1) || right.length !== left.length || !Number.isInteger(height)) throw new Error('Invalid ramp inputs.');
  const out = new Uint8ClampedArray(height * width * 3);
  for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
    const t = width === 1 ? 0.5 : x / (width - 1), q = (y * width + x) * 3;
    for (let c = 0; c < 3; c++) out[q + c] = left[y * 3 + c] * (1 - t) + right[y * 3 + c] * t;
  }
  return out;
}

export function composeBridge(panorama, bridgePlan) {
  const b = bridgePlan.bridge, out = canvas(b.width, b.height), ctx = context(out);
  ctx.drawImage(panorama, b.left.source.x, 0, b.left.width, b.height, b.left.x, 0, b.left.width, b.height);
  ctx.drawImage(panorama, b.right.source.x, 0, b.right.width, b.height, b.right.x, 0, b.right.width, b.height);
  return out;
}

// Preview only: tints the repair band so the operator sees what the prompt asks to change.
export function createBridgeGuide(bridge, bridgePlan) {
  const out = canvas(bridge.width, bridge.height), ctx = context(out); ctx.drawImage(bridge, 0, 0);
  const r = bridgePlan.bridge.repair;
  ctx.fillStyle = 'rgba(96, 200, 96, 0.45)'; ctx.fillRect(r.x, 0, r.width, out.height);
  ctx.fillStyle = 'rgba(255,255,255,0.9)'; ctx.fillRect(bridgePlan.bridge.seamX - 1, 0, 2, out.height);
  return out;
}

export function calculateRestoredBridgeSize(panoramaHeight, ratio = '1:1') {
  const [rw, rh] = String(ratio).split(':').map(Number);
  if (!(panoramaHeight > 0) || !(rw > 0) || !(rh > 0)) throw new Error('Invalid bridge restoration geometry.');
  return { width: Math.max(2, Math.round(panoramaHeight * rw / rh)), height: Math.round(panoramaHeight) };
}

// Restore the locked pixels from the original bridge, join the generated band to them,
// and un-roll the bridge back into the panorama.
//
// Two join strategies:
//   'seamcut' (default, verified 2026-09-10 on five paid GPT Image 2.5 runs): inside a
//     search zone at each band edge, find the vertical path where |original - generated|
//     is smallest and cut there. No ghosting, because no pixel is a mix of two structures.
//   'feather': linear alpha ramp inside the band edges. Kept for comparison; it doubled
//     edges wherever the model's copy of the locked strips drifted by a few pixels.
export function reinsertGeneratedBridge(panorama, generated, bridgePlan, options = {}) {
  const b = bridgePlan.bridge;
  const restored = calculateRestoredBridgeSize(panorama.height, b.ratio);
  if (restored.width !== b.width || restored.height !== b.height) throw new Error('Bridge plan does not match this panorama.');
  const scaled = canvas(b.width, b.height);
  context(scaled).drawImage(generated, 0, 0, generated.naturalWidth || generated.width, generated.naturalHeight || generated.height, 0, 0, b.width, b.height);

  const original = composeBridge(panorama, bridgePlan);
  const patched = (options.blend ?? 'seamcut') === 'feather'
    ? lockOutsideRepairBand(original, scaled, b.repair, options.featherWidth ?? calculateFeatherWidth(b.repair.width))
    : lockOutsideRepairBandSeamCut(original, scaled, b.repair, options.searchWidth ?? calculateSeamSearchWidth(b.repair.width));

  const output = canvas(panorama.width, panorama.height), out = context(output); out.drawImage(panorama, 0, 0);
  out.drawImage(patched, b.left.x, 0, b.left.width, b.height, b.left.source.x, 0, b.left.width, b.height);
  out.drawImage(patched, b.right.x, 0, b.right.width, b.height, b.right.source.x, 0, b.right.width, b.height);
  return output;
}

// original pixels everywhere; generated band alpha ramps 0→1→0 inside the band edges.
export function lockOutsideRepairBand(original, generated, repair, featherWidth) {
  const out = canvas(original.width, original.height), ctx = context(out); ctx.drawImage(original, 0, 0);
  const band = canvas(repair.width, original.height), bctx = context(band);
  bctx.drawImage(generated, repair.x, 0, repair.width, original.height, 0, 0, repair.width, original.height);
  bctx.globalCompositeOperation = 'destination-in';
  const f = Math.min(Math.max(1, featherWidth), Math.floor(repair.width / 2));
  const g = bctx.createLinearGradient(0, 0, repair.width, 0);
  g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(f / repair.width, '#000');
  g.addColorStop(1 - f / repair.width, '#000'); g.addColorStop(1, 'rgba(0,0,0,0)');
  bctx.fillStyle = g; bctx.fillRect(0, 0, repair.width, original.height);
  ctx.drawImage(band, repair.x, 0);
  return out;
}

// Search zone for the minimum-error cut: the outer 70% of each shoulder, where the model
// had the original to reproduce. Never reaches the fully blanked middle.
export function calculateSeamSearchWidth(repairWidth, shoulder = SHOULDER_FRACTION) { return Math.max(8, Math.round(repairWidth * shoulder * 0.7)); }

// Minimum-error vertical path through a row-major cost grid (8-connected dynamic
// programming, top to bottom). Returns one x per row. Pure; no canvas needed.
export function findMinimumErrorSeam(cost, width, height) {
  if (width < 1 || height < 1 || cost.length < width * height) throw new Error('Invalid seam cost grid.');
  const acc = Float32Array.from(cost), back = new Int8Array(width * height);
  for (let y = 1; y < height; y++) {
    const p = (y - 1) * width, r = y * width;
    for (let x = 0; x < width; x++) {
      let best = acc[p + x], k = 0;
      if (x > 0 && acc[p + x - 1] < best) { best = acc[p + x - 1]; k = -1; }
      if (x < width - 1 && acc[p + x + 1] < best) { best = acc[p + x + 1]; k = 1; }
      acc[r + x] += best; back[r + x] = k;
    }
  }
  let x = 0; const last = (height - 1) * width;
  for (let i = 1; i < width; i++) if (acc[last + i] < acc[last + x]) x = i;
  const path = new Int32Array(height);
  for (let y = height - 1; y >= 0; y--) { path[y] = x; x += back[y * width + x]; }
  return path;
}

// Original pixels everywhere outside two minimum-error cuts; generated pixels between
// them. The cuts live inside the repair band, within `searchWidth` of each band edge.
// A 3 px box blur along x softens the cut without mixing distinct structures.
// TODO 2026-09-16: at an 8192x4096 panorama, `o`, `g` and `image.data` each hold a full
// width*height*4 Uint8ClampedArray (~134 MB apiece here, ~400 MB live at once) — out of scope
// for this fix, but real on a low-memory device. Tiling the seam search and composite by row
// band, rather than materializing the whole frame three times, would cut this without changing
// the result.
export function lockOutsideRepairBandSeamCut(original, generated, repair, searchWidth, softEdge = 3) {
  const w = original.width, h = original.height;
  const o = context(original).getImageData(0, 0, w, h).data, g = context(generated).getImageData(0, 0, w, h).data;
  const zone = Math.min(Math.max(4, Math.round(searchWidth)), Math.floor(repair.width / 2));
  const costWindow = x0 => {
    const cost = new Float32Array(zone * h);
    for (let y = 0; y < h; y++) for (let i = 0; i < zone; i++) {
      const p = (y * w + x0 + i) * 4;
      cost[y * zone + i] = (Math.abs(o[p] - g[p]) + Math.abs(o[p + 1] - g[p + 1]) + Math.abs(o[p + 2] - g[p + 2])) / 3;
    }
    return cost;
  };
  const leftX = repair.x, rightX = repair.x + repair.width - zone;
  const left = findMinimumErrorSeam(costWindow(leftX), zone, h), right = findMinimumErrorSeam(costWindow(rightX), zone, h);

  const out = canvas(w, h), ctx = context(out), image = ctx.createImageData(w, h), d = image.data;
  const hard = new Float32Array(w), prefix = new Float32Array(w + 1);
  for (let y = 0; y < h; y++) {
    const a = leftX + left[y], b = rightX + right[y];
    for (let x = 0; x < w; x++) hard[x] = x > a && x < b ? 1 : 0;
    prefix[0] = 0; for (let x = 0; x < w; x++) prefix[x + 1] = prefix[x] + hard[x];
    for (let x = 0; x < w; x++) {
      const lo = Math.max(0, x - softEdge), hi = Math.min(w, x + softEdge + 1);
      const alpha = (prefix[hi] - prefix[lo]) / (hi - lo), p = (y * w + x) * 4;
      d[p] = o[p] + (g[p] - o[p]) * alpha; d[p + 1] = o[p + 1] + (g[p + 1] - o[p + 1]) * alpha;
      d[p + 2] = o[p + 2] + (g[p + 2] - o[p + 2]) * alpha; d[p + 3] = 255;
    }
  }
  ctx.putImageData(image, 0, 0);
  return out;
}

export function createWrapPreview(panorama, fraction = 0.18) {
  const width = Math.max(1, Math.round(panorama.width * fraction)); const out = canvas(width * 2, panorama.height); const ctx = context(out);
  ctx.drawImage(panorama, panorama.width - width, 0, width, panorama.height, 0, 0, width, panorama.height);
  ctx.drawImage(panorama, 0, 0, width, panorama.height, width, 0, width, panorama.height); return out;
}

// Acceptance criterion 8: original and repaired wrap bands side by side.
export function createWrapComparison(original, repaired, fraction = 0.18) {
  const a = createWrapPreview(original, fraction), b = createWrapPreview(repaired, fraction), gap = 12;
  const out = canvas(a.width + gap + b.width, a.height), ctx = context(out);
  ctx.fillStyle = '#111'; ctx.fillRect(0, 0, out.width, out.height);
  ctx.drawImage(a, 0, 0); ctx.drawImage(b, a.width + gap, 0);
  return out;
}

export function scalePanorama(panorama, exportWidth) {
  const width = Math.max(2, Math.round(exportWidth / 2) * 2); const out = canvas(width, width / 2); context(out).drawImage(panorama, 0, 0, width, width / 2); return out;
}
export function canvasBlob(c, type = 'image/png', quality = 0.95) { return new Promise((resolve, reject) => c.toBlob(b => b ? resolve(b) : reject(new Error('Image encoding failed.')), type, quality)); }
export function loadImage(src) { return new Promise((resolve, reject) => { const image = new Image(); image.crossOrigin = 'anonymous'; image.onload = () => resolve(image); image.onerror = () => reject(new Error('Image could not be loaded.')); image.src = src; }); }

// TODO 2026-09-16: an 8192x4096 (or larger) panorama exceeds the canvas area some Safari builds
// (older WebKit/iOS in particular) cap 2D canvases to; drawImage/getImageData silently return a
// blank or truncated buffer instead of throwing, so preparePanorama/scalePanorama/
// lockOutsideRepairBandSeamCut have no signal to surface. Out of scope for this fix: needs a
// capability probe (draw a known pixel, read it back) gating the whole editor, not a resolution
// change inside these helpers.
function canvas(width, height) { const c = document.createElement('canvas'); c.width = Math.round(width); c.height = Math.round(height); return c; }
function context(c) { const x = c.getContext('2d', { alpha: true }); if (!x) throw new Error('Canvas 2D is unavailable.'); x.imageSmoothingEnabled = true; x.imageSmoothingQuality = 'high'; return x; }
function assertSize(s) { if (!Number.isFinite(s.width) || !Number.isFinite(s.height) || s.width <= 0 || s.height <= 0) throw new Error(`Invalid image size ${s.width}x${s.height}`); }
function clamp(v, min, max) { return Math.min(max, Math.max(min, v)); }
