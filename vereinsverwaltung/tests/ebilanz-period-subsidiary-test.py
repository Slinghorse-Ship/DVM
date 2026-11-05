"""Dated original whole-club B/GuV plus separately reviewed subsidiary result."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest
import xml.etree.ElementTree as ET

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('period_whole',HERE/'ebilanz-period-whole-test.py')
whole=importlib.util.module_from_spec(spec);spec.loader.exec_module(whole)
from ebilanz_builder import EbilanzBuilder, NS, BASE, money
from ebilanz_club_scope import digest
from ebilanz_subsidiary import KIND, RESULT, period_packet

def fixture(variant='book'):
    php=Path(os.environ['VEREIN_FIXTURE_PHP'])
    if not php.is_absolute() or not php.is_file():raise ValueError('Native PHP required')
    r=subprocess.run([str(php),str(HERE/'fixtures/ebilanz-period-subsidiary.php'),variant],capture_output=True,text=True,encoding='utf8',timeout=60)
    if r.returncode or r.stderr.strip():raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

def inputs(v):
    m,p=whole.inputs(v['whole']);changed=bool(v['subsidiary']['review']['asset_tax_plans'])
    return m,p|{'kind':KIND,'source_scope':'complete_club_with_reviewed_subsidiary','tax_privileged':True,
               'asset_scope':'whole_and_subsidiary','tax_values_sha256':v['sha256'],'whole_book_standard':'HGBM',
               'whole_business_name':'Synthetischer Gesamtverein','asset_values_unchanged':not changed,'asset_tax_values_reviewed':changed}

def build(v):
    m,p=inputs(v);return EbilanzBuilder().build(v,m,p,'12345')

def seal(v):
    for key in ('whole','subsidiary'):
        if key=='subsidiary':
            for sub in ('income','valuation'):
                d=v[key][sub];d['sha256']=digest({k:x for k,x in d.items() if k not in ('sha256','transmission')})
        d=v[key];d['sha256']=digest({k:x for k,x in d.items() if k not in ('sha256','transmission')})
    v['sha256']=digest({k:x for k,x in v.items() if k not in ('sha256','transmission')})

class DatedSubsidiary(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.cases={k:fixture(k) for k in ('book','tax','off-balance','book-successor','tax-successor','off-balance-successor')}
    def test_all_variants_original_books_and_separate_subsidiary(self):
        for kind,v in self.cases.items():
            with self.subTest(kind=kind):
                before=copy.deepcopy(v);out=build(v);root=ET.fromstring(out['xml'])
                self.assertEqual(v,before)
                self.assertEqual(out['period'],v['period']);self.assertEqual(out['asset_items'],7)
                self.assertEqual(v['whole']['source_spheres'],['1','2','3','4'])
                for concept,n in [('bs.ass',v['whole']['book']['asset_total_cents']),('is.netIncome',v['whole']['book']['annual_result_cents']),(RESULT,v['subsidiary']['result']['taxable_income_cents'])]:
                    self.assertEqual(root.find('.//de-gaap-ci:'+concept,NS).text,money(n))
                self.assertIsNone(root.find('.//de-gaap-ci:BVV.profitLoss',NS))
                self.assertIsNone(root.find('.//de-gaap-ci:'+BASE+'balanceSheet.valueOfItem',NS))
                self.assertIsNotNone(root.find('.//de-gcd:genInfo.company.id.entityWithTaxablePurposeBusiness.taxPrivileged',NS))
                self.assertIn(v['period']['start']+' bis '+v['period']['end'],out['xml'])
                self.assertEqual(out['transmission'],'unavailable')
                if kind!='book' and not kind.startswith('book-'):self.assertNotEqual(v['whole']['book'],v['subsidiary']['valuation']['tax'])
    def test_original_section_and_subsidiary_not_promotable_to_whole(self):
        for v in self.cases.values():
            for section in (v['whole'],period_packet(v['subsidiary'])):
                m,p=whole.inputs(v['whole']);p['tax_values_sha256']=section['sha256']
                with self.assertRaises(ValueError):EbilanzBuilder().build(section,m,p,'12345')
    def test_resealed_changes_rejected(self):
        for field in ('period','whole-period','tax-period','whole-book','tax-result','off-balance','income-source','previous','opening','asset-life'):
            v=copy.deepcopy(self.cases['off-balance-successor']);s=v['subsidiary']
            if field=='period':v['period']={'start':'2026-01-01','end':'2026-12-31'}
            if field=='whole-period':v['whole']['period']={'start':'2026-01-01','end':'2026-12-31'}
            if field=='tax-period':s['review']['period']={'start':'2026-01-01','end':'2026-12-31'}
            if field=='whole-book':v['whole']['book']['annual_result_cents']+=1
            if field=='tax-result':s['result']['taxable_income_cents']+=1
            if field=='off-balance':s['off_balance']['entries'][0]['cents']+=1
            if field=='income-source':s['income']['scope']['source']['closed_sha256']='f'*64
            if field=='previous':s['previous']=None
            if field=='opening':s['review']['opening_adjustments']=[]
            if field=='asset-life':s['review']['asset_tax_plans']['linear']['months']=144
            seal(v)
            with self.subTest(field=field),self.assertRaises((ValueError,KeyError)):build(v)
    def test_tax_accounts_and_tax_free_areas_are_kept_distinct(self):
        v=self.cases['tax'];s=v['subsidiary'];out=build(v)
        self.assertIn('tax_period_disposal_68890',s['valuation']['accounts'])
        self.assertNotIn('tax_period_disposal_68890',v['whole']['book']['accounts'])
        self.assertIn('68890',out['xml'])
        self.assertIn('\u00b7',s['valuation']['accounts']['tax_period_disposal_68890']['name'])
        self.assertNotIn('\u00b7',out['xml']) # ERiC text repertoire, original proof unchanged.
        for sphere in ('1','2','3'):self.assertEqual(s['valuation']['book']['spheres'][sphere],s['valuation']['tax']['spheres'][sphere])
        self.assertEqual(s['result']['valuation_difference_cents'],18001)
    def test_short_followup_keeps_both_exact_carries(self):
        v=self.cases['tax-successor'];out=build(v)
        self.assertEqual(out['previous_period'],{'start':'2025-07-01','end':'2026-06-30'})
        self.assertEqual(v['whole']['opening_adjustments'],[])
        self.assertEqual(v['subsidiary']['result']['valuation_difference_cents'],10500)
        self.assertEqual(v['subsidiary']['valuation']['result_difference_cents'],10500)

if __name__=='__main__':unittest.main()
