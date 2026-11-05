"""Retained acquired rights and explicit generic asset types with real PHP journals."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import datev_years as d
from ebilanz_asset_types import ACCOUNTS,TARGET_CHOICES,AMORTIZABLE_INTANGIBLE,MOVABLE,accepts_target
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
from ebilanz_asset_allocation import item_parts
from ebilanz_period_asset_math import plan_check
def load(name):
 s=importlib.util.spec_from_file_location('linear_r27_'+name.replace('-','_'),ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
base=load('ebilanz-native.py');shared=load('ebilanz-operating-extended-test.py')
REVIEW=json.loads((ROOT/'resources/accounting/reviews/linear-assets-r27.json').read_text(encoding='utf8'))
CODES={'01000','01100','01200','01300','01400','04000','05000'}
def fixtures(mode,whole=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-linear-r27.php'),mode,'whole' if whole else 'single'],capture_output=True,encoding='utf8',timeout=180)
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

class LinearAssetsR27(unittest.TestCase):
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
 def test_generic_choice_is_never_inferred_from_plan_or_zero_value(self):
  for row in self.rows:
   if row['code'] not in ('01000','04000','05000'):continue
   fixed=next(k for k,a in row['values']['tax']['accounts'].items() if a['group']=='fixed_assets')
   self.assertNotIn(fixed,automatic_mapping(row['values']));self.assertEqual(row['preview']['rows'][fixed]['status'],'review')
   with self.assertRaises(ValueError):build(row)
   with self.assertRaises(ValueError):build(row,'bs.ass.fixAss.tan.landBuildings.landWithoutBuildings')
 def test_depreciation_and_shared_values_match_php(self):
  for row in self.rows:
   v=row['values'];auto=automatic_mapping(v)
   for k,a in v['tax']['accounts'].items():
    if a['group']=='expense' and a['skr_code'] in ('62000','62200'):
     self.assertEqual(auto[k],row['preview']['mapping'][k])
     if a['skr_code']=='62000':self.assertIn('Rechte',row['preview']['rows'][k]['label'])
   for k,parts in (row['shared'] or {}).items():self.assertEqual(item_parts(v['asset_schedule']['items'][k],{'first':2,'second':3},v['year']),parts)
 def test_acquired_rights_cannot_use_movable_or_gwg_elections(self):
  p={'basis_cents':100001,'residual_cents':0,'months':60,'account':'01100','sphere':'4','acquired_on':'2025-07-01','method':'linear_monthly'}
  for code in CODES-{'04000','05000'}:
   self.assertIn(code,AMORTIZABLE_INTANGIBLE);self.assertNotIn(code,MOVABLE);plan_check(p|{'account':code})
   with self.assertRaises(ValueError):plan_check(p|{'account':code,'method':'degressive','degressive':{'rate_basis_points':3000,'movable':True,'same_book_tax':True,'switch_to_linear':True}})
 def test_prior_bindings_preserved_and_meanings_source_pinned(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for e in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r26.json");before=d.validate_snapshot(old,model,*catalogs[e['year']]);sealed,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r27.json");after=d.validate_snapshot(sealed,model,*catalogs[e['year']])
   self.assertEqual({c:v for c,v in original_sources(model,e).items() if c in after},after)
   self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),CODES)
   for g in next(y for y in REVIEW['years'] if y['year']==e['year'])['groups']:
    for source in g['sources']:
     self.assertEqual(d.catalog(catalogs[e['year']][0])[source['code']]['signature'],source['signature'])
     for target in g['choices']:self.assertTrue(accepts_target(source['code'],target))
if __name__=='__main__':unittest.main(verbosity=2)
