"""Dated complete-corporation B/GuV/BVV, strictly distinct from privileged clubs."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest
import xml.etree.ElementTree as ET

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('period_assets',HERE/'ebilanz-period-assets-test.py')
assets=importlib.util.module_from_spec(spec);spec.loader.exec_module(assets)
from ebilanz_builder import EbilanzBuilder, NS, BASE, money
from ebilanz_club_scope import digest
from ebilanz_period import verify

def fixture(variant='book'):
    php=Path(os.environ['VEREIN_FIXTURE_PHP'])
    if not php.is_absolute() or not php.is_file():raise ValueError('Native PHP required')
    r=subprocess.run([str(php),str(HERE/'fixtures/ebilanz-period-whole.php'),variant],capture_output=True,text=True,encoding='utf8',timeout=30)
    if r.returncode or r.stderr.strip():raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

def inputs(v):
    mapping,profile=assets.inputs(v)
    return mapping,profile|{'kind':'whole_taxable_club','source_scope':'complete_club_ledger','tax_privileged':False,
                            'asset_scope':'whole_club','business_name':'Gesamter synthetischer Testverein'}

def build(v):
    m,p=inputs(v)
    return EbilanzBuilder().build(v,m,p,'12345')

def seal(v):v['sha256']=digest({k:x for k,x in v.items() if k not in ('sha256','transmission')})

class DatedWholeClub(unittest.TestCase):
    def test_all_spheres_assets_and_exact_periods(self):
        for variant in ('book','tax','book-successor','tax-successor'):
            with self.subTest(variant=variant):
                v=fixture(variant);before=copy.deepcopy(v);out=build(v);root=ET.fromstring(out['xml'])
                self.assertEqual(v,before)
                self.assertEqual(out['asset_items'],7)
                for concept,number in [('bs.ass',v['tax']['asset_total_cents']),('is.netIncome',v['tax']['annual_result_cents']),('BVV.profitLoss',v['tax']['annual_result_cents'])]:
                    self.assertEqual(root.find('.//de-gaap-ci:'+concept,NS).text,money(number))
                self.assertNotIn(BASE,out['xml'])
                self.assertEqual(out['period'],v['period'])
                self.assertEqual(out['transmission'],'unavailable')
                self.assertEqual(v['capital_comparison']['period'],v['period'])
                if not variant.endswith('successor'):self.assertEqual(v['source_spheres'],['1','2','3','4'])
                for c in root.findall('.//xbrli:context',NS):
                    for name,part in [('startDate','start'),('endDate','end'),('instant','end')]:
                        field=c.find('xbrli:period/xbrli:'+name,NS)
                        if field is not None:self.assertEqual(field.text,v['period'][part])
    def test_tax_capital_dated_not_december_or_profit(self):
        v=fixture('tax');build(v);p=v['capital_comparison']
        self.assertEqual(p['sources']['tax:synthetic-tax-capital:1']['date'],'2026-06-30')
        self.assertEqual(p['contributions_cents'],1300)
        self.assertEqual(p['withdrawals_cents'],400)
        self.assertEqual(p['tax_closing_cents']-p['tax_opening_cents']+400-1300,p['profit_cents'])
        self.assertEqual(v['result_difference_cents'],0) # Sold assets leave no residual difference.
    def test_missing_changed_or_promoted_whole_scope(self):
        for kind in ('missing','changed','version','single','privileged','partial'):
            v=fixture();m,p=inputs(v)
            if kind=='missing':del v['whole_club_scope']
            if kind=='changed':v['whole_club_scope']['source']['closed_sha256']='f'*64
            if kind=='version':v['whole_club_scope']['schema']=1
            if kind=='single':p|={'kind':'single_taxable_business','source_scope':'separate_business_ledger'}
            if kind=='privileged':p['tax_privileged']=True
            if kind=='partial':v['club_scope']={}
            seal(v);p['tax_values_sha256']=v['sha256']
            with self.subTest(kind=kind),self.assertRaises(ValueError):EbilanzBuilder().build(v,m,p,'12345')
    def test_resealed_capital_amount_decision_or_period_rejected(self):
        for kind in ('amount','date','decision','missing','profit'):
            v=fixture('tax');p=v['capital_comparison']
            if kind=='amount':p['contributions_cents']+=1
            if kind=='date':p['period']['end']='2026-12-31'
            if kind=='decision':v['period_source']['review']['capital_movements']['tax:synthetic-tax-capital:1']['kind']='withdrawal'
            if kind=='missing':p['decisions'].popitem()
            if kind=='profit':p['profit_cents']+=1
            seal(v)
            with self.subTest(kind=kind),self.assertRaises(ValueError):build(v)
    def test_previous_whole_scope_and_tax_capital_carry_not_optional(self):
        v=fixture('tax-successor');build(v)
        self.assertEqual(v['capital_comparison']['opening_adjustment_cents'],300)
        self.assertEqual(v['capital_comparison']['contributions_cents'],0)
        v['period_source']['previous']=None;seal(v)
        with self.assertRaises(ValueError):verify(v,whole=True)

if __name__=='__main__':unittest.main()
