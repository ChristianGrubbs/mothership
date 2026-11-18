#!/usr/bin/env python3
"""Run on Umbrel: replace only the RSS adapter, retaining its previous container."""
import json
import subprocess
from pathlib import Path

NAME = 'mothership-rss-api'
PREVIOUS = NAME + '-previous'
IMAGE = NAME + ':signal-v2'
STATE_VOLUME = 'mothership-rss-state'
# Host-only settings: the RSSHub address (SIGNAL_RSSHUB_URL, optional SIGNAL_RSSHUB_HINT) and ntfy
# for the source alerts (NTFY_URL, NTFY_TOPIC, NTFY_USER, NTFY_PASS).
# It never leaves the host, and it owns its keys: the replayed environment would
# otherwise pin the values from the previous deploy.
ALERTS_ENV = Path(__file__).resolve().parent / 'alerts.env'


def env_args(current, env_file):
    present = env_file.is_file()
    # Docker env-files also allow a bare KEY (taken from the caller's environment).
    owned = {line.split('=', 1)[0].strip() for line in env_file.read_text().splitlines()
             if line.strip() and not line.lstrip().startswith('#')} if present else set()
    args = []
    for value in current:
        if value.split('=', 1)[0] not in owned:
            args.extend(['--env', value])
    return args + (['--env-file', str(env_file)] if present else [])


def mount_args(mounts):
    """Replay the running adapter's mounts, failing closed on anything unfamiliar.

    Our own cache volume is skipped rather than replayed, because main() re-adds it
    explicitly; treating it as unexpected made every deploy after the first one fail.
    """
    args = []
    for mount in mounts:
        if mount['Type'] == 'volume' and mount.get('Name') == STATE_VOLUME:
            continue
        if mount['Type'] != 'bind':
            raise SystemExit('Unexpected existing mount type')
        spec = f"type=bind,src={mount['Source']},dst={mount['Destination']}"
        args.extend(['--mount', spec + ('' if mount['RW'] else ',readonly')])
    return args


def docker(*args, capture=False):
    return subprocess.run(['docker', *args], check=True, text=True,
                          stdout=subprocess.PIPE if capture else None)


def main():
    config = json.loads(docker('inspect', NAME, capture=True).stdout)[0]
    existing = docker('ps', '-a', '--format', '{{.Names}}', capture=True).stdout.splitlines()
    if PREVIOUS in existing:
        raise SystemExit('Previous rollback container already exists; reconcile it before deploying again.')
    networks = list(config['NetworkSettings']['Networks'])
    if len(networks) != 1 or config['HostConfig'].get('PortBindings'):
        raise SystemExit('Unexpected adapter network topology; inspect before deploying.')
    docker('build', '-t', IMAGE, str(Path(__file__).resolve().parent))
    args = ['run', '-d', '--name', NAME, '--network', networks[0],
            '--restart', config['HostConfig']['RestartPolicy']['Name']]
    # Never print environment values; preserve the existing adapter configuration.
    args.extend(env_args(config['Config']['Env'], ALERTS_ENV))
    args.extend(mount_args(config['Mounts']))
    args.extend(['--mount', f'type=volume,src={STATE_VOLUME},dst=/state', IMAGE])
    docker('stop', NAME)
    docker('rename', NAME, PREVIOUS)
    try:
        docker(*args)
    except subprocess.CalledProcessError:
        # docker run may create a stopped container before failing to start it.
        subprocess.run(['docker', 'rm', '-f', NAME], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        docker('rename', PREVIOUS, NAME)
        docker('start', NAME)
        raise SystemExit('Adapter start failed; previous container restored.') from None
    print('Adapter started. Verify health and all source timestamps before shipping the UI.')


if __name__ == '__main__':
    main()
