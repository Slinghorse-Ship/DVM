"""Actually completed technical source accounts with real retained final asset schedules."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('technical62_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
assets=load('ebilanz-original-depreciation-r60-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_technical_closing import CONTRACTS,validate_rule,check_rows
import datev_years as d
@lru_cache(maxsize=48)
def fixture(year,code,dated=False,tax_changed=False,bad=''):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-technical-closing-r62.php'),str(year),code,'dated' if dated else 'annual','tax' if tax_changed else 'book',bad],capture_output=True,encoding='utf8',timeout=90)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 mapping,profile=assets.inputs(row)
 for m in (mapping.values() if row['report_kind']=='combined' else [mapping]):m['technical']=CONTRACTS[row['code']][1]
 return mapping,profile
def cases():
 for code in CONTRACTS:
  for year in (2025,2026):
   for dated in (False,True):
    for changed in (False,True):
     f=fixture(year,code,dated,changed)
     for kind in ('combined','whole'):yield {'year':year,'code':code,'dated':dated,'tax_changed':changed,'report_kind':kind,'values':f['values' if kind=='combined' else 'whole_taxable']}
class TechnicalClosing(unittest.TestCase):
 def test_actual_closed_original_accounts_and_true_asset_values_preserved(self):
  n=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);mapping,profile=inputs(row);package=EbilanzBuilder().build(v,mapping,profile,'12345');self.assertEqual(v,before);self.assertGreater(package['asset_fact_count'],0);bases=[v['whole'],v['taxable']] if row['report_kind']=='combined' else [v]
   for base in bases:
    for section in ('book','tax'):
     a=base[section]['accounts']['technical'];self.assertEqual(a['opening_cents'],0);self.assertEqual(a['closing_cents'],0);self.assertEqual(a['amount_cents'],0);self.assertEqual(a['debit_cents'],4000);self.assertEqual(a['credit_cents'],4000)
   n+=1
  self.assertEqual(n,32)
 def test_original_uncleared_opening_closing_tax_area_and_cutoff_rejected(self):
  for code in CONTRACTS:
   for dated in (False,True):
    for bad in ('opening','closing','tax-nonzero','unit-mismatch')+(('unfinished',) if dated else ()):
     with self.subTest(code=code,dated=dated,bad=bad),self.assertRaises(RuntimeError):fixture(2025,code,dated,False,bad)
 def test_python_cannot_rewrite_the_original_nonzero_technical_balance(self):
  for code in CONTRACTS:
   a=fixture(2025,code)['whole_taxable']['tax']['accounts']['technical'];check_rows(2025,{'technical':a})
   for f in ('opening_cents','closing_cents','amount_cents'):
    b=copy.deepcopy(a);b[f]=1
    with self.assertRaises(ValueError):check_rows(2025,{'technical':b})
 def test_two_original_accounts_additive_and_complete_runtime_identity(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for edition in editions:
   year=edition['year'];old,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r61.json');new,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r62.json');before=d.validate_snapshot(old,model,*catalogs[year]);after=d.validate_snapshot(new,model,*catalogs[year]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),set(CONTRACTS))
  identity=(ROOT/'src/Finance/Tax/TaxFilings.php').read_text()
  for name in ('Finance/Balance/BalanceTechnicalClosing.php','../tools/ebilanz_technical_closing.py'):self.assertIn("'"+name+"'",identity)
  for code in CONTRACTS:
   rule=d.mapping(model,'technical-closing-r62-'+code+'@1','6.9');validate_rule(rule)
   for replacement in ({'technical_closing_contract':'invented@1'},{'condition':'balance_direction'},{'allowed_targets':['bs.ass.currAss.cashEquiv.bank']}):
    with self.assertRaises(ValueError):validate_rule(rule|replacement)
if __name__=='__main__':unittest.main(verbosity=2)
