"""Separate business: immutable calendar evidence, short periods, independent XML."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('period_assets',HERE/'ebilanz-period-assets-test.py')
assets=importlib.util.module_from_spec(spec);spec.loader.exec_module(assets)
from ebilanz_builder import EbilanzBuilder
from ebilanz_club_scope import digest
from ebilanz_mapping import automatic_mapping

def fixture(variant):
    r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/ebilanz-period-business-calendar.php'),variant],capture_output=True,text=True,encoding='utf8',timeout=60)
    if r.returncode or r.stderr.strip():raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

def build(v):
    _,p=assets.base.cases.inputs(v)
    changed=bool(v['period_source']['review']['asset_tax_plans'])
    p.update(kind='single_taxable_business',source_scope='separate_business_ledger',tax_privileged=True,asset_scope='single_reported_business',
             asset_values_unchanged=not changed,asset_tax_values_reviewed=changed,business_name='Synthetischer eigener Betrieb')
    m=automatic_mapping(v,{})|{'cash':'bs.ass.currAss.cashEquiv.bank','equity':'bs.eqLiab.equity.retainedEarnings',
        'receivables':'bs.ass.currAss.receiv.trade.other','expense':'is.netIncome.regular.operatingTC.otherCost.administration',
        'revenue':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
    return EbilanzBuilder().build(v,m,p,'12345')

def seal(v):v['sha256']=digest({k:n for k,n in v.items() if k not in ('sha256','transmission')})

class BusinessCalendar(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.cases={k:fixture(k) for k in ('book','tax','tax-successor')}
    def test_actual_period_and_assets_not_calendar_recalculation(self):
        for variant,v in self.cases.items():
            with self.subTest(variant=variant):
                before=copy.deepcopy(v);out=build(v)
                self.assertEqual(v,before)
                self.assertEqual(out['asset_items'],2)
                self.assertEqual(out['period'],v['period'])
                self.assertEqual(out['previous_period'],{'start':'2026-01-01','end':'2026-06-30'} if variant=='tax-successor' else {'start':'2025-01-01','end':'2025-12-31'})
                self.assertEqual(v['result_difference_cents'],0 if variant=='book' else 900)
                self.assertEqual(out['transmission'],'unavailable')
    def test_resealed_calendar_source_and_tax_tampering_rejected(self):
        for case in ('hash','journal','review','asset','tax','scope','section','missing','opening','extra','sphere'):
            v=copy.deepcopy(self.cases['tax']);p=v['period_source'];old=p['previous'];source=old['calendar_source']['source'];item=next(iter(source['asset_schedule']['items']))
            if case=='hash':source['closed_sha256']='f'*64
            if case=='journal':source['entries'][0]['lines'][0]['debit_cents']+=1
            if case=='review':old['calendar_source']['review']['asset_tax_plans']=[]
            if case=='asset':old['asset_schedule']['items'][item]['schedule']['book_closing_cents']+=1
            if case=='tax':old['asset_tax']['tax_schedule']['items'][item]['schedule']['book_closing_cents']+=1
            if case=='scope':old['values']['whole_club_scope']={'source':source};seal(old['values'])
            if case=='section':old['section']='whole'
            if case=='missing':p['previous']=None
            if case=='opening':p['review']['opening_adjustments']=[]
            if case=='extra':old['extra']=True
            if case=='sphere':source['asset_schedule']['items'][item]['sphere']='1'
            seal(v)
            with self.subTest(case=case),self.assertRaises((ValueError,KeyError)):build(v)
    def test_exempt_assets_or_sphere_changes_never_become_single_business(self):
        for case in ('sphere','change'):
            v=copy.deepcopy(self.cases['tax']);item=next(iter(v['period_source']['source']['asset_schedule']['items'].values()))
            if case=='sphere':item['plan']['sphere']='1'
            else:item['sphere_change']={'schema':1}
            seal(v)
            with self.subTest(case=case),self.assertRaises(ValueError):build(v)
    def test_retained_previous_source_cannot_be_replaced(self):
        v=copy.deepcopy(self.cases['tax-successor']);prior=v['period_source']['previous'];prior['period_source']['review']['asset_tax_plans']=[];seal(prior);seal(v)
        with self.assertRaises(ValueError):build(v)

if __name__=='__main__':unittest.main()
