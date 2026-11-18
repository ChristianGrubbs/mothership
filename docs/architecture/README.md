# Documentation architecture

Source map for the documentation release; the HTML is a standalone Archify artifact.

- Runtime routes: `app/Documentation/RouteInventory.php`.
- Reviewed contracts: `docs/api/contract-files.json`.
- API compiler: `tools/docs/build_api.py`.
- Published reference: `docs/api/openapi.json`.
- Help center: `packages/Mothership/Admin/src/Http/Controllers/DocumentationController.php`.
- Readers: `packages/Mothership/Admin/src/Resources/views/help/index.blade.php`.
