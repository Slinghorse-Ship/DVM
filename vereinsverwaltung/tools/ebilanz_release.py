"""Reviewed adapter packages, independent of annual DATEV account sources.

Package selection is software maintenance, never an upload or a newest-version
heuristic. It authorizes catalog reuse and local validation, not a fiscal release.
An added year in JSON cannot implement an adapter or its annual tax rules.
"""
import hashlib
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET
from functools import lru_cache

ROOT = Path(__file__).resolve().parent.parent
# Capabilities actually implemented by the generator. Extend only with a new
# adapter implementation and its acceptance; the package alone cannot widen it.
ADAPTERS = {'verein-6.9-v1': {'taxonomy': '6.9', 'date': '2025-04-01',
                            'years': [2025, 2026], 'data_version': 'Bilanz_6_9',
                            'stage': 'catalog_and_local_validation'},
            'verein-6.10-catalog-v1': {'taxonomy': '6.10', 'date': '2026-04-01',
                            'years': [2027], 'data_version': 'Bilanz_6_10',
                            'stage': 'catalog_preparation'}}

# Explicit software-maintenance defaults for annual comparison, not a newest-
# taxonomy heuristic. Future years stay unapproved until their source is reviewed.
CATALOG_TAXONOMIES = {2025: '6.9', 2026: '6.9', 2027: '6.10'}


def catalog_taxonomy(year):
    if type(year) is not int:
        raise ValueError('Jahr des Kontenvergleichs prüfen.')
    return CATALOG_TAXONOMIES.get(year)


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True,
                                    separators=(',', ':')).encode()).hexdigest()


@lru_cache(maxsize=4)
def _metadata(raw):
    # Cache bytes, never only the pathname: replacing a file invalidates it.
    return json.loads(raw)


def package(taxonomy, year):
    """Resolve one explicit, complete package. No defaults for unsupported years."""
    if not isinstance(taxonomy, str) or type(year) is not int:
        raise ValueError('Jahr und Taxonomieversion prüfen.')
    try:
        registry = json.loads((ROOT / 'resources/ebilanz/releases.json').read_text(encoding='utf8'))
        if registry.get('schema') != 1 or not isinstance(registry.get('packages'), list):
            raise ValueError('Ungültige E-Bilanz-Paketauswahl.')
        matches = [p for p in registry['packages'] if p.get('taxonomy', {}).get('version') == taxonomy
                   and year in p.get('years', [])]
        if len(matches) != 1:
            raise ValueError('Für dieses Jahr fehlt ein eindeutiges geprüftes E-Bilanz-Paket.')
        p = matches[0]
        adapter = ADAPTERS.get(p.get('adapter'))
        if (not adapter or not isinstance(p.get('id'), str)
                or not re.fullmatch(r'[a-z0-9.-]+', p['id'])
                or p.get('stage') != adapter['stage']
                or p.get('production_authorized') is not False
                or not p['years'] or any(type(y) is not int for y in p['years'])
                or p['years'] != sorted(set(p['years']))
                or not set(p['years']) <= set(adapter['years'])
                or taxonomy != adapter['taxonomy'] or p['taxonomy']['date'] != adapter['date']
                or p['eric']['data_version'] != adapter['data_version']):
            raise ValueError('Jahrespaket passt nicht zum implementierten E-Bilanz-Adapter.')
        path = ROOT / 'resources/ebilanz' / ('taxonomy-' + adapter['taxonomy'] + '.json')
        raw = path.read_bytes().replace(b'\r\n', b'\n')
        meta = _metadata(raw)
        if (hashlib.sha256(raw).hexdigest() != p['taxonomy']['sha256']
                or meta['version'] != taxonomy or meta['date'] != adapter['date']
                or meta['source'] != p['taxonomy']['source']
                or not meta.get('source_files_sha256')):
            raise ValueError('Amtliche Taxonomiequelle und geprüftes Jahrespaket stimmen nicht überein.')
        versions = p['eric']['reviewed_versions']
        if not isinstance(versions, list) or (not versions and adapter['stage'] != 'catalog_preparation'):
            raise ValueError('Geprüfte ERiC-Version fehlt im Jahrespaket.')
        if adapter['stage'] == 'catalog_preparation' and versions:
            raise ValueError('Das Vorbereitungspaket hat noch keinen abgenommenen ERiC-Prüfer.')
        for v in versions:
            if set(v) != {'ericapi', 'checkbilanz'} or any(
                    not isinstance(s, str) or not re.fullmatch(r'\d+\.\d+\.\d+\.\d+', s) for s in v.values()):
                raise ValueError('Geprüften ERiC-Versionsstand prüfen.')
        return p
    except (OSError, KeyError, TypeError, AttributeError, json.JSONDecodeError):
        raise ValueError('Geprüftes E-Bilanz-Jahrespaket fehlt oder ist unvollständig.') from None


def status(taxonomy, year):
    try:
        p = package(taxonomy, year)
    except ValueError as exc:
        return {'ready': False, 'reason': str(exc), 'production_authorized': False}
    return {'ready': True, 'id': p['id'], 'sha256': digest(p),
            'taxonomy': taxonomy, 'year': year, 'adapter': p['adapter'],
            'data_version': p['eric']['data_version'],
            'reviewed_eric_versions': p['eric']['reviewed_versions'],
            'native_validation_ready': p['stage'] == 'catalog_and_local_validation',
            'stage': p['stage'], 'production_authorized': False}


def check_runtime(p, version_xml):
    """Match both API and balance plugin. Newer/different is not already reviewed.

    EricVersion is local SDK metadata; no certificate or transport is needed.
    Unrelated forms may update without silently changing this approval.
    """
    if p.get('stage') != 'catalog_and_local_validation' or not p.get('eric', {}).get('reviewed_versions'):
        raise ValueError('Für dieses Jahrespaket fehlt die abgenommene native ERiC-Prüfung.')
    if (not isinstance(version_xml, str) or len(version_xml) > 1024 * 1024
            or re.search(r'<!\s*(DOCTYPE|ENTITY)', version_xml, re.I)):
        raise ValueError('ERiC-Versionsnachweis ist ungültig.')
    try:
        root = ET.fromstring(version_xml)
    except ET.ParseError:
        raise ValueError('ERiC-Versionsnachweis ist nicht lesbar.') from None
    ns = '{http://www.elster.de/EricXML/1.0/EricVersion}'
    if root.tag != ns + 'EricVersion':
        raise ValueError('ERiC-Versionsnachweis ist unbekannt.')
    plugin = 'checkbilanz_' + p['taxonomy']['version'].replace('.', '_')
    names = {'ericapi': 'ericapi', plugin: 'checkbilanz'}
    found = {}
    for block in root.findall(ns + 'Bibliothek'):
        name = block.findtext(ns + 'Name', '')
        match = re.fullmatch(r'(?:lib)?([a-z0-9_]+)\.(?:dll|so|dylib)', name)
        if not match or match[1] not in names:
            continue
        key = names[match[1]]
        version = block.findtext(ns + 'Produktversion', '')
        if key in found or not re.fullmatch(r'\d+(?:\s*,\s*\d+){3}', version):
            raise ValueError('ERiC-Versionsnachweis ist nicht eindeutig.')
        found[key] = '.'.join(part.strip() for part in version.split(','))
    if found not in p['eric']['reviewed_versions']:
        raise ValueError('Installierte ERiC- oder Bilanzprüfversion benötigt eine neue Programmprüfung.')
    return found


def xml_package(xml, data_version):
    """Bind a native validation to the actual fiscal end date and schema refs."""
    if not isinstance(xml, str) or re.search(r'<!\s*(DOCTYPE|ENTITY)', xml, re.I):
        raise ValueError('E-Bilanz-XML prüfen.')
    try:
        root = ET.fromstring(xml)
    except ET.ParseError:
        raise ValueError('E-Bilanz-XML ist nicht lesbar.') from None
    ends = [e.text for e in root.iter() if e.tag.rsplit('}', 1)[-1] == 'genInfo.report.period.fiscalYearEnd']
    if not ends or len(set(ends)) != 1 or not re.fullmatch(r'20\d{2}-\d{2}-\d{2}', ends[0] or ''):
        raise ValueError('Eindeutiges E-Bilanz-Abschlussjahr fehlt.')
    candidates = [a for a in ADAPTERS.values() if a['data_version'] == data_version]
    if len(candidates) != 1:
        raise ValueError('E-Bilanz-Datenart benötigt einen implementierten Adapter.')
    p = package(candidates[0]['taxonomy'], int(ends[0][:4]))
    refs = {e.get('{http://www.w3.org/1999/xlink}href') for e in root.iter()
            if e.tag == '{http://www.xbrl.org/2003/linkbase}schemaRef'}
    date = p['taxonomy']['date']
    expected = {f'http://www.xbrl.de/taxonomies/{mod}-{date}/{mod}-{date}-{suffix}.xsd'
                for mod, suffix in [('de-gcd', 'shell'), ('de-gaap-ci', 'shell-fiscal')]}
    if refs != expected:
        raise ValueError('XBRL-Quelle passt nicht zum geprüften E-Bilanz-Jahrespaket.')
    return p


def require_environment(p, environment, *, transport=False):
    """Software preparation and native local checks never authorize production transport."""
    if environment not in ('test', 'production'):
        raise ValueError('Ungültige E-Bilanz-Umgebung.')
    if (p.get('stage') != 'catalog_and_local_validation'
            or p.get('adapter') not in ADAPTERS):
        raise ValueError('Für dieses Jahrespaket fehlt der implementierte E-Bilanz-Adapter.')
    if transport and environment == 'production' and p.get('production_authorized') is not True:
        raise ValueError('Produktive E-Bilanz-Übermittlung für dieses Jahrespaket nicht freigegeben.')
    return p
