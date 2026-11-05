"""Reconstruct immutable calendar evidence for a dated original/SGEB carry.

No rewritten hashes, manufactured dated closing, or transmission permission.
"""
from copy import deepcopy
from ebilanz_club_scope import mapping, require
from ebilanz_period_asset_math import FIELDS, flat, schedule


def assets(subsidiary, values, section):
    source = subsidiary['income']['scope']['source']
    plans = {} if section == 'books' else mapping(subsidiary['review']['asset_tax_plans'])
    return asset_values(source, subsidiary['income']['scope']['allocation'], values, plans)


def asset_values(source, allocation, values, plans):
    year = source['year']; period = {'start': f'{year}-01-01', 'end': f'{year}-12-31'}
    original = source['asset_schedule']
    shares = mapping(allocation.get('asset_shares', [])) if allocation is not None else {}
    stored = values.get('asset_tax', {}).get('tax_schedule', original)
    items = {}; tax_items = {}
    for key, item in mapping(original['items']).items():
        require((allocation is None or key in shares) and key in mapping(stored['items']))
        if item.get('method')=='cost':
            from ebilanz_asset_valuation import calculate,from_book_plan,FIELDS as COST_FIELDS
            require(key not in plans,'Kostenbewertung benötigt ihren eigenen unveränderten Nutzungsvortrag.')
            value=item['valuation'];proof=value['source'];p=item['cost_plan'];side='book' if values.get('annual_section')=='books' else 'tax'
            require(from_book_plan(p)==proof['plan'] and proof['period']==period and calculate(proof['plan'],period,proof['events'],proof['previous'])==value)
            require(all(item[f]==value['book'][f] and stored['items'][key][f]==value[side][f] for f in COST_FIELDS))
            if allocation is not None:
                from ebilanz_asset_allocation import item_parts
                parts=item_parts(item,shares[key]['weights'],year)
                require(all(mapping(allocation['asset_schedules'][u]['items']).get(key,{}).get(f)==n for u,row in parts.items() for f,n in row.items()))
            items[key]={'item_id':key,'name':item.get('name',item.get('receipt_number',key)),'fixed_account':item['account'],'plan':p,
                'schedule':{'period':period,'depreciation_cents':0}|value['book'],'valuation':value,'valuation_accounts':item['valuation_accounts']}
            tax_items[key]={'tax_review':None,'schedule':{'period':period,'depreciation_cents':0}|value[side],'valuation_sha256':value['sha256']}
            continue
        p = {'basis_cents': item.get('basis_cents', item['cost_closing_cents']),
             'account': item['skr_code'], 'sphere': item['sphere'], 'method': item.get('method', 'linear_monthly')}
        for field in ('acquired_on', 'months', 'residual_cents', 'gwg', 'degressive'):
            if field in item: p[field] = deepcopy(item[field])
        p = deepcopy(item.get('partial_plan', p)); book = tax = None; review = plans.get(key)
        tp = p | {'months': review['months']} if review else p
        for current in range(int(p['acquired_on'][:4]), year+1):
            interval = {'start': f'{current}-01-01', 'end': f'{current}-12-31'}
            disposal = item.get('disposal')
            if disposal and disposal['date'] > interval['end']: disposal = None
            book = schedule(p, interval, book, disposal)
            tax = schedule(p, interval, tax, disposal, review['months']) if review and disposal and disposal['kind'] == 'partial_scrap' else schedule(tp, interval, tax, disposal)
        require(book is not None and flat(book) == {f: item.get(f, 0) for f in FIELDS})
        require(flat(tax) == {f: stored['items'][key].get(f, 0) for f in FIELDS})
        items[key] = {'item_id': key, 'name': item.get('name', item.get('receipt_number', key)),
                      'fixed_account': item['account'], 'plan': p, 'schedule': book}
        if 'disposal' in item: items[key]['disposal'] = deepcopy(item['disposal'])
        tax_items[key] = {'tax_review': review, 'schedule': tax}
    require(set(mapping(stored['items'])) == set(tax_items))
    retained = {'period': period, 'items': items, 'shares': (allocation or {}).get('asset_shares', []),
                'schedules': (allocation or {}).get('asset_schedules', []), 'issues': []} if items else None
    return retained, {'period': period, 'tax_schedule': {'items': tax_items or []}}


def packet(subsidiary):
    """Arithmetic proof for the subsidiary branch, never an exportable profile."""
    require('period' not in subsidiary)
    values = subsidiary['valuation']; retained, tax = assets(subsidiary, values, 'subsidiary')
    return {'calendar_source': subsidiary, 'section': 'subsidiary', 'values': values,
            'asset_schedule': retained, 'asset_tax': tax}


def verify(packet, section, depth=0):
    from ebilanz_subsidiary import verify as verify_subsidiary, sealed
    from ebilanz_whole_club import validate, bvv, validate_tax
    from ebilanz_mapping import automatic_mapping
    from ebilanz_assets import prepare_assets
    from ebilanz_builder import EbilanzBuilder
    require(depth <= 100 and section in ('books', 'subsidiary', 'whole', 'business') and set(packet) == {'calendar_source','section','values','asset_schedule','asset_tax'} and packet['section'] == section)
    s = packet['calendar_source']; v = packet['values']; sealed(v, True)
    require('period' not in s and 'period' not in v and v['year'] in (2025, 2026))
    profile = {'kind':'whole_taxable_club','source_scope':'complete_club_ledger','tax_privileged':False,
               'asset_scope':'whole_club','asset_values_unchanged':True,'asset_tax_values_reviewed':False}
    meta = EbilanzBuilder().meta
    m = automatic_mapping(v, {})
    if section == 'business':
        from ebilanz_club_scope import calculate
        require(type(s) is dict and set(s)=={'source','review'} and not any(k in v for k in ('whole_club_scope','club_scope','capital_comparison')))
        source, review = s['source'], s['review']
        require(set(source)=={'year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule'}
                and source['year']==v['year'] and source['state']=='closed' and source['issues']==[] and source['closed_sha256']==v['journal_sha256']
                and source['asset_schedule']==v['asset_schedule'] and source['accounts'][source['result_account']]['group']=='equity')
        require(calculate(source['year'],source['accounts'],source['opening'],source['entries'])==source['calculation']==v['book'])
        require(all(l['sphere'] in ('','4') for e in source['entries']+[{'lines':source['opening']}] for l in e['lines'])
                and all(i['sphere']=='4' for i in mapping(source['asset_schedule']['items']).values()))
        require(type(review) is dict and {'year','journal_sha256','complete','opening_adjustments','current_adjustments'} <= set(review)
                and set(review) <= {'year','journal_sha256','complete','opening_adjustments','current_adjustments','asset_tax_plans'}
                and review['year']==v['year'] and review['journal_sha256']==v['journal_sha256'] and review['complete'] is True)
        evidence=v.get('asset_tax',{}); plans=mapping(review.get('asset_tax_plans',[]))
        require(plans=={key:item['plan'] for key,item in mapping(evidence.get('items',[])).items() if 'plan' in item})
        for field, generated in [('opening_adjustments','generated_opening'),('current_adjustments','generated_current')]:
            require(type(review[field]) is list and review[field]+evidence.get(generated,[])==v[field])
        profile.update(kind='single_taxable_business',source_scope='separate_business_ledger',asset_scope='single_reported_business',
                       asset_values_unchanged=not bool(evidence),asset_tax_values_reviewed=bool(evidence))
        validate_tax(v,source); prepare_assets(v,m,profile,meta)
        retained,tax=asset_values(source,None,v,plans)
        require(packet['asset_schedule']==retained and packet['asset_tax']==tax)
        return tax['period'],source,v
    if section == 'whole':
        require(type(s) is dict and set(s) == {'whole_club_scope','review'} and s['whole_club_scope'] == v['whole_club_scope'])
        source = s['whole_club_scope']['source']; review = s['review']
        require(type(review) is dict and set(review) == {'year','journal_sha256','complete','opening_adjustments','current_adjustments','asset_tax_plans','capital_movements'}
                and review['year'] == v['year'] and review['journal_sha256'] == v['journal_sha256'] and review['complete'] is True)
        evidence = v.get('asset_tax', {})
        require(mapping(review['asset_tax_plans']) == {key:item['plan'] for key,item in mapping(evidence.get('items', [])).items() if 'plan' in item})
        for field, generated in [('opening_adjustments','generated_opening'), ('current_adjustments','generated_current')]:
            require(type(review[field]) is list and review[field] + evidence.get(generated, []) == v[field])
        decisions=mapping(review['capital_movements'])
        require(all(type(d) is dict and set(d)=={'kind','reason'} and type(d['reason']) is str for d in decisions.values()))
        require({k:d|{'reason':d['reason'].strip()} for k,d in decisions.items()} == mapping(v['capital_comparison']['decisions']))
        profile.update(asset_values_unchanged=not bool(evidence), asset_tax_values_reviewed=bool(evidence))
        validate(v, profile); bvv(v); prepare_assets(v, m, profile, meta)
        retained, tax = asset_values(source, None, v, mapping(review['asset_tax_plans']))
        require(packet['asset_schedule'] == retained and packet['asset_tax'] == tax)
        return tax['period'], source, v
    source = s['income']['scope']['source']
    verify_subsidiary(s, m, profile, meta, depth+1)
    if section == 'books':
        require(v['whole_club_scope']['source'] == source and v['opening_adjustments'] == v['current_adjustments'] == [] and v['book'] == v['tax'] and 'asset_tax' not in v)
        validate(v, profile, book_only=True)
        prepare_assets(v, m, profile, meta)
    else:
        require(v == s['valuation'])
    retained, tax = assets(s, v, section)
    require(packet['asset_schedule'] == retained and packet['asset_tax'] == tax)
    return tax['period'], source, v
