"""Published XBRL sources and explicit annual choices, independent of adapters.

Having a historic taxonomy is not an output or transport approval. The selected
annual package separately binds an implemented adapter and the installed ERiC.
"""
import hashlib
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parent.parent
VERSIONS = ('6.7', '6.8', '6.9', '6.10')


def registry():
    value = json.loads((ROOT / 'resources/ebilanz/taxonomies.json').read_text(encoding='utf8'))
    if value.get('schema') != 1 or tuple(value.get('sources', {})) != VERSIONS:
        raise ValueError('Geprüfte Taxonomiequellen prüfen.')
    for version, entry in value['sources'].items():
        if (not re.fullmatch(r'20\d{2}-04-01', entry.get('date', ''))
            or type(entry.get('regular_start_year')) is not int
            or entry.get('optional_start_year') != entry['regular_start_year'] - 1
            or entry['date'][:4] != str(entry['optional_start_year'])
            or not re.fullmatch(r'[a-f0-9]{64}', entry.get('sha256', ''))
            or not entry.get('source', '').startswith('https://www.esteuer.de/download/')):
            raise ValueError('Taxonomiequelle und amtliche Jahresangabe stimmen nicht überein.')
    return value['sources']


def source(version):
    entries = registry()
    if not isinstance(version, str) or version not in entries:
        raise ValueError('Geprüfte Taxonomiequelle fehlt.')
    entry = entries[version]
    raw = (ROOT / f'resources/ebilanz/taxonomy-{version}.json').read_bytes().replace(b'\r\n', b'\n')
    if hashlib.sha256(raw).hexdigest() != entry['sha256']:
        raise ValueError('Amtliche Taxonomiequelle wurde verändert.')
    meta = json.loads(raw)
    if (meta.get('version') != version or meta.get('date') != entry['date']
        or meta.get('source') != entry['source'] or not meta.get('source_files_sha256')):
        raise ValueError('Taxonomiemetadaten und Originalquelle stimmen nicht überein.')
    return raw, meta


def choices(start_year):
    """Known regular and expressly optional releases by fiscal START year.

    This is not a generic licence for all later years. New annual publications
    need a reviewed update instead of guessing that the last version suffices.
    """
    if type(start_year) is not int or not 2000 <= start_year <= 2100:
        raise ValueError('Beginnjahr des Wirtschaftsjahrs prüfen.')
    entries = registry()
    return {'regular': next((v for v, e in entries.items() if e['regular_start_year'] == start_year), None),
            'optional': next((v for v, e in entries.items() if e['optional_start_year'] == start_year), None)}
