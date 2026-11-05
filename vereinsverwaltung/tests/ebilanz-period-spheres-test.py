"""Independent PHP -> XBRL sphere bridges. No transport or live data."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest
import xml.etree.ElementTree as ET

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('subsidiary_base',HERE/'ebilanz-period-subsidiary-test.py')
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
from ebilanz_builder import EbilanzBuilder,NS,money
from ebilanz_club_scope import digest
from ebilanz_period_spheres import replay
from ebilanz_subsidiary import RESULT
from ebilanz_period_assets import unreversed_turnover

def fixture(case,next=False,corrected=False,mixed=False,off=''):
    p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/ebilanz-period-spheres.php'),case,'successor' if next else '','corrected' if corrected else '','mixed' if mixed else '',off],capture_output=True,text=True,encoding='utf8',timeout=60)
    if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
    return json.loads(p.stdout)

def inputs(v):
    m,p=base.inputs(v)
    return m,p|{'asset_values_unchanged':False,'asset_tax_values_reviewed':True}

def build(v):
    m,p=inputs(v);return EbilanzBuilder().build(v,m,p,'12345')

class SphereTaxXml(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.cases={(case,n):fixture(case,n) for case in ('entry','exempt','exit-gain','exit-loss','book-return','value-return','later-return','multiple') for n in (False,True)}
    def test_all_paths_original_BAL_and_SGEB_result(self):
        for (case,n),v in self.cases.items():
            with self.subTest(case=case,next=n):
                before=copy.deepcopy(v);out=build(v);root=ET.fromstring(out['xml']);s=v['subsidiary']
                self.assertEqual(v,before);self.assertEqual(out['asset_items'],1)
                for concept,expected in [('bs.ass',v['whole']['book']['asset_total_cents']),('is.netIncome',v['whole']['book']['annual_result_cents']),(RESULT,s['result']['taxable_income_cents'])]:
                    self.assertEqual(root.find('.//de-gaap-ci:'+concept,NS).text,money(expected))
                self.assertIn('Einlagen und Entnahmen sind keine',out['xml'])
                self.assertIn('Synthetic device &lt;name&gt;',out['xml'])
                self.assertIn('INV-S - Synthetic',out['xml'])
                self.assertNotIn('\u00b7',out['xml'])
                self.assertIn('\u00b7',v['whole']['asset_schedule']['items']['device']['name'])
                self.assertIn('Schlusswert',out['xml']);self.assertEqual(out['transmission'],'unavailable')
                self.assertIsNone(root.find('.//de-gaap-ci:BVV.profitLoss',NS))
                self.assertEqual(v['whole']['book'],v['whole']['tax'])
                self.assertEqual(s['valuation']['current_adjustments'],[])
    def test_adjustments_not_confused_with_contributions(self):
        expected={'entry':-6000,'exempt':0,'exit-gain':18000,'exit-loss':-12000,'book-return':0,'value-return':14000,'later-return':0,'multiple':-4000}
        for case,n in expected.items():self.assertEqual(self.cases[case,False]['subsidiary']['result']['valuation_difference_cents'],n)
    def test_normal_tax_life_and_sphere_bridge_each_counted_once(self):
        for successor in (False,True):
            v=fixture('entry',successor,True,True);s=v['subsidiary'];out=build(v)
            self.assertEqual(out['asset_items'],2)
            self.assertEqual(s['result']['valuation_difference_cents'],s['valuation']['result_difference_cents']+s['sphere_reconciliation']['valuation_difference_cents'])
            self.assertEqual(s['valuation']['result_difference_cents'],6000 if successor else 10000)
            self.assertEqual(s['result']['valuation_difference_cents'],0 if successor else 4000)
            self.assertEqual(len(s['valuation']['current_adjustments']),1)
    def test_fully_written_off_original_still_has_separate_tax_value(self):
        for successor in (False,True):
            v=fixture('fast-book',successor);out=build(v);s=v['subsidiary'];p=s['sphere_reconciliation']['items']['device']
            self.assertEqual(out['asset_items'],1)
            self.assertEqual(s['result']['valuation_difference_cents'],2000 if successor else 42000)
            if successor:
                self.assertEqual(v['whole']['asset_schedule']['items']['device']['schedule']['book_closing_cents'],0)
                self.assertEqual(p['tax']['closing_cents'],54000)
                self.assertEqual(v['whole']['asset_schedule']['items']['device']['schedule']['cost_closing_cents'],120000)
    def test_review_required_even_if_same_numeric_depreciation(self):
        for case in ('entry','book-return','exempt'):
            v=self.cases[case,False];m,p=inputs(v);p.update(asset_values_unchanged=True,asset_tax_values_reviewed=False)
            with self.assertRaises(ValueError):EbilanzBuilder().build(v,m,p,'12345')
    def test_corrected_journal_preserves_gross_BAL_without_double_AfA(self):
        for successor in (False,True):
            v=fixture('entry',successor,True);out=build(v)
            self.assertEqual(out['subsidiary_result_cents'],self.cases['entry',successor]['subsidiary']['result']['taxable_income_cents'])
        entries=v['whole']['period_source']['previous']['period_source']['source']['entries']
        reversal=next(i for i,e in enumerate(entries) if e.get('reversal_of'))
        for kind in ('missing','wrong-line','duplicate','date','chain'):
            bad=copy.deepcopy(entries)
            if kind=='missing':bad[reversal]['reversal_of']='missing'
            if kind=='wrong-line':bad[reversal]['lines'][0]['credit_cents']+=1
            if kind=='duplicate':bad.append(bad[reversal]|{'id':'duplicate'})
            if kind=='date':bad[reversal]['date']='2025-01-01'
            if kind=='chain':bad[reversal]['reversal_of']=bad[reversal]['id']
            with self.subTest(kind=kind),self.assertRaises(ValueError):unreversed_turnover(bad)
    def test_resealed_bridge_or_result_cannot_change_XML(self):
        for field in ('result','total','tax','book','movement','missing','extra'):
            v=copy.deepcopy(self.cases['value-return',False]);s=v['subsidiary'];b=s['sphere_reconciliation'];i=b['items']['device']
            if field=='result':s['result']['taxable_income_cents']+=1
            if field=='total':b['valuation_difference_cents']+=1
            if field=='tax':i['tax']['withdrawals_cents']+=1
            if field=='book':i['book_taxable']['depreciation_cents']+=1
            if field=='movement':i['movements'][0]['result_cents']+=1
            if field=='missing':del s['sphere_reconciliation']
            if field=='extra':b['items']['invented']=copy.deepcopy(i)
            i['sha256']=digest({k:x for k,x in i.items() if k!='sha256'});base.seal(v)
            with self.subTest(field=field),self.assertRaises(ValueError):build(v)
    def test_independent_monthly_replay_not_only_hash(self):
        original=self.cases['value-return',False]['whole']['asset_schedule']['items']['device']['sphere_change']
        for field in ('gain','closing','depreciation','book-depreciation','not-first','scope','case','unsigned','residual','months','evidence','previous'):
            p=copy.deepcopy(original)
            if field=='gain':p['tax']['valuation_result_cents']+=1
            if field=='closing':p['tax']['closing_cents']+=1
            if field=='depreciation':p['tax']['depreciation_cents']+=1
            if field=='book-depreciation':p['book']['depreciation_by_sphere']['4']+=1
            if field=='not-first':p['history'][0]['on']='2026-01-02'
            if field=='scope':p['history'][0]['to_sphere']='4'
            if field=='case':p['history'][1]['case']='taxable_return'
            if field=='unsigned':p['history'][0]['tax_reviewed']=False
            if field=='residual':p['history'][1]['residual_cents']=99000
            if field=='months':p['history'][1]['remaining_months']=0
            if field=='evidence':p['history'][0]['sha256']='bad'
            if field=='previous':p['previous_sha256']='f'*64
            p['changes']=copy.deepcopy(p['history']);p['sha256']=digest({k:x for k,x in p.items() if k!='sha256'})
            with self.subTest(field=field),self.assertRaises(ValueError):replay(p)
    def test_successor_retains_continuous_tax_clock(self):
        s=self.cases['later-return',True]['subsidiary'];i=s['sphere_reconciliation']['items']['device']
        self.assertEqual(i['tax']['opening_cents'],0);self.assertEqual(i['tax']['contributions_cents'],94000)
        self.assertEqual(i['tax']['closing_cents'],88000)
        for case in ('entry','exit-gain','exit-loss','book-return','value-return','multiple'):
            s=self.cases[case,True]['subsidiary'];p=s['previous']['sphere_reconciliation']['items']['device'];i=s['sphere_reconciliation']['items']['device']
            self.assertEqual(i['tax']['opening_cents'],p['tax']['closing_cents']);self.assertEqual(i['movements'],[])
            self.assertEqual(i['tax']['valuation_result_cents'],0)

    def test_asset_specific_addbacks_in_original_whole_and_tax_result(self):
        for case in ('entry','fast-book','exit-gain','book-return','later-return','exempt'):
            for successor in (False,True):
                with self.subTest(case=case,successor=successor):
                    original=fixture(case,successor,True,True);v=fixture(case,successor,True,True,'current')
                    before=copy.deepcopy(v);out=build(v);s=v['subsidiary'];root=ET.fromstring(out['xml'])
                    self.assertEqual(v,before);self.assertEqual(v['whole'],original['whole'])
                    self.assertEqual(s['valuation'],original['subsidiary']['valuation'])
                    self.assertEqual(s['result']['taxable_income_cents'],original['subsidiary']['result']['taxable_income_cents']+s['result']['additions_cents'])
                    self.assertEqual(root.find('.//de-gaap-ci:'+RESULT,NS).text,money(s['result']['taxable_income_cents']))
                    self.assertIn('Steuerliche Abschreibung: Synthetic ordinary asset',out['xml'])
                    self.assertNotIn('asset-depreciation:',out['xml'])
        v=fixture('entry',True,True,True,'prior-only');build(v)
        self.assertGreater(v['subsidiary']['previous']['result']['additions_cents'],0)
        self.assertEqual(v['subsidiary']['result']['additions_cents'],0)

    def test_item_expense_mutations_rejected_even_after_resealing(self):
        good=fixture('entry',False,True,True,'current')
        for kind in ('amount','name','identity','tax-hash','wrong-item','stale','overlap','negative','income','missing','raw'):
            v=copy.deepcopy(good);s=v['subsidiary'];src=s['off_balance']['sources'];entry=s['review']['off_balance_adjustments'][0];key=next(iter(entry['sources']))
            if kind=='amount':src[key]['cents']+=1
            if kind=='name':src[key]['name']='Forged source'
            if kind=='identity':src[key]['asset_sha256']='f'*64
            if kind=='tax-hash':src[key]['tax_value_sha256']='f'*64
            if kind=='wrong-item':src[key]['asset_id']='ordinary'
            if kind=='stale':entry['sources']={'asset-depreciation:'+'a'*64:1}
            if kind=='overlap':s['review']['off_balance_adjustments'].append(entry|{'id':'extra','sources':{key:src[key]['cents']}})
            if kind=='negative':entry['sources'][key]=-1
            if kind=='income':entry['kind']='tax_exempt_income'
            if kind=='missing':del src[key]
            if kind=='raw':
                raw=next(k for k,r in src.items() if r['account']=='dep' and r['cents']>0)
                entry['sources']={raw:1}
            base.seal(v)
            with self.subTest(kind=kind),self.assertRaises(ValueError):build(v)

if __name__=='__main__':unittest.main()
