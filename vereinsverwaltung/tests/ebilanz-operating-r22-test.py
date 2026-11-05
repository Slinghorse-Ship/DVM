"""Additional source families, recipient choices, and debit-positive §13b discounts."""
import importlib.util,json,unittest,os,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('operating_r22_shared',ROOT/'tests/ebilanz-operating-extended-test.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
shared.REVIEW='operating-r22';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/operating-r22.json').read_text(encoding='utf8'))['years']

class AdditionalOperating(shared.OperatingExtended):
    def test_actual_views_explain_closing_and_do_not_infer_conditional_vat_rates(self):
        result=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/balance-mapping-extensions-view.php')],capture_output=True,encoding='utf8',timeout=180)
        self.assertFalse(result.stderr)
        report=json.loads(result.stdout)
        self.assertEqual(result.returncode,0,report['errors'])
        self.assertTrue(report['passed']);self.assertEqual(report['checks'],9926)
        self.assertFalse(report['database_connected'])

    def test_preserves_all_previous_bindings(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,22)))
        for current in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r21.json")
            new,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r22.json")
            prior=d.validate_snapshot(old,model,*catalogs[current['year']]);now=d.validate_snapshot(new,model,*catalogs[current['year']])
            self.assertEqual({n:now[n] for n in prior},prior)
            self.assertEqual(len(now)-len(prior),32)
            self.assertEqual({n:d.bindings(current)[n] for n in now},now)

    def test_mixed_journal_and_all_choices_in_whole_club(self):
        for edition in shared.SPECS:
            mapping={};expected={};i=0
            for index,s in enumerate(edition['groups']):
                for row in s['sources']:
                    amount=1234+i;i+=1
                    if s['conditional']:mapping['code-'+row['code']]=s['target']
                    expected[s['target']]=expected.get(s['target'],0)+(-amount if s['target'] in shared.CONTRA else amount)
                if s['conditional']:
                    v=shared.fixture(edition['year'],index,whole=True)
                    for target in s['choices']:
                        out=shared.facts(shared.build(v,dict.fromkeys(('code-'+r['code'] for r in s['sources']),target),True),True)
                        self.assertEqual(out[target],shared.money(sum(1234+j for j in range(len(s['sources'])))))
            for whole in (False,True):
                for mode,multiplier in (('normal',1),('negative',-1),('cancelled',0)):
                    v=shared.fixture(edition['year'],-1,mode,whole);out=shared.facts(shared.build(v,mapping,whole),whole)
                    for target,amount in expected.items():self.assertEqual(out[target],shared.money(multiplier*amount))
                    for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(out[target],shared.money(v['tax'][key]))

if __name__=='__main__':unittest.main(verbosity=2)
