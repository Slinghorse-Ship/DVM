"""Independent complete-club use shares: annual, mixed assets and dated carry."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent;ROOT=HERE.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_builder import EbilanzBuilder
from ebilanz_club_scope import digest
from ebilanz_period_club import project
from ebilanz_cost_asset_allocation import split
from ebilanz_asset_valuation import calculate
spec=importlib.util.spec_from_file_location('combined_cost',HERE/'ebilanz-cost-combined-test.py');base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)

def fixtures(code,variant,mode):
    r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/ebilanz-cost-combined.php'),code,variant,mode,'shared'],capture_output=True,encoding='utf8',timeout=60)
    if r.returncode or r.stderr:raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

CONSTRUCTION_REVIEW=json.loads((ROOT/'resources/accounting/reviews/construction-r28.json').read_text())
REVIEWED_COST={'02150','02350','09200'}|{s['code'] for g in CONSTRUCTION_REVIEW['years'][0]['groups'] for s in g['sources']}
assert len(REVIEWED_COST)==16

def cases():
    for mode in ('annual','mixed','period'):
        for code in sorted(REVIEWED_COST):
            variants=['unchanged','impairment','tax-only','multiple','writeup','writeup-release']+([] if mode=='mixed' else ['fully'])+(['temporary'] if code=='09200' else [])
            for variant in variants:
                for row in fixtures(code,variant,mode):yield mode,code,variant,row

class SharedCostBooks(unittest.TestCase):
    def test_odd_cent_saturation_and_successive_original_carry_in_both_implementations(self):
        document={'document_id':'a'*32,'revision':1,'sha256':'b'*64};requests=[]
        for n in range(1,74):
            plan={'kind':'financial','basis_cents':n,'acquired_on':'2025-02-15','sphere':'4','account':'09200','reason':'Synthetic odd original cost','document':document}
            event={'id':'c'*32,'on':'2025-05-01','kind':'impairment','book_cents':n//2,'tax_cents':n,'permanent':True,'tax_election':False,'reason':'Synthetic actual decision','legal_basis':'Synthetic reviewed valuation','document':document,'confirmed':True}
            first=calculate(plan,{'start':'2025-01-01','end':'2025-12-31'},[event]);previous=first
            for year in (2025,2026,2027):
                value=first if year==2025 else calculate(plan,{'start':f'{year}-01-01','end':f'{year}-12-31'},[event|{'id':'d'*32,'on':f'{year}-05-01','kind':'writeup','book_cents':n,'tax_cents':n,'permanent':False}] if year==2026 else [],previous)
                for weights in ({'food':2,'shop':3},{'food':1,'shop':1000000},{'food':1,'shop':1,'third':1}):requests.append({'value':value,'weights':weights})
                previous=value
        expected=[split(r['value'],r['weights']) for r in requests]
        r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/ebilanz-cost-shares.php')],input=json.dumps(requests),capture_output=True,encoding='utf8',timeout=60)
        self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertEqual(r.stderr,'')
        actual=json.loads(r.stdout)
        # PHP's empty associative array is encoded as []; only normalize an empty map.
        for row in actual:
            if row['events']==[]:row['events']={}
        self.assertEqual(actual,expected)
        self.assertEqual(len(expected),657)
        forged=copy.deepcopy(requests[0]);forged['value']['book']['book_closing_cents']+=1
        with self.assertRaises(ValueError):split(forged['value'],forged['weights'])
        r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/ebilanz-cost-shares.php')],input=json.dumps([forged]),capture_output=True,encoding='utf8',timeout=60)
        self.assertIn('error',json.loads(r.stdout)[0])
    def test_full_public_xml_with_independent_shares_and_original_book_tax(self):
        count=0
        for mode,code,variant,row in cases():
            v=row['values'];original=copy.deepcopy(v);m,p=base.inputs(row);result=EbilanzBuilder().build(v,m,p,'12345');count+=1
            self.assertEqual(v,original);self.assertEqual(result['transmission'],'unavailable');self.assertEqual(v['whole']['book'],v['whole']['tax'])
            scope=v['taxable']['period_club']['income']['scope'] if mode=='period' else v['taxable']['club_scope']
            a=scope['allocation'];self.assertEqual(set(u['sphere'] for u in a['units'].values()),set('1234'))
            self.assertEqual([u for u in a['units'] if a['units'][u]['sphere']=='4'],['food','shop'])
            s=scope['source'];item=s['asset_schedule']['items']['synthetic-cost'];cost=item['valuation']
            shares=row['asset_use']
            self.assertEqual(shares['shares']['synthetic-cost']['weights'],{'food':2,'shop':3})
            for u,part in split(cost,{'food':2,'shop':3})['parts'].items():self.assertEqual(shares['schedules'][u]['items']['synthetic-cost'],part)
            self.assertEqual(cost['source']['plan']['basis_cents'],12000)
        self.assertEqual(count,419)
    def test_rehashed_unit_cent_tampering_rejected_from_original_dated_values(self):
        income=copy.deepcopy(fixtures('09200','temporary','period')[0]['values']['taxable']['period_club']['income'])
        item=income['scope']['source']['assets']['schedules']['food']['items']['synthetic-cost'];item['impairment_cents']+=1
        income['sha256']=digest({k:n for k,n in income.items() if k!='sha256'})
        with self.assertRaises(ValueError):project(income)
    def test_missing_counter_movement_and_changed_weight_rejected(self):
        for mode in ('annual','period'):
            row=fixtures('09200','multiple',mode)[0];v=row['values'];m,p=base.inputs(row)
            scope=v['taxable']['period_club']['income']['scope'] if mode=='period' else v['taxable']['club_scope']
            share=scope['source']['assets']['shares']['synthetic-cost'] if mode=='period' else scope['allocation']['asset_shares']['synthetic-cost']
            share['weights']['food']=3
            with self.assertRaises(ValueError):EbilanzBuilder().build(v,m,p,'12345')

if __name__=='__main__':unittest.main(verbosity=2)
