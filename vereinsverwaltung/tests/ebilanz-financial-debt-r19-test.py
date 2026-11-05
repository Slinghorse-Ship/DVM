"""Original debt accounts, non-additive disclosures, immutable years and all profiles."""
import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('financial_debt_shared',ROOT/'tests/ebilanz-working-capital-r18-test.py')
previous=importlib.util.module_from_spec(spec);spec.loader.exec_module(previous)
shared=previous.shared
shared.REVIEW='financial-debt-r19'
shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/financial-debt-r19.json').read_text(encoding='utf8'))['years']
from ebilanz_working_capital import validate

class FinancialDebt(previous.WorkingCapital):
    def test_preserves_previous_editions_and_all_original_definitions(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json')
        editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,19)))
        for current in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r18.json")
            historic,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r19.json")
            prior=d.validate_snapshot(old,model,*catalogs[current['year']])
            now=d.validate_snapshot(historic,model,*catalogs[current['year']])
            self.assertEqual({n:now[n] for n in prior},prior)
            self.assertEqual(len(now)-len(prior),26)
            self.assertEqual({n:d.bindings(current)[n] for n in now},now)
        plan=d.compare(model,editions[0],*catalogs[2025],*catalogs[2026],'6.9')
        for g in shared.SPECS[0]['groups']:
            for row in g['sources']:
                self.assertEqual(plan['automatic'][row['code']]['purpose'],g['purpose'])

    def test_source_specific_disclosures_are_not_guessed_from_general_accounts(self):
        expected={
            '31000':[], '31010':['securities.upTo1year','securities.upTo1year.nonConvertible'],
            '31020':['securities.above1year'], '31030':['securities.above1year'],
            '31200':['securities.convertible'],
            '31210':['securities.convertible','securities.upTo1year','securities.upTo1year.warrants'],
            '31220':['securities.convertible','securities.above1year'],
            '31230':['securities.convertible','securities.above1year'],
            '31400':[], '31410':[],
            '34000':[], '34010':['assocComp.upTo1year'], '34020':['assocComp.above1year'], '34030':['assocComp.above1year'],
            '34200':['assocComp.payables'],
            '34210':['assocComp.payables','assocComp.upTo1year','assocComp.upTo1year.trade'],
            '34220':['assocComp.payables','assocComp.above1year'], '34230':['assocComp.payables','assocComp.above1year'],
            '34500':[], '34510':['particip.upTo1year'], '34520':['particip.above1year'], '34530':['particip.above1year'],
            '34700':['particip.accountsPayables'],
            '34710':['particip.accountsPayables','particip.upTo1year','particip.upTo1year.trade'],
            '34720':['particip.accountsPayables','particip.above1year'], '34730':['particip.accountsPayables','particip.above1year'],
        }
        for edition in shared.SPECS:
            actual={r['code']:g['disclosures'] for g in edition['groups'] for r in g['sources']}
            self.assertEqual(actual,{c:['bs.eqLiab.liab.'+s for s in names] for c,names in expected.items()})
            self.assertEqual(sum(len(g['sources']) for g in edition['groups'] if g['conditional']),16)
            for code in ('31420','32490','33490','35300','35990','36300','36950'):
                self.assertNotIn(code,actual)

    def test_inconsistent_maturities_and_nested_disclosures_are_rejected(self):
        b=shared.EbilanzBuilder();base='bs.eqLiab.liab.'
        bad=[('securities',['upTo1year','above1year']),
            ('securities',['upTo1year.warrants']),
            ('securities',['upTo1year','upTo1year.warrants']),
            ('securities',['upTo1year','upTo1year.nonConvertible','convertible']),
            ('securities',['upTo1year','upTo1year.nonConvertible','upTo1year.warrants','convertible']),
            ('assocComp',['upTo1year','upTo1year.trade']),
            ('assocComp',['payables','upTo1year.trade']),
            ('particip',['upTo1year','upTo1year.trade']),
            ('particip',['accountsPayables','upTo1year.trade'])]
        for family,names in bad:
            with self.subTest(family=family,names=names),self.assertRaises(ValueError):
                validate({'target':base+family+('' if family=='securities' else '.other'),
                    'disclosures':[base+family+'.'+n for n in names]},b.meta)

if __name__=='__main__':unittest.main(verbosity=2)
