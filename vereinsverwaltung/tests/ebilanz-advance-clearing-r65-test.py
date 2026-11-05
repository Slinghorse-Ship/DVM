"""Open original clearing balances preserve sign, source and economic balance side."""
import copy,importlib.util,json,os,subprocess,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('advance_r65_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
shared.REVIEW='advance-clearing-r65';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/advance-clearing-r65.json').read_text())['years']
from ebilanz_sales import fact_amount
from ebilanz_directional_closing import choices as direction_choices,presentation,validate_rule
BUILDER=shared.EbilanzBuilder()
def build(values,mapping=None,whole=False):return BUILDER.build(values,mapping or {},shared.profile(values,whole),'12345')
@lru_cache(maxsize=500)
def fixture(year,index,mode='debit',whole=False,dated=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-advance-clearing-r65.php'),str(year),str(index),mode,'whole' if whole else 'single','dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def selections(v,groups,target=None):
 out={}
 for g in groups:
  for source in g['sources']:
   key='code-'+source['code'];a=v['tax']['accounts'][key]
   if any(a[f] for f in ('opening_cents','debit_cents','credit_cents')):out[key]=target or (direction_choices(v['year'],a) or g['choices'])[0]
 for k,a in v['tax']['accounts'].items():
  if a['skr_code'] in ('12000','33000'):out[k]='bs.ass.currAss.receiv.trade.other' if a['skr_code']=='12000' else 'bs.eqLiab.liab.trade.genOther'
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
def composite_fixture(year,index,mode,dated=False,legacy=''):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-advance-composite-r65.php'),str(year),str(index),mode,'dated' if dated else 'annual',legacy],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def composite_inputs(v,mapping):
 spec=importlib.util.spec_from_file_location('r65_composite_profile',ROOT/'tests/ebilanz-combined-club-test.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);_,profile=m.inputs(v)
 from ebilanz_combined_club import assets_changed
 changed=assets_changed(v['taxable']);profile.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
 return mapping,profile
def composite_cases():
 sales={'sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
 for e in shared.SPECS:
  for i,g in enumerate(e['groups']):
   key='code-'+g['sources'][0]['code']
   for dated in (False,True):
    for mode in ('debit','credit','opening-debit','opening-credit','tax-flip-debit','tax-flip-credit'):
     v=composite_fixture(e['year'],i,mode,dated)
     for target in direction_choices(e['year'],v['whole']['tax']['accounts'][key]):
      mapping={side:selections(v[side],[g],target if side=='whole' else None)|sales for side in ('whole','taxable')}
      yield {'year':e['year'],'whole':True,'mode':mode,'index':i,'target':target,'dated':dated,'composite':True,'values':v,'mapping':mapping}
def legacy_composite_cases():
 sales={'sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
 for code in ('13700','36300'):
  for year in (2025,2026):
   for dated in (False,True):
    for mode in ('debit','credit'):
     v=composite_fixture(year,0,mode,dated,code);key='code-14970';mapping={side:selections(v[side],[shared.SPECS[0]['groups'][0]])|sales for side in ('whole','taxable')}
     yield {'year':year,'whole':True,'mode':mode,'index':0,'target':mapping['whole'][key],'dated':dated,'composite':True,'legacy_code':code,'values':v,'mapping':mapping}
def all_cases():
 for dated in (False,True):
  for row in cases(dated):yield row|{'composite':False,'legacy_code':''}
 for row in carry_cases():yield row|{'composite':False,'legacy_code':''}
 for row in composite_cases():yield row|{'legacy_code':''}
 yield from legacy_composite_cases()
def inputs(row):
 return composite_inputs(row['values'],row['mapping']) if row['composite'] else (row['mapping'],shared.profile(row['values'],row['whole']))
class AdvanceClearingR65(unittest.TestCase):
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
      with self.assertRaisesRegex(ValueError,'falsche Bilanzseite'):build(v,selections(v,[g])|{key:wrong})
 def test_actual_debtor_vendor_lines_retained(self):
  for e in shared.SPECS:
   for i,g in enumerate(e['groups']):
    code=g['sources'][0]['code']
    for mode in ('debit','credit','cancelled'):
     v=fixture(e['year'],i,mode,True);source=v['whole_club_scope']['source'];peer='peer-'+code
     self.assertEqual(source['accounts'][peer]['skr_code'],'12000' if code=='14970' else '33000');self.assertEqual(v['book']['accounts'][peer]['closing_cents'],0)
     self.assertTrue(any(l['account']==peer for entry in source['entries'] for l in entry['lines']));self.assertEqual(abs(v['book']['accounts']['code-'+code]['closing_cents']),0 if mode=='cancelled' else 1234)
 def test_actual_review_choices_and_visible_signed_values(self):
  p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/balance-advance-clearing-r65-view.php')],capture_output=True,encoding='utf8',timeout=90)
  self.assertEqual(p.returncode,0,p.stdout+p.stderr);self.assertEqual(p.stderr,'');proof=json.loads(p.stdout);self.assertTrue(proof['passed']);self.assertGreaterEqual(proof['checks'],250);self.assertFalse(proof['database_connected'])
 def test_original_composite_book_tax_sides_and_four_areas(self):
  count=0
  for row in composite_cases():
   v=row['values'];before=copy.deepcopy(v);mapping,profile=inputs(row);package=BUILDER.build(v,mapping,profile,'12345');book=shared.facts(package,True);tax=shared.facts(package,False)
   for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:
    self.assertEqual(book[target],shared.money(v['whole']['book'][key]));self.assertEqual(tax[target],shared.money(v['taxable']['tax'][key]))
   code=shared.SPECS[0]['groups'][row['index']]['sources'][0]['code'];key='code-'+code;target=row['target'];self.assertEqual(book[target],shared.money(abs(v['whole']['book']['accounts'][key]['closing_cents'])))
   wrong=(shared.SPECS[0]['groups'][row['index']]['directional_balance']['credit_targets'] if v['whole']['book']['accounts'][key]['closing_cents']>0 else shared.SPECS[0]['groups'][row['index']]['directional_balance']['debit_targets'])[0]
   with self.assertRaises(ValueError):BUILDER.build(v,mapping|{'whole':mapping['whole']|{key:wrong}},profile,'12345')
   self.assertEqual(v,before);count+=1
  self.assertEqual(count,60)
 def test_previously_supported_clearing_sides_in_original_composite_areas(self):
  count=0
  for row in legacy_composite_cases():
   v=row['values'];before=copy.deepcopy(v);mapping,profile=inputs(row);package=BUILDER.build(v,mapping,profile,'12345');book=shared.facts(package,True);tax=shared.facts(package,False)
   for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:
    self.assertEqual(book[target],shared.money(v['whole']['book'][key]));self.assertEqual(tax[target],shared.money(v['taxable']['tax'][key]))
   for side in ('whole','taxable'):self.assertEqual(v[side]['book']['accounts']['code-14970']['skr_code'],row['legacy_code'])
   self.assertEqual(v,before);count+=1
  self.assertEqual(count,16)
 def test_exact_contracts_sources_and_immutable_history(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   a,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r64.json");b,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r65.json");before=shared.d.validate_snapshot(a,model,*catalogs[e['year']]);after=shared.d.validate_snapshot(b,model,*catalogs[e['year']]);self.assertEqual(len(after)-len(before),2);self.assertEqual({k:after[k] for k in before},before)
  shared.OperatingExtended('test_sources_are_exact_and_all_choices_are_official_in_both_profiles').test_sources_are_exact_and_all_choices_are_official_in_both_profiles()
  rule=copy.deepcopy(model['purposes']['advance-clearing-r65-14970']['versions']['1']['mappings']['6.9']);rule['directional_balance']['credit_targets']=['bs.eqLiab.liab.other.employees']
  with self.assertRaises(ValueError):validate_rule(rule)
if __name__=='__main__':unittest.main(verbosity=2)
