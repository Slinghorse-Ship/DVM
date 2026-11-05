"""Original loans retain borrower legal form, country, threshold and each original cost proof."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('loan55_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
shared=load('ebilanz-cost-assets-test.py');combined=load('ebilanz-cost-combined-test.py')
import datev_years as d
from ebilanz_financial_profile import LOANS,profiles,target,counter_code,counter_key,counter_target
from ebilanz_asset_valuation import from_book_plan
from ebilanz_cost_asset_tax import event_account
from ebilanz_mapping import automatic_mapping
from ebilanz_builder import EbilanzBuilder
from datev_loan_corrections import validate,ADDED,COUNTERS
CODES=ADDED
@lru_cache(maxsize=512)
def fixture(code,profile,variant,mode):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-cost-assets.php'),code,variant,mode,profile],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def cases():
 for code in sorted(CODES):
  for profile in profiles(code):
   for mode in ('annual','whole','period','period-whole'):
    for variant in ('multiple','tax-only','writeup','writeup-release'):
     for row in fixture(code,profile,variant,mode):yield {'code':code,'financial_profile':profile,'mode':mode,'variant':variant,'year':row['values']['year'],'values':row['values']}
def inputs(row):return shared.inputs(row['values'])
def combined_cases():
 codes=sorted(CODES|{'08040','08080','08500','09800','09000','09100'})
 for code in codes:
  for profile in profiles(code):
   p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-cost-combined.php'),code,'temporary','annual',profile],capture_output=True,encoding='utf8',timeout=60)
   if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
   for row in json.loads(p.stdout):yield {'code':code,'financial_profile':profile,'mode':'combined-HGBM','variant':'temporary','year':row['values']['year'],'values':row['values'],'row':row}
class OriginalLoans(unittest.TestCase):
 def test_source_and_exact_version_extension_reject_tampering(self):
  m,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(m)
  for e in editions:
   y=e['year'];e,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r55.json');old,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r54.json');data,h=catalogs[y];before=d.validate_snapshot(old,m,data,h);after=d.validate_snapshot(e,m,data,h);proof=e['provenance'];rows=d.catalog(data)
   self.assertEqual(set(after)-set(before),CODES);self.assertEqual(validate(m,before,after,rows,proof),set(COUNTERS))
   for c in CODES:
    rule=d.mapping(m,after[c],'6.9');self.assertEqual(rule['financial_profile_contract'],c+'@1');self.assertEqual(rule['allowed_targets'],list(dict.fromkeys(LOANS[c].values())))
   for field in ('previous_purpose','new_purpose','source_signature','previous_definition_sha256','new_definition_sha256'):
    p=copy.deepcopy(proof);p['corrections']['72010'][field]='forged'
    with self.assertRaises(ValueError):validate(m,before,after,rows,p)
   changed=dict(after);changed['18000']=after['08140']
   with self.assertRaises(ValueError):validate(m,before,changed,rows,proof)
   changed=dict(after);del changed['08100']
   with self.assertRaises(ValueError):validate(m,before,changed,rows,proof)
   forged=copy.deepcopy(m);pid,v=after['72010'].rsplit('@',1);forged['purposes'][pid]['versions'][v]['mappings']['6.9']['allowed_targets'].pop(0)
   with self.assertRaises(ValueError):validate(forged,before,after,rows,proof)
 def test_all_scopes_retain_cost_and_counter_identity(self):
  count=0;b=EbilanzBuilder()
  for code in sorted(CODES):
   for profile in profiles(code):
    for mode in ('annual','whole','period','period-whole','mixed','mixed-whole'):
     for variant in (('multiple',) if mode.startswith('mixed') else ('multiple','writeup-release')):
      for row in fixture(code,profile,variant,mode):
       v=row['values'];before=copy.deepcopy(v);m,p=shared.inputs(v);b.build(v,m,p,'12345');self.assertEqual(v,before);self.assertEqual(m['fixed'],target(code,profile));self.assertEqual(m,row['automatic']|{k:t for k,t in m.items() if k not in row['automatic']})
       item=v['asset_schedule']['items']['synthetic-cost'];plan=item.get('cost_plan',item.get('plan'));self.assertEqual(plan['financial_profile'],profile);self.assertEqual(item['valuation']['source']['plan']['financial_profile'],profile)
       for event in item['valuation']['source']['events']:
        counter=counter_code(event['kind'],event.get('permanent',True),profile);key=counter_key(counter,profile);self.assertEqual(event_account('financial',event['kind'],event.get('permanent',True),profile)[0],counter)
        if key in m:self.assertEqual(m[key],counter_target(counter,profile));self.assertEqual(v['tax']['accounts'][key]['skr_code'],counter)
       count+=1
  self.assertEqual(count,280)
 def test_original_contract_rejects_missing_wrong_borrower_and_old_loan_profile(self):
  for code in CODES:
   row=fixture(code,profiles(code)[0],'multiple','whole')[0];v=row['values'];m,p=shared.inputs(v);plan=v['asset_schedule']['items']['synthetic-cost']['cost_plan']
   for invalid in (None,'','unknown','corp_domestic',1,[],{}):
    changed=copy.deepcopy(plan);changed['financial_profile']=invalid
    with self.assertRaises(ValueError):from_book_plan(changed)
   changed=copy.deepcopy(plan);changed['target']='bs.ass.fixAss.fin.sharesInAffilOtherForeign'
   with self.assertRaises(ValueError):from_book_plan(changed)
   for profile in profiles(code):self.assertFalse(target(code,profile).endswith('OtherForeign'))
   if code in ('08140','08850'):
    for profile in ('loan_pg','loan_sole'):
     with self.assertRaises(ValueError):target(code,profile)
  plan=copy.deepcopy(fixture('08100','loan_pg','unchanged','annual')[0]['values']['asset_schedule']['items']['synthetic-cost']['cost_plan']);plan['account']='08130'
  with self.assertRaises(ValueError):from_book_plan(plan)
 def test_actual_input_requires_borrower_evidence(self):
  script='require "vereinsverwaltung/src/bootstrap.php"; $i=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR); try {echo json_encode(["ok"=>true,"plan"=>\\Kulturhus\\Verein\\AssetBooks::plan($i)],JSON_THROW_ON_ERROR);}catch(DomainException $e){echo json_encode(["ok"=>false]);}'
  for code in sorted(CODES):
   original=fixture(code,profiles(code)[0],'unchanged','annual')[0]['values']['asset_schedule']['items']['synthetic-cost']['cost_plan'];base={k:original[k] for k in ('acquired_on','sphere','account','reason','valuation_kind','valuation_document')};base.update(basis='120,00',method='cost',asset_reviewed='1')
   for profile in (None,'bad',*profiles(code)):
    i=dict(base)
    if profile is not None:i['financial_profile']=profile
    p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],'-r',script],cwd=ROOT.parent,input=json.dumps(i),capture_output=True,encoding='utf8');self.assertEqual(p.returncode,0,p.stdout+p.stderr);self.assertEqual(p.stderr,'');out=json.loads(p.stdout);self.assertEqual(out['ok'],isinstance(profile,str) and profile in profiles(code))
    if out['ok']:self.assertEqual(out['plan']['target'],target(code,profile));self.assertEqual(out['plan']['financial_profile'],profile)
 def test_actual_HGBM_and_separate_tax_values_use_original_temporary_account(self):
  count=0
  for row in combined_cases():
   v=row['values'];profile=row['financial_profile'];key=counter_key('72010',profile);self.assertEqual(v['whole']['tax']['accounts'][key]['amount_cents'],4000);self.assertEqual(v['taxable']['tax']['accounts'][key]['amount_cents'],0);self.assertEqual(v['whole']['asset_schedule']['items']['synthetic-cost']['valuation']['book']['book_closing_cents'],8000);self.assertEqual(v['taxable']['asset_tax']['tax_schedule']['items']['synthetic-cost']['valuation']['tax']['book_closing_cents'],12000)
   self.assertEqual(set(u['sphere'] for u in v['taxable']['club_scope']['allocation']['units'].values()),set('1234'));m,p=combined.inputs(row['row']);EbilanzBuilder().build(v,m,p,'12345');self.assertEqual(m['whole'][key],counter_target('72010',profile));count+=1
  self.assertEqual(count,48)
if __name__=='__main__':unittest.main(verbosity=2)
