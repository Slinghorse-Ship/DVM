"""Original typical silent cost assets with distinct confirmed book/tax presentation."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('silent69_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
shared=load('ebilanz-cost-assets-test.py');combined=load('ebilanz-cost-combined-test.py');facts=load('ebilanz-operating-extended-test.py').facts
from ebilanz_builder import EbilanzBuilder
from ebilanz_financial_profile import BOOK_TARGETS_R69,profiles,target,validate_rule,book_presentation
from ebilanz_asset_valuation import from_book_plan
from ebilanz_mapping import automatic_mapping
import datev_years as d
@lru_cache(maxsize=128)
def fixture(profile,variant,mode):
 combined_mode=mode.startswith('combined');actual_mode=mode.removeprefix('combined-') if combined_mode else mode
 file='ebilanz-typical-silent-r69.php' if combined_mode else 'ebilanz-cost-assets.php'
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures'/file),'08300',variant,actual_mode,profile],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def cases():
 for profile in profiles('08300'):
  for mode in ('annual','whole','period','period-whole','combined-annual','combined-period'):
   for variant in ('unchanged','tax-only','multiple','writeup-release'):
    for original in fixture(profile,variant,mode):yield {'year':original['values']['year'],'code':'08300','financial_profile':profile,'mode':mode,'variant':variant,'row':original,'values':original['values']}
def inputs(row):return combined.inputs(row['row']) if row['mode'].startswith('combined') else shared.inputs(row['values'])
class TypicalSilentHolding(unittest.TestCase):
 def test_actual_original_cost_valuations_and_section_buckets(self):
  count=0
  for row in cases():
   before=copy.deepcopy(row['values']);m,p=inputs(row);package=EbilanzBuilder().build(row['values'],m,p,'12345');self.assertEqual(row['values'],before)
   if row['mode'].startswith('combined'):
    v=row['values'];self.assertEqual(facts(package,True)[BOOK_TARGETS_R69[row['financial_profile']]],format(v['whole']['book']['accounts']['fixed']['amount_cents']/100,'.2f'))
    self.assertEqual(facts(package)[target('08300',row['financial_profile'])],format(v['taxable']['tax']['accounts']['fixed']['amount_cents']/100,'.2f'))
   self.assertIn(row['year'],(2025,2026));count+=1
  self.assertEqual(count,60)
 def test_missing_and_wrong_contract_classification_rejected(self):
  from ebilanz_financial_profile import check
  for profile in BOOK_TARGETS_R69:
   plan={'kind':'financial','account':'08300','financial_profile':profile,'target':target('08300',profile)};check(plan)
   for field,value in [('financial_profile',None),('financial_profile','corp_domestic'),('target',BOOK_TARGETS_R69[profile]),('kind','land')]:
    wrong=dict(plan);wrong[field]=value
    with self.assertRaises(ValueError):check(wrong)
 def test_immutable_original_years_and_exact_rule(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for ed in editions:
   year=ed['year'];old,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r68.json');new,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r69.json');before=d.validate_snapshot(old,model,*catalogs[year]);after=d.validate_snapshot(new,model,*catalogs[year]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),{'08300'});rule=d.mapping(model,after['08300'],'6.9');validate_rule(rule)
   wrong=copy.deepcopy(rule);wrong['financial_book_targets']['silent_part']='bs.ass.fixAss.fin.particip.corporations'
   with self.assertRaises(ValueError):validate_rule(wrong)
 def test_typed_cost_book_form_displays_both_contract_choices(self):
  code="$root=$argv[1];require $root.'/src/bootstrap.php';function e($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}$financialAccount='08300';$financialProfile='silent_loan';require $root.'/views/Finance/Accounting/financial-investment-profile.php';"
  from tempfile import TemporaryDirectory
  with TemporaryDirectory() as folder:
   script=Path(folder)/'form.php';script.write_text('<?php '+code,encoding='utf8');p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(script),str(ROOT)],capture_output=True,encoding='utf8');self.assertEqual(p.returncode,0,p.stderr);self.assertFalse(p.stderr)
   for profile in BOOK_TARGETS_R69:self.assertIn('value="'+profile+'"',p.stdout)
   self.assertNotIn('value="corp_domestic"',p.stdout);self.assertIn('handelsrechtliche Ausleihung',p.stdout)
if __name__=='__main__':unittest.main()
