"""Independent original-line reconciliation, no database or network."""
import copy
import json
import os
from pathlib import Path
import runpy
import subprocess
import unittest

ROOT = Path(__file__).resolve().parent
helper = runpy.run_path(str(ROOT / 'balance-internal-clearing-test.py'))
digest, mapping = helper['digest'], helper['mapping']


def verify(value):
    assert value['sha256'] == digest({k: v for k, v in value.items() if k != 'sha256'})
    assert value['schema'] == 1 and value['kind'] == 'club_income_reconciliation'
    assert value['tax_values_reviewed'] is False and value['tax_export_ready'] is False
    scope = value['scope']; source = scope['source']; allocation = scope['allocation']; proof = scope['clearing']
    helper['verify'](proof)
    assert scope['club_id'] == proof['club_id'] and value['year'] == source['year']
    assert all(source[k] == v for k, v in proof['source'].items())
    assert allocation['units'] == proof['units'] and allocation['allocations'] == proof['allocations']
    selected = [u for u, meta in allocation['units'].items() if meta['sphere'] == '4']
    assert value['selected_units'] == selected
    rows = {k: a | dict.fromkeys(['whole_debit_cents', 'whole_credit_cents', 'taxable_debit_cents', 'taxable_credit_cents'], 0)
            for k, a in source['accounts'].items() if a['group'] in ('expense', 'revenue')}
    lines = {}; units = {u: meta | dict(income_cents=0, expense_cents=0, result_cents=0) for u, meta in allocation['units'].items()}
    for voucher in source['entries']:
        for index, line in enumerate(voucher['lines']):
            key = f"{voucher['id']}:{index}"; account = line['account']
            if account not in rows:
                continue
            parts = allocation['allocations'][key]['parts']
            assert sum(parts.values()) == line['debit_cents'] + line['credit_cents']
            assert all(allocation['units'][u]['sphere'] == line['sphere'] for u in parts)
            chosen = sum(parts.get(u, 0) for u in selected)
            side = 'debit' if line['debit_cents'] else 'credit'
            rows[account]['whole_' + side + '_cents'] += line[side + '_cents']
            rows[account]['taxable_' + side + '_cents'] += chosen
            group = 'expense' if rows[account]['group'] == 'expense' else 'income'
            sign = 1 if (group == 'expense') == (side == 'debit') else -1
            for u, amount in parts.items():
                units[u][group + '_cents'] += sign * amount
            lines[key] = dict(voucher=voucher['id'], date=voucher['date'], account=account, sphere=line['sphere'],
                              debit_cents=line['debit_cents'], credit_cents=line['credit_cents'], parts=parts,
                              taxable_debit_cents=chosen if side == 'debit' else 0,
                              taxable_credit_cents=chosen if side == 'credit' else 0)
    for u in units.values():
        u['result_cents'] = u['income_cents'] - u['expense_cents']
    for account, r in rows.items():
        sign = 1 if r['group'] == 'expense' else -1
        r['whole_cents'] = sign * (r['whole_debit_cents'] - r['whole_credit_cents'])
        r['taxable_book_cents'] = sign * (r['taxable_debit_cents'] - r['taxable_credit_cents'])
        r['other_areas_cents'] = r['whole_cents'] - r['taxable_book_cents']
        assert r['whole_cents'] == source['calculation']['accounts'][account]['amount_cents']
    assert rows == value['accounts'] and lines == mapping(value['lines']) and units == value['units']
    whole = sum(u['result_cents'] for u in units.values()); taxable = sum(units[u]['result_cents'] for u in selected)
    assert value['result'] == dict(whole_cents=whole, taxable_book_cents=taxable, other_areas_cents=whole-taxable)
    internal = dict.fromkeys(['opening_advanced_cents', 'opening_received_cents', 'closing_advanced_cents', 'closing_received_cents', 'within_taxable_closing_cents', 'outside_taxable_closing_cents'], 0)
    for c in mapping(proof['cases']).values():
        giver = c['from'] in selected; receiver = c['to'] in selected
        if giver == receiver:
            internal[('within' if giver else 'outside') + '_taxable_closing_cents'] += c['closing_cents']
        else:
            for period in ('opening', 'closing'):
                internal[period + ('_advanced_cents' if giver else '_received_cents')] += c[period + '_cents']
    assert internal == value['internal_balances']


class IncomeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = json.loads(subprocess.check_output([os.environ['VEREIN_FIXTURE_PHP'], str(ROOT / 'fixtures/balance-club-income.php')], encoding='utf8'))

    def test_original_rows_and_both_years(self):
        for pair in self.data:
            for value in pair:
                verify(value)
            self.assertEqual(pair[1]['scope']['previous'], pair[0]['scope'])

    def test_repayments_are_not_profit(self):
        self.assertEqual({p[0]['result']['taxable_book_cents'] for p in self.data}, {1006})
        self.assertEqual({p[1]['result']['taxable_book_cents'] for p in self.data}, {0})

    def reject(self, value):
        value['sha256'] = digest({k: v for k, v in value.items() if k != 'sha256'})
        with self.assertRaises(AssertionError):
            verify(value)

    def test_omitted_business(self):
        v = copy.deepcopy(self.data[0][0]); v['selected_units'].remove('shop'); self.reject(v)

    def test_advance_added_to_income(self):
        v = copy.deepcopy(self.data[0][0]); v['result']['taxable_book_cents'] += 1003; self.reject(v)

    def test_boundary_balance_omitted(self):
        v = copy.deepcopy(self.data[0][1]); v['internal_balances']['closing_received_cents'] = 0; self.reject(v)

    def test_altered_account_and_line(self):
        v = copy.deepcopy(self.data[0][0]); v['accounts']['expense']['taxable_book_cents'] += 1; self.reject(v)
        v = copy.deepcopy(self.data[0][0]); next(iter(v['lines'].values()))['credit_cents'] += 1; self.reject(v)


if __name__ == '__main__':
    unittest.main()
