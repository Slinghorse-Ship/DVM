"""Original7g(2) basis election, actual adjustment and recomputed regular tax depreciation."""
from copy import deepcopy
from datetime import date
from ebilanz_pension_offsets import document,FIELDS
from ebilanz_club_scope import digest
from ebilanz_asset_types import MOVABLE
from ebilanz_original_depreciation import VEHICLES,code as regular_code
CODES=('62430','62440')
TARGET='is.netIncome.regular.operatingTC.otherCost.deductValueEStG7g_2'
FLAGS=('original_claim_valid_confirmed','same_taxpayer_and_business_confirmed','domestic_use_through_next_year_confirmed','outside_addition_reviewed','no_other_elections_confirmed','amount_reviewed')
KEYS=('schema','year','journal_sha256','item_id','reduction_account','regular_account','reduction_cents','iab_year','claimed_cents','available_cents','addition_cents')+FLAGS+('iab_document','usage_document','election_document')
def require(ok):
 if not ok:raise ValueError('Kürzung7g: Original-IAB, Restbetrag, separate Hinzurechnung und gewählte Anschaffungskostenkürzung abstimmen.')
def validate_rule(r):
 ref=r.get('basis_reduction_7g_contract');require(ref in ('62430@1','62440@1') and r.get('group')=='expense' and r.get('condition')=='detail_choice' and r.get('target')==TARGET and r.get('allowed_targets')==[TARGET] and 'book_only' not in r and 'commercial_contract' not in r and not r.get('disclosures'))
 if 'codes' in r:require(r['codes']==[ref[:5]])
def bind(r,s):
 require(r.get('state')=='closed' and r.get('year') in (2025,2026) and r.get('previous_sha256') is None and 'period' not in r and 'club_scope' not in r and type(s) is dict and list(s)==list(KEYS) and type(s['schema']) is int and s['schema']==1 and type(s['year']) is int and s['year']==r['year'] and s['journal_sha256']==r['closed_sha256'])
 require(all(type(s[k]) is str for k in ('item_id','reduction_account','regular_account')) and all(s[k] is True for k in FLAGS))
 for k in ('iab','usage','election'):document(s[k+'_document'])
 require(type(s['iab_year']) is int and r['year']-3<=s['iab_year']<r['year'] and all(type(s[k]) is int and 0<s[k]<=100_000_000_000 for k in ('reduction_cents','claimed_cents','available_cents','addition_cents')) and s['claimed_cents']<=20_000_000 and s['reduction_cents']<=s['addition_cents']<=s['available_cents']<=s['claimed_cents'])
 items=r['asset_schedule']['items'];require(type(items) is dict and len(items)==1 and s['item_id'] in items);i=items[s['item_id']]
 require(i.get('method','linear_monthly')=='linear_monthly' and not set(i)&{'gwg','degressive','disposal','original_leasing','exceptional','valuation','partial_plan'} and i.get('sphere')=='4' and i.get('acquired_on','')[:4]==str(r['year']) and i.get('residual_cents')==0 and i['skr_code'] in MOVABLE and i['skr_code']!='06700')
 basis=i['cost_closing_cents'];n=s['reduction_cents'];require(s['addition_cents']<=basis*50//100 and i['cost_opening_cents']==0 and i['additions_cents']==basis and i['accumulated_opening_cents']==0 and i['book_opening_cents']==0 and i['book_closing_cents']==basis-i['depreciation_cents'])
 book=r['calculation']['accounts'];require(all(k in book for k in (s['reduction_account'],s['regular_account'],i['account'])));counter=book[s['reduction_account']];regular=book[s['regular_account']];asset=book[i['account']]
 require(counter['group']=='expense' and counter['skr_code']==('62440' if i['skr_code'] in VEHICLES else '62430') and regular['group']=='expense' and regular['skr_code']==regular_code(i) and all(type(counter.get(f)) is int and counter[f]==0 for f in FIELDS))
 require(asset['opening_cents']==0 and asset['debit_cents']==basis and asset['credit_cents']==i['depreciation_cents'] and asset['closing_cents']==i['book_closing_cents'] and regular['opening_cents']==0 and regular['debit_cents']==i['depreciation_cents'] and regular['credit_cents']==0 and regular['amount_cents']==i['depreciation_cents'])
 months=i['months'];elapsed=13-date.fromisoformat(i['acquired_on']).month;require(type(months) is int and 12<=months<=1200 and months%12==0);tax_dep=((basis-n)*elapsed+months//2)//months;difference=i['depreciation_cents']-tax_dep;require(0<=difference<n)
 return dict(schema=1,source=deepcopy(s),book_item_sha256=digest(i),asset_account=i['account'],tax_basis_cents=basis-n,tax_regular_cents=tax_dep,regular_reversal_cents=difference,tax_closing_cents=basis-n-tax_dep)
def active(rows):return {k:a for k,a in rows.items() if a['skr_code'] in CODES and any(type(a.get(f)) is not int or a[f]!=0 for f in FIELDS)}
def check(v,mapping,book_only=False):
 p=v.get('basis_reduction_7g');act=active(v['tax']['accounts']);require(not active(v['book']['accounts']))
 if p is None:require(not act);return
 require(not book_only and type(p) is dict and 'period' not in v and 'club_scope' not in v and not v['opening_adjustments'] and 'asset_tax' not in v and 'special_tax_7b' not in v and 'special_tax_7g' not in v and 'whole_club_scope' in v and bind(v['whole_club_scope']['source'],p['source'])==p)
 s=p['source'];require(set(act)=={s['reduction_account']} and mapping.get(s['reduction_account'])==TARGET);book=v['book']['accounts'];tax=v['tax']['accounts'];n=s['reduction_cents'];d=p['regular_reversal_cents']
 for f in FIELDS:
  delta=d if f=='debit_cents' else n if f=='credit_cents' else -n+d if f in ('closing_cents','amount_cents') else 0;require(tax[p['asset_account']][f]==book[p['asset_account']][f]+delta)
  delta=d if f=='credit_cents' else -d if f in ('closing_cents','amount_cents') else 0;require(tax[s['regular_account']][f]==book[s['regular_account']][f]+delta)
 for f,x in dict(opening_cents=0,debit_cents=n,credit_cents=0,closing_cents=n,amount_cents=n).items():require(tax[s['reduction_account']][f]==x)
def prepare(v,mapping,profile,meta,ordinary):
 check(v,mapping);require(profile.get('kind')=='whole_taxable_club' and profile.get('asset_scope')=='whole_club' and profile.get('asset_values_unchanged') is False and profile.get('asset_tax_values_reviewed') is True)
 p=v['basis_reduction_7g'];base=deepcopy(v);base.pop('basis_reduction_7g');base['tax']=deepcopy(base['book']);base['opening_adjustments']=[];base['current_adjustments']=[];original=ordinary(base,mapping,profile|{'asset_values_unchanged':True,'asset_tax_values_reviewed':False},meta,book_derived=True)
 n=p['source']['reduction_cents'];d=p['regular_reversal_cents'];rows=deepcopy(original['rows']);eq=meta['asset_calculations'];require(eq['accDepr.DeprPeriod'].get('accDepr.DeprPeriod.EStG7g_2')==1)
 for row in rows.values():
  row['accDepr.DeprPeriod.regular']-=d;row['accDepr.DeprPeriod.EStG7g_2']+=n
  for f in ('accDepr.DeprPeriod','accDepr'):row[f]+=n-d
  row['all']-=n-d;require(all(row[f]==sum(row[c]*w for c,w in children.items()) for f,children in eq.items()))
 totals=deepcopy(original['totals']);totals['depreciation_cents']+=n-d;totals['accumulated_closing_cents']+=n-d;totals['book_closing_cents']-=n-d
 return original|dict(rows=rows,totals=totals,sha256=digest(dict(original_schedule=v['asset_schedule'],basis_reduction_7g=p)))
