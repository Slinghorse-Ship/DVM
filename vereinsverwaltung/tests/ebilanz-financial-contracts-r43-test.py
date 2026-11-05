"""Original separately posted asset proceeds, residual book values and write-ups."""
import copy,importlib.util,json,os,subprocess,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('contracts_r43_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
shared.REVIEW='financial-contracts-r43';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/financial-contracts-r43.json').read_text())['years']
EbilanzBuilder=shared.EbilanzBuilder;BUILDER=EbilanzBuilder()
@lru_cache(maxsize=320)
def fixture(year,index,mode='normal',whole=False,dated=False):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-financial-contracts-r43.php'),str(year),str(index),mode,'whole' if whole else 'single','dated' if dated else 'annual'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def cases(dated=False):
 for e in shared.SPECS:
  for whole in (False,True):
   for i,g in enumerate(e['groups']):
    for mode in ('normal','negative','cancelled'):
     for target in g['choices']:
      yield {'year':e['year'],'whole':whole,'mode':mode,'index':i,'target':target,'dated':dated,'values':fixture(e['year'],i,mode,whole,dated),'mapping':dict.fromkeys(('code-'+s['code'] for s in g['sources']),target)}
def inputs(row):return row['mapping'],shared.profile(row['values'],row['whole'])
class FinancialContractsR43(unittest.TestCase):
 def test_each_original_signed_and_cancelled_annual_dated_choice(self):
  from ebilanz_sales import fact_amount
  for dated in (False,True):
   for row in cases(dated):
    v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);f=shared.facts(BUILDER.build(v,m,p,'12345'),row['whole']);g=next(e for e in shared.SPECS if e['year']==row['year'])['groups'][row['index']]
    amount=sum(v['tax']['accounts']['code-'+s['code']]['amount_cents'] for s in g['sources']);self.assertEqual(f[row['target']],shared.money(fact_amount(row['target'],amount,g['group'])))
    for t,k in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(f[t],shared.money(v['tax'][k]))
    self.assertEqual(v,before)
 def test_ambiguous_source_requires_exact_confirmed_classification(self):
  for e in shared.SPECS:
   for i,g in enumerate(e['groups']):
    if not g['conditional']:continue
    for dated in (False,True):
     v=fixture(e['year'],i,dated=dated);self.assertFalse(any('code-'+s['code'] in shared.automatic_mapping(v) for s in g['sources']))
     with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):BUILDER.build(v,{},shared.profile(v),'12345')
     with self.assertRaises(ValueError):BUILDER.build(v,dict.fromkeys(('code-'+s['code'] for s in g['sources']),'is.netIncome.regular.operatingTC.otherCost.administration'),shared.profile(v),'12345')
 def test_original_source_snapshots_remain_exact(self):
  from datev_history_helpers import original_sources
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r42.json");sealed,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r43.json")
   before=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);after=shared.d.validate_snapshot(sealed,model,*catalogs[e['year']]);self.assertEqual(len(after)-len(before),1);self.assertEqual({c:after[c] for c in before},before);self.assertEqual(shared.d.validate_snapshot(e,model,*catalogs[e['year']]),after)
  shared.OperatingExtended('test_sources_are_exact_and_all_choices_are_official_in_both_profiles').test_sources_are_exact_and_all_choices_are_official_in_both_profiles()
 def test_financial_disclosure_and_contract_signs(self):
  for dated in (False,True):
   for year in (2025,2026):
    for mode in ('normal','negative','cancelled'):
     for index in (0,):
      v=fixture(year,index,mode,True,dated);g=next(e for e in shared.SPECS if e['year']==year)['groups'][index];target=g['choices'][0];m=dict.fromkeys(('code-'+a['code'] for a in g['sources']),target);facts=shared.facts(BUILDER.build(v,m,shared.profile(v,True),'12345'),True);amount=sum(v['tax']['accounts']['code-'+a['code']]['amount_cents'] for a in g['sources'])
      self.assertEqual(facts[target],shared.money(amount))
      for disclosure in g['disclosures']:self.assertEqual(facts[disclosure],shared.money(amount))
if __name__=='__main__':unittest.main(verbosity=2)
