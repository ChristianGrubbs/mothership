# Fonts and brushes for the Craft apps

## Before you begin

**Fonts & brushes** keeps the fonts and PhotoCraft brushes you upload for the Craft apps (WordCraft, PhotoCraft and the other Craft apps). Everything you upload is shared with everyone who signs in to The Mothership as soon as it is added; you can make any of yours private or share it with groups instead. Fonts and brushes live in their own library: they do not appear among your Library files and do not use your folders.

The page has no sidebar entry; open it at `/admin/dam/hub/resources`. It is there only while `dam.hub.enabled` is on. Every signed-in account can upload fonts and brushes and share them, so no extra role is needed.

What you can upload:

- **Fonts:** TTF, OTF, WOFF or WOFF2, up to 20 MiB each. Variable fonts are accepted. Font collections (`.ttc`, `.otc`) are not; upload each face as its own file.
- **Brushes:** PhotoCraft brush files (ABR), up to 50 MiB each.
- **Space:** up to 5 GiB in total for everything you have uploaded. There is no limit on the number of files.

Upload a font only when its license allows sharing it, since uploading shares it with everyone. Many fonts may be installed on any number of computers in your organization but not passed on; the license that came with the font says which.

## Steps

1. **Open the page.** Go to `/admin/dam/hub/resources`. The page lists the fonts and brushes you can use: your own, those shared with everyone, and those shared with a group you belong to. The **Fonts** and **Brushes** tabs show how many of each there are. The meter at the top right shows how much of your 5 GiB you have used, for example **1.2 GiB of 5 GiB used**.
2. **Upload.** Drag one or more files from your computer anywhere onto the page, or select **Upload files** (or **choose files** in the drop area) and pick them. An **Uploads** list appears with one line per file. While a file is sending it shows a progress bar and **Uploading…**. When it is done, the line says what The Mothership read from the file, for example **Added: Inter · Bold · 700 · OTF** (it is now shared with everyone, with the license note **Shared on upload**), or **Already in your library:** when you had uploaded the same file before. A file that cannot be used says **Not added:** and why, for example **Font collections (.ttc, .otc) are not accepted. Upload each face as its own .ttf, .otf, .woff or .woff2 file.** Select **Clear finished** to empty the list.
3. **Check a font.** Each font's name is drawn in the font itself, so you can see what it looks like. Under it are the file name, the style, the weight (400 is regular, 700 bold), **Variable** for a variable font, the format and the size. A brush shows its name with **PhotoCraft brush**, ABR and its size.
4. **Rename.** Select **Rename** on one of your own fonts or brushes, type the new name and press Enter or select **Save**. Escape or **Cancel** leaves it as it was.
5. **Share.** Select **Sharing** and choose who can use it:
   - **Only me**: only you see it.
   - **Everyone**: everyone who signs in to The Mothership can use it.
   - **Groups**: members of the groups you tick can use it.

   To change several at once, tick the box beside each of your own fonts or brushes (or **Select all of yours** above the list), then select **Share with everyone** or **Make private**. A message says how many were updated.

   In **Sharing**, to share with everyone or with groups, write a **License note** (the name of the license, for example SIL Open Font License 1.1, or where it was bought) and tick **I confirm the license allows sharing this with these people.** **Save** stays off until both are done, and you confirm again each time you change who can use it. The **Who can use it** column then shows **Only me**, **Everyone** or the groups, and the **License** column shows **License noted**; hover over it to read the note.
6. **Delete.** Select **Delete** on one of your own fonts or brushes and confirm. Anyone it was shared with loses it too. The space it used is freed.

## Expected result

Your fonts and brushes are listed on **Fonts & brushes** with what The Mothership read from each file, who can use it and its license note, and the meter shows the space they use. Shared fonts and brushes appear for the people you chose. Others' shared fonts and brushes appear in your list with their owner's name; you can use them but not rename, share or delete them.

## Troubleshooting

- **Not added: The file is … MiB. The limit is 20 MiB.** (or 50 MiB for a brush) The file is over the size limit. Use a smaller file, for example the WOFF2 version of a font.
- **Not added: This upload would take your fonts and brushes past 5 GiB.** Delete fonts or brushes you no longer need, then upload again.
- **Not added: This is not a font … or a PhotoCraft brush (ABR).** or **This font could not be read. It may be damaged.** The file is not a font or brush The Mothership can use, or it is damaged. Download it again from where you got it.
- **Not added: Too many uploads in a minute.** Wait a moment, then upload the rest.
- **Not added: Your session ended.** Sign in again and upload the file again.
- **Save stays off in Sharing.** Write a license note and tick the confirmation, and for **Groups** tick at least one group.
- **Preview unavailable** after a font's details. The page could not draw that font. It is still in your library; check it in the app you use it in.
- **There is no Rename, Sharing or Delete on a font.** It belongs to someone else. Only its owner can change it.
