# ADR 0002: Assets are concepts with typed file roles, versions, renditions, and relationships

Date: 2026-09-01 · Status: accepted

## Context
The inherited DAM modelled one uploaded file as one `Asset` row. Real work is compound: a GLB with textures and
references, a 3MF with plates and materials, a DJI OSV with LRF/SRT/GPX sidecars and a stitched proxy,
a video with captions and transcript, a generated image with its prompt, mask, and inputs. Inferring
parentage from filenames is not acceptable.

## Decision
Add five first-class records alongside the existing DAM asset row (which stays the human-facing
`Asset` and keeps every current DAM feature working):
- `FileObject` — immutable blob: sha256, size, storage disk + path, mime, sniffed type, technical
  metadata JSON (dimensions, duration, codec, etc.).
- `AssetFile` — a `FileObject` attached to an `Asset` under a **role** (original, primary, proxy,
  thumbnail, texture, plate, sidecar, subtitle, transcript, source, output, mask, prompt, project,
  calibration, other) with ordering and a label.
- `AssetVersion` — lineage: version number, created_by, note, pointer to the `AssetFile` set that was
  primary at that version.
- `Rendition` — reproducible derivative: recipe name + parameters JSON, worker + worker version,
  status (queued/running/done/failed), error, output `FileObject`.
- `AssetRelationship` — typed directed edge asset→asset with `type` (contains, uses-texture,
  sidecar-of, version-of, rendition-of, subtitle-for, transcript-of, derived-from, generated-from,
  alternate-of, source-for, related-to, or a custom string) and metadata JSON; unique on
  (from, to, type).

Rules: originals are never modified in place; relationships are edited visibly, never only inferred;
delete/move/export are dependency-aware (a delete that would orphan a required file or dangle a
relationship must warn or refuse); a compound asset exports with its graph as a manifest.

## Consequences
- Migrations are forward-only and numbered; the existing `dam_assets` table is extended, not replaced.
- The completed historical import mapped related assets to `related-to` edges and alternative files to
  `AssetFile` roles, so the migration exercises the graph from day one.
- Specialist viewers (3D, 360, OSV) later read the graph instead of guessing from filenames.
