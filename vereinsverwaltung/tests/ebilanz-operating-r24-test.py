"""Actual PHP source journals, every r24 choice, mixed facts and immutable history."""
import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('operating_r24_shared',ROOT/'tests/ebilanz-operating-extended-test.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
shared.REVIEW='operating-r24'
shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/operating-r24.json').read_text(encoding='utf8'))['years']
from ebilanz_working_capital import disclosures

class OperatingR24(shared.OperatingExtended):
    def test_preserves_prior_annual_bindings(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,24)))
        for current in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r23.json")
            added,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r24.json")
            before=d.validate_snapshot(old,model,*catalogs[current['year']]);after=d.validate_snapshot(added,model,*catalogs[current['year']])
            self.assertEqual({k:after[k] for k in before},before)
            self.assertEqual(len(after)-len(before),31)

    def test_mixed_choices_preserve_actual_result_and_disclosures(self):
        for edition in shared.SPECS:
            mapping={};expected={};extras={};i=0
            for s in edition['groups']:
                for row in s['sources']:
                    key='code-'+row['code'];amount=1234+i;i+=1
                    if s['conditional']:mapping[key]=s['target']
                    expected[s['target']]=expected.get(s['target'],0)+amount
                    for extra in s['disclosures']:extras[extra]=extras.get(extra,0)+amount
            for whole in (False,True):
                for mode,multiplier in (('normal',1),('negative',-1),('cancelled',0)):
                    values=shared.fixture(edition['year'],-1,mode,whole)
                    actual=shared.facts(shared.build(values,mapping,whole),whole)
                    for target,amount in (expected|extras).items():self.assertEqual(actual[target],shared.money(multiplier*amount))
                    for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:
                        self.assertEqual(actual[target],shared.money(values['tax'][key]))

    def test_every_generic_affiliated_choice_keeps_disclosure_non_additive(self):
        for edition in shared.SPECS:
            index,s=next((i,s) for i,s in enumerate(edition['groups']) if s['disclosures'])
            for whole in (False,True):
                for mode,multiplier in (('normal',1),('negative',-1),('cancelled',0)):
                    values=shared.fixture(edition['year'],index,mode,whole)
                    for target in s['choices']:
                        mapping=dict.fromkeys(('code-'+row['code'] for row in s['sources']),target)
                        actual=shared.facts(shared.build(values,mapping,whole),whole)
                        for extra in s['disclosures']:self.assertEqual(actual[extra],shared.money(multiplier*1234))
        b=shared.EbilanzBuilder()
        with self.assertRaises(ValueError):
            from ebilanz_working_capital import validate
            validate({'group':'revenue','target':'is.netIncome.regular.fin.netInterest.income.other','disclosures':s['disclosures']},b.meta)

if __name__=='__main__':unittest.main(verbosity=2)
