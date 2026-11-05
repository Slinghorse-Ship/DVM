"""Original shareholder and related-person loans, independently replayed cost ledgers."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('shareholder57_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
shared=load('ebilanz-cost-assets-test.py');combined=load('ebilanz-cost-combined-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_financial_profile import profiles,target,counter_code,counter_key,counter_target,SHAREHOLDER_FORMS
import datev_years as d
CODES=('09600','09610','09700')
FORMS={'09600':'AG','09610':'gGMBH','09700':'EV'}
@lru_cache(maxsize=256)
def fixture(code,profile,variant,mode):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-cost-assets.php'),code,variant,mode,profile],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 m,p=shared.inputs(row['values']);p['company']['legal_status']=FORMS[row['code']];return m,p
def cases():
 for code in CODES:
  for profile in profiles(code):
   for mode in ('annual','whole','period','period-whole'):
    for variant,year in (('tax-only',2025),('writeup-release',2026)):
     for row in fixture(code,profile,variant,mode):
      if row['values']['year']==year:yield {'code':code,'financial_profile':profile,'mode':mode,'variant':variant,'year':year,'values':row['values']}
def combined_cases():
 for code in CODES:
  for profile in profiles(code):
   p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-cost-combined.php'),code,'temporary','annual',profile],capture_output=True,encoding='utf8',timeout=60)
   if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
   for row in json.loads(p.stdout):yield {'code':code,'financial_profile':profile,'mode':'combined-HGBM','variant':'temporary','year':row['values']['year'],'values':row['values'],'row':row}
def combined_inputs(row):
 m,p=combined.inputs(row['row']);p['company']['legal_status']=FORMS[row['code']];return m,p
class ShareholderLoans(unittest.TestCase):
 def test_original_nominal_cost_source_counter_and_carry_all_scopes(self):
  count=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);pack=EbilanzBuilder().build(v,m,p,'12345');self.assertEqual(v,before);self.assertEqual(m['fixed'],target(row['code'],row['financial_profile']));self.assertIn('de-gaap-ci:'+m['fixed'],pack['xml']);count+=1
   item=v['asset_schedule']['items']['synthetic-cost'];plan=item.get('cost_plan',item.get('plan'));self.assertEqual(plan['account'],row['code']);self.assertEqual(plan['financial_profile'],row['financial_profile'])
   for event in item['valuation']['source']['events']:
    code=counter_code(event['kind'],event.get('permanent',True),row['financial_profile']);key=counter_key(code,row['financial_profile'])
    if key in m:self.assertEqual(m[key],counter_target(code,row['financial_profile']));self.assertEqual(v['tax']['accounts'][key]['skr_code'],code)
  self.assertEqual(count,192)
 def test_actual_HGB_temporary_impairments_keep_book_tax_and_original72010(self):
  count=0
  for row in combined_cases():
   m,p=combined_inputs(row);v=row['values'];before=copy.deepcopy(v);EbilanzBuilder().build(v,m,p,'12345');self.assertEqual(v,before);key=counter_key('72010',row['financial_profile']);self.assertEqual(v['whole']['tax']['accounts'][key]['amount_cents'],4000);self.assertEqual(v['taxable']['tax']['accounts'][key]['amount_cents'],0);self.assertEqual(v['whole']['asset_schedule']['items']['synthetic-cost']['valuation']['book']['book_closing_cents'],8000);self.assertEqual(v['taxable']['asset_tax']['tax_schedule']['items']['synthetic-cost']['valuation']['tax']['book_closing_cents'],12000);self.assertEqual(v['whole']['tax']['accounts'][key]['skr_code'],'72010');count+=1
  self.assertEqual(count,24)
 def test_shareholder_account_requires_confirmed_reporter_legal_form(self):
  for code in CODES[:2]:
   row=next(r for r in cases() if r['code']==code);m,p=inputs(row)
   for form in ('','EV','ST','Other.KOER')+(() if code=='09600' else ('AG','EG')):
    q=copy.deepcopy(p);q['company']['legal_status']=form
    with self.assertRaises(ValueError):EbilanzBuilder().build(row['values'],m,q,'12345')
   for form in SHAREHOLDER_FORMS[code]:
    q=copy.deepcopy(p);q['company']['legal_status']=form;EbilanzBuilder().build(row['values'],m,q,'12345')
 def test_exact_three_original_accounts_added_and_previous_versions_immutable(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for e in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r56.json");before=d.validate_snapshot(old,model,*catalogs[e['year']]);current,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r57.json");after=d.validate_snapshot(current,model,*catalogs[e['year']]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),set(CODES))
   for code in CODES:
    rule=d.mapping(model,after[code],'6.9');self.assertEqual(rule['financial_profile_contract'],code+'@1');self.assertEqual(rule['allowed_targets'],[target(code,profiles(code)[0])])
if __name__=='__main__':unittest.main(verbosity=2)
