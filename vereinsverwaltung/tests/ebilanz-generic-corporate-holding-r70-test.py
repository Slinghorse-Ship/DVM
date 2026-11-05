"""Generic original holdings: actual confirmed corporate cost valuations only."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('corp70_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
shared=load('ebilanz-cost-assets-test.py');combined=load('ebilanz-cost-combined-test.py');facts=load('ebilanz-operating-extended-test.py').facts
from ebilanz_builder import EbilanzBuilder
from ebilanz_financial_profile import profiles,target,validate_rule,check,disclosures
import datev_years as d
CODES=('08000','08090','08200');PARENT='bs.ass.fixAss.fin.sharesInAffil.parentComp'
@lru_cache(maxsize=256)
def fixture(code,profile,variant,mode):
 combined_mode=mode.startswith('combined');actual_mode=mode.removeprefix('combined-') if combined_mode else mode
 file='ebilanz-generic-corporate-r70.php' if combined_mode else 'ebilanz-cost-assets.php'
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures'/file),code,variant,actual_mode,profile],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def cases():
 for code in CODES:
  for profile in profiles(code):
   for mode in ('annual','whole','period','period-whole','combined-annual','combined-period'):
    for variant in ('unchanged','tax-only','multiple','writeup-release'):
     for original in fixture(code,profile,variant,mode):yield {'year':original['values']['year'],'code':code,'financial_profile':profile,'mode':mode,'variant':variant,'row':original,'values':original['values']}
def inputs(row):return combined.inputs(row['row']) if row['mode'].startswith('combined') else shared.inputs(row['values'])
class GenericCorporateHolding(unittest.TestCase):
 def test_original_values_parent_disclosure_and_independent_tax_valuation(self):
  count=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);package=EbilanzBuilder().build(v,m,p,'12345');self.assertEqual(v,before)
   if row['mode'].startswith('combined'):
    for book,original in [(True,v['whole']['book']),(False,v['taxable']['tax'])]:
     f=facts(package,book);amount=format(original['accounts']['fixed']['amount_cents']/100,'.2f');self.assertEqual(f[target(row['code'],row['financial_profile'])],amount)
     if row['code']=='08090':self.assertEqual(f[PARENT],amount)
     else:self.assertNotIn(PARENT,f)
   count+=1
  self.assertEqual(count,180)
 def test_no_partnership_or_foreign_establishment_inferred(self):
  for code in CODES:
   self.assertEqual(set(profiles(code)),{'corp_domestic','corp_foreign'})
   for profile in profiles(code):
    plan={'kind':'financial','account':code,'financial_profile':profile,'target':target(code,profile)};check(plan);self.assertNotIn('OtherForeign',plan['target'])
    for field,value in [('financial_profile',None),('financial_profile','loan_pg'),('financial_profile','pg_comm'),('financial_profile','silent_part'),('target','bs.ass.fixAss.fin.particip.partnerships.commPart.other'),('kind','land')]:
     wrong=dict(plan);wrong[field]=value
     with self.assertRaises(ValueError):check(wrong)
 def test_immutable_prior_editions_and_exact_parent_contract(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for ed in editions:
   year=ed['year'];old,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r69.json');new,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r70.json');before=d.validate_snapshot(old,model,*catalogs[year]);after=d.validate_snapshot(new,model,*catalogs[year]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),set(CODES))
   for code in CODES:
    rule=d.mapping(model,after[code],'6.9');validate_rule(rule);wrong=copy.deepcopy(rule);wrong['financial_profile_contract']=code+'@1'
    with self.assertRaises(ValueError):validate_rule(wrong)
    wrong=copy.deepcopy(rule);wrong['financial_parent_disclosures']=[] if code=='08090' else [PARENT]
    with self.assertRaises(ValueError):validate_rule(wrong)
 def test_old_and_new_parent_holdings_add_nonadditive_original_amounts(self):
  first=fixture('08090','corp_domestic','tax-only','whole')[0]['values'];old=fixture('08080','corp_foreign','tax-only','whole')[0]['values']
  joined=copy.deepcopy(first)
  for new_schedule,old_schedule in [(joined['asset_schedule'],old['asset_schedule']),(joined['asset_tax']['tax_schedule'],old['asset_tax']['tax_schedule'])]:
   new_schedule['items']['second-parent']=copy.deepcopy(next(iter(old_schedule['items'].values())))
  for book in (False,True):self.assertEqual(disclosures(joined,book)[PARENT],disclosures(first,book)[PARENT]+disclosures(old,book)[PARENT])
if __name__=='__main__':unittest.main()
