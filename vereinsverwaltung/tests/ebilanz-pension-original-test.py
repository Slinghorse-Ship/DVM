"""Original pension proofs: reviewed MySQL fixtures, fiscal/HGB boundaries and pair netting."""
from original_manufacturer import original_manufacturer_id
import copy,json,sys,unittest
from pathlib import Path
APP=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(APP/'tools'))
from ebilanz_pension_offsets import check_values,bind,target,SURPLUS,PENSION,OTHER,INTEREST
from ebilanz_club_scope import digest
from ebilanz_builder import EbilanzBuilder
FIXTURES=APP/'tests/fixtures/pension-r80'

class PensionOriginalTests(unittest.TestCase):
 def fixtures(self):
  for p in sorted(FIXTURES.glob('*.json')):yield json.loads(p.read_text(encoding='utf8'))
 def test_native_reviewed_xml_remains_exact(self):
  count=0
  for f in self.fixtures():
   count+=1;built=EbilanzBuilder().build(f['values'],f['mapping'],f['profile'],original_manufacturer_id())
   self.assertEqual(built['xml_sha256'],f['xml_sha256'])
  self.assertEqual(count,4)
 def test_individual_surplus_is_not_cancelled_against_other_obligation(self):
  count=0
  for f in self.fixtures():
   if 'whole' not in f['values']:continue
   count+=1;v=f['values']['whole'];proof=v['pension_offsets'];keys,facts,offset=check_values(v,f['mapping']['whole'],True)
   self.assertEqual(facts[SURPLUS],3000);self.assertEqual(facts[PENSION],2000);self.assertEqual(facts[OTHER],0);self.assertEqual(offset,18000)
   self.assertEqual(proof,bind(proof['source'],v['book'],False));self.assertEqual(v['book'],v['tax'])
  self.assertEqual(count,2)
 def test_mutated_fiscal_proof_and_gross_values_are_rejected(self):
  f=next(f for f in self.fixtures() if 'whole' not in f['values']);v=f['values'];m=f['mapping'];p=v['pension_offsets'];key=next(iter(p['original_accounts']));counter=p['source']['tax_positions'][key]['account']
  cases=[]
  q=copy.deepcopy(v);q.pop('pension_offsets');cases.append(q)
  q=copy.deepcopy(v);q['pension_offsets']['book_facts'][SURPLUS]+=1;cases.append(q)
  q=copy.deepcopy(v);q['pension_offsets']['source']['pairs'][0]['creditor_protected']=False;cases.append(q)
  q=copy.deepcopy(v);q['pension_offsets']['source']['pairs'].append(q['pension_offsets']['source']['pairs'][0]);cases.append(q)
  q=copy.deepcopy(v);q['pension_offsets']['source']['pairs'][0]['movements']=[];cases.append(q)
  q=copy.deepcopy(v);q['pension_offsets']['source']['tax_positions'][key]['closing_cents']+=1;cases.append(q)
  q=copy.deepcopy(v);q['tax']['accounts'][counter]['opening_cents']+=1;cases.append(q)
  q=copy.deepcopy(v);q['tax']['accounts'][key]['closing_cents']=1;cases.append(q)
  q=copy.deepcopy(v);q['pension_offsets']['source']['book_document']['sha256']='invalid';cases.append(q)
  q=copy.deepcopy(v);q['period']={'start':'2025-01-01','end':'2025-12-31'};cases.append(q)
  q=copy.deepcopy(v);q['whole_club_scope']['source']['previous_sha256']='a'*64;cases.append(q)
  for q in cases:
   q['sha256']=digest({k:x for k,x in q.items() if k not in ('sha256','transmission')})
   with self.subTest(case=cases.index(q) if q in cases else '?'),self.assertRaises(ValueError):check_values(q,m)
  bad=dict(m);bad[key]='bs.ass.currAss.cashEquiv.bank'
  with self.assertRaises(ValueError):check_values(v,bad)
 def test_original_turnover_with_zero_closing_still_needs_proof(self):
  from ebilanz_pension_offsets import active
  row=dict(skr_code='73640',opening_cents=0,debit_cents=100,credit_cents=100,closing_cents=0,amount_cents=0)
  self.assertIn('original',active({'original':row}))
if __name__=='__main__':unittest.main()
