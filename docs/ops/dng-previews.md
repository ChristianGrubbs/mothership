# DNG previews and save feedback

Shipped scope: DNG thumbnails and image previews, plus inline saving/saved/error feedback for Details layouts, capture profiles and existing-file grouping.

DNG sources remain unchanged. ExifTool extracts a usable embedded JPEG (at least 640 pixels on its longer edge), inheriting the RAW orientation. If no usable JPEG exists, LibRaw `dcraw_emu` develops a half-size sRGB TIFF and libvips creates a JPEG up to 1920 pixels and a 300-pixel thumbnail. LibRaw is installed in the FPM, queue and web images. The converter uses `-Z .tiff`; `-c` is a numeric adjustment option in this tool, not stdout output.

Derivatives use the current original FileObject SHA-256 in their cache paths. Replacing content generates a different cache entry. Authenticated previews retain directory access checks; public previews retain share validation; downloads continue serving originals. Failed conversions retain no temporary files and are suppressed for five minutes. Normal cached reads do not run conversion. The web conversion budget is 10 seconds; a timeout queues the normal 70-second conversion job. Until that job completes, a placeholder may be shown; reopening the preview retries it. Historical derivative cache entries are retained.

Uploads schedule DNG generation through the existing thumbnail dispatch paths. The existing `dam:backfill-thumbnails` command also processes DNGs, skipping cached thumbnails. Logs use the application log at `storage/logs/laravel.log`; converter events contain a run ID and asset ID, with routine events enabled by application debug mode.

Save buttons show Saving… and block duplicate submissions. Completion displays a local checkmark and a toast naming the saved item. Validation failures leave edits intact and display an error beside the action. Editing again clears the saved indication.

## Verification

- `DngPreviewTest.php`: generic-MIME and legacy classification, oriented embedded JPEG, thumbnail dimensions, source preservation, content-replacement cache invalidation, protected preview endpoints, shared inline preview versus original download, expired-share rejection, malformed-file cleanup.
- `tools/browser/tests/save-feedback.test.cjs`: delayed-save deduplication, success timing, validation errors, draft retention and retry.
- Disposable candidate deployment: browser verified both save forms, clear-on-edit, validation-error retry, and the DNG viewer with no page errors.
- Real library asset 308: 960×540 embedded JPEG in 2.23 seconds. A disposable copy with only its embedded JPEG bytes zeroed produced a visually verified 1920×1080 RAW fallback in 6.13 seconds. Do not use ExifTool to remove the preview from this DJI fixture: rewriting its TIFF structure changes the RAW data. The original was not modified.
