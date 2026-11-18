# Native Home widgets

A native widget renders inside the Home page. Three seams must agree: the registry entry,
`HomeController::data`, and the `fillNative` renderer in `home.js`. A registry entry alone
cannot render a new native widget.

## Working example

`examples/widgets/registry.php` defines `docs-count`, disabled by default. It uses kind `native`,
permission `help`, sizes `small` and `wide`, and empty settings. The existing controller and
renderer contain a small branch for that ID. After deliberate development registration,
`GET /admin/home/data/docs-count` returns `articles`, the count of manual entries visible to
the current reader. Administrator-only articles are excluded without `configuration`.
The renderer assigns textContent so response values do not become HTML.

To activate in a development provider, merge only the selected entry into `dam-home.widgets`:

```php
$examples = require base_path('examples/widgets/registry.php');
config(['dam-home.widgets.docs-count' => $examples['docs-count']]);
```

A deployment should place deliberate permanent entries in the owning configuration instead
of depending on a runtime config edit that disappears under caching. No default configuration
entry enables this sample. Custom roles may also need `docs-count` in their allowed Home
widget IDs; the `help` permission by itself does not override that role allowlist.

## Layout, settings and access

`HomeWidgetRegistry::catalog()` filters optional permissions and role widget IDs. `allowed()`
returns 404 for unknown IDs and 403 for denied entries; the data action calls it before loading
data. Keep authorization before any database query or expensive computation.

Layout accepts at most 24 distinct widgets with keys `id`, `size`, `columns`, `rows`,
`grid_version`, and `settings`. Current responses include `grid_version: 2`, with columns
1–8 and rows 1–4. Preserve that marker when saving a response. A 1×1 tile occupies one
fixed-height row and one of eight desktop columns; overflowing content scrolls inside it.
Responsive packing clamps the displayed width without changing the stored span.

Unversioned legacy widgets accept columns 1–4 and translate their width to the finer grid
once (1→2, 2→4, 3→6, 4→8). Legacy sizes therefore normalize to small 2×1, wide 4×1,
and large 8×2 in version 2. Registry defaults already carry version 2. The JSON marker
prevents repeated conversion on read/save; no database migration or layout reset is needed.
Unknown settings are rejected. Only the built-in
recent/favorites/rss/links widgets currently have settings schemas, so these examples use `[]`.
A new configurable widget must add real validation, defaults and malformed-setting tests.

Layout is stored per account. PUT layout includes the current integer `revision`; a stale
revision returns 409. Read the latest layout before resolving that conflict rather than
silently overwriting another tab's changes. Server responses are private/no-store.

## Extending the sample

Add a registered ID, then a data branch returning only authorized data, and a corresponding
client renderer. Keep request parameters explicit and bounded, handle empty/error/loading
states, and avoid unescaped HTML. Test normal absence, allowed/denied roles, account filtering,
malformed settings, stale revisions and browser rendering. The sample's PHP test activates the
entry only in its own application instance.

Native widgets have no iframe bridge. Long-running interactive applications usually fit the
[iframe tool](iframe-tools.md) path instead. [Extension lifecycle](extensions.md).
