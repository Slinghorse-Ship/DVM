"""Original expense/provision fiscal values and independently approved capital presentation."""
from copy import deepcopy
import re,hashlib
from ebilanz_club_scope import calculate, lines
from ebilanz_pension_offsets import document

TARGET='bs.eqLiab.equity.netIncome.taxBalanceGenerally.transferDiffTaxAccounts'
ENTRY='tax-equalization-77690'
FLAGS=('original_capital_presentation_reviewed','original_fiscal_values_reviewed','no_other_result_use_reviewed')
DOCUMENTS=('capital','fiscal','presentation')
KEYS=('schema','year','journal_sha256','use_account','capital_account','original_current_adjustments')+FLAGS+tuple(k+'_document' for k in DOCUMENTS)

def require(ok):
 if not ok:raise ValueError('77690: ursprüngliche Kapitaldarstellung, steuerliche Bewertungsbuchungen und belegte erfolgsneutrale Ergebnisumbuchung vollständig abstimmen.')

def bind(r,s):
 if 'whole_club_scope' in r:r=r['whole_club_scope']['source']
 require(type(s) is dict and tuple(s)==KEYS and type(s['schema']) is int and s['schema']==1)
 require(type(r.get('year')) is int and r['year'] in (2025,2026) and r.get('state')=='closed' and r.get('issues')==[] and r.get('previous_sha256') is None and not set(r)&{'period','club_scope'} and type(s['year']) is int and s['year']==r['year'] and type(s['journal_sha256']) is str and re.fullmatch('[a-f0-9]{64}',s['journal_sha256']) and s['journal_sha256']==r['closed_sha256'])
 schedule=r.get('asset_schedule',{});require(schedule.get('year')==r['year'] and schedule.get('issues')==[] and schedule.get('items')==[])
 require(all(s[k] is True for k in FLAGS))
 for k in DOCUMENTS:document(s[k+'_document'])
 require(len({s[k+'_document']['document_id'] for k in DOCUMENTS})==3)
 book=calculate(r['year'],r['accounts'],r['opening'],r['entries']);require(book==r['calculation'] and book['issues']==[])
 require(type(s['use_account']) is str and type(s['capital_account']) is str and s['use_account']!=s['capital_account'] and s['use_account'] in book['accounts'] and s['capital_account'] in book['accounts'])
 use,capital=book['accounts'][s['use_account']],book['accounts'][s['capital_account']]
 require(use['group']=='result_use' and use['skr_code']=='77690' and capital['group']=='equity' and capital['skr_code']=='29700' and s['capital_account']==r['result_account'])
 fields=('opening_cents','debit_cents','credit_cents','closing_cents','amount_cents')
 require(all(type(use[f]) is int and use[f]==0 for f in fields) and all(k==s['use_account'] for k,a in book['accounts'].items() if a['group']=='result_use'))
 entries=s['original_current_adjustments'];require(type(entries) is list and 1<=len(entries)<=10);seen=set();vouchers=deepcopy(r['entries'])
 for i,e in enumerate(entries):
  require(type(e) is dict and tuple(e)==('id','reason','lines') and type(e['id']) is str and re.fullmatch('[a-z0-9_-]{1,40}',e['id']) and e['id']!=ENTRY and e['id'] not in seen and type(e['reason']) is str and e['reason'].strip()==e['reason'] and e['reason'] and len(e['reason'])<=1500 and not re.search('[\x00-\x1f]',e['reason']));seen.add(e['id'])
  lines(e['lines'],r['accounts']);require(len(e['lines'])==2 and sorted(r['accounts'][l['account']]['group'] for l in e['lines'])==['expense','provisions'])
  vouchers.append(dict(id=hashlib.md5(('tax-original-'+str(i)).encode()).hexdigest(),date=str(r['year'])+'-12-31',lines=deepcopy(e['lines'])))
 tax=calculate(r['year'],r['accounts'],r['opening'],vouchers);require(tax['issues']==[])
 change=tax['annual_result_cents']-book['annual_result_cents'];require(type(change) is int and 0<abs(change)<=100_000_000_000)
 def line(key,signed):return dict(account=key,debit_cents=max(0,signed),credit_cents=max(0,-signed),sphere='')
 posting=dict(id=ENTRY,reason='Belegte Ergebnisumbuchung 77690 zur unveränderten ursprünglichen Kapitaldarstellung.',lines=[line(s['use_account'],change),line(s['capital_account'],-change)])
 return dict(schema=1,source=deepcopy(s),commercial_result_cents=book['annual_result_cents'],fiscal_result_cents=tax['annual_result_cents'],current_result_difference_cents=change,capital_book_amount_cents=capital['amount_cents'],presentation_entry=posting)

def check(v):
 fields=('opening_cents','debit_cents','credit_cents','closing_cents','amount_cents')
 active={k for k,a in v['tax']['accounts'].items() if a['skr_code']=='77690' and any(a[f]!=0 for f in fields)};p=v.get('tax_equalization')
 if p is None:require(not active);return
 require(type(p) is dict and 'whole_club_scope' in v and not set(v)&{'period','club_scope','asset_tax','special_tax_7g','special_tax_7b','basis_reduction_7g','basis_reduction_6b','pension_offsets'} and bind(v['whole_club_scope']['source'],p['source'])==p)
 s=p['source'];require(active=={s['use_account']} and v['opening_adjustments']==[] and v['current_adjustments']==s['original_current_adjustments']+[p['presentation_entry']])
 r=v['whole_club_scope']['source'];entries=deepcopy(r['entries'])
 for i,e in enumerate(v['current_adjustments']):entries.append(dict(id=hashlib.md5(('tax-replay-'+str(i)).encode()).hexdigest(),date=str(r['year'])+'-12-31',lines=deepcopy(e['lines'])))
 require(calculate(r['year'],r['accounts'],r['opening'],r['entries'])==v['book'] and calculate(r['year'],r['accounts'],r['opening'],entries)==v['tax'] and v['result_difference_cents']==p['current_result_difference_cents'])

def validate_rule(r):
 require(r.get('tax_equalization_contract')=='77690@1' and r.get('group')=='result_use' and r.get('condition')=='detail_choice' and r.get('target')==TARGET and r.get('allowed_targets')==[TARGET] and not set(r)&{'book_only','commercial_contract'} and not r.get('disclosures'))
 if 'codes' in r:require(r['codes']==['77690'])

def presentation(v,mapping,book_only=False):
 check(v)
 p=v.get('tax_equalization')
 if p is None:return dict(keys=set(),facts={},result_effect=0)
 require(not book_only)
 s=p['source'];target=mapping.get(s['capital_account'])
 require(mapping.get(s['use_account'])==TARGET and target=='bs.eqLiab.equity.SurplusOfUnspentFunds')
 return dict(keys={s['use_account'],s['capital_account']},facts={TARGET:p['current_result_difference_cents'],target:p['capital_book_amount_cents']},result_effect=-p['current_result_difference_cents'])
