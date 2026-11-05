"""Shared original disposal, independent reconstruction, no network or certificates."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_asset_allocation import check_year,item_parts,rebuild_assets,disposal_movements,capped,annual_values
from ebilanz_club_scope import rebuild,digest
from ebilanz_builder import EbilanzBuilder

KINDS=('scrap','gwg-scrap','degressive-scrap','sale-gain','sale-loss','sale-exempt','software','partial')
VARIANTS=tuple('shared-disposal-'+k+s for k in KINDS+('partial-tax','scrap-tax','sale-gain-tax','sale-loss-tax','software-tax') for s in ('','-carry'))
def load(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/'tests'/('ebilanz-'+name+'-test.py'))
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod

class SharedDisposals(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.profiles={n:load(n) for n in ('club-scope','whole-club','combined-club')}
    def test_original_disposals_all_profiles_and_carry(self):
        for name,module in self.profiles.items():
            for variant in VARIANTS:
                with self.subTest(profile=name,variant=variant):
                    v=module.fixture(variant);mapping,profile=module.inputs(v)
                    result=EbilanzBuilder().build(v,mapping,profile,'12345')
                    self.assertEqual(result['transmission'],'unavailable')
                    self.assertEqual(result,EbilanzBuilder().build(v,mapping,profile,'12345'))
                    if name!='whole-club':
                        taxable=v['taxable'] if name=='combined-club' else v
                        for k,x in rebuild(taxable['club_scope']).items():self.assertEqual(taxable[k],x)
                        self.assertEqual(len(taxable['asset_schedule']['items']),1)
    def test_subsidiary_with_outstanding_internal_advance(self):
        module=load('subsidiary')
        for kind in ('scrap','sale-gain','sale-loss','software','partial','partial-tax','sale-loss-tax','software-tax'):
            with self.subTest(kind=kind):
                v=module.fixture('shared-disposal-'+kind);mapping,profile=module.inputs(v)
                result=EbilanzBuilder().build(v,mapping,profile,'12345')
                self.assertEqual(result['transmission'],'unavailable')
                self.assertEqual(result['subsidiary_result_cents'],v['subsidiary']['result']['taxable_income_cents'])
    def test_original_evidence_strict(self):
        for kind in KINDS:
            v=self.profiles['club-scope'].fixture('shared-disposal-'+kind);item=next(iter(v['asset_schedule']['items'].values()))
            check_year(item,2025);parts=item_parts(item,{'food':1,'shop':2},2025)
            for field in parts['food']:self.assertEqual(sum(r[field] for r in parts.values()),item[field])
            for field,value in {'disposal_hash':'x'*64,'disposal':None,'basis_cents':True,'disposals_cents':-1,'accumulated_disposals_cents':'0'}.items():
                bad=copy.deepcopy(item);bad[field]=value
                with self.subTest(kind=kind,field=field),self.assertRaises(ValueError):item_parts(bad,{'food':1,'shop':2},2025)
            bad=copy.deepcopy(item);bad['disposal']['date']='2026-01-01'
            with self.assertRaises(ValueError):check_year(bad,2025)
    def test_changed_prices_cannot_be_resealed(self):
        v=self.profiles['club-scope'].fixture('shared-disposal-sale-loss');scope=v['club_scope'];a=scope['allocation'];source=scope['source']
        item=next(i for i in source['asset_schedule']['items'].values() if 'disposal' in i);key=item['item_id']
        item['disposal']['gross_cents']+=119;item['disposal']['tax_cents']+=19
        a['asset_shares'][key]['item_sha256']=digest(item)
        with self.assertRaises(ValueError):rebuild(scope)
    def test_disposal_cannot_disappear_in_next_year(self):
        v=self.profiles['club-scope'].fixture('shared-disposal-scrap-carry');scope=v['club_scope']
        item=next(i for i in scope['source']['asset_schedule']['items'].values() if 'disposal' in i)
        for field in ('disposal_hash','disposal','basis_cents','disposal_accounts'):
            bad=copy.deepcopy(scope);del bad['source']['asset_schedule']['items'][item['item_id']][field]
            with self.subTest(field=field),self.assertRaises(ValueError):rebuild(bad)
    def test_capped_monotone_cent_allocation(self):
        for weights in ({'a':1,'b':2},{'a':7,'b':3,'c':1},{'a':1,'b':1000000}):
            for limit in (0,1,5,17):
                caps={u:(limit if u=='a' else 19) for u in weights};slow=dict.fromkeys(weights,0)
                for total in range(sum(caps.values())+1):
                    if total:
                        winner=None
                        for u,w in weights.items():
                            if slow[u]<caps[u] and (winner is None or w*(slow[winner]+1)>weights[winner]*(slow[u]+1)):winner=u
                        slow[winner]+=1
                    self.assertEqual(capped(total,weights,caps),slow)
        self.assertEqual(sum(capped(100000000000,{'a':1000000,'b':1},{'a':99999999999,'b':1}).values()),100000000000)
    def test_partial_costs_lifetime_and_retained_earlier_year(self):
        v=self.profiles['club-scope'].fixture('shared-disposal-partial');base=next(iter(v['asset_schedule']['items'].values()))
        for case in range(1,61):
            item=copy.deepcopy(base);p=item['partial_plan'];p.update(basis_cents=2+case*7,residual_cents=case%(1+case*7),months=36,acquired_on='2025-03-15')
            proof=item['disposal'];proof['date']='2026-04-17';a=proof['allocation'];a['cost_cents']=1+(case*3)%(p['basis_cents']-1);a['residual_cents']=min(p['residual_cents'],a['cost_cents']-1)
            if p['residual_cents']-a['residual_cents']>=p['basis_cents']-a['cost_cents']:continue
            proof['plan_sha256']=digest(p);item.update({f:p[f] for f in ('basis_cents','residual_cents','acquired_on','months')})
            previous=None;charged=0
            for year in range(2025,2031):
                annual=copy.deepcopy(item)
                if year==2025:
                    for f in ('partial_plan','disposal','basis_cents','disposal_hash','disposal_accounts','disposals_cents','accumulated_disposals_cents','book_disposals_cents'):annual.pop(f,None)
                    annual['cost_closing_cents']=p['basis_cents']
                annual.update(annual_values(annual,year));annual['expected_depreciation_cents']=annual['depreciation_cents'];check_year(annual,year)
                parts=item_parts(annual,{'a':1,'b':2,'c':5},year)
                for u,row in parts.items():
                    self.assertGreaterEqual(min(row.values()),0)
                    if previous:
                        for f in ('cost','accumulated','book'):self.assertEqual(row[f+'_opening_cents'],previous[u][f+'_closing_cents'])
                previous=parts;charged+=annual['depreciation_cents']+annual.get('book_disposals_cents',0)
            self.assertEqual(charged+annual['book_closing_cents'],p['basis_cents'])

if __name__=='__main__':unittest.main()
