"""Independent original-journal and tax-value arithmetic. Synthetic data only."""
import copy
import hashlib
import json
import os
from pathlib import Path
import runpy
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT.parent / 'tools'))
from ebilanz_club_scope import calculate, digest, mapping
income_check = runpy.run_path(str(ROOT / 'balance-club-income-test.py'))['verify']


def verify(v):
    assert v['sha256'] == digest({k: x for k, x in v.items() if k != 'sha256'})
    assert v['kind'] == 'club_tax_reconciliation' and v['tax_export_ready'] is False
    income_check(v['income'])
    source = v['income']['scope']['source']; chart = source['accounts']; review = v['review']
    assert review['income_sha256'] == v['income']['sha256'] and review['journal_sha256'] == source['closed_sha256']
    assert review['year'] == source['year'] == v['year'] and review['complete'] is True
    values = v['valuation']
    assert values['sha256'] == digest({k: x for k, x in values.items() if k not in ('sha256', 'transmission')})
    book = calculate(v['year'], chart, source['opening'], source['entries'])
    assert book == source['calculation'] == values['book']
    opening = {k: 0 for k in chart}
    for line in source['opening']:
        opening[line['account']] += line['debit_cents'] - line['credit_cents']
    for entry in values['opening_adjustments']:
        for line in entry['lines']:
            assert chart[line['account']]['group'] not in ('revenue', 'expense')
            opening[line['account']] += line['debit_cents'] - line['credit_cents']
    lines = [dict(account=k, debit_cents=max(n, 0), credit_cents=max(-n, 0), sphere='') for k, n in opening.items() if n]
    entries = copy.deepcopy(source['entries'])
    for entry in values['current_adjustments']:
        for line in entry['lines']:
            if chart[line['account']]['group'] in ('revenue', 'expense'):
                assert line['sphere'] == '4'
        entries.append(dict(id=hashlib.md5(('test-only-' + entry['id']).encode()).hexdigest(), date=f"{v['year']}-12-31", lines=entry['lines']))
    tax = calculate(v['year'], chart, lines, entries)
    assert tax == values['tax']
    for sphere in ('1', '2', '3'):
        assert tax['spheres'][sphere] == book['spheres'][sphere]
    rows = {}
    for key, a in v['income']['accounts'].items():
        diff = tax['accounts'][key]['amount_cents'] - book['accounts'][key]['amount_cents']
        rows[key] = {k: a[k] for k in ('name', 'group', 'skr_code')} | dict(book_cents=a['taxable_book_cents'], valuation_difference_cents=diff, tax_cents=a['taxable_book_cents']+diff)
    assert rows == v['accounts']
    # Reconstruct off-ledger corrections from signed original line amounts.
    sources = {}
    for kind, vouchers in [('journal', source['entries']), ('tax', values['current_adjustments'])]:
        for entry in vouchers:
            for index, line in enumerate(entry['lines']):
                group = chart[line['account']]['group']
                if group in ('expense', 'revenue') and line['sphere'] == '4':
                    sources[f"{kind}:{entry['id']}:{index}"] = dict(account=line['account'], group=group, cents=(1 if group == 'expense' else -1)*(line['debit_cents']-line['credit_cents']))
    assert sources == mapping(v['off_balance']['sources'])
    add = deduct = 0; used = {}; corrections = []
    for entry in review['off_balance_adjustments']:
        total = 0
        for key, n in entry['sources'].items():
            src = sources[key]; assert type(n) is int and n and (n > 0) == (src['cents'] > 0)
            assert src['group'] == ('expense' if entry['kind'] == 'non_deductible_expense' else 'revenue')
            used[key] = used.get(key, 0) + n; assert abs(used[key]) <= abs(src['cents']); total += n
        corrections.append(entry | dict(cents=total))
        if entry['kind'] == 'non_deductible_expense': add += total
        else: deduct += total
    assert v['off_balance']['entries'] == corrections
    assert v['off_balance']['additions_cents'] == add and v['off_balance']['deductions_cents'] == deduct
    commercial = book['spheres']['4']['result_cents']; tax_result = tax['spheres']['4']['result_cents']
    assert v['result'] == dict(book_cents=commercial, valuation_difference_cents=tax_result-commercial, tax_book_cents=tax_result, additions_cents=add, deductions_cents=deduct, taxable_income_cents=tax_result+add-deduct)
    if v['previous'] is not None:
        previous = v['previous']; verify(previous)
        assert v['income']['scope']['previous'] == previous['income']['scope']
        prior = previous['valuation']; result_account = source['result_account']
        difference = {}
        for key, a in prior['tax']['accounts'].items():
            if a['group'] in ('expense', 'revenue'): continue
            n = a['closing_cents'] - prior['book']['accounts'][key]['closing_cents']
            if key == result_account: n -= prior['tax']['annual_result_cents'] - prior['book']['annual_result_cents']
            if n: difference[key] = n
        actual = {}
        for entry in review['opening_adjustments']:
            for line in entry['lines']: actual[line['account']] = actual.get(line['account'], 0) + line['debit_cents'] - line['credit_cents']
        assert actual == difference


class TaxReconciliationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = json.loads(subprocess.check_output([os.environ['VEREIN_FIXTURE_PHP'], str(ROOT/'fixtures/balance-club-tax-reconciliation.php')], encoding='utf8'))

    def test_all_original_rows_and_both_years(self):
        for pair in self.data:
            for v in pair: verify(v)

    def test_payment_never_changes_result(self):
        self.assertEqual({p[0]['result']['taxable_income_cents'] for p in self.data}, {1507})
        self.assertEqual({p[1]['result']['taxable_income_cents'] for p in self.data}, {0})

    def reject(self, v):
        v['sha256'] = digest({k:x for k,x in v.items() if k != 'sha256'})
        with self.assertRaises((AssertionError, ValueError)): verify(v)

    def test_forged_result(self):
        v = copy.deepcopy(self.data[0][0]); v['result']['taxable_income_cents'] += 1; self.reject(v)

    def test_fake_account_tax_value(self):
        v = copy.deepcopy(self.data[0][0]); v['accounts']['expense']['tax_cents'] += 1; self.reject(v)

    def test_prior_difference_not_new_income(self):
        v = copy.deepcopy(self.data[0][1]); v['result']['tax_book_cents'] = 501; self.reject(v)

    def test_original_source_binding(self):
        v = copy.deepcopy(self.data[0][0]); v['review']['income_sha256'] = 'f'*64; self.reject(v)

if __name__ == '__main__': unittest.main()
