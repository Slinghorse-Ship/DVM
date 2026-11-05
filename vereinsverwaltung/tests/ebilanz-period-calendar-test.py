"""Independent original/SGEB calendar -> dated XML and hostile proof checks."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('dated_subsidiary', HERE/'ebilanz-period-subsidiary-test.py')
subsidiary = importlib.util.module_from_spec(spec); spec.loader.exec_module(subsidiary)
from ebilanz_club_scope import digest
from ebilanz_builder import NS, money
from ebilanz_subsidiary import RESULT
spec = importlib.util.spec_from_file_location('calendar_subsidiary', HERE/'ebilanz-subsidiary-test.py')
calendar = importlib.util.module_from_spec(spec); spec.loader.exec_module(calendar)


def inputs(v):
    m,p=calendar.inputs(v);changed=bool(v['subsidiary']['review']['asset_tax_plans'])
    return m,p|{'asset_values_unchanged':not changed,'asset_tax_values_reviewed':changed}


def build(v):
    m,p=inputs(v);return calendar.EbilanzBuilder().build(v,m,p,'12345')


def fixture(variant):
    r = subprocess.run([os.environ['VEREIN_FIXTURE_PHP'], str(HERE/'fixtures/ebilanz-period-calendar.php'), variant],capture_output=True,text=True,encoding='utf8',timeout=60)
    if r.returncode or r.stderr.strip(): raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)


class CalendarBridge(unittest.TestCase):
    @classmethod
    def setUpClass(cls): cls.cases = {k:fixture(k) for k in ('book','tax')}
    def test_exact_dates_original_books_and_original_hashes(self):
        for v in self.cases.values():
            old = copy.deepcopy(v); result = build(v); tree=ET.fromstring(result['xml'])
            self.assertEqual(v,old)
            self.assertEqual(result['period'],{'start':'2026-01-01','end':'2026-06-30'})
            self.assertEqual(result['previous_period'],{'start':'2025-01-01','end':'2025-12-31'})
            self.assertEqual(result['asset_items'],2)
            self.assertEqual(tree.find('.//de-gaap-ci:is.netIncome',NS).text,money(v['whole']['book']['annual_result_cents']))
            self.assertEqual(tree.find('.//de-gaap-ci:'+RESULT,NS).text,money(v['subsidiary']['result']['taxable_income_cents']))
    def test_prior_tax_difference_not_repeated_as_current_profit(self):
        v = self.cases['tax'];s=v['subsidiary'];build(v)
        self.assertEqual(s['result']['valuation_difference_cents'],600)
        self.assertEqual(s['valuation']['accounts']['fixed_assets']['difference_cents'],1800)
        self.assertNotEqual(s['previous']['valuation']['book'],s['previous']['valuation']['tax'])
    def test_resealed_bridge_tampering_denied(self):
        for field in ('calendar-amount','calendar-source','calendar-plan','calendar-tax','extra','section','lost-prior','false-opening'):
            v=copy.deepcopy(self.cases['tax']);p=v['whole']['period_source'];old=p['previous'];item=next(iter(old['asset_schedule']['items']))
            if field=='calendar-amount':old['values']['book']['annual_result_cents']+=1
            if field=='calendar-source':old['calendar_source']['valuation']['tax']['annual_result_cents']+=1
            if field=='calendar-plan':old['asset_schedule']['items'][item]['plan']['months']+=12
            if field=='calendar-tax':old['asset_tax']['tax_schedule']['items'][item]['schedule']['book_closing_cents']+=1
            if field=='extra':old['extra']=True
            if field=='section':old['section']='subsidiary'
            if field=='lost-prior':p['previous']=None
            if field=='false-opening':v['subsidiary']['review']['opening_adjustments']=[]
            subsidiary.seal(v)
            with self.subTest(field=field),self.assertRaises((ValueError,KeyError)):build(v)

if __name__=='__main__': unittest.main()
