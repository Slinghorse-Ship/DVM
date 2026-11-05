"""Original signed offsets, never an inferred tax deduction or fabricated zero."""
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];LIMIT=100000000000
INVENTORY='bs.ass.currAss.inventory.advPaymReceived'
BASE='is.netIncome.regular.operatingTC.grossTradingProfit.materialServices.material.'
MATERIAL=[BASE+'rawMatConsSup.'+s for s in ('reducedRateVAT','generalRateVAT','noDeductVAT','intraEU','misc','unknownVAT')]+[BASE+'purchased.'+s for s in ('reducedRateVAT','generalRateVAT','withoutVAT','intraEU','misc','unknownVAT')]+[BASE+'misc']
CONTRACTS={'11900@1':('11900','inventory',[INVENTORY]),'58600@1':('58600','expense',MATERIAL)}
def require(ok,message='Originales Gegenkonto: Vorzeichen, zugehörige Grundkonten und tatsächliche Buch-/Steuerwerte getrennt abstimmen.'):
 if not ok:raise ValueError(message)
def validate_rule(rule):
 ref=rule.get('original_offset_contract')
 if ref is None:return
 require(ref in CONTRACTS);code,group,targets=CONTRACTS[ref]
 require(rule['group']==group and rule.get('condition')=='detail_choice' and rule.get('allowed_targets')==targets and rule['target'] in targets and not rule.get('disclosures') and 'book_only' not in rule)
 if 'codes' in rule:require(rule['codes']==[code])
@lru_cache(maxsize=4)
def verified(year,raw,catalog):
 from ebilanz_mapping import checked_source
 _,rules,_=checked_source(year,raw,catalog)
 for code,rule in rules.items():
  validate_rule(rule)
  if 'original_offset_contract' in rule:require(rule['original_offset_contract']==code+'@1' and code in ('11900','58600'))
 return rules
def rules(year):return verified(year,(ROOT/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n'),(ROOT/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n'))
def is_inventory_contra(year,row):
 if row.get('skr_code')!='11900' or year not in (2025,2026):return False
 rule=rules(year).get('11900',{});return rule.get('original_offset_contract')=='11900@1' and row.get('group')=='inventory' and all(type(row.get(f)) is int and row[f]<=0 for f in ('opening_cents','closing_cents'))
def gross_inventory(source,row):
 rule=source.get(row.get('skr_code'),{});targets=rule.get('allowed_targets',[rule.get('target','')])
 return row['group']=='inventory' and row['skr_code']!='11900' and rule.get('group')=='inventory' and targets and all(t.startswith('bs.ass.currAss.inventory.') and t!='bs.ass.currAss.inventory.advPaymPaid' for t in targets)
def check_rows(year,rows):
 chosen={k:a for k,a in rows.items() if a.get('skr_code') in ('11900','58600')}
 if not chosen:return set()
 source=rules(year);adv={k:a for k,a in chosen.items() if a['skr_code']=='11900'}
 for row in chosen.values():
  require(source.get(row['skr_code'],{}).get('original_offset_contract')==row['skr_code']+'@1')
  if row['skr_code']=='11900':require(is_inventory_contra(year,row))
  else:require(row['group']=='expense' and row['opening_cents']==0 and row['closing_cents']<=0)
 for field in ('opening_cents','closing_cents'):
  advance=sum(a[field] for a in adv.values());gross=sum(a[field] for a in rows.values() if gross_inventory(source,a));require(0<=gross+advance<=LIMIT,'Erhaltene Anzahlungen überschreiten die tatsächlich vorhandenen zugehörigen Vorräte. Gesonderten Verbindlichkeitsausweis prüfen.')
 if any(a['skr_code']=='58600' for a in chosen.values()):
  total=sum(a['closing_cents'] for a in rows.values() if a['group']=='expense' and '50000'<=a['skr_code']<='50999')+sum(a['closing_cents'] for a in chosen.values() if a['skr_code']=='58600');require(0<=total<=LIMIT,'Verrechnete Stoffkosten überschreiten die tatsächlichen Grundkosten auf 50000–50999.')
 return set(adv)
def check_values(values,mapping):
 keys=set()
 for section in ('book','tax'):
  rows=values[section]['accounts'];keys|=check_rows(values['year'],rows)
  for key,row in rows.items():
   if row['skr_code']=='11900' and any(row[f] for f in ('opening_cents','debit_cents','credit_cents')):require(mapping.get(key)==INVENTORY)
   if row['skr_code']!='58600':continue
   if not row['amount_cents'] and key not in mapping:continue
   target=mapping.get(key);require(target in MATERIAL)
   if row['amount_cents']<0:
    originals=[a for k,a in rows.items() if a['group']=='expense' and '50000'<=a['skr_code']<='50999' and mapping.get(k)==target]
    contra=sum(a['amount_cents'] for k,a in rows.items() if a['skr_code']=='58600' and mapping.get(k)==target)
    require(originals and 0<=sum(a['amount_cents'] for a in originals)+contra<=LIMIT,'Stoffkosten-Gegenkonto muss dieselbe belegte Materialposition wie die zugehörigen Grundkosten mindern.')
 return keys
