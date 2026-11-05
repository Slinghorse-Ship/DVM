"""Private, bounded adapter for the annual-maintenance UI. Never activates code.

Only the source-discovery command uses the network, and only through the
existing official DATEV redirect allowlist. Candidate/approval commands run
the same source-bound maintainer used by software maintenance.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from types import SimpleNamespace
import zipfile

import datev_years as d

LIMIT = 8 * 1024 * 1024


def module(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(name + '.py'))
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


def run(request, directory):
    directory = Path(directory).resolve()
    operation = request['operation']
    model, _ = d.read_json(d.ROOT / 'resources/accounting/purposes.json')
    editions, catalogs = d.load_editions(model)
    baseline = next((e for e in editions if e['year'] == request['from_year']), None)
    if type(request['from_year']) is not int or baseline is None or type(request['year']) is not int or request['year'] != baseline['year'] + 1 or request['year'] > 2100:
        raise ValueError('Zuerst den unmittelbar folgenden Jahresstand prüfen.')
    if operation == 'fetch':
        source = module('check-datev-skr42')
        page_url = source.EDITIONS['balance']
        page = source.Links()
        page.feed(source.fetch(page_url).decode('utf8', errors='replace'))
        edition = source.edition(' '.join(page.text)) or ''
        years = re.findall(r'\b20\d{2}\b', edition)
        if len(years) != 1:
            raise ValueError('Das Jahr der DATEV-Veröffentlichung ist nicht eindeutig. Quelle prüfen.')
        year = int(years[0])
        if year != request['year']:
            return {'state': 'unavailable', 'available_year': year, 'files': []}
        url = source.official_download(page_url, page.hrefs, page.download_hrefs)
        if not url:
            raise ValueError('Der offizielle PDF-Download ist derzeit nicht eindeutig verfügbar.')
        url, raw = source.download_pdf(url)
        if len(raw) > LIMIT:
            raise ValueError('Die PDF-Datei ist zu groß für diesen Import.')
        (directory / 'original.pdf').write_bytes(raw)
        sha = hashlib.sha256(raw).hexdigest()
        # A known original has a reviewed appendix count. Never guess a future layout.
        known = any(e['year'] == year and e['source']['pdf_sha256'] == sha for e in editions)
        return {'state': 'downloaded', 'source': url, 'pdf_sha256': sha,
                'appendix_pages': 2 if known else None, 'files': ['original.pdf']}
    if operation == 'compare':
        raw = (directory / 'original.pdf').read_bytes()
        if not raw.startswith(b'%PDF-') or len(raw) > LIMIT or hashlib.sha256(raw).hexdigest() != request['pdf_sha256']:
            raise ValueError('Das gespeicherte Original ist verändert. Erneut von DATEV laden.')
        appendix = request.get('appendix_pages')
        if type(appendix) is not int or not 0 <= appendix <= 20 or not d.official_url(request['source']):
            raise ValueError('Originalquelle und Anzahl der Erläuterungsseiten prüfen.')
        catalog_dir = directory / 'parsed'
        command = [os.environ.get('KULTURHUS_CATALOG_PYTHON') or sys.executable, '-X', 'utf8', str(d.ROOT / 'tools/import-datev-catalog.py'),
                   str(directory / 'original.pdf'), '--year', str(request['year']),
                   '--edition', 'balance', '--source-url', request['source'],
                   '--expected-sha256', request['pdf_sha256'], '--appendix-pages', str(appendix),
                   '--output-dir', str(catalog_dir)]
        parsed = subprocess.run(command, capture_output=True, encoding='utf8', timeout=95)
        if parsed.returncode:
            raise ValueError('Jahr oder Tabellenaufbau der PDF-Datei nicht sicher erkannt. Original prüfen.')
        candidate = catalog_dir / f"skr42-{request['year']}.json"
        maintainer = module('maintain-datev-skr42')
        target = directory / 'comparison'
        result = maintainer.run(SimpleNamespace(command='compare', from_year=request['from_year'],
                 taxonomy=d.ebilanz_release.catalog_taxonomy(request['year']) or baseline['taxonomy'],
                 candidate=candidate, output_dir=target))
        files = ['catalog.json', 'comparison.json', 'review.json', 'review.html']
        for name in files:
            (directory / name).write_bytes((target / name).read_bytes())
        return {key: value for key, value in result.items() if key not in ('directory', 'status')} | {
            'state': 'review_required', 'files': files}
    if operation == 'approve':
        plan, _ = d.read_json(directory / 'comparison.json')
        candidate, _ = d.read_json(directory / 'catalog.json')
        if (plan.get('from_year'), plan.get('year'), candidate.get('year')) != (request['from_year'], request['year'], request['year']):
            raise ValueError('Prüfpaket gehört zu einem anderen Jahresvergleich.')
        maintainer = module('maintain-datev-skr42')
        target = directory / 'package'
        result = maintainer.run(SimpleNamespace(command='approve', plan=directory / 'comparison.json',
            review=directory / 'submitted-review.json', candidate=directory / 'catalog.json', output_dir=target))
        # Include only the maintainer's declared, hash-bound files. Never arbitrary paths.
        manifest, _ = d.read_json(target / 'manifest.json')
        names = sorted(manifest['files']) + ['manifest.json']
        with zipfile.ZipFile(directory / 'package.zip', 'x', compression=zipfile.ZIP_DEFLATED) as archive:
            for name in names:
                if not re.fullmatch(r'[a-zA-Z0-9.-]+', name) or (target / name).is_symlink():
                    raise ValueError('Ungültiger Paketinhalt.')
                if name != 'manifest.json' and hashlib.sha256((target / name).read_bytes()).hexdigest() != manifest['files'][name]:
                    raise ValueError('Prüfpaket wurde nach der Prüfung verändert.')
                archive.write(target / name, name)
        return {'state': result['status'], 'coverage': result['coverage'],
                'production_authorized': False, 'files': ['package.zip', 'submitted-review.json']}
    raise ValueError('Unbekannter Vorgang.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    args = parser.parse_args()
    try:
        request = json.loads((args.directory / 'request.json').read_text(encoding='utf8'))
        result = run(request, args.directory)
        print(json.dumps(result, ensure_ascii=False))
        return 0
    except (ValueError, KeyError, TypeError, OSError, subprocess.TimeoutExpired):
        # No local paths, subprocess traces or environment data in a browser error.
        print(json.dumps({'state': 'failed', 'error': 'Kontenplan konnte nicht sicher geprüft werden. Original und Angaben prüfen.'}))
        return 2


if __name__ == '__main__':
    sys.exit(main())
