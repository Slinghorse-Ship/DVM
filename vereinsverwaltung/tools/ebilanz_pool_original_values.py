"""Single original annual cohort with independently matching HGB/fiscal values.
Unsupported mixed years, mid-year HGB lives or different values remain blocked.
"""
from copy import deepcopy
import re
from ebilanz_pool_original_sources import keys,need,digest
from ebilanz_pool_original_journals import verify as fiscal
from ebilanz_pool_original_book_journals import verify as hgb

TARGET='bs.ass.fixAss.tan.otherEquipm.gwgsammelposten'
EXPENSE='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.lowValueAsCollItem'
METHOD='original_pool_cohort'

CONTRACTS={'original-pool-r73-06750@1':('06750','fixed_assets',TARGET),'original-pool-r73-62640@1':('62640','expense',EXPENSE)}
def validate_rule(rule):
    ref=rule.get('pool_original_contract');need(ref in CONTRACTS)
    code,group,target=CONTRACTS[ref]
    need(rule.get('group')==group and rule.get('condition')=='pool_original' and rule.get('target')==target and rule.get('allowed_targets')==[target] and rule.get('source_years')==[2025,2026] and ('years' not in rule or rule['years']==[2025,2026]))
    need(not any(k in rule for k in ('book_only','commercial_contract','partnership_income_contract','partnership_book_target','partnership_book_disclosures','own_intangible_contract','commercial_income_direction','negative_target','directional_balance')) and not rule.get('disclosures'))
    if 'codes' in rule:need(rule['codes']==[code])


def register(book_proof):
    return {k:v for k,v in book_proof['hgb'].items() if k not in ('disposals_cents','accumulated_disposals_cents','book_disposals_cents')}

def bind(report,sources):
    need(type(report) is dict and 'whole_club_scope' in report and not any(k in report for k in ('period','club_scope')) and report['state']=='closed' and report['issues']==[] and type(sources) is dict)
    items=report['asset_schedule']['items'];need(type(items) is dict and len(sources)==1 and report['asset_schedule']['issues']==[])
    for identifier,pair in sources.items():
        keys(pair,('books','fiscal'));b=pair['books'];t=pair['fiscal'];need(type(b) is dict and type(t) is dict and hgb(b['book_proof'],b['journal'],b['previous'])==b and fiscal(t['pool_proof'],t['journal'],t['previous'])==t and b['book_proof']['pool_proof']==t['pool_proof'])
        j=b['journal'];tj=t['journal'];s=t['pool_proof']['source']
        need(identifier==s['pool_id'] and j['club_id']==tj['club_id']==s['club_id'] and j['pool_id']==tj['pool_id']==identifier and j['year']==tj['year']==report['year'] and j['chart']==tj['chart']==report['accounts'] and j['result_account']==tj['result_account']==report['result_account'] and j['opening']==tj['opening']==report['opening'])
        original=deepcopy(report['entries']);brows=deepcopy(j['entries']);trows=deepcopy(tj['entries'])
        need(all('document' not in row for row in original))
        for rows in (brows,trows):
            for row in rows:row.pop('document',None)
        need(original==brows==trows and {k:v for k,v in b['calculation'].items() if k!='period'}==report['calculation'] and b['calculation']==t['calculation'])
        need(set(items)==set(t['pool_proof']['eligible_items']) and j['pool_account']==tj['pool_account'])
        amounts=register(b['book_proof']);need(report['asset_schedule']['totals']==amounts)
        originals={row['item_id']:row for row in b['book_proof']['book_source']['items']};invoices={row['item_id']:row for row in s['population']}
        for member,item in items.items():
            source=originals[member];invoice=invoices[member]
            need(type(item) is dict and item.get('method','linear_monthly')=='linear_monthly' and not set(item)-set(amounts)-{'item_id','invoice_id','receipt_number','name','account','skr_code','sphere','acquired_on','months','residual_cents','expected_depreciation_cents','acquisition_hash','depreciation_hash','method'})
            need(item['item_id']==member and item['invoice_id']==invoice['invoice_id'] and item['account']==j['pool_account'] and item['skr_code']=='06750' and item['sphere']=='4' and item['acquired_on']==source['acquired_on'] and type(item['months']) is int and item['months']==source['original_life_months'] and type(item['residual_cents']) is int and item['residual_cents']==source['original_residual_cents'])
            need(all(type(item.get(k)) is int and item[k]==source[k] for k in amounts) and type(item['expected_depreciation_cents']) is int and item['expected_depreciation_cents']==source['depreciation_cents'])
            need(all(type(item.get(k)) is str and bool(re.fullmatch('[a-f0-9]{64}',item[k])) for k in ('acquisition_hash','depreciation_hash')))
        need(all(sum(item[k] for item in items.values())==n for k,n in amounts.items()))
        need((report['previous_sha256'] is None)==(b['previous'] is None)==(t['previous'] is None))
        for key,row in report['calculation']['accounts'].items():
            if row['group']=='fixed_assets' and key!=j['pool_account']:need(all(row[f]==0 for f in ('opening_cents','debit_cents','credit_cents','closing_cents')))
    out=dict(schema=1,club_id=s['club_id'],year=report['year'],journal_sha256=report['closed_sha256'],schedule_sha256=digest(report['asset_schedule']),sources=deepcopy(sources),export_authorized=False)
    return out|{'sha256':digest(out)}

def verify_values(values):
    active=any(row.get('skr_code') in ('06750','62640') and any(row.get(k,0) for k in ('opening_cents','debit_cents','credit_cents')) for row in values['book']['accounts'].values())
    envelope=values.get('pool_original_sources')
    if envelope is None:need(not active);return None
    keys(envelope,('schema','club_id','year','journal_sha256','schedule_sha256','sources','export_authorized','sha256'))
    need(type(envelope['schema']) is int and envelope['schema']==1 and envelope['export_authorized'] is False and digest({k:v for k,v in envelope.items() if k!='sha256'})==envelope['sha256'])
    need(values['opening_adjustments']==values['current_adjustments']==[] and values['book']==values['tax'] and not any(k in values for k in ('period','club_scope','asset_tax','partnership_holdings','own_intangible_sources','own_hgb_sources','annual_section')))
    report=deepcopy(values['whole_club_scope']['source']);report['whole_club_scope']=deepcopy(values['whole_club_scope']);need(bind(report,envelope['sources'])==envelope and values['year']==envelope['year'] and values['journal_sha256']==envelope['journal_sha256'] and values['asset_schedule']==report['asset_schedule'] and values['book']==report['calculation'])
    return envelope

def mapping(values):
    p=verify_values(values)
    if p is None:return {}
    out={}
    for pair in p['sources'].values():
        j=pair['fiscal']['journal'];out[j['pool_account']]=TARGET;out[j['depreciation_account']]=EXPENSE
    return out

def prepare(values,targets,profile,meta):
    envelope=verify_values(values);need(envelope is not None and profile.get('kind')=='whole_taxable_club' and profile.get('asset_scope')=='whole_club' and profile.get('asset_values_unchanged') is True)
    need(all(targets.get(k)==v for k,v in mapping(values).items()))
    from ebilanz_assets import MEASURES
    amounts=values['asset_schedule']['totals'];eq=meta['asset_calculations'];columns=set(eq)|{child for children in eq.values() for child in children}|{'all_Prev_period'};grid=dict.fromkeys(columns,0);grid.update({MEASURES[k]:v for k,v in amounts.items()});grid['accDepr.DeprPeriod']=amounts['depreciation_cents']
    need(all(grid[parent]==sum(grid[child]*weight for child,weight in children.items()) for parent,children in eq.items()) and grid['grossCost.beginning']-grid['accDepr.beginning']==grid['all_Prev_period'])
    ancestors=set();pending=list(meta['asset_parents'].get(TARGET,[]))
    while pending:
        parent=pending.pop();need(parent!=TARGET)
        if parent in ancestors:continue
        ancestors.add(parent);pending.extend(meta['asset_parents'].get(parent,[]))
    need('bs.ass.fixAss' in ancestors)
    rows={target:deepcopy(grid) for target in {TARGET}|ancestors}
    return dict(rows=rows,items=len(values['asset_schedule']['items']),totals=deepcopy(amounts),sha256=digest(dict(original_pool_sources_sha256=envelope['sha256'],rows=rows)))
