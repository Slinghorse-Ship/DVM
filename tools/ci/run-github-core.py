"""Run registered native contracts; operational round trips are selected explicitly."""
import json
import os
import pathlib
import re
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[2]
OPERATIONAL = {'native-installer', 'native-webspace-installer', 'native-installer-integrations',
               'native-recovery', 'native-package-upgrade', 'native-restore-resume'}


def suites(source):
    first = re.search(r'\$suites = \[(.*?)\];', source, re.S)
    if first is None:
        raise ValueError('Native registration list missing')
    registered = re.findall(r"'([^']+)'", first[1]) + re.findall(r"\$suites\[\] = '([^']+)';", source)
    if len(registered) != len(set(registered)) or not OPERATIONAL.issubset(registered):
        raise ValueError('Native registrations are incomplete or duplicated')
    return registered


def operational_changes(paths, full=False):
    return full or any(
        (name.startswith('distribution/') and name != 'distribution/source-files.json')
        or (name.startswith('vereinsverwaltung/native/Installer/')
            and name != 'vereinsverwaltung/native/Installer/PackageVersion.php')
        or name.startswith(('vereinsverwaltung/src/Infrastructure/Database/',
                             'vereinsverwaltung/migrations/', 'tools/cloud/'))
        or name in ('composer.json', 'composer.lock', 'vereinsverwaltung/composer.json',
                    'vereinsverwaltung/composer.lock')
        or name.endswith('/Schema.php')
        for name in paths
    )


def plan(registered, paths, full=False):
    operational = operational_changes(paths, full)
    selected = [name for name in registered if operational or name not in OPERATIONAL]
    return {'registered': len(registered), 'selected': selected,
            'operational': operational, 'deferred_operational_groups': [] if operational else sorted(OPERATIONAL)}


def main():
    if os.environ.get('GITHUB_ACTIONS') != 'true':
        raise RuntimeError('GitHub runner context required; use local-native.sh locally')
    event = json.loads(pathlib.Path(os.environ['GITHUB_EVENT_PATH']).read_text())
    full = str(event.get('inputs', {}).get('full', False)).lower() == 'true'
    before = event.get('pull_request', {}).get('base', {}).get('sha', event.get('before', ''))
    if before and before != '0' * 40:
        paths = subprocess.check_output(['git', 'diff', '--name-only', before, 'HEAD'], cwd=ROOT, text=True).splitlines()
    else:
        paths = []
        full = full or os.environ.get('GITHUB_EVENT_NAME') == 'push'
    result = plan(suites((ROOT / 'vereinsverwaltung/tests/ci-native.php').read_text()), paths, full)
    print(json.dumps(result), flush=True)
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
            output.write('operational=' + str(result['operational']).lower() + '\n')
    environment = os.environ.copy()
    if not result['operational']:
        environment['VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP'] = '1'
    return subprocess.run(['php', 'vereinsverwaltung/tests/ci-native.php', *result['selected']],
                          cwd=ROOT, env=environment).returncode


if __name__ == '__main__':
    raise SystemExit(main())
