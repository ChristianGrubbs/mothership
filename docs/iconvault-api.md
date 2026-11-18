# Iconvault integration notes

Iconvault is a separate icon library service that The Mothership can link to and show inside a dialog. It is not part of this repository and The Mothership does not ship, install or configure it. These notes describe only the contract between the two.

The Mothership links to Iconvault only when `MOTHERSHIP_ICON_LIBRARY_URL` is set (config `dam-home.icon_library_url`). There is no built-in address: unset hides the Icon Library card, link and dialog.

## How The Mothership uses it

As of 2026-09-21 The Mothership only links to Iconvault and embeds its page in the Library/Home dialog (`packages/Mothership/DAM/src/Resources/assets/js/iconvault-dialog.js`). The server-side icon proxy (search/styles/img routes and the `iv:` icon ids stored in documents) belonged to a module that was removed on 2026-09-21. Nothing in The Mothership calls the Iconvault API from the server.

### Framing

The dialog shows Iconvault only if Iconvault's `Content-Security-Policy` `frame-ancestors` list names the origin The Mothership's page is served from. That list is set on the Iconvault side, on the response that serves its page. It has to name every host The Mothership is served from, for example `https://mothership.example.com`, `https://mothership.example.net` and `https://mothership.example.org`. When The Mothership gains or loses a host, that list changes with it.

A list that names a retired host makes browsers refuse the frame on every current host. The dialog then shows the fallback described below, not an empty frame.

### Knowing whether it opened

An iframe's `load` event also fires for a refused frame, so the dialog does not trust it. After `load` it posts `{type: 'mothership:iconvault:hello', attempt: N}` to the Iconvault origin, once a second for four seconds. The contract is that Iconvault answers `{type: 'mothership:iconvault:ready', attempt: N}` to a parent on its list, echoing the number it was sent. `N` goes up with every load ("Try again" reuses the iframe), so an answer left over from an earlier load does not count.

With an answer the status reads "Iconvault loaded.". With none, or with no `load` event within 15 seconds, the frame is replaced by "Iconvault could not be shown inside The Mothership." with an "Open Iconvault in a new tab" link and "Try again". An Iconvault deployment that does not implement the answer always gets the fallback, which is accurate while the frame is refused.

## What an Iconvault deployment must provide

| Requirement | Why |
|---|---|
| An HTTPS origin, set as `MOTHERSHIP_ICON_LIBRARY_URL` | The card, the link and the dialog all point at it. |
| `frame-ancestors` naming every Mothership host | Browsers refuse the frame otherwise. |
| The `mothership:iconvault:ready` answer | The dialog cannot tell a refused frame from a loaded one without it. |

Sign-in, hosting, licensing of the icon sets and the icon API belong to the Iconvault deployment and its maintainer. The Mothership stores no Iconvault credential and embeds no icon files from it.

## Notes for a future server-side consumer

These points are kept from the review made before the proxy was removed. They describe the API as it was observed on 2026-09-11 and may have changed.

- **Cross-origin requests.** The icon API and asset responses sent no `Access-Control-Allow-Origin` header, so a browser `fetch()` from The Mothership's origin is blocked, and images drawn on a canvas from that origin taint it. A consumer needs a server-side proxy, or a CORS change on the Iconvault side.
- **Identity.** Store the icon's `id` field from a search result, not a path parsed out of `previewUrl`. The id is stable across re-indexing; the preview path is a rendering detail.
- **Formats.** `svg`, `png`, `gif` and `apng`. There is no `webp`. Most sets are raster only, so a consumer cannot assume an SVG exists.
- **Rate limits.** Generated PNG sizes were rate limited per client address; stored assets and search were not. Bulk export ran one request at a time for the whole service.
- **Licensing.** The API returns no per-icon license text. Before icons from an Iconvault deployment are embedded in content shared outside the organization that runs it, check the license of each icon set with that deployment's maintainer.
