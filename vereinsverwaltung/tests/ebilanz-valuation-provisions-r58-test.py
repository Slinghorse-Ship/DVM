"""Original cash-fund valuation and actual commercial/tax provision reconciliation."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('valuation58_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
combined=load('ebilanz-combined-club-test.py');shared=load('ebilanz-operating-extended-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_commercial import CONTRACTS,validate_rule
import datev_years as d
CODES=('30920','30980','48430','68830')
TARGETS={'30920':['bs.eqLiab.accruals.other.imminentLoss.other'],'30980':['bs.eqLiab.accruals.other.expenditure'],'48430':['is.netIncome.regular.operatingTC.otherOpRevenue.currGains','is.netIncome.regular.operatingTC.otherOpRevenue.miscellaneous'],'68830':['is.netIncome.regular.operatingTC.otherCost.currLoss','is.netIncome.regular.operatingTC.otherCost.otherOrdinary']}
@lru_cache(maxsize=128)
def fixture(year,code,opening=False,dated=False,bad=''):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-valuation-provisions-r58.php'),str(year),code,'opening' if opening else 'normal',bad,'dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values'];mapping={'hb':TARGETS[row['code']][row['detail']],'hb-result':'is.netIncome.regular.operatingTC.otherCost.provisions','sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
 if row['report_kind']=='combined':
  _,profile=combined.inputs(v)
  from ebilanz_combined_club import assets_changed
  changed=assets_changed(v['taxable']);profile.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
  return {s:dict(mapping) for s in ('whole','taxable')},profile
 return mapping,shared.profile(v,True)
def cases():
 for code in CODES:
  for year in ((2025,) if code=='30980' else (2025,2026)):
   for opening in ((False,True) if code=='30920' else (True,) if code=='30980' else (False,)):
    for dated in (False,True):
     for bad in (('', 'reverse') if code in ('48430','68830') else ('',)):
      f=fixture(year,code,opening,dated,bad)
      for detail in range(len(TARGETS[code])):
       for kind in ('combined','whole'):
        yield {'year':year,'code':code,'opening':opening,'dated':dated,'detail':detail,'variant':bad or 'normal','report_kind':kind,'values':f['values' if kind=='combined' else 'whole_taxable']}
class ValuationProvisions(unittest.TestCase):
 def test_actual_book_tax_values_without_inferred_rates_or_deductions(self):
  n=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);pack=EbilanzBuilder().build(v,m,p,'12345');self.assertEqual(v,before);self.assertGreater(len(pack['xml']),1000);tax=v['taxable'] if row['report_kind']=='combined' else v
   if row['code'] in ('30920','30980'):
    self.assertEqual(tax['tax']['accounts']['hb']['amount_cents'],0);self.assertEqual(tax['tax']['accounts']['hb']['opening_cents'],0);self.assertTrue(tax['current_adjustments'])
    if row['code']=='30980':self.assertEqual(tax['book']['accounts']['hb']['credit_cents'],0);self.assertEqual(tax['book']['accounts']['hb']['amount_cents'],200)
   else:
    self.assertEqual(abs(tax['book']['accounts']['hb']['amount_cents']),1234);self.assertEqual(abs(tax['tax']['accounts']['hb']['amount_cents']),912);self.assertEqual(abs(tax['result_difference_cents']),322)
   n+=1
  self.assertEqual(n,84)
 def test_missing_partial_or_invented_legacy_tax_elimination_rejected(self):
  for code in ('30920','30980'):
   for dated in (False,True):
    for bad in ('missing-opening','missing-current','one-cent')+ (('new-addition',) if code=='30980' else ()):
     if code=='30980' and bad in ('missing-current','one-cent'):
      with self.assertRaisesRegex(RuntimeError,'Saldo auf der Gegenseite'):
       fixture(2025,code,True,dated,bad)
      continue
     f=fixture(2025,code,True,dated,bad)
     for kind in ('combined','whole'):
      row={'code':code,'detail':0,'report_kind':kind,'values':f['values' if kind=='combined' else 'whole_taxable']};m,p=inputs(row)
      with self.assertRaises(ValueError):EbilanzBuilder().build(row['values'],m,p,'12345')
 def test_original_years_exact_additions_preserve_previous57(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for e in editions:
   y=e['year'];before,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r57.json');after,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r58.json');a=d.validate_snapshot(before,model,*catalogs[y]);b=d.validate_snapshot(after,model,*catalogs[y]);self.assertEqual({c:b[c] for c in a},a);self.assertEqual(set(b)-set(a),set(CODES)-({'30980'} if y==2026 else set()))
   for code in set(b)-set(a):self.assertEqual(d.mapping(model,b[code],'6.9')['allowed_targets'],TARGETS[code])
 def test_original_commercial_target_and_legacy_contract_are_not_interchangeable(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json')
  for code in ('30920','30980'):
   rule=d.mapping(model,'valuation-provisions-r58-'+code+'@1','6.9');validate_rule(rule)
   for change in ({'book_only':False},{'commercial_contract':'invented@1'},{'allowed_targets':['bs.eqLiab.accruals.other.other']}):
    with self.assertRaises(ValueError):validate_rule(rule|change)
if __name__=='__main__':unittest.main(verbosity=2)
