"""Original whole books and separately reviewed cost tax values, including carry."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent;ROOT=HERE.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_builder import EbilanzBuilder,NS
from ebilanz_combined_club import assets_changed
from ebilanz_club_scope import digest
from ebilanz_mapping import automatic_mapping
import xml.etree.ElementTree as ET
spec=importlib.util.spec_from_file_location('original_cost_cases',HERE/'ebilanz-cost-assets-test.py');base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)

def fixtures(code,variant,mode='annual'):
    r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/ebilanz-cost-combined.php'),code,variant,mode],capture_output=True,encoding='utf8',timeout=60)
    if r.returncode or r.stderr:raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

def inputs(row):
    v=row['values'];whole=v.get('whole',v);m,p=base.base.inputs(whole)
    m={k:t for k,t in m.items() if k in whole['tax']['accounts']}
    m['revenue']='is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'
    m={k:t for k,t in m.items() if k in whole['tax']['accounts']}
    if 'whole' in v:
        maps={s:m|automatic_mapping(v[s]) for s in ('whole','taxable')}
        changed=assets_changed(v['taxable'])
        p.update(kind='tax_privileged_club_with_sgeb',source_scope='complete_club_with_approved_allocation',asset_scope='whole_and_taxable',
            whole_book_standard='HGBM',whole_business_name='Synthetic complete club',tax_values_sha256=v['sha256'],
            asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
    else:
        maps=m|automatic_mapping(v)
        p.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club',
            tax_values_sha256=v['sha256'],asset_values_unchanged=True,asset_tax_values_reviewed=False)
    return maps,p

def build(row):
    v=row['values'];m,p=inputs(row);b=EbilanzBuilder()
    if 'whole' in v:return b.build(v,m,p,'12345')
    root,balance,meta=b._build_tree(v,m,p,'12345',book_only=True)
    return b._serialize(root,balance,meta)

CONSTRUCTION_REVIEW=json.loads((ROOT/'resources/accounting/reviews/construction-r28.json').read_text())
REVIEWED_COST={'02150','02350','09200'}|{s['code'] for g in CONSTRUCTION_REVIEW['years'][0]['groups'] for s in g['sources']}
assert len(REVIEWED_COST)==16

def cases():
    for mode in ('annual','mixed','period'):
        for code in sorted(REVIEWED_COST):
            variants=['unchanged','impairment','tax-only','multiple','writeup','writeup-release']+([] if mode=='mixed' else ['fully'])+(['temporary'] if code=='09200' else [])
            for variant in variants:
                for row in fixtures(code,variant,mode):yield mode,code,variant,row

class OriginalCostBooks(unittest.TestCase):
    def test_whole_books_and_taxable_values_with_all_four_areas_and_two_businesses(self):
        count=0
        for mode,code,variant,row in cases():
            v=row['values'];w=v.get('whole',v);before=copy.deepcopy(v);result=build(row);count+=1
            self.assertEqual(v,before);self.assertEqual(w['book'],w['tax']);self.assertEqual(w['opening_adjustments'],[]);self.assertEqual(w['current_adjustments'],[])
            self.assertEqual(result['transmission'],'unavailable');ET.fromstring(result['xml'])
            if 'whole' in v:
                self.assertEqual(set(u['sphere'] for u in v['taxable']['club_scope']['allocation']['units'].values()),set('1234'))
                self.assertEqual(len([u for u in v['taxable']['club_scope']['allocation']['units'].values() if u['sphere']=='4']),2)
                self.assertNotEqual(w['book']['annual_result_cents'],v['taxable']['tax']['annual_result_cents'])
                self.assertEqual(result['asset_items'],2 if mode=='mixed' else 1)
        self.assertEqual(count,419)
    def test_book_section_cannot_be_promoted_to_public_taxable_whole(self):
        row=fixtures('09200','temporary','period')[0];m,p=inputs(row)
        with self.assertRaises(ValueError):EbilanzBuilder().build(row['values'],m,p,'12345')
        row=fixtures('09200','temporary')[0];m,p=inputs(row);w=row['values']['whole']
        _,p=base.inputs(w)
        with self.assertRaises(ValueError):EbilanzBuilder().build(w,m['whole'],p,'12345')
    def test_changed_cost_tax_values_require_explicit_review(self):
        row=fixtures('09200','temporary')[0];m,p=inputs(row);p.update(asset_values_unchanged=True,asset_tax_values_reviewed=False)
        with self.assertRaises(ValueError):EbilanzBuilder().build(row['values'],m,p,'12345')
    def test_resealed_original_book_tampering_is_rejected(self):
        for mode in ('annual','period'):
            row=fixtures('09200','temporary',mode)[0];v=row['values'];w=v.get('whole',v)
            w['asset_schedule']['items']['synthetic-cost']['valuation']['book']['book_closing_cents']+=1
            w['sha256']=digest({k:n for k,n in w.items() if k not in ('sha256','transmission')})
            if 'whole' in v:v['sha256']=digest({k:n for k,n in v.items() if k not in ('sha256','transmission')})
            with self.assertRaises(ValueError):build(row)

if __name__=='__main__':unittest.main(verbosity=2)
