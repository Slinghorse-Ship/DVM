"""Actual original realised partnership profit/loss: commercial and fiscal taxonomy sections."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('partnership68_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
combined=load('ebilanz-combined-club-test.py');shared=load('ebilanz-operating-extended-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_commercial import PARTNERSHIP_R68,validate_rule,partnership_mapping
import datev_years as d
@lru_cache(maxsize=128)
def fixture(year,code,mode='active',dated=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-partnership-income-r68.php'),str(year),code,mode,'dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values'];target=PARTNERSHIP_R68['partnership-income-r68-'+row['code']+'@1'][2]
 mapping={'hb':target,'sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
 if row['profile']=='combined':
  _,p=combined.inputs(v)
  from ebilanz_combined_club import assets_changed
  changed=assets_changed(v['taxable']);p.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
  return {side:dict(mapping) for side in ('whole','taxable')},p
 return mapping,shared.profile(v,True)
def cases():
 for year in (2025,2026):
  for code in ('70030','70040','70080','72080'):
   for dated in (False,True):
    for mode in ('active','cancelled','inactive'):
     f=fixture(year,code,mode,dated)
     for profile in ('combined','whole-taxable'):yield {'year':year,'code':code,'mode':mode,'dated':dated,'profile':profile,'values':f['values' if profile=='combined' else 'whole_taxable']}
 for code in ('70030','70040','70080','72080'):yield {'year':2026,'code':code,'mode':'carry','dated':False,'profile':'whole-taxable','values':fixture(2026,code,'carry')}
class PartnershipIncome(unittest.TestCase):
 def test_actual_original_signed_income_and_taxonomy_section(self):
  n=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);package=EbilanzBuilder().build(v,m,p,'12345');self.assertEqual(v,before)
   side=v['taxable'] if row['profile']=='combined' else v
   self.assertEqual(side['book']['accounts']['hb']['amount_cents'],side['tax']['accounts']['hb']['amount_cents'])
   if row['mode']=='active':
    tax=PARTNERSHIP_R68['partnership-income-r68-'+row['code']+'@1'][2];self.assertEqual(shared.facts(package,row['profile']=='whole-taxable')[tax],'12.34')
    if row['profile']=='combined':
     book=PARTNERSHIP_R68['partnership-income-r68-'+row['code']+'@1'][3];self.assertEqual(shared.facts(package,True)[book],'-12.34' if row['code']=='72080' else '12.34')
     if row['code']=='70040':self.assertEqual(shared.facts(package,True)['is.netIncome.regular.fin.netParticipation.earnings.groupComp'],'12.34')
   if row['mode']=='carry':self.assertEqual(side['tax']['annual_result_cents'],0);self.assertTrue(side['capital_comparison']['complete'])
   n+=1
  self.assertEqual(n,100)
 def test_missing_review_and_wrong_original_sign_rejected(self):
  for year in (2025,2026):
   for code in ('70030','70040','70080','72080'):
    for profile in ('combined','whole-taxable'):
     f=fixture(year,code);row={'code':code,'profile':profile,'values':f['values' if profile=='combined' else 'whole_taxable']};m,p=inputs(row)
     if profile=='combined':del m['whole']['hb'];del m['taxable']['hb']
     else:del m['hb']
     with self.assertRaisesRegex(ValueError,'ausdrücklich bestätigen'):EbilanzBuilder().build(row['values'],m,p,'12345')
     f=fixture(year,code,'reversed');row['values']=f['values' if profile=='combined' else 'whole_taxable'];m,p=inputs(row)
     with self.assertRaisesRegex(ValueError,'Gegenbetrag'):EbilanzBuilder().build(row['values'],m,p,'12345')
 def test_unbound_nonzero_original_participation_is_not_discarded(self):
  f=fixture(2025,'70080');v=copy.deepcopy(f['whole_taxable']);v['tax']['accounts']['bank']['skr_code']='08600';v['tax']['accounts']['bank']['group']='fixed_assets';v['book']['accounts']['bank']['skr_code']='08600';v['book']['accounts']['bank']['group']='fixed_assets'
  before=copy.deepcopy(v)
  # Section resolver cannot invent, remove or reclassify a remaining participation.
  m,_=partnership_mapping(v,{'hb':PARTNERSHIP_R68['partnership-income-r68-70080@1'][2]});self.assertNotIn('bank',m);self.assertEqual(v,before)
 def test_immutable_previous_purposes_and_exact_contract(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');old,_=d.read_json(ROOT/'resources/accounting/years/2025-r67.json')
  for ref in d.bindings(old).values():self.assertEqual(d.digest(d.definition(model,ref)),old['definitions'][ref])
  for ref in PARTNERSHIP_R68:
   rule=d.mapping(model,ref,'6.9');validate_rule(rule)
   for key,value in [('partnership_book_target','is.netIncome.regular.fin.netParticipation.earnings.corporations'),('target','is.netIncome.regular.fin.netParticipation.earnings.silentPart'),('book_only',True),('partnership_book_disclosures',['is.netIncome.regular.fin.netParticipation.earnings.groupComp','is.netIncome.regular.fin.commPart'])]:
    wrong=copy.deepcopy(rule);wrong[key]=value
    with self.assertRaises(ValueError):validate_rule(wrong)
if __name__=='__main__':unittest.main()
