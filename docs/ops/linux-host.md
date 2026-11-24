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

## Menu bar app

The Mac menu bar app (`tools/studio/menubar`, see `studio-runtime.md`) can watch
this host instead of a stack on the Mac. It then only reads: it has no Start,
Stop or Restart, does not start anything at login, and never restarts a bridge.
Install it on the Mac with the host's SSH login, the key, and the SSH alias of
the jump host, if there is one:

```bash
zsh tools/studio/menubar/scripts/install.sh --site-url https://<host>/up --linux-host <user>@<vm-host> --linux-key <private-key> --linux-jump <jump-alias> --umbrel-ssh <user@umbrel-host> --umbrel-key <umbrel-key>
```

- `--linux-home` (default `/srv/mothership`) is this host's `MOTHERSHIP_HOME`.
  `--linux-name` (default `Windows PC`) is what the panel calls the host.
- The installer connects once before it replaces anything. That first
  connection records the host's key in the app's own known-hosts file, through
  the jump host. Every later connection requires the same key.
- The site row probes the public `/up` from the Mac. Every other row comes from
  one SSH call that runs `Resources/linux-host-status.py` here with
  `python3 -`. That script reads `stack.sh ps` and each service's
  `container_name` from `stack.sh config`, the tunnel's readiness endpoint, each
  bridge's `/health`, and Ollama at the default gateway. From `app.env` it reads
  only the three bridge address keys. It runs these reads side by side, prints
  one JSON object within 18 seconds even if Docker hangs, and changes nothing.
- The panel counts the 13 `mothership-private-*` containers, as on the Mac. The
  tunnel and the bridges have rows of their own.
- While `stack.sh up` or `stop`, or `docker compose` for this project, runs
  here, the panel shows **Busy** instead of an outage.
- The call runs at the cadence of the Mac's process reads: every 5 minutes while
  everything is healthy and the panel is closed, and every minute otherwise. If
  it fails for longer than 90 seconds, the panel says it can't reach the server.

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

## Backups

`backup.sh` (also `stack.sh backup …`) writes a backup set to
`$MOTHERSHIP_HOME/backups/<UTC stamp>/`. A set has four files:

- `postgres.dump`: `pg_dump` custom format.
- `redis.rdb`: a fresh `BGSAVE`, covering every Redis database.
- `state.tar.gz`: `app.env`, `stack.env`, `storage/` (without logs and
  framework caches), `looper/`, `video/`, `higgsfield-session/` and `tunnel/`.
  It is published only from a clean read. If a file changes while it is
  archived, the archive is retried up to three times, then the run fails.
- `manifest.json`: the bytes and sha256 of each file, the exact row count of
  every table and the Redis keyspace.

Elasticsearch is not backed up, because its indices are rebuilt from Postgres.
Asset bytes stay in the S3 bucket. The host keeps the newest four sets
(`MOTHERSHIP_BACKUP_KEEP`). Every run appends JSONL events to
`backups/backup.jsonl`.

```bash
source/deploy/linux-host/backup.sh create          # one set, path printed
source/deploy/linux-host/backup.sh restore-test    # newest set into a scratch database
```

`restore-test` runs these checks and exits 0 only if all of them pass:

1. Checks every file against the manifest.
2. Restores the dump into a scratch database (`mothership_restore_check`),
   compares every table's row count with the manifest, then drops the scratch
   database.
3. Runs `redis-check-rdb` on the RDB.
4. Lists the state archive.

The manifest counts are taken just before `pg_dump`, outside its snapshot. A
write between the two shows up as exit 3 with the differing tables listed.

### Off-host copies (Windows host)

On a Hyper-V host the copies live on a disk that holds neither the VM nor
Windows.

1. On Windows, run `windows/install-backup-pull.ps1` once, elevated. It does
   four things:
   - Creates `G:\Mothership\backups`, readable only by the owner, SYSTEM and
     Administrators.
   - Copies `pull-backup.ps1` to `D:\Mothership\scripts`.
   - Creates the key `C:\Users\<user>\.ssh\mothership_backup_ed25519`.
   - Registers the scheduled task `\Mothership\Mothership Backup Pull`. It runs
     every 6 hours from 00:30, uses S4U and needs nobody logged in.
2. In the VM, authorize that key for one command only, by adding this line to
   `~mothership/.ssh/authorized_keys`:

   ```text
   restrict,command="/srv/mothership/source/deploy/linux-host/backup.sh stream" ssh-ed25519 AAAA… mothership-backup-pull
   ```

Each run has the VM create a set and stream it as a tar. The puller then:

- checks every file's sha256 against the manifest;
- keeps the set as `G:\Mothership\backups\<stamp>\`;
- keeps every set from the last 14 days, and never fewer than the newest 8;
- logs JSONL to `G:\Mothership\backups\pull-backup.jsonl`.

Run it by hand with `schtasks /run /tn "\Mothership\Mothership Backup Pull"`.

### Restoring the live stack from a set

This replaces the live data with the set. Anything written after the set was
taken is lost, so take a fresh `backup.sh create` first if the stack still
runs. Run every step from `/srv/mothership`, with `SET` set to the set's
folder.

1. Check the set: `source/deploy/linux-host/backup.sh restore-test "$SET"`
   must exit 0.
2. Stop every writer. Postgres and Redis stay up:

   ```bash
   source/deploy/linux-host/stack.sh stop tunnel nginx fpm queue panorama-queue \
     stylepress-queue vectorization-queue ai-index-queue scheduler looper ai-bridge video-bridge
   ```

3. Replace the database. The restore stops at its first error, so a failed
   run leaves an incomplete database to restore again, never a mixed one:

   ```bash
   docker exec mothership-private-postgres sh -c \
     'dropdb -U "$POSTGRES_USER" --force "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'
   docker exec -i mothership-private-postgres sh -c \
     'pg_restore --no-owner --exit-on-error -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < "$SET/postgres.dump"
   ```

4. Replace Redis. The compose service starts with `--appendonly yes`, which
   would load an empty AOF and ignore the RDB. So load the RDB once in a
   temporary container with AOF off, switch AOF on, then return to the
   service:

   ```bash
   source/deploy/linux-host/stack.sh stop redis
   docker run --rm -v mothership-private-redis-data:/data -v "$SET":/set:ro redis:7.2-alpine \
     sh -c 'rm -rf /data/appendonlydir /data/dump.rdb && cp /set/redis.rdb /data/dump.rdb && chown redis:redis /data/dump.rdb'
   docker run -d --name mothership-redis-restore -v mothership-private-redis-data:/data redis:7.2-alpine redis-server --appendonly no
   docker exec mothership-redis-restore redis-cli INFO keyspace          # compare with the manifest's redis_keyspace
   docker exec mothership-redis-restore redis-cli CONFIG SET appendonly yes
   docker exec mothership-redis-restore redis-cli INFO persistence       # repeat until aof_rewrite_in_progress:0
   # Go on only when it also shows aof_enabled:1 and aof_last_bgrewrite_status:ok. A failed rewrite leaves an incomplete
   # AOF that the service would load instead of the RDB: run CONFIG SET appendonly yes again and recheck.
   docker stop mothership-redis-restore && docker rm mothership-redis-restore
   source/deploy/linux-host/stack.sh up redis
   ```

5. Restore the state files: `tar -C state -xzf "$SET/state.tar.gz"`.
6. Run `stack.sh up`, then `stack.sh verify`.
