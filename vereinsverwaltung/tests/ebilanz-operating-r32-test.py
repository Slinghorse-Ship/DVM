"""Original operating source choices, signs, disclosures and sealed annual history."""
import copy,importlib.util,json,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('operating_r32_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
shared.REVIEW='operating-r32';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/operating-r32.json').read_text())['years']
@lru_cache(maxsize=200)
def fixture(year,index,mode,whole):return shared.fixture(year,index,mode,whole)
def cases():
 for edition in shared.SPECS:
  for whole in (False,True):
   for mode in ('normal','negative','cancelled'):
    for index,spec in enumerate(edition['groups']):
     for target in spec['choices']:
      yield {'year':edition['year'],'whole':whole,'mode':mode,'index':index,'target':target,'values':fixture(edition['year'],index,mode,whole),'mapping':dict.fromkeys(('code-'+s['code'] for s in spec['sources']),target)}
import sys as history_sys
history_sys.path.insert(0,str(Path(__file__).resolve().parent))
from datev_history_helpers import original_sources

class OperatingR32(unittest.TestCase):
 def test_all_original_choices_and_movements_match_the_journal(self):
  from ebilanz_sales import fact_amount
  count=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);f=shared.facts(shared.build(v,row['mapping'],row['whole']),row['whole']);spec=next(e for e in shared.SPECS if e['year']==row['year'])['groups'][row['index']]
   amount=sum(v['tax']['accounts']['code-'+s['code']]['amount_cents'] for s in spec['sources'])
   self.assertEqual(f[row['target']],shared.money(fact_amount(row['target'],amount,spec['group'])))
   for target in spec['disclosures']:self.assertEqual(f[target],shared.money(amount))
   for target,k in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(f[target],shared.money(v['tax'][k]))
   self.assertEqual(v,before);count+=1
  self.assertEqual(count,sum(len(g['choices']) for e in shared.SPECS for g in e['groups'])*6)
 def test_mixed_sources_and_missing_explicit_choices(self):
  for edition in shared.SPECS:
   explicit={}
   for spec in edition['groups']:explicit.update(dict.fromkeys(('code-'+s['code'] for s in spec['sources']),spec['target']))
   for whole in (False,True):
    for mode in ('normal','negative','cancelled'):
     v=fixture(edition['year'],-1,mode,whole);f=shared.facts(shared.build(v,explicit,whole),whole)
     self.assertEqual(f['is.netIncome'],shared.money(v['tax']['annual_result_cents']))
   for i,spec in enumerate(edition['groups']):
    if not spec['conditional']:continue
    v=fixture(edition['year'],i,'normal',False)
    with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):shared.build(v)
    self.assertFalse(any('code-'+s['code'] in shared.automatic_mapping(v) for s in spec['sources']))
 def test_sealed_history_and_exact_official_choices(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r31.json");sealed,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r32.json")
   before=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);after=shared.d.validate_snapshot(sealed,model,*catalogs[e['year']]);self.assertEqual(len(after)-len(before),31 if e['year']==2025 else 22);self.assertEqual({c:after[c] for c in before},before);self.assertEqual({c:v for c,v in original_sources(model,e).items() if c in after},after)
  suite=shared.OperatingExtended('test_sources_are_exact_and_all_choices_are_official_in_both_profiles');suite.test_sources_are_exact_and_all_choices_are_official_in_both_profiles()
 def test_cross_branch_disclosure_cannot_be_used_for_unrelated_facts(self):
  from ebilanz_working_capital import validate
  b=shared.EbilanzBuilder();target='is.netIncome.regular.operatingTC.otherOpRevenue.licenses'
  with self.assertRaisesRegex(ValueError,'widersprechen'):validate({'target':'is.netIncome.regular.operatingTC.otherOpRevenue.miscellaneous','disclosures':[target]},b.meta)
  with self.assertRaisesRegex(ValueError,'ungeprüfte|Ungeprüfte'):validate({'target':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.generalRateVAT','disclosures':[target+'.invented']},b.meta)
if __name__=='__main__':unittest.main(verbosity=2)
