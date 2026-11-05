"""Exact original debtor/creditor split counters preserve signed source values."""
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];LIMIT=100000000000
TRADE='bs.ass.currAss.receiv.trade.'
LIAB='bs.eqLiab.liab.'
CONTRACTS={
 '12580':('receivables','12000',[TRADE+s for s in ('other','shareholders','misc')],[('13000','14969')]),
 '12590':('receivables','12000',[TRADE+s for s in ('other','shareholders','misc')],[('12210','12299'),('12400','12459'),('12500','12579'),('12700','12759'),('12900','12979')]),
 '32490':('liabilities','32100',[LIAB+'bank.other'],[('31500','31839')]),
 '33490':('liabilities','33000',[LIAB+'trade.'+s for s in ('genOther','shareholders','misc')],[('33310','33399'),('33400','33439'),('34200','34239'),('34700','34739')]),
 '35990':('liabilities','35700',[LIAB+'other.other'],[('35000','35699'),('36400','36539')]),
}
def require(ok,message='Originales Aufteilungsgegenkonto: tatsächliches Grundkonto, aufgegliederte Bestände und unveränderte Buch-/Steuersalden getrennt abstimmen.'):
 if not ok:raise ValueError(message)
def validate_rule(rule):
 ref=rule.get('original_reclassification_contract')
 if ref is None:return
 require(type(ref) is str and ref.endswith('@1') and ref[:-2] in CONTRACTS);c=ref[:-2];g,primary,targets,ranges=CONTRACTS[c]
 require(rule.get('group')==g and rule.get('condition')=='detail_choice' and rule.get('target') in targets and rule.get('allowed_targets')==targets and not rule.get('disclosures') and 'book_only' not in rule)
 if 'codes' in rule:require(rule['codes']==[c])
@lru_cache(maxsize=4)
def verified(year,raw,catalog):
 from ebilanz_mapping import checked_source
 _,rules,_=checked_source(year,raw,catalog)
 for c,rule in rules.items():
  validate_rule(rule)
  if 'original_reclassification_contract' in rule:require(rule['original_reclassification_contract']==c+'@1')
 return rules
def rules(year):return verified(year,(ROOT/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n'),(ROOT/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n'))
def amount(row,field):return row[field]*(1 if row['group']=='receivables' else -1)
def is_contra(year,row):
 c=row.get('skr_code')
 if c not in CONTRACTS or year not in (2025,2026):return False
 g,_,_,_=CONTRACTS[c];return rules(year).get(c,{}).get('original_reclassification_contract')==c+'@1' and row.get('group')==g and all(type(row.get(f)) is int and amount(row,f)<=0 for f in ('opening_cents','closing_cents'))
def split(c,row,source):
 g,primary,targets,ranges=CONTRACTS[c];code=row.get('skr_code','');rule=source.get(code,{})
 if not any(lo<=code<=hi for lo,hi in ranges) or code in CONTRACTS or rule.get('group')!=row['group']:return False
 if c=='12580':return row['group']=='other_assets' and any(t.startswith('bs.ass.currAss.receiv.other.') for t in rule.get('allowed_targets',[rule.get('target','')]))
 return row['group']==g
def check_rows(year,rows):
 chosen={k:a for k,a in rows.items() if a.get('skr_code') in CONTRACTS}
 if not chosen:return set()
 source=rules(year)
 for k,row in chosen.items():
  c=row['skr_code'];g,primary,targets,ranges=CONTRACTS[c];require(is_contra(year,row))
  for field in ('opening_cents','closing_cents'):
   contra=sum(amount(a,field) for a in chosen.values() if a['skr_code']==c)
   originals=[a for a in rows.values() if a['skr_code']==primary and a['group']==g]
   gross=sum(amount(a,field) for a in originals);parts=[a for a in rows.values() if split(c,a,source)]
   allocated=sum(a[field]*(1 if a['group'] in ('receivables','other_assets') else -1) for a in parts)
   require(0<=gross+contra<=LIMIT and 0<=allocated+contra<=LIMIT and (not contra or originals and parts))
 return set(chosen)
def check_values(values,mapping):
 keys=set()
 for section in ('book','tax'):
  rows=values[section]['accounts'];keys|=check_rows(values['year'],rows)
  for k,a in rows.items():
   c=a.get('skr_code')
   if c not in CONTRACTS or not any(a[f] for f in ('opening_cents','debit_cents','credit_cents')):continue
   g,primary,targets,_=CONTRACTS[c];target=mapping.get(k);require(target in targets)
   for field in ('opening_cents','closing_cents'):
    contra=sum(amount(r,field) for key,r in rows.items() if r['skr_code']==c and mapping.get(key)==target)
    gross=sum(amount(r,field) for key,r in rows.items() if r['skr_code']==primary and r['group']==g and mapping.get(key)==target)
    require(0<=gross+contra<=LIMIT,'Aufteilungsgegenkonto muss dieselbe bestätigte Originalposition des tatsächlichen Grundkontos mindern.')
 return keys
