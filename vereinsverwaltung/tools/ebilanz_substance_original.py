"""Original acquired exhaustible land, measured units and source-bound annual depletion."""
from copy import deepcopy
from datetime import date
from ebilanz_club_scope import digest
from ebilanz_pension_offsets import document,FIELDS as ACCOUNT_FIELDS
METHOD='substance_original'
TARGET='bs.ass.fixAss.tan.landBuildings.landWithoutBuildings'
EXPENSE='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'
FIELDS=('cost_opening_cents','additions_cents','cost_closing_cents','accumulated_opening_cents','depreciation_cents','accumulated_closing_cents','book_opening_cents','book_closing_cents')
KEYS=('basis_cents','residual_cents','acquired_on','months','sphere','account','reason','method','total_units','unit','acquired_reviewed','estimate_reviewed','acquisition_document','estimate_document')
LIMIT=100_000_000_000
def require(ok):
 if not ok:raise ValueError('Substanzverzehr: ursprüngliche Kosten, Restwert, Gesamtmenge, Jahresverbrauch und getrennte Buch-/Steuerbestätigung abstimmen.')
def validate_rule(r):
 require(r.get('substance_original_contract')=='02250@1' and r.get('group')=='fixed_assets' and r.get('condition')=='detail_choice' and r.get('target')==TARGET and r.get('allowed_targets')==[TARGET] and 'book_only' not in r and 'commercial_contract' not in r and not r.get('disclosures'))
 if 'codes' in r:require(r['codes']==['02250'])
def plan(p):
 require(type(p) is dict and list(p)==list(KEYS) and p['method']==METHOD and p['account']=='02250' and type(p['months']) is int and p['months']==0 and type(p['basis_cents']) is int and 0<p['basis_cents']<=LIMIT and type(p['residual_cents']) is int and 0<=p['residual_cents']<p['basis_cents'] and type(p['total_units']) is int and 0<p['total_units']<=1_000_000 and p['unit'] in ('kg','m3','unit') and p['sphere'] in ('1','2','3','4') and p['acquired_reviewed'] is True and p['estimate_reviewed'] is True and type(p['reason']) is str and p['reason'].strip() and len(p['reason'])<=2000 and all(ord(c)>=32 for c in p['reason']))
 require(type(p['acquired_on']) is str);d=date.fromisoformat(p['acquired_on']);require(d.isoformat()==p['acquired_on'] and d.year in (2025,2026))
 for k in ('acquisition_document','estimate_document'):document(p[k])
def calculate(p,s):
 plan(p);require(type(s) is dict and list(s)==['schema','year','used_units','book_reviewed','tax_reviewed','document'] and type(s['schema']) is int and s['schema']==1 and type(s['year']) is int and s['year']==date.fromisoformat(p['acquired_on']).year and type(s['used_units']) is int and 0<s['used_units']<=p['total_units'] and s['book_reviewed'] is True and s['tax_reviewed'] is True);document(s['document'])
 n=((p['basis_cents']-p['residual_cents'])*s['used_units']+p['total_units']//2)//p['total_units'];require(0<n<=p['basis_cents']-p['residual_cents'])
 out=dict(schema=1,source=dict(plan=deepcopy(p),proof=deepcopy(s)),year=s['year'],opening_cents=p['basis_cents'],depreciation_cents=n,closing_cents=p['basis_cents']-n);return out|{'sha256':digest(out)}
def original_mapping(v):
 items=(v.get('asset_schedule') or {}).get('items') or {};result={}
 for i in items.values():
  if i.get('method')!=METHOD:continue
  x=i['substance'];require(calculate(x['source']['plan'],x['source']['proof'])==x and x['year']==v['year'] and i['skr_code']=='02250')
  require(i['account'] in v['book']['accounts'] and v['book']['accounts'][i['account']]['skr_code']=='02250')
  result[i['account']]=TARGET
 return result
def prepare(v,mapping,profile,meta):
 schedule=v['asset_schedule'];items=schedule['items'];require(type(items) is dict and len(items)==1 and not v['opening_adjustments'] and not v['current_adjustments'] and not set(v)&{'period','club_scope','asset_tax','special_tax_7b','special_tax_7g','basis_reduction_7g'} and profile.get('kind')=='whole_taxable_club' and profile.get('asset_scope')=='whole_club' and profile.get('asset_values_unchanged') is True and v['whole_club_scope']['source'].get('previous_sha256') is None)
 rows={};totals=dict.fromkeys(FIELDS,0)
 for identifier,i in items.items():
  require(set(i)==set(FIELDS)|{'item_id','invoice_id','receipt_number','account','skr_code','sphere','acquired_on','months','residual_cents','method','substance','substance_account','acquisition_hash','depreciation_hash','expected_depreciation_cents','name'} and i['method']==METHOD)
  x=i['substance'];p=x['source']['plan'];s=x['source']['proof'];require(calculate(p,s)==x and i['item_id']==identifier and x['year']==v['year'] and i['skr_code']=='02250' and i['sphere']==p['sphere'] and i['acquired_on']==p['acquired_on'] and type(i['months']) is int and i['months']==0 and i['residual_cents']==p['residual_cents'] and i['expected_depreciation_cents']==x['depreciation_cents'])
  for k in ('acquisition_hash','depreciation_hash'):require(type(i[k]) is str and len(i[k])==64 and all(c in '0123456789abcdef' for c in i[k]))
  key=i['account'];counter=i['substance_account'];require(mapping.get(key)==TARGET and mapping.get(counter)==EXPENSE)
  n=x['depreciation_cents'];basis=p['basis_cents'];amounts=dict(cost_opening_cents=0,additions_cents=basis,cost_closing_cents=basis,accumulated_opening_cents=0,depreciation_cents=n,accumulated_closing_cents=n,book_opening_cents=0,book_closing_cents=basis-n);require(all(type(i[f]) is int and i[f]==value for f,value in amounts.items()))
  for side in ('book','tax'):
   for k,a in v[side]['accounts'].items():
    if k==key:require(a['group']=='fixed_assets' and a['skr_code']=='02250' and all(type(a[f]) is int and a[f]==value for f,value in dict(opening_cents=0,debit_cents=basis,credit_cents=n,closing_cents=basis-n,amount_cents=basis-n).items()))
    elif a['group']=='fixed_assets':require(all(type(a[f]) is int and a[f]==0 for f in ACCOUNT_FIELDS))
    if k==counter:require(a['group']=='expense' and a['skr_code']=='62200' and all(type(a[f]) is int and a[f]==value for f,value in dict(opening_cents=0,debit_cents=n,credit_cents=0,closing_cents=n,amount_cents=n).items()))
    elif a['skr_code'] in ('62200','62220','62100','62000','62600'):require(all(a[f]==0 for f in ACCOUNT_FIELDS))
  eq=meta['asset_calculations'];require(eq['accDepr.DeprPeriod']['accDepr.DeprPeriod.regular']==1);cols=set(eq)|{k for children in eq.values() for k in children}|{'all_Prev_period'};row=dict.fromkeys(cols,0);row.update({'gross.addition':basis,'gross':basis,'accDepr.DeprPeriod.regular':n,'accDepr.DeprPeriod':n,'accDepr':n,'all':basis-n});require(all(row[k]==sum(row[c]*w for c,w in children.items()) for k,children in eq.items()))
  pending=list(meta['asset_parents'].get(TARGET,[]));parents=set()
  while pending:
   parent=pending.pop();require(parent!=TARGET)
   if parent in parents:continue
   parents.add(parent);pending.extend(meta['asset_parents'].get(parent,[]))
  require('bs.ass.fixAss' in parents)
  for target in {TARGET}|parents:rows[target]=deepcopy(row)
  for f,value in amounts.items():totals[f]+=value
 require(set(schedule['totals'])==set(totals) and all(type(schedule['totals'][f]) is int and schedule['totals'][f]==n for f,n in totals.items()))
 return dict(rows=rows,items=1,totals=totals,sha256=digest(schedule))
