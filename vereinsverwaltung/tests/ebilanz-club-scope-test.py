"""Synthetic whole-club to SGEB tests. No certificate, ERiC, bank, mail or network."""
import copy
import hashlib
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
from ebilanz_club_scope import digest,rebuild


def fixture(variant=''):
    return json.loads(subprocess.check_output([os.environ.get('VEREIN_FIXTURE_PHP','php'),str(ROOT/'tests/fixtures/ebilanz-club-values.php'),variant],encoding='utf8'))


def inputs(values):
    mapping={'cash':'bs.ass.currAss.cashEquiv.bank','equity':'bs.eqLiab.equity.retainedEarnings',
            'revenue':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1',
            'expense':'is.netIncome.regular.operatingTC.otherCost.administration'}
    if 'bound' in values['tax']['accounts']:
        mapping.pop('equity');mapping.update({'bound':'bs.eqLiab.equity.revenueRes.charityAppropriated.project','free':'bs.eqLiab.equity.revenueRes.unappropriated.assetManagement'})
    if 'result' in values['tax']['accounts']:
        mapping.pop('equity',None);mapping['liabilities']='bs.eqLiab.liab.trade.genOther'
    return mapping, {
        'kind':'club_taxable_businesses','source_scope':'approved_club_allocation','scope_confirmed':True,'tax_privileged':True,
        'tax_values_sha256':values['sha256'],'asset_scope':'single_reported_business','asset_values_unchanged':'asset_tax' not in values,
        'asset_tax_values_reviewed':'asset_tax' in values,'business_name':'Steuerpflichtige Geschäftsbetriebe des Testvereins','supplier':'Synthetischer Softwaretest',
        'company':{'tax_number':'5192050001276','name':'Synthetischer Testverein','street':'Testweg','house_number':'1','postal_code':'11111','city':'Teststadt','country':'Deutschland'}}


class ClubScope(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.base=fixture();cls.assets=fixture('assets');cls.tax_assets=fixture('tax-assets')
    def setUp(self):self.values=copy.deepcopy(self.base);self.builder=EbilanzBuilder();self.mapping,self.profile=inputs(self.values)
    def build(self):return self.builder.build(self.values,self.mapping,self.profile,'12345')
    def seal(self):
        self.values['sha256']=digest({k:v for k,v in self.values.items() if k not in ('sha256','transmission')});self.profile['tax_values_sha256']=self.values['sha256']
    def test_full_aggregate_facts(self):
        result=self.build();root=ET.fromstring(result['xml']);contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)};amounts={}
        for f in root.findall('.//de-gaap-ci:'+BASE+'balanceSheet.valueOfItem',NS)+root.findall('.//de-gaap-ci:'+BASE+'IncomeStatement.valueOfItem',NS):
            if f.text is not None:amounts[contexts[f.get('contextRef')].find('.//xbrldi:explicitMember',NS).text]=f.text
        self.assertEqual(amounts['de-gaap-ci:bs.ass'],'410.06');self.assertEqual(amounts['de-gaap-ci:bs.eqLiab'],'410.06');self.assertEqual(amounts['de-gaap-ci:is.netIncome'],'10.06')
        self.assertEqual(result['profile'],'club_taxable_businesses');self.assertEqual(result['environment'],'test');self.assertEqual(result['transmission'],'unavailable')
    def test_independent_php_python_reconstruction(self):
        expected=rebuild(self.values['club_scope'])
        for k,v in expected.items():self.assertEqual(v,self.values[k])
    def test_deterministic(self):self.assertEqual(self.build(),self.build())
    def test_full_depreciation_does_not_remove_asset(self):
        self.values=fixture('fully');self.mapping,self.profile=inputs(self.values);self.assertEqual(self.build()['asset_items'],1)
        self.assertEqual(self.values['asset_schedule']['totals']['book_closing_cents'],0)
    def test_carry_rebuilds_both_years(self):
        self.values=fixture('carry');self.mapping,self.profile=inputs(self.values);self.build()
        self.assertEqual(self.values['book']['annual_result_cents'],0);self.assertEqual(self.values['book']['capital_total_cents'],41006)
    def test_reversal_retains_both_sides(self):
        self.values=fixture('reversal');self.mapping,self.profile=inputs(self.values);self.build()
        self.assertEqual(self.values['book']['expense_cents'],0);self.assertEqual(self.values['book']['accounts']['expense']['debit_cents'],1003);self.assertEqual(self.values['book']['accounts']['expense']['credit_cents'],1003)
    def test_loss_not_hidden(self):
        self.values=fixture('loss');self.mapping,self.profile=inputs(self.values);self.build();self.assertEqual(self.values['book']['annual_result_cents'],-8994)
    def test_non_taxable_source_names_not_in_transmitted_xml(self):
        xml=self.build()['xml']
        for name in ('Allgemeine Vereinsarbeit','Vermietung','Kultur'):self.assertNotIn(name,xml)
    def test_book_asset_scope_and_amounts(self):
        self.values=copy.deepcopy(self.assets);self.mapping,self.profile=inputs(self.values);result=self.build()
        self.assertEqual(result['asset_items'],1);self.assertEqual(self.values['book']['accounts']['fixed_assets']['amount_cents'],9600)
    def test_separate_tax_asset_values(self):
        self.values=copy.deepcopy(self.tax_assets);self.mapping,self.profile=inputs(self.values);result=self.build()
        self.assertEqual(result['asset_items'],1);self.assertEqual(self.values['tax']['accounts']['fixed_assets']['amount_cents'],10800)
    def test_changed_source_even_with_resealed_envelope(self):
        self.values['club_scope']['source']['entries'][0]['lines'][0]['debit_cents']+=1;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_business_omission(self):
        del self.values['club_scope']['allocation']['units']['shop'];self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_missing_line(self):
        self.values['club_scope']['allocation']['allocations'].pop('opening:0');self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_wrong_allocation_digest(self):
        self.values['club_scope']['allocation']['sha256']='0'*64;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_extra_cent(self):
        self.values['club_scope']['allocation']['allocations']['opening:0']['parts']['food']+=1;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_allocation_bool_not_integer(self):
        self.values['club_scope']['allocation']['allocations']['opening:0']['parts']['food']=True;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_wrong_asset_scope(self):
        self.values=copy.deepcopy(self.assets);self.mapping,self.profile=inputs(self.values)
        item=next(iter(self.values['asset_schedule']['items']));self.values['club_scope']['source']['asset_schedule']['items'][item]['sphere']='3';self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_missing_asset_history(self):
        del self.values['club_scope']['source']['asset_schedule'];self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_unprivileged_club_cannot_exclude_other_spheres(self):
        self.profile['tax_privileged']=False
        with self.assertRaises(ValueError):self.build()
    def test_direct_single_profile_cannot_bypass_lineage(self):
        self.profile.update(kind='single_taxable_business',source_scope='separate_business_ledger')
        with self.assertRaises(ValueError):self.build()
    def test_missing_club_proof(self):
        del self.values['club_scope'];self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_book_mutation_with_new_envelope_hash(self):
        self.values['book']['asset_total_cents']+=1;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_review_changed_invalidates_projection_hash(self):
        self.values['club_scope']['review']['revision']+=1;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_no_arbitrary_selection(self):
        self.values['club_scope']['selection']=['food'];self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_prior_evidence_required(self):
        self.values['club_scope']['source']['previous_sha256']='a'*64;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_changed_prior_source_rejected(self):
        self.values=fixture('carry');self.mapping,self.profile=inputs(self.values)
        self.values['club_scope']['previous']['source']['closed_sha256']='f'*64;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_tax_result_rebuilt_not_trusted(self):
        self.values['tax']['annual_result_cents']+=1;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_tax_movements_rebuilt_even_without_balance_change(self):
        self.values['tax']['accounts']['cash']['debit_cents']+=100
        self.values['tax']['accounts']['cash']['credit_cents']+=100;self.seal()
        with self.assertRaises(ValueError):self.build()
    def test_adjustment_without_booked_tax_effect(self):
        self.values['current_adjustments']=[{'id':'foreign','reason':'Synthetic changed adjustment','lines':copy.deepcopy(self.values['club_scope']['source']['entries'][-1]['lines'])}];self.seal()
        with self.assertRaises(ValueError):self.build()


if __name__=='__main__':unittest.main()
