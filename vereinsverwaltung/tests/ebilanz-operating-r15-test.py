"""Every new exact source row, both tax profiles and all sign/reversal cases."""
import importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('r15_shared',ROOT/'tests/ebilanz-operating-extended-test.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
shared.REVIEW='operating-r15'
shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/operating-r15.json').read_text(encoding='utf8'))['years']

import sys as history_sys
history_sys.path.insert(0,str(Path(__file__).resolve().parent))
from datev_history_helpers import original_sources

class OperatingR15(shared.OperatingExtended):
    def test_immutable_r14_and_revision_chain(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json')
        editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,15)))
        for current in editions:
            edition,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r15.json")
            old,_=d.read_json(ROOT/f"resources/accounting/years/{edition['year']}-r14.json")
            previous=d.validate_snapshot(old,model,*catalogs[edition['year']]);new=d.bindings(edition)
            self.assertEqual({n:new[n] for n in previous},previous)
            self.assertEqual(len(new)-len(previous),72)
            self.assertEqual({n:v for n,v in original_sources(model,current).items() if n in new},new)
            self.assertFalse(d.coverage(model,edition,catalogs[edition['year']][0])['complete'])

    def test_detailed_tax_categories_do_not_rewrite_original_journal(self):
        for edition in shared.SPECS:
            for index,s in enumerate(edition['groups']):
                if 'nonDeductible' not in s['target'] and 'nondeductible' not in s['target']:continue
                v=shared.fixture(edition['year'],index)
                self.assertEqual(v['opening_adjustments'],[])
                self.assertEqual(v['current_adjustments'],[])
                self.assertEqual(v['book'],v['tax'])
                # Off-balance addbacks must not be recorded as a second expense.
                shared.build(v)

if __name__=='__main__':unittest.main(verbosity=2)
