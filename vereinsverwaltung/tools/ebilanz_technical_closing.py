"""Actual cleared original technical accounts; never invent or replace a balance."""
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
CONTRACTS={'14980':('other_assets','bs.ass.currAss.receiv.other.other'),'39500':('liabilities','bs.eqLiab.liab.other.other')}
def require(ok,message='Technisches Originalkonto muss im tatsächlichen Eröffnungs- und Abschlussbestand vollständig übergeleitet sein; Originalbuchungen berichtigen, keinen Saldo ersetzen.'):
 if not ok:raise ValueError(message)
SPECIAL={'29840': ('deferred', 'bs.eqLiab.otherSpecRes.other.other'), '29860': ('deferred', 'bs.eqLiab.otherSpecRes.other.other'), '29870': ('deferred', 'bs.eqLiab.otherSpecRes.subsidies')}

def validate_rule(rule):
 special=rule.get('special_post_contract')
 if special is not None:
  require(type(special) is str and special.endswith('@1') and special[:-2] in SPECIAL,'Originalen Sonderpostenvertrag prüfen.');c=special[:-2];g,t=SPECIAL[c]
  require(rule.get('group')==g and rule.get('condition')=='detail_choice' and rule.get('target')==t and rule.get('allowed_targets')==[t] and not rule.get('disclosures') and 'book_only' not in rule and 'technical_closing_contract' not in rule,'Originalen Sonderpostenvertrag prüfen.')
  if 'codes' in rule:require(rule['codes']==[c])

 ref=rule.get('technical_closing_contract')
 if ref is None:return
 require(type(ref) is str and ref.endswith('@1') and ref[:-2] in CONTRACTS);c=ref[:-2];g,t=CONTRACTS[c]
 require(rule.get('group')==g and rule.get('condition')=='detail_choice' and rule.get('target')==t and rule.get('allowed_targets')==[t] and not rule.get('disclosures') and 'book_only' not in rule)
 if 'codes' in rule:require(rule['codes']==[c])
@lru_cache(maxsize=4)
def verified(year,raw,catalog):
 from ebilanz_mapping import checked_source
 _,rules,_=checked_source(year,raw,catalog)
 for c,rule in rules.items():
  validate_rule(rule)
  if 'technical_closing_contract' in rule:require(rule['technical_closing_contract']==c+'@1')
  if 'special_post_contract' in rule:require(rule['special_post_contract']==c+'@1')
 return rules
def rules(year):return verified(year,(ROOT/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n'),(ROOT/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n'))
def check_rows(year,rows):
 selected={k:a for k,a in rows.items() if a.get('skr_code') in CONTRACTS}
 if not selected:return
 source=rules(year)
 for row in selected.values():
  c=row['skr_code'];require(source.get(c,{}).get('technical_closing_contract')==c+'@1' and row['group']==CONTRACTS[c][0])
  require(all(type(row.get(f)) is int and row[f]==0 for f in ('opening_cents','closing_cents','amount_cents')))
  require(row['debit_cents']==row['credit_cents'])
def check_special(year,rows,mapping):
 selected={k:a for k,a in rows.items() if a.get('skr_code') in SPECIAL}
 if not selected:return
 source=rules(year)
 for key,row in selected.items():
  c=row['skr_code'];require(source.get(c,{}).get('special_post_contract')==c+'@1' and row['group']==SPECIAL[c][0],'Originalen Sonderposten und Jahresquelle prüfen.')
  require(type(row.get('amount_cents')) is int and row['amount_cents']>=0,'Sonderposten benötigt einen tatsächlichen nichtnegativen Bestand.')
  if c in ('29840','29860'):require(row['opening_cents']<=0 and row['amount_cents']<=-row['opening_cents'],'Sonderposten mit Rücklageanteil: belegten Altbestand fortführen oder auflösen; keine neue Zuführung aus Kontonummer ableiten.')
  if key in mapping:require(mapping[key]==SPECIAL[c][1],'Originalen Sonderposten ausdrücklich zuordnen.')

def check_values(values,mapping):
 for section in ('book','tax'):
  rows=values[section]['accounts'];check_rows(values['year'],rows);check_special(values['year'],rows,mapping)
  for k,row in rows.items():
   c=row.get('skr_code')
   if c in CONTRACTS and k in mapping:require(mapping[k]==CONTRACTS[c][1])
