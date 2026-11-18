# The Mothership native AI bridge

The production Mac runs Ollama natively. The Mothership's Linux containers cannot reach
Ollama's loopback listener, so a small stdlib-only bridge
(`tools/studio/ai-service/server.py`) fronts it on the private Apple-container
bridge, the same path used by `tools/studio/clop_service.py` (see
`docs/ops/studio-clop.md`). It is managed by `tools/studio/ai_service.py` and runs
under the Studio's system `python3` (3.9-compatible). Ollama itself stays on
`127.0.0.1:11434`; it is never exposed.

## 1. What it is

Endpoints (the contract the PHP client in `packages/Mothership/DAM` is built against):

- `GET /health` -> `200 {"ok":true,"version":"1","ollama":bool,"models":{"caption":bool,"embed":bool}}`.
  `ok` only means the bridge is up; `ollama` and `models` say whether Ollama
  answers and has both models pulled.
- `POST /caption` body
  `{"images":[base64 JPEG, 1..4],"context":{"file_name","folder_path","kind"},"vocabulary":[str, 1..200]}`
  -> `200 {"caption","tags","keywords","visible_text","model":"qwen3.5:9b","prompt_version":"2026-11-17.1"}`.
  `tags` is always a subset of `vocabulary` (enforced both in the Ollama JSON
  schema and again after parsing).
- `POST /embed` body `{"texts":[str, 1..64],"kind":"document"|"query"}`
  -> `200 {"vectors":[[float x 1024]],"model":"qwen3-embedding:0.6b"}`. `query`
  texts get the Qwen3-Embedding instruct prefix; `document` texts are sent as is.
- Errors: `400 {"error":"<code>"}` invalid input, `413` over `AI_MAX_BYTES`
  (default 32 MiB), `502 {"error":"ollama_failed"}`, `504 {"error":"ollama_timeout"}`.

Models: caption `qwen3.5:9b`, embedding `qwen3-embedding:0.6b`. Inference is
single-flight: one Ollama call at a time (the Studio runs one VLM, and the queue
worker is single), so concurrent requests wait on a lock.

State lives under `~/Studio Data/Mothership Native Services/ai`: deployed source
`runtime/ai-service/server.py`, process log `logs/ai-service.log`, PID receipt
`run/ai-service.json`, JSONL events (gated by `AI_SERVICE_LOG=1`)
`logs/ai-service-events.jsonl` and `manager-events.jsonl`.

## 2. Start (after deploy phase B)

The `192.168.164.1` bridge address is the Studio-side gateway of the
`mothership-private` network, which `tools/studio/runtime.py` pins to
`192.168.164.0/24` (`NETWORK_SUBNET`, 2026-10-08). It exists only while the
container stack runs, so start the bridge after deploy phase B.

`runtime.py` keeps an existing owned network as it is and does not migrate its
subnet, so a legacy network can still have the old gateway. Confirm the live
gateway with `container network inspect mothership-private` first, and use the
observed address consistently for the bridge, `MOTHERSHIP_AI_API` and the tunnel
rule below.

```bash
python3 tools/studio/ai_service.py up --host 192.168.164.1 --port 18767
python3 tools/studio/ai_service.py verify --host 192.168.164.1 --port 18767 \
  --public-url https://ai.mothership.example.com
```

`up` deploys only `server.py` (digest-checked, so it is idempotent), launches it
in a detached session, and waits for HTTP health. `verify` succeeds only when
`ok`, `ollama`, `models.caption` and `models.embed` are all true; with
`--public-url` it also checks `<url>/health` and exits 1 if that fails. The
manager rejects unspecified and public bind addresses. `down` terminates only the
process recorded in the PID receipt.

## 3. Application environment

```
MOTHERSHIP_AI_API=http://192.168.164.1:18767
MOTHERSHIP_AI_INDEX_ENABLED=false
MOTHERSHIP_SMART_SEARCH_ENABLED=false
```

Both feature flags start `false`. Check reachability from inside the app:

```bash
container exec mothership-private-fpm curl -fsS http://192.168.164.1:18767/health
```

## 4. Cloudflare tunnel

Hostname `ai.mothership.example.com` on the existing remotely managed Studio
tunnel `c01b4dd8-ba5c-47d7-a7e8-505addb8f5ed`, ingress service
`http://192.168.164.1:18767`. No Cloudflare Access policy, the same posture as
`mothership.example.com` (operator decision 2026-10-01).

- Insert the new ingress rule before the catch-all. Do not change the SSH or
  `mothership` rules; read the tunnel configuration back after the change.
- The rule was first created against the pre-pin address `192.168.64.1` and was
  re-pointed at the bridge address above on 2026-10-09 (tunnel configuration
  version 4 -> 5, read back; only that rule changed). If the bridge address ever
  changes again, update this rule too: the public `/health` times out until you do.
- DNS: CNAME `mothership-ai` -> the Studio's Cloudflare tunnel
  (proxied).

## 5. Backfill

Run inside the queue container:

```bash
php artisan dam:ai-index:backfill
```

Progress:

```sql
SELECT status, count(*) FROM dam_ai_index GROUP BY status;
```

## 6. Autostart through `runtime.py up`

`runtime.py up` starts this bridge (idempotently) whenever `MOTHERSHIP_AI_API` is
set, and `runtime.py verify` fails naming `ai` if its health check fails, so a
Studio reboot or a deploy that runs `~/Desktop/Mothership-Private.command`
brings it back. Rerun `ai_service.py up` (section 2) only for manual recovery.

The managers deploy `server.py` on `up` and restart a stale bridge, so the next
deploy that reruns the launcher picks up a changed `tools/studio/ai-service/`.
To pick it up without a full redeploy, run this from the Studio checkout
(`~/Studio Workspaces/Mothership/source`) once main is checked out there:

```bash
python3 tools/studio/ai_service.py up --host 192.168.164.1 --port 18767
python3 tools/studio/ai_service.py verify --host 192.168.164.1 --port 18767
```

Before `runtime.py up` owned startup, a stale bridge went unnoticed. On 2026-10-09 a bridge that predated
the `/name` route made Rename with AI answer 503 on live until it was restarted.

## 7. Rollback

1. Set `MOTHERSHIP_AI_INDEX_ENABLED=false` and `MOTHERSHIP_SMART_SEARCH_ENABLED=false`.
2. Restart the fpm and queue roles.
3. `python3 tools/studio/ai_service.py down --host 192.168.164.1 --port 18767`.

## Environment keys

- `AI_HOST` / `AI_PORT` - bind address; the manager sets them from `--host`/`--port`.
- `AI_OLLAMA_URL` - Ollama base URL (manager `--ollama`). Default `http://127.0.0.1:11434`.
- `AI_CAPTION_MODEL` / `AI_EMBED_MODEL` - defaults `qwen3.5:9b` / `qwen3-embedding:0.6b`.
- `AI_MAX_BYTES` - request body cap. Default 32 MiB.
- `AI_CAPTION_TIMEOUT` / `AI_EMBED_TIMEOUT` - Ollama call timeouts in seconds
  (default 120 / 30). `AI_NUM_CTX` - context window (default 8192).
- `AI_SERVICE_LOG` - `1` enables JSONL event logging. `AI_SERVICE_LOG_PATH` - where
  the server writes its events; the manager sets it under its state directory.

## Recovery

If the bridge fails, inspect `logs/ai-service.log`, then run `down` followed by
`up`. A `verify` that reports `ollama: false` or a missing model is an Ollama
provisioning problem (`ollama pull qwen3.5:9b`, `ollama pull qwen3-embedding:0.6b`),
not a bridge fault.

New uploads need no manual step once the bridge is back:

- Every new or replaced file is queued for indexing as it is saved (queue `ai-index`, 4 tries over about 20 minutes).
- `bridge_watch.py` restarts a crashed bridge within about ten minutes (`docs/ops/studio-runtime.md`, "Bridge watch").
- `dam:ai-index:reconcile --transient` runs hourly at :45. Once `/health` answers, it re-queues files that failed only
  because the bridge or Ollama was down, or whose run a stopped worker lost. While the bridge is down it does nothing,
  so an outage spends none of a file's attempts.
- The nightly `dam:ai-index:reconcile` at 03:30 retries everything else (up to 12 attempts) and refreshes word
  matching.

### Reconcile run record (`storage/logs/ai-index-reconcile.jsonl`)

Each `dam:ai-index:reconcile` run (nightly 03:30, hourly `--transient` at :45) appends two JSON lines to this fixed file:
`ai_index_reconcile.start` when it begins, and one `ai_index_reconcile.result` when the command's handler finishes.
Every line has `ts`, `run_id` (the same on both lines of one run, new for every run), `level`, `event`, `loc` and `ctx`.

| `ctx` field | Meaning |
| --- | --- |
| `mode` | `full` (nightly) or `transient` (hourly) |
| `pid` | The PHP process id |
| `enabled` | Start line only: whether `ai-index.index_enabled` was on |
| `outcome` | Result line: `completed` or `failed` |
| `exit_code` | The code the handler returned (`0`), or `null` when it threw. It is the handler's return value, not an observed operating-system exit status |
| `skip_reason` | `index_disabled`, `ai_service_not_answering`, or `null` when the run did its work |
| `full_dispatched` | Files for which a full index job was actually queued |
| `lexical_dispatched` | Files handed to the search-word refresh (nightly only); `null` if that step was not reached |
| `transient_dispatched` | Lost or outage-failed runs re-queued by the hourly pass |
| `duration_ms` | Handler wall time |
| `exception_class` | Result line when `outcome` is `failed`: the class name only. No message, SQL, file name, caption, token, URL or user is ever written; the exception itself still propagates unchanged |

Reading it:

- **A `result` line means the command finished and its dispatch calls returned. It does not mean the queued AI jobs have run.**
  Whether the files were indexed is a question for the `dam_ai_index` rows and the `ai-index` queue.
- **A `start` line with no matching `result` is unknown, not success.** A hard kill (SIGKILL, power loss) leaves exactly that.
  `outcome: failed` with a class name says the handler threw; the scheduler's own output has the detail.
- If the file cannot be written, the command carries on and nothing is recorded: a missing line is never evidence of success.
- Older runs have no record. The first record for a schedule slot is the next natural run after this change is deployed;
  nothing here proves what an earlier run did.
- Do not run `dam:ai-index:reconcile` by hand to produce a record. Wait for the next scheduled run.
