"""Maintainer tool: create or check the offline inventory from tracked sources."""
import argparse
import hashlib
import json
import pathlib
import subprocess

PREFIXES = (
    'vereinsverwaltung/',
    'banking/', 'config/', 'docs/', 'docker/facebook-events/',
)
EXCLUDED_FROM_FRESH = {
    'banking/fints-institutions.json',
    # Repository development aliases are not customer installation entry points.
    'vereinsverwaltung/start-demo.sh',
    'vereinsverwaltung/start-demo.ps1',
    'vereinsverwaltung/start-local.ps1',
}


def inventory(root, paths):
    """Inventory the reviewed source set; never enumerate runtime directories."""
    root = pathlib.Path(root).resolve()
    php = json.loads((root / 'distribution/packages/php-runtime/manifest.json').read_text(encoding='utf-8'))
    linux = json.loads((root / 'distribution/packages/linux-runtime/manifest.json').read_text(encoding='utf-8'))
    artifacts = {
        'distribution/packages/php-runtime/' + component.get('archive', component.get('file'))
        for component in php['components']
    }
    for component in linux['components'] + linux.get('sources', []):
        artifacts.update('distribution/packages/linux-runtime/' + part['file'] for part in component['parts'])
    files, installer_files = [], []
    for name in sorted(set(paths)):
        if not name or name in EXCLUDED_FROM_FRESH:
            continue
        installer = (
            name.startswith('distribution/') and not name.startswith('distribution/extras/')
            and name != 'distribution/source-files.json' and name not in artifacts
        )
        if not installer and not name.startswith(PREFIXES) and name not in ('composer.json', 'composer.lock', 'LICENSE'):
            continue
        path = root / name
        if not path.resolve().is_relative_to(root) or path.is_symlink():
            raise RuntimeError('Unsafe source path: ' + name)
        if not path.is_file():
            raise RuntimeError('Tracked source missing: ' + name)
        data = path.read_bytes()
        try:
            data.decode('utf-8')
            text = b'\0' not in data and not name.startswith('distribution/packages/')
        except UnicodeDecodeError:
            text = False
        if text:
            data = data.replace(b'\r\n', b'\n')
        entry = {
            'path': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(),
            'normalized_lf': text, 'executable': name.endswith('.sh'),
        }
        (installer_files if installer else files).append(entry)
    return {'format': 1, 'files': files, 'installer_files': installer_files}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Check the committed inventory without changing files.')
    parser.add_argument('--include-untracked', action='append', default=[], help='Explicit reviewed source path; ignored runtime and backup files are never scanned.')
    options = parser.parse_args()
    root = pathlib.Path(__file__).resolve().parent.parent
    paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
    untracked = set(subprocess.check_output(['git', 'ls-files', '--others', '--exclude-standard', '-z'], cwd=root).decode().split('\0'))
    for name in options.include_untracked:
        candidate = root / name
        if name not in untracked or candidate.is_symlink() or not candidate.is_file() or not candidate.resolve().is_relative_to(root):
            raise RuntimeError('Explicit untracked source path is not safe: ' + name)
        paths.append(name)
    expected = inventory(root, paths)
    destination = root / 'distribution/source-files.json'
    if options.check:
        current = json.loads(destination.read_text(encoding='utf-8'))
        if current != expected:
            old = {entry['path']: entry for key in ('files', 'installer_files') for entry in current.get(key, [])}
            new = {entry['path']: entry for key in ('files', 'installer_files') for entry in expected[key]}
            changed = sorted(name for name in old.keys() | new.keys() if old.get(name) != new.get(name))
            print(json.dumps({'status': 'stale', 'changed': len(changed), 'examples': changed[:20]}, ensure_ascii=False))
            return 1
    else:
        destination.write_text(json.dumps(expected, ensure_ascii=False, indent=2) + '\n', encoding='utf-8', newline='\n')
    print(json.dumps({
        'status': 'current', 'files': len(expected['files']), 'installer_files': len(expected['installer_files']),
        'bytes': sum(entry['bytes'] for key in ('files', 'installer_files') for entry in expected[key]),
    }))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
