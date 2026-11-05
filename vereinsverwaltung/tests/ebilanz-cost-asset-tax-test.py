"""Book/tax cost projection with replayed original valuations and exact counterpart identities."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent;ROOT=HERE.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_asset_valuation import calculate
from ebilanz_cost_asset_tax import item_projection,event_account
from ebilanz_asset_types import ACCOUNTS,COST,REVIEW
import datev_years as d
spec=importlib.util.spec_from_file_location('cost_kernel_cases',HERE/'ebilanz-asset-valuation-test.py');cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(cases)

def request(plan,period,events,previous=None):
    v=calculate(plan,period,events,previous)
    book_plan={'basis_cents':plan['basis_cents'],'residual_cents':0,'acquired_on':plan['acquired_on'],'months':0,'sphere':plan['sphere'],
      'account':plan['account'],'reason':plan['reason'],'method':'cost','valuation_kind':plan['kind'],'valuation_document':plan['document']}
    for field in ('financial_profile','target'):
        if field in plan:book_plan[field]=plan[field]
    chart={'fixed':{'group':'fixed_assets','skr_code':plan['account']}};accounts={}
    for e in events:
        code,group=event_account(plan['kind'],e['kind'],e.get('permanent',True),plan.get('financial_profile'));from ebilanz_financial_profile import counter_key;key=counter_key(code,plan.get('financial_profile'))
        accounts[code]=key;chart[key]={'group':group,'skr_code':code}
    item={'item_id':'Synthetic-cost-item','account':'fixed','method':'cost','cost_plan':book_plan,'valuation':v,'valuation_accounts':accounts or [],
          'depreciation_cents':0,**v['book']}
    return {'item':item,'chart':chart,'period':period}

def php(rows):
    p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/cost-asset-tax.php')],input=json.dumps(rows,ensure_ascii=False),capture_output=True,encoding='utf8',timeout=60)
    if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
    return json.loads(p.stdout)

class CostTax(unittest.TestCase):
    def test_tax_only_loss_recovery_and_repeated_release(self):
        rows=[];period={'start':'2025-01-01','end':'2025-12-31'}
        for kind in ('land','financial'):
            p=cases.plan(kind,12000)
            events=[cases.event('impairment','2025-05-01',8000,12000,permanent=kind=='land',tax_election=False),
              cases.event('impairment','2025-06-01',8000,9000,permanent=True,tax_election=True),
              cases.event('disposal','2025-07-01',2000,2200,cost_cents=3000,allocation_document=cases.document('split')),
              cases.event('disposal','2025-09-01',1000,1000,cost_cents=1000,allocation_document=cases.document('split two'))]
            first=request(p,period,events);rows.append(first)
            second=request(p,{'start':'2026-01-01','end':'2026-12-31'},[
              cases.event('writeup','2026-03-01',6000,6800,permanent=False,tax_election=False),
              cases.event('disposal','2026-04-01',6000,6800,cost_cents=8000,allocation_document=cases.document('all remaining'))],first['item']['valuation']);rows.append(second)
        self.assertEqual(php(rows),[{'value':item_projection(**row)} for row in rows])
        for row in rows:
            before=copy.deepcopy(row);out=item_projection(**row);self.assertEqual(row,before)
            for e in out['generated_current']:
                self.assertEqual(sum(l['debit_cents'] for l in e['lines']),sum(l['credit_cents'] for l in e['lines']))
            self.assertEqual(out['opening_difference_cents']+sum(e['lines'][0]['debit_cents']-e['lines'][0]['credit_cents'] for e in out['generated_current']),out['proof']['closing_difference_cents'])
    def test_zero_event_confirmation_keeps_originals_and_proves_zero_difference(self):
        row=request(cases.plan(),{'start':'2025-01-01','end':'2025-12-31'},[])
        self.assertEqual(php([row]),[{'value':item_projection(**row)}]);self.assertEqual(item_projection(**row)['generated_current'],[])
    def test_tampered_proof_originals_and_counterpart_cannot_be_disguised(self):
        base=request(cases.plan(),{'start':'2025-01-01','end':'2025-12-31'},[cases.event('impairment','2025-06-01',90000,100001,permanent=True,tax_election=False)])
        invalid=[]
        for mutation in [lambda r:r['item'].__setitem__('book_closing_cents',1),lambda r:r['item']['valuation'].__setitem__('sha256','0'*64),
          lambda r:r['item']['cost_plan'].__setitem__('months',60),lambda r:r['item']['cost_plan'].__setitem__('valuation_kind','financial'),
          lambda r:r['item']['valuation_accounts'].__setitem__('62300','fixed'),lambda r:r['chart']['src_asset_valuation_62300'].__setitem__('skr_code','62200'),
          lambda r:r['period'].__setitem__('end','2025-12-30')]:
            row=copy.deepcopy(base);mutation(row);invalid.append(row)
            with self.assertRaises(ValueError):item_projection(**row)
        self.assertTrue(all('rejected' in r for r in php(invalid)))
    def test_source_capabilities_pin_exact_original_meanings_without_granting_tax_export(self):
        historic=frozenset(('02150','02350','09000','09200'))
        construction=json.loads((ROOT/'resources/accounting/reviews/construction-r28.json').read_text())
        added={s['code'] for g in construction['years'][0]['groups'] for s in g['sources']}
        self.assertEqual(len(added),13)
        loans=json.loads((ROOT/'resources/accounting/reviews/ordinary-loans-r42.json').read_text());loan_codes={s['code'] for g in loans['years'][0]['groups'] for s in g['sources']};self.assertEqual(loan_codes,{'09300','09350','09400','09900'})
        for edition in loans['years']:
            data,h=d.read_json(ROOT/f"data/skr42-{edition['year']}.json");self.assertEqual(edition['source'],d.source(data,h,'balance'));rows=d.catalog(data)
            for group in edition['groups']:
                for source in group['sources']:
                    self.assertTrue(rows[source['code']]['bookable']);self.assertEqual(source['signature'],rows[source['code']]['signature']);self.assertEqual(source['semantic'],rows[source['code']]['semantic'])
        related=json.loads((ROOT/'resources/accounting/reviews/noncorporate-loans-r45.json').read_text());related_codes={s['code'] for g in related['years'][0]['groups'] for s in g['sources']};self.assertEqual(related_codes,{'08130','08150','08830'})
        for edition in related['years']:
            data,h=d.read_json(ROOT/f"data/skr42-{edition['year']}.json");self.assertEqual(edition['source'],d.source(data,h,'balance'));rows=d.catalog(data)
            for group in edition['groups']:
                for source in group['sources']:
                    self.assertTrue(rows[source['code']]['bookable']);self.assertEqual(source['signature'],rows[source['code']]['signature']);self.assertEqual(source['semantic'],rows[source['code']]['semantic'])
        advances=json.loads((ROOT/'resources/accounting/reviews/intangible-advances-r46.json').read_text());advance_codes={s['code'] for g in advances['years'][0]['groups'] if g['group']=='fixed_assets' for s in g['sources']};self.assertEqual(advance_codes,{'01700','01790'})
        corporate=json.loads((ROOT/'resources/accounting/reviews/corporate-investments-r50.json').read_text());corporate_codes={s['code'] for g in corporate['years'][0]['groups'] for s in g['sources']};self.assertEqual(corporate_codes,{'08040','08080','08500','09800'})
        rights=json.loads((ROOT/'resources/accounting/reviews/land-rights-r51.json').read_text());rights_codes={s['code'] for g in rights['years'][0]['groups'] for s in g['sources']};self.assertEqual(rights_codes,{'02100','02200'})
        self.assertEqual(COST,historic|added|loan_codes|{'07000'}|related_codes|advance_codes|corporate_codes|rights_codes|{'09100','08100','08140','08800','08850'})
        for source in REVIEW['years']:
            data,h=d.read_json(ROOT/f"data/skr42-{source['source']['year']}.json")
            self.assertEqual(source['source'],d.source(data,h,'balance'));rows=d.catalog(data)
            for row in source['assets']+source['event_accounts']:
                self.assertTrue(rows[row['code']]['bookable'])
                for key in ('name','page','semantic','signature'):self.assertEqual(row[key],rows[row['code']][key])
            for row in source['assets']:self.assertEqual(ACCOUNTS[row['code']]['target'],row['target'])
        from ebilanz_builder import SUPPORTED
        from ebilanz_cost_assets import ELIGIBLE
        self.assertEqual(ELIGIBLE,{'02150','02350','09000','09200','07000'}|added|loan_codes|related_codes|advance_codes|corporate_codes|rights_codes|{'09100','08100','08140','08800','08850'})
        for code in COST:self.assertEqual(ACCOUNTS[code]['target'] in SUPPORTED['fixed_assets'],code in ELIGIBLE)

if __name__=='__main__':unittest.main(verbosity=2)
