"""Retained original6b reserve transfer, actual fiscal postings and native XML."""
from original_manufacturer import original_manufacturer_id
import copy,json,sys,unittest
from pathlib import Path
A=Path(__file__).resolve().parents[1];sys.path.insert(0,str(A/'tools'))
from ebilanz_basis_reduction_6b import bind,check,FLAGS,TARGET,validate_rule
from ebilanz_builder import EbilanzBuilder
class Original6b(unittest.TestCase):
 def fixtures(self):return [json.loads(p.read_text()) for p in sorted((A/'tests/fixtures/basis6b-r88').glob('*.json'))]
 def test_retained_native_xml_and_original_calculation(self):
  fixtures=self.fixtures();self.assertEqual(len(fixtures),4)
  for f in fixtures:
   v=f['values'];p=v['basis_reduction_6b'];self.assertEqual(bind(v['whole_club_scope']['source'],p['source']),p);self.assertEqual(p['original_gain_cents'],14000000)
   self.assertEqual(EbilanzBuilder().build(v,f['mapping'],f['profile'],original_manufacturer_id())['xml_sha256'],f['xml_sha256']);self.assertEqual(v['book']['accounts'][p['source']['regular_account']]['amount_cents'],220000)
 def test_original_claim_and_scope_cannot_be_replaced(self):
  for f in self.fixtures():
   v=f['values'];changes=[('reserve_year',v['year']),('sold_kind','financial'),('held_since',str(v['year']-6)+'-02-16'),('sold_on',str(v['year']-1)+'-02-30'),('formed_cents',14000001),('available_cents',1),('building_completed_on','2023-01-01'),('journal_sha256','0'*64)]
   changes.extend((k,False) for k in FLAGS)
   for key,value in changes:
    q=copy.deepcopy(v);q['basis_reduction_6b']['source'][key]=value
    with self.subTest(key=key),self.assertRaises(ValueError):check(q,f['mapping'])
   for key in ('basis_reduction_6b','whole_club_scope'):
    q=copy.deepcopy(v);q.pop(key)
    with self.assertRaises(ValueError):check(q,f['mapping'])
   for key in ('period','club_scope','asset_tax','special_tax_7b','special_tax_7g','basis_reduction_7g'):
    q=copy.deepcopy(v);q[key]={}
    with self.assertRaises(ValueError):check(q,f['mapping'])
 def test_actual_reserve_opening_release_reduction_and_regular_fields(self):
  for f in self.fixtures():
   v=f['values'];p=v['basis_reduction_6b'];s=p['source'];keys=[p['asset_account']]+[s[k] for k in ('regular_account','reduction_account','reserve_account','release_account','capital_account')]
   for key in keys:
    for field in ('opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'):
     q=copy.deepcopy(v);q['tax']['accounts'][key][field]+=1
     with self.subTest(key=key,field=field),self.assertRaises(ValueError):check(q,f['mapping'])
   for key in ('opening_adjustments','current_adjustments'):
    q=copy.deepcopy(v);q[key]=[]
    with self.assertRaises(ValueError):check(q,f['mapping'])
   for key in ('tax_basis_cents','tax_regular_cents','tax_closing_cents','reserve_closing_cents','original_gain_cents'):
    q=copy.deepcopy(v);q['basis_reduction_6b'][key]+=1
    with self.assertRaises(ValueError):check(q,f['mapping'])
   for key in ('reduction_account','reserve_account','release_account'):
    with self.assertRaises(ValueError):check(v,f['mapping']|{s[key]:'bs.ass.currAss.cashEquiv.bank'})
   with self.assertRaises(ValueError):check(v,f['mapping'],True)
 def test_exact_rule_contract(self):
  r=dict(basis_reduction_6b_contract='62490@1',group='expense',condition='detail_choice',target=TARGET,allowed_targets=[TARGET],codes=['62490']);validate_rule(r)
  for q in (r|{'codes':None},r|{'book_only':False},r|{'commercial_contract':None},r|{'allowed_targets':[]}):
   with self.assertRaises(ValueError):validate_rule(q)
if __name__=='__main__':unittest.main()
