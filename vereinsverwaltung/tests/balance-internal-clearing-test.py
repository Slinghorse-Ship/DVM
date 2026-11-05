"""Independent verification of the PHP clearing workpaper; synthetic, offline only."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'tools'))
from ebilanz_club_scope import calculate, digest


def mapping(value):
    return {} if value == [] else value


def verify(p):
    source = p['source']
    assert source['calculation'] == calculate(source['year'], source['accounts'], source['opening'], source['entries'])
    payload = {k: v for k, v in p.items() if k != 'sha256'}
    assert p['sha256'] == digest(payload) and p['tax_export_ready'] is False
    year = source['year']
    cases = {}
    if p['previous']:
        prior = p['previous']
        verify(prior)
        assert prior['club_id'] == p['club_id'] and prior['source']['year'] + 1 == year
        assert source['previous_sha256'] == prior['source']['closed_sha256']
        for key, c in mapping(prior['cases']).items():
            cases[key] = c | {'opening_cents': c['closing_cents'], 'advanced_cents': 0, 'repaid_cents': 0}
    else:
        assert source['previous_sha256'] is None
    entries = {v['id']: v for v in source['entries']}
    events = []
    for kind, rows in p['instructions'].items():
        for key, row in mapping(rows).items():
            events.append((entries[row['voucher']]['date'], kind != 'advances', row['voucher'], key, row))
    used = {}
    movements = {}
    for when, repayment, voucher, key, row in sorted(events):
        n = row['cents']
        assert type(n) is int and n > 0
        if not repayment:
            assert key not in cases
            a = {k: v for k, v in p['allocations'].items() if k.startswith(voucher + ':')}
            cases[key] = dict(origin_year=year, origin_voucher=voucher, origin_sha256=digest({'entry': entries[voucher], 'allocations': a}),
                              **{'from': row['from'], 'to': row['to']}, original_cents=n, opening_cents=0, advanced_cents=n,
                              repaid_cents=0, closing_cents=n, repay_by=row['repay_by'], reason=row['reason'], status='open')
            case_id = key
        else:
            case_id = row['advance']
            c = cases[case_id]
            assert n <= c['closing_cents']
            c['closing_cents'] -= n
            c['repaid_cents'] += n
            assert all(source['accounts'][l['account']]['group'] == 'cash' for l in entries[voucher]['lines'])
        c = cases[case_id]
        sender, receiver = (c['to'], c['from']) if repayment else (c['from'], c['to'])
        movements[key] = dict(case=case_id, voucher=voucher, date=when, kind='repayment' if repayment else 'advance',
                              **{'from': sender, 'to': receiver}, cents=n, reason=row['reason'])
        parts = used.setdefault(voucher, {})
        parts[sender] = parts.get(sender, 0) + n
        parts[receiver] = parts.get(receiver, 0) - n
    for voucher, claimed in used.items():
        expected, cash = {}, {}
        for i, line in enumerate(entries[voucher]['lines']):
            parts = p['allocations'][voucher + ':' + str(i)]['parts']
            assert sum(parts.values()) == line['debit_cents'] + line['credit_cents']
            for unit, n in parts.items():
                assert type(n) is int and n > 0 and unit in p['units']
                net = n if line['credit_cents'] else -n
                expected[unit] = expected.get(unit, 0) + net
                if source['accounts'][line['account']]['group'] == 'cash':
                    cash[unit] = cash.get(unit, 0) + net
        assert claimed == {u: n for u, n in expected.items() if n}
        assert all(cash.get(u, 0) >= n for u, n in claimed.items() if n > 0)
    for c in cases.values():
        c['status'] = 'settled' if c['closing_cents'] == 0 else ('overdue' if c['repay_by'] < str(year) + '-12-31' else 'open')
        assert c['opening_cents'] + c['advanced_cents'] - c['repaid_cents'] == c['closing_cents']
    assert cases == mapping(p['cases']) and movements == mapping(p['movements'])
    assert p['totals'] == {f: sum(c[f] for c in cases.values()) for f in ('opening_cents', 'advanced_cents', 'repaid_cents', 'closing_cents')}


class ClearingTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = json.loads(subprocess.check_output([os.environ['VEREIN_FIXTURE_PHP'], str(ROOT / 'tests/fixtures/balance-internal-clearing.php')], encoding='utf8'))

    def test_independent_reconstruction(self):
        for name, p in self.data.items():
            with self.subTest(year=name):
                verify(p)

    def test_partial_and_next_year(self):
        self.assertEqual(self.data['initial']['totals']['closing_cents'], 903)
        self.assertEqual(self.data['next']['totals'], dict(opening_cents=903, advanced_cents=0, repaid_cents=234, closing_cents=669))
        self.assertEqual(self.data['next']['source']['calculation']['annual_result_cents'], 0)

    def test_rehashed_changes_rejected(self):
        for field in ('closing_cents', 'opening_cents', 'advanced_cents', 'repaid_cents'):
            p = copy.deepcopy(self.data['initial'])
            p['cases'][next(iter(p['cases']))][field] += 1
            p['sha256'] = digest({k: v for k, v in p.items() if k != 'sha256'})
            with self.subTest(field=field), self.assertRaises(AssertionError):
                verify(p)

    def test_direction_is_bound_to_source(self):
        p = copy.deepcopy(self.data['initial'])
        row = next(iter(p['instructions']['advances'].values()))
        row['from'], row['to'] = row['to'], row['from']
        p['sha256'] = digest({k: v for k, v in p.items() if k != 'sha256'})
        with self.assertRaises(AssertionError):
            verify(p)

if __name__ == '__main__':
    unittest.main()
