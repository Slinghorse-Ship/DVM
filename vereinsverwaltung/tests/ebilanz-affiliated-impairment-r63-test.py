"""Original 72070 events in the real retained acquisition, valuation and tax source chain."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('affiliated63_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
shared=load('ebilanz-cost-assets-test.py');combined=load('ebilanz-cost-combined-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_financial_profile import profiles,counter_key,counter_target,counter_code
from ebilanz_asset_valuation import calculate
import datev_years as d
CODES=('08040','08080','08100','08140')
@lru_cache(maxsize=512)
def fixture(code,profile,variant,mode,original='72070'):
 file='ebilanz-cost-combined.php' if mode.startswith('combined') else 'ebilanz-cost-assets.php'
 source_mode='period' if mode=='combined-period' else 'annual' if mode=='combined' else mode
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures'/file),code,variant,source_mode,profile,original]+(['shared'] if mode=='combined-period' else []),capture_output=True,encoding='utf8',timeout=120)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 if row['mode'].startswith('combined'):return combined.inputs(row['row'])
 return shared.inputs(row['values'])
def cases():
 for code in CODES:
  for profile in profiles(code):
   for mode in ('whole','period-whole','combined','combined-period'):
    for variant in ('tax-only','temporary','writeup'):
     for raw in fixture(code,profile,variant,mode):yield {'year':raw['values']['year'],'code':code,'financial_profile':profile,'mode':mode,'variant':variant,'values':raw['values'],'row':raw}
class AffiliatedImpairment(unittest.TestCase):
 def test_all_actual_source_profiles_and_tax_book_carry_reconstruct_before_XML(self):
  count=0
  for row in cases():
   values=row['values'];before=copy.deepcopy(values);mapping,profile=inputs(row);package=EbilanzBuilder().build(values,mapping,profile,'12345');self.assertEqual(values,before);self.assertGreater(package['asset_fact_count'],0)
   sections=[values['whole'],values['taxable']] if 'whole' in values else [values]
   key=counter_key('72070',row['financial_profile'])
   for section in sections:
    item=section['asset_schedule']['items']['synthetic-cost'];valuation=item['valuation'];source=valuation['source'];self.assertEqual(calculate(source['plan'],source['period'],source['events'],source['previous']),valuation)
    if row['year']==2025:
     self.assertEqual(source['events'][0]['counter_code'],'72070');self.assertEqual(section['book']['accounts'][key]['skr_code'],'72070');self.assertEqual(section['book']['accounts'][key]['amount_cents'],4000)
     if not section.get('whole_club_scope',{}).get('book_only') and section.get('determination')!='handelsrechtlich':self.assertIn(key,section['tax']['accounts'])
     if row['mode']=='combined':self.assertEqual(mapping['whole'][key],counter_target('72070',row['financial_profile']));self.assertEqual(mapping['taxable'][key],counter_target('72070',row['financial_profile']))
    else:self.assertEqual(source['previous']['source']['events'][0]['counter_code'],'72070')
   count+=1
  self.assertEqual(count,224)
 def test_actual_unrelated_financial_assets_and_wrong_event_counters_are_rejected(self):
  for code,profile in (('08500','corp_domestic'),('09800','corp_foreign'),('09000','corp_domestic'),('08850','loan_de_gt25'),('09600','loan_pg'),('09700','loan_sole')):
   with self.subTest(code=code),self.assertRaises(RuntimeError):fixture(code,profile,'tax-only','whole')
  for original in ('72040','72000','invented'):
   with self.subTest(original=original),self.assertRaises(RuntimeError):fixture('08040','corp_domestic','tax-only','whole',original)
  for event in ('writeup','disposal'):
   with self.assertRaises(ValueError):counter_code(event,True,'corp_domestic','72070','08040')
 def test_original_event_and_tax_election_cannot_be_changed(self):
  row=next(cases());section=row['values'];item=section['asset_schedule']['items']['synthetic-cost'];s=item['valuation']['source']
  for field,value in (('counter_code','72040'),('counter_code',72070),('tax_election',False)):
   events=copy.deepcopy(s['events']);events[0][field]=value
   with self.assertRaises(ValueError):calculate(s['plan'],s['period'],events,s['previous'])
  for account in ('08500','08850'):
   plan=copy.deepcopy(s['plan']);plan['account']=account
   from ebilanz_financial_profile import target
   with self.assertRaises(ValueError):
    plan['target']=target(account,plan['financial_profile']);calculate(plan,s['period'],s['events'],s['previous'])
 def test_original_account_additive_and_previous_rules_immutable(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for edition in editions:
   year=edition['year'];old,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r62.json');new,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r63.json');before=d.validate_snapshot(old,model,*catalogs[year]);after=d.validate_snapshot(new,model,*catalogs[year]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),{'72070'})
   rule=d.mapping(model,after['72070'],'6.9');self.assertEqual(rule['condition'],'detail_choice');self.assertEqual(set(rule['allowed_targets']),{counter_target('72070',p) for c in CODES for p in profiles(c)})
if __name__=='__main__':unittest.main(verbosity=2)
