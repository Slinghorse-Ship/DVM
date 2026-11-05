"""Independent reconciliation of original accounts and internal advances; offline fixtures."""
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
GROUPS = {
    **dict.fromkeys(['fixed_assets', 'inventory', 'receivables', 'cash', 'other_assets', 'prepaid'], 'asset'),
    **dict.fromkeys(['equity', 'reserves'], 'capital'),
    **dict.fromkeys(['provisions', 'liabilities', 'member_payables', 'deferred'], 'liability'),
    'revenue': 'income', 'expense': 'expense',
}


def verify(data):
    source, allocation, proof, statement = (data[k] for k in ['source', 'allocation', 'proof', 'statement'])
    helper['verify'](proof)
    assert statement['sha256'] == digest({k: v for k, v in statement.items() if k != 'sha256'})
    assert statement['journal_sha256'] == source['closed_sha256']
    assert statement['allocation_sha256'] == allocation['sha256'] and statement['clearing_sha256'] == proof['sha256']
    assert statement['club_calculation'] == source['calculation'] == proof['source']['calculation']
    assert statement['complete'] is True and statement['issues'] == [] and statement['tax_export_ready'] is False
    assert set(statement['units']) == set(allocation['units'])
    for unit, meta in allocation['units'].items():
        actual = statement['units'][unit]
        assert actual['name'] == meta['name'] and actual['sphere'] == meta['sphere']
        accounts = {k: v | dict.fromkeys(['opening_cents', 'debit_cents', 'credit_cents', 'closing_cents', 'amount_cents'], 0)
                    for k, v in source['accounts'].items()}
        internal = dict.fromkeys(['opening_advance_cents', 'opening_owed_cents', 'closing_advance_cents', 'closing_owed_cents'], 0)
        for c in mapping(proof['cases']).values():
            for period in ['opening', 'closing']:
                if unit == c['from']:
                    internal[period + '_advance_cents'] += c[period + '_cents']
                if unit == c['to']:
                    internal[period + '_owed_cents'] += c[period + '_cents']
        assert internal == actual['internal']
        vouchers = {'opening': source['opening']} | {v['id']: v['lines'] for v in source['entries']}
        for voucher, lines in vouchers.items():
            net = 0
            for ordinal, line in enumerate(lines):
                account = line['account']
                capital = mapping(allocation.get('capital_carry', {}))
                if voucher == 'opening' and account in capital:
                    continue
                row = allocation['allocations'][f'{voucher}:{ordinal}']
                assert sum(row['parts'].values()) == line['debit_cents'] + line['credit_cents']
                n = row['parts'].get(unit, 0)
                sign = 1 if line['debit_cents'] else -1
                net += sign * n
                if voucher == 'opening':
                    accounts[account]['opening_cents'] += sign * n
                else:
                    accounts[account]['debit_cents' if sign > 0 else 'credit_cents'] += n
            if voucher == 'opening':
                for account, parts in mapping(allocation.get('capital_carry', {})).items():
                    n = parts.get(unit, 0)
                    net += n
                    accounts[account]['opening_cents'] += n
                net += internal['opening_advance_cents'] - internal['opening_owed_cents']
            else:
                transfer = mapping(allocation.get('transfers', {})).get(voucher)
                if transfer:
                    n = transfer['parts'].get(unit, 0)
                    net += n
                    accounts[transfer['account']]['debit_cents' if n > 0 else 'credit_cents'] += abs(n)
                for m in mapping(proof['movements']).values():
                    if m['voucher'] == voucher:
                        net += m['cents'] * ((unit == m['from']) - (unit == m['to']))
            assert net == 0, (unit, voucher, net)
        totals = dict.fromkeys(['asset', 'capital', 'liability', 'income', 'expense'], 0)
        for row in accounts.values():
            row['closing_cents'] = row['opening_cents'] + row['debit_cents'] - row['credit_cents']
            side = GROUPS[row['group']]
            row['amount_cents'] = row['closing_cents'] * (1 if side in ('asset', 'expense') else -1)
            totals[side] += row['amount_cents']
        assert accounts == actual['accounts'] and actual['unbalanced'] == []
        result = totals['income'] - totals['expense']
        passive = totals['capital'] + totals['liability'] + result
        assert totals['asset'] + internal['closing_advance_cents'] == passive + internal['closing_owed_cents']
        assert actual['totals'] == dict(asset_total_cents=totals['asset'], capital_total_cents=totals['capital'],
                                        liability_total_cents=totals['liability'], income_cents=totals['income'],
                                        expense_cents=totals['expense'], annual_result_cents=result,
                                        passive_total_cents=passive, difference_cents=0)
    assert statement['eliminated_internal_cents'] == proof['totals']
    for account, row in source['calculation']['accounts'].items():
        for field in ['opening_cents', 'debit_cents', 'credit_cents', 'closing_cents', 'amount_cents']:
            total = sum(u['accounts'][account][field] for u in statement['units'].values())
            if field in ('debit_cents', 'credit_cents'):
                total -= sum(max(0, n) for t in mapping(allocation.get('transfers', {})).values()
                             if t['account'] == account for n in t['parts'].values())
            assert total == row[field]


class StatementTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = json.loads(subprocess.check_output([os.environ['VEREIN_FIXTURE_PHP'], str(ROOT / 'fixtures/balance-clearing-statement.php')], encoding='utf8'))

    def test_independent_original_reconciliation(self):
        for data in self.data:
            with self.subTest(year=data['source']['year']):
                verify(data)

    def test_original_capital_and_result_carry(self):
        for prior, current in zip(self.data, self.data[1:]):
            for unit, values in prior['statement']['units'].items():
                for account, row in values['accounts'].items():
                    expected = 0 if row['group'] in ('revenue', 'expense') else row['closing_cents']
                    if account == prior['source']['result_account']:
                        expected -= values['totals']['annual_result_cents']
                    self.assertEqual(current['statement']['units'][unit]['accounts'][account]['opening_cents'], expected)

    def test_rehashed_result_cannot_change_original_accounts(self):
        data = copy.deepcopy(self.data[1])
        data['statement']['units']['food']['accounts']['cash']['opening_cents'] += 1
        self.rehash_and_reject(data)

    def test_balanced_internal_inflation_is_not_accepted(self):
        data = copy.deepcopy(self.data[1])
        for unit, field in [('club', 'closing_advance_cents'), ('shop', 'closing_owed_cents')]:
            data['statement']['units'][unit]['internal'][field] += 100
        self.rehash_and_reject(data)

    def test_external_debt_must_not_include_internal_advance(self):
        data = copy.deepcopy(self.data[1])
        data['statement']['units']['shop']['totals']['liability_total_cents'] += 669
        self.rehash_and_reject(data)

    def rehash_and_reject(self, data):
        data['statement']['sha256'] = digest({k: v for k, v in data['statement'].items() if k != 'sha256'})
        with self.assertRaises(AssertionError):
            verify(data)


if __name__ == '__main__':
    unittest.main()
