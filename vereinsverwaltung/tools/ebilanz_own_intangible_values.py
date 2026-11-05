"""Replay original development journals; extract only independently established fiscal nonrecognition."""
from copy import deepcopy
from ebilanz_own_intangible_sources import require,digest
from ebilanz_own_intangible_journals import verify

CODES={'01440','01450','01460','01470','01480'}

def net(lines,chart):
 out=dict.fromkeys(chart,0)
 for line in lines:out[line['account']]+=line['debit_cents']-line['credit_cents']
 return out

def verify_values(values):
 if 'own_intangible_sources' not in values:return None
 envelope=values['own_intangible_sources']
 require(type(envelope) is dict and set(envelope)=={'schema','club_id','year','journal_sha256','schedule_sha256','sources','export_authorized','sha256'})
 require(type(envelope['schema']) is int and envelope['schema']==1 and envelope['export_authorized'] is False and digest({k:v for k,v in envelope.items() if k!='sha256'})==envelope['sha256'])
 require(('whole_club_scope' in values)!=('club_scope' in values) and 'period' not in values and 'asset_tax' not in values and 'partnership_holdings' not in values)
 r=values.get('whole_club_scope',values.get('club_scope'))['source'];require(envelope['year']==values['year']==r['year'] and envelope['journal_sha256']==values['journal_sha256'])
 if 'club_scope' in values:
  from ebilanz_club_scope import rebuild
  rebuilt=rebuild(values['club_scope']);require(rebuilt['book']==r['calculation']==values['book'] and rebuilt['asset_schedule']==r['asset_schedule']==values['asset_schedule'] and rebuilt['journal_sha256']==values['journal_sha256'])
 else:require(values['journal_sha256']==r['closed_sha256'])
 require(values['asset_schedule']==r['asset_schedule'] and envelope['schedule_sha256']==digest(r['asset_schedule']))
 items=r['asset_schedule']['items'];rows=envelope['sources'];require(type(items) is dict and type(rows) is dict and len(items)==len(rows)==1 and set(items)==set(rows))
 for identifier,p in rows.items():
  item=items[identifier];require(item['method']=='own_intangible_hgb' and verify(p['book_proof'],p['journal'],p['previous'])==p and p['book_proof']==item['own_book'])
  from ebilanz_own_intangible_books import register
  register(item,p)
  j=p['journal'];require(j['club_id']==envelope['club_id'] and j['item_id']==identifier and j['year']==r['year'] and j['period']=={'start':str(r['year'])+'-01-01','end':str(r['year'])+'-12-31'} and j['account_key']==item['account'])
  require(j['chart']==r['accounts'] and j['result_account']==r['result_account'] and j['book_opening']==r['opening'])
  docs={};events={row['original']['id']:row['original'] for row in p['book_proof']['events']}
  for cost in p['book_proof']['source_proof']['source']['costs']:
   key=cost['journal_id'];require(key not in docs or docs[key]==cost['document']);docs[key]=cost['document']
  for voucher,event in j['book_links'].items():require(voucher not in docs and event in events);docs[voucher]=events[event]['document']
  original=deepcopy(r['entries']);found=set()
  for entry in original:
   require('document' not in entry)
   if entry['id'] in docs:entry['document']=docs[entry['id']];found.add(entry['id'])
  require(found==set(docs) and original==j['book_entries'])
  tax=original+[{'id':'tax-adjustment-'+row['id'],'date':str(r['year'])+'-12-31','lines':deepcopy(row['lines']),'document':j['fiscal_source']['document']} for row in values['current_adjustments']]
  require(tax==j['tax_entries']);opening=net(r['opening'],r['accounts'])
  for row in values['opening_adjustments']:
   for line in row['lines']:opening[line['account']]+=line['debit_cents']-line['credit_cents']
  require(net(j['tax_opening'],r['accounts'])==opening)
  require(all({k:v for k,v in p[side+'_calculation'].items() if k!='period'}==values[side] for side in ('book','tax')))
  require((r['previous_sha256'] is None)==(p['previous'] is None))
  if r['accounts'][r['result_account']]['skr_code'] in ('29000','29080'):require(values['book']['annual_result_cents']==values['tax']['annual_result_cents']==0)
 return envelope

def excluded(values,book_only=False):
 if book_only:
  from ebilanz_own_intangible_books import verify_values as original_books
  original_books(values);return set()
 require('own_hgb_sources' not in values)
 active={key for key,row in values['book']['accounts'].items() if row['skr_code'] in CODES and any(row[f] for f in ('opening_cents','debit_cents','credit_cents'))}
 proof=verify_values(values)
 if proof is None:
  require(not active,'Selbst geschaffene Werte benötigen den unabhängig geprüften ursprünglichen Entwicklungs- und Steuerjournalnachweis.')
  return set()
 require(not book_only,'Ursprünglichen HGB-Ausweis selbst geschaffener Werte separat an den amtlichen Spiegel anbinden.')
 out=set()
 for p in proof['sources'].values():
  j=p['journal'];key=j['account_key'];require(key in active or p['book_proof']['hgb']['book_closing_cents']==0);out.add(key)
  counters={}
  for trace in p['original_book_lines'].values():
   for line in trace['original']['lines']:
    if line['account']==key:continue
    sums=counters.setdefault(line['account'],[0,0]);sums[0]+=line['debit_cents'];sums[1]+=line['credit_cents']
  for counter,(debit,credit) in counters.items():
   book=values['book']['accounts'][counter];tax=values['tax']['accounts'][counter]
   require(book['opening_cents']==0 and book['debit_cents']==debit and book['credit_cents']==credit,'Gemeinsame Gegenkonten erst mit allen ursprünglichen Vorgängen qualifizieren.')
   require(tax['opening_cents']==tax['closing_cents']==tax['amount_cents']==0);out.add(counter)
  require(all(values['tax']['accounts'][key][f]==0 for f in ('opening_cents','closing_cents','amount_cents')))
 require(active<={p['journal']['account_key'] for p in proof['sources'].values()});return out

def mapping(values):
 if 'own_intangible_sources' not in values:return {}
 from ebilanz_own_intangible_books import TARGET,COUNTERS
 e=verify_values(values);excluded(values);out={}
 for p in e['sources'].values():
  out[p['journal']['account_key']]=TARGET;sums={}
  for trace in p['original_book_lines'].values():
   key=trace['counter_key'];code=p['journal']['chart'][key]['skr_code'];require(code in COUNTERS)
   require(key not in out or out[key]==COUNTERS[code]);out[key]=COUNTERS[code]
   n=sums.setdefault(key,[0,0])
   for line in trace['original']['lines']:
    if line['account']==key:n[0]+=line['debit_cents'];n[1]+=line['credit_cents']
  for key,(debit,credit) in sums.items():
   a=values['book']['accounts'][key];require(a['opening_cents']==0 and a['debit_cents']==debit and a['credit_cents']==credit,'Alle ursprünglichen Gegenkontenbewegungen vollständig abgleichen.')
 return out

def prepare(values,mapping,profile,meta,legacy):
 proof=verify_values(values);require(proof is not None and profile.get('kind') in ('whole_taxable_club','club_taxable_businesses') and profile.get('asset_scope')==('whole_club' if profile['kind']=='whole_taxable_club' else 'single_reported_business') and profile.get('asset_tax_values_reviewed') is True and values.get('annual_section')!='books')
 excluded(values);subset=deepcopy(values);subset.pop('own_intangible_sources');keys={p['journal']['account_key'] for p in proof['sources'].values()}
 for side in ('book','tax'):
  for key in keys:subset[side]['accounts'].pop(key)
 for key in keys:subset['accounts'].pop(key)
 # This is the ordinary-assets projection of a verified full register. The
 # complete original register and both unchanged calculations remain in values.
 from ebilanz_assets import FIELDS
 subset['asset_schedule']['items']={};subset['asset_schedule']['totals']=dict.fromkeys(FIELDS,0)
 out=legacy(subset,{key:target for key,target in mapping.items() if key not in keys},profile,meta)
 return out|{'items':out['items']+len(proof['sources']),'sha256':digest({'ordinary_sha256':out['sha256'],'original_development_sources_sha256':proof['sha256']})}
