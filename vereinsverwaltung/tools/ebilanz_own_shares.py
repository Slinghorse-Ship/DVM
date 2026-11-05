"""Original29610 is a reserve deduction; nominal29090 remains separately deducted."""
from pathlib import Path
CODE='29610';TARGET='bs.eqLiab.equity.revenueRes.other';DETAIL=TARGET+'.ownSharesPlus';NOMINAL='bs.eqLiab.equity.subscribed.ownSharesdeducted';FORMS={'AG','GMBH','gGMBH','UG'};LIMIT=100000000000

def require(ok,message='Eigene Anteile: Gesellschaftsform, ursprünglichen Nennbetrag und nichtnegativen Rücklagenbestand bestätigen.'):
 if not ok:raise ValueError(message)
def validate_rule(rule):
 ref=rule.get('own_share_cost_contract')
 if ref is None:return
 require(rule.get('own_share_disclosures')==[DETAIL])
 require(ref==CODE+'@1' and rule.get('group')=='reserves' and rule.get('target')==TARGET and rule.get('allowed_targets')==[TARGET] and rule.get('condition')=='detail_choice' and not rule.get('disclosures') and 'reserve_pool' not in rule)
 if 'codes' in rule:require(rule['codes']==[CODE])
def check_values(values,mapping,profile):
 from ebilanz_mapping import checked_source
 root=Path(__file__).resolve().parent.parent;year=values['year'];_,rules,_=checked_source(year,(root/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n'),(root/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n'));selected=set();facts={}
 for side in ('book','tax'):
  rows=values[side]['accounts'];own={k:a for k,a in rows.items() if a.get('skr_code')==CODE}
  if not own:continue
  require(profile.get('company',{}).get('legal_status') in FORMS)
  for key,row in own.items():
   rule=rules.get(CODE,{});validate_rule(rule);require(rule.get('own_share_cost_contract')==CODE+'@1' and row['group']=='reserves' and mapping.get(key)==TARGET)
   require(all(type(row[f]) is int and -LIMIT<=row[f]<=LIMIT for f in ('opening_cents','amount_cents','closing_cents')))
   require(row['amount_cents']==-row['closing_cents'],'Unterschiedsbetrag eigener Anteile mit dem ursprünglichen Rücklagensaldo abstimmen.')
   if side=='tax':selected.add(key)
  pool=[a for k,a in rows.items() if a['group']=='reserves' and mapping.get(k)==TARGET]
  require(all(0<=sum(-a['opening_cents'] if f=='opening_cents' else a[f] for a in pool)<=LIMIT for f in ('opening_cents','amount_cents')))
  nominal=[a for k,a in rows.items() if a.get('skr_code')=='29090' and a['group']=='equity' and mapping.get(k)==NOMINAL]
  require(nominal and all(a['opening_cents']>=0 and a['closing_cents']>=0 for a in nominal))
  require(all(sum(a[f] for a in own.values())>=-sum(a[f] for a in nominal) for f in ('opening_cents','closing_cents')),'Ursprünglicher Erwerbspreis eigener Anteile darf nicht negativ sein.')
  require(all(not sum(a[f] for a in own.values()) or sum(a[f] for a in nominal)>0 for f in ('opening_cents','closing_cents')),'Mehrerwerbskosten ohne den getrennt belegten ursprünglichen Nennbetrag eigener Anteile prüfen.')
  if side=='tax':facts[DETAIL]=max(sum(a['closing_cents'] for a in own.values()),0)
 return selected,facts
