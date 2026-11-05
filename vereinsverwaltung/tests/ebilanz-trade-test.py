"""Taxonomy sum fields cannot replace the reviewed counterparty detail."""
import importlib.util
from pathlib import Path
import sys
import unittest
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
spec=importlib.util.spec_from_file_location('trade_cases',ROOT/'tests/ebilanz-native.py')
cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(cases)
from ebilanz_builder import EbilanzBuilder,NS,BASE
from ebilanz_mapping import automatic_mapping


class TradeDetails(unittest.TestCase):
    def setUp(self):
        self.values=cases.fixture();self.mapping,self.profile=cases.inputs(self.values)

    def build(self):return EbilanzBuilder().build(self.values,self.mapping,self.profile,'12345')

    def test_no_automatic_counterparty_inference(self):
        result=automatic_mapping(self.values)
        self.assertNotIn('receivables',result);self.assertNotIn('liabilities',result)

    def test_missing_classification_blocks(self):
        del self.mapping['receivables']
        with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):self.build()

    def test_parent_and_wrong_group_blocked(self):
        for key,parent in [('receivables','bs.ass.currAss.receiv.trade'),('liabilities','bs.eqLiab.liab.trade')]:
            original=self.mapping[key]
            for target in [parent,'bs.ass.currAss.cashEquiv.bank','bs.eqLiab.liab.bank']:
                with self.subTest(key=key,target=target):
                    self.mapping[key]=target
                    with self.assertRaises(ValueError):self.build()
            self.mapping[key]=original

    def test_reviewed_details_and_totals_both_present(self):
        for r,p in [('other','genOther'),('shareholders','shareholders'),('misc','misc')]:
            self.mapping['receivables']='bs.ass.currAss.receiv.trade.'+r
            self.mapping['liabilities']='bs.eqLiab.liab.trade.'+p
            root=ET.fromstring(self.build()['xml']);contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)}
            values={}
            for fact in root.findall('.//de-gaap-ci:'+BASE+'balanceSheet.valueOfItem',NS):
                if fact.text is not None:values[contexts[fact.get('contextRef')].find('.//xbrldi:explicitMember',NS).text]=fact.text
            self.assertEqual(values['de-gaap-ci:bs.ass.currAss.receiv.trade'],'650.00')
            self.assertEqual(values['de-gaap-ci:bs.ass.currAss.receiv.trade.'+r],'650.00')
            self.assertEqual(values['de-gaap-ci:bs.ass'],'1550.00')


if __name__=='__main__':unittest.main()
