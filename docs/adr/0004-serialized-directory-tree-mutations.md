# ADR 0004: Every directory tree mutation runs under one advisory lock

Date: 2026-09-15 · Status: accepted

## Context
`dam_directories` is a kalnoy/nestedset tree. Every insert, move and delete rewrites the `_lft`/`_rgt`
intervals of rows the writer never loaded, and `Directory::generatePath()` — the source of every
on-disk folder path, asset path prefix, deletion subtree and permission-inheritance query — walks those
intervals rather than `parent_id`.

On 2026-09-13 at 16:15 local, 25 rows were left with intervals that disagreed with their `parent_id`
chain (48 rows share that timestamp; the originating action is unrecoverable). The visible symptom was a
super-administrator being told they were "not allowed to create a directory" in a folder that exists,
because `is_writable()` was asked about a path assembled from the wrong ancestors. Nothing in the
codebase serialized tree writes: web requests and five queue workers could interleave shifts, and the
row locks `DeleteDirectory` took covered only the rows it read. A codex review of the diagnosis ranked
serialized mutations above hourly self-heal (detection dressed as prevention) and above deriving paths
from `parent_id` (which leaves interval-driven consumers wrong).

## Decision
All mutations of the directory tree run under `DirectoryTreeLock`: a PostgreSQL transaction-scoped
advisory lock (`pg_advisory_xact_lock(74190001)`, released on commit or rollback), a `FOR UPDATE`
row lock on the single `dam_directory_tree_lock` row on MySQL/MariaDB (likewise freed only when the
outermost transaction ends)†, nothing extra on SQLite. Two layers take it:

1. `Directory::save()` and `Directory::delete()` — the two entry points through which every nestedset
   shift happens — so the long tail of direct `Directory::create()` callers (copy jobs, generation
   folders, personal map folders*, seeders) is covered without touching each one.
2. The operations that read the tree before changing it (`DirectoryRepository` create/update/delete/copy,
   `MoveDirectoryStructure`, `MassMove`, `CopyDirectoryStructure`, `DeleteDirectory`) wrap their whole
   body so parent lookups, writability checks, path derivation and storage renames happen under the
   same lock. The lock is re-entrant per connection, so the inner `save()` calls join the outer hold.

Integrity is checked, never silently repaired: `dam:directories:check` runs hourly and writes one JSONL
record to `storage/logs/directory-tree.jsonl`; `dam:directories:repair --force` rebuilds intervals from
`parent_id` under the same lock and rolls back unless `countErrors()` is zero, every derived path equals
its `parent_id` chain, and no name or parent changed.

## Consequences
- Tree writes are serialized system-wide. Directory operations are rare and small compared with asset
  work, so throughput is unaffected; a long move or copy briefly blocks folder creation instead of
  corrupting it.
- A crash mid-write cannot leave the lock held, and a waiting writer never reads the tree before the
  holder's rows commit: on every driver the lock is transaction-scoped†. MySQL is an advisory target only.
- Storage renames still happen inside the transaction, so a filesystem failure surfaces as a rolled-back
  tree change rather than a tree that names folders that do not exist.
- The repair path is a documented command, not ad-hoc PHP; the 2026-09-15 production repair used the
  same key and the same verification sequence.

## Note 2026-09-21
\* The personal map-folder module named among the direct `Directory::create()` callers above was removed
from The Mothership on 2026-09-21 (operator directive; its folders were purged by migration
`2026_09_21_000001`). The decision itself is unchanged: every remaining tree writer still takes
`DirectoryTreeLock`.

## Note 2026-09-23
† Until this date MySQL took a session-scoped named `GET_LOCK` and called `RELEASE_LOCK` in a `finally`
*inside* the transaction callback, so the lock was free before Laravel committed: a waiting writer could
take it and read the tree without the first writer's rows (for example a check-then-create producing
duplicate sibling folders). Found by a Codex review of the Web Pages upload fix (PR #30). Deferring the
release to Laravel's `afterCommit`/`afterRollBack` was tried and rejected in review (PR #31): an earlier
throwing callback skips it and leaks the lock on a long-lived worker session, and the transaction manager
can attach it to another connection's transaction. MySQL/MariaDB now lock one row of
`dam_directory_tree_lock` (migration `2026_09_21_000000`, first shipped as `2026_09_23_000003` and moved
ahead of the mind-map purge, which takes the lock) `FOR UPDATE`; InnoDB frees it only when the
outermost transaction commits or rolls back — the same lifetime `pg_advisory_xact_lock` has — so there is
no release step at all. A lock-wait timeout (InnoDB error 1205) surfaces as the same "directory tree is
busy" error `GET_LOCK`'s timeout did when `run()` owned the transaction; inside a caller's transaction it
stays Laravel's `DeadlockException`, so the caller unwinds as for any concurrency error (the server may
already have rolled the whole transaction back). A missing lock row fails closed instead of being
recreated inside a transaction that could roll back with it. PostgreSQL and SQLite locking is unchanged.
`DirectoryTreeLock` is now bound `scoped`, so the per-connection re-entrancy the Decision describes
actually joins the outer hold; before, each `app(DirectoryTreeLock::class)` was a fresh instance and
nested saves re-took the lock in a savepoint instead. Re-entry covers only the transaction body: a tree
write in an `afterCommit`/`afterRollBack` callback runs after the lock is gone and takes it again.
