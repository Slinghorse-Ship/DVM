"""Shared asset capabilities; numbers are derived from reviewed annual meanings.

Never infer a useful life, movable-property proof, or tax election from a label.
The exporter separately pins the exact-year source catalogue and registry.
"""
import json
import hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
DATA=json.loads((ROOT/'resources/ebilanz/asset-types.json').read_text(encoding='utf8'))
if DATA.get('schema')!=1:raise ValueError('Anlagenarten prüfen.')
TYPES={}
for definition in DATA['types'].values():
    for target in definition['targets']:
        if target in TYPES or definition['kind'] not in ('software','intangible','movable','gwg','land','financial','construction','intangible_advance','building','goodwill'):raise ValueError('Widersprüchliche Anlagenarten.')
        TYPES[target]={'kind':definition['kind'],'code':definition['depreciation_code'],'target':target,'expense':definition['depreciation_target']}
ACCOUNTS={};TARGET_CHOICES={}
for rule in json.loads((ROOT/'resources/ebilanz/skr42-6.9.json').read_text(encoding='utf8'))['rules']:
    if rule['group']!='fixed_assets' or rule.get('condition') not in (None,'detail_choice','cost_schedule'):continue
    choices=tuple(rule.get('allowed_targets',[rule['target']]))
    if not choices or any(t not in TYPES for t in choices):continue
    source_types=TYPES
    if 'asset_type' in rule:
        source=DATA.get('source_types',{}).get(rule['asset_type'])
        if (rule['asset_type']!='immovable_fixture' or type(source) is not dict
            or set(source)!={'kind','depreciation_code','depreciation_target','targets'} or source['kind']!='immovable_fixture'
            or source['depreciation_code']!='62200' or source['depreciation_target']!='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'
            or source['targets']!=['bs.ass.fixAss.tan.landBuildings.buildingsOnOwnLand.buildings','bs.ass.fixAss.tan.landBuildings.buildingsOnNonOwnedLand']
            or any(t not in source['targets'] for t in choices)):raise ValueError('Quellgebundene unbewegliche Anlage vollständig prüfen.')
        source_types=TYPES|{t:{'kind':source['kind'],'code':source['depreciation_code'],'target':t,'expense':source['depreciation_target']} for t in source['targets']}
    definitions=[source_types[t] for t in choices];definition=dict(source_types[rule['target']])
    if len({(d['code'],d['expense']) for d in definitions})!=1:raise ValueError('Sammelkonto benötigt eine einheitliche Abschreibungsart.')
    kinds={d['kind'] for d in definitions}
    if len(kinds)>1:
        if kinds!={'software','intangible'}:raise ValueError('Sammelkonto verbindet unterschiedliche Anlagenarten.')
        definition['kind']='intangible'
    for code in rule['codes']:
        if code in ACCOUNTS and (ACCOUNTS[code]!=definition or TARGET_CHOICES[code]!=choices):raise ValueError('Anlagenart hat zwischen Jahresständen gewechselt.')
        ACCOUNTS[code]=definition;TARGET_CHOICES[code]=choices
REVIEW=json.loads((ROOT/'resources/accounting/reviews/cost-assets.json').read_text(encoding='utf8'))
if REVIEW.get('schema')!=1 or REVIEW.get('scope')!='book_cost_asset_lifecycle' or REVIEW.get('production_authorized') is not False:
    raise ValueError('Quellprüfung der Kostenanlagen wurde verändert.')
for source in REVIEW['years']:
    catalog=(ROOT/f"data/skr42-{source['source']['year']}.json").read_bytes().replace(b'\r\n',b'\n')
    if hashlib.sha256(catalog).hexdigest()!=source['source']['catalog_sha256']:raise ValueError('Originaler Jahreskontenplan wurde verändert.')
    for asset in source['assets']:
        code=asset['code'];definition=TYPES[asset['target']]
        if definition['kind'] not in ('land','financial') or definition['code'] is not None or code in ACCOUNTS and ACCOUNTS[code]!=definition:
            raise ValueError('Quellzuordnung der Kostenanlage ist widersprüchlich.')
        ACCOUNTS[code]=definition
for rule in json.loads((ROOT/'resources/ebilanz/skr42-6.9.json').read_text(encoding='utf8'))['rules']:
    if 'substance_original_contract' in rule:
        if rule['substance_original_contract']!='02250@1' or rule['codes']!=['02250'] or rule['group']!='fixed_assets' or rule['target']!='bs.ass.fixAss.tan.landBuildings.landWithoutBuildings' or rule.get('allowed_targets')!=[rule['target']] or rule.get('condition')!='detail_choice' or 'book_only' in rule or 'commercial_contract' in rule:raise ValueError('Substanzkonto und Originalregel prüfen.')
        ACCOUNTS['02250']={'kind':'substance','code':'62200','target':rule['target'],'expense':'is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'};TARGET_CHOICES['02250']=(rule['target'],)
MOVABLE=frozenset(c for c,t in ACCOUNTS.items() if t['kind']=='movable')
SOFTWARE=frozenset(c for c,t in ACCOUNTS.items() if t['kind']=='software')
GWG_CODES=frozenset(c for c,t in ACCOUNTS.items() if t['kind']=='gwg')
INTANGIBLE=frozenset(c for c,t in ACCOUNTS.items() if t['kind']=='intangible')
AMORTIZABLE_INTANGIBLE=SOFTWARE|INTANGIBLE
BUILDING=frozenset(c for c,t in ACCOUNTS.items() if t['kind']=='building')
GOODWILL=frozenset(c for c,t in ACCOUNTS.items() if t['kind']=='goodwill')
IMMOVABLE_FIXTURES=frozenset(c for c,t in ACCOUNTS.items() if t['kind']=='immovable_fixture')
ORDINARY_EQUIPMENT=MOVABLE|IMMOVABLE_FIXTURES
LINEAR=ORDINARY_EQUIPMENT|AMORTIZABLE_INTANGIBLE|BUILDING|GOODWILL
DISPOSABLE=MOVABLE|GWG_CODES
DEP={c:t['code'] for c,t in ACCOUNTS.items() if t['code'] is not None}
COST=frozenset(c for c,t in ACCOUNTS.items() if t['kind'] in ('land','financial','construction','intangible_advance'))

def accepts_target(code,target):
    return target in TARGET_CHOICES.get(code,(ACCOUNTS.get(code,{}).get("target"),))

def cost_target(plan):
    from ebilanz_financial_profile import check
    check(plan)
    code=plan.get('account');definition=ACCOUNTS.get(code,{})
    choices=TARGET_CHOICES.get(code,(definition.get('target'),))
    target=plan.get('target',choices[0] if len(choices)==1 else None)
    if definition.get('kind') not in ('land','financial','construction','intangible_advance') or definition['kind']!=plan.get('kind',plan.get('valuation_kind')) or target not in choices:
        raise ValueError('Anlagen im Bau: ursprüngliche Anlagenart und genaue Bilanzposition im Anschaffungsplan bestätigen.')
    return target
