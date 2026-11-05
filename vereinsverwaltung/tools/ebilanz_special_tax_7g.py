"""Independently confirmed initial7g(5) election; ordinary HGB plan retained."""
from copy import deepcopy
from ebilanz_pension_offsets import document,FIELDS
from ebilanz_club_scope import digest
from ebilanz_asset_types import MOVABLE
from ebilanz_original_depreciation import VEHICLES,code as regular_code
CODES=('62400','62410','62420')
TARGET='is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.impairment'
LIMIT=100_000_000_000
KEYS=('schema','year','journal_sha256','item_id','special_account','regular_account','special_cents','previous_profit_cents','domestic_business_use_confirmed','use_through_next_year_confirmed','no_other_elections_confirmed','amount_reviewed','profit_document','eligibility_document','election_document')
def require(ok):
 if not ok:raise ValueError('Sonderabschreibung7g: ursprüngliche Anschaffung, Vorjahresgewinn, Nutzungsbindung und gesondert bestätigte Steuerwahl abstimmen.')
def validate_rule(r):
 ref=r.get('special_tax_7g_contract');require(ref in ('62400@1','62410@1','62420@1') and r.get('group')=='expense' and r.get('condition')=='detail_choice' and r.get('target')==TARGET and r.get('allowed_targets')==[TARGET] and 'book_only' not in r and 'commercial_contract' not in r and not r.get('disclosures'))
 if 'codes' in r:require(r['codes']==[ref[:5]])
def bind(report,source):
 require(report.get('state')=='closed' and report.get('year') in (2025,2026) and report.get('previous_sha256') is None and 'period' not in report and 'club_scope' not in report)
 generic=type(source) is dict and type(source.get('schema')) is int and source.get('schema')==2
 require(type(source) is dict and list(source)==list(KEYS)+( ['tax_law'] if generic else []) and type(source['schema']) is int and source['schema']==(2 if generic else 1) and (not generic or source['tax_law']=='EStG7g5') and type(source['year']) is int and source['year']==report['year'] and source['journal_sha256']==report['closed_sha256'])
 require(all(type(source[k]) is str for k in ('item_id','special_account','regular_account')) and all(source[k] is True for k in ('domestic_business_use_confirmed','use_through_next_year_confirmed','no_other_elections_confirmed','amount_reviewed')))
 require(type(source['previous_profit_cents']) is int and abs(source['previous_profit_cents'])<=LIMIT and source['previous_profit_cents']<=20_000_000)
 for k in ('profit_document','eligibility_document','election_document'):document(source[k])
 items=report['asset_schedule']['items'];require(type(items) is dict and len(items)==1 and source['item_id'] in items);item=items[source['item_id']]
 require(item.get('method','linear_monthly')=='linear_monthly' and not set(item)&{'gwg','degressive','disposal','original_leasing','exceptional','valuation','partial_plan'} and item.get('sphere')=='4' and item.get('acquired_on','')[:4]==str(source['year']) and item.get('residual_cents')==0)
 code=item['skr_code'];require(code in MOVABLE and code!='06700');book=report['calculation']['accounts'];require(all(k in book for k in (source['special_account'],source['regular_account'],item['account'])))
 special=book[source['special_account']];regular=book[source['regular_account']];asset=book[item['account']]
 require(special['group']=='expense' and special['skr_code']==('62400' if generic else '62420' if code in VEHICLES else '62410') and (not generic or code not in VEHICLES) and regular['group']=='expense' and regular['skr_code']==regular_code(item) and all(type(special.get(f)) is int and special[f]==0 for f in FIELDS))
 n=source['special_cents'];basis=item['cost_closing_cents'];require(type(n) is int and 0<n<=basis*40//100 and n<=item['book_closing_cents'] and item['cost_opening_cents']==0 and item['additions_cents']==basis and item['accumulated_opening_cents']==0 and item['book_opening_cents']==0 and item['book_closing_cents']==basis-item['depreciation_cents'])
 require(asset['opening_cents']==0 and asset['debit_cents']==basis and asset['credit_cents']==item['depreciation_cents'] and asset['closing_cents']==item['book_closing_cents'] and regular['opening_cents']==0 and regular['debit_cents']==item['depreciation_cents'] and regular['credit_cents']==0 and regular['amount_cents']==item['depreciation_cents'])
 return dict(schema=1,source=deepcopy(source),book_item_sha256=digest(item),asset_account=item['account'],regular_depreciation_cents=item['depreciation_cents'],tax_special_cents=n,tax_closing_cents=item['book_closing_cents']-n)
def active(rows):return {k:a for k,a in rows.items() if a['skr_code'] in CODES and any(type(a.get(f)) is not int or a[f]!=0 for f in FIELDS)}
def check(values,mapping,book_only=False):
 p=values.get('special_tax_7g');act=active(values['tax']['accounts']);original=active(values['book']['accounts']);require(not original)
 if p is None:require(not act);return
 require(not book_only and type(p) is dict and 'period' not in values and 'club_scope' not in values and not values['opening_adjustments'] and 'asset_tax' not in values and 'special_tax_7b' not in values and 'whole_club_scope' in values)
 require(bind(values['whole_club_scope']['source'],p['source'])==p)
 s=p['source'];require(set(act)=={s['special_account']} and mapping.get(s['special_account'])==TARGET)
 b=values['book']['accounts'];t=values['tax']['accounts'];n=s['special_cents']
 for f in FIELDS:
  delta=n if f=='credit_cents' else -n if f in ('closing_cents','amount_cents') else 0
  require(t[p['asset_account']][f]==b[p['asset_account']][f]+delta and t[s['regular_account']][f]==b[s['regular_account']][f])
 for f,x in dict(opening_cents=0,debit_cents=n,credit_cents=0,closing_cents=n,amount_cents=n).items():require(t[s['special_account']][f]==x)
def prepare(values,mapping,profile,meta,ordinary):
 check(values,mapping);require(profile.get('kind')=='whole_taxable_club' and profile.get('asset_scope')=='whole_club' and profile.get('asset_values_unchanged') is False and profile.get('asset_tax_values_reviewed') is True)
 p=values['special_tax_7g'];source=p['source'];base=deepcopy(values);base.pop('special_tax_7g');base['tax']=deepcopy(base['book']);base['opening_adjustments']=[];base['current_adjustments']=[]
 original=ordinary(base,mapping,profile|{'asset_values_unchanged':True,'asset_tax_values_reviewed':False},meta,book_derived=True)
 n=p['tax_special_cents'];rows=deepcopy(original['rows']);eq=meta['asset_calculations'];require(eq['accDepr.DeprPeriod'].get('accDepr.DeprPeriod.impairment')==1)
 # One retained original item means every emitted ancestor is part of this item.
 for row in rows.values():
  for f in ('accDepr.DeprPeriod.impairment','accDepr.DeprPeriod','accDepr'):row[f]+=n
  row['all']-=n
  require(all(row[f]==sum(row[c]*w for c,w in children.items()) for f,children in eq.items()))
 totals=deepcopy(original['totals']);totals['depreciation_cents']+=n;totals['accumulated_closing_cents']+=n;totals['book_closing_cents']-=n
 return original|dict(rows=rows,totals=totals,sha256=digest(dict(original_schedule=values['asset_schedule'],special_tax_7g=p)))
