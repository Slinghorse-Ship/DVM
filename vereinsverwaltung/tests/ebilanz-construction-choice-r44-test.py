"""Original collective construction classification and evidenced valuation lifecycle."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import datev_years as d
from ebilanz_asset_types import COST,LINEAR,cost_target,TARGET_CHOICES
from ebilanz_asset_valuation import from_book_plan
from ebilanz_builder import EbilanzBuilder
s=importlib.util.spec_from_file_location('construction_r44_shared',ROOT/'tests/ebilanz-cost-assets-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
TARGETS=TARGET_CHOICES['07000'];FIXTURE=ROOT/'tests/fixtures/ebilanz-construction-choice-r44.php'
@lru_cache(maxsize=100)
def fixture(target,variant='multiple',mode='annual'):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(FIXTURE),'07000',variant,mode]+([target] if target is not None else []),capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def cases():
 for target in TARGETS:
  for mode in ('annual','whole','period','period-whole'):
   for variant in ('multiple','tax-only','writeup','writeup-release'):
    for row in fixture(target,variant,mode):yield {'year':row['values']['year'],'target':target,'code':'07000','mode':mode,'variant':variant,'values':row['values']}
def inputs(row):return shared.inputs(row['values'])
class ConstructionChoice(unittest.TestCase):
 def test_original_classification_and_full_cost_lifecycles(self):
  b=EbilanzBuilder();count=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);package=b.build(v,m,p,'12345');self.assertIn(row['target'],package['xml']);self.assertEqual(v,before);self.assertEqual(package['asset_items'],1);count+=1
  self.assertEqual(count,72)
  for target in TARGETS:
   for mode in ('mixed','mixed-whole'):
    for f in fixture(target,'multiple',mode):m,p=shared.inputs(f['values']);self.assertEqual(b.build(f['values'],m,p,'12345')['asset_items'],2)
 def test_no_inferred_or_changed_source_classification(self):
  self.assertIn('07000',COST);self.assertNotIn('07000',LINEAR)
  for target in (None,'','null','bs.ass.fixAss.fin.otherLoans.other','bs.ass.fixAss.tan.otherEquipm.office'):
   with self.assertRaisesRegex(RuntimeError,'ursprüngliche Anlagenart'):fixture(target)
  for target in TARGETS:
   v=fixture(target)[0]['values'];m,p=shared.inputs(v)
   for other in set(TARGETS)-{target}:
    with self.assertRaisesRegex(ValueError,'Kostenanlagen'):EbilanzBuilder().build(v,m|{'fixed':other},p,'12345')
   plan=v['asset_schedule']['items']['synthetic-cost']['cost_plan'];self.assertEqual(cost_target(plan),target)
   for bad in ({'target':None},{'target':'invented'},{'valuation_kind':'financial'}):
    with self.assertRaises(ValueError):from_book_plan(plan|bad)
 def test_additive_exact_original_source_and_target_labels(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for e in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r43.json");before=d.validate_snapshot(old,model,*catalogs[e['year']]);now=d.validate_snapshot(e,model,*catalogs[e['year']]);self.assertEqual({c:now[c] for c in before},before);self.assertEqual(set(now)-set(before),{'07000'})
 def test_actual_php_plan_requires_retained_choice_and_form_offers_it(self):
  for target in TARGETS:
   plan=fixture(target,'unchanged')[0]['values']['asset_schedule']['items']['synthetic-cost']['cost_plan'];incoming={'basis':'120,00','acquired_on':plan['acquired_on'],'sphere':plan['sphere'],'account':'07000','reason':plan['reason'],'method':'cost','valuation_kind':'construction','valuation_document':plan['valuation_document'],'valuation_target':target,'asset_reviewed':'1'}
   script='require '+json.dumps(str(ROOT/'src/bootstrap.php'))+'; echo json_encode(\\Kulturhus\\Verein\\AssetBooks::plan(json_decode(stream_get_contents(STDIN),true)));'
   p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],'-r',script],input=json.dumps(incoming),capture_output=True,encoding='utf8');self.assertEqual(p.returncode,0,p.stdout+p.stderr);self.assertEqual(json.loads(p.stdout),plan)
  form=(ROOT/'views/Finance/Accounting/finance-book-values.php').read_text();self.assertIn('type="radio" name="valuation_target"',form);self.assertNotIn('<select name="valuation_target"',form)
  for target in TARGETS:self.assertIn(target,form)
if __name__=='__main__':unittest.main(verbosity=2)
