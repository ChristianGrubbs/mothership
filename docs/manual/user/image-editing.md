# Edit and export images

## Before you begin

Open an asset from **DAM > Assets** and select the **Edit image** pencil action. You need access to the asset and permission to edit it. **Save as new asset** also requires permission to upload assets. The full editor accepts readable PNG, JPEG and WebP images no larger than 8,192 pixels on either side or 32 megapixels in total.

Opening the editor makes a private working base and does not change the asset's stored bytes. For a byte-level check, use the asset's normal download action before editing and calculate its SHA-256 with an independent local hashing tool. Keep that value so you can distinguish the pre-edit original from the exported result.

## Steps

1. Open **Image editor**. Use **Edit image** to crop, resize, rotate, flip, change brightness or contrast, sharpen, blur, apply grayscale or invert. Use **Undo**, **Redo**, **Compare original**, **Fit** and the zoom controls while you work.
2. Use **Annotate** for arrows, text, rectangles, highlights, numbered steps, blur, pixelation and freehand drawing. Ordinary saves retain annotations as selectable layers when the document is reopened. Use **Background** for presentation framing: add a solid, gradient or uploaded canvas; choose its ratio, padding, image scale, rounded corners, shadow and alignment. Browser-saved presentation presets do not save an edited asset by themselves.
3. Select **Save new version** to export the complete working document as a PNG on the same asset. The asset keeps its identity, the new PNG becomes its current downloadable file, an editable editor document is attached to the new version, and the pre-edit source remains retained in **Files** history. Close and reopen the image editor to confirm that editable layers and the new base return.
4. Select **Save as new asset** when the source asset must remain current and unchanged. This exports an `- edited.png` asset in the source folders, carries over its title, tags and folders, and gives the new asset its own editable document. Download the source asset again and compare its SHA-256 with the baseline; they should match. Download the edited asset and compare it with the baseline; its bytes should differ when the visible edit changed the render.

The older preview quick-edit operations for crop/resize, adjustments, filters, transform and background replacement, if exposed by your deployment, behave differently from the full editor: applying one writes directly to the current asset path. Those handlers do not create a separate asset or an editable version record. Use the full **Image editor** save choices when you need retained history or a separate derived record.

## Expected result

The full editor exports a PNG only when **Save new version** or **Save as new asset** succeeds. Reopening the saved asset restores its editable document. Unsaved canvas changes do not replace the stored asset.

After **Save new version**, the current asset download contains the edited PNG and should differ from the pre-edit download when the render changed; the earlier original remains in **Files** history. After **Save as new asset**, the source download remains byte-for-byte identical to its baseline and the separate edited asset contains the exported PNG.

## Troubleshooting

- **Edit image is unavailable or a request is forbidden.** Confirm that you can access the asset and that your role can edit it. If **Save as new asset** alone is forbidden, your role also needs asset-upload permission.
- **The editor rejects the source.** Use a readable PNG, JPEG or WebP within the 8,192-pixel-per-side and 32-megapixel limits. A missing or undecodable source must be restored or re-uploaded before editing.
- **Save reports that a newer edit exists.** Your working edit remains open. Reopen to load the newer version, or use **Save as new asset** if keeping both results is appropriate.
- **The saved download is unexpected.** Reopen the asset and verify which save choice completed. The normal download follows the current asset; use **Files** for a retained earlier version, or open the separate `- edited.png` asset after **Save as new asset**.

The prompt-driven **Edit with AI** tab was removed on 2026-09-21. Assets and versions it produced remain ordinary assets. Generate new imagery with StylePress instead.
