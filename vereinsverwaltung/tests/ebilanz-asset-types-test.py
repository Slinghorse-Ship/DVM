"""Retained tangible lifecycle with exact annual purposes, not synthetic net balances."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent;ROOT=HERE.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_asset_types import ACCOUNTS,MOVABLE,LINEAR,DEP,TYPES
HISTORIC_MOVABLE=MOVABLE & {code for codes in json.loads((ROOT/'resources/accounting/years/2026-r17.json').read_text(encoding='utf8'))['bindings'].values() for code in codes}
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
from ebilanz_asset_allocation import item_parts
import datev_years as d
def load(name,path):
    s=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
annual=load('asset_types_annual',HERE/'ebilanz-native.py')
dated=load('asset_types_dated',HERE/'ebilanz-period-assets-test.py')

def fixtures(mode):
    p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/ebilanz-asset-types.php'),mode],capture_output=True,encoding='utf8',timeout=180)
    if p.returncode or p.stderr:raise RuntimeError(p.stdout[:2000]+p.stderr)
    return json.loads(p.stdout)

def build(row,mode='annual'):
    v=row['values'];mapping,profile=(dated.inputs(v) if mode=='dated' else annual.inputs(v))
    # Use actual reviewed asset meaning, not the old fixture's generic equipment caption.
    mapping={k:t for k,t in mapping.items() if k not in automatic_mapping(v)}
    if mode=='whole':
        profile.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club')
    return EbilanzBuilder().build(v,mapping,profile,'12345')

import sys as history_sys
history_sys.path.insert(0,str(Path(__file__).resolve().parent))
from datev_history_helpers import original_sources

class AssetTypes(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.annual=fixtures('annual');cls.dated=fixtures('dated');cls.whole=fixtures('whole')
    def test_every_actual_original_asset_path_generates_full_gross_schedule(self):
        self.assertEqual(len(HISTORIC_MOVABLE),20)
        for mode,rows in [('annual',self.annual),('dated',self.dated),('whole',self.whole)]:
            self.assertEqual(len(rows),len(HISTORIC_MOVABLE)*(6 if mode=='dated' else 10))
            for row in rows:
                with self.subTest(mode=mode,code=row['code'],variant=row['variant']):
                    before=copy.deepcopy(row['values']);result=build(row,mode)
                    self.assertEqual(row['values'],before)
                    self.assertEqual(result['asset_items'],1);self.assertGreater(result['asset_fact_count'],0)
                    self.assertIn(ACCOUNTS[row['code']]['target'],result['xml'])
                    if row['preview'] is not None:
                        actual=automatic_mapping(row['values'])
                        for k,v in row['preview']['mapping'].items():self.assertEqual(actual[k],v)
                        for k,item in row['values']['asset_schedule']['items'].items():
                            self.assertEqual(item_parts(item,{'first':2,'second':3},row['values']['year']),row['shared'][k])
    def test_unknown_or_non_depreciable_types_are_not_silently_allowed(self):
        from ebilanz_period_asset_math import plan_check
        base={
            'basis_cents':100001,'residual_cents':0,'months':60,'account':'06300','sphere':'4','acquired_on':'2025-07-01','method':'linear_monthly'}
        for code in ('02000','06750','08000','99999'):
            with self.subTest(code=code),self.assertRaises(ValueError):plan_check(base|{'account':code})
        # Building support was independently added in revision 31.
        self.assertNotIn('02400',MOVABLE)
        self.assertEqual(plan_check(base|{'account':'02400','months':480})[2],480)
        for code in ('01350','06700'):
            self.assertNotIn(code,MOVABLE)
    def test_additive_source_review_and_annual_reuse(self):
        model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,17)))
        for e in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r16.json")
            reviewed,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r17.json")
            previous=d.validate_snapshot(old,model,*catalogs[e['year']]);new=d.bindings(reviewed)
            self.assertEqual({n:new[n] for n in previous},previous)
            self.assertEqual(len(new)-len(previous),18)
            self.assertEqual({n:v for n,v in original_sources(model,e).items() if n in new},new)
            for code in HISTORIC_MOVABLE:self.assertIn(code,new)
    def test_manual_leaf_cannot_disguise_an_unsupported_asset(self):
        from ebilanz_assets import prepare_assets
        for code in ('02400','06750','08000',''):
            v=copy.deepcopy(self.annual[0]['values']);item=next(iter(v['asset_schedule']['items'].values()));key=item['account']
            mapping,p=annual.inputs(v);mapping.update(automatic_mapping(v))
            item['skr_code']=code
            for section in ('book','tax'):v[section]['accounts'][key]['skr_code']=code
            with self.subTest(code=code),self.assertRaisesRegex(ValueError,'Anlagenart'):
                prepare_assets(v,mapping,p,{})
    def test_shared_source_and_depreciation_types_match_php(self):
        script='require "vereinsverwaltung/src/Finance/Assets/AssetTypes.php"; echo json_encode(\\Kulturhus\\Verein\\AssetTypes::accounts());'
        p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],'-r',script],cwd=ROOT.parent,capture_output=True,encoding='utf8',timeout=30)
        self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'')
        php_accounts=json.loads(p.stdout)
        # The separately qualified original pool uses an individual HGB source
        # plan in PHP and a complete fiscal pool in Python. It must never enter
        # the ordinary linear-depreciation account set.
        from ebilanz_pool_original_values import CONTRACTS
        asset_code,asset_group,asset_target=CONTRACTS['original-pool-r73-06750@1']
        depreciation_code,depreciation_group,_=CONTRACTS['original-pool-r73-62640@1']
        self.assertEqual((asset_group,depreciation_group),('fixed_assets','expense'))
        self.assertEqual(php_accounts.pop(asset_code),{'kind':'pool_member','code':depreciation_code,'target':asset_target})
        self.assertNotIn(asset_code,ACCOUNTS)
        self.assertNotIn(asset_code,LINEAR)
        self.assertEqual(php_accounts,{c:{k:t[k] for k in ('kind','code','target')} for c,t in ACCOUNTS.items()})
    def test_new_asset_files_are_part_of_approval_runtime_identity(self):
        text=(ROOT/'src/Finance/Tax/TaxFilings.php').read_text(encoding='utf8')
        for name in ('Finance/Assets/AssetTypes.php','../tools/ebilanz_asset_types.py','../resources/ebilanz/asset-types.json'):self.assertIn("'"+name+"'",text)

if __name__=='__main__':unittest.main(verbosity=2)
