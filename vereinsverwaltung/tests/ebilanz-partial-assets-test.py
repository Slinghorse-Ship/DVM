"""Actual PHP export -> independent Python validation -> XBRL. Synthetic data only."""
import copy
import importlib.util
from pathlib import Path
import unittest
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('base_tests',HERE/'ebilanz-builder-test.py')
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)


class PartialAssets(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.cases={case:base.cases.fixture('asset-partial-'+case) for case in ('same','prior','carry','zero','cents','residual','tax-slow','tax-fast','tax-carry','tax-zero')}
    def build(self,v):
        base.seal(v);mapping,profile=base.cases.inputs(v)
        mapping.update(base.automatic_mapping(v))
        return base.EbilanzBuilder().build(v,mapping,profile,'12345')
    def test_complete_exports(self):
        for case,v in self.cases.items():
            with self.subTest(case=case):
                r=self.build(copy.deepcopy(v));self.assertEqual(r['asset_items'],1);self.assertEqual(r['environment'],'test')
    def test_mutated_fields(self):
        for case in ('same','tax-slow','tax-carry'):
            original=self.cases[case]
            for field in ('cost_opening_cents','cost_closing_cents','depreciation_cents','book_disposals_cents','accumulated_disposals_cents','book_closing_cents'):
                with self.subTest(case=case,field=field):
                    v=copy.deepcopy(original);v['asset_schedule']['items']['asset-0'][field]+=1
                    with self.assertRaises(ValueError):self.build(v)
    def test_missing_or_forged_proof(self):
        item=self.cases['same']['asset_schedule']['items']['asset-0']
        for key in item['disposal']:
            v=copy.deepcopy(self.cases['same']);del v['asset_schedule']['items']['asset-0']['disposal'][key]
            with self.subTest(key=key),self.assertRaises(ValueError):self.build(v)
        for key in ('partial_plan','disposal_hash','disposal'):
            v=copy.deepcopy(self.cases['same']);del v['asset_schedule']['items']['asset-0'][key]
            with self.subTest(key=key),self.assertRaises(ValueError):self.build(v)
    def test_wrong_allocation(self):
        for key,value in [('cost_cents',12000),('cost_cents',True),('confirmed',False),('same_use_and_life',False),('basis','quantity'),('residual_cents',1)]:
            v=copy.deepcopy(self.cases['same']);v['asset_schedule']['items']['asset-0']['disposal']['allocation'][key]=value
            with self.subTest(key=key,value=value),self.assertRaises(ValueError):self.build(v)
    def test_tax_values_not_trusted(self):
        for key in ('book_closing_cents','accumulated_disposals_cents','depreciation_cents'):
            v=copy.deepcopy(self.cases['tax-slow']);v['asset_tax']['tax_schedule']['items']['asset-0'][key]+=1
            with self.subTest(key=key),self.assertRaises(ValueError):self.build(v)

if __name__=='__main__':unittest.main()
