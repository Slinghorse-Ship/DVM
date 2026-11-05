"""Retained acquired rights and explicit generic asset types with real PHP journals."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import datev_years as d
from ebilanz_asset_types import ACCOUNTS,TARGET_CHOICES,AMORTIZABLE_INTANGIBLE,MOVABLE,BUILDING,DISPOSABLE,accepts_target
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
from ebilanz_asset_allocation import item_parts
from ebilanz_period_asset_math import plan_check
def load(name):
 s=importlib.util.spec_from_file_location('building_r31_'+name.replace('-','_'),ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
base=load('ebilanz-native.py');shared=load('ebilanz-operating-extended-test.py')
REVIEW=json.loads((ROOT/'resources/accounting/reviews/building-r31.json').read_text(encoding='utf8'))
CODES={'03500', '02500', '03000', '03800', '03400', '03700', '02300', '02600', '03050', '02410', '03600', '02430', '02400', '03300', '02700', '02440'}
def fixtures(mode,whole=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-building-r31.php'),mode,'whole' if whole else 'single'],capture_output=True,encoding='utf8',timeout=180)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row,target=None):
 v=row['values'];mapping,profile=base.inputs(v)
 auto=automatic_mapping(v)
 mapping={k:t for k,t in mapping.items() if k in v['tax']['accounts'] and k not in auto and v['tax']['accounts'][k]['group']!='fixed_assets'}
 for k,a in v['tax']['accounts'].items():
  if a['group']=='fixed_assets' and target is not None:mapping[k]=target
 if row['mode']=='dated':
  changed=bool(v['period_source']['review']['asset_tax_plans']);profile.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
 if row['whole']:profile.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club')
 return mapping,profile
def build(row,target=None):
 m,p=inputs(row,target);return EbilanzBuilder().build(row['values'],m,p,'12345')
import sys as history_sys
history_sys.path.insert(0,str(Path(__file__).resolve().parent))
from datev_history_helpers import original_sources

class BuildingAssetsR31(unittest.TestCase):
 @classmethod
 def setUpClass(cls):cls.rows=[row for mode in ('annual','dated') for whole in (False,True) for row in fixtures(mode,whole)]
 def test_every_choice_preserves_gross_journal_and_result(self):
  for row in self.rows:
   v=row['values'];before=copy.deepcopy(v)
   for target in TARGET_CHOICES[row['code']]:
    with self.subTest(code=row['code'],mode=row['mode'],whole=row['whole'],variant=row['variant'],target=target):
     package=build(row,target);facts=shared.facts(package,row['whole'])
     self.assertEqual(package['asset_items'],1);self.assertGreater(package['asset_fact_count'],0)
     for t,k in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(facts[t],shared.money(v['tax'][k]))
     fixed=next(a for a in v['tax']['accounts'].values() if a['group']=='fixed_assets')
     self.assertEqual(facts[target],shared.money(fixed['amount_cents']));self.assertEqual(v,before)
 def test_building_never_maps_to_land_or_movable_assets(self):
  for row in self.rows:
   fixed=next(k for k,a in row['values']['tax']['accounts'].items() if a['group']=='fixed_assets')
   self.assertEqual(automatic_mapping(row['values'])[fixed],TARGET_CHOICES[row['code']][0])
   for target in ('bs.ass.fixAss.tan.landBuildings.landWithoutBuildings','bs.ass.fixAss.tan.otherEquipm'):
    with self.assertRaises(ValueError):build(row,target)
 def test_depreciation_and_shared_values_match_php(self):
  for row in self.rows:
   v=row['values'];auto=automatic_mapping(v)
   for k,a in v['tax']['accounts'].items():
    if a['group']=='expense' and a['skr_code'] in ('62210',):
     self.assertEqual(auto[k],row['preview']['mapping'][k])
     if a['skr_code']=='62000':self.assertIn('Rechte',row['preview']['rows'][k]['label'])
   for k,parts in (row['shared'] or {}).items():self.assertEqual(item_parts(v['asset_schedule']['items'][k],{'first':2,'second':3},v['year']),parts)
 def test_building_cannot_use_equipment_degressive_gwg_or_disposal(self):
  p={'basis_cents':100001,'residual_cents':0,'months':480,'account':'02400','sphere':'4','acquired_on':'2025-07-01','method':'linear_monthly'}
  for code in CODES:
   self.assertIn(code,BUILDING);self.assertNotIn(code,MOVABLE);self.assertNotIn(code,DISPOSABLE);plan_check(p|{'account':code})
   with self.assertRaises(ValueError):plan_check(p|{'account':code,'method':'degressive','degressive':{'rate_basis_points':3000,'movable':True,'same_book_tax':True,'switch_to_linear':True}})
   with self.assertRaises(ValueError):plan_check(p|{'account':code,'method':'gwg_immediate','months':1,'gwg':{'net_cents':100001,'vat_cents':0,'deductible_cents':0,'independent_movable':True,'no_pool':True}})
 def test_depreciation_without_full_schedule_is_not_automatic(self):
  for row in self.rows:
   v=copy.deepcopy(row['values']);keys=[k for k,a in v['tax']['accounts'].items() if a['skr_code']=='62210']
   for key in keys:self.assertEqual(automatic_mapping(v)[key],'is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.buildings')
   # A bare account has no retained proof, even when the amount is zero.
   from ebilanz_mapping import automatic_mapping as auto
   for key in keys:
    bare={'year':v['year'],'tax':{'accounts':{key:v['tax']['accounts'][key]}}}
    self.assertNotIn(key,auto(bare))
 def test_prior_bindings_preserved_and_meanings_source_pinned(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for e in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r30.json");before=d.validate_snapshot(old,model,*catalogs[e['year']]);sealed,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r31.json");after=d.validate_snapshot(sealed,model,*catalogs[e['year']])
   original=original_sources(model,e)
   # Four later, separately reviewed purposes preserve the original definitions.
   later={'49120':('cost-register-r26-49120@1','cost-register-r26-49120@3'),'68970':('cost-register-r26-68970@1','cost-register-r26-68970@2'),'72000':('cost-register-r26-72000@1','cost-register-r26-72000@2'),'72010':('cost-register-r26-72010@1','cost-register-r26-72010@3')}
   for code,(historic,current) in later.items():
    self.assertEqual(after[code],historic);self.assertEqual(original[code],current)
   self.assertEqual({c:v for c,v in original.items() if c in after and c not in later},{c:v for c,v in after.items() if c not in later})
   self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),CODES|{'62210'})
   for g in next(y for y in REVIEW['years'] if y['year']==e['year'])['groups']:
    for source in g['sources']:
     self.assertEqual(d.catalog(catalogs[e['year']][0])[source['code']]['signature'],source['signature'])
     if source['code']!='62210':
      for target in g['choices']:self.assertTrue(accepts_target(source['code'],target))
if __name__=='__main__':unittest.main(verbosity=2)
