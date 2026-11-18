# The Mothership native video download service

The Home **Video Downloader** tool lets a signed-in person paste a link, pick a size and save the
file to their own device. The production Mac does the downloading natively with its own `yt-dlp` and
`ffmpeg` (the same pair the Studio's Yoink terminal app drives; Yoink itself is a full-screen
terminal program with no scriptable mode, so the service uses Yoink's format recipe directly).
The Mothership's containers reach the service over the private Apple-container bridge, like the Clop
service (`docs/ops/studio-clop.md`). It has no public listener, Cloudflare route or DNS record.

## Installed runtime

A single stdlib-only script (`tools/studio/video-service/server.py`) run with the Studio's system
`python3` (3.9-compatible). All state is on the Studio internal disk under:

`~/Studio Data/Mothership Native Services/video`

- Deployed service source: `runtime/video-service/server.py`
- Queue and finished files: `data/jobs.json`, `data/files/<job id>/`
- Process log: `logs/video-service.log`
- Service JSONL events (gated by `VIDEO_SERVICE_LOG=1`): `logs/video-service-events.jsonl`
- PID/config receipt: `run/video-service.json`

It needs `/opt/homebrew/bin/yt-dlp` and `/opt/homebrew/bin/ffmpeg`. The manager refuses to start
and names whichever is missing. Sites change often; when downloads from a site start failing,
update the Studio's yt-dlp (`brew upgrade yt-dlp`) and restart the service.

## Start, stop, verify

`runtime.py up` starts the service when `app.env` has the key below, and `bridge_watch.py`
restarts it if it stops answering. By hand, on the Studio:

```bash
python3 tools/studio/video_service.py up --host 192.168.164.1 --port 18769
python3 tools/studio/video_service.py status --host 192.168.164.1 --port 18769
python3 tools/studio/video_service.py down --host 192.168.164.1 --port 18769
```

From the app container:

```bash
container exec mothership-private-fpm curl -fsS http://192.168.164.1:18769/health
```

## App setting

`Studio Data/Mothership Private/app.env`:

```
MOTHERSHIP_HOME_VIDEO_API=http://192.168.164.1:18769
```

Without the key the tool is not shown on Home and its routes answer 403. Stage is pointed at a
dead address on purpose, so stage never queues work on the live service.

## Who may use it

Every signed-in account, unless a full-access account removes **Video Downloader** from a role's
Home tools (right-click a tool on Home). The queue owner is always the signed-in account id, set
by `HomeVideoController`; an owner sent by the browser is ignored, and a job id belonging to
someone else answers 404.

## Queue and limits

The queue is kept on disk, so nothing is lost when a browser closes, a device changes network or
the service restarts. A person sees the same list on every device they sign in on.

| Setting | Default | Meaning |
| --- | --- | --- |
| `VIDEO_WORKERS` | 4 | Downloads running at once across the Studio |
| `VIDEO_USER_BACKLOG` | 25 | Waiting or running downloads one person may hold (then 429) |
| `VIDEO_KEEP_SECONDS` | 86400 | How long a finished file stays available |
| `VIDEO_MAX_BYTES` | 2 GiB | Largest single file |
| `VIDEO_JOB_TIMEOUT` | 7200 | Seconds before a running download is stopped |
| `VIDEO_MIN_FREE_BYTES` | 10 GiB | Free disk below which new downloads wait |
| `VIDEO_OPTIMIZER_API` | `http://<this service's host>:18766` | The Clop bridge used to make files smaller; `off` disables it |
| `VIDEO_OPTIMIZE_MAX_BYTES` | 250 MiB | Largest video that is made smaller; a larger one is kept as downloaded. `0` removes the limit |

Turn order is fair: the person with the fewest running downloads goes next, and each person's own
downloads run oldest first. Asking for the same link and size twice returns the existing download.
A download that was running when the service stopped starts again on the next start.

## Making files smaller

The tool offers **Make the file smaller** when a size is picked and an **Optimize** button on a
finished video. Both hand the finished MP4 to the Studio's Clop bridge (`docs/ops/studio-clop.md`,
`POST /optimize?type=video&compression=75&encoder=software`, the Library's Strong and Smaller file choices), which the service reaches on its own
host at port 18766, so no extra setting is needed when `MOTHERSHIP_OPTIMIZER_API` is already in use.
The service checks the bridge's `/health` at start and every minute; while it is not answering, the
option is hidden and `POST /jobs/<id>/optimize` answers `503`.

The original file is replaced only when a complete, smaller file has come back. If Clop cannot make
it smaller, fails, or the service stops part-way, the original stays and the download is still
ready to save. Audio (MP3) is never sent. Files from YouTube are already tightly compressed: the
Balanced setting could not shrink them at all, which is why Strong is used; a 1080p, ten-minute
video took about three and a half minutes and came back 41% smaller. Clop works on one file at a time across the whole Studio,
including the Library's Optimize action, so a busy day means a longer wait in "Making the file
smaller", not a failure. A file being made smaller cannot be saved until it is done.

Only videos up to `VIDEO_OPTIMIZE_MAX_BYTES` (250 MB, operator limit of 2026-11-11) are made smaller. The limit is
on the downloaded file, not a size the result is squeezed to: Clop has no size target. A larger video that was
asked to be made smaller is kept as downloaded and marked `optimized: "too_large"`, its row says "too large to make
smaller (over 250 MB)", it gets no **Optimize** button, and `POST /jobs/<id>/optimize` answers `422`. The tool
says so before the download too, under **Make the file smaller**. Change the limit with the setting above and
restart the service; the tool reads the number from `GET /jobs`, so its wording follows.

## What progress is shown

- **Downloading** is measured. A video and its sound are usually two streams; yt-dlp is asked for every stream's
  size before the first byte, so the percentage, the sizes and the time left are for the whole file. When a site
  gives no sizes, the row says which part it is on ("Downloading part 2 of 2, 40%") instead of running to 99% twice.
- **Finishing the file** (joining the streams) has no measure: a moving line.
- **Making the file smaller** has no measure either: Clop reports nothing until it is done. The row shows a moving
  line and how long it has been working, counted from `optimize_started_at`, which the service sets when the file
  is handed to Clop. Until then the row says "Waiting its turn to be made smaller". The service sends its own clock
  (`now`) with the list, so the elapsed time is right after a reload, on another device, or with a wrong device clock.
- **Getting it onto this device** (installed iPhone or iPad app only) is measured by the tool as it fetches the
  finished file; see below.

## Saving on an iPhone or iPad

In Safari and on a computer, **Save to this device** is a plain download link. An app added to the Home Screen
has no download manager: following a file link there replaces the whole app with a file preview that has no way
back (operator report 2026-11-11). So in the installed app the tool never follows the link. It fetches the file
itself, showing how far along it is, then **Save video** opens the share sheet, where **Save Video** puts it in
Photos and **Save to Files** in Files; closing the sheet leaves the tool as it was. A finished download is fetched
by itself on the device that asked for it, so one tap on **Save video** remains. The file is held in memory, so
this path stops at 500 MB (`APP_SAVE_MAX` in the widget's `app.js`) and tells the person to open The Mothership in
Safari for anything larger.

## Host <-> app contract

Fixed; `HomeVideoController` is built to match it.

- `GET /health` -> `200 {"ok":true,"ytdlp":"<version>","ffmpeg":true,"workers":4,"optimizer":true,...}`, or `503` when yt-dlp cannot be run.
- `POST /probe {"url"}` -> `200 {"title","duration","thumbnail","choices":[{"id","kind","label","size","default"}]}`.
  Choice ids are `v<height>`, `best` and `mp3`. At most four video sizes are offered (the original's
  top size plus 1080p, 720p and 480p where they exist); the default is 1080p or the next size down.
- `GET /jobs?owner=<id>` -> `200 {"jobs":[...],"optimize":true|false,"optimize_max":<bytes or null>,"now":<epoch>}`,
  newest first, each with `status` (`queued`, `running`, `processing`, `optimizing`, `done`, `failed`), `position`,
  `percent`, `downloaded`, `total`, `speed`, `eta`, `filename`, `size`, `error`, `expires_at`, `part` and `parts`
  (set only when the stream sizes are not all known), and for the smaller-file option `can_optimize`, `optimized`
  (`smaller`, `none`, `failed`, `too_large` or null), `saved` (bytes) and `optimize_started_at` (null while the
  file waits its turn for Clop). `/health` carries `optimize_max` as well.
- `POST /jobs {"owner","url","choice","title","optimize"}` -> `201 {"job","jobs"}`; `429` when the
  person's backlog is full. `optimize: true` makes a video smaller before it is marked done.
- `POST /jobs/<id>/optimize?owner=<id>` -> `202 {"job"}` for a finished MP4; `409` when it is not
  ready or already done, `422` for audio or a video over the limit, `503` when the Clop bridge is not answering.
- `GET /jobs/<id>?owner=<id>`, `DELETE /jobs/<id>?owner=<id>` (cancels a running download and
  deletes its file).
- `GET /files/<id>?owner=<id>` -> the file as an attachment; `Range` is honoured (`206`).

Links must be `http` or `https`, carry no user name or password, and resolve only to public
addresses; anything else is refused with `422` before yt-dlp runs. That check alone would not stop a
public page that redirects to a private address, names one as its media URL, or changes its DNS
answer, so yt-dlp and the ffmpeg it starts are also given a loopback proxy inside the service as
their only way out (`--proxy` and the proxy environment variables). The proxy resolves every
destination itself, refuses it with `403` unless every address is public, connects to the address it
checked, and logs `egress.refused`. ffmpeg is given no network at all: the service writes
`data/bin/ffmpeg` and `data/bin/ffprobe` stand-ins that limit every input to local files, because a
manifest can name key or segment addresses ffmpeg would open itself, around the proxy. On macOS the
stand-ins also run ffmpeg under `sandbox-exec` with all network access denied, which no ffmpeg option
or playlist file can override. ffmpeg only
joins and converts what yt-dlp has already downloaded, so live streams, which yt-dlp hands to ffmpeg
to fetch, fail with a plain error rather than download. yt-dlp is run with
`--ignore-config` and the link after `--`, so a link can never be read as an option.
