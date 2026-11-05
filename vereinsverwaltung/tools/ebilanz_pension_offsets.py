"""Individually evidenced protected bank deposits: HGB net and independently reviewed fiscal gross."""
from copy import deepcopy
LIMIT=100_000_000_000
CODES=('13810','13830','30090','30770','71440','71450','73640','73650')
PAIRS={'13810':'30090','13830':'30770'}
GROUPS=dict(zip(CODES,('other_assets','other_assets','provisions','provisions','revenue','revenue','expense','expense')))
SURPLUS='bs.ass.SurplusFromOffsetting'
PENSION='bs.eqLiab.accruals.pensions.direct'
OTHER='bs.eqLiab.accruals.other.other'
INTEREST='is.netIncome.regular.fin.netInterest.expenses.offsetting'
FIELDS=('opening_cents','debit_cents','credit_cents','closing_cents','amount_cents')
def require(ok):
 if not ok:raise ValueError('Pensionsverrechnung: einzelne Vereinbarung, geschütztes Bankvermögen, Originalnachweise und unabhängige steuerliche Bruttowerte prüfen.')
def target(code):return SURPLUS if code in PAIRS else PENSION if code=='30090' else OTHER if code=='30770' else INTEREST
def validate_rule(rule):
 ref=rule.get('pension_offset_contract')
 if ref is None:return
 code=ref[:5] if type(ref) is str else ''
 require(code in CODES and ref==code+'@1' and rule.get('group')==GROUPS[code] and rule.get('condition')=='detail_choice' and rule.get('target')==target(code) and rule.get('allowed_targets')==[target(code)] and rule.get('book_only') is True and not rule.get('disclosures') and 'commercial_contract' not in rule)
 if 'codes' in rule:require(rule['codes']==[code])
def active(rows):return {k:a for k,a in rows.items() if a.get('skr_code') in CODES and any(type(a.get(f)) is not int or a[f]!=0 for f in FIELDS)}
def row(rows,key):
 require(type(key) is str and key in rows);a=rows[key]
 require(all(type(a.get(f)) is int and abs(a[f])<=LIMIT for f in FIELDS) and a['debit_cents']>=0 and a['credit_cents']>=0 and a['closing_cents']==a['opening_cents']+a['debit_cents']-a['credit_cents'])
 require(a['amount_cents']==(-a['closing_cents'] if a['group'] in ('provisions','revenue') else a['closing_cents']));return a
def document(ref):
 import re
 require(type(ref) is dict and list(ref)==['document_id','revision','sha256'] and type(ref['document_id']) is str and re.fullmatch('[a-f0-9]{32}',ref['document_id']) and type(ref['revision']) is int and ref['revision']>0 and type(ref['sha256']) is str and re.fullmatch('[a-f0-9]{64}',ref['sha256']))
def bind(source,book,fiscal=True):
 require(type(source) is dict and list(source)==['schema','year','journal_sha256','pairs','tax_positions','book_document','tax_document','book_reviewed','tax_reviewed'] and type(source['schema']) is int and source['schema']==1 and type(source['year']) is int and source['year'] in (2025,2026) and source['book_reviewed'] is True and source['tax_reviewed'] is fiscal and type(source['pairs']) is list and 1<=len(source['pairs'])<=20)
 document(source['book_document'])
 if fiscal:document(source['tax_document']);require(type(source['tax_positions']) is dict)
 else:require(source['tax_document'] is None and source['tax_positions'] in ([],{}))
 rows=book['accounts'];act=active(rows);require(bool(act));covered=set();original={};facts={};offset=0
 for pair in source['pairs']:
  require(type(pair) is dict and list(pair)==['asset','provision','movements','obligation_kind','exclusive_bank_deposit','creditor_protected','sole_purpose','netting_reviewed'] and type(pair['movements']) is list and all(pair[f] is True for f in ('exclusive_bank_deposit','creditor_protected','sole_purpose','netting_reviewed')))
  asset=row(rows,pair['asset']);provision=row(rows,pair['provision']);require(asset['skr_code'] in PAIRS and PAIRS[asset['skr_code']]==provision['skr_code'] and pair['obligation_kind']==('direct_pension' if asset['skr_code']=='13810' else 'comparable_long_term') and asset['group']=='other_assets' and provision['group']=='provisions' and asset['opening_cents']>=0 and asset['closing_cents']>=0 and provision['opening_cents']<=0 and provision['closing_cents']<=0)
  keys=[pair['asset'],pair['provision']];expense=income=0
  for key in pair['movements']:
   a=row(rows,key);code=a['skr_code'];require(code in CODES[4:] and a['group']==GROUPS[code] and a['opening_cents']==0 and a['amount_cents']>=0)
   if a['group']=='revenue':income+=a['amount_cents']
   else:expense+=a['amount_cents']
   keys.append(key)
  for key in keys:require(key not in covered);covered.add(key);original[key]=deepcopy(row(rows,key))
  net=asset['amount_cents']-provision['amount_cents'];offset+=min(asset['amount_cents'],provision['amount_cents'])
  for t,n in ((SURPLUS,max(0,net)),(target(provision['skr_code']),max(0,-net)),(INTEREST,expense-income)):facts[t]=facts.get(t,0)+n
 require(not set(act)-covered)
 if fiscal:require(set(source['tax_positions'])==covered)
 targets=set()
 for key,position in (source['tax_positions'].items() if fiscal else []):
  require(type(position) is dict and list(position)==['account','opening_cents','closing_cents'] and all(type(position[f]) is int and abs(position[f])<=LIMIT for f in ('opening_cents','closing_cents')))
  a=row(rows,position['account']);o=original[key];require(position['account'] not in targets|covered and a['skr_code'] not in CODES and all(a[f]==0 for f in FIELDS));targets.add(position['account'])
  group='cash' if o['group']=='other_assets' else o['group'];require(a['group']==group)
  if group=='cash':require(a['skr_code']=='18000' and position['opening_cents']>=0 and position['closing_cents']>=0)
  elif group=='provisions':require(position['opening_cents']<=0 and position['closing_cents']<=0)
  else:require(position['opening_cents']==0 and (position['closing_cents']>=0 if group=='expense' else position['closing_cents']<=0))
 require(all(abs(n)<=LIMIT for n in facts.values()) and 0<=offset<=book['asset_total_cents'])
 return dict(schema=1,fiscal=fiscal,source=deepcopy(source),original_accounts=original,book_facts=facts,book_offset_cents=offset)
def check_values(values,mapping,book_only=False):
 act=active(values['book']['accounts']);proof=values.get('pension_offsets')
 if not act:require(proof is None);return set(),{},0
 require(type(proof) is dict and type(proof.get('fiscal')) is bool and proof['fiscal'] is not book_only)
 source=proof['source'];require(source['year']==values['year'] and source['journal_sha256']==values['journal_sha256'] and 'period' not in values and 'club_scope' not in values)
 scope=values.get('whole_club_scope',{}).get('source',{});require(scope.get('state')=='closed' and scope.get('previous_sha256') is None and scope.get('closed_sha256')==values['journal_sha256'])
 require(bind(source,values['book'],not book_only)==proof)
 for key,original in proof['original_accounts'].items():
  require(mapping.get(key)==target(original['skr_code']))
  if not book_only:
   actual=row(values['tax']['accounts'],key);require(actual['opening_cents']==actual['closing_cents']==actual['amount_cents']==0)
   position=source['tax_positions'][key];counter=row(values['tax']['accounts'],position['account']);require(counter['opening_cents']==position['opening_cents'] and counter['closing_cents']==position['closing_cents'])
 if book_only:require(values['book']==values['tax'] and not values['opening_adjustments'] and not values['current_adjustments'])
 return set(proof['original_accounts']),proof['book_facts'] if book_only else {},proof['book_offset_cents'] if book_only else 0
