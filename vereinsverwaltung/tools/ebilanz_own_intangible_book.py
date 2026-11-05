"""Original self-created HGB event chain; no journal or export permission."""
from copy import deepcopy
from ebilanz_own_intangible_sources import calculate,require,money,document,note,day,digest
import re

def calculate_book(source_proof,book,previous=None):
 require(type(source_proof) is dict and set(source_proof)>={'source','previous'} and calculate(source_proof['source'],source_proof['previous'])==source_proof)
 require(type(book) is dict and set(book)=={'schema','club_id','item_id','period','opening_cost_cents','opening_amort_cents','opening_impairment_cents','closing_book_cents','events','document','reason','confirmed'} and book['schema']==1 and type(book['schema']) is int and book['confirmed'] is True)
 s=source_proof['source'];require(all(book[k]==s[k] for k in ('club_id','item_id','period')));document(book['document']);note(book['reason'])
 for field in ('opening_cost_cents','opening_amort_cents','opening_impairment_cents','closing_book_cents'):money(book[field])
 if previous is None:
  require(source_proof['previous'] is None and all(book[k]==0 for k in ('opening_cost_cents','opening_amort_cents','opening_impairment_cents')),'Älterer ursprünglicher HGB-Ansatz benötigt einen eigenen Anfangsbestandadapter.')
 else:
  require(type(previous) is dict and set(previous)=={'schema','source_proof','book_source','previous','hgb','events','journal_verified','export_authorized','sha256'})
  require(calculate_book(previous['source_proof'],previous['book_source'],previous['previous'])==previous and source_proof['previous']==previous['source_proof'])
  require((book['opening_cost_cents'],book['opening_amort_cents'],book['opening_impairment_cents'])==(previous['hgb']['cost_closing_cents'],previous['hgb']['amort_closing_cents'],previous['hgb']['impairment_closing_cents']))
 require(type(book['events']) is list and len(book['events'])<=200)
 cost=book['opening_cost_cents'];amort=book['opening_amort_cents'];impair=book['opening_impairment_cents'];require(amort+impair<=cost)
 totals=dict.fromkeys(('additions','amortisation','impairment','writeup','disposed_cost','disposed_amort','disposed_impairment','disposed_book'),0);trace=[];seen=set();last=day(s['period']['start'])
 for e in book['events']:
  require(type(e) is dict and set(e)=={'id','on','kind','amount_cents','counter_code','document','reason','confirmed'} and type(e['id']) is str and re.fullmatch('[a-f0-9]{32}',e['id']) and e['id'] not in seen and e['confirmed'] is True);seen.add(e['id']);on=day(e['on']);require(last<=on<=day(s['period']['end']));last=on;document(e['document']);note(e['reason']);money(e['amount_cents']);n=e['amount_cents'];require(n>0)
  kind=e['kind'];require(type(kind) is str and kind in ('capitalisation','amortisation','impairment','writeup','disposal'))
  if kind=='capitalisation':
   available=sum(c['attributable_cents'] for c in s['costs'] if source_proof['cost_rows'][c['id']]['eligible'] and day(c['on'])<=on)
   require(s['capitalise'] and e['counter_code']=='48250' and totals['additions']+n<=available and (s['stage']=='development' or on<=day(s['ready_on'])));cost+=n;totals['additions']+=n
  elif kind=='amortisation':
   require(s['stage']=='ready' and on>=day(s['ready_on']) and e['counter_code']=='62010' and n<=cost-amort-impair);amort+=n;totals['amortisation']+=n
  elif kind=='impairment':
   require(e['counter_code']=='62110' and n<=cost-amort-impair);impair+=n;totals['impairment']+=n
  elif kind=='writeup':
   require(e['counter_code']=='49110' and n<=impair);impair-=n;totals['writeup']+=n
  else:
   require(e['counter_code']=='68960' and n==cost-amort-impair,'Nur ursprünglicher vollständiger Abgang ist in dieser Stufe nachgewiesen.');totals['disposed_book']+=n;totals['disposed_cost']+=cost;totals['disposed_amort']+=amort;totals['disposed_impairment']+=impair;cost=amort=impair=0
  require(amort+impair<=cost);money(cost);trace.append({'original':deepcopy(e),'cost_after_cents':cost,'amort_after_cents':amort,'impairment_after_cents':impair,'book_after_cents':cost-amort-impair})
 require(totals['additions']==source_proof['hgb_original_cost_addition_cents'] and cost-amort-impair==book['closing_book_cents'])
 hgb={'cost_opening_cents':book['opening_cost_cents'],'amort_opening_cents':book['opening_amort_cents'],'impairment_opening_cents':book['opening_impairment_cents'],'book_opening_cents':book['opening_cost_cents']-book['opening_amort_cents']-book['opening_impairment_cents'],'cost_closing_cents':cost,'amort_closing_cents':amort,'impairment_closing_cents':impair,'book_closing_cents':cost-amort-impair,**{k+'_cents':v for k,v in totals.items()}}
 out={'schema':1,'source_proof':deepcopy(source_proof),'book_source':deepcopy(book),'previous':deepcopy(previous),'hgb':hgb,'events':trace,'journal_verified':False,'export_authorized':False};return out|{'sha256':digest(out)}
