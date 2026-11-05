"""Source-pinned participation, current securities and tax-reserve selections."""
import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('financial_r30_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
shared.REVIEW='financial-r30';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/financial-r30.json').read_text())['years']
def cases():
 for edition in shared.SPECS:
  for whole in (False,True):
   for mode in ('normal','negative','cancelled'):
    for index,spec in enumerate(edition['groups']):
     for target in spec['choices']:
      yield {'year':edition['year'],'whole':whole,'mode':mode,'index':index,'target':target,'values':shared.fixture(edition['year'],index,mode,whole),'mapping':dict.fromkeys(('code-'+s['code'] for s in spec['sources']),target)}
import sys as history_sys
history_sys.path.insert(0,str(Path(__file__).resolve().parent))
from datev_history_helpers import original_sources

class FinancialR30(unittest.TestCase):
 def test_all_original_choices_and_movements_match_the_journal(self):
  count=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);f=shared.facts(shared.build(v,row['mapping'],row['whole']),row['whole']);spec=next(e for e in shared.SPECS if e['year']==row['year'])['groups'][row['index']]
   amount=0 if row['mode']=='cancelled' else sum(1234+i for i in range(len(spec['sources'])))
   if row['mode']=='negative':amount=-amount
   self.assertEqual(f[row['target']],shared.money(amount))
   for target in spec['disclosures']:self.assertEqual(f[target],shared.money(amount))
   for target,k in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(f[target],shared.money(v['tax'][k]))
   self.assertEqual(v,before);count+=1
  self.assertEqual(count,504)
 def test_mixed_originals_and_missing_explicit_choices(self):
  for edition in shared.SPECS:
   explicit={}
   for spec in edition['groups']:explicit.update(dict.fromkeys(('code-'+s['code'] for s in spec['sources']),spec['target']))
   for whole in (False,True):
    for mode in ('normal','negative','cancelled'):
     v=shared.fixture(edition['year'],-1,mode,whole);f=shared.facts(shared.build(v,explicit,whole),whole)
     self.assertEqual(f['is.netIncome'],shared.money(v['tax']['annual_result_cents']))
   for i,spec in enumerate(edition['groups']):
    v=shared.fixture(edition['year'],i)
    with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):shared.build(v)
    self.assertFalse(any('code-'+s['code'] in shared.automatic_mapping(v) for s in spec['sources']))
 def test_sealed_history_and_exact_official_choices(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r29.json");sealed,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r30.json")
   before=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);after=shared.d.validate_snapshot(sealed,model,*catalogs[e['year']]);self.assertEqual(len(after)-len(before),7);self.assertEqual({c:after[c] for c in before},before);self.assertEqual({c:v for c,v in original_sources(model,e).items() if c in after},after)
  suite=shared.OperatingExtended('test_sources_are_exact_and_all_choices_are_official_in_both_profiles');suite.test_sources_are_exact_and_all_choices_are_official_in_both_profiles()
 def test_existing_expense_refund_keeps_its_official_sign(self):
  from ebilanz_sales import fact_amount
  target='is.netIncome.tax.releaseTaxProv.Gew'
  self.assertEqual(fact_amount(target,1234,'revenue'),1234);self.assertEqual(fact_amount(target,-1234,'expense'),1234);self.assertEqual(fact_amount(target,-1234),1234)
if __name__=='__main__':unittest.main(verbosity=2)
