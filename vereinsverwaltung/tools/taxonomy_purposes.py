"""Source-equivalent purpose projections across published taxonomies.

Historic definitions remain byte-for-byte unchanged. A projection reuses the
existing rule only when its referenced concepts, hierarchy and calculation
weights are unchanged. This is catalog preparation, not annual tax acceptance.
Changed facts are withheld for substantive review; no labels or digits infer them.
"""
import hashlib
import json
from functools import lru_cache
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parent.parent


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True,
                                    separators=(',', ':')).encode()).hexdigest()


def dependencies(rule, meta):
    targets = sorted(set([rule['target'], *rule.get('allowed_targets', []),
                          *rule.get('disclosures', []), *rule.get('own_source_disclosures', []), *rule.get('financial_parent_disclosures', []), *rule.get('own_share_disclosures', []), *([rule['partnership_book_target']] if 'partnership_book_target' in rule else []), *rule.get('partnership_book_disclosures', []), *rule.get('financial_book_targets', {}).values()]))
    concepts = meta['concepts']['de-gaap-ci']
    result = {target: {'concept': concepts.get(target),
        'parents': sorted(meta['parents'].get(target, [])),
        'children': meta['calculations'].get(target, {}),
        'incoming_weights': {parent: children[target] for parent, children in meta['calculations'].items()
                             if target in children},
        'asset_parents': sorted(meta['asset_parents'].get(target, [])),
        'asset_children': meta['asset_calculations'].get(target, {}),
        'asset_incoming_weights': {parent: children[target] for parent, children in meta['asset_calculations'].items()
                                  if target in children}}
        for target in targets}
    if rule.get('income_use_contract'):
        from ebilanz_income_use import network
        graph=network()['versions'][meta['version']]
        for target in targets:
            pending=[target];seen=set()
            while pending:
                node=pending.pop()
                if node in seen:continue
                seen.add(node);pending.extend(graph['parents'].get(node,[]))
            result[target]['income_use_network']={node:{'parents':graph['parents'].get(node,[]),'children':graph['calculations'].get(node,{})} for node in sorted(seen)}
    return result


def compile_projection(model, old, new):
    eligible, blocked = {}, {}
    for purpose, entry in model['purposes'].items():
        for version, definition in entry['versions'].items():
            ref = purpose + '@' + version
            rule = definition['mappings'].get(old['version'])
            if rule is None:
                continue
            before, after = dependencies(rule, old), dependencies(rule, new)
            changed = [target for target in before if before[target] != after[target]
                       or before[target]['concept'] is None or after[target]['concept'] is None]
            if changed:
                blocked[ref] = {'changed_targets': changed}
            else:
                eligible[ref] = {'definition_sha256': digest(definition), 'rule_sha256': digest(rule),
                                 'dependencies_sha256': digest(before)}
    return {'schema': 1, 'from_taxonomy': old['version'], 'taxonomy': new['version'],
            'kind': 'source_equivalent_catalog_projection', 'eligible': eligible, 'blocked': blocked,
            'production_authorized': False}


@lru_cache(maxsize=4)
def checked(raw, old_raw, new_raw):
    review, old, new = json.loads(raw), json.loads(old_raw), json.loads(new_raw)
    payload = {k: v for k, v in review.items() if k != 'sha256'}
    if (review.get('schema') != 1 or review.get('sha256') != digest(payload)
        or review.get('kind') != 'source_equivalent_catalog_projection'
        or review.get('production_authorized') is not False
        or review.get('from_taxonomy') != old.get('version') or review.get('taxonomy') != new.get('version')
        or not isinstance(review.get('eligible'), dict) or not isinstance(review.get('blocked'), dict)):
        raise ValueError('Taxonomieüberleitung wurde verändert. Quellen erneut prüfen.')
    return review, old, new, {}


def load(model, taxonomy):
    registration = model.get('taxonomy_projections', {}).get(taxonomy)
    if registration is None:
        return None
    if (not isinstance(taxonomy, str) or not re.fullmatch(r'\d+\.\d+', taxonomy)
        or registration.get('file') != f'reviews/taxonomy-{taxonomy}.json'):
        raise ValueError('Geprüfte Taxonomieüberleitung fehlt.')
    def read(path):
        return path.read_bytes().replace(b'\r\n', b'\n')
    raw = read(ROOT / 'resources/accounting' / registration['file'])
    if hashlib.sha256(raw).hexdigest() != registration['sha256']:
        raise ValueError('Taxonomieüberleitung und Zweckregister stimmen nicht überein.')
    source = registration.get('from_taxonomy')
    if not isinstance(source, str) or not re.fullmatch(r'\d+\.\d+', source):
        raise ValueError('Ausgangstaxonomie der Überleitung prüfen.')
    old_raw = read(ROOT / f'resources/ebilanz/taxonomy-{source}.json')
    new_raw = read(ROOT / f'resources/ebilanz/taxonomy-{taxonomy}.json')
    for version, data in [(source, old_raw), (taxonomy, new_raw)]:
        if hashlib.sha256(data).hexdigest() != model['taxonomies'][version]['sha256']:
            raise ValueError('Amtliche Taxonomiequelle der Überleitung wurde verändert.')
    projection = checked(raw, old_raw, new_raw)
    review = projection[0]
    if review['from_taxonomy'] != source:
        raise ValueError('Ausgangstaxonomie und Überleitung stimmen nicht überein.')
    return projection


def projected_rule(definition, ref, projection):
    if projection is None:
        return None
    review, old, new, cache = projection
    proof = review['eligible'].get(ref)
    if proof is None:
        return None
    rule = definition['mappings'].get(review['from_taxonomy'])
    key = (ref, digest(definition))
    if key in cache:
        return cache[key]
    before = dependencies(rule, old) if rule else None
    after = dependencies(rule, new) if rule else None
    if (before is None or before != after or any(v['concept'] is None for v in before.values())
        or proof != {'definition_sha256': digest(definition), 'rule_sha256': digest(rule),
                     'dependencies_sha256': digest(before)}):
        raise ValueError('Kontenzweck und geprüfte Taxonomieüberleitung widersprechen sich: ' + ref)
    cache[key] = rule
    return rule
