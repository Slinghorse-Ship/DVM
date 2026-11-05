"""Full tangible asset sale, separate proceeds/book value, strict evidence and signed taxonomy facts."""
import copy
import importlib.util
from pathlib import Path
import unittest
import xml.etree.ElementTree as ET

spec=importlib.util.spec_from_file_location('base_tests',Path(__file__).with_name('ebilanz-builder-test.py'))
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
from ebilanz_builder import EbilanzBuilder,NS,BASE
from ebilanz_sales import GAIN_SALE,GAIN_BOOK,LOSS_SALE,LOSS_BOOK
from ebilanz_mapping import automatic_mapping

class Sale(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixtures={v:base.cases.fixture(v) for v in ('asset-sale-gain','asset-sale-loss','asset-sale-gain-zero','asset-sale-loss-zero','asset-sale-even','asset-sale-fully','asset-sale-gwg','asset-sale-degressive','asset-sale-carry')}
    def setUp(self):
        self.values=copy.deepcopy(self.fixtures['asset-sale-gain']);self.item=self.values['asset_schedule']['items']['asset-0']
    def build(self):
        base.seal(self.values);mapping,profile=base.cases.inputs(self.values)
        return EbilanzBuilder().build(self.values,mapping,profile,'12345')
    def facts(self):
        root=ET.fromstring(self.build()['xml']);contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)};facts={}
        for row in root.findall('.//de-gaap-ci:'+BASE+'IncomeStatement.valueOfItem',NS):
            if row.text is not None:facts[contexts[row.get('contextRef')].find('.//xbrldi:explicitMember',NS).text.removeprefix('de-gaap-ci:')]=row.text
        return facts
    def test_gain_and_loss_use_positive_deduction_facts(self):
        self.assertEqual(self.values['tax']['accounts']['scrapping']['amount_cents'],-4000)
        f=self.facts();self.assertEqual((f[GAIN_SALE],f[GAIN_BOOK]),('50.00','40.00'));self.assertEqual(f['is.netIncome'],'730.00')
        self.values=copy.deepcopy(self.fixtures['asset-sale-loss']);f=self.facts()
        self.assertEqual((f[LOSS_SALE],f[LOSS_BOOK]),('30.00','40.00'));self.assertEqual(f['is.netIncome'],'710.00')
    def test_all_supported_schedules(self):
        for key,values in self.fixtures.items():
            with self.subTest(case=key):self.values=copy.deepcopy(values);self.build()
    def test_php_python_mapping_agree(self):
        for key,values in self.fixtures.items():
            with self.subTest(case=key):self.assertEqual(automatic_mapping(values),base.cases.fixture(key,'mapping')['mapping'])
    def test_no_duplicate_proceeds_or_writeoff(self):
        for key in ('sale-proceeds','scrapping'):
            self.setUp();self.values['tax']['accounts'][key]['amount_cents']+=1
            with self.assertRaises(ValueError):self.build()
    def test_tax_and_evidence_cannot_be_changed(self):
        for key,value in [('tax_cents',0),('gross_cents',True),('rate',7),('sale_entry','x'),('sale_hash','x'),('complete',False),('revision',True)]:
            with self.subTest(field=key):
                self.setUp();self.item['disposal'][key]=value
                with self.assertRaises(ValueError):self.build()
    def test_zero_tax_requires_explicit_reason(self):
        self.values=copy.deepcopy(self.fixtures['asset-sale-gain-zero']);self.values['asset_schedule']['items']['asset-0']['disposal']['vat_reason']=''
        with self.assertRaises(ValueError):self.build()
    def test_later_year_never_repeats_sale(self):
        self.values=copy.deepcopy(self.fixtures['asset-sale-carry']);f=self.facts();self.assertEqual(f['is.netIncome'],'0.00')
        self.assertTrue(all(v==0 for v in self.values['asset_schedule']['totals'].values()))
    def test_no_tax_bridge_changes_to_sale(self):
        self.values['current_adjustments'].append({'lines':[{'account':'sale-proceeds','debit_cents':1,'credit_cents':0,'sphere':'4'}]})
        with self.assertRaises(ValueError):self.build()
    def test_explicit_mapping_cannot_hide_missing_disposal(self):
        del self.values['asset_schedule']['items']['asset-0']['disposal']
        with self.assertRaises(ValueError):self.build()

if __name__=='__main__':unittest.main()
