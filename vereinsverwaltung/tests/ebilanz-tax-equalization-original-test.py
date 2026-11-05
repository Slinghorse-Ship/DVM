"""Retained original current fiscal result and separately approved capital display."""
from original_manufacturer import original_manufacturer_id
import copy,json,sys,unittest,xml.etree.ElementTree as ET
from decimal import Decimal
from pathlib import Path
A=Path(__file__).resolve().parents[1];sys.path.insert(0,str(A/'tools'))
from ebilanz_tax_equalization import bind,check,presentation,TARGET,FLAGS,validate_rule
from ebilanz_builder import EbilanzBuilder
class TaxEqualization(unittest.TestCase):
 def fixtures(self):
  f=[json.loads(p.read_text()) for p in sorted((A/'tests/fixtures/tax-equalization-r89').glob('*.json'))];self.assertEqual(len(f),4);return f
 def test_retained_native_xml_and_original_profit(self):
  for f in self.fixtures():
   v=f['values'];p=v['tax_equalization'];self.assertEqual(bind(v['whole_club_scope']['source'],p['source']),p)
   b=EbilanzBuilder().build(v,f['mapping'],f['profile'],original_manufacturer_id());self.assertEqual(b['xml_sha256'],f['xml_sha256'])
   facts={n.tag.split('}')[-1]:int(Decimal(n.text)*100) for n in ET.fromstring(b['xml']).iter() if n.tag.split('}')[-1] in (TARGET,'is.netIncome','bs.eqLiab.equity.netIncome','BVV.profitLoss','bs.eqLiab.equity.SurplusOfUnspentFunds') and n.text}
   self.assertEqual(facts[TARGET],p['current_result_difference_cents']);self.assertEqual(facts['is.netIncome'],p['fiscal_result_cents']);self.assertEqual(facts['BVV.profitLoss'],p['fiscal_result_cents']);self.assertEqual(facts['bs.eqLiab.equity.netIncome'],p['commercial_result_cents']);self.assertEqual(facts['bs.eqLiab.equity.SurplusOfUnspentFunds'],p['capital_book_amount_cents'])
 def test_original_proof_and_scope(self):
  for f in self.fixtures():
   v=f['values']
   for key in FLAGS:
    q=copy.deepcopy(v);q['tax_equalization']['source'][key]=False
    with self.assertRaises(ValueError):check(q)
   for key in ('period','club_scope','asset_tax','special_tax_7g','special_tax_7b','basis_reduction_7g','basis_reduction_6b','pension_offsets'):
    q=copy.deepcopy(v);q[key]={}
    with self.assertRaises(ValueError):check(q)
   q=copy.deepcopy(v);q.pop('tax_equalization')
   with self.assertRaises(ValueError):check(q)
 def test_actual_five_account_fields_and_exact_vouchers(self):
  for f in self.fixtures():
   v=f['values']
   for key in ('expense','provision','equalization','equity'):
    for field in ('opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'):
     q=copy.deepcopy(v);q['tax']['accounts'][key][field]+=1
     with self.assertRaises(ValueError):check(q)
   q=copy.deepcopy(v);q['current_adjustments'].reverse()
   with self.assertRaises(ValueError):check(q)
   for key in ('commercial_result_cents','fiscal_result_cents','current_result_difference_cents','capital_book_amount_cents'):
    q=copy.deepcopy(v);q['tax_equalization'][key]+=1
    with self.assertRaises(ValueError):check(q)
 def test_exact_rule_and_mapping(self):
  r=dict(tax_equalization_contract='77690@1',group='result_use',target=TARGET,allowed_targets=[TARGET],condition='detail_choice',codes=['77690']);validate_rule(r)
  for q in (r|{'codes':[]},r|{'book_only':False},r|{'commercial_contract':None},r|{'group':'expense'},r|{'target':TARGET.replace('transferDiffTaxAccounts','changePresentYear')}):
   with self.assertRaises(ValueError):validate_rule(q)
  for f in self.fixtures():
   for key in ('equalization','equity'):
    with self.assertRaises(ValueError):presentation(f['values'],f['mapping']|{key:'bs.ass.currAss.cashEquiv.bank'})
   with self.assertRaises(ValueError):presentation(f['values'],f['mapping'],True)
if __name__=='__main__':unittest.main()
