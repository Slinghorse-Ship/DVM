import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'));import datev_years as d
from ebilanz_builder import EbilanzBuilder,NS
from ebilanz_own_shares import CODE,TARGET,DETAIL,NOMINAL,validate_rule
s=importlib.util.spec_from_file_location('own56_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
@lru_cache(maxsize=64)
def fixture(year,variant,dated=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-own-shares-r56.php'),str(year),variant,'dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values'];p=shared.profile(v,True);p['company']['legal_status']=row['legal_status'];p['company']['name']='Synthetic own-share corporation';p['tax_privileged']=False
 return {'capital':'bs.eqLiab.equity.subscribed.corp','ordinary-reserve':TARGET,'own-nominal':NOMINAL,'own-cost':TARGET},p
def cases():
 for year in (2025,2026):
  for dated in (False,True):
   for variant in (('purchase','opening','purchase-below-par','opening-below-par') if year==2025 else ('purchase','opening','release','purchase-below-par','opening-below-par','release-below-par')):
    for form in ('GMBH','AG','gGMBH','UG'):yield fixture(year,variant,dated)|{'code':CODE,'legal_status':form,'mode':'dated' if dated else 'annual'}
class OwnShares(unittest.TestCase):
 def test_original_capital_and_positive_disclosure_without_duplicate_reserve(self):
  count=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);pack=EbilanzBuilder().build(v,m,p,'12345');f=shared.facts(pack,True);cost=v['tax']['accounts']['own-cost']['closing_cents'];self.assertEqual(f[TARGET],shared.money(20000-cost));self.assertEqual(f[DETAIL],shared.money(max(cost,0)));self.assertEqual(v['tax']['annual_result_cents'],0);self.assertEqual(v,before);self.assertIn('genInfo.company.id.legalStatus.legalStatus.'+('GMBH' if row['legal_status']=='gGMBH' else row['legal_status']),pack['xml']);count+=1
  self.assertEqual(count,80)
 def test_wrong_form_sign_pool_and_missing_nominal_rejected(self):
  for variant in ('overpool','no-nominal','wrong-sign'):
   row=fixture(2025,variant)|{'legal_status':'GMBH'};m,p=inputs(row)
   with self.assertRaises(ValueError):EbilanzBuilder().build(row['values'],m,p,'12345')
  row=fixture(2025,'purchase')|{'legal_status':'GMBH'};m,p=inputs(row)
  for form in ('EV','ST','Other.KOER','UNKNOWN','',None,False,0,[]):
   q=copy.deepcopy(p);q['company']['legal_status']=form
   with self.assertRaises(ValueError):EbilanzBuilder().build(row['values'],m,q,'12345')
  for mutate in ({'target':NOMINAL},{'own_share_cost_contract':'fake'},{'own_share_disclosures':[]},{'reserve_pool':'other@1'}):
   model,_=d.read_json(ROOT/'resources/accounting/purposes.json');rule=d.mapping(model,'own-shares-r56-001@1','6.9')
   with self.assertRaises(ValueError):validate_rule(rule|mutate)
 def test_immutable_previous55_bindings_and_original_signatures(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for e in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r55.json");before=d.validate_snapshot(old,model,*catalogs[e['year']]);current,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r56.json");after=d.validate_snapshot(current,model,*catalogs[e['year']]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),{CODE});self.assertTrue(d.catalog(catalogs[e['year']][0])[CODE]['bookable'])
if __name__=='__main__':unittest.main(verbosity=2)
