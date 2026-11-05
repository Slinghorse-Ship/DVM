"""Source-specific immovable facilities retain 62200 even on a shared building BS leaf."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_asset_types import ACCOUNTS,TYPES,IMMOVABLE_FIXTURES,ORDINARY_EQUIPMENT,MOVABLE,BUILDING,LINEAR,TARGET_CHOICES
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
from ebilanz_period_asset_math import plan_check
s=importlib.util.spec_from_file_location('fixtures_shared_buildings',ROOT/'tests/ebilanz-building-r31-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
CODES={'02800','02850','02900','03100','03150','03200','03900','03950','03980','06800'}
EXPENSE='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'
def fixtures(mode,whole=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-fixtures-r38.php'),mode,'whole' if whole else 'single'],capture_output=True,encoding='utf8',timeout=180)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):return shared.inputs(row,TARGET_CHOICES[row['code']][0])
def cases():
 for mode in ('annual','dated'):
  for whole in (False,True):yield from fixtures(mode,whole)
class ImmovableFixtures(unittest.TestCase):
 @classmethod
 def setUpClass(cls):cls.rows=list(cases())
 def test_original_gross_annual_dated_tax_and_carry_values(self):
  b=EbilanzBuilder()
  for row in self.rows:
   with self.subTest(code=row['code'],mode=row['mode'],whole=row['whole'],variant=row['variant']):
    v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);package=b.build(v,m,p,'12345');f=shared.shared.facts(package,row['whole'])
    self.assertEqual(package['asset_items'],1);self.assertGreater(package['asset_fact_count'],0)
    for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(f[target],shared.shared.money(v['tax'][key]))
    fixed=next(k for k,a in v['tax']['accounts'].items() if a['group']=='fixed_assets');dep=next(k for k,a in v['tax']['accounts'].items() if a['skr_code']=='62200')
    self.assertEqual(f[TARGET_CHOICES[row['code']][0]],shared.shared.money(v['tax']['accounts'][fixed]['amount_cents']));self.assertEqual(f.get(EXPENSE,'0.00'),shared.shared.money(v['tax']['accounts'][dep]['amount_cents']))
    self.assertNotIn(fixed,automatic_mapping(v));self.assertEqual(automatic_mapping(v)[dep],EXPENSE);self.assertEqual(row['preview']['mapping'][dep],EXPENSE);self.assertEqual(v,before)
 def test_shared_bs_leaf_never_changes_original_afa_or_movability(self):
  self.assertEqual(IMMOVABLE_FIXTURES,CODES)
  for code in CODES:
   self.assertIn(code,LINEAR);self.assertIn(code,ORDINARY_EQUIPMENT);self.assertNotIn(code,MOVABLE);self.assertNotIn(code,BUILDING)
   self.assertEqual(ACCOUNTS[code]['code'],'62200');self.assertEqual(ACCOUNTS[code]['expense'],EXPENSE);self.assertEqual(TYPES[ACCOUNTS[code]['target']]['code'],'62210')
   p={'basis_cents':100001,'residual_cents':0,'months':60,'acquired_on':'2025-07-01','sphere':'4','account':code,'method':'linear_monthly','reason':'Confirmed fixed outside facility'};plan_check(p)
   with self.assertRaises(ValueError):plan_check(p|{'method':'degressive','degressive':{'rate_basis_points':3000,'movable':True,'same_book_tax':True,'switch_to_linear':True}})
 def test_required_type_review_and_exact_original_history(self):
  import datev_years as d
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for e in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r37.json");before=d.validate_snapshot(old,model,*catalogs[e['year']]);sealed,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r38.json");after=d.validate_snapshot(sealed,model,*catalogs[e['year']]);current=d.bindings(e);self.assertEqual({c:current[c] for c in after},after)
   self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),CODES)
  for row in self.rows:
   m,p=inputs(row);v=row['values'];fixed=next(k for k,a in v['tax']['accounts'].items() if a['group']=='fixed_assets');missing=m.copy();missing.pop(fixed)
   with self.assertRaises(ValueError):EbilanzBuilder().build(v,missing,p,'12345')
if __name__=='__main__':unittest.main(verbosity=2)
