"""Restore only the ERiC product-runtime archive bundled with DVM, never a developer SDK."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import tempfile


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def private_directory(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError('Privaten absoluten Zielpfad verwenden.')
    for parent in (path, *path.parents):
        if parent.is_symlink():
            raise ValueError('Verzeichnisverknüpfungen sind nicht erlaubt.')
    path.mkdir(mode=0o700, parents=True, exist_ok=True)
    if os.name != 'nt' and path.stat().st_mode & 0o077:
        raise ValueError('Zielverzeichnis benötigt private Dateirechte0700.')
    return path


def restore(base, destination, entry):
    name = entry['original_name']
    if not re.fullmatch(r'ERiC-[A-Za-z0-9_.-]+\.tar\.gz', name):
        raise ValueError('Ungültiger amtlicher Paketname.')
    target = destination / name
    if target.is_symlink():
        raise ValueError('Ziel darf keine Verknüpfung sein.')
    if target.exists():
        if not target.is_file() or target.stat().st_size != entry['bytes'] or digest(target) != entry['sha256']:
            raise ValueError('Vorhandene Originaldatei weicht ab; sie bleibt erhalten.')
        return {'file': name, 'bytes': entry['bytes'], 'sha256': entry['sha256'], 'retained': True}
    total = 0
    whole = hashlib.sha256()
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(prefix='.eric-original-', dir=destination, delete=False) as output:
            temporary = Path(output.name)
            for part in entry['parts']:
                relative = Path(part['file'])
                if relative.is_absolute() or '..' in relative.parts or '\\' in part['file']:
                    raise ValueError('Unsicherer Paketpfad.')
                source = base / relative
                if source.is_symlink() or not source.resolve(strict=True).is_relative_to(base.resolve(strict=True)):
                    raise ValueError('Paketverknüpfungen sind nicht erlaubt.')
                count = 0
                one = hashlib.sha256()
                with source.open('rb') as stream:
                    while block := stream.read(1024 * 1024):
                        count += len(block)
                        if count > part['bytes']:
                            raise ValueError('Paketbestandteil ist größer als angegeben.')
                        output.write(block)
                        one.update(block)
                        whole.update(block)
                if count != part['bytes'] or one.hexdigest() != part['sha256']:
                    raise ValueError('Paketbestandteil fehlt oder ist verändert.')
                total += count
            if total != entry['bytes'] or whole.hexdigest() != entry['sha256']:
                raise ValueError('Amtliches Gesamtpaket stimmt nicht überein.')
            output.flush()
            os.fsync(output.fileno())
        # An exclusive link publishes only the complete file and never overwrites
        # another process's retained original. Both files are on the same filesystem.
        os.link(temporary, target)
        return {'file': name, 'bytes': total, 'sha256': whole.hexdigest(), 'retained': False}
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, type=Path, help='Privates Ziel außerhalb des Webroots.')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    output = args.output
    if output.resolve().is_relative_to(root):
        raise ValueError('Produktlaufzeit außerhalb des Repository-/Webverzeichnisses ablegen.')
    base = root / 'distribution/packages/linux-runtime'
    manifest = json.loads((base / 'manifest.json').read_text(encoding='utf-8'))
    entries = [entry for entry in manifest['components'] if entry['id'] == 'eric-runtime']
    if len(entries) != 1 or any(entry['version'] != '44.3.6.0' for entry in entries):
        raise ValueError('ERiC44.3.6.0-Produktlaufzeit erforderlich; kein Entwickler-SDK enthalten.')
    destination = private_directory(output)
    results = [restore(base, destination, entry) for entry in entries]
    print(json.dumps({'version': '44.3.6.0', 'runtime_archives': results, 'developer_documentation_included': False, 'live_transport': False}, ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, KeyError, TypeError, json.JSONDecodeError):
        print(json.dumps({'error': 'ERiC-Produktlaufzeit nicht vollständig wiederhergestellt. Vorhandene Dateien bleiben erhalten.', 'live_transport': False}), flush=True)
        raise SystemExit(1)
