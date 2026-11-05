"""Five original deferred-tax contracts: original HGB books and confirmed tax elimination."""
from pathlib import Path
CONTRACTS={
 'deferred-active@1':('19500','other_assets','bs.ass.defTax'),
 'deferred-provision@1':('30600','provisions','bs.eqLiab.accruals.tax.defTax'),
 'deferred-passive@1':('30650','deferred','bs.eqLiab.defTax'),
 'deferred-expense@1':('76450','expense','is.netIncome.tax.deferred.release'),
 'deferred-income@1':('76490','expense','is.netIncome.tax.deferred.addition')}
CONTRACTS.update({'imminent-loss@1': ('30920', 'provisions', 'bs.eqLiab.accruals.other.imminentLoss.other'), 'legacy-expenditure@1': ('30980', 'provisions', 'bs.eqLiab.accruals.other.expenditure')})
# Exact original commercial equity/debt classification; fiscal position is separately reviewed.
R64_CONTRACTS={'equity-participation-r64-29790@1': ('29790', 'equity', 'bs.eqLiab.equity.profSharing'), 'atypical-debt-r64-35300@1': ('35300', 'liabilities', 'bs.eqLiab.liab.other.silentPartner.atyp'), 'atypical-debt-r64-35310@1': ('35310', 'liabilities', 'bs.eqLiab.liab.other.silentPartner.atyp'), 'atypical-debt-r64-35320@1': ('35320', 'liabilities', 'bs.eqLiab.liab.other.silentPartner.atyp'), 'atypical-debt-r64-35330@1': ('35330', 'liabilities', 'bs.eqLiab.liab.other.silentPartner.atyp')}
R64_DISCLOSURES={'atypical-debt-r64-35310@1': ['bs.eqLiab.liab.other.upTo1year'], 'atypical-debt-r64-35320@1': ['bs.eqLiab.liab.other.above1year'], 'atypical-debt-r64-35330@1': ['bs.eqLiab.liab.other.above1year']}
CONTRACTS.update(R64_CONTRACTS)
INCOME_R67='atypical-income-r67-73980@1'
INCOME_TARGETS_R67=['is.netIncome.incomeSharing.gain.silentAtypical','is.netIncome.incomeSharing.loss.atypPart']
CONTRACTS[INCOME_R67]=('73980','expense',INCOME_TARGETS_R67[0])
SUPPORTED={'expense':set(INCOME_TARGETS_R67)}
for code,group,target in CONTRACTS.values():SUPPORTED.setdefault(group,set()).add(target)
PARTNERSHIP_R68={'partnership-income-r68-70030@1': ('70030', 'revenue', 'is.netIncome.regular.fin.commPart', 'is.netIncome.regular.fin.netParticipation.earnings.silentPart', []), 'partnership-income-r68-70040@1': ('70040', 'revenue', 'is.netIncome.regular.fin.commPart', 'is.netIncome.regular.fin.netParticipation.earnings.partnerships', ['is.netIncome.regular.fin.netParticipation.earnings.groupComp']), 'partnership-income-r68-70080@1': ('70080', 'revenue', 'is.netIncome.regular.fin.commPart', 'is.netIncome.regular.fin.netParticipation.earnings.partnerships', []), 'partnership-income-r68-72080@1': ('72080', 'expense', 'is.netIncome.regular.fin.lossCommPart', 'is.netIncome.regular.fin.netParticipation.earnings.partnerships', [])}
for code,group,tax,book,disc in PARTNERSHIP_R68.values():SUPPORTED.setdefault(group,set()).update((tax,book))

def require(ok,message='Latente Steuern: ursprüngliche Handelsbilanz und bestätigte steuerliche Ausbuchung vollständig abstimmen.'):
 if not ok:raise ValueError(message)
def validate_rule(rule):
 if 'substance_original_contract' in rule:
  from ebilanz_substance_original import validate_rule
  validate_rule(rule);return
 if 'basis_reduction_6b_contract' in rule:
  from ebilanz_basis_reduction_6b import validate_rule as reduction_rule
  reduction_rule(rule);return
 if 'basis_reduction_7g_contract' in rule:
  from ebilanz_basis_reduction_7g import validate_rule as reduction_rule
  reduction_rule(rule);return
 if 'special_tax_7b_contract' in rule:
  from ebilanz_special_tax_7b import validate_rule
  validate_rule(rule);return
 if 'special_tax_7g_contract' in rule:
  from ebilanz_special_tax_7g import validate_rule as special_rule
  special_rule(rule);return
 if 'pension_offset_contract' in rule:
  from ebilanz_pension_offsets import validate_rule as pension_rule
  pension_rule(rule);return
 if 'pool_original_contract' in rule:
  from ebilanz_pool_original_values import validate_rule as pool_rule
  pool_rule(rule);return
 require(rule.get('condition')!='pool_original')
 if 'own_intangible_contract' in rule:
  from ebilanz_own_intangible_books import validate_rule as own_rule
  own_rule(rule);return
 require(rule.get('condition')!='own_intangible_original')
 ref=rule.get('partnership_income_contract')
 if ref is not None:
  require(ref in PARTNERSHIP_R68,'Beteiligungsertrag: ursprünglichen Vertrag und getrennte Handels-/Steuerposition prüfen.')
  code,group,tax,book,disc=PARTNERSHIP_R68[ref]
  require(rule.get('group')==group and rule.get('condition')=='detail_choice' and rule.get('target')==tax and rule.get('allowed_targets')==[tax] and rule.get('partnership_book_target')==book and rule.get('partnership_book_disclosures',[])==disc and not rule.get('disclosures') and 'book_only' not in rule and 'commercial_contract' not in rule)
  if 'codes' in rule:require(rule['codes']==[code])
  return
 require('partnership_book_target' not in rule and 'partnership_book_disclosures' not in rule)
 ref=rule.get('commercial_contract')
 if ref is None:
  require('book_only' not in rule);return
 require(ref in CONTRACTS and rule.get('book_only') is True)
 code,group,target=CONTRACTS[ref]
 require(rule.get('group')==group and rule.get('condition')=='detail_choice' and rule.get('target')==target and rule.get('allowed_targets')==(INCOME_TARGETS_R67 if ref==INCOME_R67 else [target]) and rule.get('disclosures',[])==R64_DISCLOSURES.get(ref,[]))
 if ref==INCOME_R67:require(rule.get('commercial_income_direction')=={'debit_target':INCOME_TARGETS_R67[0],'credit_target':INCOME_TARGETS_R67[1]})
 else:require('commercial_income_direction' not in rule)
 if 'codes' in rule:require(rule['codes']==[code])
def check_values(values,mapping,*,book_only=False):
 from ebilanz_mapping import checked_source
 root=Path(__file__).resolve().parent.parent;year=values['year']
 _,rules,_=checked_source(year,(root/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n'),(root/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n'))
 out=set()
 for key,row in values['tax']['accounts'].items():
  rule=rules.get(row.get('skr_code'),{});validate_rule(rule)
  if 'commercial_contract' not in rule:continue
  require(row['group']==rule['group'] and values['book']['accounts'][key]['skr_code']==row['skr_code'])
  if rule['commercial_contract']=='legacy-expenditure@1':
   require(values['book']['accounts'][key]['credit_cents']==0 and values['book']['accounts'][key]['opening_cents']<=0, 'Aufwandsrückstellung nach altem HGB: Nur belegten Altbestand beibehalten oder auflösen; keine neue Zuführung.')
  if rule['commercial_contract'] in R64_CONTRACTS:
   require(values['book']['accounts'][key]['amount_cents']>=0, 'Eigenkapitalcharakter oder tatsächliche Schuld vor getrenntem Ausweis prüfen.')
  if rule['commercial_contract']==INCOME_R67:
   original=values['book']['accounts'][key];n=original['amount_cents'];require(type(n) is int)
   if any(original[f] for f in ('debit_cents','credit_cents')):require(mapping.get(key) in ([INCOME_TARGETS_R67[0]] if n>0 else [INCOME_TARGETS_R67[1]] if n<0 else INCOME_TARGETS_R67),'Atypisch stille Gewinnabführung oder Verlustausgleich nach tatsächlichem Originalsaldovorzeichen zuordnen.')
  if book_only:
   require(values['book']==values['tax'] and not values['opening_adjustments'] and not values['current_adjustments'])
   continue
  require(all(type(row[f]) is int and row[f]==0 for f in ('opening_cents','closing_cents','amount_cents')), ('Handelsbilanzposition in der tatsächlich bestätigten Steuerüberleitung auf den eigenständig bestimmten steuerlichen Konten ausweisen: ' if rule['commercial_contract'] in R64_CONTRACTS or rule['commercial_contract']==INCOME_R67 else 'Latente Steuern zuerst in der bestätigten steuerlichen Überleitung vollständig ausbuchen: ')+str(row['skr_code']))
  out.add(key)
 return out

def partnership_mapping(values,mapping,*,book_only=False):
 """Resolve only the section's taxonomy position; retain all original account values."""
 from ebilanz_mapping import checked_source
 root=Path(__file__).resolve().parent.parent;year=values['year']
 _,rules,_=checked_source(year,(root/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n'),(root/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n'))
 result=dict(mapping);disclosures={}
 for key,row in values['tax']['accounts'].items():
  rule=rules.get(row.get('skr_code'),{});validate_rule(rule)
  if 'partnership_income_contract' not in rule:continue
  code,group,tax,book,disc=PARTNERSHIP_R68[rule['partnership_income_contract']]
  for side in ('book','tax'):
   original=values[side]['accounts'][key]
   require(original['skr_code']==code and original['group']==group and type(original['amount_cents']) is int and original['amount_cents']>=0,'Beteiligungsertrag: tatsächlichen Gewinn-/Verlustanteil gesondert zuordnen; Gegenbetrag unabhängig umbuchen.')
  active=any(row[f] or values['book']['accounts'][key][f] for f in ('opening_cents','debit_cents','credit_cents','amount_cents'))
  if key not in result:
   require(not active,'Beteiligungsertrag: Partnervertrag, Gewinnfeststellung und steuerliche Einordnung ausdrücklich bestätigen.')
   continue
  require(result[key]==tax,'Beteiligungsertrag: bestätigte steuerliche Mitunternehmerposition auswählen.')
  if book_only:
   require(values['book']==values['tax'] and not values['opening_adjustments'] and not values['current_adjustments'])
   result[key]=book
   for target in disc:disclosures[target]=disclosures.get(target,0)+row['amount_cents']
 return result,disclosures
