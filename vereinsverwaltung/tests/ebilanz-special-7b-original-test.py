"""Retained public MySQL7b, two independent depreciation values and original XML."""
from original_manufacturer import original_manufacturer_id
import copy,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
A=Path(__file__).resolve().parents[1];sys.path.insert(0,str(A/'tools'))
from ebilanz_special_tax_7b import amounts,bind,check,validate_rule,TARGET,FLAGS,DOCUMENTS
from ebilanz_builder import EbilanzBuilder

class Special7bTests(unittest.TestCase):
 def fixtures(self):
  rows=[json.loads(p.read_text()) for p in sorted((A/'tests/fixtures/special-7b-r85').glob('*.json'))];self.assertEqual(len(rows),4);return rows
 def php(self,op,value):
  source="""<?php declare(strict_types=1);$app=__APP__;require_once $app.'/src/autoload.php';set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);try{$value=match($input['op']){'amounts'=>Kulturhus\\Verein\\OriginalSpecialTax7b::amounts(...$input['value']),'bind'=>Kulturhus\\Verein\\OriginalSpecialTax7b::bind(...$input['value']),'check'=>Kulturhus\\Verein\\OriginalSpecialTax7b::check($input['value']),'rule'=>Kulturhus\\Verein\\OriginalSpecialTax7b::rule($input['value'])};echo json_encode(['accepted'=>true,'value'=>$value],JSON_THROW_ON_ERROR);}catch(DomainException){echo json_encode(['accepted'=>false]);}""".replace('__APP__',"'"+A.as_posix().replace("'","\\'")+"'")
  with tempfile.TemporaryDirectory(prefix='original7b-parity-') as d:
   p=Path(d)/'proof.php';p.write_text(source,encoding='utf8');r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(p)],input=json.dumps(dict(op=op,value=value)),capture_output=True,encoding='utf8',timeout=30);self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertFalse(r.stderr,r.stderr);return json.loads(r.stdout)
 def test_exact_original_native_xml(self):
  for f in self.fixtures():self.assertEqual(EbilanzBuilder().build(f['values'],f['mapping'],f['profile'],original_manufacturer_id())['xml_sha256'],f['xml_sha256'])
 def test_basis_cap_months_cent_rounding_and_php_parity(self):
  for args in ([12_000_000,3000,600_000,'2025-02-15'],[12_000_000,2500,500_000,'2026-02-15'],[520_000,100,20_000,'2025-12-31'],[400_001,100,20_000,'2026-01-01'],[101,100,5,'2025-01-01']):
   result=amounts(*args);self.assertEqual(self.php('amounts',args),dict(accepted=True,value=result))
  self.assertEqual(amounts(12_000_000,2500,500_000,'2026-02-15')['special_basis_cents'],10_000_000)
  self.assertEqual(amounts(12_000_000,3000,600_000,'2025-02-15')['tax_regular_cents'],330_000)
  self.assertEqual(amounts(520_000,100,20_000,'2025-12-31')['tax_regular_cents'],1300)
  for args in ([12_000_000,3000,600_001,'2025-02-15'],[12_000_000,2500,500_001,'2026-02-15'],[520_001,100,1,'2025-01-01'],[True,100,1,'2025-01-01'],[100000,True,1,'2025-01-01'],[100000,100,True,'2025-01-01'],[100000,0,1,'2025-01-01'],[100000,100,0,'2025-01-01'],[100000,100,1,'2025-02-29'],[100000,100,1,None],[100000,100,1,'20250101']):
   with self.subTest(args=args),self.assertRaises(ValueError):amounts(*args)
   self.assertEqual(self.php('amounts',args),dict(accepted=False))
 def test_original_eligibility_and_php_source_parity(self):
  f=self.fixtures()[0];v=f['values'];p=v['special_tax_7b'];r=v['whole_club_scope']['source'];s=p['source'];self.assertEqual(bind(r,s),p);self.assertEqual(self.php('bind',[r,s]),dict(accepted=True,value=p))
  variants=[s|{flag:False} for flag in FLAGS]+[s|{'application_on':'2022-12-31'},s|{'application_on':'2029-10-01'},s|{'completed_on':'2024-12-31'},s|{'completed_on':'2025-03-01'},s|{'area_hundredths':2307},s|{'aid_value_cents':0},s|{'aid_value_cents':True},s|{'other_aid_cents':29_900_001},s|{'special_cents':600_001},s|{'unknown':1}]
  for bad in variants:
   with self.subTest(bad=bad),self.assertRaises(ValueError):bind(r,bad)
   self.assertEqual(self.php('bind',[r,bad]),dict(accepted=False))
  for mutate in (lambda q:q.__setitem__('previous_sha256','a'*64),lambda q:q.__setitem__('period',{}),lambda q:q['asset_schedule']['items'][s['item_id']].__setitem__('sphere','2'),lambda q:q['asset_schedule']['items'][s['item_id']].__setitem__('method','cost'),lambda q:q['asset_schedule']['items'][s['item_id']].__setitem__('skr_code','06300')):
   bad=copy.deepcopy(r);mutate(bad)
   with self.assertRaises(ValueError):bind(bad,s)
   self.assertEqual(self.php('bind',[bad,s]),dict(accepted=False))
 def test_actual_tax_fields_and_exclusive_scope(self):
  f=self.fixtures()[0];v=f['values'];s=v['special_tax_7b']['source'];p=v['special_tax_7b'];check(v,f['mapping']);self.assertEqual(self.php('check',v),dict(accepted=True,value=None))
  for account in (s['special_account'],s['regular_account'],p['asset_account']):
   for field in ('opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'):
    bad=copy.deepcopy(v);bad['tax']['accounts'][account][field]+=1
    with self.assertRaises(ValueError):check(bad,f['mapping'])
    self.assertEqual(self.php('check',bad),dict(accepted=False))
  for key in ('asset_tax','special_tax_7g','basis_reduction_7g','period','club_scope'):
   bad=copy.deepcopy(v);bad[key]={}
   with self.assertRaises(ValueError):check(bad,f['mapping'])
   self.assertEqual(self.php('check',bad),dict(accepted=False))
  with self.assertRaises(ValueError):check(v,f['mapping'],True)
  with self.assertRaises(ValueError):check(v,f['mapping']|{s['special_account']:'is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'})
 def test_strict_rule(self):
  rule=dict(special_tax_7b_contract='62450@1',group='expense',condition='detail_choice',target=TARGET,allowed_targets=[TARGET],codes=['62450']);validate_rule(rule);self.assertEqual(self.php('rule',rule),dict(accepted=True,value=None))
  for bad in (rule|{'codes':None},rule|{'codes':['62410']},rule|{'condition':'equipment_schedule'},rule|{'book_only':None},rule|{'commercial_contract':None}):
   with self.assertRaises(ValueError):validate_rule(bad)
   self.assertEqual(self.php('rule',bad),dict(accepted=False))
if __name__=='__main__':unittest.main()
