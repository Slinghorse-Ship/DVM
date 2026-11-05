"""Replay original holding sources against whole annual book/tax values.

No database, inferred matrix, amount-changing entries or catalog permission.
"""
from copy import deepcopy
from ebilanz_partnership_basis import require,digest
from ebilanz_partnership_matrix import reconcile
import re

def net(lines,chart):
 out=dict.fromkeys(chart,0)
 for l in lines:out[l['account']]+=l['debit_cents']-l['credit_cents']
 return dict(sorted(out.items()))

def verify_values(values):
 if 'partnership_holdings' not in values:return None
 proof=values['partnership_holdings'];require(type(proof) is dict and set(proof)=={'schema','club_id','year','journal_sha256','ordinary_schedule_sha256','book_calculation_sha256','tax_calculation_sha256','initial_opening_holdings','holdings','export_authorized','sha256'})
 require(type(proof['schema']) is int and proof['schema']==1 and proof['export_authorized'] is False and digest({k:v for k,v in proof.items() if k!='sha256'})==proof['sha256'])
 require('whole_club_scope' in values and 'club_scope' not in values and 'period' not in values)
 r=values['whole_club_scope']['source'];require(proof['year']==values['year']==r['year'] and proof['journal_sha256']==values['journal_sha256']==r['closed_sha256'])
 require(proof['ordinary_schedule_sha256']==digest(r['asset_schedule']) and values['asset_schedule']==r['asset_schedule'])
 require(all(proof[s+'_calculation_sha256']==digest(values[s]) for s in ('book','tax')))
 holdings=proof['holdings'];require(type(holdings) is dict and 1<=len(holdings)<=200);seen=set();chart=r['accounts'];initial=[]
 require('previous_sha256' in r)
 if chart[r['result_account']]['skr_code'] in ('29000','29080'):
  for side in ('book','tax'):
   c=values[side];remaining=c['annual_result_cents']+sum(a['amount_cents'] for a in c['accounts'].values() if a['group']=='result_use')
   require(remaining==0,'Ergebnisvortrag auf ein gesondertes Ergebniskonto buchen; das ursprüngliche Stammkapital darf durch den automatischen Vortrag nicht verändert werden.')
 for holding,m in holdings.items():
  require(type(holding) is str and re.fullmatch('[a-f0-9]{32}',holding) and type(m) is dict and reconcile(m['journal_proof'],m['source'],m.get('previous'))==m)
  p=m['journal_proof'];j=p['journal'];key=j['account_key'];require(holding==j['holding_id'] and key not in seen);seen.add(key)
  require(j['club_id']==proof['club_id'])
  require(j['year']==r['year'] and j['period']=={'start':str(r['year'])+'-01-01','end':str(r['year'])+'-12-31'})
  require(j['chart']==chart and j['result_account']==r['result_account'] and j['book_opening']==r['opening'] and j['book_entries']==r['entries'])
  require(all({k:v for k,v in p[s+'_calculation'].items() if k!='period'}==values[s] for s in ('book','tax')))
  if r['previous_sha256'] is not None:
   require(p['previous'] is not None and m['previous'] is not None and p['carry']=='verified_original_journal' and m['carry']=='verified_original_matrix_journal','Ursprüngliche Vorjahresjournale und steuerliche Originalmatrizen vollständig übernehmen.')
  elif p['carry']=='unbound_previous_journal' or m['carry']=='unbound_previous_matrix':
   require(p['previous'] is None and m['previous'] is None,'Anfangsübernahme nur ohne vorangehenden Anwendungsabschluss und ohne behauptetes Vorjahresjournal bestätigen.')
   initial.append(holding)
  expected=deepcopy(r['entries'])+[{'id':'tax-adjustment-'+e['id'],'date':str(r['year'])+'-12-31','lines':deepcopy(e['lines'])} for e in values['current_adjustments']]
  require(j['tax_entries']==expected,'Jede ursprüngliche steuerliche Journalzeile muss der tatsächlichen, bestätigten Wertänderung entsprechen.')
  opening=net(r['opening'],chart)
  for e in values['opening_adjustments']:
   for l in e['lines']:opening[l['account']]+=l['debit_cents']-l['credit_cents']
  require(net(j['tax_opening'],chart)==opening)
  items=r['asset_schedule']['items'] or {};require(type(items) is dict)
  for identifier,item in items.items():
   if item.get('account',item.get('fixed_account'))==key:
    require(item.get('method')=='partnership_hgb' and identifier==holding and item.get('partnership_book')==p['sources']['book_basis'] and item.get('partnership_plan',{}).get('partnership_relationship')==p['sources']['relationship'],'Ursprüngliche Beteiligung nicht zugleich als gewöhnliche Kostenanlage bewerten; das bestätigte HGB-Register unverändert übernehmen.')
 require(type(proof['initial_opening_holdings']) is list and proof['initial_opening_holdings']==initial)
 return proof

TARGETS={'bs.ass.fixAss.fin.'+prefix+'.partnerships.commPart.'+kind for prefix in ('particip','sharesInAffil') for kind in ('other','silentAtyp')}

def mapping(values):
 proof=verify_values(values)
 if proof is None:return {}
 from ebilanz_cost_assets import COUNTERPARTS
 out={}
 for m in proof['holdings'].values():
  p=m['journal_proof'];key=p['journal']['account_key']
  require(key not in out);out[key]=m['target']
  for line in (p['book_fixed_lines'] or {}).values():
   code=line['counter_code']
   if code not in ('72000','72010','49120','68970'):continue
   target=COUNTERPARTS[code];counter=line['counter'];require(counter not in out or out[counter]==target);out[counter]=target
  for line in (p['tax_fixed_lines'] or {}).values():
   code=line['counter_code'];target='is.netIncome.regular.fin.commPart' if code in ('70030','70040','70080') else ('is.netIncome.regular.fin.lossCommPart' if code=='72080' else None)
   if target is not None:
    counter=line['counter'];require(counter not in out or out[counter]==target);out[counter]=target
 return out

def disclosures(values,book_only=False):
 proof=verify_values(values)
 if proof is None:return {}
 require(not book_only,'Ursprüngliche HGB-Beteiligungsmatrix dieser Quellenstufe noch nicht an den zusammengesetzten Bericht angebunden.')
 out={}
 for m in proof['holdings'].values():
  s=m['journal_proof']['sources']
  for target in s['parent_disclosures']:out[target]=out.get(target,0)+s['capital_basis']['closing_cents']
 return out

def prepare(values,selected,profile,meta,legacy):
 proof=verify_values(values);require(proof is not None)
 require(profile.get('kind')=='whole_taxable_club' and 'asset_tax' not in values,'Beteiligungen und abweichende gewöhnliche Steuer-Anlagenpläne gemeinsam anhand vollständiger Originalquellen anbinden.')
 subset=deepcopy(values);subset.pop('partnership_holdings');keys={m['journal_proof']['journal']['account_key'] for m in proof['holdings'].values()}
 for side in ('book','tax'):
  for key in keys:subset[side]['accounts'].pop(key)
 for key in keys:subset['accounts'].pop(key)
 # Extract the separate confirmed HGB register before replaying the ordinary
 # cost subset. Its full untouched source remains in the retained whole report.
 items=subset['asset_schedule']['items'] or {}
 ordinary={hid:item for hid,item in items.items() if item.get('account') not in keys}
 if len(ordinary)!=len(items):
  from ebilanz_assets import FIELDS,DISPOSAL_FIELDS
  fields=FIELDS
  if any(item.get('method')=='cost' or 'disposal' in item for item in ordinary.values()):fields+=DISPOSAL_FIELDS
  if any(item.get('method')=='cost' for item in ordinary.values()):fields+=('impairment_cents','writeup_cents')
  subset['asset_schedule']['items']=ordinary
  subset['asset_schedule']['totals']={field:sum(item.get(field,0) for item in ordinary.values()) for field in fields}
 out=legacy(subset,{k:t for k,t in selected.items() if k not in keys},profile,meta)
 rows=deepcopy(out['rows']);network=meta['asset_calculations'];direct=set()
 for m in proof['holdings'].values():
  key=m['journal_proof']['journal']['account_key'];leaf=m['target'];require(selected.get(key)==leaf)
  require(leaf not in direct,'Mehrere Originalbeteiligungen derselben Position gemeinsam anhand ihrer ursprünglichen Summen abstimmen.');direct.add(leaf)
  ancestors=set();pending=[leaf]
  while pending:
   parent=pending.pop()
   if parent in ancestors:continue
   ancestors.add(parent);pending+=meta['asset_parents'].get(parent,[])
  require('bs.ass.fixAss' in ancestors)
  for target in ancestors:
   col=m['columns']
   if target not in rows:rows[target]=deepcopy(col);continue
   require(set(rows[target])==set(col))
   rows[target]={c:rows[target][c]+n if rows[target][c] is not None and n is not None else None for c,n in col.items()}
 for target,columns in rows.items():
  for parent,children in network.items():
   known=[(columns[c],w) for c,w in children.items() if columns[c] is not None]
   require(columns[parent]==sum(n*w for n,w in known) if known else columns[parent] is None,'Ursprüngliche Summenmatrix bleibt unvollständig: '+target+'/'+parent)
 return out|{'rows':rows,'items':out['items']+len(proof['holdings']),'sha256':digest({'ordinary_schedule_sha256':out['sha256'],'partnership_holdings_sha256':proof['sha256']})}
