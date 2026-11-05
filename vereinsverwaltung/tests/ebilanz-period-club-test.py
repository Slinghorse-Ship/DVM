"""Independent dated all-business projection; no database or transmission."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))
from ebilanz_builder import EbilanzBuilder
from ebilanz_club_scope import digest
from ebilanz_period_club import verify

def fixture(kind):
    r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(Path(__file__).parent/'fixtures/ebilanz-period-club.php'),kind],capture_output=True,text=True,encoding='utf8',timeout=90)
    if r.returncode or r.stderr.strip():raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

def profile(v):
    changed=bool(v['period_source']['review']['asset_tax_plans'])
    return {'kind':'club_taxable_businesses','source_scope':'approved_club_allocation','tax_privileged':True,'scope_confirmed':True,'tax_values_sha256':v['sha256'],
            'asset_scope':'single_reported_business','asset_values_unchanged':not changed,'asset_tax_values_reviewed':changed,'business_name':'Synthetische Geschaeftsbetriebe',
            'supplier':'Synthetischer Softwaretest','company':{'tax_number':'5192050001276','name':'Synthetischer Verein','street':'Testweg','house_number':'1','postal_code':'11111','city':'Teststadt','country':'Deutschland'}}

def build(v):
    m={'cash':'bs.ass.currAss.cashEquiv.bank',
       'revenue':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
    if not v['tax']['accounts']['equity']['skr_code']:
        m|={'equity':'bs.eqLiab.equity.retainedEarnings','expense':'is.netIncome.regular.operatingTC.otherCost.administration'}
    return EbilanzBuilder().build(v,m,profile(v),'12345')

def seal(v):v['sha256']=digest({k:n for k,n in v.items() if k not in ('sha256','transmission')})

class PeriodClub(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.cases={k:fixture(k) for k in ('book','tax','book-successor','tax-successor','reversal','reversal-successor')}
    def test_generated_dates_original_and_all_taxable_units(self):
        for case,v in self.cases.items():
            with self.subTest(case=case):
                before=copy.deepcopy(v);out=build(v);self.assertEqual(before,v)
                self.assertEqual(out['period'],v['period']);self.assertEqual(out['asset_items'],1)
                self.assertEqual(v['period_club']['income']['selected_units'],['food','shop'])
                self.assertEqual(len(v['period_club']['whole']['asset_schedule']['items']),2)
                self.assertEqual(v['source_spheres'],['4']);self.assertEqual(v['book']['income_cents'],0 if case.endswith('successor') else 9997)
                self.assertEqual(v['result_difference_cents'],6000 if case=='tax-successor' else 10000 if case=='tax' else 0)
                self.assertEqual(out['transmission'],'unavailable')
    def test_cancellation_link_retained(self):
        v=self.cases['reversal'];entries=v['period_source']['source']['entries']
        self.assertEqual(entries[1]['reversal_of'],entries[0]['id'])
        self.assertEqual(v['asset_schedule']['items']['shared']['schedule']['additions_cents'],120001)
        self.assertEqual(build(v)['asset_items'],1)
        bad=copy.deepcopy(v);del bad['period_source']['source']['entries'][1]['reversal_of'];seal(bad)
        with self.assertRaises(ValueError):verify(bad)
    def test_internal_advances_do_not_become_external_debts(self):
        v=fixture('internal-advance');out=build(v)
        self.assertEqual(v['book']['annual_result_cents'],2007)
        self.assertEqual(out['asset_items'],0)
        self.assertEqual(v['period_club']['income']['selected_units'],['club','food','shop'])
    def test_resealed_forged_scope_and_source_rejected(self):
        for case in ('selected','allocation','whole','asset','date','opening','prior','tax-plan','original','extra'):
            v=copy.deepcopy(self.cases['tax-successor']);p=v['period_club'];inc=p['income'];s=inc['scope']['source']
            if case=='selected':inc['selected_units']=['food']
            if case=='allocation':inc['scope']['allocation']['allocations'][next(iter(inc['scope']['allocation']['allocations']))]['parts']={'food':1}
            if case=='whole':p['whole']['book']['income_cents']+=1;seal(p['whole'])
            if case=='asset':v['asset_schedule']['items']['shared']['plan']['basis_cents']+=1
            if case=='date':s['period']['start']='2026-08-01'
            if case=='opening':v['period_source']['review']['opening_adjustments']=[]
            if case=='prior':p['previous']=None
            if case=='tax-plan':v['period_source']['review']['asset_tax_plans']=[]
            if case=='original':s['entries'][0]['lines'][0]['debit_cents']+=1
            if case=='extra':p['extra']=True
            seal(inc);seal(v)
            with self.subTest(case=case),self.assertRaises((ValueError,KeyError)):verify(v)
    def test_cannot_promote_partial_to_single_or_whole(self):
        v=self.cases['book']
        for kind,scope in [('single_taxable_business','separate_business_ledger'),('whole_taxable_club','complete_club_ledger')]:
            p=profile(v)|{'kind':kind,'source_scope':scope}
            with self.subTest(kind=kind),self.assertRaises(ValueError):EbilanzBuilder().build(v,{},p,'12345')

if __name__=='__main__':unittest.main()
