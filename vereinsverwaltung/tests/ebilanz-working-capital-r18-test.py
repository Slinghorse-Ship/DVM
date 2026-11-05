"""Original source-bound working-capital accounts, maturity facts and both profiles."""
import copy,importlib.util,json,os,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('working_capital_shared',ROOT/'tests/ebilanz-operating-extended-test.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
shared.REVIEW='working-capital-r18'
shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/working-capital-r18.json').read_text(encoding='utf8'))['years']
from ebilanz_working_capital import validate,disclosures


class WorkingCapital(shared.OperatingExtended):
    def test_runtime_identity_includes_disclosure_generation(self):
        self.assertIn("'../tools/ebilanz_working_capital.py'",(ROOT/'src/Finance/Tax/TaxFilings.php').read_text(encoding='utf8'))

    def test_every_added_account_normal_negative_and_cancelled(self):
        for edition in shared.SPECS:
            for index,s in enumerate(edition['groups']):
                for whole in (False,True):
                    for mode in ('normal','negative','cancelled'):
                        with self.subTest(year=edition['year'],index=index,whole=whole,mode=mode):
                            explicit={'code-'+row['code']:s['target'] for row in s['sources']} if s['conditional'] else {}
                            if mode=='negative':
                                with self.assertRaisesRegex((RuntimeError,ValueError),'Bilanzwerte stimmen nicht|Gesamtbilanz und Originalbuchungen stimmen nicht|Gegenseitigen Saldo'):
                                    shared.build(shared.fixture(edition['year'],index,mode,whole),explicit,whole)
                                continue
                            v=shared.fixture(edition['year'],index,mode,whole);before=copy.deepcopy(v)
                            out=shared.facts(shared.build(v,explicit,whole),whole)
                            amount=0 if mode=='cancelled' else sum(1234+i for i in range(len(s['sources'])))
                            self.assertEqual(out[s['target']],shared.money(amount))
                            for target in s['disclosures']:self.assertEqual(out[target],shared.money(amount))
                            for target,key in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:
                                self.assertEqual(out[target],shared.money(v['tax'][key]))
                            self.assertEqual(v,before)

    def test_preserves_previous_editions_and_all_original_definitions(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json')
        editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,18)))
        for current in editions:
            old,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r17.json")
            historic,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r18.json")
            prior=d.validate_snapshot(old,model,*catalogs[current['year']]);now=d.validate_snapshot(historic,model,*catalogs[current['year']])
            self.assertEqual({n:now[n] for n in prior},prior)
            self.assertEqual(len(now)-len(prior),79)
            self.assertEqual({n:d.bindings(current)[n] for n in now},now)

    def test_supplements_cannot_add_balance_amounts_or_cross_account_meanings(self):
        b=shared.EbilanzBuilder()
        rule={'group':'receivables','target':'bs.ass.currAss.receiv.trade.other',
              'disclosures':['bs.ass.currAss.receiv.trade.upTo1year']}
        validate(rule,b.meta)
        for fields in [
            {'disclosures':['bs.ass.currAss.receiv.trade']},
            {'disclosures':['bs.ass.currAss.receiv.trade.upTo1year']*2},
            {'disclosures':'bs.ass.currAss.receiv.trade.upTo1year'},
            {'disclosures':[True]}, {'target':'bs.eqLiab.liab.trade.genOther'},
            {'allowed_targets':['bs.ass.currAss.receiv.affil']},
        ]:
            with self.assertRaises(ValueError):validate(rule|fields,b.meta)
        meta=copy.deepcopy(b.meta);meta['calculations']['bs.ass']['bs.ass.currAss.receiv.trade.upTo1year']=1
        with self.assertRaisesRegex(ValueError,'Bilanzsumme'):validate(rule,meta)

    def test_maturity_aggregates_balances_not_movements_and_cannot_be_manual_target(self):
        for edition in shared.SPECS:
            for index,s in enumerate(edition['groups']):
                if not s['disclosures']:continue
                v=shared.fixture(edition['year'],index)
                mapping={k:s['target'] for k in v['tax']['accounts'] if k.startswith('code-')}
                b=shared.EbilanzBuilder();extras=disclosures(v,mapping,b.meta,b.permitted)
                self.assertEqual(extras,{t:sum(1234+i for i in range(len(s['sources']))) for t in s['disclosures']})
                key=next(iter(mapping));altered=copy.deepcopy(v);altered['tax']['accounts'][key]['amount_cents']=True
                with self.assertRaises(ValueError):disclosures(altered,mapping,b.meta,b.permitted)
                wrong=mapping|{key:s['disclosures'][0]}
                with self.assertRaisesRegex(ValueError,'Kontenbedeutung'):shared.build(v,wrong)

    def test_release_audit_counts_xml_rules_not_inferred_statement_groups(self):
        p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tools/balance-mapping-audit.php')],capture_output=True,encoding='utf8',timeout=60)
        self.assertEqual(p.returncode,2,p.stderr);self.assertFalse(p.stderr)
        report=json.loads(p.stdout);self.assertFalse(report['mapping_complete']);self.assertFalse(report['production_authorized'])
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
        for e in editions:
            expected=d.coverage(model,e,catalogs[e['year']][0]);actual=report['years'][str(e['year'])]
            self.assertEqual(actual['automatic']+actual['conditional'],expected['assigned'])
            self.assertEqual(actual['missing'],expected['bookable']-expected['assigned'])
            self.assertIn('19500',{r['code'] for r in actual['missing_accounts']})

if __name__=='__main__':unittest.main(verbosity=2)
