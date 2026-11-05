"""Original signed commercial atypical income and actual fiscal capital reclassification."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('atypical67_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
combined=load('ebilanz-combined-club-test.py');shared=load('ebilanz-operating-extended-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_commercial import INCOME_R67,INCOME_TARGETS_R67,validate_rule
import datev_years as d
@lru_cache(maxsize=128)
def fixture(year,mode,dated=False,bad=''):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-atypical-income-r67.php'),str(year),mode,'dated' if dated else 'annual',bad],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values']
 def mapping(section):
  n=section['book']['accounts']['hb']['amount_cents'];return {'hb':INCOME_TARGETS_R67[n<0],'sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
 if row['profile']=='combined':
  _,p=combined.inputs(v)
  from ebilanz_combined_club import assets_changed
  changed=assets_changed(v['taxable']);p.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
  return {side:mapping(v[side]) for side in ('whole','taxable')},p
 return mapping(v),shared.profile(v,True)
def cases():
 for year in (2025,2026):
  for dated in (False,True):
   for mode in ('debit','credit','cancelled','inactive'):
    f=fixture(year,mode,dated)
    for profile in ('combined','whole-taxable'):yield {'year':year,'mode':mode,'dated':dated,'profile':profile,'values':f['values' if profile=='combined' else 'whole_taxable']}
 for mode in ('debit','credit'):yield {'year':2026,'mode':'carry-'+mode,'dated':False,'profile':'whole-taxable','values':fixture(2026,'carry-'+mode)}
class AtypicalIncome(unittest.TestCase):
 def test_actual_signed_original_books_and_independent_tax_reclassification(self):
  n=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);pack=EbilanzBuilder().build(v,m,p,'12345');self.assertEqual(v,before)
   tax=v['taxable'] if row['profile']=='combined' else v
   self.assertEqual(tax['tax']['accounts']['hb']['amount_cents'],0)
   if row['profile']=='combined' and row['mode'] in ('debit','credit'):
    facts=shared.facts(pack,True);target=m['whole']['hb'];self.assertEqual(facts[target],'12.34');self.assertEqual(v['whole']['book']['accounts']['hb']['amount_cents'],1234 if row['mode']=='debit' else -1234)
   if row['mode'].startswith('carry-'):self.assertEqual(tax['tax']['annual_result_cents'],0);self.assertEqual(tax['book']['annual_result_cents'],0);self.assertTrue(tax['capital_comparison']['complete'])
   n+=1
  self.assertEqual(n,34)
 def test_missing_or_partial_actual_fiscal_reclassification_rejected(self):
  for year in (2025,2026):
   for dated in (False,True):
    for mode in ('debit','credit'):
     for bad in ('missing-current','one-cent'):
      f=fixture(year,mode,dated,bad)
      for profile in ('combined','whole-taxable'):
       row={'profile':profile,'values':f['values' if profile=='combined' else 'whole_taxable']};m,p=inputs(row)
       with self.assertRaisesRegex(ValueError,'Handelsbilanzposition'):EbilanzBuilder().build(row['values'],m,p,'12345')
 def test_wrong_original_side_and_unconfirmed_capital_movements_rejected(self):
  for mode in ('debit','credit'):
   f=fixture(2025,mode)
   for profile in ('combined','whole-taxable'):
    row={'profile':profile,'values':f['values' if profile=='combined' else 'whole_taxable']};m,p=inputs(row)
    if profile=='combined':m['whole']['hb']=INCOME_TARGETS_R67[mode=='debit'];m['taxable']['hb']=m['whole']['hb']
    else:m['hb']=INCOME_TARGETS_R67[mode=='debit']
    with self.assertRaisesRegex(ValueError,'Originalsaldovorzeichen'):EbilanzBuilder().build(row['values'],m,p,'12345')
   f=fixture(2025,mode,False,'missing-movement');row={'profile':'whole-taxable','values':f['whole_taxable']};m,p=inputs(row)
   with self.assertRaises(ValueError):EbilanzBuilder().build(row['values'],m,p,'12345')
 def test_exact_original_addition_and_contract(self):
  m,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(m)
  for e in editions:
   y=e['year'];old,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r66.json');new,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r67.json');before=d.validate_snapshot(old,m,*catalogs[y]);after=d.validate_snapshot(new,m,*catalogs[y]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),{'73980'});rule=d.mapping(m,after['73980'],'6.9');validate_rule(rule)
   for change in ({'book_only':False},{'commercial_contract':'invented@1'},{'commercial_income_direction':{'debit_target':INCOME_TARGETS_R67[1],'credit_target':INCOME_TARGETS_R67[0]}}):
    with self.assertRaises(ValueError):validate_rule(rule|change)
if __name__=='__main__':unittest.main(verbosity=2)
