"""Independent cost-asset reconstruction from original evidence and reviewed events."""
from copy import deepcopy
from datetime import date, timedelta
import hashlib
import json
import re
from ebilanz_period import check, require

LIMIT = 100000000000
FIELDS = ('cost_opening_cents', 'additions_cents', 'disposals_cents', 'cost_closing_cents',
          'accumulated_opening_cents', 'impairment_cents', 'writeup_cents', 'accumulated_disposals_cents',
          'accumulated_closing_cents', 'book_opening_cents', 'book_disposals_cents', 'book_closing_cents')


def number(value, minimum=0):
    require(type(value) is int and minimum <= value <= LIMIT, 'Bewertungsbeträge als vollständige Centbeträge prüfen.')
    return value


def note(value):
    require(type(value) is str and value.strip() and len(value) <= 2000 and not re.search(r'[\x00-\x1f]', value),
            'Bewertung mit fachlicher Begründung und Rechtsgrundlage bestätigen.')


def document(value):
    require(type(value) is dict and set(value) == {'document_id', 'revision', 'sha256'}
            and type(value['document_id']) is str and re.fullmatch('[a-f0-9]{32}', value['document_id'])
            and type(value['revision']) is int and value['revision'] >= 1
            and type(value['sha256']) is str and re.fullmatch('[a-f0-9]{64}', value['sha256']),
            'Unveränderten Originalbeleg der Bewertung nachweisen.')


def plan_check(plan):
    require(type(plan) is dict and set(plan)-{'target','financial_profile'} == {'kind', 'basis_cents', 'acquired_on', 'sphere', 'account', 'reason', 'document'}
            and plan['kind'] in ('land', 'financial', 'construction', 'intangible_advance') and plan['sphere'] in ('1', '2', '3', '4')
            and type(plan['acquired_on']) is str and re.fullmatch(r'(?:19|20|21)\d{2}-\d{2}-\d{2}', plan['acquired_on'])
            and type(plan['account']) is str and re.fullmatch('[0-9]{5}', plan['account']),
            'Nicht planmäßig abzuschreibende Anlage mit ursprünglichen Anschaffungskosten prüfen.')
    date.fromisoformat(plan['acquired_on']);number(plan['basis_cents'], 1);note(plan['reason']);document(plan['document'])
    from ebilanz_asset_types import TARGET_CHOICES,cost_target
    from ebilanz_financial_profile import check as financial_check
    financial_check(plan)
    if 'target' in plan or len(TARGET_CHOICES.get(plan['account'],()))>1:cost_target(plan)


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()


def reconcile(row):
    require(type(row) is dict and set(row) == set(FIELDS))
    for field in FIELDS:
        number(row[field])
    require(row['cost_opening_cents'] + row['additions_cents'] - row['disposals_cents'] == row['cost_closing_cents']
            and row['accumulated_opening_cents'] + row['impairment_cents'] - row['writeup_cents'] - row['accumulated_disposals_cents'] == row['accumulated_closing_cents']
            and row['cost_opening_cents'] - row['accumulated_opening_cents'] == row['book_opening_cents']
            and row['cost_closing_cents'] - row['accumulated_closing_cents'] == row['book_closing_cents']
            and row['disposals_cents'] - row['accumulated_disposals_cents'] == row['book_disposals_cents']
            and row['book_opening_cents'] + row['additions_cents'] - row['impairment_cents'] + row['writeup_cents'] - row['book_disposals_cents'] == row['book_closing_cents'],
            'Ursprüngliche Kosten, Wertminderung, Wertaufholung und Abgänge stimmen nicht überein.')


def from_book_plan(plan):
    require(type(plan) is dict and set(plan)-{'target','financial_profile'}=={'basis_cents','residual_cents','acquired_on','months','sphere','account','reason','method','valuation_kind','valuation_document'}
            and plan['method']=='cost' and type(plan['months']) is int and plan['months']==0
            and type(plan['residual_cents']) is int and plan['residual_cents']==0)
    result={'kind':plan['valuation_kind'],'basis_cents':plan['basis_cents'],'acquired_on':plan['acquired_on'],
            'sphere':plan['sphere'],'account':plan['account'],'reason':plan['reason'],'document':plan['valuation_document']}
    if 'target' in plan:result['target']=plan['target']
    if 'financial_profile' in plan:result['financial_profile']=plan['financial_profile']
    plan_check(result);return result


def calculate(plan, period, events=None, previous=None, depth=0):
    require(depth <= 100, 'Bewertungsvorträge sind zu umfangreich.');plan_check(plan);period = check(period)
    if events is None:
        events = []
    require(plan['acquired_on'] <= period['end'] and type(events) is list and len(events) <= 200)
    cost = plan['basis_cents'];remaining = cost;values = {'book': cost, 'tax': cost};seen = []
    if previous is not None:
        require(type(previous) is dict and type(previous.get('source')) is dict)
        source = previous['source']
        require(set(source) == {'plan', 'period', 'events', 'previous'} and source['plan'] == plan
                and date.fromisoformat(source['period']['end']) + timedelta(days=1) == date.fromisoformat(period['start'])
                and calculate(source['plan'], source['period'], source['events'], source['previous'], depth + 1) == previous,
                'Bewertungsvortrag wurde verändert oder gehört nicht zum unmittelbaren Vorzeitraum.')
        values = {side: previous[side]['book_closing_cents'] for side in ('book', 'tax')}
        remaining = previous['book']['cost_closing_cents'];seen = list(previous['event_ids'])
    else:
        require(plan['acquired_on'] >= period['start'], 'Vorgetragene Anlage benötigt ihren bestätigten Bewertungsstand.')
    before = plan['acquired_on'] < period['start'];opening_cost = remaining if before else 0
    rows = {side: dict.fromkeys(FIELDS, 0) for side in values}
    for side, row in rows.items():
        row.update(cost_opening_cents=opening_cost, additions_cents=0 if before else cost,
                   book_opening_cents=values[side] if before else 0)
        row['accumulated_opening_cents'] = opening_cost - row['book_opening_cents']
    last = period['start'];movements = []
    for event in events:
        require(type(event) is dict and not set(event) - {'id', 'on', 'kind', 'book_cents', 'tax_cents', 'cost_cents', 'permanent', 'tax_election', 'reason', 'legal_basis', 'document', 'allocation_document', 'confirmed', 'no_proceeds', 'counter_code'}
                and type(event.get('id')) is str and re.fullmatch('[a-f0-9]{32}', event['id']) and event['id'] not in seen
                and type(event.get('on')) is str and plan['acquired_on'] <= event['on'] and last <= event['on'] <= period['end']
                and event.get('confirmed') is True and event.get('kind') in ('impairment', 'writeup', 'disposal'))
        if 'counter_code' in event:
            from ebilanz_financial_profile import counter_code
            require(type(event['counter_code']) is str and plan['kind']=='financial' and 'financial_profile' in plan)
            counter_code(event['kind'],event.get('permanent',True),plan['financial_profile'],event['counter_code'],plan['account'])
        date.fromisoformat(event['on']);note(event.get('reason'));note(event.get('legal_basis'));document(event.get('document'))
        require(remaining > 0, 'Eine vollständig abgegangene Anlage kann nicht erneut bewertet werden.')
        seen.append(event['id']);last = event['on'];changes = {}
        if event['kind'] == 'disposal':
            require(event.get('no_proceeds') is True and not set(event) & {'permanent', 'tax_election'})
            release = number(event.get('cost_cents'), 1);require(release <= remaining);document(event.get('allocation_document'))
            for side in values:
                carrying = number(event.get(side + '_cents'))
                require(carrying <= release and carrying <= values[side] and values[side] - carrying <= remaining - release
                        and (release != remaining or carrying == values[side]),
                        'Teilabgang benötigt die belegten Kosten und Buch-/Steuerwerte des tatsächlich abgegangenen Teils.')
                row = rows[side];row['disposals_cents'] += release;row['book_disposals_cents'] += carrying
                row['accumulated_disposals_cents'] += release - carrying;values[side] -= carrying
                changes[side] = {'impairment_cents': 0, 'writeup_cents': 0, 'book_disposals_cents': carrying}
            remaining -= release
        else:
            require(not set(event) & {'cost_cents', 'allocation_document', 'no_proceeds'}
                    and type(event.get('permanent')) is bool and type(event.get('tax_election')) is bool)
            book = number(event.get('book_cents'));tax = number(event.get('tax_cents'));require(book <= remaining and tax <= remaining)
            if event['kind'] == 'impairment':
                require(book <= values['book'] and (book < values['book'] or tax < values['tax']) and (event['permanent'] or plan['kind'] == 'financial')
                        and tax <= values['tax'] and (event['permanent'] and tax < values['tax'] if event['tax_election'] else tax == values['tax']))
            else:
                require(book >= values['book'] and tax >= values['tax'] and (book > values['book'] or tax > values['tax'])
                        and event['permanent'] is False and event['tax_election'] is False)
            for side, value in (('book', book), ('tax', tax)):
                down, up = max(0, values[side] - value), max(0, value - values[side])
                rows[side]['impairment_cents'] += down;rows[side]['writeup_cents'] += up;values[side] = value
                changes[side] = {'impairment_cents': down, 'writeup_cents': up, 'book_disposals_cents': 0}
        for row in rows.values():
            for value in row.values():
                number(value)
        movements.append({'event_id': event['id'], 'on': event['on'], 'kind': event['kind'], 'book': changes['book'], 'tax': changes['tax']})
    for side, row in rows.items():
        row.update(cost_closing_cents=remaining, book_closing_cents=values[side], accumulated_closing_cents=remaining - values[side]);reconcile(row)
    source = {'plan': deepcopy(plan), 'period': period, 'events': deepcopy(events), 'previous': previous}
    out = {'schema': 1, 'source': source, 'event_ids': seen, 'book': rows['book'], 'tax': rows['tax'], 'movements': movements}
    return out | {'sha256': digest(out)}
