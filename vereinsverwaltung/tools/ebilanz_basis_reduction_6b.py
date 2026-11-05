"""Original prior-year section6b(3) reserve and current old residential building."""
from copy import deepcopy
from datetime import date
from ebilanz_pension_offsets import document,FIELDS
from ebilanz_club_scope import digest

TARGET='is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.EStG6b_3'
FLAGS=('original_taxable_gain_reviewed','six_year_domestic_holding_reviewed',
       'original_reserve_and_remaining_reviewed','same_taxpayer_business_reviewed',
       'domestic_replacement_reviewed','residential_building_reviewed',
       'ordinary_two_percent_reviewed','no_other_elections_reviewed','amount_reviewed')
DOCUMENTS=('sale','reserve','election')
KEYS=('schema','year','journal_sha256','item_id','reduction_account','regular_account',
      'reserve_account','release_account','capital_account','reserve_year','sold_kind',
      'held_since','sold_on','sold_tax_book_cents','sale_price_cents','sale_cost_cents',
      'formed_cents','available_cents','reduction_cents','building_completed_on')+FLAGS+tuple(k+'_document' for k in DOCUMENTS)

def require(ok):
 if not ok:raise ValueError('§6b: Originalverkauf, sechsjährige Inlandsbindung, ursprüngliche Rücklage, Restbetrag und konkrete Gebäude-Ersatzinvestition vollständig abstimmen.')

def day(value):
 require(type(value) is str and len(value)==10)
 try:r=date.fromisoformat(value)
 except ValueError:require(False)
 require(r.isoformat()==value);return r

def bind(r,s):
 require(type(s) is dict and list(s)==list(KEYS) and type(s['schema']) is int and s['schema']==1)
 require(r.get('state')=='closed' and type(r.get('year')) is int and r['year'] in (2025,2026) and r.get('previous_sha256') is None and 'period' not in r and 'club_scope' not in r and type(s['year']) is int and s['year']==r['year'] and s['journal_sha256']==r['closed_sha256'])
 require(all(type(s[k]) is str for k in ('item_id','reduction_account','regular_account','reserve_account','release_account','capital_account')) and all(s[k] is True for k in FLAGS))
 for k in DOCUMENTS:document(s[k+'_document'])
 require(len({s[k+'_document']['document_id'] for k in DOCUMENTS})==3)
 require(type(s['reserve_year']) is int and s['reserve_year']==r['year']-1 and s['sold_kind'] in ('land','building'))
 held,sold,completed=map(day,(s['held_since'],s['sold_on'],s['building_completed_on']))
 require(sold.year==s['reserve_year'] and held<sold and (sold.month,sold.day)!=(2,29) and held<=sold.replace(year=sold.year-6) and date(1925,1,1)<=completed<date(2023,1,1))
 for k in ('sold_tax_book_cents','sale_price_cents','sale_cost_cents','formed_cents','available_cents','reduction_cents'):
  require(type(s[k]) is int and 0<=s[k]<=100_000_000_000)
 gain=s['sale_price_cents']-s['sale_cost_cents']-s['sold_tax_book_cents'];n=s['reduction_cents']
 require(0<n<=s['available_cents']<=s['formed_cents']<=gain)
 items=r['asset_schedule']['items'];require(type(items) is dict and len(items)==1 and s['item_id'] in items);i=items[s['item_id']]
 acquired=day(i['acquired_on']);require(completed<=acquired and acquired.year==r['year'] and i.get('method','linear_monthly')=='linear_monthly' and i.get('skr_code')=='02400' and i.get('sphere')=='4' and i.get('months')==600 and i.get('residual_cents')==0 and not set(i)&{'gwg','degressive','disposal','original_leasing','exceptional','valuation','partial_plan'})
 basis=i['cost_closing_cents'];require(0<n<basis and i['cost_opening_cents']==i['accumulated_opening_cents']==i['book_opening_cents']==0 and i['additions_cents']==basis and i['book_closing_cents']==basis-i['depreciation_cents'])
 book=r['calculation']['accounts'];keys=[i['account']]+[s[k] for k in ('reduction_account','regular_account','reserve_account','release_account','capital_account')];require(len(set(keys))==6 and all(k in book for k in keys))
 for name,code,group in (('reduction_account','62490','expense'),('reserve_account','29810','deferred'),('release_account','49270','revenue')):
  a=book[s[name]];require(a['skr_code']==code and a['group']==group and all(type(a.get(f)) is int and a[f]==0 for f in FIELDS))
 regular,asset=book[s['regular_account']],book[i['account']]
 require(regular['group']=='expense' and regular['skr_code']=='62210' and s['capital_account']==r['result_account'] and book[s['capital_account']]['group']=='equity')
 dep=i['depreciation_cents'];require(asset['opening_cents']==0 and asset['debit_cents']==basis and asset['credit_cents']==dep and asset['closing_cents']==i['book_closing_cents'] and regular['opening_cents']==0 and regular['debit_cents']==dep and regular['credit_cents']==0 and regular['amount_cents']==dep)
 elapsed=13-acquired.month;require(dep==(basis*elapsed+300)//600)
 tax_dep=((basis-n)*elapsed+300)//600;difference=dep-tax_dep
 return dict(schema=1,source=deepcopy(s),book_item_sha256=digest(i),asset_account=i['account'],original_gain_cents=gain,tax_basis_cents=basis-n,tax_regular_cents=tax_dep,regular_reversal_cents=difference,tax_closing_cents=basis-n-tax_dep,reserve_closing_cents=s['available_cents']-n)

def validate_rule(r):
 require(r.get('basis_reduction_6b_contract')=='62490@1' and r.get('group')=='expense' and r.get('condition')=='detail_choice' and r.get('target')==TARGET and r.get('allowed_targets')==[TARGET] and not set(r)&{'book_only','commercial_contract'} and not r.get('disclosures'))
 if 'codes' in r:require(r['codes']==['62490'])

def check(v,mapping,book_only=False):
 active={k for k,a in v['tax']['accounts'].items() if a['skr_code']=='62490' and any(a[f]!=0 for f in FIELDS)}
 p=v.get('basis_reduction_6b')
 if p is None:require(not active);return
 require(not book_only and type(p) is dict and not set(v)&{'period','club_scope','asset_tax','special_tax_7b','special_tax_7g','basis_reduction_7g'} and 'whole_club_scope' in v and bind(v['whole_club_scope']['source'],p['source'])==p)
 s=p['source'];require(active=={s['reduction_account']} and mapping.get(s['reduction_account'])==TARGET and mapping.get(s['reserve_account'])=='bs.eqLiab.otherSpecRes.other.EStG6b' and mapping.get(s['release_account'])=='is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.EStG6b_3')
 n,N,d=s['reduction_cents'],s['available_cents'],p['regular_reversal_cents'];book=v['book']['accounts'];tax=v['tax']['accounts']
 require(len(v['opening_adjustments'])==1 and len(v['current_adjustments'])==(3 if d else 2))
 expected=deepcopy(book)
 for f in FIELDS:
  expected[p['asset_account']][f]+=d if f=='debit_cents' else n if f=='credit_cents' else -n+d if f in ('closing_cents','amount_cents') else 0
  expected[s['regular_account']][f]+=d if f=='credit_cents' else -d if f in ('closing_cents','amount_cents') else 0
  expected[s['capital_account']][f]+=N if f in ('opening_cents','closing_cents') else -N if f=='amount_cents' else 0
 for key,values in ((s['reduction_account'],(0,n,0,n,n)),(s['reserve_account'],(-N,n,0,n-N,N-n)),(s['release_account'],(0,0,n,-n,n))):
  expected[key].update(zip(FIELDS,values))
 require(expected==tax)

def prepare(v,mapping,profile,meta,ordinary):
 check(v,mapping);require(profile.get('kind')=='whole_taxable_club' and profile.get('asset_scope')=='whole_club' and profile.get('asset_values_unchanged') is False and profile.get('asset_tax_values_reviewed') is True)
 p=v['basis_reduction_6b'];base=deepcopy(v);base.pop('basis_reduction_6b');base['tax']=deepcopy(base['book']);base['opening_adjustments']=[];base['current_adjustments']=[]
 original=ordinary(base,mapping,profile|{'asset_values_unchanged':True,'asset_tax_values_reviewed':False},meta,book_derived=True)
 n,d=p['source']['reduction_cents'],p['regular_reversal_cents'];rows=deepcopy(original['rows']);eq=meta['asset_calculations'];require(eq['accDepr.DeprPeriod'].get('accDepr.DeprPeriod.EStG6b_3')==1)
 for row in rows.values():
  row['accDepr.DeprPeriod.regular']-=d;row['accDepr.DeprPeriod.EStG6b_3']+=n
  for f in ('accDepr.DeprPeriod','accDepr'):row[f]+=n-d
  row['all']-=n-d;require(all(row[f]==sum(row[c]*w for c,w in children.items()) for f,children in eq.items()))
 totals=deepcopy(original['totals']);totals['depreciation_cents']+=n-d;totals['accumulated_closing_cents']+=n-d;totals['book_closing_cents']-=n-d
 return original|dict(rows=rows,totals=totals,sha256=digest(dict(original_schedule=v['asset_schedule'],basis_reduction_6b=p)))
