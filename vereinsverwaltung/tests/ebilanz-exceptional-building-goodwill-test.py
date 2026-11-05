"""Original MySQL fixtures: acquired goodwill and buildings, no guessed tax election."""
from original_manufacturer import original_manufacturer_id
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
APP=Path(__file__).resolve().parents[1];sys.path.insert(0,str(APP/'tools'))
import datev_years
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
from ebilanz_exceptional_original import calculate,expense_target
s=importlib.util.spec_from_file_location('building_goodwill_profile',APP/'tests/ebilanz-native.py');native=importlib.util.module_from_spec(s);s.loader.exec_module(native)
CASES=json.loads((APP/'tests/fixtures/exceptional-building-goodwill-mysql-values.json').read_text(encoding='utf8'))
class BuildingGoodwill(unittest.TestCase):
 def php(self,source):
  p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(APP/'tests/fixtures/exceptional-original.php')],input=json.dumps(source),capture_output=True,encoding='utf8',timeout=30)
  self.assertEqual(p.returncode,0,p.stdout+p.stderr);self.assertFalse(p.stderr);return json.loads(p.stdout)
 def test_actual_four_xml_outputs_and_php_python_agree(self):
  for row in CASES:
   with self.subTest(year=row['year'],code=row['code']):
    v=row['values'];original=copy.deepcopy(v);_,p=native.inputs(v);p.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club');p['company']['legal_status']='EV';m=automatic_mapping(v)
    for key,account in v['tax']['accounts'].items():
     if account['skr_code'] in ('62090','62310'):m[key]=expense_target(account['skr_code'])
     if account['group']=='liabilities':m[key]='bs.eqLiab.liab.other.other'
     if key=='equity':m[key]='bs.eqLiab.equity.SurplusOfUnspentFunds'
    built=EbilanzBuilder().build(v,m,p,original_manufacturer_id());self.assertEqual(built['xml_sha256'],row['xml_sha256']);self.assertEqual(v,original)
    item=next(iter(v['asset_schedule']['items'].values()));proof=item['exceptional'];self.assertEqual(calculate(**proof['source']),proof);self.assertEqual(self.php(proof['source']),{'passed':True,'value':proof})
    self.assertEqual(item['depreciation_cents'],733 if row['code']=='62090' else 220);self.assertEqual(item['impairment_cents'],4000)
 def test_wrong_asset_kind_life_and_tax_evidence_rejected_in_both_languages(self):
  for row in CASES:
   source=next(iter(row['values']['asset_schedule']['items'].values()))['exceptional']['source']
   changes=[('proof','tax_cents',3999),('proof','tax_reviewed',False),('proof','book_cents',12000),('plan','account','03100'),('proof','code','62330')]
   if row['code']=='62090':changes.append(('plan','months',120))
   for side,key,value in changes:
    with self.subTest(year=row['year'],code=row['code'],side=side,key=key):
     modified=copy.deepcopy(source);modified[side][key]=value
     with self.assertRaises(ValueError):calculate(**modified)
     self.assertFalse(self.php(modified)['passed'])
if __name__=='__main__':unittest.main(verbosity=2)
