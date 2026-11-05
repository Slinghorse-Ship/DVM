"""Retained original corporate investments, source country and gross cost values."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('financial50_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
shared=load('ebilanz-cost-assets-test.py');fact_helpers=load('ebilanz-operating-extended-test.py')
import datev_years as d
from ebilanz_asset_types import COST,LINEAR,MOVABLE
from ebilanz_asset_valuation import from_book_plan
from ebilanz_financial_profile import PROFILES,target,counter_key,counter_target,disclosures
from ebilanz_mapping import automatic_mapping
from ebilanz_builder import EbilanzBuilder
REVIEW=json.loads((ROOT/'resources/accounting/reviews/corporate-investments-r50.json').read_text())
CODES={source['code'] for g in REVIEW['years'][0]['groups'] for source in g['sources']}
@lru_cache(maxsize=512)
def fixture(code,profile,variant,mode):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-cost-assets.php'),code,variant,mode,profile],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def cases():
 for code in sorted(CODES):
  for profile in sorted(PROFILES):
   for mode in ('annual','whole','period','period-whole'):
    for variant in ('multiple','tax-only','writeup','writeup-release'):
     for row in fixture(code,profile,variant,mode):yield {'code':code,'financial_profile':profile,'mode':mode,'variant':variant,'year':row['values']['year'],'values':row['values']}
def inputs(row):return shared.inputs(row['values'])
class CorporateInvestments(unittest.TestCase):
 def test_original_book_and_tax_cost_columns_all_scopes(self):
  count=0;builder=EbilanzBuilder()
  for code in sorted(CODES):
   for profile in sorted(PROFILES):
    for mode in ('annual','whole','period','period-whole','mixed','mixed-whole'):
     for variant in ('unchanged','impairment','tax-only','multiple','writeup','writeup-release','fully'):
      if mode.startswith('mixed') and variant=='fully':continue
      for row in fixture(code,profile,variant,mode):
       with self.subTest(code=code,profile=profile,mode=mode,variant=variant,year=row['values']['year']):
        v=row['values'];before=copy.deepcopy(v);mapping,details=shared.inputs(v);package=builder.build(v,mapping,details,'12345');f=fact_helpers.facts(package,'whole' in mode)
        self.assertEqual(automatic_mapping(v),row['automatic']);self.assertEqual(mapping['fixed'],target(code,profile));self.assertEqual(package['asset_items'],2 if mode.startswith('mixed') else 1);self.assertEqual(v,before)
        for counter in ('49140','68980','72040'):
         key=counter_key(counter,profile)
         if key in mapping:self.assertEqual(mapping[key],counter_target(counter,profile));self.assertEqual(v['tax']['accounts'][key]['skr_code'],counter)
         elif key in v['tax']['accounts']:self.assertEqual(v['tax']['accounts'][key]['amount_cents'],0)
        original=v['asset_schedule']['items']['synthetic-cost'];plan=original.get('cost_plan',original.get('plan'));self.assertEqual(plan['financial_profile'],profile);self.assertEqual(original['valuation']['source']['plan']['financial_profile'],profile);self.assertEqual(plan['valuation_document'],original['valuation']['source']['plan']['document'])
        parent='bs.ass.fixAss.fin.sharesInAffil.parentComp'
        if code=='08080':self.assertEqual(f[parent],fact_helpers.money(original['valuation']['tax']['book_closing_cents']));self.assertEqual(disclosures(v,True)[parent],original['valuation']['book']['book_closing_cents'])
        else:self.assertNotIn(parent,disclosures(v))
        count+=1
  self.assertEqual(count,416)
 def test_unproved_country_source_and_wrong_counterpart_rejected(self):
  for code in sorted(CODES):
   self.assertIn(code,COST);self.assertNotIn(code,LINEAR|MOVABLE)
   v=fixture(code,'corp_domestic','multiple','whole')[0]['values'];m,p=shared.inputs(v);plan=v['asset_schedule']['items']['synthetic-cost']['cost_plan']
   for value in (None,'','unknown',1,[],{}):
    changed=copy.deepcopy(plan);changed['financial_profile']=value
    with self.subTest(code=code,value=value),self.assertRaises(ValueError):from_book_plan(changed)
   changed=copy.deepcopy(plan);del changed['financial_profile']
   with self.assertRaises(ValueError):from_book_plan(changed)
   changed=copy.deepcopy(plan);changed['target']='bs.ass.fixAss.fin.sharesInAffilOtherForeign'
   with self.assertRaises(ValueError):from_book_plan(changed)
   self.assertEqual(automatic_mapping({'year':2025,'tax':{'accounts':{'fixed':{'skr_code':code,'group':'fixed_assets','amount_cents':0}}},'asset_schedule':{'year':2025,'items':{},'issues':[]}}),{})
   for mutate in ('changed_profile','changed_document','changed_counter'):
    bad=copy.deepcopy(v);mapping=dict(m)
    if mutate=='changed_profile':bad['asset_schedule']['items']['synthetic-cost']['cost_plan']['financial_profile']='corp_foreign'
    elif mutate=='changed_document':bad['asset_schedule']['items']['synthetic-cost']['cost_plan']['valuation_document']['sha256']='0'*64
    else:mapping[counter_key('72040','corp_domestic')]=counter_target('72040','corp_foreign')
    with self.subTest(code=code,mutate=mutate),self.assertRaises(ValueError):EbilanzBuilder().build(bad,mapping,p,'12345')
  old=shared.fixtures('09200','unchanged')[0]['values']['asset_schedule']['items']['synthetic-cost']['cost_plan'];bad=copy.deepcopy(old);bad['financial_profile']='corp_domestic'
  with self.assertRaises(ValueError):from_book_plan(bad)
 def test_sealed_original_catalog_and_all_previous_bindings_retained(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model);self.assertEqual(CODES,{'08040','08080','08500','09800'})
  for e in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r49.json");prior=d.validate_snapshot(old,model,*catalogs[e['year']]);sealed,_=d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r50.json");now=d.validate_snapshot(sealed,model,*catalogs[e['year']]);self.assertEqual({c:now[c] for c in prior},prior);self.assertEqual(set(now)-set(prior),CODES);self.assertEqual({c:d.bindings(e)[c] for c in now},{c:ref.rsplit('@',1)[0]+'@'+('3' if c in ('49120','72010') else '2') if c in (__import__('datev_issuer_corrections').CHANGES|{'72040'}) else ref for c,ref in now.items()})
   for group in next(y for y in REVIEW['years'] if y['year']==e['year'])['groups']:
    rule=d.mapping(model,group['purpose'],'6.9');code=group['sources'][0]['code'];self.assertEqual(rule['financial_profile_contract'],code+'@1');self.assertEqual(rule['allowed_targets'],['bs.ass.fixAss.fin.'+('sharesInAffil.corporations' if code in ('08040','08080') else 'particip.corporations'),'bs.ass.fixAss.fin.'+('sharesInAffilOtherForeign' if code in ('08040','08080') else 'participOtherForeign')]);self.assertEqual(rule.get('financial_parent_disclosures',[]),['bs.ass.fixAss.fin.sharesInAffil.parentComp'] if code=='08080' else [])
    for source in group['sources']:self.assertEqual(d.catalog(catalogs[e['year']][0])[source['code']]['signature'],source['signature'])
 def test_actual_cost_input_and_optional_form_keep_country(self):
  v=fixture('08040','corp_domestic','unchanged','whole')[0]['values'];base=v['asset_schedule']['items']['synthetic-cost']['cost_plan'];input={k:base[k] for k in ('acquired_on','sphere','account','reason','valuation_kind','valuation_document')};input.update(basis='120,00',method='cost',asset_reviewed='1')
  script='require "vereinsverwaltung/src/bootstrap.php"; $i=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR); try {echo json_encode(["ok"=>true,"plan"=>\\Kulturhus\\Verein\\AssetBooks::plan($i)],JSON_THROW_ON_ERROR);}catch(DomainException $e){echo json_encode(["ok"=>false]);}'
  for code in sorted(CODES):
   for profile in (None,'bad','corp_domestic','corp_foreign'):
    i=dict(input,account=code)
    if profile is not None:i['financial_profile']=profile
    p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],'-r',script],cwd=ROOT.parent,input=json.dumps(i),capture_output=True,encoding='utf8');self.assertEqual(p.returncode,0,p.stdout+p.stderr);self.assertEqual(p.stderr,'');result=json.loads(p.stdout)
    self.assertEqual(result['ok'],profile in PROFILES if isinstance(profile,str) else False)
    if result['ok']:self.assertEqual(result['plan']['target'],target(code,profile));self.assertEqual(result['plan']['financial_profile'],profile)
  view=(ROOT/'views/Finance/Accounting/financial-investment-profile.php').read_text();self.assertIn('Keine solche Beteiligung',view);self.assertIn('Im Inland',view);self.assertIn('Im Ausland',view);self.assertIn('financial-investment-profile.php',(ROOT/'views/Finance/Accounting/finance-book-values.php').read_text())
if __name__=='__main__':unittest.main(verbosity=2)
