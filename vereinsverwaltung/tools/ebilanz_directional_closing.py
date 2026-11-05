"""Exact original clearing accounts: signed source remains, BS side follows debt/claim."""
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
ASS='bs.ass.currAss.receiv.other.';LIAB='bs.eqLiab.liab.other.';BANK='bs.ass.currAss.cashEquiv.bank'
CONTRACTS={
 '14970':('other_assets', ['bs.ass.currAss.receiv.other.other'], ['bs.eqLiab.liab.advPaym']),
 '36950':('liabilities', ['bs.ass.currAss.inventory.advPaymPaid', 'bs.ass.currAss.receiv.other.other'], ['bs.eqLiab.liab.trade.genOther']),
 '13700':('other_assets',[ASS+'other'],[LIAB+'other']),
 '13720':('other_assets',[BANK,ASS+'other'],[LIAB+'other']),
 '13740':('other_assets',[ASS+'other'],[LIAB+'other']),
 '13750':('other_assets',[ASS+'other'],[LIAB+'other']),
 '14950':('other_assets',[ASS+'vat'],[LIAB+'theroffTax.vat']),
 '18950':('liabilities',[BANK],['bs.eqLiab.liab.bank.other']),
 '36300':('liabilities',[ASS+'other'],[LIAB+'other']),
 '37900':('liabilities',[ASS+'employees',ASS+'socInsur',ASS+'otherTaxRec'],[LIAB+'employees',LIAB+'thereoffSocSec',LIAB+'theroffTax.otherTaxes']),
}
def require(ok,message='Verrechnungskonto: Originalquelle, Kontenbereich und Forderungs-/Verbindlichkeitsausweis abstimmen.'):
 if not ok:raise ValueError(message)
def validate_rule(rule):
 if 'directional_balance' not in rule:return
 value=rule['directional_balance'];require(type(value) is dict and set(value)=={'debit_targets','credit_targets'})
 pair=(rule['group'],value['debit_targets'],value['credit_targets']);require(pair in CONTRACTS.values())
 targets=value['debit_targets']+value['credit_targets'];require(rule.get('condition')=='detail_choice' and rule.get('allowed_targets')==targets and rule.get('target') in targets and 'disclosures' not in rule)
@lru_cache(maxsize=4)
def verified(year,registry,catalog):
 from ebilanz_mapping import checked_source
 _,rules,_=checked_source(year,registry,catalog)
 for code,rule in rules.items():
  if 'directional_balance' not in rule:continue
  validate_rule(rule);value=rule['directional_balance'];require(CONTRACTS.get(code)==(rule['group'],value['debit_targets'],value['credit_targets']))
 return rules
def rule(year,row):
 code=row.get('skr_code')
 if year not in (2025,2026) or code not in CONTRACTS:return None
 source=verified(year,(ROOT/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n'),(ROOT/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n')).get(code)
 require(source and 'directional_balance' in source and row['group']==source['group'])
 return source
def presentation(year,row):
 source=rule(year,row)
 if source is None:return None
 value=row['closing_cents'];require(type(value) is int and abs(value)<=100000000000)
 group='other_assets' if value>0 else 'liabilities' if value<0 else row['group']
 return row|{'group':group,'amount_cents':abs(value)}
def choices(year,row):
 source=rule(year,row)
 if source is None:return None
 value=row['closing_cents'];proof=source['directional_balance']
 return proof['debit_targets'] if value>0 else proof['credit_targets'] if value<0 else source['allowed_targets']
def check_values(values,mapping):
 selected={}
 for section in ('book','tax'):
  for key,row in values[section]['accounts'].items():
   view=presentation(values['year'],row)
   if view is None:continue
   require(row['amount_cents']==(row['closing_cents'] if row['group']=='other_assets' else -row['closing_cents']))
   if section=='tax':
    if key in mapping:require(mapping[key] in choices(values['year'],row),'Verrechnungskonto: Forderung und Verbindlichkeit wurden auf die falsche Bilanzseite zugeordnet.')
    selected[key]=view
 return selected
