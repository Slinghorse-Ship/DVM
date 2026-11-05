"""Original ordinary long-term loans, deposits and retained insurance claims."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import datev_years as d
from ebilanz_asset_types import ACCOUNTS,COST,MOVABLE,LINEAR
from ebilanz_mapping import automatic_mapping
from ebilanz_period_asset_math import plan_check
from ebilanz_builder import EbilanzBuilder
spec=importlib.util.spec_from_file_location('construction_cost',ROOT/'tests/ebilanz-cost-assets-test.py');shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
REVIEW=json.loads((ROOT/'resources/accounting/reviews/intangible-advances-r46.json').read_text())
CODES={s['code'] for g in REVIEW['years'][0]['groups'] if g['group']=='fixed_assets' for s in g['sources']}

def rows():
 for code in sorted(CODES):
  for mode in ('annual','whole','period','period-whole','mixed','mixed-whole'):
   for variant in ('unchanged','impairment','tax-only','multiple','writeup','writeup-release','fully'):
    if mode.startswith('mixed') and variant=='fully':continue
    for row in shared.fixtures(code,variant,mode):yield row,mode

def cases():
 for code in sorted(CODES):
  for mode in ('annual','whole','period','period-whole'):
   for variant in ('multiple','tax-only','writeup','writeup-release'):
    for row in shared.fixtures(code,variant,mode):
     yield {'code':code,'mode':mode,'variant':variant,'year':row['values']['year'],'values':row['values']}
def inputs(row):return shared.inputs(row['values'])
class Construction(unittest.TestCase):
 def test_every_original_journal_and_gross_valuation_in_all_scopes(self):
  builder=EbilanzBuilder();count=0
  for row,mode in rows():
   with self.subTest(code=row['code'],variant=row['variant'],mode=mode,year=row['values']['year']):
    v=row['values'];before=copy.deepcopy(v);m,p=shared.inputs(v)
    self.assertEqual(automatic_mapping(v),row['automatic']);result=builder.build(v,m,p,'12345')
    self.assertEqual(result['asset_items'],2 if mode.startswith('mixed') else 1)
    self.assertIn(ACCOUNTS[row['code']]['target'],result['xml']);self.assertEqual(v,before);count+=1
  self.assertEqual(count,104)
 def test_nondepreciable_capability_and_unproved_values_do_not_bypass(self):
  for code in sorted(CODES):
   self.assertIn(code,COST);self.assertNotIn(code,LINEAR|MOVABLE);self.assertEqual(ACCOUNTS[code]['kind'],'intangible_advance')
   with self.assertRaises(ValueError):plan_check({'basis_cents':100001,'residual_cents':0,'months':60,'acquired_on':'2025-07-01','sphere':'4','account':code,'method':'linear_monthly'})
   value=shared.fixtures(code,'unchanged')[0]['values'];m,p=shared.inputs(value)
   for mutate in (lambda v:v['asset_schedule']['items']['synthetic-cost']['cost_plan'].__setitem__('valuation_kind','land'),lambda v:v['asset_schedule']['items']['synthetic-cost']['valuation'].__setitem__('sha256','0'*64)):
    bad=copy.deepcopy(value);mutate(bad)
    with self.assertRaises(ValueError):EbilanzBuilder().build(bad,m,p,'12345')
   self.assertEqual(automatic_mapping({'year':2025,'tax':{'accounts':{'asset':{'skr_code':code,'group':'fixed_assets','amount_cents':0}}},'asset_schedule':{'year':2025,'items':{},'issues':[]}}),{})
 def test_additive_source_review_preserves_all_previous_bindings(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  self.assertEqual(CODES,{'01700','01790'})
  for current in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r45.json");prior=d.validate_snapshot(old,model,*catalogs[current['year']]);sealed,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r46.json");now=d.validate_snapshot(sealed,model,*catalogs[current['year']])
   self.assertEqual({c:d.bindings(current)[c] for c in now},now)
   self.assertEqual({c:now[c] for c in prior},prior);self.assertEqual(set(now)-set(prior),CODES|{'62100'})
   groups=next(y for y in REVIEW['years'] if y['year']==current['year'])['groups']
   for g in groups:
    self.assertEqual(d.mapping(model,g['purpose'],'6.9')['condition'],'cost_schedule')
    for source in g['sources']:self.assertEqual(d.catalog(catalogs[current['year']][0])[source['code']]['signature'],source['signature'])
 def test_cost_plan_form_matches_both_runtimes_and_demands_review(self):
  script='require "vereinsverwaltung/src/bootstrap.php"; echo json_encode(\\Kulturhus\\Verein\\AssetTypes::accounts());'
  p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],'-r',script],cwd=ROOT.parent,capture_output=True,encoding='utf8',check=True);self.assertEqual(p.stderr,'')
  php=json.loads(p.stdout)
  for code in CODES:self.assertEqual(php[code],{k:ACCOUNTS[code][k] for k in ('kind','code','target')})
  form=(ROOT/'views/Finance/Accounting/finance-book-values.php').read_text();self.assertIn('value="construction"',form);self.assertIn('noch nicht betriebsbereit',form);self.assertIn('Finanzanlagen und langfristige Ausleihungen',form);self.assertIn('value="intangible_advance"',form)

if __name__=='__main__':unittest.main(verbosity=2)
