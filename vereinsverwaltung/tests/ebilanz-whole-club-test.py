"""Synthetic complete corporation, independent reconstruction and scope regressions."""
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
from ebilanz_builder import EbilanzBuilder,NS,BASE
from ebilanz_club_scope import digest

def fixture(variant=''):
    return json.loads(subprocess.check_output([os.environ.get('VEREIN_FIXTURE_PHP','php'),str(ROOT/'tests/fixtures/ebilanz-club-values.php'),variant,'whole'],encoding='utf8'))

def inputs(values):
    mapping={'cash':'bs.ass.currAss.cashEquiv.bank','equity':'bs.eqLiab.equity.retainedEarnings',
            'revenue':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1',
            'expense':'is.netIncome.regular.operatingTC.otherCost.administration'}
    if 'equity_other' in values['tax']['accounts']:mapping['equity_other']='bs.eqLiab.equity.retainedEarnings'
    if 'bound' in values['tax']['accounts']:
        mapping.pop('equity');mapping.update({'bound':'bs.eqLiab.equity.revenueRes.charityAppropriated.project','free':'bs.eqLiab.equity.revenueRes.unappropriated.assetManagement'})
    if 'result' in values['tax']['accounts']:
        mapping.pop('equity',None);mapping['liabilities']='bs.eqLiab.liab.trade.genOther'
    return mapping, {
        'kind':'whole_taxable_club','source_scope':'complete_club_ledger','scope_confirmed':True,'tax_privileged':False,
        'tax_values_sha256':values['sha256'],'asset_scope':'whole_club','asset_values_unchanged':'asset_tax' not in values,
        'asset_tax_values_reviewed':'asset_tax' in values,'business_name':'Gesamter synthetischer Testverein','supplier':'Synthetischer Softwaretest',
        'company':{'tax_number':'5192050001276','name':'Synthetischer Testverein','street':'Testweg','house_number':'1','postal_code':'11111','city':'Teststadt','country':'Deutschland'}}

class WholeClub(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.base=fixture()
    def setUp(self):self.values=copy.deepcopy(self.base);self.mapping,self.profile=inputs(self.values)
    def build(self):return EbilanzBuilder().build(self.values,self.mapping,self.profile,'12345')
    def seal(self):
        self.values['sha256']=digest({k:v for k,v in self.values.items() if k not in ('sha256','transmission')});self.profile['tax_values_sha256']=self.values['sha256']
    def rejects(self):
        self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_complete_facts(self):
        result=self.build();root=ET.fromstring(result['xml'])
        self.assertEqual(root.find('.//de-gaap-ci:bs.ass',NS).text,'1040.12')
        self.assertEqual(root.find('.//de-gaap-ci:is.netIncome',NS).text,'40.12')
        self.assertEqual(root.find('.//de-gaap-ci:BVV.profitLoss',NS).text,'40.12')
        self.assertNotIn(BASE,result['xml']);self.assertEqual(result['environment'],'test')
    def test_deterministic(self):self.assertEqual(self.build(),self.build())
    def test_assets_all_spheres_retained(self):
        self.values=fixture('assets');self.mapping,self.profile=inputs(self.values)
        self.assertEqual(self.build()['asset_items'],2);self.assertEqual(self.values['book']['accounts']['fixed_assets']['amount_cents'],14400)
    def test_tax_assets_keep_source_spheres(self):
        self.values=fixture('tax-assets');self.mapping,self.profile=inputs(self.values);self.assertEqual(self.build()['asset_items'],2)
    def test_tax_adjustments_across_internal_spheres(self):
        self.values=fixture('tax-assets-all');self.mapping,self.profile=inputs(self.values);self.assertEqual(self.build()['asset_items'],2)
        self.assertEqual(sorted(l['sphere'] for e in self.values['current_adjustments'] for l in e['lines'] if l['sphere']),['3','4'])
        self.assertEqual(self.values['tax']['accounts']['fixed_assets']['amount_cents'],16200)
    def test_fully_depreciated_not_omitted(self):
        self.values=fixture('fully');self.mapping,self.profile=inputs(self.values);self.assertEqual(self.build()['asset_items'],1)
    def test_following_year(self):
        self.values=fixture('carry');self.mapping,self.profile=inputs(self.values);self.build();self.assertEqual(self.values['book']['annual_result_cents'],0)
    def test_reversal(self):
        self.values=fixture('reversal');self.mapping,self.profile=inputs(self.values);self.build();self.assertEqual(self.values['book']['expense_cents'],0)
    def test_loss(self):
        self.values=fixture('loss');self.mapping,self.profile=inputs(self.values);self.build();self.assertLess(self.values['book']['annual_result_cents'],0)
    def test_tax_privileged_not_sent_as_taxable_whole(self):self.profile['tax_privileged']=True;self.rejects()
    def test_partial_profile_not_allowed(self):self.profile['kind']='club_taxable_businesses';self.rejects()
    def test_partial_scope_not_allowed(self):self.profile['source_scope']='approved_club_allocation';self.rejects()
    def test_proof_required(self):del self.values['whole_club_scope'];self.rejects()
    def test_partial_proof_cannot_be_mixed(self):self.values['club_scope']={};self.rejects()
    def test_original_line_missing(self):self.values['whole_club_scope']['source']['entries'].pop();self.rejects()
    def test_source_hash_changed(self):self.values['whole_club_scope']['source']['closed_sha256']='0'*64;self.rejects()
    def test_hidden_other_spheres(self):self.values['source_spheres']=['4'];self.rejects()
    def test_book_sum_changed(self):self.values['book']['annual_result_cents']+=1;self.rejects()
    def test_tax_sum_changed(self):self.values['tax']['annual_result_cents']+=1;self.rejects()
    def test_tax_movement_changed(self):self.values['tax']['accounts']['cash']['debit_cents']+=1;self.rejects()
    def test_adjustment_without_matching_tax_values(self):
        line=copy.deepcopy(self.values['whole_club_scope']['source']['entries'][0]['lines'])
        self.values['current_adjustments']=[{'id':'change','reason':'Synthetic correction','lines':line}];self.rejects()
    def test_asset_scope_not_partial(self):self.profile['asset_scope']='single_reported_business';self.rejects()
    def test_missing_asset_schedule(self):self.values['asset_schedule']=None;self.rejects()
    def test_php_empty_asset_items_are_valid(self):
        self.assertEqual(self.values['asset_schedule']['items'],[])
        self.assertEqual(self.build()['asset_items'],0)
    def test_malformed_asset_item_containers_rejected(self):
        for items in (None,'',False,[{'method':'cost'}]):
            with self.subTest(items=items):
                self.values=copy.deepcopy(self.base);self.values['asset_schedule']['items']=items;self.rejects()
    def test_account_reconciliation_changed(self):self.values['accounts']['cash']['difference_cents']=1;self.rejects()
    def test_capital_does_not_become_profit(self):
        self.values=fixture('capital');self.mapping,self.profile=inputs(self.values);root=ET.fromstring(self.build()['xml'])
        for concept,v in [('BVV.profitLoss','40.12'),('BVV.profitLoss.contribution','10.00'),('BVV.profitLoss.withdrawalDistrib','4.00'),('BVV.profitLoss.assetsCurrentYear','1046.12')]:self.assertEqual(root.find('.//de-gaap-ci:'+concept,NS).text,v)
    def test_capital_evidence_required(self):
        self.values=fixture('capital');self.mapping,self.profile=inputs(self.values);self.values['capital_comparison']['decisions'].popitem();self.rejects()
    def test_wrong_capital_direction(self):
        self.values=fixture('capital');self.mapping,self.profile=inputs(self.values)
        for d in self.values['capital_comparison']['decisions'].values():
            if d['kind']=='contribution':d['kind']='withdrawal'
        self.rejects()
    def test_unbalanced_capital_reclassification(self):
        self.values=fixture('capital');self.mapping,self.profile=inputs(self.values)
        for d in self.values['capital_comparison']['decisions'].values():
            if d['kind']=='contribution':d['kind']='reclassification'
        self.rejects()
    def test_bvv_sum_not_trusted(self):self.values['capital_comparison']['tax_closing_cents']+=1;self.rejects()

if __name__=='__main__':unittest.main()
