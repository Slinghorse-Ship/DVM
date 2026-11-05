"""Independent exact-rational verification of one evidenced component scrapping."""
from datetime import date
from fractions import Fraction
import hashlib
import json
import re
from ebilanz_asset_types import MOVABLE


def fail():
    raise ValueError('Teilabgang: Originalplan, Teilkosten, Nachweise oder Restwerte stimmen nicht überein.')


def number(v, low=0, high=100000000000):
    if type(v) is not int or not low <= v <= high: fail()
    return v


def reason(v):
    if not isinstance(v, str) or not v.strip() or len(v)>2000 or re.search(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]',v): fail()


def document(v):
    for field,pattern in [('document_id','[a-f0-9]{32}'),('sha256','[a-f0-9]{64}')]:
        if not isinstance(v.get(field),str) or not re.fullmatch(pattern,v[field]): fail()
    number(v.get('revision'),1,2147483647)


def values(plan, proof, year, tax_months=None):
    if not isinstance(plan,dict) or set(plan)!={'basis_cents','residual_cents','acquired_on','months','sphere','account','reason','method'}: fail()
    if not isinstance(proof,dict) or set(proof)!={'schema','kind','date','reason','document_id','revision','sha256','plan_sha256','allocation','no_proceeds','complete'}: fail()
    if (type(proof['schema']) is not int or proof['schema']!=1 or proof['kind']!='partial_scrap'
        or proof['no_proceeds'] is not True or proof['complete'] is not False
        or plan['method']!='linear_monthly' or plan['account'] not in MOVABLE or plan['sphere'] not in ('1','2','3','4')): fail()
    reason(plan['reason']);reason(proof['reason']);document(proof)
    digest=hashlib.sha256(json.dumps(plan,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
    if proof['plan_sha256']!=digest: fail()
    for v in (plan['acquired_on'],proof['date']):
        if not isinstance(v,str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}',v): fail()
    acquired=date.fromisoformat(plan['acquired_on']);disposed=date.fromisoformat(proof['date'])
    if not 2000<=acquired.year<=year<=2200 or disposed<acquired or disposed.year>2200: fail()
    cost=number(plan['basis_cents'],1);residual=number(plan['residual_cents'],0,cost-1)
    months=number(plan['months'],12,1200)
    if months%12: fail()
    if tax_months is not None:
        months=number(tax_months,12,1200)
        if months%12: fail()
    a=proof['allocation']
    if not isinstance(a,dict) or set(a)!={'basis','cost_cents','residual_cents','document_id','revision','sha256','reason','same_use_and_life','confirmed'}: fail()
    if a['basis']!='documented_component_cost' or a['same_use_and_life'] is not True or a['confirmed'] is not True: fail()
    document(a);reason(a['reason'])
    part_cost=number(a['cost_cents'],1,cost-1);part_residual=number(a['residual_cents'],0,min(residual,part_cost-1))
    if residual-part_residual>=cost-part_cost: fail()
    def rounded(v):
        return (2*v.numerator+v.denominator)//(2*v.denominator)
    def cumulative(n):
        total=rounded(Fraction((cost-residual)*max(0,min(months,n)),months))
        part=rounded(Fraction(total*(part_cost-part_residual),cost-residual))
        return total,part,total-part
    before=cumulative((year-acquired.year)*12-acquired.month+1)
    after=cumulative((year-acquired.year)*12+13-acquired.month)
    event=cumulative((disposed.year-acquired.year)*12+disposed.month-acquired.month+1)
    old=disposed.year<year;now=disposed.year==year
    opening_cost=0 if acquired.year==year else cost-part_cost if old else cost
    opening_accum=0 if acquired.year==year else before[2 if old else 0]
    depreciation=(event[0]-before[0]+after[2]-event[2]) if now else after[2 if old else 0]-before[2 if old else 0]
    closing_cost=cost-part_cost if disposed.year<=year else cost
    closing_accum=after[2 if disposed.year<=year else 0]
    return dict(cost_opening_cents=opening_cost,additions_cents=cost if acquired.year==year else 0,
        cost_closing_cents=closing_cost,accumulated_opening_cents=opening_accum,depreciation_cents=depreciation,
        accumulated_closing_cents=closing_accum,book_opening_cents=opening_cost-opening_accum,
        book_closing_cents=closing_cost-closing_accum,disposals_cents=part_cost if now else 0,
        accumulated_disposals_cents=event[1] if now else 0,book_disposals_cents=part_cost-event[1] if now else 0)


def item_values(item,year,tax_derived=False):
    p=item.get('partial_plan')
    if not isinstance(p,dict): fail()
    expected=values(p,item.get('disposal'),year,item['months'] if tax_derived else None)
    for field,key in [('basis_cents','basis_cents'),('residual_cents','residual_cents'),('acquired_on','acquired_on'),('sphere','sphere'),('skr_code','account')]:
        if type(item.get(field)) is not type(p.get(key)) or item[field]!=p[key]: fail()
    if not tax_derived and item['months']!=p['months']: fail()
    if item.get('method','linear_monthly')!='linear_monthly' or 'gwg' in item or 'degressive' in item: fail()
    return expected
