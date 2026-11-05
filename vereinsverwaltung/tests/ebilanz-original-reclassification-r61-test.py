"""Original split counters from actual journal/source constructors, including tax differences."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('reclass61_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
combined=load('ebilanz-combined-club-test.py');shared=load('ebilanz-operating-extended-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
from ebilanz_original_reclassification import CONTRACTS,validate_rule
import datev_years as d
@lru_cache(maxsize=96)
def fixture(year,code,opening=False,dated=False,bad=''):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-original-reclassification-r61.php'),str(year),code,'opening' if opening else 'normal',bad,'dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
@lru_cache(maxsize=2)
def metadata(year):
 model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model);edition=next(e for e in editions if e['year']==year);return model,d.bindings(edition)
def one_mapping(v,row):
 target=CONTRACTS[row['code']][2][row['detail']];mapping=automatic_mapping(v)
 model,bindings=metadata(v['year'])
 for key,a in v['tax']['accounts'].items():
  if key in ('hb','base'):mapping[key]=target
  elif key=='split':mapping[key]=d.mapping(model,bindings[a['skr_code']],'6.9')['target']
  elif key=='sales':mapping[key]='is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'
 return mapping
def inputs(row):
 v=row['values']
 if row['report_kind']=='combined':
  _,profile=combined.inputs(v)
  from ebilanz_combined_club import assets_changed
  changed=assets_changed(v['taxable']);profile.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
  return {s:one_mapping(v['whole' if s=='whole' else 'taxable'],row) for s in ('whole','taxable')},profile
 return one_mapping(v,row),shared.profile(v,True)
def cases():
 for code,spec in CONTRACTS.items():
  for year in (2025,2026):
   for opening in (False,True):
    for dated in (False,True):
     f=fixture(year,code,opening,dated)
     for detail in range(len(spec[2])):
      for kind in ('combined','whole'):yield {'year':year,'code':code,'opening':opening,'dated':dated,'detail':detail,'report_kind':kind,'values':f['values' if kind=='combined' else 'whole_taxable']}
class OriginalReclassification(unittest.TestCase):
 def test_all_actual_original_split_counters_preserve_whole_and_taxable_values(self):
  n=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);mapping,profile=inputs(row);package=EbilanzBuilder().build(v,mapping,profile,'12345');self.assertEqual(v,before);tax=v['taxable'] if row['report_kind']=='combined' else v
   self.assertLess(tax['tax']['accounts']['hb']['amount_cents'],0);self.assertNotEqual(tax['book']['accounts']['hb']['amount_cents'],tax['tax']['accounts']['hb']['amount_cents']);self.assertIn('de-gaap-ci:'+CONTRACTS[row['code']][2][row['detail']],package['xml']);n+=1
  self.assertEqual(n,176)
 def test_actual_uncovered_opposite_and_uncovered_area_counters_rejected(self):
  for code in CONTRACTS:
   for dated in (False,True):
    for bad in ('positive','overdraw','tax-overdraw','uncovered-sphere'):
     with self.subTest(code=code,dated=dated,bad=bad),self.assertRaisesRegex(RuntimeError,'Aufteilungsgegenkonto|Bereiche, Anlagen und Verrechnungen|Vollständige unveränderte Betriebsaufteilung'):
      fixture(2025,code,True,dated,bad)
 def test_selected_counter_cannot_reduce_another_primary_position(self):
  for code in ('12580','12590','33490'):
   for kind in ('combined','whole'):
    f=fixture(2025,code);row={'year':2025,'code':code,'detail':0,'report_kind':kind,'values':f['values' if kind=='combined' else 'whole_taxable']};mapping,profile=inputs(row)
    for m in (mapping.values() if kind=='combined' else [mapping]):m['hb']=CONTRACTS[code][2][1]
    with self.assertRaisesRegex(ValueError,'Aufteilungsgegenkonto'):EbilanzBuilder().build(row['values'],mapping,profile,'12345')
 def test_five_original_accounts_additive_and_contracts_exact(self):
  identity=(ROOT/'src/Finance/Tax/TaxFilings.php').read_text()
  for n in ('Finance/Balance/BalanceOriginalReclassification.php','../tools/ebilanz_original_reclassification.py','Finance/Balance/BalanceOriginalOffsets.php','../tools/ebilanz_original_offsets.py','Finance/Assets/AssetOriginalDepreciation.php','../tools/ebilanz_original_depreciation.py'):self.assertIn("'"+n+"'",identity)
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for edition in editions:
   year=edition['year'];old,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r60.json');new,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r61.json');before=d.validate_snapshot(old,model,*catalogs[year]);after=d.validate_snapshot(new,model,*catalogs[year]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),set(CONTRACTS))
  for code in CONTRACTS:
   rule=d.mapping(model,'original-reclassification-r61-'+code+'@1','6.9');validate_rule(rule)
   for replacement in ({'original_reclassification_contract':'invented@1'},{'group':'revenue'},{'condition':'balance_direction'},{'allowed_targets':['bs.ass.currAss.receiv.other.other']}):
    with self.assertRaises(ValueError):validate_rule(rule|replacement)
if __name__=='__main__':unittest.main(verbosity=2)
