# Video Downloader

A tool (`video-downloader`, shown once `dam-home.video_api` is set) that downloads a video from a link onto the
device in use. The studio does the download; the tool asks it to and lists what it has done. Its folder,
`packages/Mothership/DAM/src/Resources/widgets/video-downloader/`, holds `index.html`, `app.js` and `style.css`, drawn
with the shared tool kit (`widgets/_kit/tool-kit.css`). The same files work inside the tool page
(`/admin/tools/video-downloader`) and in Home's tool panel. How the studio side works is in
[`docs/ops/studio-video.md`](../ops/studio-video.md); what a person does is in the Home user guide.

## Layout

- **Find:**
  - the link field and Find video, with a line saying where to copy a link from and which sites work;
  - the video found: its title, site and length, "Choose a size", "Make the file smaller" (when the studio can), Download and Not this one.
- **Your downloads:** one row per download: its title, what is happening to it in words, a bar while it runs, and its actions.
- **By the tool's own width:**
  - From 860 px wide, Find (400 px) and Your downloads sit side by side, each scrolling by itself.
  - Narrower, they scroll as one, Find first.
  - A row whose pane is under 560 px wide puts its buttons under the words. A single plain word, such as Cancel, stays beside the title.
- **On a folding phone:** the page that frames the tool says where the fold is.
  - Book fold: Find goes left of it and the downloads right.
  - Tabletop fold: the downloads stay above it and Find goes below.
- **The tool page's bar:** it names the tool and offers nothing else. The tool keeps no saved sets, and its files go to the device, not the Library.

## One filled control

Only the next step is filled. `main[data-next]` names it, and `app.js` keeps it current:
- Download, while a found video is open;
- otherwise Save video, while the Home Screen app on an iPhone or iPad holds a file that has not been saved;
- otherwise Find video.

## States

- **The bar:** while a download runs, its bar shows how far it has gone. It is ink, never the accent.
- **No measure:** starting, finishing the file and being made smaller have no measure. Their bar slides back and forth, and with reduced motion it is drawn as still stripes the whole way along.
- **Errors:** a refused link, a refused download, a failed row and a lost connection to the studio each show in red, with a ✕ and words.

## Checks

`tools/browser/tests/video-downloader-dom.test.cjs` opens the tool from disk with a made-up studio, under the kit. It covers:
- saving in the installed app, one file at a time;
- what Home is told about work it would lose;
- the words over the size limit;
- the plain file link in a browser;
- the folding phone's two screens;
- the panes on a book and a tabletop fold;
- which one control is filled.

`packages/Mothership/DAM/tests/Feature/HomeVideoDownloaderTest.php` covers who may open the tool and use the studio.
