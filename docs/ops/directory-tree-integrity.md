# Directory tree integrity

The DAM folder tree (`dam_directories`) is a nested set. Every on-disk folder path is derived from its
`_lft`/`_rgt` intervals, so an inconsistent tree shows up as folder operations refused with
"not allowed to create a directory in …", folders listed under the wrong parent, or a subtree delete
reaching the wrong rows. ADR 0004 records why every tree mutation now runs under one lock.

## Detection

`dam:directories:check` runs hourly from the scheduler and prints one JSON line:

```json
{"checked_at":"…","rows":437,"roots":1,"broken":false,"errors":{"oddness":0,"duplicates":0,"wrong_parent":0,"missing_parent":0},"path_mismatches":[]}
```

Exit code 1 means `broken` is true. The same record is appended to `storage/logs/directory-tree.jsonl`
(`event: integrity.checked`, level `warning` when broken). `path_mismatches` lists directory ids whose
nested-set path differs from their `parent_id` chain; those are the folders whose disk path is wrong.

The command never writes.

## Repair

The sequence below is for the Studio runtime. It uses the container names and the
`container exec` / `container stop` / `container start` calls that `tools/studio/runtime.py` itself
uses ([runtime guide](studio-runtime.md)). It is derived from the launcher and has not yet been
exercised on the Studio; the 2026-09-15 repair in History ran the equivalent Docker Compose commands
on the runtime of that time. Run it on the Studio with `STATE` set as in the runtime guide.

1. Take a database backup of the table:

   ```
   mkdir -p "$STATE/storage/backups"
   /opt/homebrew/bin/container exec mothership-private-postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t dam_directories --no-owner' > "$STATE/storage/backups/dam_directories-YYYYMMDD.sql"
   ```

2. Drain active jobs, then stop the queue workers and the scheduler so no job is mid-move:

   ```
   for name in mothership-private-queue mothership-private-panorama-queue mothership-private-stylepress-queue mothership-private-vectorization-queue mothership-private-ai-index-queue mothership-private-scheduler; do
     /opt/homebrew/bin/container stop --time 10 "$name"
   done
   ```

3. Dry run, then repair:

   ```
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:directories:repair
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:directories:repair --force
   ```

   `--force` writes a JSON snapshot to `storage/backups/dam_directories-before-<timestamp>.json`, takes the
   directory tree lock, rebuilds `_lft`/`_rgt` from `parent_id`, and commits only if `countErrors()` is
   all zero, every derived path equals its `parent_id` chain, and no name or parent changed. Any failed
   check rolls the rebuild back and exits 1 with the reason.

4. Start the workers again with the launcher's `up` (it starts stopped owned containers and leaves
   unchanged running ones alone), then run `dam:directories:check` once more through the same
   `container exec mothership-private-fpm php artisan` form.

In a Compose development stack (`compose.dev.yaml`) the same steps use `docker compose exec -T mothership-pgsql`,
`docker compose stop`, `docker compose exec -T mothership-fpm php artisan …` and `docker compose up -d --wait`
with the `mothership-*` service names.

The repair rewrites only `_lft`/`_rgt`. It cannot relocate a folder whose `parent_id` is itself wrong;
`parent_id` matched the disk for every affected row in the 2026-09-13 incident.

## History

- 2026-09-13 16:15 local: 25 of 417 rows corrupted by an interleaved subtree move; origin unrecoverable.
- 2026-09-15 05:33 local: repaired on production with the sequence above (437 rows, `pg_advisory_xact_lock(74190001)`, all checks green); prevention shipped as ADR 0004.
