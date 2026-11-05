"""Original annual fiscal pool election and five-year replay only.

No database, HGB assumption, journal permission or export authorization.
Eligibility uses cost after deductible input VAT, including non-deductible VAT.
"""
from copy import deepcopy
from datetime import date
import hashlib,json,re

LIMIT=100000000000

def need(ok):
    if not ok: raise ValueError('Originale Jahreswahl, vollständige Anschaffungen und steuerlichen Sammelposten abstimmen.')

def keys(value,fields): need(type(value) is dict and set(value)==set(fields))
def money(value): need(type(value) is int and 0<=value<=LIMIT)
def day(value):
    need(type(value) is str and bool(re.fullmatch(r'[0-9]{4}-[0-9]{2}-[0-9]{2}',value)))
    return date.fromisoformat(value)
def identifier(value): need(type(value) is str and bool(re.fullmatch('[a-f0-9]{32}',value)))
def note(value): need(type(value) is str and bool(value.strip()) and len(value)<=2000 and not re.search('[\x00-\x1f]',value))
def document(value):
    keys(value,('document_id','revision','sha256'));identifier(value['document_id'])
    need(type(value['revision']) is int and value['revision']>=1 and type(value['sha256']) is str and bool(re.fullmatch('[a-f0-9]{64}',value['sha256'])))
def digest(value): return hashlib.sha256(json.dumps(value,sort_keys=True,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()

def population(source):
    keys(source,('schema','club_id','pool_id','formed_year','population','population_document','population_complete','election','election_document','election_reason','election_confirmed','rounding'))
    need(type(source['schema']) is int and source['schema']==1);identifier(source['pool_id'])
    need(type(source['club_id']) is str and bool(source['club_id'].strip()) and len(source['club_id'])<=128 and not re.search('[\x00-\x1f]',source['club_id']))
    need(type(source['formed_year']) is int and source['formed_year'] in (2025,2026) and source['population_complete'] is True and source['election']=='uniform_fiscal_pool_6_2a' and source['election_confirmed'] is True and source['rounding']=='cumulative_half_up')
    document(source['population_document']);document(source['election_document']);note(source['election_reason'])
    rows=source['population'];need(type(rows) is list and 1<=len(rows)<=200)
    seen=set();invoices=set();eligible=[];excluded=[];cost=0
    for row in rows:
        keys(row,('item_id','invoice_id','invoice_ordinal','acquired_on','transaction_kind','quantity','depreciable_movable','independent_use','net_cents','vat_cents','deductible_cents','basis_cents','document','reason','confirmed'))
        identifier(row['item_id']);identifier(row['invoice_id']);need(row['item_id'] not in seen);seen.add(row['item_id'])
        need(type(row['invoice_ordinal']) is int and 0<=row['invoice_ordinal']<=9999);identity=(row['invoice_id'],row['invoice_ordinal']);need(identity not in invoices);invoices.add(identity)
        need(day(row['acquired_on']).year==source['formed_year'] and row['transaction_kind']=='purchase' and type(row['quantity']) is int and row['quantity']==1 and type(row['depreciable_movable']) is bool and type(row['independent_use']) is bool and row['confirmed'] is True)
        for field in ('net_cents','vat_cents','deductible_cents','basis_cents'):money(row[field])
        # An original invoice may carry foreign/reverse-charge or different VAT;
        # the source stage verifies arithmetic rather than guessing a 19% rate.
        need(row['net_cents']>0 and row['deductible_cents']<=row['vat_cents'] and row['basis_cents']==row['net_cents']+row['vat_cents']-row['deductible_cents'])
        document(row['document']);note(row['reason'])
        if row['depreciable_movable'] and row['independent_use'] and 25000<row['basis_cents']<=100000:
            eligible.append(row['item_id']);cost+=row['basis_cents'];money(cost)
        else:excluded.append(row['item_id'])
    need(cost>0)
    return cost,eligible,excluded

def accumulated(cost,years):
    money(cost);need(type(years) is int and 0<=years<=5)
    return (cost*years+2)//5

def calculate(source,register,previous=None,_depth=0):
    need(type(_depth) is int and 0<=_depth<5)
    cost,eligible,excluded=population(source)
    keys(register,('schema','club_id','pool_id','year','opening_cents','depreciation_cents','closing_cents','physical_departures','document','reason','confirmed'))
    need(type(register['schema']) is int and register['schema']==1 and register['club_id']==source['club_id'] and register['pool_id']==source['pool_id'] and register['confirmed'] is True)
    year=register['year'];formed=source['formed_year'];need(type(year) is int and formed<=year<=formed+4)
    document(register['document']);note(register['reason'])
    for field in ('opening_cents','depreciation_cents','closing_cents'):money(register[field])
    age=year-formed;before=accumulated(cost,age);after=accumulated(cost,age+1)
    opening=0 if age==0 else cost-before
    need(register['opening_cents']==opening and register['depreciation_cents']==after-before and register['closing_cents']==cost-after)
    if previous is None:need(age==0)
    else:
        keys(previous,('schema','source','register','previous','fiscal','eligible_items','excluded_items','departed_items','journal_verified','export_authorized','sha256'))
        expected=calculate(previous['source'],previous['register'],previous['previous'],_depth+1);need(expected==previous)
        need(previous['source']==source and previous['register']['year']+1==year and previous['fiscal']['book_closing_cents']==opening)
    departures=register['physical_departures'];need(type(departures) is list and len(departures)<=len(eligible));departed=list(previous['departed_items']) if previous else []
    for departure in departures:
        keys(departure,('item_id','on','document','reason','confirmed'));identifier(departure['item_id']);need(departure['item_id'] in eligible and departure['item_id'] not in departed and departure['confirmed'] is True)
        on=day(departure['on']);item=next(row for row in source['population'] if row['item_id']==departure['item_id']);need(on.year==year and on>=day(item['acquired_on']))
        document(departure['document']);note(departure['reason']);departed.append(departure['item_id'])
    fiscal=dict(cost_opening_cents=cost if age else 0,additions_cents=0 if age else cost,cost_closing_cents=cost,accumulated_opening_cents=before,depreciation_cents=after-before,accumulated_closing_cents=after,book_opening_cents=opening,book_closing_cents=cost-after,disposals_cents=0,accumulated_disposals_cents=0,book_disposals_cents=0)
    out=dict(schema=1,source=deepcopy(source),register=deepcopy(register),previous=deepcopy(previous),fiscal=fiscal,eligible_items=eligible,excluded_items=excluded,departed_items=departed,journal_verified=False,export_authorized=False)
    return out|{'sha256':digest(out)}
