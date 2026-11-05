"""Exact cost registrations cannot bypass the original valuation proof."""
import copy,hashlib,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
spec=importlib.util.spec_from_file_location('cost_register_shared',ROOT/'tests/ebilanz-cost-assets-test.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
import datev_years as d
from ebilanz_mapping import automatic_mapping
from ebilanz_builder import EbilanzBuilder

class CostRegister(shared.CostAssets):
    def test_new_annual_bindings_preserve_every_previous_purpose(self):
        model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
        review=json.loads((ROOT/'resources/accounting/reviews/cost-register-r26.json').read_text(encoding='utf8'))
        for current in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r25.json")
            previous=d.validate_snapshot(old,model,*catalogs[current['year']]);sealed,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r26.json");now=d.validate_snapshot(sealed,model,*catalogs[current['year']])
            self.assertEqual({n:d.bindings(current)[n] for n in now},now)
            self.assertEqual({n:now[n] for n in previous},previous);self.assertEqual(len(now)-len(previous),9)
            groups=next(e for e in review['years'] if e['year']==current['year'])['groups']
            self.assertEqual({r['code'] for g in groups for r in g['sources']},set(shared.HISTORIC_COST)|(set(shared.cost.COUNTERPARTS)-{'68950'}))
            for g in groups:self.assertEqual(d.mapping(model,g['purpose'],'6.9')['condition'],'cost_schedule')

    def test_registered_accounts_never_resolve_without_evidence_even_at_zero(self):
        for year in (2025,2026):
            for code in sorted(set(shared.HISTORIC_COST)|(set(shared.cost.COUNTERPARTS)-{'68950'})):
                group='fixed_assets' if code in shared.cost.ELIGIBLE else 'revenue' if code.startswith('49') else 'expense'
                for amount in (0,1234,-1234):
                    values={'year':year,'tax':{'accounts':{'source':{'skr_code':code,'group':group,'amount_cents':amount}}},'asset_schedule':{'year':year,'items':{},'issues':[]}}
                    target=shared.cost.ACCOUNTS[code]['target'] if code in shared.cost.ELIGIBLE else shared.cost.COUNTERPARTS[code]
                    self.assertEqual(automatic_mapping(values),{})
                    self.assertEqual(automatic_mapping(values,{'source':target}),{})

    def test_official_validity_uses_both_start_and_balance_sheet_date(self):
        b=EbilanzBuilder();expired='is.netIncome.regular.operatingTC.otherCost.addPreTaxRes.KStG34_6e'
        self.assertFalse(b.permitted(expired,2025));self.assertFalse(b.permitted(expired,2026))
        release='is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.KStGs34_6e'
        self.assertTrue(b.permitted(release,2025));self.assertTrue(b.permitted(release,2026))
        target='is.netIncome.regular.operatingTC.otherCost.otherOrdinary'
        b.concepts=copy.deepcopy(b.concepts)
        refs=b.concepts[target]['refs']
        refs['ValidSince']=['2025-02-01'];self.assertFalse(b.permitted(target,2025))
        b.period={'start':'2025-02-01','end':'2025-12-31'};self.assertTrue(b.permitted(target,2025))
        refs['ValidThrough']=['2025-06-30'];self.assertFalse(b.permitted(target,2025))
        b.period['end']='2025-06-30';self.assertTrue(b.permitted(target,2025))
        refs['fiscalValidThrough']=['2025-06-29'];self.assertFalse(b.permitted(target,2025))

if __name__=='__main__':unittest.main(verbosity=2)
