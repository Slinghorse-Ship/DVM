"""Bind original HGB and assessed capital movements to actual balanced journals.

No entries are generated. A reversed HGB impairment is retained as an explicit
pair, rather than being disguised as a fiscal capital loss or writeup.
"""
from copy import deepcopy
import re
import hashlib
from ebilanz_partnership_sources import reconcile
from ebilanz_partnership_basis import require,document,digest
from ebilanz_partnership_book import note
from ebilanz_club_scope import calculate
from ebilanz_period import check

def verify(sources,journal,previous=None,depth=0):
 require(type(depth) is int and 0<=depth<=100)
 require(type(sources) is dict and reconcile(sources['book_basis'],sources['capital_basis'],sources['relationship'])==sources)
 require(type(journal) is dict and set(journal)=={'schema','club_id','holding_id','account_key','result_account','period','year','chart','book_opening','book_entries','tax_opening','tax_entries','book_links','tax_links','document','reason','confirmed'})
 require(type(journal['schema']) is int and journal['schema']==1 and journal['confirmed'] is True and all(journal[k]==sources[k] for k in ('club_id','holding_id','period')))
 require(type(journal['year']) is int and journal['year'] in (2025,2026),'Beteiligungsjournal: ursprünglichen geprüften Jahreskatalog verwenden.')
 period=check(journal['period'],journal['year']);document(journal['document']);note(journal['reason'])
 require(sources['capital_basis']['opening_cents']>=0 and sources['capital_basis']['closing_cents']>=0,'Negativer Spiegelbildansatz benötigt seinen eigenen signierten Bilanzadapter; der Originalbetrag bleibt im Quellenstand erhalten.')
 chart=journal['chart'];key=journal['account_key'];require(type(chart) is dict and type(key) is str and chart.get(key,{}).get('group')=='fixed_assets' and chart[key].get('skr_code')==sources['account'])
 require(type(journal['result_account']) is str and chart.get(journal['result_account'],{}).get('group')=='equity')
 carry='unbound_previous_journal' if sources['book_basis']['previous'] is not None or sources['capital_basis']['previous'] is not None else 'initial_source'
 if previous is not None:
  require(type(previous) is dict and verify(previous['sources'],previous['journal'],previous.get('previous'),depth+1)==previous)
  old=previous['sources'];require(sources['book_basis']['previous']==old['book_basis'] and sources['capital_basis']['previous']==old['capital_basis'] and journal['result_account']==previous['journal']['result_account'])
  for k,row in previous['journal']['chart'].items():require(chart.get(k)==row)
  for side in ('book','tax'):
   calc=previous[side+'_calculation'];expected_opening={k:row['closing_cents'] for k,row in calc['accounts'].items() if row['group'] not in ('expense','revenue','result_use')}
   use=sum(row['amount_cents'] for row in calc['accounts'].values() if row['group']=='result_use')
   expected_opening[journal['result_account']]-=calc['annual_result_cents']+use
   actual={k:0 for k in chart}
   for line in journal[side+'_opening']:actual[line['account']]+=line['debit_cents']-line['credit_cents']
   require(actual=={k:expected_opening.get(k,0) for k in chart},'Sämtliche ursprünglichen Buch-/Steuerbestände mit ihrem tatsächlichen Vorjahresergebnis unverändert vortragen.')
  carry='verified_original_journal'
 expected={};s=sources['book_basis']['source'];p=s['plan'];basis=sources['book_basis']['book']
 if period['start']<=p['acquired_on']<=period['end']:expected['acquisition']={'on':p['acquired_on'],'kind':'addition','cents':p['basis_cents']}
 for movement in sources['book_basis']['movements']:
  expected[movement['event_id']]={'on':movement['on'],'kind':movement['kind'],'cents':movement['book_change_cents']}
 book_calc=calculate(journal['year'],chart,journal['book_opening'],journal['book_entries'],period)
 tax_entries=deepcopy(journal['tax_entries']);actual_ids=set()
 for entry in tax_entries:
  identifier=entry['id'];require(type(identifier) is str and identifier not in actual_ids);actual_ids.add(identifier)
  if re.fullmatch('tax-adjustment-[a-z0-9_-]{1,40}',identifier):
   # Only the independent arithmetic calculator needs UUID-shaped internal IDs.
   # The original tax journal and every source link retain their actual IDs.
   entry['id']=hashlib.sha256(identifier.encode()).hexdigest()[:32]
 tax_calc=calculate(journal['year'],chart,journal['tax_opening'],tax_entries,period)
 require(not book_calc['issues'] and not tax_calc['issues'])
 require(book_calc['accounts'][key]['opening_cents']==basis['book_opening_cents'] and book_calc['accounts'][key]['closing_cents']==basis['book_closing_cents'])
 require(tax_calc['accounts'][key]['opening_cents']==sources['capital_basis']['opening_cents'] and tax_calc['accounts'][key]['closing_cents']==sources['capital_basis']['closing_cents'])
 require(book_calc['accounts'][key]['debit_cents']==basis['additions_cents']+basis['writeup_cents'] and book_calc['accounts'][key]['credit_cents']==basis['impairment_cents']+basis['book_disposals_cents'],'Ursprüngliche HGB-Bruttobewegungen vollständig abstimmen.')
 def fixed_lines(entries):
  out={}
  for e in entries:
   require(type(e['id']) is str and e['id'] not in {x['voucher'] for x in out.values()})
   for index,line in enumerate(e['lines']):
    if line['account']!=key:continue
    require(len(e['lines'])==2,'Beteiligungsjournal: mehrzeilige Originalvorgänge benötigen ihren eigenen vollständigen Aufteilungsnachweis.')
    other=e['lines'][1-index];require(other['account']!=key)
    ref=e['id']+':'+str(index);require(ref not in out)
    out[ref]={'voucher':e['id'],'on':e['date'],'cents':line['debit_cents']-line['credit_cents'],'counter':other['account'],'counter_code':chart[other['account']]['skr_code'],'counter_group':chart[other['account']]['group']}
  return out
 book_lines=fixed_lines(journal['book_entries']);tax_lines=fixed_lines(journal['tax_entries'])
 require((type(journal['book_links']) is dict or journal['book_links']==[]) and set(journal['book_links'])==set(book_lines))
 used=set();original={}
 for ref,event_id in (journal['book_links'] or {}).items():
  require(type(event_id) is str and event_id in expected and event_id not in used);used.add(event_id);actual=book_lines[ref];wanted=expected[event_id]
  require(actual['on']==wanted['on'] and actual['cents']==wanted['cents']);kind=wanted['kind'];code=actual['counter_code'];group=actual['counter_group']
  if kind=='addition':require(group in ('cash','receivables','other_assets','liabilities'),'Tatsächliche ursprüngliche Zuführung ohne fingierten Ertrag nachweisen.')
  elif kind=='impairment':require(group=='expense' and code in ('72000','72010','72070'))
  elif kind=='writeup':require(group=='revenue' and code=='49120')
  else:require(group=='expense' and code=='68970')
  if code=='72070':require(sources['relationship']['kind'] in ('affiliated','parent_company'))
  original[event_id]=actual
 require(used==set(expected),'Alle ursprünglichen HGB-Ereignisse an ihre tatsächlichen Journalzeilen binden.')
 require((type(journal['tax_links']) is dict or journal['tax_links']==[]) and set(journal['tax_links'])==set(tax_lines))
 capital_events={e['id']:e for e in sources['capital_basis']['source']['movements']};capital_deltas={e['event_id']:e['basis_change_cents'] for e in sources['capital_basis']['movements']};used=set();reversals={}
 for ref,link in (journal['tax_links'] or {}).items():
  require(type(link) is dict and set(link)=={'kind','event_id'} and type(link['event_id']) is str);actual=tax_lines[ref];event_id=link['event_id']
  if link['kind']=='capital_movement':
   require(event_id in capital_events and event_id not in used);used.add(event_id);e=capital_events[event_id]
   require(actual['on']==e['on'] and actual['cents']==capital_deltas[event_id]);kind=e['kind'];group=actual['counter_group'];code=actual['counter_code']
   if kind in ('contribution','withdrawal'):require(group in ('cash','receivables','other_assets','liabilities'))
   elif kind=='assessed_profit':
    require(group=='revenue' and code in (('70030',) if sources['capital_basis']['source']['classification']=='atypical_silent_partner' else (('70040','70080') if sources['relationship']['kind'] in ('affiliated','parent_company') else ('70080',))))
   elif kind=='assessed_loss':require(group=='expense' and code=='72080')
   else:raise ValueError('Kapitalberichtigung benötigt einen gesondert belegten ursprünglichen steuerlichen Gegenposten; nicht als laufenden Gewinn oder Verlust ausgeben.')
  else:
   require(link['kind']=='book_reversal_pair' and event_id in original and expected[event_id]['kind'] in ('impairment','writeup','disposal'))
   wanted=original[event_id];require(actual['counter']==wanted['counter'] and abs(actual['cents'])==abs(wanted['cents']) and actual['on']>=wanted['on'])
   reversals.setdefault(event_id,[]).append(actual)
 require(used==set(capital_events),'Alle tatsächlichen steuerlichen Kapitalbewegungen einzeln an ihre Originaljournalzeilen binden.')
 for event_id,pair in reversals.items():
  wanted=original[event_id]
  require(len(pair)==2 and sum(x['cents'] for x in pair)==0 and sum(x==wanted for x in pair)==1,'HGB-Bewegung und tatsächliche steuerliche Gegenbuchung als unverändertes Originalpaar nachweisen.')
 # A book event not used as a zero-net reversal must correspond to an assessed
 # capital movement. No active financial movement may disappear by net balance.
 for event_id,wanted in original.items():
  if event_id in reversals:continue
  require(any(actual==wanted and journal['tax_links'][ref]['kind']=='capital_movement' for ref,actual in tax_lines.items()),'HGB-Bewegung ohne tatsächlichen steuerlichen Kapitalnachweis oder vollständiges Gegenbuchungspaar.')
 original_journal=deepcopy(journal)
 # PHP's empty associative arrays have the explicit JSON representation [].
 # Accept only that empty case; nonempty link collections remain keyed maps.
 for name in ('book_links','tax_links'):
  if not original_journal[name]:original_journal[name]=[]
 out={'schema':1,'sources':deepcopy(sources),'previous':deepcopy(previous),'carry':carry,'sources_sha256':sources['sha256'],'journal':original_journal,'book_calculation':book_calc,'tax_calculation':tax_calc,'book_fixed_lines':book_lines or [],'tax_fixed_lines':tax_lines or [],'reversal_pairs':reversals or [],'export_authorized':False}
 return out|{'sha256':digest(out)}
