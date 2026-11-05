"""Original receivable contra accounts and balanced calculative cost pairs.

Only four source-reviewed trade allowance families permit credit asset balances.
The original, separately classified maturity pool and the selected debtor fact
both remain nonnegative. Calculative journal pairs cancel within each sphere.
"""
from functools import lru_cache
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
LIMIT=100000000000
BASE='bs.ass.currAss.receiv.trade'
ALLOWANCES={'12460':'upTo1year','12470':'above1year','12480':'upTo1year','12490':'above1year','12760':'upTo1year','12770':'above1year','12960':'upTo1year','12970':'above1year'}
FAMILIES={'12760':'affil','12770':'affil','12960':'particip','12970':'particip'}
def base_for(code):return 'bs.ass.currAss.receiv.'+FAMILIES[code] if code in FAMILIES else BASE
def targets_for(family):return ['bs.ass.currAss.receiv.'+family] if family in ('affil','particip') else [BASE+'.other',BASE+'.shareholders',BASE+'.misc']
PAIRS=(('69700','69800'),('69720','69820'),('69740','69840'),('69760','69860'),('69780','69880'),('69790','69890'),('69900','69920','69940','69990'))
CALC={c:str(i) for i,pair in enumerate(PAIRS) for c in pair}
COST='is.netIncome.regular.operatingTC.otherCost.otherOrdinary'

def require(ok,message='Kontenabschluss: ursprüngliche Forderungen, Restlaufzeit oder kalkulatorische Gegenbuchungen stimmen nicht überein.'):
    if not ok:raise ValueError(message)

def validate_rule(rule):
    maturity=rule.get('contra_receivable_maturity')
    family=rule.get('contra_receivable_family','trade');base='bs.ass.currAss.receiv.'+family
    require(family in ('trade','affil','particip'))
    pair=rule.get('calculation_only_pair')
    if maturity is not None:
        require(maturity in ('upTo1year','above1year') and rule['group']=='receivables'
                and rule.get('condition')=='detail_choice'
                and rule.get('allowed_targets')==targets_for(family)
                and rule.get('disclosures')==[base+'.'+maturity] and pair is None)
    if pair is not None:
        require(pair in CALC.values() and rule['group']=='expense' and rule.get('condition')=='detail_choice'
                and rule['target']==COST and rule.get('allowed_targets')==[COST] and maturity is None)

@lru_cache(maxsize=4)
def verified(year,registry,catalog):
    from ebilanz_mapping import checked_source
    _,rules,_=checked_source(year,registry,catalog)
    for code,rule in rules.items():
        validate_rule(rule)
        if 'contra_receivable_maturity' in rule:require(ALLOWANCES.get(code)==rule['contra_receivable_maturity'] and rule.get('contra_receivable_family','trade')==FAMILIES.get(code,'trade'))
        if 'calculation_only_pair' in rule:require(CALC.get(code)==rule['calculation_only_pair'])
    return rules

def rules(year):
    return verified(year,(ROOT/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n'),
                    (ROOT/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n'))

def is_allowance(year,row):
    code=row.get('skr_code')
    if code not in ALLOWANCES or year not in (2025,2026) or row.get('group')!='receivables':return False
    rule=rules(year).get(code,{})
    return rule.get('contra_receivable_maturity')==ALLOWANCES[code] and rule.get('group')==row['group']

def receivables(year,rows):
    selected={k:a for k,a in rows.items() if a.get('skr_code') in ALLOWANCES}
    if not selected or year not in (2025,2026):return set()
    source=rules(year);totals={(family,m):dict.fromkeys(('opening_cents','closing_cents'),0) for family in ('trade','affil','particip') for m in ('upTo1year','above1year')}
    for row in rows.values():
        rule=source.get(row.get('skr_code'),{})
        if row['group']!='receivables' or rule.get('group')!='receivables':continue
        targets=rule.get('allowed_targets',[rule.get('target','')])
        for (family,m),fields in totals.items():
            base='bs.ass.currAss.receiv.'+family
            if not targets or any(t!=base and not t.startswith(base+'.') for t in targets):continue
            if base+'.'+m not in rule.get('disclosures',[]):continue
            for f in fields:fields[f]+=row[f]
    for key,row in selected.items():
        require(is_allowance(year,row))
        for f in ('opening_cents','closing_cents'):require(type(row[f]) is int and -LIMIT<=row[f]<=0)
    for fields in totals.values():
        require(all(0<=v<=LIMIT for v in fields.values()),'Wertberichtigung überschreitet die ursprünglichen Forderungen derselben Restlaufzeit.')
    return set(selected)

def calculative_rows(year,rows):
    selected={k:a for k,a in rows.items() if a.get('skr_code') in CALC}
    if not selected:return set()
    source=rules(year);totals={str(i):0 for i in range(len(PAIRS))}
    for key,row in selected.items():
        pair=CALC[row['skr_code']];rule=source.get(row['skr_code'],{})
        require(row['group']=='expense' and rule.get('group')=='expense' and rule.get('calculation_only_pair')==pair)
        require(row['opening_cents']==0)
        totals[pair]+=row['amount_cents'];require(abs(totals[pair])<=LIMIT)
    require(not any(totals.values()),'Kalkulatorische Kosten und ihre Gegenbuchungen müssen vollständig ausgeglichen sein.')
    return set(selected)

def calculative_journal(year,rows,entries):
    selected=calculative_rows(year,rows)
    if not selected:return
    totals={str(i):dict.fromkeys(('1','2','3','4'),0) for i in range(len(PAIRS))}
    for entry in entries:
        for line in entry['lines']:
            if line['account'] not in selected:continue
            pair=CALC[rows[line['account']]['skr_code']];s=line['sphere'];require(s in totals[pair])
            totals[pair][s]+=line['debit_cents']-line['credit_cents'];require(abs(totals[pair][s])<=LIMIT)
    require(not any(v for t in totals.values() for v in t.values()),'Kalkulatorische Gegenbuchungen müssen in derselben Sphäre ausgeglichen sein.')

def check_values(values,mapping,profile):
    allowed=receivables(values['year'],values['tax']['accounts'])
    receivables(values['year'],values['book']['accounts'])
    if allowed:
        source=rules(values['year'])
        for section in ('book','tax'):
            pools={}
            for key,row in values[section]['accounts'].items():
                rule=source.get(row.get('skr_code'),{});target=mapping.get(key)
                if row['group']!='receivables' or rule.get('group')!='receivables' or not target:continue
                for maturity in ('upTo1year','above1year'):
                    if not any(base+'.'+maturity in rule.get('disclosures',[]) for base in ('bs.ass.currAss.receiv.trade','bs.ass.currAss.receiv.affil','bs.ass.currAss.receiv.particip')):continue
                    for field in ('opening_cents','closing_cents'):
                        ident=(target,maturity,field);pools[ident]=pools.get(ident,0)+row[field]
            for key in allowed:
                if key not in mapping:continue # The ordinary missing-mapping guard still applies.
                maturity=ALLOWANCES[values[section]['accounts'][key]['skr_code']]
                require(all(pools.get((mapping[key],maturity,f),0)>=0 for f in ('opening_cents','closing_cents')),
                        'Wertberichtigung überschreitet die ursprüngliche Schuldnergruppe derselben Restlaufzeit.')
    for section in ('book','tax'):
        selected=calculative_rows(values['year'],values[section]['accounts'])
        for key in selected:
            if any(values[section]['accounts'][key][f] for f in ('opening_cents','debit_cents','credit_cents')):
                require(mapping.get(key)==COST,'Kalkulatorische Konten müssen gemeinsam auf ihre geprüfte Gegenbuchungsposition zugeordnet werden.')
    if selected:
        if profile.get('kind')=='whole_taxable_club':
            entries=values['whole_club_scope']['source']['entries']
            calculative_journal(values['year'],values['book']['accounts'],entries)
            calculative_journal(values['year'],values['tax']['accounts'],entries+values['current_adjustments'])
        else:
            require(set(values['source_spheres'])<=set(('4',)),'Kalkulatorische Gegenbuchungen benötigen die ursprüngliche Aufteilung je Sphäre.')
    return allowed
