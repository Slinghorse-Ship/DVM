"""Original corporate participation classification never infers tax deductions."""
PENSION_ASSETS={'13800':{'pension_reinsurance_unoffset':'bs.ass.fixAss.fin.otherFinAss.reInsurClaim'},'13820':{'pension_reinsurance_unoffset':'bs.ass.fixAss.fin.otherFinAss.reInsurClaim'}}
CODES={'13800','13820','08040','08080','08500','09800','09000','09100'}
LEGACY_SECURITIES={'09000': {'corp_domestic': 'bs.ass.fixAss.fin.securities.shares', 'corp_foreign': 'bs.ass.fixAss.fin.securitiesOtherForeign', 'cert_corp_de': 'bs.ass.fixAss.fin.securities.partCertificate', 'cert_corp_ab': 'bs.ass.fixAss.fin.securitiesOtherForeign', 'bond_de': 'bs.ass.fixAss.fin.securities.bonds', 'bond_ab': 'bs.ass.fixAss.fin.securitiesOtherForeign', 'convert_de': 'bs.ass.fixAss.fin.securities.convertBond', 'convert_ab': 'bs.ass.fixAss.fin.securitiesOtherForeign', 'fund_de': 'bs.ass.fixAss.fin.securities.mutInvestm', 'fund_ab': 'bs.ass.fixAss.fin.securitiesOtherForeign', 'cert_de': 'bs.ass.fixAss.fin.securities.partCertificate', 'cert_ab': 'bs.ass.fixAss.fin.securitiesOtherForeign', 'warrant_de': 'bs.ass.fixAss.fin.securities.warrants', 'warrant_ab': 'bs.ass.fixAss.fin.securitiesOtherForeign', 'other_de': 'bs.ass.fixAss.fin.securities.other', 'other_ab': 'bs.ass.fixAss.fin.securitiesOtherForeign'}, '09100': {'cert_corp_de': 'bs.ass.fixAss.fin.securities.partCertificate', 'cert_corp_ab': 'bs.ass.fixAss.fin.securitiesOtherForeign', 'corp_domestic': 'bs.ass.fixAss.fin.securities.shares', 'corp_foreign': 'bs.ass.fixAss.fin.securitiesOtherForeign'}}
SECURITIES={'09000': {'corp_domestic': 'bs.ass.fixAss.fin.securities.shares', 'corp_foreign': 'bs.ass.fixAss.fin.securities.shares', 'cert_corp_de': 'bs.ass.fixAss.fin.securities.partCertificate', 'cert_corp_ab': 'bs.ass.fixAss.fin.securities.partCertificate', 'bond_de': 'bs.ass.fixAss.fin.securities.bonds', 'bond_ab': 'bs.ass.fixAss.fin.securities.bonds', 'convert_de': 'bs.ass.fixAss.fin.securities.convertBond', 'convert_ab': 'bs.ass.fixAss.fin.securities.convertBond', 'fund_de': 'bs.ass.fixAss.fin.securities.mutInvestm', 'fund_ab': 'bs.ass.fixAss.fin.securities.mutInvestm', 'cert_de': 'bs.ass.fixAss.fin.securities.partCertificate', 'cert_ab': 'bs.ass.fixAss.fin.securities.partCertificate', 'warrant_de': 'bs.ass.fixAss.fin.securities.warrants', 'warrant_ab': 'bs.ass.fixAss.fin.securities.warrants', 'other_de': 'bs.ass.fixAss.fin.securities.other', 'other_ab': 'bs.ass.fixAss.fin.securities.other'}, '09100': {'cert_corp_de': 'bs.ass.fixAss.fin.securities.partCertificate', 'cert_corp_ab': 'bs.ass.fixAss.fin.securities.partCertificate', 'corp_domestic': 'bs.ass.fixAss.fin.securities.shares', 'corp_foreign': 'bs.ass.fixAss.fin.securities.shares'}}
CORPORATE_PROFILES={'corp_domestic', 'cert_corp_de', 'corp_foreign', 'cert_corp_ab'}
ALL_PROFILES={'other_ab', 'corp_domestic', 'bond_ab', 'bond_de', 'cert_de', 'cert_ab', 'other_de', 'cert_corp_de', 'convert_ab', 'convert_de', 'warrant_ab', 'fund_de', 'fund_ab', 'warrant_de', 'corp_foreign', 'cert_corp_ab'}
LOANS={'08140': {'loan_de_le25': 'bs.ass.fixAss.fin.loansToAffil.corporations', 'loan_de_gt25': 'bs.ass.fixAss.fin.loansToAffil.corporations', 'loan_ab_le25': 'bs.ass.fixAss.fin.loansToAffil.corporations', 'loan_ab_gt25': 'bs.ass.fixAss.fin.loansToAffil.corporations'}, '08850': {'loan_de_le25': 'bs.ass.fixAss.fin.loansToParticip.corporations', 'loan_de_gt25': 'bs.ass.fixAss.fin.loansToParticip.corporations', 'loan_ab_le25': 'bs.ass.fixAss.fin.loansToParticip.corporations', 'loan_ab_gt25': 'bs.ass.fixAss.fin.loansToParticip.corporations'}, '08100': {'loan_de_le25': 'bs.ass.fixAss.fin.loansToAffil.corporations', 'loan_de_gt25': 'bs.ass.fixAss.fin.loansToAffil.corporations', 'loan_ab_le25': 'bs.ass.fixAss.fin.loansToAffil.corporations', 'loan_ab_gt25': 'bs.ass.fixAss.fin.loansToAffil.corporations', 'loan_pg': 'bs.ass.fixAss.fin.loansToAffil.partnerships.other', 'loan_sole': 'bs.ass.fixAss.fin.loansToAffil.soleProprietor'}, '08800': {'loan_de_le25': 'bs.ass.fixAss.fin.loansToParticip.corporations', 'loan_de_gt25': 'bs.ass.fixAss.fin.loansToParticip.corporations', 'loan_ab_le25': 'bs.ass.fixAss.fin.loansToParticip.corporations', 'loan_ab_gt25': 'bs.ass.fixAss.fin.loansToParticip.corporations', 'loan_pg': 'bs.ass.fixAss.fin.loansToParticip.partnerships.other', 'loan_sole': 'bs.ass.fixAss.fin.loansToParticip.soleProprietor'}}
LOANS.update({'09600': {'loan_de_le25': 'bs.ass.fixAss.fin.loansToSharehold.misc', 'loan_de_gt25': 'bs.ass.fixAss.fin.loansToSharehold.misc', 'loan_ab_le25': 'bs.ass.fixAss.fin.loansToSharehold.misc', 'loan_ab_gt25': 'bs.ass.fixAss.fin.loansToSharehold.misc', 'loan_pg': 'bs.ass.fixAss.fin.loansToSharehold.misc', 'loan_sole': 'bs.ass.fixAss.fin.loansToSharehold.misc', 'loan_person_de': 'bs.ass.fixAss.fin.loansToSharehold.misc', 'loan_person_ab': 'bs.ass.fixAss.fin.loansToSharehold.misc'}, '09610': {'loan_de_le25': 'bs.ass.fixAss.fin.loansToSharehold.gmbh', 'loan_de_gt25': 'bs.ass.fixAss.fin.loansToSharehold.gmbh', 'loan_ab_le25': 'bs.ass.fixAss.fin.loansToSharehold.gmbh', 'loan_ab_gt25': 'bs.ass.fixAss.fin.loansToSharehold.gmbh', 'loan_pg': 'bs.ass.fixAss.fin.loansToSharehold.gmbh', 'loan_sole': 'bs.ass.fixAss.fin.loansToSharehold.gmbh', 'loan_person_de': 'bs.ass.fixAss.fin.loansToSharehold.gmbh', 'loan_person_ab': 'bs.ass.fixAss.fin.loansToSharehold.gmbh'}, '09700': {'loan_de_le25': 'bs.ass.fixAss.fin.otherLoans.other', 'loan_de_gt25': 'bs.ass.fixAss.fin.otherLoans.other', 'loan_ab_le25': 'bs.ass.fixAss.fin.otherLoans.other', 'loan_ab_gt25': 'bs.ass.fixAss.fin.otherLoans.other', 'loan_pg': 'bs.ass.fixAss.fin.otherLoans.other', 'loan_sole': 'bs.ass.fixAss.fin.otherLoans.other', 'loan_person_de': 'bs.ass.fixAss.fin.otherLoans.other', 'loan_person_ab': 'bs.ass.fixAss.fin.otherLoans.other'}})
LOAN_PROFILES=('loan_de_le25', 'loan_de_gt25', 'loan_ab_le25', 'loan_ab_gt25', 'loan_pg', 'loan_sole','loan_person_de','loan_person_ab')
CODES |= set(LOANS)
ALL_PROFILES |= set(LOAN_PROFILES)
BOOK_TARGETS_R69={'silent_part': 'bs.ass.fixAss.fin.particip.silent', 'silent_loan': 'bs.ass.fixAss.fin.otherLoans.silent'}
CODES |= {'08000','08090','08200'}
CODES.add('08300');ALL_PROFILES |= set(BOOK_TARGETS_R69)
def profiles(code):return tuple(BOOK_TARGETS_R69) if code=='08300' else tuple(LOANS[code]) if code in LOANS else tuple(SECURITIES[code]) if code in SECURITIES else ('corp_domestic','corp_foreign')

def rule_targets(code):return list(dict.fromkeys((["bs.ass.fixAss.fin.securities"] if code=='09000' else [])+[target(code,p) for p in profiles(code)]))
PROFILES={'corp_domestic','corp_foreign'}
def require(ok,message='Ursprüngliche Beteiligungsart, Sitz und Beleg vollständig bestätigen.'):
 if not ok:raise ValueError(message)
CODES|=set(PENSION_ASSETS)
ALL_PROFILES.add('pension_reinsurance_unoffset')
_original_profiles=profiles
def profiles(code):return list(PENSION_ASSETS[code]) if code in PENSION_ASSETS else _original_profiles(code)

def target(code,profile):
 require(code in CODES and type(profile) is str and profile in profiles(code))
 if code in PENSION_ASSETS:return PENSION_ASSETS[code][profile]
 if code=='08300':return 'bs.ass.fixAss.fin.otherLoans.other'
 if code in LOANS:return LOANS[code][profile]
 if code in SECURITIES:return SECURITIES[code][profile]
 affiliated=code in ('08000','08090','08040','08080')
 return 'bs.ass.fixAss.fin.'+('sharesInAffil.corporations' if affiliated else 'particip.corporations')
def check(plan):
 code=plan.get('account')
 if code not in CODES:
  require('financial_profile' not in plan);return
 expected=target(code,plan.get('financial_profile'))
 require(plan.get('kind',plan.get('valuation_kind'))=='financial' and plan.get('target')==expected)
def counter_key(code,profile=None):
 require(profile is None or type(profile) is str and profile in ALL_PROFILES)
 return 'src_asset_valuation_'+code+('' if profile is None else '_'+('rein_unoffset' if profile=='pension_reinsurance_unoffset' else profile))
def counter_target(code,profile):
 require(type(profile) is str and profile in ALL_PROFILES)
 if code=='72070':
  require(profile in ('corp_domestic','corp_foreign','loan_de_le25','loan_de_gt25','loan_ab_le25','loan_ab_gt25','loan_pg','loan_sole'));return counter_target('72040' if profile in CORPORATE_PROFILES or profile.endswith('gt25') else '72000',profile)
 if profile in ('loan_pg','loan_sole','loan_person_de','loan_person_ab'):
  require(code in ('49120','68970','72000','72010'));return {'49120':'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.other','68970':'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.other','72000':'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.other','72010':'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.other'}[code]
 if profile in LOAN_PROFILES:
  high=profile.endswith('gt25');place='domestic' if profile.startswith('loan_de_') else 'foreign'
  if code==('49130' if high else '49120'):return 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.'+place+'Loan.'+('relatedParty' if high else 'unrelatedParty')
  if code=='68970':return 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.other'
  require(code==('72040' if high else '72000') or code=='72010')
  return 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.'+('receivCorp25pt.'+place if high else 'other')
 if profile in ('fund_de','fund_ab'):
  require(code in ('49120', '68970', '72000', '72010'));return {'49120': 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.investmentShares', '68970': 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.investmentShares', '72000': 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.investementShares', '72010': 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.investementShares'}[code]
 if profile not in CORPORATE_PROFILES:
  require(code in ('49120','68970','72000','72010'))
  return {'49120':'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.other','68970':'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.other','72000':'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.other','72010':'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.other'}[code]
 require(code in ('49140','68980','72040','72010'))
 prefix={'72010':'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.participCorp.','49140':'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.','68980':'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.','72040':'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.participCorp.'}[code]
 return prefix+('domesticCorp' if profile in ('corp_domestic','cert_corp_de') else 'foreignCorp')

def validate_rule(rule):
 ref=rule.get('financial_profile_contract')
 if ref is None:return
 require(type(ref) is str and ref[-2:] in ('@1','@2'));code=ref[:-2];require(code in CODES)
 if code in PENSION_ASSETS:
  require(ref==code+'@1' and rule.get('source_years')==[2025,2026] and rule.get('pension_asset_contract')=='original-unnetted-reinsurance-r74-'+code+'@1' and 'book_only' not in rule);expected=[PENSION_ASSETS[code]['pension_reinsurance_unoffset']]
 elif code=='08300':
  require(ref=='08300@1' and rule.get('financial_book_targets')==BOOK_TARGETS_R69);expected=rule_targets(code)
 elif code in ('08000','08090','08200'):
  require(ref==code+'@2');expected=rule_targets(code)
 elif ref.endswith('@2'):expected=rule_targets(code)
 elif code in LOANS:expected=rule_targets(code)
 elif code in LEGACY_SECURITIES:expected=list(dict.fromkeys((['bs.ass.fixAss.fin.securities'] if code=='09000' else [])+list(LEGACY_SECURITIES[code].values())))
 else:expected=['bs.ass.fixAss.fin.'+('sharesInAffil.corporations' if code in ('08040','08080') else 'particip.corporations'),'bs.ass.fixAss.fin.'+('sharesInAffilOtherForeign' if code in ('08040','08080') else 'participOtherForeign')]
 require(rule.get('group')=='fixed_assets' and rule.get('condition')=='cost_schedule' and rule.get('target')==expected[0] and rule.get('allowed_targets')==expected)
 require(rule.get('financial_parent_disclosures',[])==(['bs.ass.fixAss.fin.sharesInAffil.parentComp'] if code in ('08080','08090') else []))
 if code!='08300':require('financial_book_targets' not in rule)
 if 'codes' in rule:require(rule['codes']==[code])
 if code in SHAREHOLDER_FORMS:require(rule.get('reporter_legal_forms')==list(SHAREHOLDER_FORMS[code]))
 else:require('reporter_legal_forms' not in rule)


def disclosures(values,book_only=False):
 side='book' if book_only else 'tax'
 schedule=values.get('asset_schedule') if book_only or 'period' in values else (values.get('asset_tax') or {}).get('tax_schedule') or values.get('asset_schedule')
 amount=0;found=False
 items=(schedule or {}).get('items',{});require(type(items) is dict or items==[])
 for item in ({} if items==[] else items).values():
  plan=item.get('cost_plan',item.get('plan',{}))
  if plan.get('account') not in ('08080','08090'):continue
  check(plan);found=True;amount+=item['valuation'][side]['book_closing_cents']
 return {'bs.ass.fixAss.fin.sharesInAffil.parentComp':amount} if found else {}

def counter_code(event,permanent,profile,original=None,account=None):
 require(event in ('writeup','disposal','impairment') and type(permanent) is bool and type(profile) is str and profile in ALL_PROFILES)
 if original is not None:
  require(original=='72070' and event=='impairment' and account in ('08040','08080','08100','08140') and profile in profiles(account));return original
 loan=profile in LOAN_PROFILES;high=loan and profile.endswith('gt25');corp=profile in CORPORATE_PROFILES
 if event=='writeup':return '49130' if high else '49140' if corp else '49120'
 if event=='disposal':return '68980' if corp else '68970'
 return '72010' if not permanent else '72040' if high or corp else '72000'

SHAREHOLDER_FORMS={'09600':('AG','GMBH','gGMBH','UG','EG'),'09610':('GMBH','gGMBH','UG')}
def check_reporter(values,legal_status,book_only=False):
 side='book' if book_only else 'tax'
 for account in values[side]['accounts'].values():
  code=account.get('skr_code')
  if code in SHAREHOLDER_FORMS and any(account.get(k,0) for k in ('amount_cents','opening_cents','closing_cents')):
   require(type(legal_status) is str and legal_status in SHAREHOLDER_FORMS[code],
    'Ausleihung an Gesellschafter: Rechtsform der berichtenden Gesellschaft ausdrücklich bestätigen. Vereinsmitglieder und Stiftungen sind keine GmbH-Gesellschafter.')

def book_presentation(values,mapping,assets,meta):
 """Reclassify only confirmed original08300 report buckets after full cost replay."""
 if not any(a['skr_code']=='08300' for a in values['tax']['accounts'].values()):return mapping,assets
 from copy import deepcopy
 from ebilanz_cost_asset_grid import project
 result=dict(mapping);assets=deepcopy(assets);selected={}
 items=values['asset_schedule']['items'];items={} if items==[] else items
 def chain(leaf):
  seen={leaf};pending=list(meta['asset_parents'].get(leaf,[]))
  while pending:
   parent=pending.pop()
   if parent in seen:continue
   seen.add(parent);pending.extend(meta['asset_parents'].get(parent,[]))
  require('bs.ass.fixAss' in seen);return seen
 for item in items.values():
  plan=item.get('plan') if 'period' in values else item.get('cost_plan')
  if not isinstance(plan,dict) or plan.get('account')!='08300':continue
  check(plan);profile=plan.get('financial_profile');require(profile in BOOK_TARGETS_R69)
  key=item['fixed_account'] if 'period' in values else item['account'];old=target('08300',profile);new=BOOK_TARGETS_R69[profile]
  require(mapping.get(key)==old and (key not in selected or selected[key]==new),'Typisch stille Beteiligung: unterschiedliche handelsrechtliche Vertragsarten auf getrennten Originalkonten nachweisen.')
  selected[key]=new;result[key]=new
  measures=project(item['valuation'],'book',meta['version'])['columns']
  for leaf,sign in ((old,-1),(new,1)):
   for position in chain(leaf):
    row=assets['rows'].setdefault(position,dict.fromkeys(measures,0))
    require(set(row)==set(measures))
    for column,n in measures.items():row[column]+=sign*n
 require(all(n>=0 for row in assets['rows'].values() for n in row.values()),'Typisch stille Beteiligung: ursprüngliche Anlagenbewegungen vollständig abstimmen.')
 return result,assets
