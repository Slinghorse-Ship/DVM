"""Retained native MySQL basis reductions: independent IAB, adjustments and XML."""
from original_manufacturer import original_manufacturer_id
import copy,json,sys,unittest
from pathlib import Path
A=Path(__file__).resolve().parents[1];sys.path.insert(0,str(A/'tools'))
from ebilanz_basis_reduction_7g import bind,check,validate_rule,TARGET,active,FLAGS
from ebilanz_builder import EbilanzBuilder
class Basis7gTests(unittest.TestCase):
 def fixtures(self):
  for p in sorted((A/'tests/fixtures/basis7g-r83').glob('*.json')):yield json.loads(p.read_text())
 def test_retained_native_xml(self):
  count=0
  for f in self.fixtures():
   count+=1;self.assertEqual(EbilanzBuilder().build(f['values'],f['mapping'],f['profile'],original_manufacturer_id())['xml_sha256'],f['xml_sha256'])
  self.assertEqual(count,4)
 def test_invalid_rule_contracts(self):
  r=dict(basis_reduction_7g_contract='62430@1',codes=['62430'],group='expense',condition='detail_choice',target=TARGET,allowed_targets=[TARGET]);validate_rule(r)
  for q in (r|{'book_only':None},r|{'commercial_contract':None},r|{'allowed_targets':[]},r|{'codes':['62440']}):
   with self.assertRaises(ValueError):validate_rule(q)
 def test_original_source_actual_adjustment_and_regular_recalculation(self):
  for f in self.fixtures():
   v=f['values'];p=v['basis_reduction_7g'];s=p['source'];m=f['mapping'];self.assertEqual(bind(v['whole_club_scope']['source'],s),p)
   mutations=[lambda q:q.pop('basis_reduction_7g'),lambda q:q['basis_reduction_7g']['source'].__setitem__('claimed_cents',20000001),lambda q:q['basis_reduction_7g']['source'].__setitem__('available_cents',1),lambda q:q['basis_reduction_7g']['source'].__setitem__('addition_cents',6001),lambda q:q['basis_reduction_7g']['source'].__setitem__('iab_year',v['year']),lambda q:q['basis_reduction_7g']['source'].__setitem__('iab_year',v['year']-4),lambda q:q['basis_reduction_7g'].__setitem__('tax_regular_cents',1),lambda q:q['tax']['accounts'][s['reduction_account']].__setitem__('debit_cents',0),lambda q:q['tax']['accounts'][s['regular_account']].__setitem__('credit_cents',0),lambda q:q['tax']['accounts'][p['asset_account']].__setitem__('debit_cents',0),lambda q:q['book']['accounts'][s['reduction_account']].__setitem__('credit_cents',1),lambda q:q.__setitem__('period',{}),lambda q:q.__setitem__('opening_adjustments',[{}]),lambda q:q.__setitem__('special_tax_7g',{})]
   mutations += [lambda q,k=k:q['basis_reduction_7g']['source'].__setitem__(k,False) for k in FLAGS]
   for i,mutate in enumerate(mutations):
    q=copy.deepcopy(v);mutate(q)
    with self.subTest(year=v['year'],code=s['reduction_account'],case=i),self.assertRaises(ValueError):check(q,m)
   with self.assertRaises(ValueError):check(v,m|{s['reduction_account']:'bs.ass.currAss.cashEquiv.bank'})
   with self.assertRaises(ValueError):check(v,m,True)
 def test_zero_closing_with_turnover_requires_source(self):
  self.assertIn('basis',active({'basis':dict(skr_code='62430',opening_cents=0,debit_cents=100,credit_cents=100,closing_cents=0,amount_cents=0)}))
if __name__=='__main__':unittest.main()
