"""Original regular/AfaA annual entries, kept as separate gross movements."""
from copy import deepcopy
from datetime import date
import hashlib,json,re
from ebilanz_asset_types import MOVABLE,BUILDING,GOODWILL,ACCOUNTS,accepts_target
from ebilanz_original_depreciation import code as regular_code,VEHICLES
METHOD='exceptional_original'
TARGET='is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.except.tan'
REGULAR='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'
GWG='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.lowValueAs'
GOODWILL_TARGET='is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.except.goodwill'
CONTRACTS={c+'@1':c for c in ('62090','62310','62320','62330','62660')}
def expense_target(code):return GOODWILL_TARGET if code=='62090' else TARGET
FIELDS=('cost_opening_cents','additions_cents','cost_closing_cents','accumulated_opening_cents','depreciation_cents','accumulated_closing_cents','book_opening_cents','book_closing_cents','impairment_cents','writeup_cents')
LIMIT=100_000_000_000
def require(ok,message='AfaA: ursprünglichen Einzelplan, Jahresnachweis sowie unabhängig bestätigte Buch- und Steuerbeträge prüfen.'):
 if not ok:raise ValueError(message)
def digest(value):return hashlib.sha256(json.dumps(value,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
def validate_rule(rule):
 ref=rule.get('exceptional_original_contract')
 if ref is None:return
 require(ref in CONTRACTS and rule.get('group')=='expense' and rule.get('condition')=='detail_choice' and rule.get('target')==expense_target(CONTRACTS[ref]) and rule.get('allowed_targets')==[expense_target(CONTRACTS[ref])] and not rule.get('disclosures') and 'book_only' not in rule)
 if 'codes' in rule:require(rule['codes']==[CONTRACTS[ref]])
def calculate(plan,proof):
 require(set(proof)=={'schema','year','on','code','book_cents','tax_cents','book_reason','tax_reason','book_reviewed','tax_reviewed','document'} and type(proof['schema']) is int and proof['schema']==1 and type(proof['year']) is int and proof['year'] in (2025,2026))
 require(not set(plan)-{'basis_cents','residual_cents','acquired_on','months','sphere','account','reason','method','depreciation_code'} and plan.get('sphere') in ('1','2','3','4') and type(plan.get('reason')) is str and plan['reason'].strip() and len(plan['reason'])<=2000)
 year=proof['year'];require(proof['on']==f'{year}-12-31' and plan.get('method')=='linear_monthly' and not {'original_leasing','gwg','degressive'}&set(plan))
 acquired=date.fromisoformat(plan['acquired_on']);require(acquired.year==year and acquired.isoformat()==plan['acquired_on'] and plan.get('residual_cents')==0 and type(plan['residual_cents']) is int)
 code=proof['code'];asset=plan.get('account');require((code=='62090' and asset in GOODWILL and plan.get('months')==180) or (code=='62310' and asset in BUILDING) or (code=='62320' and asset in VEHICLES) or (code=='62330' and asset in MOVABLE and asset not in VEHICLES) or (code=='62660' and asset=='06700' and plan.get('depreciation_code')=='62620'))
 regular=regular_code(plan);require(regular is not None)
 n=proof['book_cents'];require(type(n) is int and 0<n<=LIMIT and type(proof['tax_cents']) is int and proof['tax_cents']==n and proof['book_reviewed'] is True and proof['tax_reviewed'] is True)
 require(all(type(proof[k]) is str and proof[k].strip() and len(proof[k])<=2000 and not re.search('[\x00-\x1f]',proof[k]) for k in ('book_reason','tax_reason')))
 doc=proof['document'];require(type(doc) is dict and set(doc)=={'document_id','revision','sha256'} and type(doc['document_id']) is str and re.fullmatch('[a-f0-9]{32}',doc['document_id']) and type(doc['revision']) is int and doc['revision']>=1 and type(doc['sha256']) is str and re.fullmatch('[a-f0-9]{64}',doc['sha256']))
 basis=plan['basis_cents'];months=plan['months'];require(type(basis) is int and 0<basis<=LIMIT and type(months) is int and 12<=months<=1200 and months%12==0)
 through=min(months,13-acquired.month);amount=(basis*through+months//2)//months;require(n<=basis-amount)
 out=dict(schema=1,source=dict(plan=deepcopy(plan),proof=deepcopy(proof)),year=year,opening_cents=basis,depreciation_cents=amount,impairment_cents=n,closing_cents=basis-amount-n)
 return out|{'sha256':digest(out)}
def grid(item,meta):
 v=item['exceptional'];basis=v['source']['plan']['basis_cents'];eq=meta['asset_calculations'];require(eq['all']=={'accDepr':-1,'gross':1,'revaluation':1} and eq['accDepr.DeprPeriod']['accDepr.DeprPeriod.extraordinary']==1 and eq['accDepr.DeprPeriod']['accDepr.DeprPeriod.regular']==1)
 cols=set(eq)|{k for children in eq.values() for k in children}|{'all_Prev_period'};row=dict.fromkeys(cols,0)
 row.update({'gross.addition':basis,'gross':basis,'accDepr.DeprPeriod.regular':v['depreciation_cents'],'accDepr.DeprPeriod.extraordinary':v['impairment_cents'],'accDepr.DeprPeriod':v['depreciation_cents']+v['impairment_cents'],'accDepr':v['depreciation_cents']+v['impairment_cents'],'all':v['closing_cents']})
 require(all(type(n) is int and 0<=n<=LIMIT for n in row.values()) and all(row[p]==sum(row[c]*w for c,w in children.items()) for p,children in eq.items()))
 return row
def prepare(values,mapping,profile,meta):
 schedule=values['asset_schedule'];items=schedule['items'];require(type(items) is dict and items and all(i.get('method')==METHOD for i in items.values()),'AfaA: gemischte Anlagen, Abgänge und Folgejahre gesondert qualifizieren.')
 require(not values['opening_adjustments'] and not values['current_adjustments'] and 'period' not in values and 'club_scope' not in values and not (values.get('asset_tax') or {}).get('items') and profile.get('kind')=='whole_taxable_club' and profile.get('asset_scope')=='whole_club' and profile.get('asset_values_unchanged') is True)
 rows={};expected={};expense={};totals=dict.fromkeys(FIELDS,0)
 for identifier,item in items.items():
  require(set(item)==set(FIELDS)|{'item_id','invoice_id','receipt_number','account','skr_code','sphere','acquired_on','months','residual_cents','method','exceptional','exceptional_accounts','acquisition_hash','depreciation_hash','expected_depreciation_cents','name'},'AfaA: unbekannte Anlagenbewegung oder unvollständiger Originalnachweis.')
  v=item['exceptional'];plan=v['source']['plan'];proof=v['source']['proof'];require(calculate(plan,proof)==v and item['item_id']==identifier and v['year']==values['year'])
  require(item['skr_code']==plan['account'] and item['sphere']==plan['sphere'] and item['sphere'] in ('1','2','3','4') and item['acquired_on']==plan['acquired_on'] and item['months']==plan['months'] and item['residual_cents']==0)
  key=item['account'];target=mapping.get(key);require(accepts_target(plan['account'],target))
  amounts=dict(cost_opening_cents=0,additions_cents=plan['basis_cents'],cost_closing_cents=plan['basis_cents'],accumulated_opening_cents=0,depreciation_cents=v['depreciation_cents'],accumulated_closing_cents=v['depreciation_cents']+v['impairment_cents'],book_opening_cents=0,book_closing_cents=v['closing_cents'],impairment_cents=v['impairment_cents'],writeup_cents=0)
  require(all(type(item[f]) is int and item[f]==n for f,n in amounts.items()) and item['expected_depreciation_cents']==v['depreciation_cents'])
  require(all(type(item.get(f)) is str and re.fullmatch('[a-f0-9]{64}',item[f]) for f in ('acquisition_hash','depreciation_hash')))
  original=regular_code(plan);ordinary_target=GWG if original=='62620' else ACCOUNTS[plan['account']]['expense'];require(set(item['exceptional_accounts'])=={original,proof['code']})
  for code,n,target_expense in [(original,v['depreciation_cents'],ordinary_target),(proof['code'],v['impairment_cents'],expense_target(proof['code']))]:
   account=item['exceptional_accounts'][code];require(mapping.get(account)==target_expense)
   for side in ('book','tax'):require(values[side]['accounts'][account]['group']=='expense' and values[side]['accounts'][account]['skr_code']==code)
   expense[account]=expense.get(account,0)+n
  total=expected.setdefault(key,dict(opening_cents=0,debit_cents=0,credit_cents=0,closing_cents=0,amount_cents=0))
  for f,n in {'debit_cents':plan['basis_cents'],'credit_cents':v['depreciation_cents']+v['impairment_cents'],'closing_cents':v['closing_cents'],'amount_cents':v['closing_cents']}.items():total[f]+=n
  for f,n in amounts.items():totals[f]+=n
  pending=list(meta['asset_parents'].get(target,[]));parents=set()
  while pending:
   p=pending.pop();require(p!=target)
   if p in parents:continue
   parents.add(p);pending.extend(meta['asset_parents'].get(p,[]))
  require('bs.ass.fixAss' in parents);movement=grid(item,meta)
  for t in {target}|parents:
   row=rows.setdefault(t,dict.fromkeys(movement,0))
   for f,n in movement.items():row[f]+=n
 for side in ('book','tax'):
  for key,a in values[side]['accounts'].items():
   if key in expected:require(a['group']=='fixed_assets' and all(type(a[f]) is int and a[f]==n for f,n in expected[key].items()))
   elif a['group']=='fixed_assets':require(all(a[f]==0 for f in ('opening_cents','debit_cents','credit_cents','amount_cents')))
   if key in expense:require(a['opening_cents']==0 and a['amount_cents']==expense[key] and a['debit_cents']==expense[key] and a['credit_cents']==0)
   elif a.get('skr_code') in ('62000','62050','62100','62200','62210','62220','62600','62620','62090','62310','62320','62330','62660'):require(all(a[f]==0 for f in ('opening_cents','debit_cents','credit_cents','amount_cents')))
 require(set(schedule['totals'])==set(totals) and all(type(n) is int and n==totals[f] for f,n in schedule['totals'].items()))
 return dict(rows=rows,items=len(items),totals=totals,sha256=digest(schedule))
