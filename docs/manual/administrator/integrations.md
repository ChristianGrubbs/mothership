# Create and revoke scoped integrations

## Before you begin

Sign in with an administrator account that can view, create, edit and delete **Configuration → Integrations** records. Decide which API operations the client needs and choose one harmless allowed request plus a neighboring request that must be denied. Use an isolated integration for the check so you can revoke it without interrupting another client.

An Mothership integration is separate from a browser account. The browser screens use your signed-in administrator session. Creating an integration provisions a dedicated API user; an external client exchanges that integration's OAuth client and API-user credentials for an access token, then sends the token as bearer authentication to REST API routes. A browser session cookie is not an API bearer token.

## Steps

1. Open **Configuration → Integrations**, select **Create**, enter a recognizable name, set **Permissions** to **Custom**, and save. Creation provisions the integration's dedicated API user and opens **Edit Integration**.
2. Under **Access Control**, select only the permissions the client requires, then select **Save**. Do not use **All** for a least-scope fixture. Each protected API route is mapped to an API permission; a custom integration receives `403` with `This action is unauthorized` when that route is not selected.
3. Open **Credentials** and select **Generate** if no OAuth client exists. Record the displayed **Client ID**, **Secret Key**, **API Username** and one-time **API Password** in the integration's approved secret store. The password cannot be retrieved later. Do not place any of these values in documentation, source control, logs or browser test fixtures.
4. Have the client request an OAuth access token with the password grant, then use that token as bearer authentication for REST API calls. Keep the token exchange and API calls outside the browser-management session. Test the selected harmless operation and confirm it succeeds; call one neighboring unselected operation and confirm it is denied.
5. To change scope, return to **Edit Integration**, update the custom permission tree and save. Repeat both the allowed and denied requests with a current token so the verification covers the intended permission boundary rather than login alone.
6. To rotate credentials, use **Re-Generate Secret Key** or **Regenerate password**. Either rotation revokes access tokens issued for the integration's OAuth client, so the client must obtain a new token with the newly displayed value.
7. To revoke the fixture, delete it from **Configuration → Integrations** and accept the confirmation. Deletion removes its OAuth client and marks the integration revoked. Confirm the previous bearer token no longer reaches a scoped endpoint before removing the fixture from the external client's configuration.

For StylePress, provider access is configured on the Mothership server rather than in an API integration or a user's browser. The relevant configuration keys are `STYLEPRESS_ENABLED`, `STYLEPRESS_HIGGSFIELD_DRIVER`, `STYLEPRESS_HIGGSFIELD_CLI_BINARY`, `STYLEPRESS_HIGGSFIELD_CLI_HOME`, `STYLEPRESS_HIGGSFIELD_ACCOUNT_EMAIL`, `STYLEPRESS_HIGGSFIELD_WORKSPACE_ID`, `STYLEPRESS_HIGGSFIELD_API_BASE_URL`, `STYLEPRESS_HIGGSFIELD_KEY_ID`, `STYLEPRESS_HIGGSFIELD_KEY_SECRET`, `STYLEPRESS_HIGGSFIELD_ESTIMATE_PREFIX`, `STYLEPRESS_HIGGSFIELD_BALANCE_ENDPOINT`, `STYLEPRESS_HIGGSFIELD_MODEL`, `STYLEPRESS_ALLOWED_EMAILS` and `STYLEPRESS_ALLOWED_USER_IDS`. Configure provider account values only in the deployment's secret/configuration layer. Mock the provider connection during integration acceptance; do not submit a paid generation.

Image Vectorization is also a server-side provider integration. Set `MOTHERSHIP_VECTORIZATION_ENABLED`, `MOTHERSHIP_VECTORIZATION_OWNER_ADMIN_ID`, `MOTHERSHIP_VECTORIZER_API_ID` and `MOTHERSHIP_VECTORIZER_API_SECRET` in the deployment configuration. The configured owner administrator is unlimited, and so is every administrator id listed, comma-separated, in the optional `MOTHERSHIP_VECTORIZATION_UNLIMITED_ADMIN_IDS` (for example an agent account that converts through the API). All other accounts default to zero available vectorizations.

To allocate vectorizations, open **Settings → Users**, edit a human account and set **Available vectorizations** from 0 through 1,000,000. You need the `settings.users.users.edit` permission. Available balance does not expire; for limited accounts, queued and processing work appears separately as **in progress**. One completed conversion consumes one available vectorization, while a failed conversion returns its reservation. If another administrator changes the balance first, the save reports **Balance changed. Reload before saving.** and loads the current value for a deliberate retry. The owner's allowance control reports **Unlimited** without an in-progress count and cannot be changed.

## Expected result

The isolated integration authenticates independently of the administrator's browser session. Its bearer token can perform the selected API operation, receives a forbidden response for the neighboring unselected permission, and stops working after the integration is revoked. A credential rotation invalidates previously issued tokens and exposes the replacement secret or password only for the current response.

Provider credentials remain server-side. StylePress and Image Vectorization users and API clients never receive provider account keys, and an Mothership integration permission does not grant a user access to a provider account. Vectorization results and SVG/PDF exports are private to the submitting account; repeated downloads use stored outputs instead of making another provider request.

## Troubleshooting

- **The Integrations page or an action is forbidden.** The signed-in browser administrator needs the matching `configuration.integrations` view, create, edit or delete permission. These management permissions are distinct from the permissions assigned to the API integration.
- **Credentials are masked or the API password is unavailable.** One-time values are not recoverable. Use **Re-Generate Secret Key** or **Regenerate password**, update the external secret store, and request a new token.
- **The token request fails.** Confirm the integration's dedicated API user is active and that the client is using the four credentials displayed for the same integration. Browser cookies do not authenticate the REST API.
- **Authentication succeeds but an API call returns `This action is unauthorized`.** Add only the route's required API permission, save the integration, and retry with a current token. Selecting **All** would hide the scope error instead of proving least access.
- **A token still appears cached after rotation.** Rotation revokes issued tokens server-side. Remove the old token from the client and perform a fresh token exchange; do not reuse a bearer value captured before rotation.
- **A provider-backed feature cannot connect.** Verify the named server-side configuration keys and the deployment secret source, then use a mocked provider connection for acceptance. Do not copy provider credentials into the integration record or this article.
- **A vectorization balance cannot be saved.** Confirm you can edit users, the value is between 0 and 1,000,000, and the account is not the configured unlimited owner. If the balance changed concurrently, review the newly loaded value before retrying.
- **A vectorization failed or became stale.** Its reserved allowance is returned. The worker makes one paid attempt and does not automatically retry it; correct the service problem before the user submits again.
