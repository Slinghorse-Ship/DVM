"""Maintainer: export the self-contained fresh-install repository without operational history."""
import argparse
import hashlib
import json
import pathlib
import shutil
import subprocess

p = argparse.ArgumentParser()
p.add_argument('destination')
a = p.parse_args()
root = pathlib.Path(__file__).resolve().parent.parent
target = pathlib.Path(a.destination).resolve()
excluded_from_fresh = {'banking/fints-institutions.json'}
if target == root or root in target.parents and '.local' not in target.parts:
    raise SystemExit('Use a separate destination or an ignored .local export directory.')
if target.exists() and any(target.iterdir()):
    raise SystemExit('Destination must be empty; existing files are never overwritten.')
target.mkdir(parents=True, exist_ok=True)
manifest = json.loads((root / 'distribution/source-files.json').read_text(encoding='utf-8'))
paths = {x['path'] for x in manifest['files'] if x['path'] not in excluded_from_fresh}
for path in (root / 'distribution').rglob('*'):
    if path.is_file() and 'extras' not in path.relative_to(root / 'distribution').parts and '__pycache__' not in path.parts:
        paths.add(path.relative_to(root).as_posix())
paths.update(('.gitignore', '.gitattributes'))
for name in sorted(paths):
    if name in excluded_from_fresh:
        continue
    source = root / name
    if source.is_symlink() or not source.is_file():
        raise SystemExit('Required source missing: ' + name)
    dest = target / name
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, dest)
# Keep the current installation guide instead of an obsolete private-project README.
shutil.copy2(root / 'README.md', target / 'README.md')
commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
tree = subprocess.check_output(['git', 'rev-parse', 'HEAD^{tree}'], cwd=root, text=True).strip()
(target / 'INSTALLATION-SOURCE.json').write_text(json.dumps({'source_repository': 'Slinghorse-Ship/DVM', 'source_commit': commit, 'source_tree': tree, 'source_manifest_sha256': hashlib.sha256((root / 'distribution/source-files.json').read_bytes()).hexdigest(), 'contains_operational_data': False}, indent=2) + '\n', encoding='utf-8', newline='\n')
print(json.dumps({'destination': str(target), 'files': len(paths) + 2, 'source_commit': commit}))
