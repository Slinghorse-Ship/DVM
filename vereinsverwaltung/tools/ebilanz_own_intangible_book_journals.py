"""Complete original HGB journal replay, independent of fiscal sources."""
from copy import deepcopy
import hashlib,re,sys
from pathlib import Path
from ebilanz_club_scope import calculate
from ebilanz_own_intangible_sources import require,document,note,digest
from ebilanz_own_intangible_book import calculate_book

def verify(book_proof,journal,previous=None,depth=0):
 require(type(depth) is int and 0<=depth<2)
 require(type(book_proof) is dict and set(book_proof)>={'source_proof','book_source','previous'} and calculate_book(book_proof['source_proof'],book_proof['book_source'],book_proof['previous'])==book_proof)
 require(type(journal) is dict and set(journal)=={'schema','club_id','item_id','year','period','account_key','result_account','chart','book_opening','book_entries','book_links','document','reason','confirmed'} and type(journal['schema']) is int and journal['schema']==1 and journal['confirmed'] is True)
 s=book_proof['source_proof']['source'];require(all(journal[k]==s[k] for k in ('club_id','item_id','period')) and type(journal['year']) is int and journal['period']=={'start':f"{journal['year']}-01-01",'end':f"{journal['year']}-12-31"});document(journal['document']);note(journal['reason'])
 chart=journal['chart'];key=journal['account_key'];result_key=journal['result_account'];require(type(chart) is dict and type(key) is str and chart.get(key,{}).get('group')=='fixed_assets' and chart[key].get('skr_code')==s['account'] and type(result_key) is str and chart.get(result_key,{}).get('group')=='equity')
 if previous is None:require(book_proof['previous'] is None)
 else:
  require(type(previous) is dict and set(previous)>={'book_proof','journal','previous'} and verify(previous['book_proof'],previous['journal'],previous['previous'],depth+1)==previous and book_proof['previous']==previous['book_proof'] and result_key==previous['journal']['result_account'])
  require(all(chart.get(k)==v for k,v in previous['journal']['chart'].items()))
  for side in ('book',):
   old=previous[side+'_calculation'];opening={k:row['closing_cents'] for k,row in old['accounts'].items() if row['group'] not in ('expense','revenue','result_use')};opening[result_key]-=old['annual_result_cents']+sum(row['amount_cents'] for row in old['accounts'].values() if row['group']=='result_use');actual=dict.fromkeys(chart,0)
   for line in journal[side+'_opening']:actual[line['account']]+=line['debit_cents']-line['credit_cents']
   require(actual=={k:opening.get(k,0) for k in chart})
 def calc(side):
  entries=deepcopy(journal[side+'_entries'])
  return calculate(journal['year'],chart,journal[side+'_opening'],entries,journal['period'])
 book=calc('book');require(not book['issues']);h=book_proof['hgb'];require(book['accounts'][key]['opening_cents']==h['book_opening_cents'] and book['accounts'][key]['closing_cents']==h['book_closing_cents'])
 require(journal['document']==book_proof['book_source']['document'] and journal['reason']==book_proof['book_source']['reason'] and (chart[result_key]['skr_code'] not in ('29000','29080') or book['annual_result_cents']==0))
 entries={};costs={};fixed={}
 for e in journal['book_entries']:
  require(type(e) is dict and type(e.get('id')) is str and e['id'] not in entries);entries[e['id']]=e
  if any(line['account']==key for line in e['lines']):fixed[e['id']]=e
 for c in s['costs']:
  require(c['journal_id'] in entries);e=entries[c['journal_id']];n=c['line_ordinal'];require(n<len(e['lines']));line=e['lines'][n];require(chart[line['account']]['group']=='expense' and chart[line['account']]['skr_code'] not in ('62010','62110','68960') and line['credit_cents']==0 and line['debit_cents']==c['booked_cents'] and e['date']==c['on'] and e.get('document')==c['document']);costs[c['id']]={'voucher':e['id'],'line_ordinal':n,'original_expense_cents':line['debit_cents'],'attributed_cents':c['attributable_cents'],'capitalisation_eligible':book_proof['source_proof']['cost_rows'][c['id']]['eligible']}
 expected={e['original']['id']:e['original'] for e in book_proof['events']};require(type(journal['book_links']) is dict and set(journal['book_links'])==set(fixed) and set(journal['book_links'].values())==set(expected) and len(journal['book_links'])==len(expected));trace={}
 for voucher,identifier in journal['book_links'].items():
  original=expected[identifier];e=fixed[voucher];require(e['date']==original['on'] and e.get('document')==original['document'] and len(e['lines'])==2);own=[l for l in e['lines'] if l['account']==key];other=[l for l in e['lines'] if l['account']!=key];require(len(own)==len(other)==1);line=own[0];counter=other[0];positive=original['kind'] in ('capitalisation','writeup');require((line['debit_cents'],line['credit_cents'])==((original['amount_cents'],0) if positive else (0,original['amount_cents'])));require(chart[counter['account']]['skr_code']==original['counter_code'] and chart[counter['account']]['group']==('revenue' if positive else 'expense'));trace[identifier]={'original':deepcopy(e),'counter_key':counter['account'],'book_change_cents':line['debit_cents']-line['credit_cents']}
 require(book['accounts'][key]['debit_cents']==h['additions_cents']+h['writeup_cents'] and book['accounts'][key]['credit_cents']==h['amortisation_cents']+h['impairment_cents']+h['disposed_book_cents'])
 out={'schema':1,'book_proof':deepcopy(book_proof),'journal':deepcopy(journal),'previous':deepcopy(previous),'book_calculation':book,'original_cost_lines':costs,'original_book_lines':trace,'journal_verified':True,'export_authorized':False};return out|{'sha256':digest(out)}
