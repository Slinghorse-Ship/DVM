"""Source-bound securities, provisions and prepaid amounts in both annual editions."""
import importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('balance_positions_shared',ROOT/'tests/ebilanz-working-capital-r18-test.py')
previous=importlib.util.module_from_spec(spec);spec.loader.exec_module(previous)
shared=previous.shared
shared.REVIEW='balance-positions-r20'
shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/balance-positions-r20.json').read_text(encoding='utf8'))['years']

class BalancePositions(previous.WorkingCapital):
    def test_preserves_previous_editions_and_all_original_definitions(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json')
        editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,20)))
        for current in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r19.json")
            historic,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r20.json")
            prior=d.validate_snapshot(old,model,*catalogs[current['year']])
            now=d.validate_snapshot(historic,model,*catalogs[current['year']])
            self.assertEqual({n:now[n] for n in prior},prior)
            self.assertEqual(len(now)-len(prior),19)
            self.assertEqual({n:d.bindings(current)[n] for n in now},now)

    def test_all_detail_choices_in_whole_club(self):
        for edition in shared.SPECS:
            for index,s in enumerate(edition['groups']):
                if not s['conditional']:continue
                v=shared.fixture(edition['year'],index,whole=True)
                for target in s['choices']:
                    with self.subTest(year=edition['year'],target=target):
                        out=shared.facts(shared.build(v,{'code-'+r['code']:target for r in s['sources']},True),True)
                        self.assertEqual(out[target],shared.money(sum(1234+i for i in range(len(s['sources'])))))

    def test_mixed_accounts_require_disjoint_securities_and_preserve_totals(self):
        for edition in shared.SPECS:
            mapping={};expected={};i=0
            for s in edition['groups']:
                for row in s['sources']:
                    target=s['target']
                    if row['code']=='15000':target='bs.ass.currAss.securities.affil.subsidiaries'
                    if s['conditional']:mapping['code-'+row['code']]=target
                    expected[target]=expected.get(target,0)+1234+i;i+=1
            for whole in (False,True):
                for mode in ('normal','cancelled'):
                    v=shared.fixture(edition['year'],-1,mode,whole)
                    out=shared.facts(shared.build(v,mapping,whole),whole)
                    for target,amount in expected.items():self.assertEqual(out[target],shared.money(amount if mode=='normal' else 0))
                    for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:
                        self.assertEqual(out[target],shared.money(v['tax'][key]))
                    with self.assertRaisesRegex(ValueError,'Überlappende Taxonomiezuordnung'):
                        shared.build(v,mapping|{'code-15000':'bs.ass.currAss.securities.affil'},whole)

if __name__=='__main__':unittest.main(verbosity=2)
