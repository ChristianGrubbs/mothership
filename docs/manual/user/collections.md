# Collections

A collection is a hand-picked, ordered set of files from any Library folders, shared with the people and groups you name, or through one public link. It can expire. (The Web Pages area's folders are called **page groups**; they are not collections.)

## Before you begin

Open **DAM > Collections** in the sidebar. Anyone signed in sees the collections shared with them under **Shared with me**. Creating a collection needs the **Collections > Create** permission, and adding files, changing the audience or creating a link needs **Collections > Update**. Only a collection's owner and full administrators can change it.

To add a file you must be able to see it in the Library, and you must own it, be a full administrator, or have permission to share assets. Otherwise The Mothership refuses the whole request and adds nothing.

## Steps

1. In **DAM > Collections**, select **New collection**. Enter a name and, optionally, a description.
2. Under **Who can see it**, choose **People and groups** and search for each person or group to add, or choose **Public link**.
3. Optionally set **Expires on**. On that day the collection closes for everyone but its managers.
4. Optionally set an **Address**: 3 to 64 lowercase letters, numbers and single hyphens. The collection's short URL is then `/c/your-address`.
5. Select **Create collection**. Then, in the Library, select files and choose **Select action > Add to collection**, or open a file and select **Add to collection**.
6. On the collection's page, use the **↑** and **↓** buttons on each file to change the order, or **Remove** to take a file out. The files stay in the Library.
7. For a **Public link** collection, choose the link's expiry, an optional password, whether downloads are allowed, and whether the link closes if you lose access, then select **Create link** and **Copy** its address. **Reissue** replaces the address; **Revoke** closes the link.

## Expected result

People in the audience see the collection under **Shared with me** and can open, preview and download its files there. Sharing a file through a collection does not show it to them anywhere else: in the Library, search, Find similar, the REST API or folder ZIPs it stays hidden unless they could already see it.

A public link shows the files in the collection's order, with the link's password, download and expiry rules. Only files that are available are shown or counted: a withdrawn, pending, out-of-date or trashed file is left out until it is available again. A removed file stops opening at once.

## Troubleshooting

- **The collection says it has closed.** It has expired or been archived. Its owner can extend the date or make it active again under **Edit**; the files and audience are kept.
- **A file shows "Not available".** It is withdrawn, pending review, outside its availability dates or in Trash. Fix the file, or remove it from the collection.
- **"You can only add files you own…"** You can see the file but may not share it. Ask its owner or an administrator to add it.
- **"That address is already in use" or "That address is reserved".** Choose another address. A collection's old addresses keep redirecting to its current one, so no other collection can take them.
- **The short address asks a recipient to sign in.** For a **People and groups** collection the address only leads to the collection page; recipients must sign in and be in the audience. Only a **Public link** collection with an active link opens without signing in.
- **I cannot see the link's address.** Only the link's creator, the collection's owner and full administrators see it.
