"""Capital deductions follow official weights; disclosures never duplicate capital."""
import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('capital_r25_shared',ROOT/'tests/ebilanz-operating-extended-test.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
shared.REVIEW='capital-r25'
shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/capital-r25.json').read_text(encoding='utf8'))['years']
from ebilanz_working_capital import validate,disclosures

class CapitalR25(shared.OperatingExtended):
    def test_preserves_prior_annual_bindings(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,25)))
        for current in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r24.json")
            added,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r25.json")
            before=d.validate_snapshot(old,model,*catalogs[current['year']]);after=d.validate_snapshot(added,model,*catalogs[current['year']])
            self.assertEqual({k:after[k] for k in before},before)
            self.assertEqual(len(after)-len(before),31 if current['year']==2025 else 32)
            self.assertEqual('27400' in after,current['year']==2026)

    def test_every_added_account_normal_negative_and_cancelled(self):
        for edition in shared.SPECS:
            for index,s in enumerate(edition['groups']):
                for whole in (False,True):
                    for mode in ('normal','cancelled'):
                        values=shared.fixture(edition['year'],index,mode,whole);before=copy.deepcopy(values)
                        actual=shared.facts(shared.build(values,{},whole),whole)
                        amount=0 if mode=='cancelled' else sum(1234+i for i in range(len(s['sources'])))
                        opening=1000000 if s['target'] in ('bs.eqLiab.equity.subscribed.corp','bs.eqLiab.equity.foundation.estab') else 0
                        self.assertEqual(actual[s['target']],shared.money(amount+opening))
                        for extra in s['disclosures']:self.assertEqual(actual[extra],shared.money(amount))
                        for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(actual[target],shared.money(values['tax'][key]))
                        self.assertEqual(values,before)
                    if s['group'] not in ('equity',):
                        with self.assertRaises((RuntimeError,ValueError)):shared.build(shared.fixture(edition['year'],index,'negative',whole),{},whole)

    def test_all_capital_and_special_posts_together_keep_capital_comparison(self):
        for edition in shared.SPECS:
            expected={};extras={};i=0
            for s in edition['groups']:
                for row in s['sources']:
                    amount=1234+i;i+=1
                    expected[s['target']]=expected.get(s['target'],0)+amount
                    for extra in s['disclosures']:extras[extra]=extras.get(extra,0)+amount
            for whole in (False,True):
                for mode,multiplier in (('normal',1),('cancelled',0)):
                    values=shared.fixture(edition['year'],-1,mode,whole)
                    actual=shared.facts(shared.build(values,{},whole),whole)
                    for target,amount in (expected|extras).items():
                        opening=1000000 if target in ('bs.eqLiab.equity.subscribed.corp','bs.eqLiab.equity.foundation.estab') else 0
                        self.assertEqual(actual[target],shared.money(multiplier*amount+opening))
                    for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(actual[target],shared.money(values['tax'][key]))
                    if whole:self.assertEqual(actual['BVV.profitLoss'],shared.money(values['tax']['annual_result_cents']))

    def test_exact_disclosures_and_capital_deduction_weights(self):
        b=shared.EbilanzBuilder();base='bs.eqLiab.equity.capRes'
        validate({'target':base,'disclosures':[base+'.sharePremium']},b.meta)
        for extra in (base,base+'.addPresentYear','bs.eqLiab.equity.subscribed.corp'):
            with self.assertRaises(ValueError):validate({'target':base,'disclosures':[extra]},b.meta)
        for edition in shared.SPECS:
            for s in edition['groups']:
                if s['contra']:
                    weights=[children[s['target']] for children in b.meta['calculations'].values() if s['target'] in children]
                    self.assertEqual(weights,[-1])
                for extra in s['disclosures']:
                    self.assertFalse(b.meta['calculations'].get(extra))
                    self.assertFalse(any(extra in children for children in b.meta['calculations'].values()))

if __name__=='__main__':unittest.main(verbosity=2)
