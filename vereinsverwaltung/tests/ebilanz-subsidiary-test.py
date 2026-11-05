"""Subsidiary SGEB result with real whole-club B/GuV; synthetic, offline."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_builder import EbilanzBuilder, NS, BASE
from ebilanz_club_scope import digest
from ebilanz_subsidiary import KIND, RESULT


def fixture(variant=''):
    return json.loads(subprocess.check_output([os.environ.get('VEREIN_FIXTURE_PHP','php'),str(ROOT/'tests/fixtures/ebilanz-subsidiary.php'),variant],encoding='utf8'))


def inputs(values):
    mapping={'cash':'bs.ass.currAss.cashEquiv.bank','equity':'bs.eqLiab.equity.retainedEarnings',
             'receivables':'bs.ass.currAss.receiv.trade.other',
             'revenue':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1',
             'expense':'is.netIncome.regular.operatingTC.otherCost.administration'}
    return mapping, {'kind':KIND,'source_scope':'complete_club_with_reviewed_subsidiary','scope_confirmed':True,'tax_privileged':True,
        'tax_values_sha256':values['sha256'],'asset_scope':'whole_and_subsidiary','whole_book_standard':'HGBM',
        'asset_values_unchanged':'asset_tax' not in values['subsidiary']['valuation'],
        'asset_tax_values_reviewed':'asset_tax' in values['subsidiary']['valuation'],
        'whole_business_name':'Kulturförderung und Vereinsarbeit','supplier':'Synthetischer Softwaretest',
        'company':{'tax_number':'5192050001276','name':'Synthetischer Testverein','street':'Testweg','house_number':'1','postal_code':'11111','city':'Teststadt','country':'Deutschland'}}


class Subsidiary(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.cases={key:fixture(key) for key in ('','settled','valuation','off-balance','assets','tax-assets','carry')}
    def setUp(self):self.v=copy.deepcopy(self.cases['']);self.m,self.p=inputs(self.v)
    def build(self):return EbilanzBuilder().build(self.v,self.m,self.p,'12345')
    def seal(self):
        s=self.v['subsidiary']; s['valuation']['sha256']=digest({k:v for k,v in s['valuation'].items() if k not in ('sha256','transmission')})
        s['sha256']=digest({k:v for k,v in s.items() if k!='sha256'})
        self.v['sha256']=digest({k:v for k,v in self.v.items() if k not in ('sha256','transmission')});self.p['tax_values_sha256']=self.v['sha256']
    def reject(self):
        self.seal()
        with self.assertRaises((ValueError,KeyError,TypeError)):self.build()
    def test_variants(self):
        for key,v in self.cases.items():
            with self.subTest(key=key):
                self.v=copy.deepcopy(v);self.m,self.p=inputs(self.v);r=self.build();root=ET.fromstring(r['xml'])
                self.assertEqual(r['subsidiary_result_cents'],v['subsidiary']['result']['taxable_income_cents'])
                self.assertEqual(root.find('.//de-gaap-ci:'+RESULT,NS).text,format(r['subsidiary_result_cents']/100,'.2f'))
                self.assertIsNone(root.find('.//de-gaap-ci:'+BASE+'balanceSheet.valueOfItem',NS))
                self.assertIsNone(root.find('.//de-gaap-ci:BVV.profitLoss',NS))
                self.assertEqual(r['transmission'],'unavailable');self.assertIn('700000004',r['xml'])
    def test_deterministic(self):self.assertEqual(self.build(),self.build())
    def test_no_extra_receivable(self):
        root=ET.fromstring(self.build()['xml']);self.assertEqual(root.find('.//de-gaap-ci:bs.ass',NS).text,format(self.v['whole']['book']['asset_total_cents']/100,'.2f'))
    def test_footnote_is_bound_and_escaped(self):
        self.v=copy.deepcopy(self.cases['off-balance']);self.m,self.p=inputs(self.v);xml=self.build()['xml'];root=ET.fromstring(xml)
        self.assertIsNotNone(root.find('.//*[@id="subsidiary_result"]'))
        self.assertEqual(root.find('.//link:loc',NS).get('{'+NS['xlink']+'}href'),'#subsidiary_result')
        self.assertIn('&lt;text&gt; &amp; proof',xml)
    def test_profile_is_not_taxable_whole(self):
        root=ET.fromstring(self.build()['xml']);self.assertIsNotNone(root.find('.//de-gcd:genInfo.company.id.transmissionOptionCorporateEntitySpecialCaseProfitDetermination.umbrellaBodyAdjustments',NS))
        self.assertIsNotNone(root.find('.//de-gcd:genInfo.company.id.entityWithTaxablePurposeBusiness.taxPrivileged',NS))
    def test_forged_result(self):self.v['subsidiary']['result']['taxable_income_cents']+=1;self.reject()
    def test_forged_account(self):self.v['subsidiary']['accounts']['expense']['tax_cents']+=1;self.reject()
    def test_missing_confirmation(self):self.p['scope_confirmed']=False;self.reject()
    def test_wrong_standard(self):self.p['whole_book_standard']='AO';self.reject()
    def test_wrong_scope(self):self.p['tax_privileged']=False;self.reject()
    def test_wrong_source(self):self.p['source_scope']='complete_club_ledger';self.reject()
    def test_source_not_bound(self):self.v['subsidiary']['review']['income_sha256']='0'*64;self.reject()
    def test_unreviewed_valuation(self):
        self.v=copy.deepcopy(self.cases['valuation']);self.m,self.p=inputs(self.v);self.v['subsidiary']['review']['current_adjustments']=[];self.reject()
    def test_double_off_balance(self):
        self.v=copy.deepcopy(self.cases['off-balance']);self.m,self.p=inputs(self.v)
        e=self.v['subsidiary']['review']['off_balance_adjustments'][0];e['sources'][next(iter(e['sources']))]=10**10;self.reject()
    def test_wrong_legal_reason(self):
        self.v=copy.deepcopy(self.cases['off-balance']);self.m,self.p=inputs(self.v);self.v['subsidiary']['review']['off_balance_adjustments'][0]['legal_basis']='';self.reject()
    def test_asset_confirmation(self):
        self.v=copy.deepcopy(self.cases['tax-assets']);self.m,self.p=inputs(self.v);self.p['asset_tax_values_reviewed']=False;self.reject()
    def test_prior_required(self):
        self.v=copy.deepcopy(self.cases['carry']);self.m,self.p=inputs(self.v);self.v['subsidiary']['previous']=None;self.reject()
    def test_no_arbitrary_xml(self):self.v['xml']='<x/>';self.reject()


if __name__=='__main__':unittest.main()
