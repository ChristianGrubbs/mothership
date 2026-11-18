# Refine and download a video loop

## Before you begin

Open **Video Loop Refiner** from Home and choose a short video. The uploader accepts MP4, MOV, M4V, WebM, AVI and MKV files up to the 512 MB sandbox limit. Uploading creates a temporary backend copy; it never modifies your original file.

The editor state and the backend files have different lifetimes. **Collapse ↙** keeps the mounted tool and its current start/end choices; select the Video Loop Refiner card again to reopen that same iframe. Reloading Home, closing the tool or selecting **New clip** discards that in-browser editing state. A successful upload and any derived output can remain on the Looper backend even after local state is gone, but the current UI does not rebuild an editor from an old filename.

## Steps

1. Select **Choose video** or drop one video onto the upload area. Keep the page open until upload and preview preparation finish. The service records the uploaded file under a UUID and returns its duration, frame rate and reference frame.
2. Let **Auto Match** run after upload. It samples the search range, compares frame structure and motion continuity, ranks candidate seams and selects the strongest match. Play the looping preview, inspect **Loop confidence**, **Visual match** and **Motion flow**, or choose another option under **Alternative seams**.
3. To change the loop, move the timeline, use **← -1 frame** or **+1 frame →**, and choose **Set current frame as start** when needed. Run **Auto-find cleanest seam** again after changing the start. Select **Open focused preview** to watch the chosen start-to-end transition repeatedly before trimming; preview speed choices are 0.5×, 1×, 1.5× and 2×.
4. Select **Export MP4**. The service trims from the selected start through end, re-encodes a frame-accurate H.264/AAC loop and opens an **Export preview** when ready. Choose **View file** to preview the created output again or **Download**/**Download MP4** to stream the owned result through Mothership to the browser.

## Expected result

The downloaded loop begins and ends at the selected frame times, while the source video remains unchanged. The export-ready signal includes the server filename, download address, start/end times and match score; the Home integration does not automatically import this tool export into the DAM.

Mothership records the UUID from a successful upload against the signed-in account. Every later metadata, frame, timeline, match, trim, preview and download request uses that UUID ownership record. A different account receives a forbidden response for the source and for derived filenames that share the same UUID.

## Troubleshooting

- **This widget service is unavailable. Please retry shortly.** Home is running, but the configured Looper adapter cannot answer. Keep the mounted editor open, ask the administrator to restore the Video Loop Refiner service, then retry the failed analysis, trim or download. A full Home reload loses local editor state.
- **The video could not be prepared.** Confirm the file uses a listed video format, stays below 512 MB and can be decoded. Then choose the source again; a failed upload does not create an owned file record.
- **Auto-match failed.** The uploaded source may still exist. When the adapter is available again, run **Auto-find cleanest seam** on the same mounted editor rather than uploading a duplicate.
- **Trim failed.** Confirm the end is after the start and retry **Export MP4**. If upload ownership was lost or belongs to another account, the proxy rejects the request instead of exposing the file.
- **Download is forbidden or unavailable.** Use the same Mothership account that uploaded the source and keep the current output link. If the backend file was removed, upload the original again and recreate the loop; retained browser controls cannot restore a missing server file.
