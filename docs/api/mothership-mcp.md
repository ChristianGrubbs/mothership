# The `mothership` library server (MCP, stdio)

The Mothership is the asset library and system of record for finished creative work. Local creative tools (Blender, the
image and video generators, ArtCraft through its own relay, plain scripts) reach it through one MCP server, `mothership`,
with eight `library_*` tools. The server runs no engines and keeps no sessions: tools make files on this machine, and the
library keeps them.

It replaced the hosted Craft MCP (`/mcp/craft`, the `craft_*` tools, upload links and the Craft worker) in November 2026.

## Running it

```bash
php artisan mcp:start mothership
```

The server speaks MCP over stdin and stdout, so an MCP client starts it as a local command (Skill Scope registers it as a
stdio server). It has no web route and no sign-in: every call acts as one admin account, named in `.env`.

| Setting | Meaning |
| --- | --- |
| `MOTHERSHIP_MCP_USER_ID` | The admin account every call acts as. Its own permissions and folder access apply, and assets it saves are created by it. Unset, 0 or an inactive account: every tool refuses with a message that names this setting. |
| `MOTHERSHIP_MCP_MAX_FILE_BYTES` | The largest local file `library_save` publishes (default 2 GiB). |
| `MOTHERSHIP_MCP_FETCH_DIR` | Where `library_fetch` writes copies when a call names no `dest_dir` (default `<system temp>/mothership-library`). |
| `MOTHERSHIP_MCP_SEARCH_EXCLUDED_FOLDERS` | Folder ids (comma-separated) that `library_search` leaves out unless asked for by `folder_id`, such as QA output. |
| `MOTHERSHIP_MCP_LOGGING` | `true` writes redacted JSONL to `storage/logs/mothership-mcp.jsonl`: operation, outcome, reason code, size and counts only, never a path, name, title, tag, note or byte content. One `run_id` per server process. |

Whoever can start the process on this machine acts as that account, so name an account whose access fits the tools that
use it.

## Tools

Results are JSON (`structuredContent`, also as text). A refusal is an MCP tool error with a sentence the agent can act on.
A hidden asset or folder answers `Not found.` exactly like a missing one.

| Tool | Does | Needs |
| --- | --- | --- |
| `library_search` | Finds assets by file name, title or tag name (`query`, either case), and by `extension`, `kind` (`file_type`: image, video, audio, document, 3d ...), exact `tag`, `folder_id` and `producer`. Pages by `after_id`/`next_after_id`, `limit` up to 100. | `dam.asset`; the Library's listing rule |
| `library_list_folders` | Folders the account can open; `parent_id`, `name`, pages of 500. | folder access |
| `library_create_folder` | A subfolder under `parent_id`, as the Library's New folder makes it (the creator keeps access to it). An existing visible folder of that name (either case) under the same parent is returned with `created: false`. | `dam.directory.store`; access to the parent |
| `library_get_asset` | Title, file name, kind, size, sha256 and pixel size of the main file, folders, tags, stack size, `has_proxy`, and the producer, provenance and notes recorded by `library_save`. | `dam.asset`; the listing rule |
| `library_list_stack` | The files stacked in one asset: `file_id`, `label`, `extension`, `size`, `sha256`, `is_cover`, `producer`. Filters `extension` and `name`, pages of 50. | `dam.asset.download`; download access |
| `library_fetch` | Copies the main file, one stack file (`file_id`) or a video's 1080p web proxy (`proxy: true`) into `dest_dir`. Returns `path`, `sha256`, `size` and pixel size. | `dam.asset.download`; download access |
| `library_save` | Saves a local file (`local_path`) as a new asset in `folder_id`, or as a new member of `stack_asset_id`'s stack (cover unchanged). Records `producer`, `provenance`, `notes` and `evidence`; sets `title` (new asset only) and `tags` (on the new asset, or on the stack asset). | new asset: `dam.asset.upload` and access to the folder; stack: `dam.asset.files.store`, `dam.asset.edit` and edit access; tags: `dam.asset.tag` |
| `library_update` | Changes `title`, adds `tags_add`, removes `tags_remove` (recorded as a removal, so automatic tagging does not restore it). | title: `dam.asset.update`; tags: `dam.asset.tag`; edit access |

No tool deletes or overwrites anything in the library.

### Files in and out

- **Out (`library_fetch`).** The bytes stream from `ContentStore` into a hidden partial file in `dest_dir` and are checked
  against the stored sha256 before the copy gets its name. A mismatch writes nothing and is reported. The copy is the
  agent's own: changing it never changes the library. A file already at that name with the same bytes is reused
  (`reused: true`); any other file of that name is never overwritten, and the copy is named `name-2`, `name-3` and so on.
  The name is taken with an exclusive create before the copy moves in, so two fetches at once never replace each other.
  `dest_dir` must be absolute and outside The Mothership's `storage/`, so no answer ever points into the blob store, which
  may live off this machine.
- **In (`library_save`).** `local_path` must be an absolute path to a regular, non-empty file within the size limit. It is
  copied to a private staging file first, so a tool still writing the original cannot change what is published. The
  bytes must be what the name says (`CreativeHubSaver::contentMatches`), and an MP4 or MOV must hold its `moov` index
  and end on a whole box (a render cut short is refused). `name` overrides the saved file name and must carry an extension. Publication goes
  through `CreativeHubSaver` (`saveNew` / `addToStack`), the same create-only path as the Library's Creative Hub: a name
  already in the folder or stack is saved under the next free one and reported as `renamed_from`.
- **Retries.** `request_id` (a UUID) is claimed in `dam_creative_hub_saves` per account. The same id with the same file
  and destination returns the first result (`replayed: true`); with other bytes or another destination it is refused.
  A retry changes nothing: title and tags are set only by the first save, so later edits stay, and a retry for an
  asset the account can no longer see answers `Not found.`

### The library record

`producer` (`tool`, optional `app` and `version`), `provenance` (`prompt`, `model`, `backend`, `generation_id`,
`reference_asset_ids`, `vault_note`, `source_url`), `notes` and `evidence` are stored as JSON in the request's
`dam_creative_hub_saves.evidence` column. `library_get_asset` returns them for the asset the save created, and
`library_search` filters on `producer`. A stack save also records a Creative Hub rendition naming the producer
(`creative-hub:<tool>[:<app>]`), which `library_list_stack` reports.

## Code

- `packages/Mothership/DAM/src/Mcp/MothershipServer.php`: the server and its instructions.
- `packages/Mothership/DAM/src/Mcp/LibraryTool.php`: each tool's input schema and validation rules, from one table.
- `packages/Mothership/DAM/src/Mcp/LibraryOperations.php`: the operations.
- `packages/Mothership/DAM/src/Providers/MothershipMcpServiceProvider.php`: `Mcp::local('mothership', ...)`.
- `config/mothership-mcp.php`; tests in `tests/Feature/MothershipMcpLibraryTest.php`.

The OAuth classes in `packages/Mothership/AdminApi` (`Mcp*`, [mcp-oauth.md](mcp-oauth.md)) stay registered but inactive:
`mcp-oauth.enabled` is `false` in `config/mcp-oauth.php`, so that OAuth surface is switched off. A remote mode for this
server (`/mcp` behind that OAuth) is a later phase of the plan.
