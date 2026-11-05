"""Original HGB deferred taxes plus actual reviewed tax reversal, annual and dated."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('hb_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
combined=load('ebilanz-combined-club-test.py');shared=load('ebilanz-operating-extended-test.py')
from ebilanz_commercial import CONTRACTS as ALL_CONTRACTS,validate_rule
CONTRACTS={k:v for k,v in ALL_CONTRACTS.items() if k.startswith("deferred-")}
from ebilanz_builder import EbilanzBuilder,NS
import xml.etree.ElementTree as ET

def fixture(year,kind,opening=False,dated=False,bad=''):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-commercial-r40.php'),str(year),kind,'opening' if opening else 'normal',bad,'dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values'];sales={'sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
 def mapping(section):
  return sales|{k:next(t for c,g,t in CONTRACTS.values() if c==a['skr_code']) for k,a in section['tax']['accounts'].items() if a['skr_code'] in {c for c,g,t in CONTRACTS.values()}}
 if row['profile']=='combined':
  _,p=combined.inputs(v)
  from ebilanz_combined_club import assets_changed
  changed=assets_changed(v['taxable']);p.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
  return {side:mapping(v[side]) for side in ('whole','taxable')},p
 p=shared.profile(v,True);return mapping(v),p

def cases():
 for year in (2025,2026):
  for kind in ('active','provision','passive'):
   for opening in (False,True):
    for dated in (False,True):
     f=fixture(year,kind,opening,dated)
     for profile in ('combined','whole-taxable'):
      yield {k:f[k] for k in ('year','kind','opening','dated')}|{'profile':profile,'values':f['values' if profile=='combined' else 'whole_taxable']}
class CommercialDeferredTaxes(unittest.TestCase):
 def test_original_books_retained_and_tax_values_actually_eliminated(self):
  b=EbilanzBuilder()
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);package=b.build(v,m,p,'12345')
   self.assertEqual(v,before)
   if row['profile']=='combined':
    self.assertEqual(v['whole']['book'],v['whole']['tax']);self.assertEqual(v['taxable']['tax']['accounts']['hb']['amount_cents'],0)
    self.assertGreater(v['whole']['book']['accounts']['hb']['amount_cents'],0);self.assertNotEqual(v['whole']['book']['annual_result_cents'],v['taxable']['tax']['annual_result_cents'])
    r=ET.fromstring(package['xml']);self.assertEqual(r.find('.//de-gcd:genInfo.report.id.accountingStandard.accountingStandard.HGBM',NS).tag,'{'+NS['de-gcd']+'}genInfo.report.id.accountingStandard.accountingStandard.HGBM')
   else:
    self.assertEqual(v['tax']['accounts']['hb']['opening_cents'],0);self.assertEqual(v['tax']['accounts']['hb']['closing_cents'],0)
    f=shared.facts(package,True);self.assertEqual(f['bs.ass'],shared.money(v['tax']['asset_total_cents']));self.assertEqual(f['is.netIncome'],shared.money(v['tax']['annual_result_cents']))
 def test_unreviewed_partial_or_wrong_tax_elimination_rejected(self):
  for year in (2025,2026):
   for kind in ('active','provision','passive'):
    for dated in (False,True):
     for bad in ('missing-current','one-cent','missing-opening'):
      f=fixture(year,kind,True,dated,bad)
      for profile in ('combined','whole-taxable'):
       row={k:f[k] for k in ('year','kind','opening','dated')}|{'profile':profile,'values':f['values' if profile=='combined' else 'whole_taxable']};m,p=inputs(row)
       with self.assertRaisesRegex(ValueError,'Latente Steuern'):EbilanzBuilder().build(row['values'],m,p,'12345')
 def test_no_hgb_permission_in_tax_or_arbitrary_exclusions(self):
  b=EbilanzBuilder()
  for code,group,target in CONTRACTS.values():
   self.assertFalse(b.permitted(target,2025));self.assertTrue(b.permitted(target,2025,book_only=True))
  with self.assertRaises(ValueError):validate_rule({'book_only':True})
  with self.assertRaises(ValueError):validate_rule({'commercial_contract':'invented@1','book_only':True})
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json')
  for entry in model['purposes'].values():
   for definition in entry['versions'].values():
    rule=definition['mappings'].get('6.9',{})
    if 'commercial_contract' not in rule:continue
    for change in ({'book_only':False},{'group':'fixed_assets'},{'allowed_targets':['bs.ass.currAss.cashEquiv.bank']}):
     with self.assertRaises(ValueError):validate_rule(rule|change)
 def test_original_source_history_additive(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r39.json");new,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r40.json")
   before=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);after=shared.d.validate_snapshot(new,model,*catalogs[e['year']]);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(set(after)-set(before),{'19500','30600','30650','76450','76490'})
if __name__=='__main__':unittest.main(verbosity=2)
