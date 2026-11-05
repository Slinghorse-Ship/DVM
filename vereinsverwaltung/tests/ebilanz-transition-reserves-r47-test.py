"""Original signed historical reserves preserve pool, tax review and noncash capital source."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
s=importlib.util.spec_from_file_location('reserves_r47_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
from ebilanz_reserve_closing import CODES,OTHER,validate_rule
from ebilanz_builder import EbilanzBuilder
REVIEW=json.loads((ROOT/'resources/accounting/reviews/transition-reserves-r47.json').read_text());BUILDER=EbilanzBuilder()
@lru_cache(maxsize=300)
def fixture(year,index,mode='normal',whole=False,dated=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-transition-reserves-r47.php'),str(year),str(index),mode,'whole' if whole else 'single','dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):return {k:OTHER for k,a in row['values']['tax']['accounts'].items() if a['skr_code'] in CODES},shared.profile(row['values'],row['whole'])
def cases(dated=False):
 for e in REVIEW['years']:
  for whole in (False,True):
   for i,g in enumerate(e['groups']):
    for mode in ('normal','opening','cancelled'):
     yield {'year':e['year'],'whole':whole,'mode':mode,'index':i,'target':OTHER,'dated':dated,'values':fixture(e['year'],i,mode,whole,dated)}
class OriginalReserves(unittest.TestCase):
 def test_actual_historical_opening_correction_and_cancellation(self):
  count=0
  for dated in (False,True):
   for row in cases(dated):
    v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);package=BUILDER.build(v,m,p,'12345');f=shared.facts(package,row['whole']);pool=sum(a['amount_cents'] for a in v['tax']['accounts'].values() if a['group']=='reserves');self.assertEqual(f[OTHER],shared.money(pool));self.assertEqual(v['tax']['annual_result_cents'],0);self.assertEqual(f['is.netIncome'],'0.00');self.assertEqual(v,before);count+=1
    if row['index']==0:self.assertEqual(f[OTHER+'.AktG58_2a'],shared.money(v['tax']['accounts']['code-29620']['amount_cents']))
  self.assertEqual(count,192)
  for year in (2025,2026):
   for whole in (False,True):
    for dated in (False,True):
     row={'values':fixture(year,-1,'normal',whole,dated),'whole':whole};m,p=inputs(row);self.assertEqual(BUILDER.build(row['values'],m,p,'12345')['asset_items'],0)
 def test_no_inferred_purpose_or_negative_reserve_pool(self):
  for year in (2025,2026):
   for whole in (False,True):
    for dated in (False,True):
     for index in (6,7):
      row={'values':fixture(year,index,'overpool',whole,dated),'whole':whole};m,p=inputs(row)
      with self.assertRaisesRegex(ValueError,'Rücklagen'):BUILDER.build(row['values'],m,p,'12345')
     row={'values':fixture(year,6,'normal',whole,dated),'whole':whole};m,p=inputs(row)
     with self.assertRaisesRegex(ValueError,'Gewinnrücklagen'):BUILDER.build(row['values'],{},p,'12345')
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json')
  for e in REVIEW['years']:
   for g in e['groups']:
    rule=shared.d.mapping(model,g['purpose'],'6.9')
    for bad in ({'reserve_pool':'invented'},{'target':'bs.eqLiab.equity.revenueRes.legal'},{'group':'equity'},{'condition':'invented'}):
     with self.assertRaises(ValueError):validate_rule(rule|bad)
 def test_all_original_prior_bindings_remain_immutable(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r46.json");after=shared.d.validate_snapshot(e,model,*catalogs[e['year']]);before=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),CODES)
   for g in next(s for s in REVIEW['years'] if s['year']==e['year'])['groups']:
    for source in g['sources']:self.assertEqual(shared.d.catalog(catalogs[e['year']][0])[source['code']]['signature'],source['signature'])
if __name__=='__main__':unittest.main(verbosity=2)
