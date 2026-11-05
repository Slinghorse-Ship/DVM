"""Taxonomy BAL columns from replayed cost valuations; does not authorize an export.

The published all = gross - accDepr + revaluation formula presents current
writeups separately. Its accDepr column is therefore before those writeups;
the retained book/tax accumulated value in our ledger is after writeups.
Currency changes, transfers and unexplained differences are never fabricated.
"""
import json
from pathlib import Path
from ebilanz_asset_valuation import calculate
from ebilanz_asset_types import ACCOUNTS,COST
from ebilanz_period import require

ROOT=Path(__file__).resolve().parent.parent
VERSIONS={'6.7','6.8','6.9','6.10'}

def project(valuation,side='tax',version='6.9'):
    require(side in ('book','tax') and version in VERSIONS,'Kostenbewertung: Wertseite und amtliche Taxonomiequelle prüfen.')
    source=valuation.get('source');require(type(source) is dict)
    require(calculate(source['plan'],source['period'],source['events'],source['previous'])==valuation,
            'Kostenbewertung: Ursprünglichen unveränderten Bewertungsverlauf nachweisen.')
    code=source['plan']['account'];definition=ACCOUNTS.get(code,{})
    require(code in COST and definition.get('kind')==source['plan']['kind'],'Geprüfte ursprüngliche Kostenanlagenart erforderlich.')
    meta=json.loads((ROOT/f'resources/ebilanz/taxonomy-{version}.json').read_text(encoding='utf8'))
    require(meta['version']==version)
    equations=meta['asset_calculations']
    require(equations['all']=={'accDepr':-1,'gross':1,'revaluation':1}
            and equations['accDepr'].get('accDepr.DeprPeriod')==1 and equations['accDepr'].get('accDepr.release')==-1
            and equations['accDepr.DeprPeriod'].get('accDepr.DeprPeriod.extraordinary')==1,
            'Neue amtliche Bewegungsgleichung erfordert einen geprüften Adapter.')
    columns=sorted(set(equations)|{c for children in equations.values() for c in children}|{'all_Prev_period'})
    values=dict.fromkeys(columns,0);v=valuation[side]
    values.update({'grossCost.beginning':v['cost_opening_cents'],'gross.addition':v['additions_cents'],
        'gross.release':v['disposals_cents'],'gross':v['cost_closing_cents'],
        'accDepr.beginning':v['accumulated_opening_cents'],'accDepr.DeprPeriod.extraordinary':v['impairment_cents'],
        'accDepr.DeprPeriod':v['impairment_cents'],'accDepr.release':v['accumulated_disposals_cents'],
        'accDepr':v['accumulated_closing_cents']+v['writeup_cents'],'revaluation':v['writeup_cents'],
        'all_Prev_period':v['book_opening_cents'],'all':v['book_closing_cents']})
    require(values['grossCost.beginning']-values['accDepr.beginning']==values['all_Prev_period'])
    require(all(type(n) is int and 0<=n<=100000000000 for n in values.values()))
    require(all(values[parent]==sum(values[c]*weight for c,weight in children.items()) for parent,children in equations.items()),
            'Kostenbewertung: Amtliche Anlagenspiegelgleichung ist nicht erfüllt.')
    return {'taxonomy':version,'target':definition['target'],'side':side,'valuation_sha256':valuation['sha256'],
        'columns':values,'export_authorized':False}
