"""Actual original vehicle/GWG plans, per-counter journals, and independent tax useful lives."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('dep60_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
combined=load('ebilanz-combined-club-test.py');shared=load('ebilanz-operating-extended-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_original_depreciation import code,validate_rule
from ebilanz_asset_types import TARGET_CHOICES
from ebilanz_asset_allocation import item_parts
from ebilanz_mapping import automatic_mapping
from ebilanz_period_asset_math import plan_check
import datev_years as d
@lru_cache(maxsize=80)
def fixture(year,asset,dated=False,tax_changed=False,bad=''):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-original-depreciation-r60.php'),str(year),asset,'dated' if dated else 'annual','tax' if tax_changed else 'book',bad],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values'];mapping={'sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
 raw=v['taxable'] if row['report_kind']=='combined' else v
 for key,a in raw['tax']['accounts'].items():
  if a['group']=='fixed_assets':mapping[key]=TARGET_CHOICES[a['skr_code']][0]
  elif a['skr_code']=='62220':mapping[key]='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'
  elif a['skr_code']=='62620':mapping[key]='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.lowValueAs'
 if row['report_kind']=='combined':
  _,p=combined.inputs(v)
  from ebilanz_combined_club import assets_changed
  changed=assets_changed(v['taxable']);p.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
  return {section:dict(mapping) for section in ('whole','taxable')},p
 p=shared.profile(v,True);p.update(asset_values_unchanged=not row['tax_changed'],asset_tax_values_reviewed=row['tax_changed'],no_retained_fixed_assets=False);return mapping,p
def cases():
 for year in (2025,2026):
  for asset in ('05200','05250','05400','05600','06700','mixed'):
   for dated in (False,True):
    for changed in (False,True):
     f=fixture(year,asset,dated,changed)
     for kind in ('combined','whole'):yield {'year':year,'code':asset,'dated':dated,'tax_changed':changed,'report_kind':kind,'values':f['values' if kind=='combined' else 'whole_taxable']}
class OriginalDepreciation(unittest.TestCase):
 def test_all_actual_original_book_tax_plans_and_mixed_counter_accounts(self):
  count=0
  for row in cases():
   with self.subTest(**{k:v for k,v in row.items() if k!='values'}):
    mapping,p=inputs(row);before=copy.deepcopy(row['values']);package=EbilanzBuilder().build(row['values'],mapping,p,'12345');self.assertGreater(package['asset_fact_count'],0);self.assertEqual(row['values'],before)
    if row['report_kind']=='whole':
     facts=shared.facts(package,True)
     for t,k in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(facts[t],shared.money(row['values']['tax'][k]))
    count+=1
  self.assertEqual(count,96)
 def test_original_wrong_kind_immediate_and_forged_counter_rejected(self):
  for year in (2025,2026):
   for bad in ('wrong-kind','immediate','forged'):
    with self.assertRaisesRegex(RuntimeError,'Abschreibungskonto|Sofortabschreibung'):fixture(year,'05200',bad=bad)
   with self.assertRaises((RuntimeError,ValueError)):
    f=fixture(year,'05200',bad='wrong-counter');row={'year':year,'code':'05200','dated':False,'tax_changed':False,'report_kind':'whole','values':f['whole_taxable']};m,p=inputs(row);EbilanzBuilder().build(row['values'],m,p,'12345')
 def test_whole_original_item_and_counter_cannot_be_reclassified(self):
  p={'basis_cents':120001,'residual_cents':0,'months':60,'acquired_on':'2025-02-01','sphere':'4','account':'05200','method':'linear_monthly','depreciation_code':'62220'}
  self.assertEqual(code(p),'62220');plan_check(p)
  for key,value in [('account','06300'),('depreciation_code','62200'),('method','gwg_immediate')]:
   wrong=copy.deepcopy(p);wrong[key]=value
   with self.assertRaises(ValueError):plan_check(wrong)
  for year in (2025,2026):
   f=fixture(year,'mixed');v=f['whole_taxable']
   for identifier,item in v['asset_schedule']['items'].items():self.assertEqual(item_parts(item,{'first':2,'second':3},year),f['shared'][identifier])
   self.assertNotIn('dep-62220',automatic_mapping(v));self.assertNotIn('dep-62620',automatic_mapping(v));self.assertIn('dep-62200',automatic_mapping(v))
 def test_original_snapshots_and_bounded_contracts_preserve_previous59(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for e in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r59.json");new,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r60.json");a=d.validate_snapshot(old,model,*catalogs[e['year']]);b=d.validate_snapshot(new,model,*catalogs[e['year']]);self.assertEqual(set(b)-set(a),{'62220','62620'});self.assertEqual({k:b[k] for k in a},a)
  for c in ('62220','62620'):
   original=model['purposes']['original-depreciation-r60-'+c]['versions']['1']['mappings']['6.9'];validate_rule(original)
   for k,value in [('original_depreciation_contract','invented@1'),('group','fixed_assets'),('condition','equipment_schedule'),('allowed_targets',['bs.ass.currAss.cashEquiv.bank'])]:
    wrong=copy.deepcopy(original);wrong[k]=value
    with self.assertRaises(ValueError):validate_rule(wrong)
if __name__=='__main__':unittest.main(verbosity=2)
