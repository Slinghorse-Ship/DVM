"""Actual affiliated/participating debtor corrections confined to source maturity pools."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('related_allowance_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
SPECS=json.loads((ROOT/'resources/accounting/reviews/related-allowances-r39.json').read_text())['years']
from ebilanz_builder import EbilanzBuilder
from ebilanz_account_closing import is_allowance

def fixture(year,index,mode='normal',whole=False,dated=False,bad=''):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-related-allowances-r39.php'),str(year),str(index),mode,'whole' if whole else 'single','dated' if dated else 'annual',bad],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def inputs(row):
 v=row['values'];m={};p=shared.profile(v,row['whole'])
 for k,a in v['tax']['accounts'].items():
  if a['skr_code'] in ('12760','12770','12960','12970'):m[k]='bs.ass.currAss.receiv.'+('affil' if a['skr_code'].startswith('127') else 'particip')
 if row['dated']:
  p.update(asset_values_unchanged=True,asset_tax_values_reviewed=False)
 if row['whole']:p.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club')
 return m,p
def cases():
 for e in SPECS:
  for whole in (False,True):
   for dated in (False,True):
    for i,g in enumerate(e['groups']):
     for mode in ('normal','opening','cancelled','inactive'):
      yield {'year':e['year'],'whole':whole,'dated':dated,'mode':mode,'index':i,'code':g['sources'][0]['code'],'target':g['target'],'values':fixture(e['year'],i,mode,whole,dated)}
class RelatedAllowances(unittest.TestCase):
 def test_actual_gross_book_tax_annual_and_dated(self):
  b=EbilanzBuilder()
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);package=b.build(v,m,p,'12345');facts=shared.facts(package,row['whole'])
   for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(facts[target],shared.money(v['tax'][key]))
   codes=v['tax']['accounts'];amount=sum(a['amount_cents'] for a in codes.values() if a['skr_code'].startswith(row['code'][:3]))
   self.assertEqual(facts.get(row['target'],'0.00'),shared.money(amount));self.assertEqual(v,before)
   self.assertTrue(is_allowance(v['year'],codes['code-'+row['code']]))
 def test_debtor_family_maturity_limit_and_sign(self):
  for e in SPECS:
   for i,g in enumerate(e['groups']):
    for dated in (False,True):
     for whole in (False,True):
      for bad in ('wrong-family','wrong-maturity','too-high','positive'):
       with self.subTest(year=e['year'],code=g['sources'][0]['code'],dated=dated,whole=whole,bad=bad):
        with self.assertRaisesRegex(RuntimeError,'Wertberichtigung'):fixture(e['year'],i,'normal',whole,dated,bad)
 def test_explicit_review_not_inferred_and_cross_family_choice_rejected(self):
  for e in SPECS:
   for i,g in enumerate(e['groups']):
    row={'values':fixture(e['year'],i),'dated':False,'whole':False};m,p=inputs(row);key='code-'+g['sources'][0]['code']
    self.assertNotIn(key,shared.automatic_mapping(row['values']))
    missing=m.copy();missing.pop(key)
    with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):EbilanzBuilder().build(row['values'],missing,p,'12345')
    wrong=m.copy();wrong[key]='bs.ass.currAss.receiv.'+('particip' if g['contra_receivable_family']=='affil' else 'affil')
    with self.assertRaises(ValueError):EbilanzBuilder().build(row['values'],wrong,p,'12345')
 def test_exact_original_source_and_sealed_additive_history(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r38.json");new,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r39.json")
   before=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);after=shared.d.validate_snapshot(new,model,*catalogs[e['year']]);self.assertEqual({k:after[k] for k in before},before);self.assertEqual(set(after)-set(before),{'12760','12770','12960','12970'})
if __name__=='__main__':unittest.main(verbosity=2)
