"""Actual original special-post stock, release and independent tax reconciliation."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('special66_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
combined=load('ebilanz-combined-club-test.py');shared=load('ebilanz-operating-extended-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_technical_closing import SPECIAL,validate_rule
import datev_years as d
CODES=('29840','29860','29870')
TARGETS={c:SPECIAL[c][1] for c in CODES}
@lru_cache(maxsize=128)
def fixture(year,code,mode,dated=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-special-post-r66.php'),str(year),code,mode,'dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values'];m={'hb':TARGETS[row['code']],'hb-result':'is.netIncome.regular.operatingTC.otherOpRevenue.miscellaneous','sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
 if row['report_kind']=='combined':
  _,p=combined.inputs(v)
  from ebilanz_combined_club import assets_changed
  changed=assets_changed(v['taxable']);p.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
  return {s:dict(m) for s in ('whole','taxable')},p
 return m,shared.profile(v,True)
def cases():
 for code in CODES:
  for year in (2025,2026):
   for dated in (False,True):
    for mode in ('retain','release','full-release','reviewed'):
     f=fixture(year,code,mode,dated)
     for kind in ('combined','whole'):
      yield {'year':year,'code':code,'mode':mode,'dated':dated,'report_kind':kind,'values':f['values' if kind=='combined' else 'whole_taxable']}
 for code in CODES:yield {'year':2026,'code':code,'mode':'carry','dated':False,'report_kind':'whole','values':fixture(2026,code,'carry')}
class SpecialPost(unittest.TestCase):
 def test_actual_original_stock_release_tax_and_carry(self):
  n=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);pack=EbilanzBuilder().build(v,m,p,'12345');self.assertEqual(v,before);self.assertGreater(len(pack['xml']),1000)
   tax=v['taxable'] if row['report_kind']=='combined' else v
   for section in ('book','tax'):
    a=tax[section]['accounts']['hb'];self.assertEqual(a['group'],'deferred');self.assertGreaterEqual(a['amount_cents'],0)
    if row['code']!='29870':self.assertLessEqual(a['amount_cents'],-a['opening_cents'])
   if row['mode']=='reviewed':
    self.assertEqual(tax['book']['accounts']['hb']['amount_cents'],2034 if row['code']=='29870' else 800)
    self.assertEqual(tax['tax']['accounts']['hb']['amount_cents'],1592 if row['code']=='29870' else 680)
   if row['mode']=='carry':self.assertEqual(tax['book']['annual_result_cents'],0);self.assertEqual(tax['tax']['annual_result_cents'],0)
   n+=1
  self.assertEqual(n,99)
 def test_new_legacy_stock_and_over_release_rejected(self):
  for year in (2025,2026):
   for dated in (False,True):
    for code in CODES:
     for mode in (('over-release',) if code=='29870' else ('new-addition','over-release')):
      with self.assertRaisesRegex(RuntimeError,'Altbestand|Saldo auf der Gegenseite|nichtnegativen'):fixture(year,code,mode,dated)
 def test_exact_original_additions_and_immutable65(self):
  m,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(m)
  for e in editions:
   y=e['year'];old,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r65.json');new,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r66.json');a=d.validate_snapshot(old,m,*catalogs[y]);b=d.validate_snapshot(new,m,*catalogs[y]);self.assertEqual({c:b[c] for c in a},a);self.assertEqual(set(b)-set(a),set(CODES))
   for c in CODES:rule=d.mapping(m,b[c],'6.9');validate_rule(rule);self.assertEqual(rule['target'],TARGETS[c]);self.assertEqual(rule['group'],'deferred')
 def test_contract_target_or_code_substitution_rejected(self):
  m,_=d.read_json(ROOT/'resources/accounting/purposes.json')
  for c in CODES:
   rule=d.mapping(m,'special-post-r66-'+c+'@1','6.9')
   for change in ({'group':'reserves'},{'special_post_contract':'invented@1'},{'allowed_targets':['bs.eqLiab.otherSpecRes.other.misc']},{'book_only':True}):
    with self.assertRaises(ValueError):validate_rule(rule|change)
if __name__=='__main__':unittest.main(verbosity=2)
