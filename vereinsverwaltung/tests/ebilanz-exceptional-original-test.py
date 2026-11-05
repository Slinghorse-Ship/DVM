"""Actual original MySQL papers: separate regular/AfaA and exact original failures."""
from original_manufacturer import original_manufacturer_id
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
APP=Path(__file__).resolve().parents[1];sys.path.insert(0,str(APP/'tools'))
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
from ebilanz_exceptional_original import calculate,validate_rule,TARGET
CASES=json.loads((APP/'tests/fixtures/exceptional-original-mysql-values.json').read_text(encoding='utf8'))
s=importlib.util.spec_from_file_location('original_exceptional_native_profile',APP/'tests/ebilanz-native.py');native=importlib.util.module_from_spec(s);s.loader.exec_module(native)
def inputs(v):
 _,p=native.inputs(v);m=automatic_mapping(v);p.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club');p['company']['legal_status']='EV'
 for key,row in v['tax']['accounts'].items():
  if row['skr_code'] in ('62320','62330','62660'):m[key]=TARGET
  if row['skr_code']=='62620':m[key]='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.lowValueAs'
  if row['group']=='liabilities':m[key]='bs.eqLiab.liab.other.other'
  if key=='equity':m[key]='bs.eqLiab.equity.SurplusOfUnspentFunds'
 return m,p
def original(row):return next(iter(row['values']['asset_schedule']['items'].values()))['exceptional']
class ExceptionalOriginal(unittest.TestCase):
 def test_actual_six_mysql_xml_amounts_unchanged(self):
  for row in CASES:
   with self.subTest(year=row['year'],code=row['code']):
    v=row['values'];before=copy.deepcopy(v);m,p=inputs(v);built=EbilanzBuilder().build(v,m,p,original_manufacturer_id());self.assertEqual(v,before);self.assertEqual(built['xml_sha256'],row['xml_sha256']);self.assertIn(TARGET,built['xml']);self.assertEqual(v['asset_schedule']['totals']['depreciation_cents'],2200);self.assertEqual(v['asset_schedule']['totals']['impairment_cents'],4000);self.assertEqual(v['asset_schedule']['totals']['book_closing_cents'],5800)
 def test_actual_source_calculation_php_python_parity(self):
  for row in CASES:
   v=original(row);self.assertEqual(calculate(v['source']['plan'],v['source']['proof']),v)
   r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(APP/'tests/fixtures/exceptional-original.php')],input=json.dumps(v['source']),capture_output=True,encoding='utf8',timeout=30)
   self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertFalse(r.stderr);self.assertEqual(json.loads(r.stdout),dict(passed=True,value=v))
 def test_original_boundaries_fail_in_both_languages(self):
  changes=[('proof','book_cents',-1),('proof','book_cents',12000),('proof','book_cents',40.0),('proof','tax_cents',3999),('proof','tax_reviewed',False),('proof','book_reviewed',1),('proof','year',2027),('proof','on','2025-06-30'),('proof','code','62310'),('proof','tax_reason',''),('plan','sphere','9'),('plan','method','degressive'),('plan','residual_cents',1),('plan','unsupported',True)]
  for side,key,value in changes:
   v=copy.deepcopy(original(CASES[0])['source']);v[side][key]=value
   with self.subTest(side=side,key=key,value=value):
    with self.assertRaises((ValueError,KeyError)):calculate(v['plan'],v['proof'])
    r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(APP/'tests/fixtures/exceptional-original.php')],input=json.dumps(v),capture_output=True,encoding='utf8',timeout=30);self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertFalse(json.loads(r.stdout)['passed'])
 def test_unknown_movements_and_tax_adjustments_do_not_export(self):
  for change in ('disposal','regular','tax','mapping'):
   v=copy.deepcopy(CASES[0]['values']);item=next(iter(v['asset_schedule']['items'].values()));m,p=inputs(v)
   if change=='disposal':item['disposal']={'kind':'sale'}
   elif change=='regular':item['depreciation_cents']+=1
   elif change=='tax':v['current_adjustments']=[{'lines':[]}]
   else:m[item['exceptional_accounts'][item['exceptional']['source']['proof']['code']]]='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'
   with self.subTest(change=change):
    with self.assertRaises(ValueError):EbilanzBuilder().build(v,m,p,original_manufacturer_id())
 def test_original_rule_cannot_change_group_target_or_contract(self):
  for code in ('62320','62330','62660'):
   rule=dict(group='expense',condition='detail_choice',target=TARGET,allowed_targets=[TARGET],exceptional_original_contract=code+'@1',codes=[code]);validate_rule(rule)
   for delta in ({'group':'revenue'},{'target':'is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'},{'exceptional_original_contract':'62310@1'},{'book_only':True}):
    with self.assertRaises(ValueError):validate_rule(rule|delta)
if __name__=='__main__':unittest.main(verbosity=2)
