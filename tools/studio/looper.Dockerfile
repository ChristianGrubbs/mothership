FROM docker.io/library/python:3.12-slim-bookworm

ARG HOST_UID=502
ARG HOST_GID=20

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY tools/widgets/looper/backend/requirements.txt ./requirements.txt
RUN pip install --no-cache-dir --requirement requirements.txt

COPY tools/widgets/looper/backend/main.py ./main.py
# The host group may not exist in the base image (macOS gid 20 does, a Linux user's private group may not).
RUN (getent group "${HOST_GID}" >/dev/null || groupadd --gid "${HOST_GID}" looper) \
    && useradd --no-create-home --uid "${HOST_UID}" --gid "${HOST_GID}" --shell /usr/sbin/nologin looper \
    && mkdir -p /app/uploads \
    && chown -R "${HOST_UID}:${HOST_GID}" /app

USER ${HOST_UID}:${HOST_GID}
EXPOSE 3457
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "3457"]
