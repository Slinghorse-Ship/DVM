"""Checked dated export boundary; not a new transmission permission.

Retained single-business and explicitly whole-club journals are reconstructed.
Tax-privileged multi-area/combined profiles require their own checked adapter.
"""
from datetime import date, timedelta
from copy import deepcopy
import hashlib
import re


def require(ok, message='Zeitraumbezogene E-Bilanz: Original, Zeitraum oder Vortrag prüfen.'):
    if not ok:
        raise ValueError(message)


def check(period, year=None):
    require(type(period) is dict and set(period) == {'start', 'end'})
    for value in period.values():
        require(type(value) is str and re.fullmatch(r'(?:19|20|21)\d{2}-\d{2}-\d{2}', value))
    start, end = (date.fromisoformat(period[k]) for k in ('start', 'end'))
    # PHP DateTime +1 year rolls leap day into March in a non-leap year.
    try:
        anniversary = start.replace(year=start.year+1)
    except ValueError:
        anniversary = date(start.year+1, 3, 1)
    require(start <= end < anniversary and (year is None or end.year == year))
    return {'start': start.isoformat(), 'end': end.isoformat()}


def verify(values, depth=0, whole=False, section=None):
    """Replay book, tax, review, predecessor and exact signed carry independently."""
    from ebilanz_club_scope import calculate, digest, mapping, amount, lines
    require(depth <= 100 and type(values) is dict)
    require(section in (None,'books','subsidiary') and (values.get('period_section')==section if section else 'period_section' not in values))
    require(section!='books' or whole)
    require(section!='subsidiary' or not whole)
    require(values.get('sha256') == digest({k: v for k, v in values.items() if k not in ('sha256','transmission')})
            and values.get('transmission') == 'unavailable')
    year = values['year']; period = check(values['period'], year)
    require(year in (2025, 2026) and period['start'] >= '2025-01-01',
            'Zeitraumbezogene E-Bilanz: Für diesen Zeitraum fehlt der geprüfte Katalog-/Taxonomiestand.')
    proof = values.get('period_source')
    require(type(proof) is dict and set(proof) == {'source', 'review', 'previous'})
    source, review, previous = (proof[k] for k in ('source','review','previous'))
    require(type(source) is dict and source.get('period') == period and source.get('year') == year
            and source.get('state') == 'closed' and source.get('issues') == []
            and type(source.get('closed_sha256')) is str and re.fullmatch('[a-f0-9]{64}', source['closed_sha256'])
            and source['closed_sha256'] == values['journal_sha256'])
    require('club_scope' not in values)
    if whole:
        require(set(source)=={'year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule','period'})
        require(values.get('whole_club_scope')=={'schema':2,'kind':'whole_taxable_club','source':source})
    else:require('whole_club_scope' not in values and 'capital_comparison' not in values)
    if not whole and section is None:
        require(all(l['sphere'] in ('','4') for e in source['entries']+[{'lines':source['opening']}] for l in e['lines']))
        require(all(i['plan']['sphere']=='4' and 'sphere_change' not in i for i in mapping(source['asset_schedule']['items']).values()))
    chart = source['accounts']
    require(chart.get(source.get('result_account'), {}).get('group') == 'equity')
    book = calculate(year, chart, source['opening'], source['entries'], period)
    require(book == source['calculation'] == values['book'] and values['asset_schedule'] == source['asset_schedule'])
    review_fields={'period','year','journal_sha256','complete','opening_adjustments','current_adjustments','asset_tax_plans'}|({'capital_movements'} if whole and section!='books' else set())
    require(type(review) is dict and set(review) == review_fields
            and review['period'] == period and review['year'] == year and review['journal_sha256'] == source['closed_sha256']
            and review['complete'] is True)
    for field in ('opening_adjustments','current_adjustments'):
        require(type(review[field]) is list)
    require(len(review['opening_adjustments'])+len(review['current_adjustments']) <= 200)
    if section=='books':require(review['opening_adjustments']==review['current_adjustments']==[] and not mapping(review['asset_tax_plans']) and values['book']==values['tax'] and 'capital_comparison' not in values)
    calendar = previous is not None and 'calendar_source' in previous
    if calendar:
        if previous.get('section') in ('club','club_books'):
            from ebilanz_calendar_club import verify as verify_calendar_club
            require(not whole or section=='books')
            prior_period,prior_source,prior_values=verify_calendar_club(previous,'club_books' if section=='books' else 'club')
        else:
            from ebilanz_calendar_source import verify as verify_calendar
            prior_period, prior_source, prior_values = verify_calendar(previous,section or ('whole' if whole else 'business'),depth+1)
    else:
        prior_period = verify(previous,depth+1,whole,section)[0] if previous is not None else None
        prior_source = previous['period_source']['source'] if previous is not None else None
        prior_values = previous
    from ebilanz_period_assets import rebuild as asset_rebuild, MANAGED
    assets=asset_rebuild(values)['proof']
    chart=chart | mapping(assets['additional_accounts'])
    require(values['opening_adjustments']==review['opening_adjustments'] and values['current_adjustments']==review['current_adjustments']+assets['generated_current'])
    managed=set(assets['managed_accounts'])|{k for k,a in chart.items() if a['group']=='fixed_assets' or a['skr_code'] in MANAGED}
    require(all(l['account'] not in managed for e in review['current_adjustments'] for l in e['lines']))
    opening = {k: book['accounts'].get(k,{}).get('opening_cents',0) for k in chart}
    entries = deepcopy(source['entries']); seen = set()
    for field in ('opening_adjustments','current_adjustments'):
        for row in values[field]:
            require(type(row) is dict and set(row) == {'id','reason','lines'} and type(row['id']) is str
                    and re.fullmatch('[a-z0-9_-]{1,40}',row['id']) and row['id'] not in seen)
            seen.add(row['id'])
            require(type(row['reason']) is str and row['reason'].strip() and len(row['reason']) <= 1500
                    and not re.search(r'[\x00-\x1f]',row['reason']))
            lines(row['lines'],chart,field == 'opening_adjustments')
            if field == 'opening_adjustments':
                for line in row['lines']:
                    opening[line['account']] = amount(opening[line['account']]+line['debit_cents']-line['credit_cents'])
            else:
                entries.append({'id': hashlib.sha256(('tax-adjustment-'+row['id']).encode()).hexdigest()[:32],
                                'date': period['end'], 'lines': row['lines']})
    tax_opening = [{'account':k,'debit_cents':max(0,n),'credit_cents':max(0,-n),'sphere':''} for k,n in opening.items() if n]
    tax = calculate(year,chart,tax_opening,entries,period)
    require(tax == values['tax'])
    spheres = sorted({l['sphere'] for e in entries for l in e['lines'] if l['sphere']})
    require(spheres == values['source_spheres'] and (whole or section=='subsidiary' or all(s == '4' for s in spheres)))
    rows = {k: {'name':a['name'],'group':a['group'],'skr_code':a['skr_code'],
                'book_opening_cents':book['accounts'].get(k,{}).get('opening_cents',0),'tax_opening_cents':tax['accounts'][k]['opening_cents'],
                'book_cents':book['accounts'].get(k,{}).get('amount_cents',0),'tax_cents':tax['accounts'][k]['amount_cents'],
                'difference_cents':tax['accounts'][k]['amount_cents']-book['accounts'].get(k,{}).get('amount_cents',0)} for k,a in chart.items()}
    require(rows == values['accounts'] and values['result_difference_cents'] == tax['annual_result_cents']-book['annual_result_cents'])
    if previous is None:
        require(source.get('previous_sha256') is None)
        prior_period = None
    else:
        require(date.fromisoformat(prior_period['end'])+timedelta(days=1) == date.fromisoformat(period['start'])
                and source.get('previous_sha256') == prior_source['closed_sha256']
                and source['result_account'] == prior_source['result_account'])
        for key,a in prior_source['accounts'].items():
            require(key in chart and all(a[f] == chart[key][f] for f in ('group','skr_code')))
        carried = {}; difference = {}
        for key,a in prior_values['book']['accounts'].items():
            if a['group'] in ('expense','revenue'): continue
            n = a['closing_cents']; d = prior_values['tax']['accounts'][key]['closing_cents']-n
            if key == source['result_account']:
                n -= prior_values['book']['annual_result_cents']; d -= prior_values['result_difference_cents']
            if n: carried[key] = n
            if d: difference[key] = d
        require(carried == {l['account']: l['debit_cents']-l['credit_cents'] for l in source['opening']})
        # The retained predecessor's hash includes its source proof. The original
        # PHP valuation hash (used for the carry entry) does not include that proof.
        original_hash = prior_values['sha256'] if calendar else digest({k:v for k,v in prior_values.items() if k not in ('sha256','transmission','period_source','whole_club_scope','capital_comparison','period_section')})
        expected = []
        if difference:
            expected = [{'id':'carry-'+original_hash[:32],
                         'reason':'Unveränderter steuerlicher Wertvortrag aus '+prior_period['start']+' bis '+prior_period['end']+'.',
                         'lines':[{'account':k,'debit_cents':max(0,n),'credit_cents':max(0,-n),'sphere':''} for k,n in sorted(difference.items())]}]
        require(review['opening_adjustments'] == expected)
    if whole and section!='books':
        from ebilanz_whole_club import bvv
        decisions=mapping(review['capital_movements'])
        require(all(type(d) is dict and set(d)=={'kind','reason'} and type(d['reason']) is str for d in decisions.values()))
        normalized={k:d|{'reason':d['reason'].strip()} for k,d in decisions.items()}
        require(mapping(values['capital_comparison']['decisions'])==normalized)
        bvv(values)
    return period, prior_period


def dates(values, profile, *, book_only=False):
    explicit = 'period' in values or any('period' in values.get(s,{}) for s in ('book','tax')) or 'period_source' in values
    year = values['year']
    if not explicit:
        return ({'start':f'{year}-01-01','end':f'{year}-12-31'},
                {'start':f'{year-1}-01-01','end':f'{year-1}-12-31'})
    whole=profile.get('kind')=='whole_taxable_club'
    if 'period_club' in values:
        require(profile.get('kind')=='club_taxable_businesses' and profile.get('source_scope')=='approved_club_allocation' and profile.get('tax_privileged') is True and not book_only)
        from ebilanz_period_club import verify as verify_club
        return verify_club(values)
    if whole:require(profile.get('source_scope')=='complete_club_ledger' and profile.get('tax_privileged') is False and profile.get('asset_scope')=='whole_club')
    else:require(profile.get('kind') == 'single_taxable_business' and profile.get('source_scope') == 'separate_business_ledger',
            'Zeitraumbezogene Vereinsaufteilung noch nicht vollständig an den Steuerexport angebunden.')
    require('period' in values and all(values.get(s,{}).get('period') == values['period'] for s in ('book','tax')))
    return verify(values,whole=whole,section='books' if book_only else None)
