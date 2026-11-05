"""Actual PHP journal -> financial facts, signed disclosures and native-ready XML."""
import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('financial_income_shared',ROOT/'tests/ebilanz-operating-extended-test.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
shared.REVIEW='financial-income-r21'
shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/financial-income-r21.json').read_text(encoding='utf8'))['years']
from ebilanz_working_capital import validate,disclosures

class FinancialIncome(shared.OperatingExtended):
    def test_preserves_all_previous_bindings(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,21)))
        for current in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r20.json")
            new,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r21.json")
            prior=d.validate_snapshot(old,model,*catalogs[current['year']]);now=d.validate_snapshot(new,model,*catalogs[current['year']])
            self.assertEqual({n:now[n] for n in prior},prior)
            self.assertEqual(len(now)-len(prior),36)
            self.assertEqual({n:d.bindings(current)[n] for n in now},now)

    def test_mixed_signed_income_and_disclosures_never_duplicate_result(self):
        for edition in shared.SPECS:
            mapping={};expected={};extras={};i=0
            for s in edition['groups']:
                for row in s['sources']:
                    key='code-'+row['code'];amount=1234+i;i+=1
                    if s['conditional']:mapping[key]=s['target']
                    expected[s['target']]=expected.get(s['target'],0)+amount
                    for target in s['disclosures']:extras[target]=extras.get(target,0)+amount
            for whole in (False,True):
                for mode,multiplier in (('normal',1),('negative',-1),('cancelled',0)):
                    v=shared.fixture(edition['year'],-1,mode,whole)
                    out=shared.facts(shared.build(v,mapping,whole),whole)
                    for target,amount in (expected|extras).items():
                        self.assertEqual(out[target],shared.money(multiplier*amount))
                    for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:
                        self.assertEqual(out[target],shared.money(v['tax'][key]))

    def test_each_choice_with_signed_disclosures_in_both_profiles(self):
        for edition in shared.SPECS:
            for index,s in enumerate(edition['groups']):
                if not s['disclosures']:continue
                for whole in (False,True):
                    for mode,multiplier in (('normal',1),('negative',-1),('cancelled',0)):
                        v=shared.fixture(edition['year'],index,mode,whole)
                        for target in s['choices']:
                            mapping=dict.fromkeys(('code-'+r['code'] for r in s['sources']),target)
                            out=shared.facts(shared.build(v,mapping,whole),whole)
                            amount=multiplier*sum(1234+i for i in range(len(s['sources'])))
                            for extra in s['disclosures']:self.assertEqual(out[extra],shared.money(amount))

    def test_disclosure_validation_and_signed_amount_boundaries(self):
        b=shared.EbilanzBuilder();base='is.netIncome.regular.fin.netInterest.'
        rule={'group':'revenue','target':base+'income.other','disclosures':[base+'income.assoc']}
        validate(rule,b.meta)
        for patch in ({'target':base+'expenses.other'}, {'disclosures':[base+'income.valueDiscount.accruals']},
                      {'disclosures':[base+'income.assoc']*2}):
            with self.assertRaises(ValueError):validate(rule|patch,b.meta)
        altered=copy.deepcopy(b.meta);altered['calculations']['is.netIncome'][base+'income.assoc']=1
        with self.assertRaisesRegex(ValueError,'Bilanzsumme'):validate(rule,altered)
        for edition in shared.SPECS:
            index,s=next((i,s) for i,s in enumerate(edition['groups']) if s['disclosures'])
            v=shared.fixture(edition['year'],index,'negative');mapping=dict.fromkeys(('code-'+r['code'] for r in s['sources']),s['target'])
            self.assertTrue(all(n<0 for n in disclosures(v,mapping,b.meta,b.permitted).values()))
            key=next(iter(mapping))
            for invalid in (True,-100000000001,100000000001):
                bad=copy.deepcopy(v);bad['tax']['accounts'][key]['amount_cents']=invalid
                with self.assertRaises(ValueError):disclosures(bad,mapping,b.meta,b.permitted)

if __name__=='__main__':unittest.main(verbosity=2)
