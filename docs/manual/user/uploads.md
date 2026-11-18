# Resumable uploads

## Before you begin
Open **DAM > Assets**, choose the destination folder, and confirm that the upload controls are available. Your account needs upload access to that folder.

The DAM browser uploader accepts an individual file up to **20 GiB** and transfers it in independently verified **32 MiB** chunks. This browser flow is separate from the retained multipart API endpoints used by compatible integrations. Those endpoints do not provide the browser's resumable flow and keep their own request limits.

## Steps
1. In the destination folder, select **New > Upload Files** or **Upload Folder** and choose the source, or drag files or folders into the Library. The upload panel lists each file and shows the batch count, overall percentage, and per-file activity.
2. Keep the panel open while it sends chunks. A brief connection or server interruption is retried automatically. Select **Pause** to stop active transfers without discarding accepted chunks, then select **Resume** to continue while the original files are still available to the page.
3. After a reload or browser restart, the restored job is marked interrupted because the browser stores resumable metadata, not a copy of the file. Select **Reselect**, then choose the same source file. Its name, size, last-modified time, and fingerprint must match. The browser asks the server which chunks were accepted and sends only the missing chunks.
4. After every chunk is accepted, wait through **Queued for final processing** and **Assembling and verifying**. These states mean the server is still joining and checking the staged chunks; they are not a finished asset. The file becomes available in the destination only when the panel reports completion.

## Expected result
The panel reaches 100% and reports the file as uploaded only after server assembly finishes and the upload state is completed. The Library refreshes and shows one asset in the chosen destination. Having every chunk accepted, **Queued for final processing**, or **Assembling and verifying** alone does not mean that an asset is available.

## Troubleshooting
Use the retry button on a failed file, or **Retry** for a session whose remaining files failed. Integrity or storage failures can be retried while the matching source and resumable session are still available. If prompted to reselect, choose the original file; a changed source is rejected instead of being combined with previously accepted chunks.

Select **Cancel** to stop unfinished work and discard its staged chunks. Assets that already reached completed are kept. A receiving upload expires **12 hours** after initialization or the most recently accepted chunk. Once it has expired, its staged chunks cannot be resumed; start the upload again. A file above **20 GiB** must also be reduced or split before it can be selected for this browser flow.
