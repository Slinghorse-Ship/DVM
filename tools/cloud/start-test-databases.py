#!/usr/bin/env python3
"""Prepare pinned, loopback-only local test servers without replacing containers."""
import argparse
import json
import os
import subprocess
import time

DIGEST = 'sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242'
IMAGE = 'mysql:8.4@' + DIGEST
SERVERS = {
    'native': (('kulturhus-native-ci-mysql',), '13371', 'synthetic-ci-only'),
    'calendar': (('kulturhus-calendar-ci-mysql', 'kulturhus-finance-ci-mysql'), '13372', 'isolated-regression-only'),
}
MEMORY = {'native': '4g', 'calendar': '2g'}


def prepare_memory(kind, name, container):
    """Upgrade only our exact former default; keep custom and unlimited budgets."""
    if (kind != 'native'
            or (container['Config'].get('Labels') or {}).get('kulturhus.purpose') != 'local-synthetic-ci'
            or container['HostConfig'].get('Memory') != 2147483648
            or container['HostConfig'].get('MemorySwap') != 2147483648):
        return
    identity = container['Id']
    docker('update', '--memory=4g', '--memory-swap=4g', identity)
    adjusted = inspect(name)
    if (adjusted is None or adjusted.get('Id') != identity
            or adjusted['HostConfig'].get('Memory') != 4294967296
            or adjusted['HostConfig'].get('MemorySwap') != 4294967296):
        raise RuntimeError('Lokales Speicherbudget nicht bestätigt; ursprüngliche Laufzeit und Daten bleiben erhalten.')
    print('Eigener nativer Testserver: früheres 2-GB-Standardbudget auf4 GB erweitert; Container und Daten erhalten.')


def docker(*args, optional=False):
    environment = {k: v for k, v in os.environ.items() if k not in {
        'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'}}
    result = subprocess.run(['docker', '--host=unix:///var/run/docker.sock', *args],
                            env=environment, capture_output=True, text=True, timeout=55)
    if result.returncode and not optional:
        raise RuntimeError('Lokale MySQL-Laufzeit nicht erreichbar oder nicht bereit; vorhandene Container bleiben erhalten.')
    return result


def inspect(name):
    result = docker('container', 'inspect', name, optional=True)
    if not result.returncode:
        records = json.loads(result.stdout)
        if len(records) != 1:
            raise RuntimeError('Lokale Containerzuordnung nicht eindeutig.')
        return records[0]
    # Never interpret a daemon/permission error as an absent container.
    if 'No such container' not in result.stderr and 'No such object' not in result.stderr:
        raise RuntimeError('Containerprüfung fehlgeschlagen; keine neue Laufzeit anlegen.')
    return None


def validate(container, name, port, password):
    config = container['Config']
    images = json.loads(docker('image', 'inspect', container['Image']).stdout)
    digest_matches = len(images) == 1 and images[0]['Id'] == container['Image'] and any(
        value.rsplit('@', 1)[-1] == DIGEST for value in images[0].get('RepoDigests', []))
    environment = config.get('Env', [])
    bindings = container['HostConfig'].get('PortBindings')
    if (container.get('Name') != '/' + name or not digest_matches
            or environment.count('MYSQL_ROOT_PASSWORD=' + password) != 1
            or sum(value.startswith('MYSQL_ROOT_PASSWORD=') for value in environment) != 1
            or 'MYSQL_ROOT_HOST=%' not in environment
            or config.get('User', '') not in ('', 'mysql')
            or bindings != {'3306/tcp': [{'HostIp': '127.0.0.1', 'HostPort': port}]}
            or container['HostConfig'].get('NetworkMode') == 'host'
            or container['HostConfig'].get('Privileged', False)):
        raise RuntimeError('Name durch unpassende Laufzeit belegt; Container, Ports und Daten bleiben erhalten.')


def prepare(selection):
    docker('info')
    plans = []
    for kind in selection:
        names, port, password = SERVERS[kind]
        existing = []
        for name in names:
            container = inspect(name)
            if container is not None:
                validate(container, name, port, password)
                existing.append((name, container))
        if len(existing) > 1:
            raise RuntimeError('Mehrere lokale Testserver für denselben Port vorhanden; keine Container verändern.')
        plans.append((kind, existing[0][0] if existing else names[0], port, password, existing))
    # Inspect every selected existing server before starting or creating any server.
    for kind, name, port, password, existing in plans:
        if existing:
            prepare_memory(kind, name, existing[0][1])
            docker('start', name)
        else:
            docker('run', '-d', '--name', name, '--label', 'kulturhus.purpose=local-synthetic-ci',
                   '-p', '127.0.0.1:' + port + ':3306', '--memory=' + MEMORY[kind], '--memory-swap=' + MEMORY[kind], '--cpus=2',
                   '-e', 'MYSQL_ROOT_PASSWORD=' + password, '-e', 'MYSQL_ROOT_HOST=%', IMAGE,
                   '--binlog-expire-logs-seconds=3600', '--max-binlog-size=104857600')
            created = inspect(name)
            if created is None:
                raise RuntimeError('Neue lokale Testlaufzeit nicht prüfbar; keine Daten entfernen.')
            validate(created, name, port, password)
        for attempt in range(45):
            result = docker('exec', '-e', 'MYSQL_PWD=' + password, name, 'mysql', '--user=root',
                            '--batch', '--skip-column-names', '--execute=SELECT 1', optional=True)
            if result.returncode == 0 and result.stdout.strip() == '1':
                break
            if attempt == 44:
                raise RuntimeError('Lokale Testdatenbank nicht bereit; ursprünglicher Container bleibt erhalten.')
            time.sleep(1)
        print('Lokale ' + kind + '-Testdatenbank auf127.0.0.1:' + port + ' bereit; keine Befüllung oder Schemaarbeit.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    choice = parser.add_mutually_exclusive_group(required=True)
    for name in ['native', 'calendar', 'all']:
        choice.add_argument('--' + name, dest='mode', action='store_const', const=name)
    args = parser.parse_args()
    if os.environ.get('DVM_LOCAL_TEST_DATABASES') != '1' or os.environ.get('GITHUB_ACTIONS') == 'true':
        parser.error('Nur ausdrücklich aktivierte lokale synthetische Prüfungen; Actions hat einen eigenen Startweg.')
    try:
        prepare(SERVERS if args.mode == 'all' else [args.mode])
    except (RuntimeError, ValueError, KeyError, OSError, subprocess.SubprocessError) as error:
        parser.exit(1, str(error) + '\n')


if __name__ == '__main__':
    main()
