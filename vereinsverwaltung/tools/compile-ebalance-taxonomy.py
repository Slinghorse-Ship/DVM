"""Compile published core-taxonomy metadata, never ERiC example tax values.

Input: official XBRL package extracted from https://www.esteuer.de/.
Output is derived schema metadata, not a DATEV account-to-taxonomy mapping.
"""
import argparse
import hashlib
import json
from pathlib import Path
import xml.etree.ElementTree as ET

LINK = '{http://www.xbrl.org/2003/linkbase}'
XL = '{http://www.w3.org/1999/xlink}'
XS = '{http://www.w3.org/2001/XMLSchema}'
XI = '{http://www.xbrl.org/2003/instance}'
SOURCE = 'https://www.esteuer.de/download/taxonomie_20250618/german-gaap-taxonomy-v6.9-2025-04-01-xbrl.zip'
SOURCES = {
    '6.7': ('2023-04-01', 'https://www.esteuer.de/download/taxonomie_20230619/german-gaap-taxonomy-v6.7-2023-04-01.zip'),
    '6.8': ('2024-04-01', 'https://www.esteuer.de/download/taxonomie_20240610/german-gaap-taxonomy-v6.8-2024-04-01-xbrl.zip'),
    '6.9': ('2025-04-01', SOURCE),
    '6.10': ('2026-04-01', 'https://www.esteuer.de/download/taxonomie_20260615/germany-gaap-taxonomy-v6.10-2026-04-01-xbrl.zip'),
}


def compile_taxonomy(root, version='6.9'):
    if version not in SOURCES:
        raise ValueError('Published taxonomy source needs an explicit compiler review.')
    date, source = SOURCES[version]
    source_files = {}
    def read(relative):
        data = (root / relative).read_bytes()
        source_files[relative] = hashlib.sha256(data).hexdigest()
        return ET.fromstring(data)

    result = {'version': version, 'date': date, 'source': source,
              'copyright': 'Taxonomy metadata: XBRL Deutschland e.V.; see http://www.xbrl.de',
              'concepts': {}, 'parents': {}, 'calculations': {},
              'asset_parents': {}, 'asset_calculations': {}}
    for module in ['de-gcd', 'de-gaap-ci']:
        stem = module + '-' + date
        concepts = {}
        ids = {}
        schemas = [read(f'{stem}/{stem}.xsd')]
        if module == 'de-gaap-ci':
            schemas.append(read(f'{stem}/{stem}-dimensions.xsd'))
        for element in [e for schema in schemas for e in schema.findall(XS + 'element')]:
            name = element.attrib['name']
            ids[element.attrib['id']] = name
            concepts[name] = {'type': element.get('type'), 'period': element.get(XI + 'periodType'),
                              'abstract': element.get('abstract') == 'true', 'refs': {}}
        for suffix in ['reference', 'reference-fiscal', 'dimensions-reference-fiscal']:
            path = f'{stem}/{stem}-{suffix}.xml'
            if not (root / path).is_file():
                continue
            link = read(path)
            for block in link.findall(LINK + 'referenceLink'):
                locs = {x.get(XL+'label'): ids.get(x.get(XL+'href').split('#')[-1]) for x in block.findall(LINK+'loc')}
                refs = {x.get(XL+'label'): x for x in block.findall(LINK+'reference')}
                for arc in block.findall(LINK+'referenceArc'):
                    name = locs.get(arc.get(XL+'from'))
                    if not name:
                        continue
                    for part in refs[arc.get(XL+'to')]:
                        key = part.tag.split('}')[-1]
                        concepts[name]['refs'].setdefault(key, [])
                        if part.text not in concepts[name]['refs'][key]:
                            concepts[name]['refs'][key].append(part.text)
        result['concepts'][module] = concepts
        if module != 'de-gaap-ci':
            continue
        for section in ['balanceSheet', 'incomeStatement']:
            for kind in ['presentation', 'calculation']:
                link = read(f'{stem}/{stem}-{kind}-{section}.xml')
                for block in link.findall(LINK+kind+'Link'):
                    locs = {x.get(XL+'label'): ids.get(x.get(XL+'href').split('#')[-1]) for x in block.findall(LINK+'loc')}
                    for arc in block.findall(LINK+kind+'Arc'):
                        parent, child = locs.get(arc.get(XL+'from')), locs.get(arc.get(XL+'to'))
                        if not parent or not child or arc.get('use') == 'prohibited':
                            continue
                        if kind == 'presentation':
                            result['parents'].setdefault(child, [])
                            if parent not in result['parents'][child]:
                                result['parents'][child].append(parent)
                        else:
                            weight = int(arc.get('weight'))
                            existing = result['calculations'].setdefault(parent, {}).get(child)
                            if existing is not None and existing != weight:
                                raise ValueError('Conflicting calculation role: ' + child)
                            result['calculations'][parent][child] = weight
        # The fixed-asset table uses concepts from dimensions.xsd and a separate
        # row hierarchy. Do not mix its movement calculations into the main BS.
        for kind, suffix in [('definition','-gen'), ('calculation','')]:
            # The published 6.10 package renamed definition linkbase files.
            # Select the documented source explicitly, never fall back silently.
            component = 'dim-def' if version == '6.10' and kind == 'definition' else 'dimensions-' + kind
            link = read(f'{stem}/{stem}-{component}-notesTableStmOfChgInFAssGross{suffix}.xml')
            for block in link.findall(LINK+kind+'Link'):
                locs = {x.get(XL+'label'): ids.get(x.get(XL+'href').split('#')[-1]) for x in block.findall(LINK+'loc')}
                for arc in block.findall(LINK+kind+'Arc'):
                    parent, child = locs.get(arc.get(XL+'from')), locs.get(arc.get(XL+'to'))
                    if not parent or not child or arc.get('use') == 'prohibited':
                        continue
                    if kind == 'definition' and arc.get(XL+'arcrole').endswith('/domain-member'):
                        result['asset_parents'].setdefault(child, []).append(parent)
                    elif kind == 'calculation':
                        result['asset_calculations'].setdefault(parent, {})[child] = int(arc.get('weight'))
    result['source_files_sha256'] = source_files
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('taxonomy_directory', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('--version', choices=SOURCES, default='6.9')
    args = parser.parse_args()
    result = compile_taxonomy(args.taxonomy_directory, args.version)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True)+'\n', encoding='utf-8')
    print('Compiled schema metadata:', sum(len(v) for v in result['concepts'].values()))
