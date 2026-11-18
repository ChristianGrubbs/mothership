# ADR 0010: Owners decide who sees their files and folders, through per-item access lists

Date: 2026-09-25 · Status: accepted (slice 1 implemented; reads stay on grants) · Spec: the maintainer's design note "Sharing and groups - spec" (2026-09-24) · Plan: "Sharing slice 1 access lists - plan" (2026-09-25), both kept outside this repository

## Context
The Mothership is ready for more people, and today only a full admin decides who sees what. Access is folder
grants: a role's grants (`dam_directory_role`, with the role-wide `inherit_children` and `all_directories`
in `dam_role_settings`) and per-user and per-group grants (`dam_directory_user`, `dam_directory_group`,
each with its own `inherit_children`). A file has no access of its own. It is reachable when any of its
folders is. `DirectoryPermissionService` answers every surface: 173 direct call sites and 88 more
through `AssetAccessControl` (the spec's inventory at `1777737`).

The operator decided on 2026-09-24 (spec D1–D8):
- The owner of a file or folder chooses who can see it: Only me, specific people and groups, or Everyone.
- A folder's setting cascades to everything beneath it, and new items take their folder's setting.
- Full admins see everything.
- Roles keep capabilities only; their folder grants fold into groups.
- Share links behave exactly as before.

The writers that make new items inherit their folder's list (slice 2) rewrite the same code as storage
slice 3, so they must wait for it. Switching reads before those writers land would hide every new
upload in a shared folder from the people the folder is shared with.

## Decision
One access rule, in one place. An active admin U can open item X when any of these holds:
1. U is a full admin (`permission_type = all`).
2. U owns X (`created_by`).
3. X's user list names U.
4. X's group list names Everyone or one of U's groups.

- A disabled admin has no access. Under `lists` every method denies them before any bypass, the
  session-free `forAdmin()` path included. `adminCanAccessAsset()` keeps its own `status` check in both
  models, and `grants` answers exactly as before.
- An admin with no role keeps today's bypass (open decision 2).
- `all_directories` no longer bypasses. The rebuild lists that role's group on every folder and every
  file.
- A file has its own list, and its folders never widen it.
- A folder U cannot open shows as a path, name only, when it leads to a folder U can open or to a
  folder holding a file U can open.

- **Data.** Four list tables: `dam_directory_user_access`, `dam_directory_group_access`,
  `dam_asset_user_access` and `dam_asset_group_access`.
  - Each has a composite primary key and a reverse (principal, item) index.
  - Item and group keys cascade. `admin_id` cascades too, except on SQL Server. There a second cascade
    path from `admins` (through `created_by`) is error 1785, so the key is NO ACTION. On SQL Server
    only, `DeleteAdminAccessRows` removes the rows on the Admin model's `deleting` event.
    `AdminRepository::delete()` runs that event and the row delete in one transaction. A delete that
    fails, or that a listener vetoes (by throwing or by returning false), keeps the rows.
  - The owner is the existing `created_by`; there is no owner column.
  - `dam_groups.system_key` marks the built-in Everyone group (`everyone`) and groups made from roles
    (`role:<id>`). On SQL Server its unique index is filtered to non-null keys.
- **Module.** `DirectoryPermissionService` keeps its public methods and delegates each call to the
  reader for the current access model: `LegacyGrantReader` under `grants` (today's code, moved
  verbatim) and `AccessListReader` under `lists`. Every call site stays as it is.
- **Switch.** The `DAM_ACCESS_MODEL` row in `dam_configuration` holds one of three states:
  - absent or `grants`: reads use the grants;
  - `pending:<run>`: a switch to lists is under way, or died before it verified; reads still use the
    grants;
  - `lists`: reads use the lists.

  Only an exact `lists` reads the lists, so every failure falls back to the grants.
  - `AccessModel` reads the row directly, once per request or queue job. The DAM middleware's KEY_MAP
    runs only on `dam` routes and casts values to booleans, so it cannot carry the switch.
  - `dam:access:report` prints the stored state, including a pending run id.
- **Rebuild** (`dam:access:rebuild`) runs in one transaction under `DirectoryTreeLock`. It is set-based
  and idempotent:
  - It runs only while the state is exactly `grants`. Under `lists` the lists are the record, and a
    pending state is a switch under way or interrupted, which the operator finishes with
    `dam:access:switch lists` or clears with `dam:access:switch grants`. Otherwise it refuses, writes
    nothing, logs `access_rebuild_refused` and exits 1.
  - Each role that grants folders becomes a "<role> (from role)" group holding the role's admins.
  - Every user, group and role grant is written onto the folders it reaches.
  - Each file takes the union of its folders' lists, which is today's any-directory rule made concrete.
  - It never changes a grants answer:
    - It writes no owner.
    - It never touches Everyone's member rows.
    - It leaves a generated group alone when a legacy folder grant uses it.
- **Generated role groups** are machine-maintained previews until the flip.
  - A rebuild resets the members of those no folder grant uses to the role's current admins, and counts
    them.
  - If the members of a generated group that a folder grant uses would change, the rebuild refuses and
    writes nothing. It logs `access_rebuild_refused` with group ids and counts. The operator then either
    removes that group's folder grants or restores its members.
  - The check uses locking reads in one order: the generated group rows first (`FOR UPDATE`, by id),
    then their folder grants, their members and the role holders. A locking read returns the latest
    committed rows even under REPEATABLE READ (MySQL and MariaDB), where a plain read would miss a grant
    committed after the transaction's snapshot. While the group rows are locked, a concurrent grant or
    Groups page edit waits for the rebuild. A legacy writer that locks in another order can deadlock
    with it; the database rolls one side back, which is a safe failure.
- **Proof** (`dam:access:report --compare`) compares the grants answer with the lists answer for every
  active admin and every folder and file.
  - Two differences are allowed and listed: an owner gaining their own item, and a folder gained as a
    path.
  - Any other difference exits 1.
  - Disabled admins are left out, because the lists deny them outright and they cannot sign in.
- **The cache lock.** `dam:access:rebuild` and `dam:access:switch` each run inside
  `AccessModel::exclusively()`, a cache lock (`dam-access-model`). It only prevents wasted concurrent
  work; correctness never depends on it or on how long it lasts.
  - A competing run finds it held and refuses at once.
  - The lock is shared by every process on the same cache store (Redis on the Studio). A lock left by a
    run that died expires within an hour, plus the switch's drain and settle.
- **The fence.** What guarantees correctness is the state row, locked, plus the compare-and-set.
  - **One transaction, locked first.** Every list rebuild and every change of state runs inside
    `AccessModel::transactionally()`.
    - Its first statements create the `DAM_ACCESS_MODEL` row as `grants` if it is missing. The insert
      runs in a savepoint, so a racing creator costs only the savepoint; `insertOrIgnore` is not used,
      because SQL Server's grammar has no compiler for it.
    - It then holds the row `FOR UPDATE` until the transaction commits or rolls back. A failure anywhere
      rolls the whole transaction back.
    - PostgreSQL, MySQL and SQL Server take a real row lock; SQLite serialises writers anyway. So no
      rebuild can commit across an activation, and no two transitions can interleave.
  - **Lock order.** The state row first, then the directory tree lock, then the generated role groups.
    A legacy writer that locks in another order can deadlock with a rebuild. So can two runs that both
    find the row missing, on MySQL or SQL Server. Either way the database rolls one side back, which is
    a safe failure.
  - **Compare-and-set.** Every change of state (`AccessModel::compareAndSet()`) is an update with an
    equality condition on the value read under that lock, and it must affect exactly one row.
    - The switch's transitions: from what it read to `pending:<run>`, then `pending:<run>` to `lists` or
      `grants`.
    - The rollback's: from what it read to `grants`.
  - **Superseded.** A mismatch, or a compare-and-set that affects no row, means another run has taken
    over. The run then writes nothing more, logs `access_switch_superseded` (counts only) and exits 1.
  - **Defence in depth.** The switch also checks that it still owns the cache lock before its
    verification and before it reports success.
  - Log lines are written only after the outcome is known.
- **Flip.** `dam:access:switch lists` runs only in maintenance mode, because every grant, group and role
  writer is an HTTP page. The operator runs:

  ```
  php artisan down
  php artisan dam:access:switch lists
  php artisan up
  ```

  The switch drains, switches and verifies, under the lock throughout:
  1. **Drain.** It waits `--drain` seconds (default 30) for requests admitted before `down` to finish.
     If maintenance mode was lifted meanwhile, it refuses and writes nothing.
  2. **Switch.** In one transaction it rebuilds, compares, fills in missing owners and writes
     `pending:<run>`. Reads stay on the grants.
     - It goes on only after a clean comparison. Otherwise it refuses, and the whole transaction,
       rebuild included, rolls back.
     - `--force` is the operator's override, and it prints the failures.
     - Missing owners go to the lowest-id active full admin, only after the comparison passes (or under
       `--force`). Every such owner already sees everything. This moved here from the plan's rebuild,
       so a preparatory rebuild never changes `created_by`.
  3. **Verify.** It waits `--settle` seconds (default 5). Then one more transaction locks the state row,
     compares again from fresh state, and settles the state:
     - If the failing pairs are the ones it switched on (none, or the set `--force` let through), it
       moves `pending:<run>` to `lists`. Only now do the lists become authoritative.
     - Otherwise a grant, role or membership change landed during the switch. It moves the state to
       grants, logs `access_switch_reverted` and exits 1. The operator reruns it, still in maintenance
       mode. The owners filled in stay, since each is a full admin.
  4. **Done.** On success it tells the operator to run `php artisan up`.
- **Recovery.** A failure after step 2 leaves the state at `pending:<run>`, and reads stay on the grants.
  That covers a verification or revert that throws, and a process that dies during the settle.
  - The switch logs `access_switch_unverified` when it can and exits 1. It prints the state as read
    afresh and the model reads use for it, which is unknown if the state cannot be read.
  - Rerunning `switch lists` redoes the whole switch: it drains, rebuilds, compares and verifies, and
    never takes the already-on-lists shortcut from a pending state.
  - Alternatively, `switch grants` ends it.
- **Residual risk.** A legacy write admitted before `down` that is still running after the drain, the
  switch and the settle is lost. It is bounded by request duration, and slices 3 and 4 remove the
  legacy writers under `lists`.
- **Rollback.** `dam:access:switch grants` moves any state to `grants`. It takes the same lock but needs
  no maintenance mode. While reads use the lists, neither command rebuilds over them.

## Consequences
The work ships as six pull requests. Each one runs local checks, CI on the self-hosted pool, a
cross-model review and a Studio release.

1. **Model, data and tools (this slice).** No UI change. Reads stay on grants. The migration adds empty
   tables and the Everyone group. The lists are filled by the rebuild, which the switch runs.
2. **Inherit on create, move and copy.** Every writer maintains the lists under both models. Starts
   after storage slice 3 ("folders are metadata", ADR 0009) has merged. To keep every list write
   serialised with rebuilds and activation, its writers take the state row lock too (`FOR SHARE`), first.
3. **Share dialog and cascade.** Owners and full admins set a list, which cascades through a subtree.
4. **Groups page and Roles editor.** Everyone becomes read-only. The Roles editor loses its folder
   grants.
5. **Share links for everyone.** The Shared Links page is scoped to what each person created or owns.
6. **Cleanup.** After the operator accepts, the retired grant tables and columns are dropped.

The operator runs `dam:access:switch lists` once slices 2 and 3 have merged. Maintenance mode keeps
HTTP writers out of the switch, but queued placement jobs already in flight may still land. They are
covered once slice 2's writers maintain the lists under both models, which is one more reason the flip
waits for slice 2.

Before the flip, reverting this slice removes it. After the flip, `dam:access:switch grants` is the
rollback. Sharing changes made under `lists` stay in the tables but are ignored under `grants`, and a
later switch to `lists` rebuilds from the grants.

**Known limitation until slice 4.** The Groups page, the folder access panel and every Blade view stay
untouched in slice 1, so under `grants`, Everyone and the generated role groups are visible and
editable there like any group.
- A grant made to Everyone under `grants` reaches nobody. The switch's comparison refuses it, because
  under `lists` it would reach every active admin.
- An edit to a generated group that a legacy grant uses makes the rebuild refuse, instead of silently
  changing who can open folders.
- Slice 4 makes both groups read-only on those pages.
