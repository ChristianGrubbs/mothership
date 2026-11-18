# Web Pages

Web Pages hosts HTML pages and small static sites inside The Mothership. Each page is an asset, so it keeps its versions, folder grants and share links. The Mothership shows it in a locked-down frame rather than on the Mothership site itself.

## Before you begin
Open **DAM > Web Pages** in the sidebar. You need the **Web Pages** permission to see this screen and view permission on an asset to open it. Folder grants still apply, so you only see pages in Library folders your account can access.

Uploading needs both the **Assets** permission and asset upload permission. Creating, renaming, moving and deleting page groups needs **Organize web pages**. **Copy share link** needs share permission. An administrator can give a Sandbox role **Upload and manage Web Pages**, which covers viewing, organizing and upload permission. It does not include **Assets**, so that role cannot upload until it also has Library access. Without these permissions, **Upload here**, **New page group** and **Select** are hidden.

A page is one of these:
- a single `.html` or `.htm` file;
- a `.zip` whose entry page is at its root, or inside exactly one top-level folder. The entry is `index.html`, or the only `.html` or `.htm` file at that level. Put its stylesheets, scripts, images and fonts next to it, linked by relative paths.

An export laid out as one `.html` launcher file beside a `site/` folder also counts as a page. A `.zip` can hold up to 5,000 entries (folders count as entries) and 512 MiB of uncompressed content, and a single `.html` upload can be up to 512 MiB. A `.zip` that holds server-side or executable files, such as `.php`, `.py`, `.sh`, `.exe` or `.jar`, is refused.

## Steps
1. In **DAM > Web Pages**, open the page group you want the page in, then select **Upload here**. At the top level, pages land in **Unfiled**.
2. Check **Upload to**. Pages are filed in the **Web Pages** Library folder; if your account has no access to it, choose one of your own folders instead. Drop `.html`, `.htm` or `.zip` files on the dialog, or choose them with the file picker, then select **Upload**.
3. Wait while a new card shows **Preparing…**. The Mothership unpacks the bundle once, then the card becomes clickable.
4. Select a card to preview the page. Select **Reload** in the preview to load it again, or open the card's **⋯** menu and choose **Open full screen** to view it on its own tab.
5. To organize pages, use **New page group** to create a page group, and the group's **⋯** menu to **Rename**, **Move to…** or **Delete** it. To move pages, turn on **Select**, tick the cards, then choose **Move to…**. You can also drag pages and page groups onto a page group. Page groups nest up to 10 levels deep.
6. To share a page, open its **⋯** menu and choose **Copy share link**, or use the same button in the preview. The page's active share link is copied, or a new one is created. Manage its expiry and revocation in **Shared Links**, as described in [Sharing and revocation](sharing.md).
7. To share every page in a page group at once, open the group's **⋯** menu (or right-click it) and choose **Share pages in folder**. The dialog lists the pages directly in that group that you may share and says how many links it will make. Nothing is created until you select **Create public links**; **Cancel** and **Escape** create nothing. Each page gets its own ordinary public link for **30 days**, covering the page and its existing stack files. Pages inside nested page groups and pages added later are not included, and no link covers the group itself. A page that already has a link that still opens and that you can use keeps that link, unchanged: the dialog shows its expiry and whether it is password protected, has downloads off, or closes if its creator loses access. The dialog then shows the result for each page, so a page that moved, changed or is no longer yours to share is reported and skipped, never swapped for another. While it runs, the page being shared shows **Sharing…** and the rest show **Waiting**. **Stop** (or **Escape**) ends the run at once: if a page was being shared at that moment it is reported as possibly having a link, so check **Shared Links**, and pages not yet reached are not shared; a click elsewhere on the screen does not stop it. If a request, or the rest of its answer, never arrives the run stops the same way and nothing is retried. The dialog says only that the run stopped; the page that may have a link carries its own warning. **Copy links** copies the new and kept links. A group with more than 100 shareable pages lists and shares the first 100 in name order, and says how many were left out and that they come later by name; those are not reached by running it again, so share each of them from its own page with **Copy share link**. If the list is longer than the dialog, a line under it says to scroll. You need share permission for pages.
8. Use **Search titles** to find a page by title, and the owner filter to switch between **Everyone** and **Mine**. The card's **⋯** menu also offers **Download original**, and **Set thumbnail** to replace the card image with a picture you choose.

The **Sort** menu orders the pages by **Newest first**, **Oldest first**, **Name A–Z** or **Name Z–A**, and the grid/list toggle switches between cards and a table; The Mothership remembers your choice of view in this browser. You can also drop `.html`, `.htm` or `.zip` files anywhere on the Web Pages screen: this opens **Upload here** for the page group you are viewing, with the files already added.

## Expected result
The page appears as a card with its title, last change, creator and version count. **Shared** marks a page with an active share link. When a ready page is first opened in The Mothership, it takes a thumbnail of the page for the card, unless one has already been set.

Links inside the bundle, relative images and scripts that load from the bundle all work. The page runs in an isolated frame with no access to your Mothership session: it cannot read The Mothership cookies or storage, or change the Mothership page around it. Its scripts cannot fetch data from other sites. Pop-ups and dialogs work, and a page's scripts can handle its forms, but a form cannot submit to any address.

A share-link recipient sees the same page in the same isolated frame, without signing in. **Share pages in folder** makes the same kind of link as **Copy share link**, one per page; it does not make a link to the folder, and the links are managed one by one in **Shared Links**.

Page groups are only for organizing (they were called folders before; they are not [Collections](collections.md)). A page stays in its Library folder and keeps its grants. Deleting a page group never deletes pages: its nested page groups and pages move up to the parent group, or to **Unfiled** at the top level, and the confirmation shows how many will move.

## Troubleshooting
If a card shows **Bundle failed — re-upload**, the bundle could not be unpacked into a page. Check that its entry page, `index.html` or the only `.html` or `.htm` file at that level, is at the root or in one top-level folder, that it stays within the entry and size limits, and that it contains no server-side files. Then upload it again. **Not a web page — filed in the Library only** means the file was stored as an ordinary Library asset because it is not an HTML file or a page bundle.

If an upload is refused with **Uploading script files is not allowed.**, remove the server-side or executable files from the `.zip` and upload again.

If a page loads without its styling, fonts or data, check that it uses relative paths to files inside the bundle. A page cannot fetch from other sites, embed other sites in frames, or load plugins. Script from `unpkg.com` and fonts from Google Fonts are allowed, so exports from Claude Design work. Other outside scripts and stylesheets are blocked.

If a page that was open for a long time stops loading its files, select **Reload**. Access to an open page is renewed each time it loads.

If **Web pages need the local asset disk** appears, The Mothership is storing assets on a disk that cannot serve pages. Ask an administrator.

If a page group action reports **A page group with that name already exists here**, **Page groups can nest at most 10 levels deep** or **A page group cannot be moved into itself**, choose another name or destination. If **Upload here**, **New page group** or **Select** is missing, ask an administrator for the permissions listed under **Before you begin**.
