"""Original cheques, prepaid statutory taxes, VAT and declared payables."""
import copy,importlib.util,json,os,subprocess,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('positions_r34_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
shared.REVIEW='balance-positions-r34';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/balance-positions-r34.json').read_text())['years']
@lru_cache(maxsize=250)
def fixture(year,index,mode='normal',whole=False,dated=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-balance-positions-r34.php'),str(year),str(index),mode,'whole' if whole else 'single','dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def selections(v,groups,target=None):
 return {'code-'+s['code']:target or g['target'] for g in groups for s in g['sources'] if g['conditional'] and any(v['tax']['accounts']['code-'+s['code']][f] for f in ('opening_cents','debit_cents','credit_cents'))}
def cases(dated=False):
 for e in shared.SPECS:
  for whole in (False,True):
   for i,g in enumerate(e['groups']):
    for mode in ('normal','opening','cancelled')+(('negative',) if g['group'] in ('revenue','expense') else ()):
     v=fixture(e['year'],i,mode,whole,dated)
     for target in g['choices']:yield {'year':e['year'],'whole':whole,'mode':mode,'index':i,'target':target,'dated':dated,'values':v,'mapping':selections(v,[g],target)}
   for mode in ('normal','opening','cancelled','inactive'):
    v=fixture(e['year'],-1,mode,whole,dated);yield {'year':e['year'],'whole':whole,'mode':mode,'index':-1,'target':'reviewed-mixed','dated':dated,'values':v,'mapping':selections(v,e['groups'])}
class BalancePositionsR34(unittest.TestCase):
 def test_actual_annual_and_dated_journal_positions(self):
  for dated in (False,True):
   for row in cases(dated):
    v=row['values'];before=copy.deepcopy(v);f=shared.facts(shared.build(v,row['mapping'],row['whole']),row['whole'])
    for t,k in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(f[t],shared.money(v['tax'][k]))
    if row['index']!=-1:
     g=next(e for e in shared.SPECS if e['year']==row['year'])['groups'][row['index']];self.assertEqual(f[row['target']],shared.money(sum(v['tax']['accounts']['code-'+s['code']]['amount_cents'] for s in g['sources'])))
    self.assertEqual(v,before)
 def test_negative_ordinary_assets_and_liabilities_remain_rejected(self):
  for e in shared.SPECS:
   for i,g in enumerate(e['groups']):
    if g['group'] in ('revenue','expense'):continue
    for whole in (False,True):
     for dated in (False,True):
      with self.assertRaisesRegex(RuntimeError,'Gegenseite'):fixture(e['year'],i,'negative',whole,dated)
 def test_confirmed_entitlement_and_historical_tax_choice_required(self):
  for e in shared.SPECS:
   for i,g in enumerate(e['groups']):
    if not g['conditional']:continue
    v=fixture(e['year'],i);self.assertFalse(any('code-'+s['code'] in shared.automatic_mapping(v) for s in g['sources']))
    with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):shared.build(v)
    bad=dict.fromkeys(('code-'+s['code'] for s in g['sources']),'bs.eqLiab.liab.other.employees')
    with self.assertRaises(ValueError):shared.build(v,bad)
 def test_exact_source_immutable_history_and_absent_2026_accounts(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   a,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r33.json");b,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r34.json");before=shared.d.validate_snapshot(a,model,*catalogs[e['year']]);after=shared.d.validate_snapshot(b,model,*catalogs[e['year']]);self.assertEqual(len(after)-len(before),11 if e['year']==2025 else 8);self.assertEqual({k:after[k] for k in before},before)
  shared.OperatingExtended('test_sources_are_exact_and_all_choices_are_official_in_both_profiles').test_sources_are_exact_and_all_choices_are_official_in_both_profiles()
  rows=shared.d.catalog(catalogs[2026][0]);self.assertFalse(any(c in rows and rows[c]['bookable'] for c in ('38710','41850')))
if __name__=='__main__':unittest.main(verbosity=2)
