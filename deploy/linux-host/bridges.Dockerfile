# The AI and video bridges for the Linux host (docs/ops/linux-host.md). The bridge programs are the
# same files the macOS launcher runs natively; this image only supplies their tools. Build context:
# the repository root.
FROM docker.io/denoland/deno:bin-2.9.8 AS deno

FROM docker.io/library/python:3.12-slim-bookworm

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# yt-dlp solves YouTube's JavaScript challenges with deno; downie drives yt-dlp and ffmpeg.
COPY --from=deno /deno /usr/local/bin/deno
RUN pip install --no-cache-dir "downie==0.2.0" "yt-dlp[default]==2026.10.26" \
    && deno --version && yt-dlp --version && downie video download --help >/dev/null

WORKDIR /app
COPY tools/studio/ai-service/server.py ./ai-service/server.py
COPY tools/studio/video-service/server.py ./video-service/server.py
COPY deploy/linux-host/bridge.py ./bridge.py

RUN groupadd --non-unique --gid "${HOST_GID}" bridge \
    && useradd --no-create-home --non-unique --uid "${HOST_UID}" --gid "${HOST_GID}" --shell /usr/sbin/nologin bridge \
    && mkdir -p /data && chown "${HOST_UID}:${HOST_GID}" /data

USER ${HOST_UID}:${HOST_GID}
ENTRYPOINT ["python3", "/app/bridge.py"]
