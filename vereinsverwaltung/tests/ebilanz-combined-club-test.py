"""Whole commercial B/GuV plus linked taxable SGEB. Synthetic, no transport."""
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
    return json.loads(subprocess.check_output([os.environ.get('VEREIN_FIXTURE_PHP','php'),str(ROOT/'tests/fixtures/ebilanz-club-values.php'),variant,'combined'],encoding='utf8'))


def inputs(values):
    mapping={'cash':'bs.ass.currAss.cashEquiv.bank','equity':'bs.eqLiab.equity.retainedEarnings',
             'revenue':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1',
             'expense':'is.netIncome.regular.operatingTC.otherCost.administration'}
    if 'bound' in values['whole']['tax']['accounts']:
        mapping.pop('equity');mapping.update({'bound':'bs.eqLiab.equity.revenueRes.charityAppropriated.project','free':'bs.eqLiab.equity.revenueRes.unappropriated.assetManagement'})
    if 'result' in values['whole']['tax']['accounts']:
        mapping.pop('equity',None);mapping['liabilities']='bs.eqLiab.liab.trade.genOther'
    return {'whole':mapping.copy(),'taxable':mapping.copy()}, {
        'kind':'tax_privileged_club_with_sgeb','source_scope':'complete_club_with_approved_allocation','scope_confirmed':True,'tax_privileged':True,
        'tax_values_sha256':values['sha256'],'asset_scope':'whole_and_taxable','whole_book_standard':'HGBM','asset_values_unchanged':'asset_tax' not in values['taxable'],
        'asset_tax_values_reviewed':'asset_tax' in values['taxable'],'business_name':'Steuerpflichtige Geschäftsbetriebe des Testvereins','whole_business_name':'Kulturförderung und Vereinsarbeit','supplier':'Synthetischer Softwaretest',
        'company':{'tax_number':'5192050001276','name':'Synthetischer Testverein','street':'Testweg','house_number':'1','postal_code':'11111','city':'Teststadt','country':'Deutschland'}}


class CombinedClub(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.base=fixture()
    def setUp(self):self.values=copy.deepcopy(self.base);self.mapping,self.profile=inputs(self.values)
    def build(self):return EbilanzBuilder().build(self.values,self.mapping,self.profile,'12345')
    def seal(self):
        for s in ('whole','taxable'):
            self.values[s]['sha256']=digest({k:v for k,v in self.values[s].items() if k not in ('sha256','transmission')})
        self.values['sha256']=digest({k:v for k,v in self.values.items() if k not in ('sha256','transmission')});self.profile['tax_values_sha256']=self.values['sha256']
    def rejects(self):
        self.seal()
        with self.assertRaises((ValueError,KeyError,TypeError)):self.build()
    def test_both_results_separate(self):
        root=ET.fromstring(self.build()['xml']);self.assertEqual(root.find('.//de-gaap-ci:is.netIncome',NS).text,'40.12')
        contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)}
        profit=[f.text for f in root.findall('.//de-gaap-ci:'+BASE+'IncomeStatement.valueOfItem',NS) if contexts[f.get('contextRef')].find('.//xbrldi:explicitMember',NS).text=='de-gaap-ci:is.netIncome']
        self.assertEqual(profit,['10.06']);self.assertEqual(self.values['reconciliation']['result']['other_areas'],3006)
    def test_deterministic(self):self.assertEqual(self.build(),self.build())
    def test_official_profile_not_taxable_whole(self):
        root=ET.fromstring(self.build()['xml']);self.assertIsNotNone(root.find('.//de-gcd:genInfo.company.id.transmissionOptionofCorpWithSpecialCaseProfitDetermination.umbrellaBodyTable',NS))
        self.assertIsNotNone(root.find('.//de-gcd:genInfo.report.id.accountingStandard.accountingStandard.HGBM',NS));self.assertIsNone(root.find('.//de-gaap-ci:BVV.profitLoss',NS))
    def test_contexts_unique_and_resolved(self):
        root=ET.fromstring(self.build()['xml']);ids=[c.get('id') for c in root.findall('.//xbrli:context',NS)]
        self.assertEqual(len(ids),len(set(ids)))
        for n in root.iter():
            if n.get('contextRef'):self.assertIn(n.get('contextRef'),ids)
    def test_whole_description_separate_from_taxable_business(self):
        root=ET.fromstring(self.build()['xml']);self.assertEqual(root.find('.//de-gcd:genInfo.company.id.business',NS).text,self.profile['whole_business_name'])
        self.assertEqual({m.text for m in root.findall('.//de-gaap-ci:dim_keyType',NS)},{self.profile['business_name']})
    def test_whole_description_required(self):del self.profile['whole_business_name'];self.rejects()
    def test_no_transmission(self):self.assertEqual(self.build()['transmission'],'unavailable');self.assertIn('700000004',self.build()['xml'])
    def test_assets_not_added_together(self):
        self.values=fixture('assets');self.mapping,self.profile=inputs(self.values);r=self.build();self.assertEqual((r['asset_items'],r['taxable_asset_items']),(2,1))
    def test_tax_asset_change_does_not_change_whole(self):
        self.values=fixture('tax-assets');self.mapping,self.profile=inputs(self.values);self.build()
        self.assertEqual(self.values['whole']['tax']['accounts']['fixed_assets']['amount_cents'],14400)
        self.assertEqual(self.values['taxable']['tax']['accounts']['fixed_assets']['amount_cents'],10800)
    def test_following_year(self):
        self.values=fixture('carry');self.mapping,self.profile=inputs(self.values);self.build();self.assertEqual(self.values['whole']['book']['capital_total_cents'],104012)
    def test_reversal(self):
        self.values=fixture('reversal');self.mapping,self.profile=inputs(self.values);self.build();self.assertEqual(self.values['whole']['book']['expense_cents'],0)
    def test_loss(self):
        self.values=fixture('loss');self.mapping,self.profile=inputs(self.values);self.build();self.assertLess(self.values['taxable']['tax']['annual_result_cents'],0)
    def test_retained_zero_asset(self):
        self.values=fixture('fully');self.mapping,self.profile=inputs(self.values);self.assertEqual(self.build()['asset_items'],1)
    def test_resealed_wrong_reconciliation(self):self.values['reconciliation']['result']['taxable_tax']+=1;self.rejects()
    def test_resealed_wrong_account_reconciliation(self):self.values['reconciliation']['accounts']['cash']['amount_cents']['other_areas']+=1;self.rejects()
    def test_resealed_tax_change_without_journal(self):self.values['taxable']['tax']['annual_result_cents']+=1;self.rejects()
    def test_wrong_company_scope(self):self.profile['tax_privileged']=False;self.rejects()
    def test_partial_source_not_combined(self):self.profile['source_scope']='approved_club_allocation';self.rejects()
    def test_confirmation_required(self):self.profile['scope_confirmed']=False;self.rejects()
    def test_book_standard_not_assumed(self):del self.profile['whole_book_standard'];self.rejects()
    def test_no_taxable_whole_standard(self):self.profile['whole_book_standard']='AO';self.rejects()
    def test_source_exchange(self):self.values['taxable']['club_scope']['source']['closed_sha256']='0'*64;self.rejects()
    def test_mismatched_original_description(self):self.values['whole']['whole_club_scope']['source']['entries'][0]['description']='Changed';self.rejects()
    def test_missing_section(self):del self.values['whole']['whole_club_scope'];self.rejects()
    def test_foreign_field(self):self.values['external_xml']='<x/>';self.rejects()
    def test_omitted_business(self):del self.values['taxable']['club_scope']['allocation']['units']['shop'];self.rejects()
    def test_flat_mapping_not_allowed(self):self.mapping=self.mapping['whole'];self.rejects()
    def test_missing_mapping_whole(self):del self.mapping['whole']['revenue'];self.rejects()
    def test_missing_mapping_taxable(self):del self.mapping['taxable']['revenue'];self.rejects()
    def test_tax_assets_need_confirmation(self):
        self.values=fixture('tax-assets');self.mapping,self.profile=inputs(self.values);self.profile['asset_tax_values_reviewed']=False;self.rejects()
    def test_footnote_bound_to_taxable_balance(self):
        self.values=fixture('tax-assets');self.mapping,self.profile=inputs(self.values);root=ET.fromstring(self.build()['xml'])
        loc=root.find('.//link:footnoteLink/link:loc',NS);self.assertEqual(loc.get('{'+NS['xlink']+'}href'),'#taxable_asset_evidence')
        target=root.find('.//*[@id="taxable_asset_evidence"]');self.assertEqual(target.tag,'{'+NS['de-gaap-ci']+'}'+BASE+'balanceSheet.valueOfItem')
        self.assertIn('108.00',' '.join(root.find('.//link:footnote',NS).itertext()))
        for ctx in root.findall('.//xbrli:context',NS):
            if ctx.get('id','').startswith('FA'):
                dims=[d.text for d in ctx.findall('.//xbrldi:explicitMember',NS)];self.assertIn('de-gaap-ci:dim_comBal',dims)
        self.assertFalse(any(c.get('id','').startswith('T_FA') for c in root.findall('.//xbrli:context',NS)))
    def test_receipt_text_not_interpreted_as_html(self):
        self.values=fixture('assets');self.mapping,self.profile=inputs(self.values)
        # Only the presentation helper accepts this synthetic text directly;
        # full build still verifies all asset evidence before creating footnotes.
        root=ET.fromstring(self.build()['xml']);x=root.find('.//xbrli:xbrl',NS)
        for link in x.findall('link:footnoteLink',NS):x.remove(link)
        v=copy.deepcopy(self.values['taxable']);next(iter(v['asset_schedule']['items'].values()))['receipt_number']='<script>bad & text</script>'
        from ebilanz_combined_club import asset_footnote
        asset_footnote(x,v);xml=ET.tostring(x,encoding='unicode');self.assertIn('&lt;script&gt;bad &amp; text&lt;/script&gt;',xml)
        self.assertIsNone(x.find('.//{http://www.w3.org/1999/xhtml}script'))


if __name__=='__main__':unittest.main()
