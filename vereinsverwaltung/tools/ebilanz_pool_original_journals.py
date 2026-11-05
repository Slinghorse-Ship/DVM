"""Rebuild original fiscal pool journal; never generates or authorizes postings."""
from copy import deepcopy
from ebilanz_pool_original_sources import calculate,keys,need,document,note,digest
from ebilanz_club_scope import calculate as journal_calculate

def net(opening,chart):
    out=dict.fromkeys(chart,0)
    for row in opening:out[row['account']]+=row['debit_cents']-row['credit_cents']
    return out

def verify(proof,journal,previous=None,_depth=0):
    need(type(_depth) is int and 0<=_depth<2)
    keys(proof,('schema','source','register','previous','fiscal','eligible_items','excluded_items','departed_items','journal_verified','export_authorized','sha256'))
    need(calculate(proof['source'],proof['register'],proof['previous'])==proof)
    keys(journal,('schema','club_id','pool_id','year','period','chart','result_account','pool_account','depreciation_account','opening','entries','acquisition_links','depreciation_vouchers','document','reason','confirmed'))
    need(type(journal['schema']) is int and journal['schema']==1 and journal['confirmed'] is True)
    year=journal['year'];s=proof['source'];f=proof['fiscal']
    need(type(year) is int and year in (2025,2026) and year==proof['register']['year'] and journal['club_id']==s['club_id'] and journal['pool_id']==s['pool_id'] and journal['period']=={'start':f'{year}-01-01','end':f'{year}-12-31'})
    document(journal['document']);note(journal['reason']);chart=journal['chart'];asset=journal['pool_account'];dep=journal['depreciation_account'];capital=journal['result_account']
    need(type(chart) is dict and type(asset) is str and type(dep) is str and type(capital) is str and chart.get(asset,{}).get('group')=='fixed_assets' and chart[asset].get('skr_code')=='06750' and chart.get(dep,{}).get('group')=='expense' and chart[dep].get('skr_code')=='62640' and chart.get(capital,{}).get('group')=='equity' and chart[capital].get('skr_code') not in ('29000','29080'))
    if previous is None:need(proof['previous'] is None)
    else:
        keys(previous,('schema','pool_proof','journal','previous','calculation','journal_verified','export_authorized','sha256'))
        need(verify(previous['pool_proof'],previous['journal'],previous['previous'],_depth+1)==previous and proof['previous']==previous['pool_proof'])
        old=previous['journal'];need(old['year']+1==year and all(chart.get(key)==value for key,value in old['chart'].items()) and all(journal[key]==old[key] for key in ('club_id','pool_id','pool_account','depreciation_account','result_account')))
        calc=previous['calculation'];want={key:row['closing_cents'] for key,row in calc['accounts'].items() if row['group'] not in ('expense','revenue','result_use')};want[capital]-=calc['annual_result_cents']+sum(row['amount_cents'] for row in calc['accounts'].values() if row['group']=='result_use')
        need(net(journal['opening'],chart)=={key:want.get(key,0) for key in chart})
    calc=journal_calculate(year,chart,journal['opening'],journal['entries'],journal['period']);need(not calc['issues'])
    a=calc['accounts'][asset];d=calc['accounts'][dep];need(a['opening_cents']==f['book_opening_cents'] and a['closing_cents']==f['book_closing_cents'] and a['debit_cents']==f['additions_cents'] and a['credit_cents']==f['depreciation_cents'] and d['opening_cents']==0 and d['debit_cents']==f['depreciation_cents'] and d['credit_cents']==0)
    entries={row['id']:row for row in journal['entries']};need(len(entries)==len(journal['entries']))
    links=journal['acquisition_links'];need(type(links) is list and len(links)==(len(proof['eligible_items']) if year==s['formed_year'] else 0));seen=set()
    for link in links:
        keys(link,('item_id','voucher_id'));need(link['item_id'] in proof['eligible_items'] and link['item_id'] not in seen and link['voucher_id'] in entries);seen.add(link['item_id'])
        item=next(row for row in s['population'] if row['item_id']==link['item_id']);entry=entries[link['voucher_id']]
        need(entry['date']==item['acquired_on'] and entry.get('document')==item['document'] and len(entry['lines'])==2)
        own=[row for row in entry['lines'] if row['account']==asset];other=[row for row in entry['lines'] if row['account']!=asset]
        need(len(own)==len(other)==1 and own[0]['debit_cents']==item['basis_cents'] and own[0]['credit_cents']==0 and other[0]['debit_cents']==0 and other[0]['credit_cents']==item['basis_cents'] and chart[other[0]['account']]['group'] in ('cash','liabilities'))
    need(seen==set(proof['eligible_items']) if year==s['formed_year'] else not seen)
    vouchers=journal['depreciation_vouchers'];need(type(vouchers) is list and 1<=len(vouchers)<=len(proof['eligible_items']) and len(set(vouchers))==len(vouchers))
    expected_vouchers={row['voucher_id'] for row in links}
    for voucher in vouchers:
        need(type(voucher) is str and voucher in entries and voucher not in expected_vouchers);entry=entries[voucher]
        need(entry['date']==f'{year}-12-31' and entry.get('document')==proof['register']['document'] and len(entry['lines'])==2)
        n=entry['lines'][0]['debit_cents'];need(type(n) is int and n>0 and entry['lines']==[dict(account=dep,debit_cents=n,credit_cents=0,sphere='4'),dict(account=asset,debit_cents=0,credit_cents=n,sphere='')])
        expected_vouchers.add(voucher)
    need({row['id'] for row in journal['entries'] if any(line['account'] in (asset,dep) for line in row['lines'])}==expected_vouchers)
    out=dict(schema=1,pool_proof=deepcopy(proof),journal=deepcopy(journal),previous=deepcopy(previous),calculation=calc,journal_verified=True,export_authorized=False)
    return out|{'sha256':digest(out)}
