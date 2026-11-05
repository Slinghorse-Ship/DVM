"""Original tax-exempt sale classification remains attached to invoice and disposal."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('export48_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
shared=load('ebilanz-operating-extended-test.py');base=load('ebilanz-native.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
from ebilanz_sales import sale_values,sale_codes,GAIN_SALE,GAIN_BOOK,LOSS_SALE,LOSS_BOOK
@lru_cache(maxsize=50)
def fixture(year,kind,variant,whole):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-export-sales-r48.php'),str(year),kind,variant,'whole' if whole else 'single'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def cases():
 for year in (2025,2026):
  for kind in ('export_4_1a','intra_eu_4_1b'):
   for variant in ('gain','loss','cross','cents'):
    for whole in (False,True):yield {'year':year,'vat_kind':kind,'variant':variant,'whole':whole,'values':fixture(year,kind,variant,whole)}
def inputs(row):
 v=row['values'];m,p=base.inputs(v)
 if row['whole']:p.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club')
 return m,p
class ExportSales(unittest.TestCase):
 def test_original_invoice_books_tax_and_both_classifications(self):
  seen=set()
  for row in cases():
   with self.subTest(year=row['year'],kind=row['vat_kind'],variant=row['variant'],whole=row['whole']):
    v=row['values'];before=copy.deepcopy(v);m,p=inputs(row);package=EbilanzBuilder().build(v,m,p,'12345');facts=shared.facts(package,row['whole']);sale=v['asset_tax']['items']['asset-0']['sale'];self.assertEqual(v,before)
    self.assertEqual(v['asset_schedule']['items']['asset-0']['disposal']['vat_kind'],row['vat_kind']);self.assertEqual(sale['book']['tax_cents'],0);self.assertEqual(sale['tax']['tax_cents'],0);self.assertEqual(sale['book']['net_cents'],sale['tax']['net_cents']);self.assertEqual(facts['is.netIncome'],shared.money(v['tax']['annual_result_cents']))
    for side in ('book','tax'):
     seen.add(sale[side]['sale_code']);self.assertIn(sale[side]['sale_code'],sale_codes({'rate':0,'vat_kind':row['vat_kind']}))
    auto=automatic_mapping(v);self.assertEqual(auto['sale-proceeds'],GAIN_SALE if sale['book']['gain'] else LOSS_SALE)
    tax_key=next(k for k,a in v['tax']['accounts'].items() if a['skr_code']==sale['tax']['sale_code']);self.assertEqual(auto[tax_key],GAIN_SALE if sale['tax']['gain'] else LOSS_SALE)
    self.assertEqual(facts[GAIN_SALE if sale['tax']['gain'] else LOSS_SALE],shared.money(sale['tax']['net_cents']))
    self.assertEqual(package['asset_items'],1)
  self.assertEqual(seen,{'48440','48480','68840','68880'})
 def test_unknown_vat_class_rate_or_missing_source_rejected(self):
  row=next(cases());v=row['values'];item=v['asset_schedule']['items']['asset-0'];proof=item['disposal']
  for bad in ({'vat_kind':None},{'vat_kind':'invented'},{'vat_kind':'export_4_1a','rate':19},{'vat_reason':''}):
   with self.assertRaises(ValueError):sale_values(proof|bad,100)
  for source in ('vat_kind','disposal'):
   bad=copy.deepcopy(v)
   if source=='vat_kind':bad['asset_schedule']['items']['asset-0']['disposal']['vat_kind']='intra_eu_4_1b'
   else:del bad['asset_schedule']['items']['asset-0']['disposal']
   m,p=inputs(row|{'values':bad})
   with self.assertRaises(ValueError):EbilanzBuilder().build(bad,m,p,'12345')
 def test_prior_source_catalog_bindings_remain_immutable(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r47.json");a=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);sealed,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r48.json");b=shared.d.validate_snapshot(sealed,model,*catalogs[e['year']]);latest=shared.d.validate_snapshot(e,model,*catalogs[e['year']]);self.assertEqual({c:latest[c] for c in b},b);self.assertEqual({c:b[c] for c in a},a);self.assertEqual(set(b)-set(a),{'48440','48480','68840','68880'})
if __name__=='__main__':unittest.main(verbosity=2)
