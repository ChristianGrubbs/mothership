# Linux host (Docker Compose)

The production site can run on any Linux host with Docker Engine and the Compose
plugin. The files are in `deploy/linux-host/`. They run the same services as the
macOS launcher (`tools/studio/runtime.py`, see `studio-runtime.md`):

- the same images, commands, CPU and memory limits, and container names
  (`mothership-private-*`);
- the same private subnet;
- the same bridge endpoints.

So one `app.env` serves both hosts. The only difference is that this host does
not serve `MOTHERSHIP_OPTIMIZER_API`.

## Layout

| Path | Contents |
|---|---|
| `/srv/mothership/source` | a checkout of this repository; the images are built from its `HEAD` |
| `/srv/mothership/state/app.env` | the deployment's settings, mode 0600, never committed |
| `/srv/mothership/state/storage` | Laravel `storage/`, bind-mounted into FPM, the workers and nginx |
| `/srv/mothership/state/looper`, `video`, `higgsfield-session` | Looper uploads, video bridge jobs and files, the Higgsfield CLI session |
| `/srv/mothership/state/generated` | files `host.py render` writes: nginx site, FPM pool and entrypoint, worker supervisor, `postgres.env` (0600) |
| `/srv/mothership/state/tunnel/credentials.json` | the Cloudflare tunnel credentials, mode 0600; the `tunnel` service runs only when this file and `TUNNEL_ID` exist |
| `/srv/mothership/state/stack.env` | non-secret compose variables: image tags, paths, uid/gid, `TUNNEL_ID` |
| volumes `mothership-private-{postgres,redis,elasticsearch}-data` | database, cache/queue and search data |

`MOTHERSHIP_HOME` overrides `/srv/mothership`.

## How it differs from macOS

- **Private network.** The Docker bridge `mothership-private` (interface
  `mothership0`) uses 192.168.164.0/24 with gateway .1, as on macOS. FPM is
  pinned at .10, because nginx resolves its FastCGI upstream once at start.
  Every other container draws an address from .128/25. Containers reach each
  other by service name.
- **Bridges.** The AI and video bridge programs run unchanged in the
  `mothership-bridges` image (`bridges.Dockerfile`). They use the host network
  and listen on the gateway address. So `MOTHERSHIP_AI_API` and
  `MOTHERSHIP_HOME_VIDEO_API` keep their macOS values, and the network's
  `TRUSTED_PROXIES` entry still covers nginx.
- **Ollama.** The AI bridge sends requests to Ollama at the host's default
  gateway, port 11434. `bridge.py` reads the gateway at every start; set
  `AI_OLLAMA_URL` to override it. On a Hyper-V guest behind the Default Switch,
  the gateway is the Windows host. Run Ollama there with
  `OLLAMA_HOST=0.0.0.0:11434`, and let the firewall accept only the Hyper-V
  ranges.
- **Not on this host.**
  - The optimizer (Clop) is macOS-only. Leave `MOTHERSHIP_OPTIMIZER_API` unset:
    the Optimize action is hidden, and the video bridge runs with
    `VIDEO_OPTIMIZER_API=off`.
  - The system metrics collector reads macOS counters, so the Home quick view
    shows its metrics as unavailable.
- **Ownership.** The images are built with `HOST_UID`/`HOST_GID` set to the
  user that runs `stack.sh`. The FPM entrypoint gives `storage/` back to that
  user before `php-fpm` starts, because Linux bind mounts keep the owner of
  files created by root.
- **`app.env`** is loaded in Compose's `raw` env-file format: values arrive
  byte for byte, with no interpolation or quote handling.
- **Restarts.** Every service has `restart: unless-stopped`, and the Docker
  daemon starts at boot. The stack therefore comes back after a reboot without
  anyone signing in.

## Operating

```bash
cd /srv/mothership
git -C source pull --ff-only          # the commit to deploy
source/deploy/linux-host/stack.sh build    # fpm, q, looper and bridges images tagged <sha12>
source/deploy/linux-host/stack.sh up       # render, then create/start everything
source/deploy/linux-host/stack.sh verify   # the launcher's verify checks
source/deploy/linux-host/stack.sh ps
source/deploy/linux-host/stack.sh logs --tail 100 fpm
source/deploy/linux-host/stack.sh stop     # containers, volumes and state stay
```

`verify` checks that:

- every container is running;
- the Looper and both bridges answer `/health` from FPM;
- each worker has a live `artisan` process;
- `/up` and `/admin/login` answer 200;
- the login page names the commit of the FPM image;
- the page's first 30 static references load;
- the tunnel reports ready, when it runs.

`STUDIO_LOG=1` appends JSONL events to `state/events.jsonl`.

## First install and data migration

1. Install Docker Engine and the Compose plugin. Add the operating user to the
   `docker` group. Clone this repository to `/srv/mothership/source`.
2. Copy `app.env` to `state/app.env` (0600) without printing it. Copy
   `storage/`, the Looper uploads and the video bridge's `data/` into `state/`
   with `rsync -a`.
3. Run `stack.sh build`, then `stack.sh render`.
4. **Postgres before FPM**, because the FPM entrypoint migrates, and seeds an
   empty database.
   1. Run `stack.sh up postgres`.
   2. Restore a `pg_dump -Fc` file with `pg_restore --no-owner --role=<db user>`
      into the empty database.
   3. Compare the row counts of the main tables with the source.
5. **Redis**, if sessions and queued jobs should carry over.
   1. Stop the redis service.
   2. Put the source's `dump.rdb` in the volume with no `appendonlydir`.
   3. Start redis once with `--appendonly no`.
   4. Run `CONFIG SET appendonly yes` and wait for the rewrite.
   5. Run `stack.sh up redis` to return to the normal command.
6. Elasticsearch starts empty. Reindex with the application's commands if the
   source held indices.
7. Run `stack.sh up`, then `stack.sh verify`.

## Tunnel

Create a locally managed Cloudflare tunnel. Put its credentials JSON in
`state/tunnel/credentials.json` (0600), add `TUNNEL_ID=<id>` to
`state/stack.env`, and run `stack.sh up`. The `tunnel` service runs `cloudflared`
on the host network with origin `http://127.0.0.1:18090`. Its readiness endpoint
is `127.0.0.1:20241/ready`.

Point the public hostname's proxied CNAME at the new tunnel (Cloudflare's
tunnel address for its id). To roll back, point it at the previous tunnel. The site moves as soon as the DNS change
propagates.
