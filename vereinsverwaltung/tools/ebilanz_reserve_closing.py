"""Exact signed original transition reserves remain in their nonnegative reserve pool."""
from pathlib import Path
OTHER='bs.eqLiab.equity.revenueRes.other'
CODES={'29620','29630','29640','29650','29660','29670','29680','29690'}
LIMIT=100000000000

def require(ok,message='Rücklagen: ursprüngliche erfolgsneutrale Verrechnung und nichtnegativen Gesamtbestand der anderen Gewinnrücklagen abstimmen.'):
 if not ok:raise ValueError(message)

def validate_rule(rule):
 if 'reserve_pool' not in rule:return
 require(rule['reserve_pool']=='other@1' and rule.get('group')=='reserves' and rule.get('target')==OTHER and rule.get('condition')=='detail_choice' and rule.get('allowed_targets')==[OTHER])
 require(rule.get('disclosures',[]) in ([],[OTHER+'.AktG58_2a']))
 if 'codes' in rule:require(set(rule['codes'])<=CODES and (rule.get('disclosures',[])!=[OTHER+'.AktG58_2a'] or rule['codes']==['29620']))

def check_values(values,mapping):
 from ebilanz_mapping import checked_source
 root=Path(__file__).resolve().parent.parent;year=values['year']
 _,rules,_=checked_source(year,(root/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n'),(root/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n'))
 selected=set()
 for side in ('book','tax'):
  pool={'opening_cents':0,'amount_cents':0};has=False
  for key,row in values[side]['accounts'].items():
   rule=rules.get(row.get('skr_code'),{});validate_rule(rule)
   if 'reserve_pool' in rule:
    require(row['skr_code'] in CODES and row['group']=='reserves');has=True
    if side=='tax':selected.add(key)
    if any(row[f] for f in ('opening_cents','debit_cents','credit_cents')):require(mapping.get(key)==OTHER,'Ursprüngliche Gewinnrücklagen ausdrücklich auf ihren bestätigten Gesamtbestand zuordnen.')
   if row['group']=='reserves' and mapping.get(key)==OTHER:
    for field in pool:
     require(type(row[field]) is int and abs(row[field])<=LIMIT)
     pool[field]+=(-row[field] if field=='opening_cents' else row[field])
  if has:require(all(0<=n<=LIMIT for n in pool.values()))
 return selected
