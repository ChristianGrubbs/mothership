# Documentation index

One line per place under `docs/`. Repository-level guides sit at the root: [README.md](../README.md) (setup),
[CONTRIBUTING.md](../CONTRIBUTING.md) (workflow and CI), [CONTEXT.md](../CONTEXT.md) (glossary and package map),
[AGENTS.md](../AGENTS.md) (agent entrypoint).

## Start here

- [CODING_STANDARDS.md](CODING_STANDARDS.md) — the short, enforceable code rules.
- [Static analysis and lint guardrails](reference/guardrails.md) — what PHPStan, Pint and the frontend lint gates check, and how to run them.
- [../CONTEXT.md](../CONTEXT.md) — domain terms and the Mothership-versus-upstream package map.

## Decisions and designs

- [adr/](adr/) — Architecture Decision Records, numbered 0001 onward. Read the relevant one before changing that area (0001 is the fork decision, 0009 the content-addressed storage, 0010 access lists, 0017 asset bytes on a remote object store).
- [stylepress/](stylepress/) — the StylePress design, integration contract and implementation ledger.

## Using and running the product

- [manual/](manual/) — the user, administrator and developer guides. This is the source of the in-app Help center (`manual/manifest.json` lists the articles; `tools/docs/check_manual.py` checks them).
- [features/](features/) — feature guides: [Home](features/customizable-home.md), [Home calendar](features/event-calendar.md), [folder access](features/folder-access.md), [Transfers](features/transfers.md).
- [ops/](ops/) — operations runbooks and acceptance notes: the Studio runtime ([studio-runtime.md](ops/studio-runtime.md)), asset bytes on an object store ([object-storage.md](ops/object-storage.md)), storage and directory integrity, previews (DNG, 360, 3D), uploads, and the Studio AI, depth and clop services.

## Generated and reference material

- [api/](api/) — the generated route and contract inventory (`routes.json`, `openapi.json`, `acl-map.json` and friends). Built by `tools/docs/build_api.py`; refresh `routes.json` as CONTRIBUTING describes.
- [architecture/](architecture/) — the documentation system's own source map and its standalone HTML artifact.
- [reference/](reference/) — standing reference notes, for example [Adobe application marks](reference/adobe-application-marks.md).
- [iconvault-api.md](iconvault-api.md) — integration notes for the Iconvault icon service.
- [rss-ticker.md](rss-ticker.md) — the global Signal ticker.
