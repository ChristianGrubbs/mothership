#!/usr/bin/env bash
# Writes the private proof that tests/Support/TestTargetGuard::loadCiProof() verifies, derived from THIS job's own
# PostgreSQL service container (Docker's view of it), and prints the proof's SHA-256 on stdout (nothing else).
#
#   MOTHERSHIP_CI_PROOF_SHA256="$(bash .github/scripts/ci-pg-proof.sh)"
#
# Inputs (from the workflow step): MOTHERSHIP_CI_SERVICE_ID (job.services.db.id), MOTHERSHIP_CI_SERVICE_PORT
# (job.services.db.ports[5432]), MOTHERSHIP_CI_DB_USER (the service's POSTGRES_USER), and the runner's own GITHUB_RUN_ID,
# GITHUB_RUN_ATTEMPT, GITHUB_WORKSPACE, RUNNER_TEMP.
#
# Fail-closed: any missing, ambiguous or unexpected fact exits non-zero with a message naming that fact, and writes no
# proof. The only commands run against the container are `docker inspect` and one read-only SELECT through `docker exec`.
set -euo pipefail
umask 077

die() {
    echo "ci-pg-proof: $*" >&2
    exit 1
}

DATABASE=mothership_test_ci
DATA_DIRECTORY=/var/lib/postgresql/data

for name in MOTHERSHIP_CI_SERVICE_ID MOTHERSHIP_CI_SERVICE_PORT MOTHERSHIP_CI_DB_USER GITHUB_RUN_ID GITHUB_RUN_ATTEMPT GITHUB_WORKSPACE RUNNER_TEMP; do
    [[ -n "${!name:-}" ]] || die "$name is not set"
done

service_id=$MOTHERSHIP_CI_SERVICE_ID
service_port=$MOTHERSHIP_CI_SERVICE_PORT
[[ $service_id =~ ^[0-9a-f]{64}$ ]] || die "job.services.db.id must be a full 64-hex container id (got length ${#service_id}: '$service_id')"
[[ $service_port =~ ^[0-9]{4,5}$ ]] || die "job.services.db.ports[5432] must be a host port (got '$service_port')"
[[ $MOTHERSHIP_CI_DB_USER =~ ^[A-Za-z0-9_]{1,63}$ ]] || die "database user is not a plain identifier"
[[ $GITHUB_RUN_ID =~ ^[0-9]{1,20}$ && $GITHUB_RUN_ATTEMPT =~ ^[0-9]{1,6}$ ]] || die "run id or attempt is malformed"
command -v docker >/dev/null || die "docker is not on PATH for this step"

workspace=$(cd "$GITHUB_WORKSPACE" && pwd -P)
[[ $workspace == "$GITHUB_WORKSPACE" ]] || die "GITHUB_WORKSPACE ($GITHUB_WORKSPACE) is not canonical ($workspace)"
[[ $workspace =~ ^[A-Za-z0-9_./-]+$ ]] || die "workspace path has characters the proof does not carry"
temp=$(cd "$RUNNER_TEMP" && pwd -P)
case $temp/ in "$workspace"/*) die "RUNNER_TEMP is inside the checkout" ;; esac

# One docker inspect with a Go template; a failure names the fact that could not be read instead of dying silently.
inspect() {
    local what=$1 format=$2 out
    out=$(docker inspect --format "$format" "$service_id" 2>&1) || die "docker inspect could not read $what: $out"
    printf '%s\n' "$out"
}

[[ "$(inspect 'container id' '{{.Id}}')" == "$service_id" ]] || die "docker reports another id for job.services.db.id"
[[ "$(inspect 'running state' '{{.State.Running}}')" == true ]] || die "service container is not running"

# The Docker-allocated host port for container port 5432 must be exactly the one the Actions context reports.
# Docker may publish 5432/tcp on several host addresses (0.0.0.0 and [::]) with different ports, so the job's port must be
# a member of the mapping, and the member must be an IPv4 binding: the tests connect to 127.0.0.1.
mappings=$(inspect 'port mapping for 5432/tcp' '{{with (index .NetworkSettings.Ports "5432/tcp")}}{{range .}}{{.HostIp}}:{{.HostPort}}{{"\n"}}{{end}}{{end}}' | sed '/^$/d' | sort -u)
[[ -n $mappings ]] || die "docker reports no host mapping for 5432/tcp (job context says $service_port)"
shown=${mappings//$'\n'/ }
grep -Eq "^[0-9.]+:${service_port}\$" <<<"$mappings" || die "job service port $service_port is not an IPv4 host mapping of 5432/tcp; docker maps (HostIp:HostPort): [$shown]"
grep -Eq "^(0\.0\.0\.0|127\.0\.0\.1):${service_port}\$" <<<"$mappings" || die "job service port $service_port is bound to a host address other than 0.0.0.0/127.0.0.1; docker maps (HostIp:HostPort): [$shown]"

# The data directory must be a tmpfs of this very container. `--tmpfs` service options are recorded in HostConfig.Tmpfs (not in
# .Mounts), so the requested option is read there, and the running container is then asked what filesystem actually sits on it.
requested=$(inspect 'HostConfig.Tmpfs' '{{range $path, $options := .HostConfig.Tmpfs}}{{$path}}{{"\n"}}{{end}}' | sed '/^$/d')
grep -Fxq "$DATA_DIRECTORY" <<<"$requested" || die "HostConfig.Tmpfs does not contain $DATA_DIRECTORY; HostConfig.Tmpfs: [${requested//$'\n'/ }]"
mountinfo=$(docker exec "$service_id" cat /proc/self/mountinfo 2>/dev/null || true)
if [[ -n $mountinfo ]]; then
    # mountinfo: "id parent maj:min root mountpoint opts [optional...] - fstype source superopts"; the last entry for a path wins.
    fstype=$(awk -v d="$DATA_DIRECTORY" '$5 == d { for (i = 6; i <= NF; i++) if ($i == "-") { t = $(i + 1); break } } END { print t }' <<<"$mountinfo")
    source_of=/proc/self/mountinfo
else
    mountinfo=$(docker exec "$service_id" cat /proc/mounts 2>&1) || die "cannot read /proc/self/mountinfo or /proc/mounts in the container: $mountinfo"
    fstype=$(awk -v d="$DATA_DIRECTORY" '$2 == d { t = $3 } END { print t }' <<<"$mountinfo")
    source_of=/proc/mounts
fi
[[ $fstype == tmpfs ]] || die "$DATA_DIRECTORY is not a tmpfs in the running container ($source_of says '${fstype:-no mount}'); HostConfig.Tmpfs: [${requested//$'\n'/ }]"

# Every IPv4 address the container holds: the server reports whichever one the mapped connection reaches.
ips=$(inspect 'network addresses' '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{"\n"}}{{end}}' | sed '/^$/d' | sort -u)
[[ -n $ips ]] || die "container has no IPv4 address on any network (.NetworkSettings.Networks is empty or has no IPAddress)"
while IFS= read -r ip; do
    [[ $ip =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || die "unexpected container address '$ip'"
done <<<"$ips"
ips_json=$(awk 'BEGIN{printf "["} {printf "%s\"%s\"", (NR>1?",":""), $0} END{printf "]"}' <<<"$ips")

# Identity of the running postmaster, read-only, from inside the container (local socket as the service user).
psql_err=$(mktemp "$temp/ci-pg-proof-psql.XXXXXX")
identity=$(docker exec "$service_id" psql -X -q -At -F '|' -U "$MOTHERSHIP_CI_DB_USER" -d "$DATABASE" -c \
    "select current_database(), current_setting('data_directory'), floor(extract(epoch from pg_postmaster_start_time()))::bigint, (select system_identifier::text from pg_control_system())" 2>"$psql_err") \
    || die "docker exec psql failed as $MOTHERSHIP_CI_DB_USER on the container's local socket: $(cat "$psql_err")"
rm -f "$psql_err"
IFS='|' read -r live_database live_data live_started live_sysid <<<"$identity"
[[ $live_database == "$DATABASE" && $live_data == "$DATA_DIRECTORY" ]] || die "server reports database '$live_database' data directory '$live_data'"
[[ $live_started =~ ^[0-9]{9,12}$ && $live_sysid =~ ^[0-9]{1,20}$ ]] || die "server identity is malformed: '$identity'"

proof="$temp/mothership-ci-pg-proof.json"
rm -f "$proof"
set -o noclobber
printf '{"container_id":"%s","run_id":"%s","run_attempt":"%s","workspace":"%s","host_port":%s,"container_ips":%s,"database":"%s","data_directory":"%s","tmpfs":true,"started_at":%s,"system_identifier":"%s"}' \
    "$service_id" "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" "$workspace" "$service_port" "$ips_json" "$DATABASE" "$DATA_DIRECTORY" "$live_started" "$live_sysid" >"$proof"
chmod 600 "$proof"

if command -v sha256sum >/dev/null; then sha256sum "$proof" | cut -d' ' -f1; else shasum -a 256 "$proof" | cut -d' ' -f1; fi
