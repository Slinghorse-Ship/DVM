"""Own generator regressions; synthetic PHP fixture only, no ERiC or network."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
import xml.etree.ElementTree as ET

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('native_cases',HERE/'ebilanz-native.py')
cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(cases)
from ebilanz_builder import EbilanzBuilder, NS, BASE, money
from ebilanz_mapping import automatic_mapping


def seal(values):
    payload={k:v for k,v in values.items() if k not in ('sha256','transmission')}
    values['sha256']=hashlib.sha256(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()


class Ebilanz(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.base=cases.fixture()
    def setUp(self):
        self.values=copy.deepcopy(self.base)
        self.mapping,self.profile=cases.inputs(self.values)
        self.builder=EbilanzBuilder()
    def build(self):
        return self.builder.build(self.values,self.mapping,self.profile,'12345')
    def reseal(self):
        seal(self.values);self.profile['tax_values_sha256']=self.values['sha256']
    def test_other_year_ledger_rejected_even_after_resealing(self):
        for side in ('book','tax'):
            with self.subTest(side=side):
                self.values=copy.deepcopy(self.base);self.values[side]['year']=2026;self.reseal()
                with self.assertRaisesRegex(ValueError,'Abschlussjahr'):self.build()
    def test_explicit_period_not_silently_rendered_as_calendar_year(self):
        for side in ('book','tax','values'):
            with self.subTest(side=side):
                self.values=copy.deepcopy(self.base)
                target=self.values if side=='values' else self.values[side]
                target['period']={'start':'2024-07-01','end':'2025-06-30'};self.reseal()
                with self.assertRaisesRegex(ValueError,'Zeitraumbezogene'):self.build()
    def test_generated_facts_and_accounts(self):
        p=self.build();root=ET.fromstring(p['xml'])
        contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)}
        amounts={}
        for f in root.findall('.//de-gaap-ci:'+BASE+'balanceSheet.valueOfItem',NS)+root.findall('.//de-gaap-ci:'+BASE+'IncomeStatement.valueOfItem',NS):
            target=contexts[f.get('contextRef')].find('.//xbrldi:explicitMember',NS).text
            if f.text is not None:amounts[target]=f.text
        self.assertEqual(amounts['de-gaap-ci:bs.ass'],'1550.00')
        self.assertEqual(amounts['de-gaap-ci:bs.eqLiab'],'1550.00')
        self.assertEqual(amounts['de-gaap-ci:is.netIncome'],'850.00')
        self.assertEqual(amounts['de-gaap-ci:bs.ass.currAss.receiv.trade'],'650.00')
        self.assertEqual(len(root.findall('.//de-gaap-ci:detailedInformation.accountBalances',NS)),6)
    def test_test_only_envelope(self):
        p=self.build();root=ET.fromstring(p['xml'])
        self.assertEqual(root.find('e:TransferHeader/e:Testmerker',NS).text,'700000004')
        self.assertEqual(p['transmission'],'unavailable')
        self.assertEqual(p['environment'],'test')
        self.assertEqual(p['xml_sha256'],hashlib.sha256(p['xml'].encode()).hexdigest())
    def test_deterministic(self):self.assertEqual(self.build(),self.build())
    def test_filing_revision_separate_from_finality(self):
        for status in ('E','B','G','BG','sK'):
            source=None if status=='E' else {'filing_id':'a'*32,'attempt_id':'b'*32,'xml_sha256':'c'*64,'transfer_ticket':'synthetic-ticket','journal_sha256':'d'*64,'approval_sha256':'e'*64}
            self.profile['filing_revision']={'status':status,'reason':'Synthetic reviewed status','legal_basis':'' if status=='E' else 'Synthetic legal permission','confirmed':True,'source':source,'journal_sha256':'f'*64,'identity_sha256':'1'*64}
            p=self.build();root=ET.fromstring(p['xml'])
            with self.subTest(status=status):
                self.assertIsNotNone(root.find('.//de-gcd:genInfo.report.id.revisionStatus.revisionStatus.'+status,NS))
                self.assertIsNotNone(root.find('.//de-gcd:genInfo.report.id.reportStatus.reportStatus.E',NS))
                self.assertEqual(p['revision_status'],status)
    def test_revision_cannot_be_inferred_from_unknown_status(self):
        for bad in ({'status':'B'},{'status':'K'},None,{'status':'E','source':'foreign'}):
            self.profile['filing_revision']=bad
            with self.subTest(value=bad),self.assertRaises(ValueError):self.build()
    def test_source_and_tax_hash_binding(self):
        p=self.build();self.assertEqual(p['tax_values_sha256'],self.values['sha256']);self.assertEqual(p['journal_sha256'],self.values['journal_sha256'])
    def test_mutated_values(self):
        self.values['tax']['annual_result_cents']+=1
        with self.assertRaisesRegex(ValueError,'verändert'):self.build()
    def test_stale_review(self):
        self.profile['tax_values_sha256']='b'*64
        with self.assertRaises(ValueError):self.build()
    def test_unconfirmed_scope(self):
        self.profile['scope_confirmed']=False
        with self.assertRaises(ValueError):self.build()
    def test_no_whole_association_as_business(self):
        self.profile['source_scope']='whole_club'
        with self.assertRaises(ValueError):self.build()
    def test_other_sphere_even_when_entries_cancel(self):
        self.values['source_spheres']=['1','4'];self.reseal()
        with self.assertRaisesRegex(ValueError,'Quelljournal'):self.build()
    def test_other_sphere_totals(self):
        self.values['tax']['spheres']['2']['income_cents']=200;self.reseal()
        with self.assertRaises(ValueError):self.build()
    def test_new_taxonomy_year_not_implicitly_enabled(self):
        self.values['year']=2027;self.reseal()
        with self.assertRaises(ValueError):self.build()
    def test_missing_mapping(self):
        del self.mapping['capital']
        with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):self.build()
    def test_unknown_account(self):
        self.mapping['foreign-account']=self.mapping['bank']
        with self.assertRaises(ValueError):self.build()
    def test_unknown_concept(self):
        self.mapping['bank']='bs.ass.invented'
        with self.assertRaises(ValueError):self.build()
    def test_group_mismatch(self):
        self.mapping['bank']=self.mapping['sales']
        with self.assertRaises(ValueError):self.build()
    def test_not_automatically_mapped_from_skr(self):
        self.mapping={}
        with self.assertRaises(ValueError):self.build()
    def test_unimplemented_fixed_asset(self):
        self.values['tax']['accounts']['bank']['group']='fixed_assets';self.values['tax']['accounts']['bank']['skr_code']='';self.reseal()
        with self.assertRaisesRegex(ValueError,'Anlagespiegel'):self.build()
    def test_numeric_precision(self):
        self.assertEqual(money(84999),'849.99');self.assertEqual(money(-1),'-0.01');self.assertEqual(money(0),'0.00')
        for bad in [True,1.0,'12',100000000001]:
            with self.subTest(value=bad),self.assertRaises(ValueError):money(bad)
    def test_company_required(self):
        self.profile['company']['name']=''
        with self.assertRaises(ValueError):self.build()
    def test_escape_company_text(self):
        self.profile['company']['name']='Testverein & Freunde <Probe>'
        root=ET.fromstring(self.build()['xml'])
        self.assertEqual(root.find('.//de-gcd:genInfo.company.id.name',NS).text,'Testverein & Freunde <Probe>')
    def test_non_integer_cent(self):
        self.values['tax']['accounts']['bank']['amount_cents']=1.5;self.reseal()
        with self.assertRaises(ValueError):self.build()
    def test_invalid_manufacturer(self):
        with self.assertRaises(ValueError):self.builder.build(self.values,self.mapping,self.profile,'')
    def test_bad_balance_total(self):
        self.values['tax']['asset_total_cents']+=1;self.reseal()
        with self.assertRaisesRegex(ValueError,'Journal'):self.build()
    def test_unsupported_profile(self):
        self.profile['kind']='commercial_balance_with_transfer'
        with self.assertRaises(ValueError):self.build()
    def test_private_cli_generates_test_only(self):
        request={'action':'generate_test','values':self.values,'mapping':self.mapping,'profile':self.profile,'manufacturer_id':'12345'}
        run=subprocess.run([sys.executable,str(HERE.parent/'tools/ebilanz_builder.py')],input=json.dumps(request),text=True,capture_output=True,encoding='utf8',timeout=20)
        self.assertEqual(run.returncode,0,run.stderr)
        self.assertEqual(json.loads(run.stdout)['xml_sha256'],self.build()['xml_sha256'])
    def test_private_cli_rejects_send(self):
        request={'action':'send','values':self.values,'mapping':self.mapping,'profile':self.profile,'manufacturer_id':'12345'}
        run=subprocess.run([sys.executable,str(HERE.parent/'tools/ebilanz_builder.py')],input=json.dumps(request),text=True,capture_output=True,encoding='utf8',timeout=20)
        self.assertEqual(run.returncode,2)
        self.assertEqual(json.loads(run.stdout)['transmission'],'unavailable')
    def test_bank_automatically_mapped_by_exact_year(self):
        expected=self.build();del self.mapping['bank']
        self.assertEqual(self.build(),expected)
    def test_zero_net_assets_do_not_prove_no_retained_assets(self):
        del self.profile['no_retained_fixed_assets']
        with self.assertRaisesRegex(ValueError,'vollständig abgeschriebene'):self.build()
    def test_tax_privilege_must_be_explicit(self):
        del self.profile['tax_privileged']
        with self.assertRaisesRegex(ValueError,'Steuerbegünstigung'):self.build()
    def test_tax_privileged_and_normal_are_not_interchanged(self):
        for value,suffix in [(True,'taxPrivileged'),(False,'normal')]:
            self.profile['tax_privileged']=value;root=ET.fromstring(self.build()['xml'])
            children=list(root.find('.//de-gcd:genInfo.company.id.entityWithTaxablePurposeBusiness',NS))
            self.assertEqual([e.tag.split('}')[-1].split('.')[-1] for e in children],[suffix])
    def test_many_accounts_share_one_context(self):
        self.values=cases.fixture('multiple-bank');self.mapping,self.profile=cases.inputs(self.values)
        root=ET.fromstring(self.build()['xml']);ids=[e.get('id') for e in root.findall('.//xbrli:context',NS)]
        self.assertEqual(len(ids),len(set(ids)))
    def test_php_preview_and_python_generator_use_identical_mapping(self):
        for variant in ['2026','mapping-coverage','asset-office','asset-mixed']:
            with self.subTest(variant=variant):
                self.assertEqual(automatic_mapping(cases.fixture(variant)),cases.fixture(variant,'mapping')['mapping'])
    def test_consolidation_only_concept_not_permitted(self):
        self.assertFalse(self.builder.permitted('bs.ass.fixAss.intan.goodwill.fromConsolidation',2025))


class AssetExport(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.base=cases.fixture('asset-mixed')
    def setUp(self):
        self.values=copy.deepcopy(self.base);self.mapping,self.profile=cases.inputs(self.values)
    def build(self):return EbilanzBuilder().build(self.values,self.mapping,self.profile,'12345')
    def reseal(self):seal(self.values);self.profile['tax_values_sha256']=self.values['sha256']
    def reject(self,fragment='Anlagenspiegel'):
        self.reseal()
        with self.assertRaisesRegex(ValueError,fragment):self.build()
    def test_official_gross_fields_and_row_totals(self):
        p=self.build();root=ET.fromstring(p['xml']);rows={}
        for ctx in root.findall('.//xbrli:context',NS):
            if not ctx.get('id').startswith('FA'):continue
            members=ctx.findall('.//xbrldi:explicitMember',NS)
            self.assertEqual(members[1].text,'de-gaap-ci:dim_taxBal')
            rows[members[0].text.split(':',1)[1]]={e.tag.split('}')[-1]:e.text for e in root.findall('.//xbrli:xbrl/*',NS) if e.get('contextRef')==ctx.get('id')}
        total=rows['bs.ass.fixAss'];self.assertEqual(total['gross'],'240.00');self.assertEqual(total['grossCost.beginning'],'0.00')
        self.assertEqual(total['accDepr'],'20.00');self.assertEqual(total['accDepr.DeprPeriod.regular'],'20.00')
        self.assertEqual(total['all'],'220.00');self.assertEqual(total['all_Prev_period'],'0.00')
        self.assertEqual(p['asset_items'],2);self.assertEqual(p['asset_fact_count'],196)
        announced=root.find('.//de-gcd:genInfo.report.id.reportElement.reportElements.BAL',NS)
        self.assertNotIn('{'+NS['xsi']+'}nil',announced.attrib)
    def test_automatic_asset_and_depreciation_mapping(self):
        expected=self.build()
        self.mapping={k:v for k,v in self.mapping.items() if k in ('capital','sales','expense','receivables','liabilities')}
        self.assertEqual(self.build(),expected)
    def test_bound_asset_hash(self):
        self.values['asset_schedule']['items']['asset-0']['cost_closing_cents']+=1
        with self.assertRaisesRegex(ValueError,'verändert'):self.build()
    def test_asset_scope_not_implicit(self):
        del self.profile['asset_scope'];self.reject()
    def test_tax_values_not_assumed_equal(self):
        del self.profile['asset_values_unchanged'];self.reject()
    def test_missing_schedule(self):
        self.values['asset_schedule']=None;self.reject('Anlagespiegel')
    def test_other_sphere_asset_even_with_zero_balance(self):
        self.values['asset_schedule']['items']['asset-0']['sphere']='1';self.reject()
    def test_wrong_year(self):
        self.values['asset_schedule']['year']=2024;self.reject()
    def test_tampered_sum(self):
        self.values['asset_schedule']['totals']['book_closing_cents']+=1;self.reject()
    def test_tampered_asset_arithmetic(self):
        self.values['asset_schedule']['items']['asset-0']['accumulated_closing_cents']+=1;self.reject()
    def test_missing_origin_proof(self):
        self.values['asset_schedule']['items']['asset-0']['acquisition_hash']='';self.reject()
    def test_orphan_account(self):
        self.values['asset_schedule']['items']['asset-0']['account']='foreign';self.reject()
    def test_unsupported_disposal_not_ignored(self):
        self.values['asset_schedule']['items']['asset-0']['disposal_cents']=10;self.reject()
    def test_zero_net_movements_cannot_hide_disposal(self):
        for ledger in ('book','tax'):
            self.values[ledger]['accounts']['equipment-0']['debit_cents']+=100
            self.values[ledger]['accounts']['equipment-0']['credit_cents']+=100
        self.reject()
    def test_off_setting_tax_asset_adjustments_still_require_review(self):
        self.values['current_adjustments']=[{'id':'test','reason':'Synthetic','lines':[
            {'account':'equipment-0','debit_cents':100,'credit_cents':0},
            {'account':'equipment-0','debit_cents':0,'credit_cents':100}]}];self.reject()
    def test_depreciation_must_be_in_profit_and_loss(self):
        self.mapping['equipment-depreciation']='is.netIncome.regular.operatingTC.otherCost.administration'
        self.reject('Kontenbedeutung')
    def test_invalid_plan_and_type(self):
        for field,value in [('months',13),('acquired_on','2025-02-30'),('cost_closing_cents',12.0),('residual_cents',-1)]:
            with self.subTest(field=field):
                self.values=copy.deepcopy(self.base);self.values['asset_schedule']['items']['asset-0'][field]=value
                self.reseal()
                with self.assertRaises(ValueError):self.build()
    def test_duplicate_asset_id(self):
        self.values['asset_schedule']['items']['duplicate']=copy.deepcopy(self.values['asset_schedule']['items']['asset-0']);self.reject()


class GwgExport(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.base=cases.fixture('asset-gwg-limit')
    def setUp(self):self.values=copy.deepcopy(self.base)
    def build(self):
        seal(self.values);mapping,profile=cases.inputs(self.values)
        return EbilanzBuilder().build(self.values,mapping,profile,'12345')
    def test_native_fields_use_gross_cost_and_full_depreciation(self):
        package=self.build();self.assertEqual(package['asset_items'],1)
        self.assertEqual(self.values['asset_schedule']['items']['asset-0']['depreciation_cents'],95200)
    def test_invalid_election_or_cost_rejected_even_with_resealed_payload(self):
        for key,value in [('net_cents',80001),('vat_cents',15201),('deductible_cents',1),('no_pool',False),('independent_movable',False)]:
            with self.subTest(key=key):
                self.values=copy.deepcopy(self.base);self.values['asset_schedule']['items']['asset-0']['gwg'][key]=value
                with self.assertRaises(ValueError):self.build()
    def test_unknown_or_mixed_method_rejected(self):
        for method in ['pool','linear_monthly']:
            with self.subTest(method=method):
                self.values=copy.deepcopy(self.base);self.values['asset_schedule']['items']['asset-0']['method']=method
                with self.assertRaises(ValueError):self.build()
    def test_gwg_cannot_use_ordinary_depreciation_target(self):
        mapping,profile=cases.inputs(self.values);mapping['equipment-depreciation']='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'
        with self.assertRaises(ValueError):EbilanzBuilder().build(self.values,mapping,profile,'12345')
    def test_retained_gwg_is_not_new_expense_next_year(self):
        self.values=cases.fixture('asset-gwg-prior');self.build()
        item=self.values['asset_schedule']['items']['asset-0']
        self.assertEqual((item['cost_opening_cents'],item['accumulated_opening_cents'],item['depreciation_cents'],item['book_closing_cents']),(14280,14280,0,0))
    def test_php_python_gwg_mapping_parity(self):
        self.assertEqual(automatic_mapping(self.base),cases.fixture('asset-gwg-limit','mapping')['mapping'])

if __name__=='__main__':unittest.main()
