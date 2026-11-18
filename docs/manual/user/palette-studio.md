# Palette Studio

Palette Studio builds a colour palette of up to eight swatches, checks every pair for WCAG contrast, shows the palette as people with colour-vision deficiencies see it, and exports it as code or a PNG. It runs in your browser; your saved palettes are kept with your account.

## Before you begin

Open **Palette Studio** from **Apps** on Home. Ten starter palettes are in the library the first time you open it.

## Steps

1. Select a swatch by clicking it or pressing its number key (**1** to **8**). Edit its Hex, RGB, HSL or OKLCH value, or drag the hue, saturation and lightness sliders; the other values follow. An incomplete value keeps the last valid colour until you finish typing.
2. Lock the swatches you want to keep. Choose a harmony and select **Generate** to fill the other swatches from the selected one, **Surprise me** (or **Space**) for a new palette, or **Nudge** for small changes. Locked swatches never change. **Undo** in the notice reverts the last generation.
3. Add, remove or move swatches with the controls under each one.
4. Check **Contrast**: every pair of swatches shows its ratio and separate AA and AAA results for normal and large text. A failure is marked ✕ Fail.
5. Choose **View as** to simulate deuteranopia, protanopia, tritanopia or achromatopsia across the swatches, scales and preview. **Show original colours** turns it off.
6. Copy a value from **Scales** (stops 50 to 900), or use **Export** to copy CSS custom properties, SCSS variables, Tailwind theme colours, JSON or a hex list, or to download a 1600 × 900 PNG named after the palette.
7. Select **Save** (or press **S**) to keep the palette in the library. Press **C** to copy the selected hex. Shortcuts are ignored while you type in a field.

## Expected result

The library lists favourites first, then your other palettes in the order you set. Each palette can be opened, renamed, duplicated, favourited, moved up or down, and deleted (with **Undo**). **Export library** downloads every palette as JSON; **Import JSON** adds the palettes from such a file, or from a list of `{ "name", "colors" }` objects.

Simulation changes only what you see. Copied values, exports and the contrast table always use the original colours.

## Troubleshooting

- **An import skipped palettes.** Each palette needs one to eight hex colours. The notice names the first palette that was skipped and why.
- **The library is full.** It holds 150 palettes. Export it, then delete palettes you no longer need.
- **The PNG has no watermark.** None ships. An administrator can add one by placing an SVG at `packages/Mothership/DAM/src/Resources/widgets/palette-studio/assets/watermark.svg`; exports draw it in the top-right corner.
