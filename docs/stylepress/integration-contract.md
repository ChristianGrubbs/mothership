# StylePress integration contract v1

Approved 2026-09-07. This contract connects the Mothership application to two tools that live outside this repository: the device mirror, which moves packages to and from the runtime host, and the style publisher, which builds the catalog package.

## Catalog package

Directory containing `catalog.json`, previews and reference files. JSON keys: `schema_version: 1`, `version` (content digest), `count`, `categories` (string array), `presets` (array). Each preset retains existing fields `slug`, `title`, `category`, `categoryLabel`, `nativeFormat`, `aspectRatio`, `look`, `template`, `slots`, `image`. Add `generatable` (boolean), `kind` (`style`, `workflow`, `tool`), `sourceNote` (the style's note path, relative to the publisher's style library), `references` (array of package-relative file paths). `image` is package-relative. No absolute paths, traversal or symlinks. Full style prompts, previews, sources and references are copied; reference-only entries are visible but cannot generate. All 254 inventoried style entries must be accounted for, plus any additions discovered during implementation.

Application CLI: `php artisan mothership:sync-styles /absolute/package/catalog.json` validates and installs atomically, creates/reuses AI-Generations/Images/Pre-Built-Styles/category/title directories, and prints JSON `{ok,count,generatable,version}`. Optional `--dry-run` validates without writes.

## Generation package

Directory containing `generation.json` and output/reference files. JSON:

```json
{
  "schema_version": 1,
  "generation_id": "stable-origin-prefixed-id",
  "title": "Generation title",
  "kind": "image",
  "provider": "higgsfield-generate",
  "model": "gpt_image_2",
  "prompt": "Exact final prompt",
  "original_prompt": "User input",
  "enhanced_prompt": null,
  "style": {"slug": "example", "title": "Example", "category": "04 Style", "template": "Exact template snapshot", "version": "catalog digest"},
  "settings": {"quality": "medium", "aspect_ratio": "1:1", "resolution": "2k"},
  "source": {"note_path": "Images/example.md", "job_id": null, "url": null, "generated_at": "2026-09-07T00:00:00Z"},
  "output": {"path": "output.png", "sha256": "computed checksum", "mime": "image/png"},
  "references": [{"path": "references/source.png", "sha256": "computed checksum", "name": "Source image", "role": "source"}]
}
```

Unknown historical fields are null/absent, never inferred. `style` may be null. Paths are package-relative, ordinary files without symlinks. Import verifies digests; no remote URLs are fetched by this importer. Video outputs are accepted for device mirror records.

Application CLI: `php artisan mothership:import-generation /absolute/package/generation.json` validates and imports/reconciles. Prints JSON `{ok,asset_id,action}` where action is created/updated/unchanged. Optional `--dry-run` validates. Dedupe by origin generation ID and content checksum, attaching full generation JSON, prompt text and actual reference files. Existing migrated matching outputs are enriched and filed without duplicating them.

## Transport and reverse archive

Mirror task owns transport to the runtime host; resolve all host paths live before operating. The runtime binds its state storage to /var/www/html/storage (docs/ops/studio-runtime.md). Stage beneath storage/app/private/stylepress/incoming; invoke the two artisan commands in the FPM container. Existing Docker/SSH command mechanisms must be verified before use. Never embed secrets in packages.

The application writes successful widget generation packages beneath storage/app/private/stylepress/outbox/<generation UUID>, including an archive receipt/status. Mirror reconciliation downloads these packages, archives each one with its settings and reference metadata, verifies the archive record, and marks the outbox receipt only on verified success. Keep the widget generation_id in the mirrored package to prevent loops. Application assets persist even while the device is offline; pending archive state is explicit.

The style publisher starts a mirror style sync after it has indexed a new style. The mirror's existing single-note archive hook remains compatible, now targeting Mothership. The two tools coordinate optional generation/reference flags directly and preserve older callers.

## Ownership

This repository owns the application services, CLI, schema, UI, this contract and deployment. The device mirror owns catalog export, generation packaging, transport, reverse archive/backfill and its own tests. The style publisher owns style publication and archive metadata handling. Changes to this contract are coordinated before implementation on the other side.
