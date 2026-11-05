"""Package verified, separately installed PHP dependencies without network or app data.

Developer build helper. Customer installs use the committed archives, not this script.
The staging directory must contain root-project/, banking-project/, push-project/,
and composer-LICENSE.
"""
import argparse
import gzip
import hashlib
import io
import json
import pathlib
import re
import shutil
import tarfile

COMPOSER_SHA256 = '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6'
MAX_ARCHIVE_BYTES = 45 * 1024 * 1024


def digest(data):
    return hashlib.sha256(data).hexdigest()


def write_json(path, data):
    path.write_text(json.dumps(data, indent=2, sort_keys=True, ensure_ascii=False) + '\n', encoding='utf-8')


def safe_files(source, prefix=''):
    result = []
    for path in sorted(source.rglob('*'), key=lambda p: p.relative_to(source).as_posix()):
        if path.is_symlink():
            raise ValueError('Source symlink rejected: ' + str(path))
        if not path.is_file():
            continue
        relative = path.relative_to(source).as_posix()
        name = (prefix + '/' if prefix else '') + relative
        parts = pathlib.PurePosixPath(name).parts
        if name.startswith('/') or '\\' in name or ':' in name or any(x in ('', '.', '..', '.git') for x in parts):
            raise ValueError('Unsafe archive path: ' + name)
        if path.name in ('.env', 'auth.json') or path.suffix.lower() in ('.db', '.sqlite', '.sqlite3', '.dpapi', '.p12', '.pfx'):
            raise ValueError('Private runtime data rejected: ' + name)
        result.append((name, path))
    return result


def package(output, filename, source, prefix=''):
    files = safe_files(source, prefix)
    manifest = []
    dest = output / filename
    with dest.open('wb') as raw, gzip.GzipFile(fileobj=raw, filename='', mode='wb', mtime=0, compresslevel=9) as zipped:
        with tarfile.open(fileobj=zipped, mode='w', format=tarfile.PAX_FORMAT) as archive:
            for name, path in files:
                data = path.read_bytes()
                info = tarfile.TarInfo(name)
                info.size = len(data)
                info.mtime = 0
                info.mode = 0o644
                info.uid = info.gid = 0
                info.uname = info.gname = ''
                archive.addfile(info, io.BytesIO(data))
                manifest.append({'path': name, 'bytes': len(data), 'sha256': digest(data), 'mode': '0644'})
    if dest.stat().st_size > MAX_ARCHIVE_BYTES:
        raise ValueError('Archive exceeds configured 45 MiB limit: ' + filename)
    manifest_name = filename.removesuffix('.tar.gz') + '.files.json'
    write_json(output / manifest_name, manifest)
    # Verify all members by reading the completed archive, not the source directory.
    with tarfile.open(dest, 'r:gz') as archive:
        members = archive.getmembers()
        if [m.name for m in members] != [m['path'] for m in manifest]:
            raise ValueError('Archive inventory differs: ' + filename)
        for actual, expected in zip(members, manifest):
            if not actual.isfile() or actual.issym() or actual.islnk() or actual.mtime != 0:
                raise ValueError('Unexpected archive member: ' + actual.name)
            if digest(archive.extractfile(actual).read()) != expected['sha256']:
                raise ValueError('Archive member hash differs: ' + actual.name)
    return {
        'archive': filename, 'sha256': digest(dest.read_bytes()), 'bytes': dest.stat().st_size,
        'file_manifest': manifest_name, 'file_manifest_sha256': digest((output / manifest_name).read_bytes()),
        'files': len(manifest), 'unpacked_bytes': sum(x['bytes'] for x in manifest),
        'archive_prefix': prefix, 'strip_components': 0, 'executable_modes_required': False,
    }


def library_inventory(project, output):
    lock = json.loads((project / 'composer.lock').read_text(encoding='utf-8'))
    installed = json.loads((project / 'vendor/composer/installed.json').read_text(encoding='utf-8'))['packages']
    identity = lambda p: (p['name'], p['version'], p.get('source', {}).get('reference'))
    if {identity(p) for p in lock['packages']} != {identity(p) for p in installed}:
        raise ValueError('Installed dependencies do not match lock: ' + str(project))
    rows = []
    for dependency in sorted(lock['packages'], key=lambda p: p['name']):
        source = project / 'vendor' / dependency['name']
        notices = [p for p in source.iterdir() if p.is_file() and re.match(r'(?i)^(licen[cs]e|copying|notice)(\..*)?$', p.name)]
        if not any(re.match(r'(?i)^(licen[cs]e|copying)', p.name) for p in notices):
            raise ValueError('Package license file missing: ' + dependency['name'])
        license_files = []
        for notice in sorted(notices):
            dest = output / 'LICENSES' / (dependency['name'].replace('/', '--') + '-' + dependency['version']) / notice.name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(notice, dest)
            license_files.append({'path': dest.relative_to(output).as_posix(), 'sha256': digest(dest.read_bytes())})
        rows.append({
            'name': dependency['name'], 'version': dependency['version'], 'license': dependency.get('license', []),
            'source': dependency.get('source'), 'dist': dependency.get('dist'), 'license_files': license_files,
        })
    return rows


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--stage', type=pathlib.Path, required=True)
    parser.add_argument('--composer', type=pathlib.Path, required=True)
    parser.add_argument('--output', type=pathlib.Path, required=True)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    (args.output / 'LICENSES').mkdir(exist_ok=True)
    if digest(args.composer.read_bytes()) != COMPOSER_SHA256:
        raise ValueError('Composer differs from official 2.10.3 checksum')
    components = []
    for key, project_name, destination, lock_path in [
        ('root', 'root-project', 'project-root', 'composer.lock'),
        ('banking', 'banking-project', 'banking', 'banking/composer.lock'),
        ('push', 'push-project', 'vereinsverwaltung/push-runtime', 'vereinsverwaltung/push-runtime/composer.lock'),
    ]:
        project = args.stage / project_name
        libraries = library_inventory(project, args.output)
        item = package(args.output, key + '-vendor.tar.gz', project / 'vendor', 'vendor')
        item.update({'id': key + '-vendor', 'destination': destination, 'lock_path': lock_path,
                     'lock_sha256': digest((project / 'composer.lock').read_bytes()),
                     'composer_json_sha256': digest((project / 'composer.json').read_bytes()), 'packages': libraries,
                     'php_platform': '8.3.0', 'install_flags': ['--no-dev', '--no-scripts', '--no-plugins', '--optimize-autoloader']})
        components.append(item)
    shutil.copyfile(args.composer, args.output / 'composer-2.10.3.phar')
    shutil.copyfile(args.stage / 'composer-LICENSE', args.output / 'LICENSES/composer-LICENSE')
    components.append({'id': 'composer', 'version': '2.10.3', 'file': 'composer-2.10.3.phar',
                       'sha256': COMPOSER_SHA256, 'bytes': args.composer.stat().st_size, 'license': ['MIT'],
                       'source_url': 'https://getcomposer.org/download/2.10.3/composer.phar',
                       'checksum_url': 'https://getcomposer.org/download/2.10.3/composer.phar.sha256sum',
                       'required_for_customer_install': False})
    notices = [{'path': p.relative_to(args.output).as_posix(), 'bytes': p.stat().st_size, 'sha256': digest(p.read_bytes())}
               for p in sorted((args.output / 'LICENSES').rglob('*')) if p.is_file()]
    manifest = {'schema': 'kulturhus-offline-php-packages-v1', 'compression': 'gzip', 'archive_format': 'tar-pax',
                'deterministic_metadata': {'mtime': 0, 'uid': 0, 'gid': 0, 'mode': '0644'},
                'max_archive_bytes': MAX_ARCHIVE_BYTES, 'network_required_for_unpack': False,
                'components': components, 'license_files': notices,
                'install_contract': 'Verify archive and sorted member hashes. Extract vendor archives at each stated project root with zero stripped components. The native installer generates private configuration and deploys its own front controller. Never overwrite an existing installation or runtime data.'}
    write_json(args.output / 'manifest.json', manifest)
    checksums = [digest(p.read_bytes()) + '  ' + p.relative_to(args.output).as_posix() for p in sorted(args.output.rglob('*')) if p.is_file() and p.name != 'SHA256SUMS']
    (args.output / 'SHA256SUMS').write_text('\n'.join(checksums) + '\n', encoding='utf-8')
    print(json.dumps([{'id': c['id'], 'bytes': c['bytes'], 'sha256': c['sha256']} for c in components], indent=2))


if __name__ == '__main__':
    main()
