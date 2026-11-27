# Chunked upload over REST

A file larger than one request can carry goes up in parts. The site's proxy caps a request body at about 100 MB,
and the stack's PHP takes 96 MiB in one upload (`POST /api/v1/rest/assets/upload`). In parts, a file can be as large
as the app's upload limit, `DAM_UPLOAD_MAX_FILE_BYTES` (20 GiB by default).

These routes are the REST twin of the Library's own chunked upload. They use the same service, part checks, limits
and finalize job, and the completed upload is the asset. They act as the API key's own admin with the upload scope
`api.dam.assets.upload`, the same scope `POST assets/upload` needs. The contracts are in `docs/api/openapi.json`, under
`admin.api.dam.assets.chunk_upload.*`.

Agents normally reach this through a client:

- `mothership upload` (the CLI) sends a large file in parts.
- `library_save` through the local launcher does too. It ties its parts to its `request_id`; see
  [mothership-mcp.md](mothership-mcp.md), "Large files go up in parts".

## Flow

1. **Initialize**: `POST /api/v1/rest/assets/upload/chunks` with JSON:
   - `directory_id`: the folder, which must be open to the key's admin.
   - `original_name`: the file name, with no `/`, `\` or control characters.
   - `size`: in bytes. `last_modified`: in ms.
   - `fingerprint`: 64 hex characters. Send the file's sha256.
   - Optional: `mime_type`.
   - Optional: `create_only: true` always makes a new asset; a same-name file otherwise becomes a new version.
   - Optional: `session_uuid`, a UUID the client picks.

   Without `session_uuid` a new upload session is opened. With it, the same session, name, size, `last_modified`
   and fingerprint answer the same upload, with the parts it already has, so a client that keeps its
   `session_uuid` can resume after a crash. A `session_uuid` belonging to another account or folder answers 409,
   and so does a changed file under it (`source_mismatch`).

   The answer is 201 for a new upload or 200 for a resumed one, `{"success": true, "upload": status}`.
2. **Send the parts**: `PUT /api/v1/rest/assets/upload/chunks/{uuid}/{index}` for each index from 0 to
   `chunk_count - 1` that is not in `received_indices`.
   - The body is the raw bytes (`Content-Type: application/octet-stream`). Each part is `chunk_size` bytes (32 MiB),
     except the last.
   - `Content-Range: bytes <start>-<end>/<size>` and `X-Chunk-SHA256: <hex>` must match the part.
   - A mismatch is refused with 422 and can be sent again.
   - 507 with `retryable: true` means the part was not stored; send it again.
   - Parts may go in any order, and one at a time is enough.
3. **Complete**: `POST /api/v1/rest/assets/upload/chunks/{uuid}/complete`.
   - It answers 202 once every part is in: the finalize job then assembles, checks and publishes the file.
   - It answers 422 `upload_incomplete` while a part is missing.
   - Asking again is harmless.
4. **Poll**: `GET /api/v1/rest/assets/upload/chunks/{uuid}` until `state` is `completed` (`asset_id`, `file_name`)
   or `failed` (`failure_code`, `failure_reason`). Assembly takes about as long as copying and hashing the file once.
5. **Cancel** (optional): `DELETE /api/v1/rest/assets/upload/chunks/{uuid}` drops an unfinished upload and its parts.
   An upload nobody finishes expires after `DAM_UPLOAD_STALE_HOURS`.

## Status

Every answer carries `upload` with these fields:

- `uuid` and `state`: `receiving`, `queued`, `assembling`, `completed`, `failed`, `cancelled` or `expired`.
- `expected_bytes`, `received_bytes` and `finalization_bytes`.
- `chunk_size`, `chunk_count` and `received_indices`.
- `asset_id` and `file_name`, once completed.
- `failure_code` and `failure_reason`.
- `expires_at`.
- `duplicate` and `similar`: the same notices as a one-request upload.

A refusal is `{"success": false, "code", "message", "retryable", "upload": null}`. Another account's upload answers
403, and a missing one 404.

## Example

```bash
F=film.mov; SIZE=$(stat -f %z "$F"); SUM=$(shasum -a 256 "$F" | cut -d' ' -f1)
curl -sS -X POST "$SITE/api/v1/rest/assets/upload/chunks" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "{\"directory_id\": 12, \"original_name\": \"$F\", \"size\": $SIZE, \"last_modified\": 0, \"fingerprint\": \"$SUM\"}"
# then for each index: dd the part out, PUT it with Content-Range and X-Chunk-SHA256; then POST .../complete; then GET until completed
```
