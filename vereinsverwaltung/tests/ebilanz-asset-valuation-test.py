"""Independent original-cost valuation, carries, recoveries and repeated disposals."""
import copy
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / 'tools'))
from ebilanz_asset_valuation import calculate, reconcile


def document(label):
    return {'document_id': hashlib.md5(label.encode()).hexdigest(), 'revision': 1,
            'sha256': hashlib.sha256(label.encode()).hexdigest()}


def plan(kind='land', basis=100001):
    result={'kind': kind, 'basis_cents': basis, 'acquired_on': '2025-04-17', 'sphere': '4',
            'account': '02150' if kind == 'land' else '09000', 'reason': 'Synthetic reviewed original cost',
            'document': document('Synthetic original purchase')}
    if kind=='financial':result.update(target='bs.ass.fixAss.fin.securities.bonds',financial_profile='bond_de',reason='Synthetic confirmed domestic bond original cost')
    return result


def event(kind, when, book, tax, **extra):
    label = kind + when + str(book) + str(tax)
    if kind == 'disposal':
        extra.setdefault('no_proceeds', True)
    return {'id': hashlib.md5(label.encode()).hexdigest(), 'on': when, 'kind': kind, 'book_cents': book, 'tax_cents': tax,
            'reason': 'Synthetic individually reviewed value', 'legal_basis': 'Synthetic checked valuation basis',
            'document': document('Synthetic ' + label), 'confirmed': True, **extra}


def request(p, period, events=None, previous=None):
    return {'plan': p, 'period': period, 'events': events or [], 'previous': previous}


def php(requests):
    executable = Path(os.environ['VEREIN_FIXTURE_PHP'])
    if not executable.is_absolute() or not executable.is_file():
        raise ValueError('Explicit fixture PHP required.')
    r = subprocess.run([str(executable), str(HERE / 'fixtures/asset-valuation.php')],
                       input=json.dumps(requests, ensure_ascii=False), capture_output=True, text=True, encoding='utf8', timeout=30)
    if r.returncode or r.stderr:
        raise ValueError(r.stdout + r.stderr)
    return json.loads(r.stdout)


class Valuation(unittest.TestCase):
    def check_rows(self, requests, denied=False):
        result = php(requests)
        self.assertEqual(len(result), len(requests))
        for r, actual in zip(requests, result):
            with self.subTest(period=r['period'], events=r['events']):
                if denied:
                    self.assertIn('rejected', actual)
                    with self.assertRaises(ValueError):
                        calculate(**r)
                else:
                    expected = calculate(**r)
                    self.assertEqual(actual, {'value': expected})
                    reconcile(expected['book']);reconcile(expected['tax'])
        return result

    def test_original_cost_without_invented_depreciation_or_full_month_boundaries(self):
        rows = []
        for kind in ('land', 'financial'):
            for cost in (1, 33333, 100001, 99999999999):
                p = plan(kind, cost);previous = None
                for period in ({'start': '2025-04-17', 'end': '2025-07-22'}, {'start': '2025-07-23', 'end': '2026-06-15'},
                               {'start': '2026-06-16', 'end': '2026-12-31'}):
                    r = request(p, period, previous=previous);rows.append(r);previous = calculate(**r)
                    self.assertEqual(previous['book']['book_closing_cents'], cost)
                    self.assertEqual(previous['tax'], previous['book'])
        self.check_rows(rows)

    def test_permanent_book_impairment_tax_election_and_bounded_recovery(self):
        p = plan();first = {'start': '2025-01-01', 'end': '2025-12-31'}
        down = event('impairment', '2025-12-31', 60000, 80000, permanent=True, tax_election=True)
        r1 = request(p, first, [down]);v1 = calculate(**r1)
        self.assertEqual(v1['book']['impairment_cents'], 40001)
        self.assertEqual(v1['tax']['impairment_cents'], 20001)
        second = {'start': '2026-01-01', 'end': '2026-12-31'}
        up = event('writeup', '2026-12-31', 100001, 100001, permanent=False, tax_election=False)
        r2 = request(p, second, [up], v1);v2 = calculate(**r2)
        self.assertEqual(v2['book']['writeup_cents'], 40001);self.assertEqual(v2['tax']['writeup_cents'], 20001)
        self.assertEqual(v2['book']['cost_closing_cents'], p['basis_cents'])
        self.check_rows([r1, r2])

    def test_financial_temporary_loss_keeps_tax_value_and_later_permanent_tax_choice(self):
        p = plan('financial');period = {'start': '2025-04-17', 'end': '2026-04-16'}
        down = event('impairment', '2026-04-16', 60000, 100001, permanent=False, tax_election=False)
        r1 = request(p, period, [down]);v1 = calculate(**r1)
        self.assertEqual(v1['tax']['impairment_cents'], 0)
        following = {'start': '2026-04-17', 'end': '2026-12-31'}
        tax = event('impairment', '2026-12-31', 60000, 60000, permanent=True, tax_election=True)
        r2 = request(p, following, [tax], v1);v2 = calculate(**r2)
        self.assertEqual(v2['book']['impairment_cents'], 0)
        self.assertEqual(v2['tax']['impairment_cents'], 40001)
        self.check_rows([r1, r2])

    def test_several_documented_component_disposals_and_recovery_preserve_all_costs(self):
        p = plan();rows = []
        down = event('impairment', '2025-12-31', 60000, 80000, permanent=True, tax_election=True)
        r = request(p, {'start': '2025-01-01', 'end': '2025-12-31'}, [down]);rows.append(r);old = calculate(**r)
        first = event('disposal', '2026-03-15', 20000, 26666, cost_cents=33333, allocation_document=document('First cost component'))
        up = event('writeup', '2026-06-30', 65000, 66668, permanent=False, tax_election=False)
        second = event('disposal', '2026-09-09', 15000, 15000, cost_cents=15000, allocation_document=document('Second cost component'))
        last = event('disposal', '2026-12-31', 50000, 51668, cost_cents=51668, allocation_document=document('Remaining component'))
        r = request(p, {'start': '2026-01-01', 'end': '2026-12-31'}, [first, up, second, last], old);rows.append(r);v = calculate(**r)
        self.assertEqual(v['book']['cost_closing_cents'], 0);self.assertEqual(v['tax']['book_closing_cents'], 0)
        self.assertEqual(v['book']['disposals_cents'], p['basis_cents']);self.assertEqual(len(v['event_ids']), 5)
        self.assertEqual(v['source']['previous'], old)
        following = request(p, {'start': '2027-01-01', 'end': '2027-12-31'}, previous=v);rows.append(following)
        self.assertEqual(sum(calculate(**following)['book'].values()), 0)
        self.check_rows(rows)

    def test_invalid_policy_and_missing_or_changed_proof_fail_in_both_implementations(self):
        p = plan();period = {'start': '2025-04-17', 'end': '2025-12-31'}
        good = event('impairment', '2025-12-31', 60000, 80000, permanent=True, tax_election=True)
        rows = []
        for field, value in [('book_cents', True), ('tax_cents', -1), ('confirmed', False), ('permanent', False),
                             ('tax_election', False), ('reason', ''), ('legal_basis', '\n'), ('on', '2025-02-30'),
                             ('on', '2026-01-01'), ('on', '2025-04-16'), ('document', {}), ('kind', 'goodwill'),
                             ('cost_cents', 123), ('unknown', 1), ('id', '../bad')]:
            rows.append(request(p, period, [good | {field: value}]))
        rows.append(request(p, period, [good, good]))
        rows.append(request(p | {'acquired_on': '2025-02-30'}, period))
        rows.append(request(p | {'basis_cents': 0}, period))
        rows.append(request(p | {'basis_cents': True}, period))
        rows.append(request(p | {'kind': 'building'}, period))
        self.check_rows(rows, True)

    def test_no_upward_revaluation_or_resealed_changed_predecessor(self):
        p = plan();first = {'start': '2025-01-01', 'end': '2025-12-31'}
        down = event('impairment', '2025-12-31', 50000, 60000, permanent=True, tax_election=True)
        old = calculate(p, first, [down]);period = {'start': '2026-01-01', 'end': '2026-12-31'};rows = []
        for b, t in ((100002, 100001), (100001, 100002), (49999, 60000), (50000, 59999), (50000, 60000)):
            rows.append(request(p, period, [event('writeup', '2026-12-31', b, t, permanent=False, tax_election=False)], old))
        for field in ('cost_closing_cents', 'book_closing_cents', 'accumulated_closing_cents', 'impairment_cents'):
            changed = copy.deepcopy(old);changed['book'][field] += 1
            rows.append(request(p, period, previous=changed))
        rows.append(request(p, period, previous=old | {'event_ids': []}))
        rows.append(request(p, period, previous=old | {'sha256': 'f' * 64}))
        rows.append(request(p, period | {'start': '2026-01-02'}, previous=old))
        rows.append(request(p, period))
        self.check_rows(rows, True)

    def test_partial_disposal_values_require_exact_component_and_leave_possible_remainder(self):
        p = plan();period = {'start': '2025-01-01', 'end': '2025-12-31'};rows = []
        down = event('impairment', '2025-06-30', 60000, 80000, permanent=True, tax_election=True)
        base = event('disposal', '2025-12-31', 20000, 26666, cost_cents=33333, allocation_document=document('Cost component'))
        for field, value in [('cost_cents', 0), ('cost_cents', 100002), ('book_cents', 33334), ('tax_cents', True),
                             ('book_cents', 60001), ('cost_cents', 90000), ('allocation_document', {}), ('permanent', True), ('no_proceeds', False)]:
            rows.append(request(p, period, [down, base | {field: value}]))
        rows.append(request(p, period, [down, base | {'cost_cents': 100001}]))
        rows.append(request(p, period, [down, base, base]))
        full = event('disposal', '2025-12-31', 60000, 80000, cost_cents=100001, allocation_document=document('Whole component'))
        rows.append(request(p, period, [down, full, event('writeup', '2025-12-31', 0, 0, permanent=False, tax_election=False)]))
        self.check_rows(rows, True)


if __name__ == '__main__':
    unittest.main()
