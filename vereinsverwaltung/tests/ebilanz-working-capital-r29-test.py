"""Exact foreign-tax and contract-selected liability purposes from original SKR42."""
import copy,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('working_capital_r29_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
shared.REVIEW='working-capital-r29';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/working-capital-r29.json').read_text())['years']

def cases():
 for edition in shared.SPECS:
  for whole in (False,True):
   for mode in ('normal','cancelled'):
    for index,spec in enumerate(edition['groups']):
     for target in spec['choices']:
      mapping=dict.fromkeys(('code-'+r['code'] for r in spec['sources']),target) if spec['conditional'] else {}
      yield {'year':edition['year'],'whole':whole,'mode':mode,'index':index,'target':target,'values':shared.fixture(edition['year'],index,mode,whole),'mapping':mapping}

class WorkingCapitalR29(unittest.TestCase):
 def test_every_exact_choice_and_cancellation_matches_original_values(self):
  count=0
  for row in cases():
   v=row['values'];before=copy.deepcopy(v);facts=shared.facts(shared.build(v,row['mapping'],row['whole']),row['whole']);spec=next(e for e in shared.SPECS if e['year']==row['year'])['groups'][row['index']]
   n=0 if row['mode']=='cancelled' else sum(1234+i for i in range(len(spec['sources'])))
   self.assertEqual(facts[row['target']],shared.money(n))
   for target in spec['disclosures']:self.assertEqual(facts[target],shared.money(n))
   self.assertEqual(facts['bs.ass'],shared.money(v['tax']['asset_total_cents']));self.assertEqual(facts['bs.eqLiab'],shared.money(v['tax']['passive_total_cents']));self.assertEqual(v,before);count+=1
  self.assertEqual(count,96)
 def test_all_added_accounts_in_one_original_journal(self):
  for edition in shared.SPECS:
   explicit={};expected={}
   for spec in edition['groups']:
    for source in spec['sources']:
     if spec['conditional']:explicit['code-'+source['code']]=spec['target']
   for whole in (False,True):
    for mode in ('normal','cancelled'):
     v=shared.fixture(edition['year'],-1,mode,whole);f=shared.facts(shared.build(v,explicit,whole),whole)
     self.assertEqual(f['bs.ass'],shared.money(v['tax']['asset_total_cents']));self.assertEqual(f['bs.eqLiab'],shared.money(v['tax']['passive_total_cents']))
 def test_opposite_balances_and_prohibited_contract_types_do_not_gain_export(self):
  for edition in shared.SPECS:
   for index,spec in enumerate(edition['groups']):
    for whole in (False,True):
     with self.assertRaises((ValueError,RuntimeError)):shared.build(shared.fixture(edition['year'],index,'negative',whole),dict.fromkeys(('code-'+r['code'] for r in spec['sources']),spec['target']),whole)
    if 'silentPartner' in spec['target']:
     v=shared.fixture(edition['year'],index);target=spec['target'].rsplit('.',1)[0]+'.atyp'
     with self.assertRaises(ValueError):shared.build(v,dict.fromkeys(('code-'+r['code'] for r in spec['sources']),target))
     automatic=shared.automatic_mapping(v)
     for source in spec['sources']:self.assertNotIn('code-'+source['code'],automatic)
 def test_sealed_additions_preserve_previous_bindings_and_original_sources(self):
  from datev_history_helpers import original_sources
  d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
  for current in editions:
   old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r28.json");sealed,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r29.json")
   prior=d.validate_snapshot(old,model,*catalogs[current['year']]);now=d.validate_snapshot(sealed,model,*catalogs[current['year']]);self.assertEqual(len(now)-len(prior),10)
   self.assertEqual({c:now[c] for c in prior},prior);original=original_sources(model,current);self.assertEqual({c:original[c] for c in now},now)
   edition=next(e for e in shared.SPECS if e['year']==current['year']);rows=d.catalog(catalogs[current['year']][0])
   for spec in edition['groups']:
    for source in spec['sources']:self.assertEqual(rows[source['code']]['signature'],source['signature'])

if __name__=='__main__':unittest.main(verbosity=2)
