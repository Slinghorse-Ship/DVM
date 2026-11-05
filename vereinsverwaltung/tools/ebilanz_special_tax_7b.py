"""Bounded original new-regime §7b election; source book plan stays unchanged.

Initial calendar 2025/2026, one newly acquired German rental building without
land, mixed usage or ancillary allocation. Ordinary tax depreciation is §7(4)
3%, not inferred from the independent book useful life. The aid value is an
independently reviewed original value, never a fabricated tax-rate estimate.
"""
from copy import deepcopy
from datetime import date
from ebilanz_pension_offsets import document, FIELDS
from ebilanz_club_scope import digest
from ebilanz_asset_types import BUILDING
from ebilanz_original_depreciation import code as regular_code

CODES = ('62450',)
TARGET = 'is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.impairment'
LIMIT = 100_000_000_000
FLAGS = ('new_dwelling_confirmed', 'german_location_confirmed',
         'building_cost_only_confirmed', 'all_area_eligible_confirmed',
         'qng_eh40_confirmed', 'ten_year_rental_confirmed',
         'clawback_obligations_confirmed', 'business_income_confirmed',
         'aid_value_reviewed', 'ordinary_aid_only_confirmed', 'no_other_elections_confirmed', 'amount_reviewed')
DOCUMENTS = ('construction', 'cost_area', 'qng', 'rental', 'aid', 'election')
KEYS = ('schema', 'year', 'journal_sha256', 'item_id', 'special_account',
        'regular_account', 'special_cents', 'application_on', 'completed_on',
        'area_hundredths', 'aid_value_cents', 'other_aid_cents') + FLAGS + tuple(
            k + '_document' for k in DOCUMENTS)


def require(ok):
    if not ok:
        raise ValueError('Sonderabschreibung7b: Originalkosten, Fläche, Neubau, QNG, '
                         'Mietbindung und getrennte Steuer- und Beihilfewahl abstimmen.')


def original_date(value):
    require(type(value) is str and len(value) == 10)
    try:
        result = date.fromisoformat(value)
    except ValueError:
        raise ValueError('Ursprüngliches Kalenderdatum prüfen.') from None
    require(result.isoformat() == value)
    return result


def validate_rule(rule):
    require(rule.get('special_tax_7b_contract') == '62450@1'
            and rule.get('group') == 'expense'
            and rule.get('condition') == 'detail_choice'
            and rule.get('target') == TARGET
            and rule.get('allowed_targets') == [TARGET]
            and 'book_only' not in rule and 'commercial_contract' not in rule
            and not rule.get('disclosures'))
    if 'codes' in rule:
        require(rule['codes'] == list(CODES))


def amounts(basis, area, special, acquired_on):
    """Integer cents / hundredths m²; no annual proration of the special election."""
    require(all(type(v) is int for v in (basis, area, special))
            and 0 < basis <= LIMIT and 0 < area <= 1_000_000_000)
    acquired = original_date(acquired_on)
    # Scope: cost-area denominator equals exclusively eligible original area.
    require(basis <= area * 5200)
    special_basis = min(basis, area * 4000)
    maximum = special_basis // 20
    require(0 < special <= maximum)
    regular = (basis * 3 * (13 - acquired.month) + 600) // 1200
    require(regular + special <= basis)
    return dict(tax_basis_cents=basis, special_basis_cents=special_basis,
                special_maximum_cents=maximum, tax_regular_cents=regular,
                tax_special_cents=special, tax_closing_cents=basis-regular-special)


def bind(report, source):
    require(report.get('state') == 'closed'
            and type(report.get('year')) is int and report['year'] in (2025, 2026)
            and report.get('previous_sha256') is None
            and 'period' not in report and 'club_scope' not in report
            and type(source) is dict and list(source) == list(KEYS)
            and type(source['schema']) is int and source['schema'] == 1
            and type(source['year']) is int and source['year'] == report['year']
            and source['journal_sha256'] == report['closed_sha256'])
    require(all(type(source[k]) is str and source[k] for k in
                ('item_id', 'special_account', 'regular_account'))
            and all(source[k] is True for k in FLAGS))
    for key in DOCUMENTS:
        document(source[key + '_document'])
    application = original_date(source['application_on'])
    completed = original_date(source['completed_on'])
    require(date(2023, 1, 1) <= application < date(2029, 10, 1))
    items = report['asset_schedule']['items']
    require(type(items) is dict and len(items) == 1 and source['item_id'] in items)
    item = items[source['item_id']]
    acquired = original_date(item.get('acquired_on'))
    require(application <= completed <= acquired and completed.year == acquired.year
            and acquired.year == report['year'])
    require(item.get('method', 'linear_monthly') == 'linear_monthly'
            and not set(item) & {'gwg', 'degressive', 'disposal', 'original_leasing',
                                'exceptional', 'valuation', 'partial_plan', 'substance'}
            and item.get('sphere') == '4' and item.get('residual_cents') == 0
            and item['skr_code'] in BUILDING)
    basis = item['cost_closing_cents']
    calculated = amounts(basis, source['area_hundredths'], source['special_cents'],
                         item['acquired_on'])
    require(item['cost_opening_cents'] == 0 and item['additions_cents'] == basis
            and item['accumulated_opening_cents'] == 0 and item['book_opening_cents'] == 0
            and item['book_closing_cents'] == basis - item['depreciation_cents'])
    book = report['calculation']['accounts']
    require(all(k in book for k in
                (source['special_account'], source['regular_account'], item['account'])))
    special = book[source['special_account']]
    regular = book[source['regular_account']]
    asset = book[item['account']]
    require(special['group'] == 'expense' and special['skr_code'] == '62450'
            and regular['group'] == 'expense' and regular['skr_code'] == regular_code(item)
            and all(type(special.get(f)) is int and special[f] == 0 for f in FIELDS))
    require(asset['opening_cents'] == 0 and asset['debit_cents'] == basis
            and asset['credit_cents'] == item['depreciation_cents']
            and asset['closing_cents'] == item['book_closing_cents']
            and regular['opening_cents'] == 0 and regular['debit_cents'] == item['depreciation_cents']
            and regular['credit_cents'] == 0 and regular['amount_cents'] == item['depreciation_cents'])
    # Bounded ordinary de-minimis case; no DAWI or other same-cost aid.
    require(type(source['aid_value_cents']) is int and 0 < source['aid_value_cents'] <= 30_000_000
            and type(source['other_aid_cents']) is int and 0 <= source['other_aid_cents'] <= 30_000_000
            and source['aid_value_cents'] + source['other_aid_cents'] <= 30_000_000)
    return dict(schema=1, source=deepcopy(source), book_item_sha256=digest(item),
                asset_account=item['account'],
                regular_additional_cents=calculated['tax_regular_cents']-item['depreciation_cents'],
                **calculated)


def active(rows):
    return {k: a for k, a in rows.items() if a['skr_code'] in CODES
            and any(type(a.get(f)) is not int or a[f] != 0 for f in FIELDS)}


def check(values, mapping, book_only=False):
    proof = values.get('special_tax_7b')
    act = active(values['tax']['accounts'])
    require(not active(values['book']['accounts']))
    if proof is None:
        require(not act)
        return
    require(not book_only and type(proof) is dict
            and not set(values) & {'period', 'club_scope', 'asset_tax',
                                   'special_tax_7g', 'basis_reduction_7g'}
            and not values['opening_adjustments'] and 'whole_club_scope' in values
            and bind(values['whole_club_scope']['source'], proof['source']) == proof)
    source = proof['source']
    require(set(act) == {source['special_account']}
            and mapping.get(source['special_account']) == TARGET)
    book, tax = values['book']['accounts'], values['tax']['accounts']
    n, d = source['special_cents'], proof['regular_additional_cents']
    for field in FIELDS:
        delta = (max(0, -d) if field == 'debit_cents' else n + max(0, d)
                 if field == 'credit_cents' else -n-d
                 if field in ('closing_cents', 'amount_cents') else 0)
        require(tax[proof['asset_account']][field] == book[proof['asset_account']][field] + delta)
        delta = (max(0, d) if field == 'debit_cents' else max(0, -d)
                 if field == 'credit_cents' else d
                 if field in ('closing_cents', 'amount_cents') else 0)
        require(tax[source['regular_account']][field] == book[source['regular_account']][field] + delta)
    for field, amount in dict(opening_cents=0, debit_cents=n, credit_cents=0,
                              closing_cents=n, amount_cents=n).items():
        require(tax[source['special_account']][field] == amount)


def prepare(values, mapping, profile, meta, ordinary):
    check(values, mapping)
    require(profile.get('kind') == 'whole_taxable_club'
            and profile.get('asset_scope') == 'whole_club'
            and profile.get('asset_values_unchanged') is False
            and profile.get('asset_tax_values_reviewed') is True)
    proof = values['special_tax_7b']
    base = deepcopy(values)
    base.pop('special_tax_7b')
    base['tax'] = deepcopy(base['book'])
    base['opening_adjustments'], base['current_adjustments'] = [], []
    original = ordinary(base, mapping, profile | {'asset_values_unchanged': True,
                        'asset_tax_values_reviewed': False}, meta, book_derived=True)
    n, d = proof['tax_special_cents'], proof['regular_additional_cents']
    rows, equations = deepcopy(original['rows']), meta['asset_calculations']
    require(equations['accDepr.DeprPeriod'].get('accDepr.DeprPeriod.impairment') == 1)
    for row in rows.values():
        row['accDepr.DeprPeriod.regular'] += d
        row['accDepr.DeprPeriod.impairment'] += n
        for field in ('accDepr.DeprPeriod', 'accDepr'):
            row[field] += n+d
        row['all'] -= n+d
        require(all(row[f] == sum(row[c]*w for c, w in children.items())
                    for f, children in equations.items()))
    totals = deepcopy(original['totals'])
    totals['depreciation_cents'] += n+d
    totals['accumulated_closing_cents'] += n+d
    totals['book_closing_cents'] -= n+d
    return original | dict(rows=rows, totals=totals, sha256=digest(dict(
                           original_schedule=values['asset_schedule'], special_tax_7b=proof)))
