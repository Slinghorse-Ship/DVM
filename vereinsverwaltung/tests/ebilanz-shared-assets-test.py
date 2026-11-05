"""Independent shared-asset reconstruction and export; synthetic, no transport."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_club_scope import rebuild
from ebilanz_asset_allocation import cents, item_parts, check_year
from ebilanz_builder import EbilanzBuilder

def load(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/'tests'/('ebilanz-'+name+'-test.py'))
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod

class SharedAssets(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.profiles={n:load(n) for n in ('club-scope','whole-club','combined-club')}

    def test_profiles_and_years(self):
        for name,module in self.profiles.items():
            for variant in ('shared-assets','shared-assets-tax','shared-assets-carry','shared-assets-funding','shared-assets-funding-carry','shared-assets-gwg','shared-assets-gwg-carry','shared-assets-degressive','shared-assets-degressive-carry'):
                with self.subTest(profile=name,variant=variant):
                    v=module.fixture(variant);mapping,profile=module.inputs(v)
                    result=EbilanzBuilder().build(v,mapping,profile,'12345')
                    self.assertEqual(result['transmission'],'unavailable')
                    self.assertNotIn('Synthetic usage record',result['xml'])
                    self.assertEqual(result,EbilanzBuilder().build(v,mapping,profile,'12345'))
                    if name!='whole-club':
                        taxable=v['taxable'] if name=='combined-club' else v
                        for k,x in rebuild(taxable['club_scope']).items():self.assertEqual(taxable[k],x)
                        self.assertEqual(len(taxable['asset_schedule']['items']),1)

    def test_source_types_and_cross_spheres(self):
        original=self.profiles['club-scope'].fixture('shared-assets')['club_scope']
        for kind in ('hash','reason','fraction','negative','bool','unknown','cross','missing','measure','disposal','schema'):
            s=copy.deepcopy(original);shares=s['allocation']['asset_shares'];key=next(iter(shares));row=shares[key];unit=next(iter(row['weights']))
            if kind=='hash':row['item_sha256']='0'*64
            elif kind=='reason':row['reason']=''
            elif kind=='fraction':row['weights'][unit]=1.0
            elif kind=='negative':row['weights'][unit]=-1
            elif kind=='bool':row['weights'][unit]=True
            elif kind=='unknown':row['weights']['unknown']=1
            elif kind=='cross':row['weights']={'club':1}
            elif kind=='missing':del shares[key]
            elif kind=='measure':row['measure']='guess'
            elif kind=='disposal':s['source']['asset_schedule']['items'][key]['disposal']={'kind':'sale'}
            else:s['schema']=3
            with self.subTest(kind=kind),self.assertRaises(ValueError):rebuild(s)

    def test_monotone_rounding(self):
        for weights in ({'a':1,'b':2},{'a':1,'b':1,'c':1},{'a':7,'b':3,'c':1},{'a':1,'b':1000000}):
            previous=dict.fromkeys(weights,0)
            for n in range(302):
                row=cents(n,weights);self.assertEqual(sum(row.values()),n)
                for key,value in row.items():self.assertGreaterEqual(value,previous[key])
                previous=row
            self.assertEqual(sum(cents(100000000000,weights).values()),100000000000)

    def test_strict_expected_depreciation(self):
        scope=self.profiles['club-scope'].fixture('shared-assets')['club_scope']
        item=next(iter(scope['source']['asset_schedule']['items'].values()))
        for value in (True,float(item['expected_depreciation_cents']),str(item['expected_depreciation_cents'])):
            bad=copy.deepcopy(item);bad['expected_depreciation_cents']=value
            with self.subTest(value=value),self.assertRaises(ValueError):item_parts(bad,{'a':1,'b':2})

    def test_whole_asset_eligibility_not_fraction(self):
        for method in ('gwg','degressive'):
            source=self.profiles['club-scope'].fixture('shared-assets-'+method)['club_scope']['source']
            item=next(i for i in source['asset_schedule']['items'].values() if i['sphere']=='4')
            check_year(item,2025)
            changes=({'net_cents':80001,'independent_movable':False,'no_pool':False,'deductible_cents':1,'unknown':1}
                     if method=='gwg' else {'rate_basis_points':3001,'movable':False,'same_book_tax':False,'switch_to_linear':1,'unknown':1})
            for field,value in changes.items():
                bad=copy.deepcopy(item);bad[method][field]=value
                if field=='net_cents':
                    for f in ('cost_closing_cents','additions_cents','depreciation_cents','accumulated_closing_cents','expected_depreciation_cents'):bad[f]=80001
                with self.subTest(method=method,field=field),self.assertRaises(ValueError):item_parts(bad,{'a':1,'b':2})
            for field in ('disposal','partial_plan','disposals_cents'):
                bad=copy.deepcopy(item);bad[field]=None
                with self.subTest(method=method,field=field),self.assertRaises(ValueError):item_parts(bad,{'a':1,'b':2})
            bad=copy.deepcopy(item)
            for f in ('depreciation_cents','expected_depreciation_cents','accumulated_closing_cents'):bad[f]+=1
            bad['book_closing_cents']-=1
            with self.assertRaises(ValueError):check_year(bad,2025)
            with self.assertRaises(ValueError):check_year(item,2024)

    def test_each_method_cumulative_carry(self):
        from ebilanz_asset_allocation import annual_values
        for method in ('gwg','degressive'):
            source=self.profiles['club-scope'].fixture('shared-assets-'+method)['club_scope']['source']
            item=next(i for i in source['asset_schedule']['items'].values() if i['sphere']=='4')
            previous=None;total=0
            for year in range(2025,2032):
                annual=item|annual_values(item,year);annual['expected_depreciation_cents']=annual['depreciation_cents']
                check_year(annual,year);parts=item_parts(annual,{'a':1,'b':2});total+=annual['depreciation_cents']
                if previous:
                    for unit,row in parts.items():
                        for field in ('cost','accumulated','book'):self.assertEqual(row[field+'_opening_cents'],previous[unit][field+'_closing_cents'])
                previous=parts
            self.assertEqual(total,12001);self.assertEqual(sum(r['book_closing_cents'] for r in parts.values()),0)

if __name__=='__main__':unittest.main()
