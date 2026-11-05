"""Original BAL equations, both value sides and successive evidenced events."""
import copy,importlib.util,json,sys,unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent;ROOT=HERE.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_asset_valuation import calculate
from ebilanz_cost_asset_grid import project
spec=importlib.util.spec_from_file_location('cost_grid_cases',HERE/'ebilanz-asset-valuation-test.py');cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(cases)

class CostGrid(unittest.TestCase):
    def test_all_four_original_taxonomy_formulas_and_value_sides(self):
        for kind,code in [('land','02150'),('land','02350'),('financial','09000'),('financial','09200')]:
            p=cases.plan(kind,12000);p['account']=code
            first=calculate(p,{'start':'2025-01-01','end':'2025-12-31'},[
                cases.event('impairment','2025-05-01',8000,12000,permanent=kind=='land',tax_election=False),
                cases.event('impairment','2025-06-01',8000,9000,permanent=True,tax_election=True),
                cases.event('disposal','2025-07-01',2000,2200,cost_cents=3000,allocation_document=cases.document('split')),
                cases.event('disposal','2025-09-01',1000,1000,cost_cents=1000,allocation_document=cases.document('second split'))])
            second=calculate(p,{'start':'2026-01-01','end':'2026-12-31'},[
                cases.event('writeup','2026-03-01',6000,6800,permanent=False,tax_election=False),
                cases.event('disposal','2026-04-01',6000,6800,cost_cents=8000,allocation_document=cases.document('all remaining'))],first)
            third=calculate(p,{'start':'2027-01-01','end':'2027-12-31'},[],second)
            for value in [first,second,third]:
                original=copy.deepcopy(value)
                for version in ['6.7','6.8','6.9','6.10']:
                    equations=json.loads((ROOT/f'resources/ebilanz/taxonomy-{version}.json').read_text(encoding='utf8'))['asset_calculations']
                    for side in ['book','tax']:
                        result=project(value,side,version);v=result['columns'];self.assertFalse(result['export_authorized'])
                        for parent,children in equations.items():self.assertEqual(v[parent],sum(v[k]*w for k,w in children.items()))
                        self.assertEqual(v['all'],value[side]['book_closing_cents']);self.assertEqual(v['all_Prev_period'],value[side]['book_opening_cents'])
                        self.assertEqual(v['accDepr.currChanges'],0);self.assertEqual(v['gross.currChanges'],0)
                        self.assertEqual(v['revaluation'],value[side]['writeup_cents']);self.assertEqual(v['accDepr.DeprPeriod.regular'],0)
                self.assertEqual(value,original)
    def test_partial_day_period_and_no_event_do_not_fabricate_depreciation(self):
        p=cases.plan();p['account']='02150'
        v=calculate(p,{'start':'2025-02-01','end':'2025-11-19'},[])
        g=project(v);self.assertEqual(g['columns']['gross.addition'],p['basis_cents']);self.assertEqual(g['columns']['accDepr'],0)
    def test_changed_evidence_and_unreviewed_kind_cannot_gain_xml_permission(self):
        p=cases.plan();p['account']='02150';v=calculate(p,{'start':'2025-01-01','end':'2025-12-31'},[])
        bad=copy.deepcopy(v);bad['tax']['book_closing_cents']-=1
        with self.assertRaises(ValueError):project(bad)
        p['account']='02400';bad=calculate(p,{'start':'2025-01-01','end':'2025-12-31'},[])
        with self.assertRaises(ValueError):project(bad)
        with self.assertRaises(ValueError):project(v,version='6.11')

if __name__=='__main__':unittest.main(verbosity=2)
