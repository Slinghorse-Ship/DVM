"""Actual source schedules, strict gross disposal validation and no network or sending."""
import copy
import importlib.util
from pathlib import Path
import unittest

spec=importlib.util.spec_from_file_location('base_tests',Path(__file__).with_name('ebilanz-builder-test.py'))
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
from ebilanz_builder import EbilanzBuilder


class Disposal(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixtures={v:base.cases.fixture(v) for v in ('asset-scrap','asset-scrap-prior','asset-scrap-fully','asset-scrap-degressive','asset-scrap-gwg','asset-scrap-carry')}
    def setUp(self):
        self.values=copy.deepcopy(self.fixtures['asset-scrap']);self.item=self.values['asset_schedule']['items']['asset-0']
    def build(self):
        base.seal(self.values);mapping,profile=base.cases.inputs(self.values)
        return EbilanzBuilder().build(self.values,mapping,profile,'12345')
    def test_actual_php_schedules(self):
        for variant,values in self.fixtures.items():
            with self.subTest(variant=variant):
                self.values=copy.deepcopy(values);self.assertEqual(self.build()['asset_items'],1)
    def test_partial_depreciation(self):
        self.assertEqual(self.item['depreciation_cents'],8000)
        self.assertEqual(self.item['book_disposals_cents'],4000)
        self.assertEqual(self.item['accumulated_disposals_cents'],8000)
        self.assertEqual(self.item['disposals_cents'],12000)
    def test_following_year_stays_zero(self):
        totals=self.fixtures['asset-scrap-carry']['asset_schedule']['totals']
        self.assertTrue(all(value==0 for value in totals.values()))
    def test_changed_disposal_amounts(self):
        for field in ('disposals_cents','accumulated_disposals_cents','book_disposals_cents','depreciation_cents','cost_closing_cents'):
            with self.subTest(field=field):
                self.setUp();self.item[field]+=1
                with self.assertRaises(ValueError):self.build()
    def test_no_other_disposal_types(self):
        for kind in ('sale','gift','partial','partial_scrap','partial_sale','impairment'):
            with self.subTest(kind=kind):
                self.setUp();self.item['disposal']['kind']=kind
                with self.assertRaises(ValueError):self.build()
    def test_calculation_preview_cannot_be_exported_as_a_full_disposal(self):
        # Until source/journal/UI integration is accepted, even a freshly rehashed
        # partial preview must not bypass the established full-disposal contract.
        self.item['disposal'].update(kind='partial_scrap',complete=False,schema=1,
                                     allocation={'cost_cents':4000,'residual_cents':0})
        self.item['disposal_hash']=base.hashlib.sha256(base.json.dumps(self.item['disposal']).encode()).hexdigest()
        with self.assertRaises(ValueError):self.build()
    def test_proof_cannot_be_guessed(self):
        for field,value in [('complete',False),('no_proceeds',False),('sha256','x'),('revision',True),('reason',''),('document_id','missing')]:
            with self.subTest(field=field):
                self.setUp();self.item['disposal'][field]=value
                with self.assertRaises(ValueError):self.build()
    def test_missing_proof_and_binding(self):
        for field in ('disposal','disposal_hash','basis_cents'):
            with self.subTest(field=field):
                self.setUp();del self.item[field]
                with self.assertRaises(ValueError):self.build()
    def test_invalid_dates(self):
        for value in ('2024-12-31','2026-01-01','2025-02-30','2025-9-1'):
            with self.subTest(value=value):
                self.setUp();self.item['disposal']['date']=value
                with self.assertRaises(ValueError):self.build()
    def test_zero_value_retains_cost_release(self):
        item=self.fixtures['asset-scrap-fully']['asset_schedule']['items']['asset-0']
        self.assertEqual(item['disposals_cents'],12000)
        self.assertEqual(item['accumulated_disposals_cents'],12000)
        self.assertEqual(item['book_disposals_cents'],0)
    def test_missing_journal_writeoff(self):
        self.values['tax']['accounts']['scrapping']['amount_cents']=0
        with self.assertRaises(ValueError):self.build()

if __name__=='__main__':unittest.main()
