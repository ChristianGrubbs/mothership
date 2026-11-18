# Extension development

The fork is a Laravel application with owned packages under `packages/Mothership`. A small extension
can be an ordinary Composer package with a service provider; it does not need a new framework,
a Concord module, or a change to the core asset graph.

## Working example and installation

The repository contains `examples/documentation-extension/`, package name
`mothership/documentation-example`, namespace `Mothership\DocumentationExample`. Its Composer file
requires PHP ^8.4.1 and Illuminate Support ^13.0, maps the namespace to `src/`, and declares
`DocumentationExampleServiceProvider` for Laravel package discovery. It is not installed by
the root Composer manifest and has no production effect by default.

In a disposable development checkout, add a Composer path repository whose URL is
`examples/documentation-extension`, explicitly require `mothership/documentation-example` with
a development constraint, and regenerate the application caches using the project's normal
build procedure. Review the resulting Composer manifest and lock changes before deployment.
The example then serves the configured admin prefix plus `/documentation-example` and
`/documentation-example/data`. Its JSON response is:

```json
{"message":"Extension registered"}
```

Grant the `documentation-example` permission to the intended role. The page and JSON action
both explicitly check it. An authenticated account without that permission receives 403.
The tests register the provider per test; a separate test proves absence without registration.

## Provider lifecycle and extension seams

`register()` merges the package's `config/acl.php` into `acl` and `config/menu.php` into
`menu.admin`. `boot()` loads namespaced Blade views and translations, then registers routes
with `web` and `admin` middleware under `config('app.admin_url')`. Keep JSON routes subject to
the same permission checks as pages. For a bearer API, use the existing API middleware and
API ACL separately; the browser example is not bearer-authenticated.

Put validation in a FormRequest when adding writes. Return explicit response fields rather
than exposing arbitrary request data. Use the installed `request.locale` behavior when a
public integration API needs locale selection. Add translations under the actual locale
codes used by the fork (`en_US`, for example). The sample view uses an escaped translation
and the existing admin layout.

Models that participate in Concord proxy substitution need a module provider and a deliberate
entry in `config/concord.php`; this example owns no models, so it omits that machinery.
A package with tables can call `loadMigrationsFrom` from its provider. Keep migrations
forward-only and exercise install/update/uninstall in a disposable database. Never make
uninstall silently delete asset storage or user records.

Use the existing view render events for small additions: search the target Blade template
for `view_render_event`, listen to that exact event in a provider, and render a namespaced
partial. For domain changes, inspect the real event dispatch and payload before registering
a listener. A matching event name alone does not establish a stable payload contract.

## Build, test, update and removal

The sample contains PHP and Blade only, so it needs no frontend build. Extensions with JS/CSS
must integrate with the owning package's build/manifest and the deployment image. A Git pull
does not replace PHP code baked into FPM images; use the established release workflow.

`tests/Feature/DocumentationExamplesTest.php` exercises explicit provider registration,
permission denial and default absence. Add validation, unauthorized writes, migrations and
API contract tests as your extension grows. When updating, reconcile route inventory and
source hashes, review the API contract and run both example and affected application tests.

Remove the explicit Composer requirement to uninstall the sample, regenerate discovery and
caches, and verify the routes/menu disappear. The sample has no database schema or stored data
to remove. Roll back an extension release through a forward corrective commit and deployment.

See [native widgets](native-widgets.md) and [iframe tools](iframe-tools.md).
