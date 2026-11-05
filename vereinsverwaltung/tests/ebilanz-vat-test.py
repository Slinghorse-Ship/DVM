"""Net sales and explicitly settled VAT accounts, without a tax settlement guess."""
import copy
import importlib.util
from pathlib import Path
import unittest
import xml.etree.ElementTree as ET

spec=importlib.util.spec_from_file_location('base_tests',Path(__file__).with_name('ebilanz-builder-test.py'))
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
from ebilanz_builder import EbilanzBuilder, NS, BASE, SALES
from ebilanz_mapping import automatic_mapping

class VatMapping(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixtures={v:base.cases.fixture(v) for v in ('vat-payable','vat-refund','vat-return','vat-2026','vat-unsettled','code-41840','code-43340','code-43340-2026')}
    def setUp(self):self.values=copy.deepcopy(self.fixtures['vat-payable'])
    def build(self,extra=None):
        base.seal(self.values);mapping,profile=base.cases.inputs(self.values)
        if extra:mapping.update(extra)
        return EbilanzBuilder().build(self.values,mapping,profile,'12345')
    def amounts(self):
        root=ET.fromstring(self.build()['xml']);contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)};result={}
        for section in ('balanceSheet','IncomeStatement'):
            for f in root.findall('.//de-gaap-ci:'+BASE+section+'.valueOfItem',NS):
                if f.text is not None:
                    member=contexts[f.get('contextRef')].find('.//xbrldi:explicitMember',NS).text
                    result[member.removeprefix('de-gaap-ci:')]=f.text
        return result
    def test_separate_net_rates_and_payable(self):
        a=self.amounts()
        self.assertEqual(a[SALES+'generalRateVAT'],'100.00');self.assertEqual(a[SALES+'reducedRateVAT'],'100.00')
        self.assertEqual(a['is.netIncome'],'160.00');self.assertEqual(a['bs.eqLiab.liab.other.theroffTax.vat'],'18.40')
    def test_refund_not_expense_or_income(self):
        self.values=copy.deepcopy(self.fixtures['vat-refund']);a=self.amounts()
        self.assertEqual(a['bs.ass.currAss.receiv.other.vat'],'50.00');self.assertEqual(a['is.netIncome'],'-200.00')
    def test_sales_correction_uses_actual_net_and_tax(self):
        self.values=copy.deepcopy(self.fixtures['vat-return']);a=self.amounts()
        self.assertEqual(a[SALES+'generalRateVAT'],'90.00');self.assertEqual(a['bs.eqLiab.liab.other.theroffTax.vat'],'16.50')
        self.assertEqual(a['is.netIncome'],'150.00')
    def test_both_years(self):
        self.values=copy.deepcopy(self.fixtures['vat-2026']);self.build()
        for case in ('code-43340','code-43340-2026'):
            self.values=copy.deepcopy(self.fixtures[case]);self.assertEqual(self.amounts()[SALES+'reducedRateVAT'],'12.34')
    def test_kleinunternehmer_remains_a_distinct_target(self):
        self.values=copy.deepcopy(self.fixtures['code-41840'])
        self.assertEqual(automatic_mapping(self.values)['code-41840'],SALES+'taxExemptUStG19_1');self.build()
    def test_uncleared_tax_cannot_be_hidden_by_explicit_mapping(self):
        self.values=copy.deepcopy(self.fixtures['vat-unsettled'])
        for extra in (None,{'input-vat':'bs.ass.currAss.receiv.other.vat','output-vat':'bs.eqLiab.liab.other.theroffTax.vat'}):
            with self.subTest(extra=extra),self.assertRaisesRegex(ValueError,'Umsatzsteuerabschluss'):self.build(extra)
    def test_no_new_year_fallback(self):
        self.values=copy.deepcopy(self.fixtures['code-43340-2026']);self.values['year']=2027
        with self.assertRaisesRegex(ValueError,'Taxonomie 6.9'):self.build()
    def test_relabelled_year_cannot_reuse_other_year_balances(self):
        self.values=copy.deepcopy(self.fixtures['code-43340-2026']);self.values['year']=2025
        with self.assertRaisesRegex(ValueError,'Abschlussjahr'):self.build()
    def test_php_preview_and_python_generation_agree(self):
        for case in ('vat-payable','vat-refund','vat-return','vat-2026'):
            with self.subTest(case=case):self.assertEqual(automatic_mapping(self.fixtures[case]),base.cases.fixture(case,'mapping')['mapping'])
    def test_generation_does_not_mutate_or_invent_settlement(self):
        before=copy.deepcopy(self.values);self.build();self.assertEqual(before,self.values)
    def test_zero_tax_accounts_retain_debit_and_credit_evidence(self):
        a=self.values['tax']['accounts']['input-vat'];self.assertEqual((a['amount_cents'],a['debit_cents'],a['credit_cents']),(0,760,760))
        root=ET.fromstring(self.build()['xml'])
        detailed=root.findall('.//de-gaap-ci:detailedInformation.accountBalances',NS)
        codes=[row.find('de-gaap-ci:detailedInformation.accountbalances.accountNumber',NS).text for row in detailed]
        self.assertIn('14060',codes);self.assertIn('38060',codes)

if __name__=='__main__':unittest.main()
