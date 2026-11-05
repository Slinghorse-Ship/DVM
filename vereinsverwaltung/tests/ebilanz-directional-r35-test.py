"""Open original clearing balances preserve sign, source and economic balance side."""
import copy,importlib.util,json,os,subprocess,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('direction_r35_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
shared.REVIEW='directional-r35';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/directional-r35.json').read_text())['years']
from ebilanz_sales import fact_amount
from ebilanz_directional_closing import choices as direction_choices,presentation,validate_rule
BUILDER=shared.EbilanzBuilder()
def build(values,mapping=None,whole=False):return BUILDER.build(values,mapping or {},shared.profile(values,whole),'12345')
@lru_cache(maxsize=500)
def fixture(year,index,mode='debit',whole=False,dated=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-directional-r35.php'),str(year),str(index),mode,'whole' if whole else 'single','dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def selections(v,groups,target=None):
 out={}
 for g in groups:
  for source in g['sources']:
   key='code-'+source['code'];a=v['tax']['accounts'][key]
   if any(a[f] for f in ('opening_cents','debit_cents','credit_cents')):out[key]=target or (direction_choices(v['year'],a) or g['choices'])[0]
 return out
def cases(dated=False):
 for e in shared.SPECS:
  for whole in (False,True):
   for i,g in enumerate(e['groups']):
    for mode in ('debit','credit') if g['directional_balance'] else ('debit',):
     v=fixture(e['year'],i,mode,whole,dated);a=v['tax']['accounts']['code-'+g['sources'][0]['code']]
     for target in direction_choices(e['year'],a) or g['choices']:yield {'year':e['year'],'whole':whole,'mode':mode,'index':i,'target':target,'dated':dated,'values':v,'mapping':selections(v,[g],target)}
    for mode in ('opening-debit','opening-credit','tax-flip-debit','tax-flip-credit','cancelled','inactive') if g['directional_balance'] else ('opening-debit','cancelled','inactive'):
     v=fixture(e['year'],i,mode,whole,dated);m=selections(v,[g]);yield {'year':e['year'],'whole':whole,'mode':mode,'index':i,'target':m.get('code-'+g['sources'][0]['code'],g['target']),'dated':dated,'values':v,'mapping':m}
   for mode in ('debit','opening-debit','cancelled','inactive'):
    v=fixture(e['year'],-1,mode,whole,dated);yield {'year':e['year'],'whole':whole,'mode':mode,'index':-1,'target':'reviewed-mixed','dated':dated,'values':v,'mapping':selections(v,e['groups'])}
def carry_cases():
 e=next(e for e in shared.SPECS if e['year']==2026)
 for i,g in enumerate(e['groups']):
  for mode in ('debit','credit','tax-flip-debit','tax-flip-credit') if g['directional_balance'] else ('debit',):
   v=fixture(2026,i,'carry-'+mode,True);m=selections(v,[g])
   yield {'year':2026,'whole':True,'mode':'carry-'+mode,'index':i,'target':m['code-'+g['sources'][0]['code']],'dated':False,'values':v,'mapping':m}
class DirectionalR35(unittest.TestCase):
 def check_original_xml_account_balances(self,values,selected,package):
  tuples=shared.ET.fromstring(package['xml']).findall('.//de-gaap-ci:detailedInformation.accountBalances',shared.NS)
  full=shared.automatic_mapping(values)|selected
  for key,raw in values['tax']['accounts'].items():
   if key not in full:continue
   rows=[n for n in tuples if n.find('de-gaap-ci:detailedInformation.accountbalances.accountNumber',shared.NS).text==raw['skr_code']]
   self.assertEqual(len(rows),1,(key,raw['skr_code']))
   displayed=presentation(values['year'],raw) or raw
   expected=fact_amount(full[key],displayed['amount_cents'],displayed['group'])
   self.assertEqual(rows[0].find('de-gaap-ci:detailedInformation.accountbalances.amount',shared.NS).text,shared.money(expected))
 def test_all_original_sides_tax_differences_and_xml_totals(self):
  for dated in (False,True):
   for row in cases(dated):
    v=row['values'];before=copy.deepcopy(v);package=build(v,row['mapping'],row['whole']);f=shared.facts(package,row['whole']);self.check_original_xml_account_balances(v,row['mapping'],package)
    for t,k in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(f[t],shared.money(v['tax'][k]))
    if row['index']!=-1:
     g=next(e for e in shared.SPECS if e['year']==row['year'])['groups'][row['index']];key='code-'+g['sources'][0]['code'];a=v['tax']['accounts'][key];view=presentation(v['year'],a)
     if key in row['mapping']:
      target=row['mapping'][key];full=shared.automatic_mapping(v)|row['mapping']
      expected=sum(abs(source['closing_cents']) if presentation(v['year'],source) else source['amount_cents'] for source_key,source in v['tax']['accounts'].items() if full.get(source_key)==target)
      self.assertEqual(f[target],shared.money(expected))
     self.assertEqual(a['closing_cents'],a['opening_cents']+a['debit_cents']-a['credit_cents'])
    self.assertEqual(v,before)
 def test_original_signed_book_tax_carry_without_repeated_profit(self):
  for row in carry_cases():
   v=row['values'];package=build(v,row['mapping'],True);f=shared.facts(package,True);self.check_original_xml_account_balances(v,row['mapping'],package)
   self.assertEqual(v['tax']['annual_result_cents'],0);self.assertEqual(v['book']['annual_result_cents'],0)
   self.assertEqual(f['bs.ass'],shared.money(v['tax']['asset_total_cents']));self.assertEqual(f['bs.eqLiab'],shared.money(v['tax']['passive_total_cents']))
 def test_explicit_actual_side_and_known_claim_type_required(self):
  for e in shared.SPECS:
   for i,g in enumerate(e['groups']):
    for mode in ('debit','credit') if g['directional_balance'] else ('debit',):
     v=fixture(e['year'],i,mode);key='code-'+g['sources'][0]['code'];self.assertNotIn(key,shared.automatic_mapping(v))
     with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):build(v)
     if g['directional_balance']:
      wrong=g['directional_balance']['credit_targets' if mode=='debit' else 'debit_targets'][0]
      with self.assertRaisesRegex(ValueError,'falsche Bilanzseite'):build(v,{key:wrong})
 def test_ordinary_negative_financial_assets_are_still_rejected(self):
  for e in shared.SPECS:
   i=next(i for i,g in enumerate(e['groups']) if not g['directional_balance'])
   with self.assertRaisesRegex(RuntimeError,'Gegenseite'):fixture(e['year'],i,'credit')
  self.assertIsNone(presentation(2025,{'skr_code':'18000','group':'cash','closing_cents':-100}))
 def test_actual_review_choices_and_visible_signed_values(self):
  p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/balance-directional-r35-view.php')],capture_output=True,encoding='utf8',timeout=90)
  self.assertEqual(p.returncode,0,p.stdout+p.stderr);self.assertEqual(p.stderr,'');proof=json.loads(p.stdout);self.assertTrue(proof['passed']);self.assertGreaterEqual(proof['checks'],1088);self.assertFalse(proof['database_connected'])
 def test_exact_contracts_sources_and_immutable_history(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   a,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r34.json");b,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r35.json");before=shared.d.validate_snapshot(a,model,*catalogs[e['year']]);after=shared.d.validate_snapshot(b,model,*catalogs[e['year']]);self.assertEqual(len(after)-len(before),9);self.assertEqual({k:after[k] for k in before},before)
  shared.OperatingExtended('test_sources_are_exact_and_all_choices_are_official_in_both_profiles').test_sources_are_exact_and_all_choices_are_official_in_both_profiles()
  rule=copy.deepcopy(model['purposes']['directional-r35-001']['versions']['1']['mappings']['6.9']);rule['directional_balance']['credit_targets']=['bs.eqLiab.liab.other.employees']
  with self.assertRaises(ValueError):validate_rule(rule)
if __name__=='__main__':unittest.main(verbosity=2)
