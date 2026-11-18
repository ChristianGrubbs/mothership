# Convert an image to editable vectors

## Before you begin

Open **Image Vectorization** from Home. If the card is not on your saved layout, select **Customize**, add it and save the layout. You need access to the `dam.assets` Home tool and an available vectorization allowance. New accounts start with zero available; an administrator who can edit users must allocate a balance. The configured owner account is unlimited.

Choose a PNG, JPEG, WebP, BMP, GIF or TIFF image no larger than 20 MiB and 33,554,432 pixels. Opening the tool, loading an Mothership image and previewing it do not spend allowance. One completed conversion uses one vectorization. A failed conversion returns its reservation.

## Steps

1. Choose a local image in the Home tool. For an existing Library asset, use the direct workflow below.
2. Confirm the **Original** preview and select **Vectorize image**. The tool reserves one available vectorization while the request is queued or processing. You can leave and later reopen the tool; **Your recent vectorizations** lists the 20 newest results owned by your account.
3. When the status says **Your vector files are ready.**, compare **Original** with **Vector result**. Use the zoom control from 25% to 400% to inspect the result.
4. Select **Download SVG** for an editable vector, **Download PDF** for a PDF export, or **Copy SVG code** when another tool accepts SVG text. Repeating an export downloads the stored result and does not submit another provider request or consume another allowance.

## Convert from an asset

Open a supported raster asset, select **Convert ▾**, then choose **SVG** or **PDF**. An animated overlay shows the queued/processing state. You can leave the page; the server finishes the conversion and attaches the selected format to that asset's **Files** tab. **Saved to Files** links to the result. Conversion does not alter or replace the source media. This action requires asset edit and Files access as well as the vectorization allowance. Attached files follow the asset's access permissions.

## Expected result

A completed request creates account-owned SVG and PDF outputs while leaving the source image unchanged. The reserved count returns to zero and the available balance decreases by one. Only the account that submitted the request can open its result or exports; another account receives a not-found response.

The source upload is temporary and is removed after processing. The generated outputs remain in private Mothership storage for later preview and export. Existing SVG assets render directly in the normal Asset Preview; they do not need another vectorization.

## Troubleshooting

- **No vectorizations remaining. Ask your administrator for an allocation.** Your account has a default-zero balance or has used its available conversions. Ask an administrator with permission to edit users to set **Available vectorizations** on your account. In-progress reservations are shown separately.
- **Image Vectorization is not connected.** The feature or provider connection is unavailable. No request is submitted and no allowance is spent. Ask an administrator to verify the server-side vectorization configuration.
- **Choose an image up to 20 MiB.** Select a smaller PNG, JPEG, WebP, BMP, GIF or TIFF file.
- **Choose an image up to 33,554,432 pixels.** Reduce the image dimensions and choose it again.
- **Vectorization failed. Your allowance was returned.** The provider or result storage failed. The tool does not automatically repeat the paid request. Confirm the balance was returned, then select the source and retry when the service is available.
- **The tool says the request can be retried.** An upload or submit request was rejected or its response was ambiguous. The tool keeps the same request identity while that file remains selected, so retrying the same submission cannot charge twice. Choosing the file again starts a new request.
- **An export is unavailable or returns not found.** Sign in with the account that submitted the conversion and wait for it to complete. Failed and in-progress results do not expose download URLs.
- **SVG preview could not be loaded.** Use **Download SVG** to retry the stored file. Downloading an existing result does not call the provider or spend allowance.
