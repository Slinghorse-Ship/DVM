"""Explicit original regular counter; all historical plans without the field stay unchanged."""
VEHICLES={'05200','05250','05400','05600'}
CONTRACTS={'62500@1':('62500','is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'),'62220@1':('62220','is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'),'62620@1':('62620','is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.lowValueAs')}
def require(ok,message='Originales Abschreibungskonto und einzelner Fahrzeug-/aktivierter GWG-Plan stimmen nicht überein.'):
 if not ok:raise ValueError(message)
def code(plan,allow_gwg=True):
 from ebilanz_asset_types import DEP,GWG_CODES,MOVABLE
 from ebilanz_original_leasing import check as leasing_check
 leasing_check(plan)
 account=plan.get('skr_code',plan.get('account'))
 if 'depreciation_code' not in plan:return DEP.get(account) if allow_gwg or account not in GWG_CODES else None
 c=plan['depreciation_code'];require(type(c) is str and plan.get('method','linear_monthly')=='linear_monthly' and 'gwg' not in plan and 'degressive' not in plan and ((c=='62220' and account in VEHICLES) or (c=='62620' and account=='06700') or (c=='62500' and account in MOVABLE)));return c
def ordinary_gwg(plan):return plan.get('depreciation_code')=='62620' and code(plan)=='62620'
def validate_rule(rule):
 ref=rule.get('original_depreciation_contract')
 if ref is None:return
 require(ref in CONTRACTS);c,t=CONTRACTS[ref]
 require(rule.get('group')=='expense' and rule.get('condition')=='detail_choice' and rule.get('target')==t and rule.get('allowed_targets')==[t] and not rule.get('disclosures'))
 if 'codes' in rule:require(rule['codes']==[c])
def check_annual(items,accounts):
 if not any('depreciation_code' in i for i in items.values()) and not any(a.get('skr_code') in ('62220','62620','62500') and any(a[f] for f in ('opening_cents','debit_cents','credit_cents')) for a in accounts.values()):return
 totals={}
 for item in items.values():
  if item.get('method')=='cost':require('depreciation_code' not in item);continue
  c=code(item);require(c is not None);totals[c]=totals.get(c,0)+item['depreciation_cents']
 for c in ('62200','62220','62600','62620','62500'):
  actual=sum(a['amount_cents'] for a in accounts.values() if a['group']=='expense' and a.get('skr_code')==c)
  require(actual==totals.get(c,0),'Originales Fahrzeug-/GWG-Abschreibungskonto: alle Einzelgegenstände und tatsächlichen Gegenkonten vollständig abstimmen.')
