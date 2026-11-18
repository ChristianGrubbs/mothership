# Manage accounts, roles and folder access

## Before you begin

Sign in with an account whose role has **All** permissions. Only an all-access administrator can create or edit unrestricted roles and open a folder's **Manage access** dialog. Plan the smallest access the new account needs: one custom role, the required application permissions, only the Home widgets it should see, and either role-level directories or direct folder grants.

Keep the two access layers separate. A role controls actions such as viewing, uploading, editing and deleting. A folder grant controls which part of the library the account can reach; granting a folder never adds an action permission. Custom roles also have a Home-widget allowlist, and a widget that declares a permission appears only when both the allowlist and that underlying permission permit it.

## Steps

1. Open **Settings → Users → Roles**, create a role, and give it a descriptive name. Under **Access Control**, leave **Permissions** set to **Custom**. Do not choose **All** for a least-scope test role.
2. Select only the required permissions. For a documentation-and-library test account, expand **Advanced permissions**, select **Help**, and use the **Browse, upload, and download library files** capability. Add another capability only when the account must perform that action. **Use Sandbox defaults** selects every displayed DAM capability and every Home widget, so review and clear anything the account does not need.
3. Under **Home widgets**, select only the widgets the role may add. A library-backed widget such as **Recent files** also needs the library permission selected in the previous step. A widget omitted here is removed from that custom role's catalog and from a saved Home layout the next time it is read. An all-access administrator can also right-click any widget card on Home: the menu lists every custom role with a checkbox for that widget, saves immediately, and grants the permission the widget declares when the role lacks it. Unticking removes only that widget's allowlist entry.
4. Under **DAM Directory Permissions**, leave **All Directories — grant access to every directory now and in the future** off. Select the test folder. Turn on **Inherit Sub-directories — automatically access all subdirectories within granted directories** only when the role should reach descendants, then save the role.
5. Open **Settings → Users**, choose **Create User**, enter the name, email and password, assign the custom role, turn on **Status**, and save. Turning Status off later makes the account inactive; an inactive signed-in account is logged out on its next request.
   To let someone sign in with Google instead, follow **Sign in with Google and grant access** below. There is no self-service sign-up.
6. If one account or group needs a folder outside the role's directory selection, open that folder and choose **Manage access**. Under **Add a user or group**, select it; under **Granted on this folder**, choose **Include subfolders** only when needed, then select **Save access**. Wait for **Access saved.** Direct grants appear in this section. Parent-folder and role grants appear separately under **Access from parents and roles**.
7. Sign in as the test account and verify the allowed path: open **Help**, open a documentation article, open the selected folder, and add the allowed Home widget. Then verify denial at each neighboring boundary: an unselected folder, an unselected widget, and an operation whose permission was not granted. Use a separate browser profile so the administrator and test-account sessions do not replace each other.
8. To revoke access, use the narrowest source. Remove a direct entry from **Granted on this folder** and save; clear a role directory or Home widget in the role editor; remove an application permission under **Advanced permissions**; or turn off the account's **Status**. If access remains after removing a direct grant, check **Access from parents and roles** and change the named parent folder or role instead.

## Sign in with Google and grant access

Google sign-in needs a Google Cloud OAuth client of type **Web application** whose authorized redirect URI is `{APP_URL}/{APP_ADMIN_URL}/login/sso/google/callback` (for a local install, `http://localhost:8000/admin/login/sso/google/callback`). Put its values in `.env` as `GOOGLE_SSO_ENABLED=true`, `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`; keep the secret in the Keychain, not in a file you share. The sign-in page then shows **Sign in with Google**.

A Google account signs in only as the account it is linked to. An email address that matches an existing account does not link it. The first time an unlinked Google account signs in, it lands on **Waiting for access** with a request number, and nothing else happens until an administrator acts.

### Approve someone who is waiting

Open **Settings → Users** with an account whose role has **All** permissions. Other roles do not see these lists, even if they can manage users. **Waiting for access** shows each person's email, name, provider, whether the provider verified the email, when they asked and when the request expires (24 hours after their last sign-in; at most 20 wait at once).

1. For a new person, choose **Approve**, pick a role and confirm. This creates the account with that role and links the Google account. The account has no password.
2. For someone who already has an account, choose **Link to account** and pick the account. They sign in as that account and keep its role. **Approve** is unavailable when an account already uses the email.
3. To turn someone away, choose **Deny**. The request leaves the list. Nothing blocks that Google account: if it signs in again, it waits as a new request.
4. Once approved or linked, they sign in with Google again and land on Home.

A request whose email the provider did not verify can only be denied.

### Preapprove someone before they sign in

Under **Preapproved** on the same screen, enter the email, pick a role and choose **Add**. When someone signs in with Google using that exact address, and Google has verified it, their account is created with that role and they land on Home without waiting. Each entry works once and then leaves the list. Choose **Remove** to withdraw an entry that has not been used.

An email that already has an account cannot be preapproved, because a preapproval never links to an existing account. If an account with that email appears after you add the entry, the entry is marked and is not used: that sign-in waits for access, and you link it from **Waiting for access**. Deleting a role also removes the preapprovals that use it.

### Manage access from the command line

The same decisions are available on the server:

- `php artisan mothership:access:list` shows the waiting requests.
- `php artisan mothership:access:grant <number> --role="<exact role name>"` approves a request. Use `--admin` instead of `--role` only for an all-access administrator.
- `php artisan mothership:access:link <account id or email> <number>` links a request to an existing account.
- `php artisan mothership:access:revoke <account id or email>` removes an account's Google links (`--provider=google` for that provider only); add `--deactivate` to turn off **Status** too. It refuses to deactivate the last active all-access administrator unless you add `--force`.

Requests, approvals, links, denials, preapprovals, removals, preapproved sign-ins and revokes are logged to `storage/logs/access-grants.jsonl`.

To turn off email-and-password sign-in, set `MOTHERSHIP_PASSWORD_LOGIN=false`. The sign-in page then shows only provider buttons, and the password, forgot-password, reset-password and invite pages answer 404. Password sign-in stays on while no provider is enabled, so the page is never empty.

## Expected result

The active test account can open Help and its articles, see only the role-approved Home widget catalog, reach only the granted folder scope, and perform only the actions represented by its role permissions. Its Home layout is stored per account, so arranging or resetting the test account's widgets does not change another account's layout.

A direct user or group grant belongs to the folder where it was added. **Include subfolders** extends that grant to descendants. Inherited access remains visible under **Access from parents and roles** and survives removal of a direct grant on the child folder until its actual parent-folder or role source is changed.

## Troubleshooting

- **The account is returned to sign-in.** Confirm **Status** is on. A custom role must also retain at least one permission; an empty custom role is rejected.
- **Help or a documentation article returns a forbidden response.** Add the **Help** permission to the custom role and save it. Access to the Help page and article routes uses that permission.
- **A Home widget is missing.** Confirm the widget is selected under **Home widgets** and that its underlying application permission is selected. For **Recent files**, **Favorites**, **Storage**, **Your library**, **StylePress**, **Image Vectorization** and **Video Loop Refiner**, the library permission is required as well as the role allowlist entry. **SphereSeam** needs the *Repair panorama seams with SphereSeam* capability; a role saved before that capability existed keeps the widget ticked but never received the permission, so reopen the role, tick the capability, and save.
- **Image Vectorization opens but cannot submit.** Every account starts with zero conversions. Open **Settings → Users → Edit** for that account and set its available conversion count; only the configured owner account is unlimited.
- **A folder is visible but an action is forbidden.** The folder grant supplies reach, not capability. Add the specific role permission for the action; do not replace the role with **All** merely to make the test pass.
- **Folder access remains after Remove.** The removed item was only a direct grant. Read **Access from parents and roles**, then revoke the named parent grant or edit the role's directory selection. **All Directories** on the role also keeps every current and future folder available.
- **Manage access is forbidden or absent.** Reopen the folder as an account with an **All** permissions role. Custom-role accounts cannot manage folder grants, even when they can browse that folder.
