# Optimize an asset

## Before you begin

**Optimize** sits beside **Convert** on an asset's preview card. It needs asset edit and Files access, and the Studio Clop optimization service must be connected — an administrator sets this up ([docs/ops/studio-clop.md](../../ops/studio-clop.md)). If the button is disabled or missing, either you lack access or the service is not connected.

Supported types: JPG, JPEG and PNG images; MP4 video; PDF. Other file types do not show the button. MOV and WebP are not yet supported.

## Steps

1. Open a supported asset and select **Optimize ▾**.
2. Choose the controls for the file's type:
   - **Image**: **Size** (Original, 75%, or 50%) and **Allow format change (smaller file)**. Leaving format change off keeps the same image format at the chosen size. Turning it on lets the optimizer also pick a smaller format — for example, a PNG may come back as a JPEG.
   - **Video**: **Compression** (Gentle, Balanced, or Strong), **Encoder** (Faster or Smaller file), **Size**, and **Remove audio**.
   - **PDF**: no controls — select **Optimize** to run it with the standard setting.
3. Select **Optimize**. An overlay shows the queued/processing state; you can leave the page and come back — the preview card picks the job back up.
4. When it finishes, one of two things happens:
   - A smaller copy was made: the before → after size and percent saved are shown, along with the new file format, and the copy is attached to the asset's **Files** tab (**Saved to Files** links there). The original file is never changed.
   - No smaller copy was possible: **Already optimized, no smaller copy made** is shown, and nothing is attached.

## Troubleshooting

- **Optimization unavailable.** The optimization service is not connected. Ask an administrator to check the Studio Clop service.
- **Choose a supported image, video or PDF.** The asset's file type is not on the supported list (JPG, JPEG, PNG, MP4, PDF).
- **The optimizer on the Studio isn't running, so no copy was made.** The optimization service could not be reached. Your original is unchanged and nothing was attached. Try again later, or ask an administrator to restart the Studio Clop service.
- **Still waiting for the optimizer to start.** The job has been queued for a couple of minutes and nothing has picked it up, which usually means the optimizer is not running. It is not failed yet; if it is still waiting after about 30 minutes it is marked expired. Tell an administrator.
- **Optimization failed. Your original is unchanged. Try again.** The optimizer could not process the file. Nothing was attached; select **Optimize** again.
- **Dismiss ×.** A failure message stays next to the Optimize button until you dismiss it; a dismissed message does not come back in that browser, and a failure more than 7 days old is no longer shown.
- **This optimization expired before it could finish.** The original file changed, or access to the asset changed, between starting the job and it finishing. Nothing was attached. Start again if you still want an optimized copy.
- **Could not start optimization. Try again.** The request did not reach the server. No job was queued.
