"""Original signed inventory advances and material offsets from actual journal constructors."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('offset59_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
combined=load('ebilanz-combined-club-test.py');shared=load('ebilanz-operating-extended-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_original_offsets import INVENTORY,MATERIAL,validate_rule
import datev_years as d
@lru_cache(maxsize=64)
def fixture(year,code,opening=False,dated=False,bad=''):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-original-offsets-r59.php'),str(year),code,'opening' if opening else 'normal',bad,'dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values'];advance=row['code']=='11900';target=INVENTORY if advance else MATERIAL[row['detail']];mapping={'hb':target,'base':'bs.ass.currAss.inventory.material' if advance else target,'stock':'bs.ass.currAss.inventory.material','sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
 if row['report_kind']=='combined':
  _,profile=combined.inputs(v)
  from ebilanz_combined_club import assets_changed
  changed=assets_changed(v['taxable']);profile.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
  return {s:dict(mapping) for s in ('whole','taxable')},profile
 return mapping,shared.profile(v,True)
def cases():
 for code in ('11900','58600'):
  for year in (2025,2026):
   for opening in ((False,True) if code=='11900' else (False,)):
    for dated in (False,True):
     f=fixture(year,code,opening,dated)
     for detail in range(1 if code=='11900' else len(MATERIAL)):
      for kind in ('combined','whole'):yield {'year':year,'code':code,'opening':opening,'dated':dated,'detail':detail,'report_kind':kind,'values':f['values' if kind=='combined' else 'whole_taxable']}
class OriginalOffsets(unittest.TestCase):
 def test_actual_signed_book_and_tax_values_all_original_positions(self):
  n=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);mapping,profile=inputs(row);package=EbilanzBuilder().build(v,mapping,profile,'12345');self.assertEqual(v,before);tax=v['taxable'] if row['report_kind']=='combined' else v;self.assertLess(tax['tax']['accounts']['hb']['amount_cents'],0);self.assertNotEqual(tax['book']['accounts']['hb']['amount_cents'],tax['tax']['accounts']['hb']['amount_cents']);self.assertIn('de-gaap-ci:'+(INVENTORY if row['code']=='11900' else MATERIAL[row['detail']]),package['xml']);n+=1
  self.assertEqual(n,120)
 def test_actual_uncovered_or_wrong_signed_offsets_rejected_in_source_constructor(self):
  for code in ('11900','58600'):
   for dated in (False,True):
    for bad in ('positive','overdraw')+(('uncovered-sphere',) if code=='11900' else ()):
     message=('Bereiche, Anlagen und Verrechnungen' if dated else 'Vollständige unveränderte Betriebsaufteilung') if bad=='uncovered-sphere' else 'Anzahlungen|Stoffkosten'
     with self.assertRaisesRegex(RuntimeError,message):fixture(2025,code,False,dated,bad)
 def test_material_offset_cannot_reduce_an_unrelated_selected_position(self):
  for kind in ('combined','whole'):
   f=fixture(2025,'58600');row={'code':'58600','detail':0,'report_kind':kind,'values':f['values' if kind=='combined' else 'whole_taxable']};mapping,profile=inputs(row)
   if kind=='combined':
    for m in mapping.values():m['hb']=MATERIAL[1]
   else:mapping['hb']=MATERIAL[1]
   with self.assertRaisesRegex(ValueError,'Stoffkosten-Gegenkonto'):EbilanzBuilder().build(row['values'],mapping,profile,'12345')
 def test_original_two_accounts_added_and_previous58_immutable(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for edition in editions:
   year=edition['year'];old,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r58.json');new,_=d.read_json(ROOT/f'resources/accounting/years/{year}-r59.json');before=d.validate_snapshot(old,model,*catalogs[year]);after=d.validate_snapshot(new,model,*catalogs[year]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),{'11900','58600'})
  for code in ('11900','58600'):
   rule=d.mapping(model,'original-offsets-r59-'+code+'@1','6.9');validate_rule(rule)
   for replacement in ({'original_offset_contract':'invented@1'},{'group':'revenue'},{'condition':'balance_direction'},{'allowed_targets':['bs.ass.currAss.receiv.other.other']}):
    with self.assertRaises(ValueError):validate_rule(rule|replacement)
if __name__=='__main__':unittest.main(verbosity=2)
