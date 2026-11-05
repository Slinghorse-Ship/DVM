"""Retained original MySQL7g(5) proofs: source, separate tax election and exact XML."""
from original_manufacturer import original_manufacturer_id
import copy,json,sys,unittest
from pathlib import Path
A=Path(__file__).resolve().parents[1];sys.path.insert(0,str(A/'tools'))
from ebilanz_special_tax_7g import bind,check,validate_rule,TARGET,active
from ebilanz_builder import EbilanzBuilder
class Special7gTests(unittest.TestCase):
 def fixtures(self):
  for p in sorted((A/'tests/fixtures/special7g-r81').glob('*.json')):yield json.loads(p.read_text())
 def test_native_xml_is_unchanged(self):
  count=0
  for f in self.fixtures():
   count+=1;self.assertEqual(EbilanzBuilder().build(f['values'],f['mapping'],f['profile'],original_manufacturer_id())['xml_sha256'],f['xml_sha256'])
  self.assertEqual(count,4)
 def test_invalid_rule_contracts(self):
  r=dict(special_tax_7g_contract='62410@1',codes=['62410'],group='expense',condition='detail_choice',target=TARGET,allowed_targets=[TARGET]);validate_rule(r)
  for q in (r|{'book_only':None},r|{'commercial_contract':None},r|{'allowed_targets':[]},r|{'codes':['62420']}):
   with self.assertRaises(ValueError):validate_rule(q)
 def test_original_proof_and_actual_tax_values_are_required(self):
  f=next(self.fixtures());v=f['values'];p=v['special_tax_7g'];s=p['source'];m=f['mapping'];self.assertEqual(bind(v['whole_club_scope']['source'],s),p)
  mutations=[lambda q:q.pop('special_tax_7g'),lambda q:q['special_tax_7g']['source'].__setitem__('previous_profit_cents',20000001),lambda q:q['special_tax_7g']['source'].__setitem__('special_cents',4801),lambda q:q['special_tax_7g']['source'].__setitem__('amount_reviewed',False),lambda q:q['special_tax_7g'].__setitem__('tax_closing_cents',1),lambda q:q['tax']['accounts'][s['special_account']].__setitem__('debit_cents',0),lambda q:q['tax']['accounts'][s['regular_account']].__setitem__('credit_cents',1),lambda q:q['tax']['accounts'][p['asset_account']].__setitem__('credit_cents',1),lambda q:q['book']['accounts'][s['special_account']].__setitem__('credit_cents',1),lambda q:q.__setitem__('period',{}),lambda q:q.__setitem__('opening_adjustments',[{}])]
  for i,mutate in enumerate(mutations):
   q=copy.deepcopy(v);mutate(q)
   with self.subTest(case=i),self.assertRaises(ValueError):check(q,m)
  with self.assertRaises(ValueError):check(v,m|{s['special_account']:'bs.ass.currAss.cashEquiv.bank'})
  with self.assertRaises(ValueError):check(v,m,True)
 def test_zero_closing_with_turnover_is_active(self):
  row=dict(skr_code='62410',opening_cents=0,debit_cents=100,credit_cents=100,closing_cents=0,amount_cents=0)
  self.assertIn('special',active({'special':row}))
if __name__=='__main__':unittest.main()
