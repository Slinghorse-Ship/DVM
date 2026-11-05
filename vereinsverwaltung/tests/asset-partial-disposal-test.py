"""Independent monthly/Fraction oracle for the isolated partial-disposal preview.

No DB, network, ERiC, tax submission or journal write. The preview remains gated.
"""
import copy
from fractions import Fraction
import hashlib
import json
import os
from pathlib import Path
import random
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'vereinsverwaltung/tools'))
from ebilanz_partial_assets import values as export_partial_values
PHP = os.environ.get('VEREIN_FIXTURE_PHP') or str(ROOT / '.local/php-8.5.10/php.exe')
BRIDGE = ROOT / 'vereinsverwaltung/tests/fixtures/asset-partial-disposal.php'


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()


def half_up(value):
    return (value.numerator * 2 + value.denominator) // (value.denominator * 2)


def monthly_oracle(case, life):
    plan, proof, year = case['plan'], case['proof'], case['year']
    cost, residual = plan['basis_cents'], plan['residual_cents']
    part, part_residual = proof['allocation']['cost_cents'], proof['allocation']['residual_cents']
    acquired = int(plan['acquired_on'][:4]) * 12 + int(plan['acquired_on'][5:7]) - 1
    disposed = int(proof['date'][:4]) * 12 + int(proof['date'][5:7]) - 1
    book_cost = accumulated = last_total = last_remaining = 0
    result = dict(year=year, cost_opening_cents=0, additions_cents=0, disposals_cents=0,
                  cost_closing_cents=0, accumulated_opening_cents=0, depreciation_cents=0,
                  accumulated_disposals_cents=0, accumulated_closing_cents=0,
                  book_opening_cents=0, book_disposals_cents=0, book_closing_cents=0,
                  remaining_residual_cents=residual - part_residual if disposed < (year + 1) * 12 else residual)
    for month in range((acquired // 12) * 12, (year + 1) * 12):
        current = month // 12 == year
        if month == year * 12:
            result['cost_opening_cents'] = book_cost
            result['accumulated_opening_cents'] = accumulated
            result['book_opening_cents'] = book_cost - accumulated
        if month < acquired:
            continue
        if month == acquired:
            book_cost += cost
            if current:
                result['additions_cents'] += cost
        total = half_up(Fraction((cost - residual) * min(month - acquired + 1, life), life))
        part_dep = half_up(Fraction(total * (part - part_residual), cost - residual))
        remaining_dep = total - part_dep
        this_dep = total - last_total if month <= disposed else remaining_dep - last_remaining
        if this_dep < 0:
            raise AssertionError('Negative monthly depreciation')
        accumulated += this_dep
        if current:
            result['depreciation_cents'] += this_dep
        if month == disposed:
            book_cost -= part
            accumulated -= part_dep
            if current:
                result['disposals_cents'] += part
                result['accumulated_disposals_cents'] += part_dep
                result['book_disposals_cents'] += part - part_dep
        last_total, last_remaining = total, remaining_dep
    result['cost_closing_cents'] = book_cost
    result['accumulated_closing_cents'] = accumulated
    result['book_closing_cents'] = book_cost - accumulated
    return result


class PartialDisposal(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sample = json.loads(subprocess.check_output([PHP, str(BRIDGE), 'sample'], cwd=ROOT))

    def run_cases(self, cases):
        process = subprocess.run([PHP, str(BRIDGE)], input=json.dumps(cases, ensure_ascii=False),
                                 text=True, encoding='utf-8', capture_output=True, cwd=ROOT, timeout=60)
        self.assertEqual(process.returncode, 0, process.stderr + process.stdout[:1000])
        return json.loads(process.stdout)

    def assert_matches(self, case, actual):
        self.assertNotIn('error', actual, case)
        actual = actual['result']
        self.assertIs(actual['postable'], False)
        self.assertIs(actual['exportable'], False)
        self.assertEqual(actual['plan_sha256'], digest(case['plan']))
        self.assertEqual(actual['proof_sha256'], digest(case['proof']))
        book = monthly_oracle(case, case['plan']['months'])
        self.assertEqual(actual['book'], book, case)
        checked=export_partial_values(case['plan'],case['proof'],case['year'])
        self.assertEqual(checked,{k:book[k] for k in checked},case)
        review = case.get('tax_review')
        if not review:
            self.assertIsNone(actual['tax'])
            self.assertIsNone(actual['differences'])
            self.assertIsNone(actual['tax_review_sha256'])
            return
        tax = monthly_oracle(case, review['months'])
        self.assertEqual(actual['tax'], tax, case)
        checked=export_partial_values(case['plan'],case['proof'],case['year'],review['months'])
        self.assertEqual(checked,{k:tax[k] for k in checked},case)
        self.assertEqual(actual['tax_review_sha256'], digest(review))
        differences = dict(opening_cents=tax['book_opening_cents'] - book['book_opening_cents'],
                           depreciation_cents=tax['depreciation_cents'] - book['depreciation_cents'],
                           disposal_cents=tax['book_disposals_cents'] - book['book_disposals_cents'],
                           closing_cents=tax['book_closing_cents'] - book['book_closing_cents'])
        differences['result_cents'] = -differences['depreciation_cents'] - differences['disposal_cents']
        self.assertEqual(actual['differences'], differences)
        self.assertEqual(differences['opening_cents'] + differences['result_cents'], differences['closing_cents'])

    def test_known_value_and_separate_tax_life(self):
        result = self.run_cases([self.sample])[0]
        self.assert_matches(self.sample, result)
        self.assertEqual(result['result']['book']['book_disposals_cents'], 1000)
        self.assertEqual(result['result']['tax']['book_disposals_cents'], 2500)

    def test_600_seeded_synthetic_cases_with_large_integer_products(self):
        rng = random.Random(20260920)
        cases = []
        for i in range(600):
            c = copy.deepcopy(self.sample)
            p, a = c['plan'], c['proof']['allocation']
            cost = rng.randint(2, 100000000000) if i % 2 else rng.randint(2, 12001)
            residual = rng.randint(0, cost - 2)
            part = rng.randint(1, cost - 1)
            part_residual = rng.randint(max(0, residual - (cost - part - 1)), min(residual, part - 1))
            start_year, start_month = rng.randint(2000, 2026), rng.randint(1, 12)
            months = rng.randint(1, 100) * 12
            p.update(basis_cents=cost, residual_cents=residual, months=months,
                     acquired_on=f'{start_year:04}-{start_month:02}-15')
            a.update(cost_cents=part, residual_cents=part_residual)
            tax_life = rng.choice([m * 12 for m in range(1, 101) if m * 12 != months])
            c['tax_review']['months'] = tax_life
            event_month = start_year * 12 + start_month - 1 + rng.randint(0, max(months, tax_life) + 24)
            c['proof']['date'] = f'{event_month // 12:04}-{event_month % 12 + 1:02}-15'
            c['year'] = rng.randint(start_year, event_month // 12 + 5)
            c['proof']['plan_sha256'] = digest(p)
            if i % 4 == 0:
                c['tax_review'] = None
            cases.append(c)
        original = copy.deepcopy(cases)
        actual = self.run_cases(cases)
        self.assertEqual(cases, original)
        self.assertEqual(len(actual), len(cases))
        for index, (case, result) in enumerate(zip(cases, actual)):
            with self.subTest(index=index):
                self.assert_matches(case, result)

    def test_each_disposal_month_and_january_december_carries(self):
        cases = []
        for month in range(1, 13):
            for year in (2024, 2025, 2026, 2028):
                c = copy.deepcopy(self.sample)
                c['proof']['date'] = f'2025-{month:02}-28'
                c['year'] = year
                cases.append(c)
        for c, r in zip(cases, self.run_cases(cases)):
            self.assert_matches(c, r)

    def test_rounding_does_not_rewrite_closed_years(self):
        cases = []
        for part in range(1, 27):
            c = copy.deepcopy(self.sample)
            c['plan'].update(basis_cents=27, residual_cents=0, months=36)
            c['proof']['allocation']['cost_cents'] = part
            c['proof']['plan_sha256'] = digest(c['plan'])
            c['year'] = 2024
            cases.append(c)
        results = self.run_cases(cases)
        original = results[0]['result']['book']
        for c, r in zip(cases, results):
            self.assert_matches(c, r)
            self.assertEqual(r['result']['book'], original)

    def test_proof_types_and_unsupported_variants_are_rejected(self):
        cases = []
        for field, bad in [('cost_cents', True), ('cost_cents', '4000'), ('cost_cents', 4000.0),
                           ('residual_cents', False), ('confirmed', 1), ('same_use_and_life', 1),
                           ('basis', 'percent'), ('basis', 'quantity'), ('reason', '')]:
            c = copy.deepcopy(self.sample)
            c['proof']['allocation'][field] = bad
            cases.append(c)
        for field, bad in [('schema', True), ('complete', 0), ('no_proceeds', 1), ('kind', 'partial_sale'),
                           ('date', '2025-02-30'), ('revision', 1.0), ('plan_sha256', 'x')]:
            c = copy.deepcopy(self.sample)
            c['proof'][field] = bad
            cases.append(c)
        for r in self.run_cases(cases):
            self.assertEqual(r, {'error': 'DomainException'})

    def test_long_term_cent_conservation_and_zero_book_partial_scrap(self):
        cases = []
        for year in range(2024, 2032):
            c = copy.deepcopy(self.sample)
            c['plan'].update(basis_cents=12001, residual_cents=301, months=24)
            c['proof']['allocation'].update(cost_cents=4001, residual_cents=101)
            c['proof']['date'] = '2028-12-31'
            c['proof']['plan_sha256'] = digest(c['plan'])
            c['year'] = year
            cases.append(c)
        actual = self.run_cases(cases)
        for c, r in zip(cases, actual):
            self.assert_matches(c, r)
        for name in ('book', 'tax'):
            self.assertEqual(sum(r['result'][name]['depreciation_cents'] + r['result'][name]['book_disposals_cents'] for r in actual)
                             + actual[-1]['result'][name]['book_closing_cents'], 12001)
            self.assertEqual(actual[-1]['result'][name]['book_closing_cents'], 200)
            self.assertEqual(actual[4]['result'][name]['book_disposals_cents'], 101)


if __name__ == '__main__':
    unittest.main()
