"""Actual VAT closing entries preserve the final receivable/payable in XML."""
import importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('vat_r23_shared',ROOT/'tests/ebilanz-operating-extended-test.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
shared.REVIEW='vat-r23';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/vat-r23.json').read_text(encoding='utf8'))['years']

class VatClosing(unittest.TestCase):
    test_sources_are_exact_and_all_choices_are_official_in_both_profiles=shared.OperatingExtended.test_sources_are_exact_and_all_choices_are_official_in_both_profiles

    def test_original_and_negative_unsettled_accounts_cannot_be_overridden(self):
        for edition in shared.SPECS:
            for index,s in enumerate(edition['groups']):
                if not s['conditional']:continue
                for whole in (False,True):
                    for mode in ('normal','negative'):
                        explicit=dict.fromkeys(('code-'+r['code'] for r in s['sources']),s['target'])
                        for mapping in ({},explicit):
                            # An opposite-side balance can already be refused by
                            # the PHP journal boundary, before XML is constructed.
                            with self.assertRaisesRegex((RuntimeError,ValueError),'Umsatzsteuerabschluss prüfen|Bilanzwerte stimmen nicht|Gesamtbilanz und Originalbuchungen stimmen nicht'):
                                v=shared.fixture(edition['year'],index,mode,whole)
                                shared.build(v,mapping,whole)

    def test_all_accounts_actual_closing_and_reversal_in_both_profiles(self):
        asset='bs.ass.currAss.receiv.other.vat';debt='bs.eqLiab.liab.other.theroffTax.vat'
        for edition in shared.SPECS:
            for index in range(-1,len(edition['groups'])):
                groups=edition['groups'] if index==-1 else [edition['groups'][index]]
                for whole in (False,True):
                    for mode in ('settled','settled-negative','cancelled'):
                        v=shared.fixture(edition['year'],index,mode,whole);out=shared.facts(shared.build(v,whole=whole),whole)
                        expected={};i=0
                        for s in groups:
                            for row in s['sources']:
                                amount=0 if mode=='cancelled' else 1234+i;i+=1
                                target=s['target']
                                if mode=='settled-negative' and s['conditional']:target=debt if target==asset else asset
                                expected[target]=expected.get(target,0)+amount
                                key='code-'+row['code']
                                if s['conditional']:self.assertEqual(v['tax']['accounts'][key]['amount_cents'],0)
                        for target,amount in expected.items():self.assertEqual(out[target],shared.money(amount))
                        for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(out[target],shared.money(v['tax'][key]))

    def test_future_deductible_vat_remains_positive_without_forced_clearing(self):
        for edition in shared.SPECS:
            index,s=next((i,s) for i,s in enumerate(edition['groups']) if not s['conditional'])
            for whole in (False,True):
                v=shared.fixture(edition['year'],index,whole=whole)
                out=shared.facts(shared.build(v,whole=whole),whole)
                self.assertEqual(out[s['target']],'12.34')

    def test_preserves_all_previous_bindings(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,23)))
        for current in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r22.json")
            new,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r23.json")
            prior=d.validate_snapshot(old,model,*catalogs[current['year']]);now=d.validate_snapshot(new,model,*catalogs[current['year']])
            self.assertEqual({n:now[n] for n in prior},prior);self.assertEqual(len(now)-len(prior),39)
            self.assertEqual({n:d.bindings(current)[n] for n in now},now)

if __name__=='__main__':unittest.main(verbosity=2)
