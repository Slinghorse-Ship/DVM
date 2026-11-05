"""Original footnote11 classification keeps historic seals and excludes current postings."""
from original_manufacturer import original_manufacturer_id
import copy,json,sys,unittest
from pathlib import Path
from unittest.mock import patch
A=Path(__file__).resolve().parents[1];sys.path.insert(0,str(A/'tools'))
import datev_years as d
import datev_comparison as c
from ebilanz_mapping import automatic_mapping
from ebilanz_builder import EbilanzBuilder
class ComparisonTests(unittest.TestCase):
 def test_historic_edition_is_not_rewritten(self):
  model,_=d.read_json(A/'resources/accounting/purposes.json');data,raw=d.read_json(A/'data/skr42-2025.json');old,_=d.read_json(A/'resources/accounting/years/2025-r81.json');new,_=d.read_json(A/'resources/accounting/years/2025-r82.json')
  self.assertIn('41850',d.validate_snapshot(old,model,data,raw));self.assertNotIn('41850',d.validate_snapshot(new,model,data,raw))
  self.assertEqual(len(d.coverage(model,old,data)['missing']),14);self.assertEqual(len(d.coverage(model,new,data)['missing']),9)
  self.assertEqual(d.coverage(model,new,data)['bookable'],1683)
  previous=d.bindings(old);current=d.bindings(new);c.validate_revision(previous,current,new['provenance'],new['comparison_review'],data)
  for changed in (current|{'41850':previous['41850']},{k:v for k,v in current.items() if k!='18000'},current|{'62400':previous['18000']}):
   with self.assertRaises(ValueError):c.validate_revision(previous,changed,new['provenance'],new['comparison_review'],data)
  with self.assertRaises(ValueError):c.validate_revision(previous,current,new['provenance'],None,data)
 def test_every_comparison_code_rejects_explicit_current_mapping(self):
  for code in c.CODES:
   v=dict(year=2025,tax={'accounts':{'original':dict(skr_code=code,group='revenue' if code=='41850' or code.startswith('74') else 'expense',opening_cents=0,debit_cents=0,credit_cents=0,closing_cents=0,amount_cents=0)}})
   v['book']=copy.deepcopy(v['tax'])
   with self.subTest(code=code),self.assertRaisesRegex(ValueError,'nicht als Bilanzkonto buchbar'):automatic_mapping(v,{'original':'is.netIncome.regular.operatingTC.otherCost.miscellaneous'})
 def test_warmed_cache_does_not_hide_mutated_proof(self):
  data,_=d.read_json(A/'data/skr42-2025.json');c.review(data);original=Path.read_bytes
  def changed(p):
   raw=original(p)
   return raw+b' ' if p==A/c.PATH else raw
  with patch.object(Path,'read_bytes',changed),self.assertRaises(ValueError):c.review(data)
 def test_previous_native_xml_remains_exact(self):
  count=0
  for p in sorted((A/'tests/fixtures/special7g-r81').glob('*.json')):
   f=json.loads(p.read_text());count+=1;self.assertEqual(EbilanzBuilder().build(f['values'],f['mapping'],f['profile'],original_manufacturer_id())['xml_sha256'],f['xml_sha256'])
  self.assertEqual(count,4)
if __name__=='__main__':unittest.main()
