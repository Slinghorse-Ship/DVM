"""Actual PHP plans independently checked by the Python XBRL generator; no sending."""
import copy
import importlib.util
from pathlib import Path
import unittest

spec=importlib.util.spec_from_file_location('base_tests',Path(__file__).with_name('ebilanz-builder-test.py'))
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
from ebilanz_builder import EbilanzBuilder


class Degressive(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixtures={v:base.cases.fixture(v) for v in ('asset-degressive','asset-degressive-prior','asset-degressive-december','asset-degressive-switch','asset-degressive-no-switch')}
    def setUp(self):
        self.values=copy.deepcopy(self.fixtures['asset-degressive']);self.item=self.values['asset_schedule']['items']['asset-0']
    def build(self):
        base.seal(self.values);mapping,profile=base.cases.inputs(self.values)
        return EbilanzBuilder().build(self.values,mapping,profile,'12345')
    def test_all_actual_php_schedules_generate(self):
        for variant,values in self.fixtures.items():
            with self.subTest(variant=variant):
                self.values=copy.deepcopy(values);self.assertEqual(self.build()['asset_items'],1)
    def test_amounts(self):
        self.assertEqual(self.item['depreciation_cents'],15000)
        self.assertEqual(self.fixtures['asset-degressive-prior']['asset_schedule']['items']['asset-0']['depreciation_cents'],25500)
        self.assertEqual(self.fixtures['asset-degressive-december']['asset_schedule']['items']['asset-0']['depreciation_cents'],2500)
        self.assertEqual(self.fixtures['asset-degressive-switch']['asset_schedule']['items']['asset-0']['depreciation_cents'],50001)
    def test_no_missing_or_forged_eligibility(self):
        for key,value in [('rate_basis_points',3001),('rate_basis_points',True),('rate_basis_points',0),('movable',False),('same_book_tax',False),('switch_to_linear','yes')]:
            with self.subTest(key=key,value=value):
                self.setUp();self.item['degressive'][key]=value
                with self.assertRaises(ValueError):self.build()
    def test_missing_proof(self):
        del self.item['degressive']
        with self.assertRaises(ValueError):self.build()
    def test_invalid_window(self):
        self.item['acquired_on']='2025-06-30'
        with self.assertRaises(ValueError):self.build()
    def test_triple_rate_limit(self):
        self.item['months']=240
        with self.assertRaises(ValueError):self.build()
    def test_mixed_method_proofs(self):
        self.item['gwg']={}
        with self.assertRaises(ValueError):self.build()
    def test_tampered_charge(self):
        self.item['depreciation_cents']+=1
        with self.assertRaises(ValueError):self.build()
    def test_invalid_code_not_rescued_by_manual_mapping(self):
        for code in ('99999','90000','garbage'):
            with self.subTest(code=code):
                self.values=copy.deepcopy(self.fixtures['asset-degressive']);self.values['tax']['accounts']['bank']['skr_code']=code
                with self.assertRaises(ValueError):self.build()
    def test_known_code_group_not_overridden(self):
        self.values['tax']['accounts']['bank']['skr_code']='64000'
        with self.assertRaises(ValueError):self.build()


if __name__=='__main__':unittest.main()
