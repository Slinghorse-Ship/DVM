"""Retained original public MySQL measured depletion and exact accepted XML."""
from original_manufacturer import original_manufacturer_id
import copy,json,sys,unittest
from pathlib import Path
A=Path(__file__).resolve().parents[1];sys.path.insert(0,str(A/'tools'))
from ebilanz_substance_original import calculate,prepare,validate_rule,TARGET,EXPENSE,original_mapping
from ebilanz_builder import EbilanzBuilder
class SubstanceTests(unittest.TestCase):
 def fixtures(self):
  for p in sorted((A/'tests/fixtures/substance-r84').glob('*.json')):yield json.loads(p.read_text())
 def test_exact_native_xml(self):
  count=0
  for f in self.fixtures():
   count+=1;self.assertEqual(EbilanzBuilder().build(f['values'],f['mapping'],f['profile'],original_manufacturer_id())['xml_sha256'],f['xml_sha256'])
  self.assertEqual(count,4)
 def test_original_amount_and_rounding(self):
  for f in self.fixtures():
   v=f['values'];i=next(iter(v['asset_schedule']['items'].values()));x=i['substance'];p=x['source']['plan'];s=x['source']['proof'];self.assertEqual(calculate(p,s),x)
   self.assertGreaterEqual(x['closing_cents'],p['residual_cents'])
   q=copy.deepcopy(p);q['total_units']=3;self.assertEqual(calculate(q,s|{'used_units':1})['depreciation_cents'],3333)
   self.assertEqual(calculate(q,s|{'used_units':3})['closing_cents'],2000)
   for bad in (s|{'used_units':0},s|{'used_units':1001},s|{'book_reviewed':False},s|{'tax_reviewed':False},s|{'year':v['year']+1},s|{'used_units':True},s|{'unknown':1}):
    with self.assertRaises(ValueError):calculate(p,bad)
   for bad in (p|{'residual_cents':12000},p|{'total_units':0},p|{'total_units':1000001},p|{'method':'linear_monthly'},p|{'account':'02000'},p|{'months':60},p|{'estimate_reviewed':False},p|{'acquired_reviewed':False},p|{'unit':'years'},p|{'basis_cents':True},p|{'unknown':1}):
    with self.assertRaises(ValueError):calculate(bad,s)
 def test_actual_movements_scope_and_source_required(self):
  f=next(self.fixtures());v=f['values'];i=next(iter(v['asset_schedule']['items'].values()));key=i['account'];counter=i['substance_account'];self.assertEqual(original_mapping(v),{key:TARGET})
  mutations=[lambda q:q['asset_schedule']['items'].clear(),lambda q:q['asset_schedule']['items'][i['item_id']]['substance']['source']['proof'].__setitem__('used_units',201),lambda q:q['tax']['accounts'][key].__setitem__('credit_cents',1),lambda q:q['book']['accounts'][counter].__setitem__('debit_cents',1),lambda q:q.__setitem__('current_adjustments',[{}]),lambda q:q.__setitem__('opening_adjustments',[{}]),lambda q:q.__setitem__('period',{}),lambda q:q.__setitem__('asset_tax',{}),lambda q:q.__setitem__('special_tax_7g',{}),lambda q:q['whole_club_scope']['source'].__setitem__('previous_sha256','a'*64),lambda q:q['asset_schedule']['totals'].__setitem__('depreciation_cents',1)]
  from ebilanz_builder import EbilanzBuilder
  builder=EbilanzBuilder();meta=builder.meta
  for index,mutate in enumerate(mutations):
   q=copy.deepcopy(v);mutate(q)
   with self.subTest(case=index),self.assertRaises(ValueError):prepare(q,f['mapping'],f['profile'],meta)
  with self.assertRaises(ValueError):prepare(v,f['mapping']|{key:'bs.ass.fixAss.tan.landBuildings.buildingsOnOwnLand.land'},f['profile'],meta)
 def test_strict_rule(self):
  r=dict(substance_original_contract='02250@1',group='fixed_assets',condition='detail_choice',target=TARGET,allowed_targets=[TARGET],codes=['02250']);validate_rule(r)
  for q in (r|{'book_only':None},r|{'commercial_contract':None},r|{'codes':['02000']},r|{'condition':'equipment_schedule'}):
   with self.assertRaises(ValueError):validate_rule(q)
if __name__=='__main__':unittest.main()
