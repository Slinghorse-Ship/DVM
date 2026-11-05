"""Independent original HGB register for a bounded matching five-year cohort.

The fiscal pool election never supplies an HGB life or original HGB values.
Different lives, mid-year acquisition and physical exits require their own bridge.
"""
from copy import deepcopy
from ebilanz_pool_original_sources import calculate,keys,need,document,note,money,digest

def verify(pool_proof,book,previous=None,_depth=0):
    need(type(_depth) is int and 0<=_depth<2)
    keys(pool_proof,('schema','source','register','previous','fiscal','eligible_items','excluded_items','departed_items','journal_verified','export_authorized','sha256'))
    need(calculate(pool_proof['source'],pool_proof['register'],pool_proof['previous'])==pool_proof)
    keys(book,('schema','club_id','pool_id','year','basis','items','document','reason','confirmed'))
    source=pool_proof['source'];year=pool_proof['register']['year'];formed=source['formed_year'];age=year-formed
    need(type(book['schema']) is int and book['schema']==1 and book['club_id']==source['club_id'] and book['pool_id']==source['pool_id'] and type(book['year']) is int and book['year']==year and year in (2025,2026) and book['basis']=='original_individual_hgb_register' and book['confirmed'] is True)
    document(book['document']);note(book['reason']);items=book['items'];need(type(items) is list and {r.get('item_id') for r in items if type(r) is dict}==set(pool_proof['eligible_items']) and len(items)==len(pool_proof['eligible_items']))
    totals=dict.fromkeys(pool_proof['fiscal'],0);seen=set()
    for row in items:
        keys(row,('item_id','acquired_on','original_cost_cents','original_life_months','original_residual_cents','cost_opening_cents','additions_cents','cost_closing_cents','accumulated_opening_cents','depreciation_cents','accumulated_closing_cents','book_opening_cents','book_closing_cents','document','reason','confirmed'))
        need(row['item_id'] not in seen);seen.add(row['item_id']);original=next(r for r in source['population'] if r['item_id']==row['item_id'])
        document(row['document']);note(row['reason']);need(row['confirmed'] is True and row['acquired_on']==original['acquired_on']==f'{formed}-01-01' and type(row['original_life_months']) is int and row['original_life_months']==60 and type(row['original_residual_cents']) is int and row['original_residual_cents']==0)
        cost=row['original_cost_cents'];money(cost);need(cost==original['basis_cents'])
        before=(cost*age+2)//5;after=(cost*(age+1)+2)//5
        expected=dict(cost_opening_cents=cost if age else 0,additions_cents=0 if age else cost,cost_closing_cents=cost,accumulated_opening_cents=before,depreciation_cents=after-before,accumulated_closing_cents=after,book_opening_cents=cost-before if age else 0,book_closing_cents=cost-after)
        for field,amount in expected.items():money(row[field]);need(row[field]==amount);totals[field]+=amount;money(totals[field])
    need(totals==pool_proof['fiscal'] and not pool_proof['departed_items'])
    if previous is None:need(age==0 and pool_proof['previous'] is None)
    else:
        keys(previous,('schema','pool_proof','book_source','previous','hgb','journal_verified','export_authorized','sha256'))
        need(verify(previous['pool_proof'],previous['book_source'],previous['previous'],_depth+1)==previous and previous['pool_proof']==pool_proof['previous'])
        need(previous['book_source']['year']+1==year)
        old={r['item_id']:r for r in previous['book_source']['items']}
        for row in items:
            original=old[row['item_id']]
            need(all(row[k]==original[k] for k in ('item_id','acquired_on','original_cost_cents','original_life_months','original_residual_cents')))
            need(row['book_opening_cents']==original['book_closing_cents'] and row['accumulated_opening_cents']==original['accumulated_closing_cents'])
    out=dict(schema=1,pool_proof=deepcopy(pool_proof),book_source=deepcopy(book),previous=deepcopy(previous),hgb=totals,journal_verified=False,export_authorized=False)
    return out|{'sha256':digest(out)}
