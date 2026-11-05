"""Independent calendar whole-club -> actual dated period carry and XML checks."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest
import xml.etree.ElementTree as ET

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('dated_whole',HERE/'ebilanz-period-whole-test.py')
whole=importlib.util.module_from_spec(spec);spec.loader.exec_module(whole)
from ebilanz_builder import EbilanzBuilder, NS, money
from ebilanz_club_scope import digest

def fixture(variant):
    r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/ebilanz-period-whole-calendar.php'),variant],capture_output=True,text=True,encoding='utf8',timeout=60)
    if r.returncode or r.stderr.strip():raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

def build(v):
    from ebilanz_mapping import automatic_mapping
    _,p=whole.assets.base.cases.inputs(v)
    changed=bool(v['period_source']['review']['asset_tax_plans'])
    p.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club',
             asset_values_unchanged=not changed,asset_tax_values_reviewed=changed,business_name='Synthetischer Gesamtverein')
    reviewed={'cash':'bs.ass.currAss.cashEquiv.bank','equity':'bs.eqLiab.equity.retainedEarnings',
              'receivables':'bs.ass.currAss.receiv.trade.other','expense':'is.netIncome.regular.operatingTC.otherCost.administration',
              'revenue':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
    return EbilanzBuilder().build(v,automatic_mapping(v,{})|reviewed,p,'12345')

class WholeCalendar(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.cases={k:fixture(k) for k in ('book','tax','all-tax')}
    def test_dates_assets_capital_and_no_duplicate_prior_income(self):
        for kind,v in self.cases.items():
            before=copy.deepcopy(v);out=build(v);root=ET.fromstring(out['xml'])
            self.assertEqual(v,before)
            self.assertEqual(out['period'],{'start':'2026-01-01','end':'2026-06-30'})
            self.assertEqual(out['previous_period'],{'start':'2025-01-01','end':'2025-12-31'})
            self.assertEqual(out['asset_items'],2)
            self.assertEqual(v['capital_comparison']['opening_adjustment_cents'],{'book':0,'tax':1300,'all-tax':1900}[kind])
            self.assertEqual(v['result_difference_cents'],{'book':0,'tax':600,'all-tax':900}[kind])
            self.assertEqual(root.find('.//de-gaap-ci:BVV.profitLoss',NS).text,money(v['tax']['annual_result_cents']))
    def test_resealed_prior_tampering_rejected(self):
        for kind in ('source','book','tax','review','capital','profile','section','missing','opening','extra'):
            v=copy.deepcopy(self.cases['tax']);proof=v['period_source'];prior=proof['previous'];key=next(iter(prior['asset_schedule']['items']))
            if kind=='source':prior['calendar_source']['whole_club_scope']['source']['closed_sha256']='f'*64
            if kind=='book':prior['asset_schedule']['items'][key]['schedule']['book_closing_cents']+=1
            if kind=='tax':prior['asset_tax']['tax_schedule']['items'][key]['schedule']['book_closing_cents']+=1
            if kind=='review':prior['calendar_source']['review']['asset_tax_plans']=[]
            if kind=='capital':prior['values']['capital_comparison']['contributions_cents']+=1
            if kind=='profile':prior['calendar_source']['whole_club_scope']['kind']='single_taxable_business'
            if kind=='section':prior['section']='books'
            if kind=='missing':proof['previous']=None
            if kind=='opening':proof['review']['opening_adjustments']=[]
            if kind=='extra':prior['extra']=True
            whole.seal(v)
            with self.subTest(kind=kind),self.assertRaises((ValueError,KeyError)):build(v)

if __name__=='__main__':unittest.main()
