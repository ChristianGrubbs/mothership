# Share assets and folders

## Before you begin

You can share one asset or one folder from the Library when your role allows sharing that target and you can access its folder. Changing, revoking or reauthorizing an existing share requires the shared-links revoke permission; deleting its record requires the shared-links delete permission. A folder share includes assets in that folder and its descendants; it does not grant the recipient a DAM account or change internal folder permissions.

The share URL contains a random access token and opens without a DAM sign-in while the share is active. Treat the URL as the recipient's access credential. This is different from the short-lived signed download URL produced by an authenticated asset-download request: a share has its own record, status and public viewer, and remains usable until it expires, is revoked or is deleted. A **No expiry** share is not permanent—it can still be revoked or deleted.

## Steps

1. In **DAM > Assets**, open an asset and select **Share**, or use **Share** for the current folder or a folder's context menu. You need asset-share or folder-share permission for the selected target as well as access to its folder.
2. Select **Create share** for the basic, unnamed **No expiry** link. To add restrictions, open **Advanced options**, enter an optional name of up to 255 characters, and choose **No expiry**, **1 day**, **7 days**, **30 days** or **365 days** before selecting **Create share**.
3. Select **Copy link** and open it in a signed-out or private browser window. An asset share opens that asset and offers its download. A folder share lists available assets from the shared folder subtree, lets the recipient open an item, and offers a ZIP download. The public viewer counts page views and attachment downloads, with a refresh by the same visitor within 30 minutes counted as one view and a repeat download within 10 minutes as one download (see Usage analytics and your privacy); it does not expose the internal Library interface.
4. Optionally, type a **Custom link** under the share's options, for example `spring-reel`. The share then also opens at `/s/spring-reel`, and **Copy link** copies that address. Use 3 to 64 lowercase letters, numbers and single hyphens; the field says at once whether the address is free. Anyone with the custom link can open the share, just as with the full link, so treat it the same way; its password and expiry still apply.
5. To change an active share, open it again from the target's **Share** action or from **DAM > Shared Links**. Under **Advanced options**, change the name, expiry or custom link and select **Save**. Updating these fields keeps the same share URL; a changed custom link keeps the old one leading to the new one.
6. Select **Revoke** to disable the link without deleting its record. Verify the guest window now shows the revoked/expired page and no longer downloads files. **Reauthorize** always clears the revocation flag; the same URL becomes usable again only when its stored expiry is still in the future. If that expiry has passed, reauthorization can report success while the recipient still sees the expired page. **Delete** in **DAM > Shared Links** removes the record; the old URL then returns not found and cannot be reauthorized.

7. To share the pages in a Web Pages group, use **Share pages in folder** in the group's menu, as described in [Web Pages](web-pages.md). It creates an ordinary 30-day link for each page directly in the group, after you confirm the count. The links are separate shares that you manage here like any other; no folder share, group link or later-added page is involved.

## Expected result

A custom link opens exactly what the full link opens, with the same password, preview-only and expiry rules. While the share is revoked or expired, its custom link answers like an address that does not exist. **Reissue** removes the custom link along with the old URL; set it again if you still want it.

An active asset share opens one asset; an active folder share opens the selected folder subtree. Recipients can preview or download only content resolved through that share token. An expired or revoked share returns an unavailable page, and a deleted or unknown token returns not found.

Changing a share's name or expiry does not issue a new token. Revocation preserves the record and URL for possible reauthorization. Deletion removes the record. None of these actions change the source asset, its folder assignments or internal account permissions.

## Troubleshooting

- **Share is missing or the request is forbidden.** For creation, confirm that your role has the matching asset-share or folder-share permission and that you still have access to the target folder. Changing, revoking or reauthorizing needs the shared-links revoke permission; deleting needs the shared-links delete permission. A visible navigation ancestor is not enough to manage content outside your grant.
- **The recipient sees expired or revoked.** Check the share's status under **DAM > Shared Links**. The current UI cannot extend an expired record with **Save**. To replace it from an asset or folder's **Share** action, keep **Advanced options** closed and select **Create share**; opening Advanced options first hides Create on that modal. The **DAM > Shared Links** modal keeps its outer Create action available. Reopen the new share's Advanced options if it needs a name or expiry. Use **Reauthorize** only for a revoked record; if its expiry has also passed, clearing the revocation does not reactivate the link.
- **"That address is already in use" or "That address is reserved".** Choose another custom link. Every address a share or collection has used stays theirs, so it never starts opening something else.
- **The custom link says not found.** The share is revoked, expired or was reissued (which removes the custom link). Open the share again and check its status and custom link.
- **The recipient sees not found.** Confirm that the complete copied URL was used and that the share was not deleted. A removed target also makes its share unavailable.
- **A folder item is absent.** Confirm that the asset belongs to the selected folder or a descendant and is eligible for the public listing. The share does not broaden its target beyond that subtree.
- **A download URL stopped working.** First identify the link type. An authenticated asset download may be a short-lived signed URL and should be requested again from the Library. A public share URL follows the share's expiry, revocation and deletion state instead.
