# Folder access and favorites

## Before you begin
Open **DAM > Assets**. The Library shows folders and assets available to your account. Access can come from a folder granted directly to your account, a group you belong to, or your role. Folder access decides where you can work; your role permissions separately decide what you can do there, such as view, edit, upload, or delete.

Access can extend down the folder tree in two distinct ways. An administrator can select **Include subfolders** on a direct user or group grant. Separately, a role can be assigned folders and use its own **Inherit Sub-directories** setting. In either case, inheritance applies from a granted folder to its descendants. A parent folder shown only as a path to a granted child is navigation, not a grant: seeing that ancestor does not give access to its own files or to every other descendant.

Favorites are personal to the signed-in account. An empty star (**☆**) on an accessible asset adds it to that account's favorites; a filled star (**★**) removes it. Another account does not receive the favorite, even when both accounts share the same role or folder access.

## Steps
1. In **DAM > Assets**, open a folder granted to your test account and locate an accessible asset. If you entered through a visible ancestor, continue to the specifically granted child before expecting its files or actions to appear.
2. Select the asset's empty star (**☆**). Its label is **Add to favorites**, and it changes to a filled star (**★**) with the label **Remove from favorites**.
3. Open **Home** and reload the page. Confirm the asset appears in the **Favorites** widget. If the widget is not on your Home layout, add **Favorites** from the Home widget catalog; removing that widget does not erase saved favorites.
4. Return to the asset card or preview in the Library and select the filled star (**★**) to unstar it. Reload **Home** and confirm the asset no longer appears in **Favorites**.
5. Sign in with another test account that can access the same asset. Confirm the other account does not show the first account's favorite. Starring or unstarring the asset there changes only that second account's favorites.

## Expected result
The starred accessible asset appears in the signed-in account's **Favorites** widget after Home reloads. Unstarring removes it from that account's widget, and another account has an independent favorite state.

Inherited folder access makes the granted descendants available without turning role permissions into additional actions. A navigation-only ancestor remains visible as a path but does not expose its own files or unrelated descendants.

## Troubleshooting
If a folder is visible but contains no files or actions, it may be a navigation-only ancestor. Continue to the granted descendant. If an expected descendant is missing, ask an administrator whether the applicable user or group grant has **Include subfolders**, or whether the role's separate **Inherit Sub-directories** setting covers it. Ask an administrator for any role permission change; only administrators with full access can manage folder grants and groups.

If starring fails or an existing favorite disappears, confirm that the asset is still in a folder available to your account and that your role includes asset access. Favorites are filtered by current folder access, so removing your access makes the asset disappear from the Home widget even though another account can still see its own independently saved favorite. If access is restored and the state is unexpected, reload the Library to refresh the star, then reload Home. A deleted asset cannot remain in favorites.
