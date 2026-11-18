# Documentation compatibility and release

Documentation ships with the application and uses the same authenticated Help reader. The
display name is `documentation.name`, configured by `DOCUMENTATION_PRODUCT_NAME` with an
application-name fallback. The planned The Mothership name does not rename protocol IDs, namespaces,
tables, hosts, environment keys or URLs.

## Supported interfaces

Use the integration reference for `/api/v1/rest` operations. Internal admin JSON and HTML
routes remain visible in the route inventory but are implementation interfaces. Public share
URLs are recipient workflows, not bearer APIs. HEAD aliases are accounted for
without duplicating GET prose. Feature-gated PIM routes are registered even when unavailable.
The deprecated configurable-product spelling stays documented beside its canonical spelling.

The active contract manifest selects the supported contract and classification files. The old
unfinished admin slice is preserved outside that manifest and is not advertised as complete.
Source hashes and exact method/domain/URI accounting catch release drift; they do not replace
semantic review or runtime tests. All new routes must receive an explicit classification or
contract before publication. Never mark an unreviewed JSON endpoint as an HTML page.

## Release checks

Run the manual integrity checks, API compiler tests, example tests and affected Home/help tests.
Regenerate the runtime route inventory from the exact candidate, reconcile source changes,
and rebuild the OpenAPI/coverage artifacts. Verify pages, search, downloads, permissions,
keyboard use, narrow width, theme, locale and initial/SPA navigation in the candidate.
Use one integrated review, then the project architecture analysis and graphics, before the
normal PR/merge/deploy/live acceptance flow. Preserve the previous release identity for rollback.

The example provider and widget entries are opt-in development assets. Distributing their source
must not make their routes or widgets active for production accounts. Example enablement tests
must include a separate default-disabled test.
