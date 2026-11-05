"""Acquired goodwill: retained original books, 15-year tax values and dated successors."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_asset_types import GOODWILL,LINEAR,MOVABLE,DISPOSABLE,DEP
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
from ebilanz_period_asset_math import plan_check

def load(name):
 s=importlib.util.spec_from_file_location('goodwill_'+name.replace('-','_'),ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
base=load('ebilanz-native.py');shared=load('ebilanz-operating-extended-test.py')
ASSET='bs.ass.fixAss.intan.goodwill.purchased';EXPENSE='is.netIncome.regular.operatingTC.deprAmort.fixAss.goodwill'
def fixtures(mode,whole=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-goodwill-r37.php'),mode,'whole' if whole else 'single'],capture_output=True,encoding='utf8',timeout=120)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values'];m,p=base.inputs(v);auto=automatic_mapping(v)
 m={k:t for k,t in m.items() if k in v['tax']['accounts'] and k not in auto and v['tax']['accounts'][k]['group']!='fixed_assets'}
 if row['mode']=='dated':
  changed=bool(v['period_source']['review']['asset_tax_plans']);p.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
 if row['whole']:p.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club')
 return m,p
def cases():
 for mode in ('annual','dated'):
  for whole in (False,True):
   yield from fixtures(mode,whole)
class Goodwill(unittest.TestCase):
 @classmethod
 def setUpClass(cls):cls.rows=list(cases())
 def test_actual_gross_book_tax_journals_and_successors(self):
  for row in self.rows:
   with self.subTest(mode=row['mode'],whole=row['whole'],variant=row['variant']):
    v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);package=EbilanzBuilder().build(v,m,p,'12345');facts=shared.facts(package,row['whole'])
    self.assertEqual(package['asset_items'],1);self.assertGreater(package['asset_fact_count'],0)
    for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(facts[target],shared.money(v['tax'][key]))
    fixed=next(k for k,a in v['tax']['accounts'].items() if a['skr_code']=='01500');dep=next(k for k,a in v['tax']['accounts'].items() if a['skr_code']=='62050')
    self.assertEqual(facts[ASSET],shared.money(v['tax']['accounts'][fixed]['amount_cents']));self.assertEqual(facts.get(EXPENSE,'0.00'),shared.money(v['tax']['accounts'][dep]['amount_cents']))
    self.assertEqual(automatic_mapping(v)[fixed],ASSET);self.assertEqual(automatic_mapping(v)[dep],EXPENSE);self.assertEqual(row['preview']['mapping'][dep],EXPENSE);self.assertEqual(v,before)
 def test_no_tax_life_inferred_and_no_other_asset_kind(self):
  self.assertEqual(GOODWILL,{'01500'});self.assertIn('01500',LINEAR);self.assertNotIn('01500',MOVABLE);self.assertNotIn('01500',DISPOSABLE);self.assertEqual(DEP['01500'],'62050')
  p={'basis_cents':100001,'residual_cents':0,'months':120,'acquired_on':'2025-01-01','sphere':'4','account':'01500','method':'linear_monthly','reason':'Documented original book life'}
  plan_check(p)
  with self.assertRaises(ValueError):plan_check(p|{'residual_cents':1})
  for method in ('gwg_immediate','degressive'):
   with self.assertRaises(ValueError):plan_check(p|{'method':method})
  for row in self.rows:
   v=row['values'];dep=next(k for k,a in v['tax']['accounts'].items() if a['skr_code']=='62050')
   self.assertNotIn(dep,automatic_mapping({'year':v['year'],'tax':{'accounts':{dep:v['tax']['accounts'][dep]}}}))
   m,p=inputs(row);fixed=next(k for k,a in v['tax']['accounts'].items() if a['skr_code']=='01500')
   with self.assertRaises(ValueError):EbilanzBuilder().build(v,m|{fixed:'bs.ass.fixAss.intan.goodwill.consolidated'},p,'12345')
 def test_wrong_tax_life_fails_in_both_php_paths(self):
  for mode in ('annual','dated'):
   for scope in ('single','whole'):
    p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-goodwill-r37.php'),mode,scope,'bad-life'],capture_output=True,encoding='utf8',timeout=60)
    self.assertNotEqual(p.returncode,0);self.assertIn('15 Jahre',p.stdout+p.stderr)
 def test_original_year_sources_remain_additive(self):
  from datev_history_helpers import original_sources
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r36.json");before=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);sealed,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r37.json");after=shared.d.validate_snapshot(sealed,model,*catalogs[e['year']]);current=shared.d.bindings(e);self.assertEqual({c:current[c] for c in after},after)
   self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),{'01500','62050'})
   self.assertEqual({c:v for c,v in original_sources(model,e).items() if c in before},original_sources(model,old))
if __name__=='__main__':unittest.main(verbosity=2)
