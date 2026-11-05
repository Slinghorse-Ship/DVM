"""Actual development journals: exact reversal pairs in PHP and Python."""
import copy,json,os,subprocess,sys,unittest
from pathlib import Path
APP=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(APP/'tools'))
from ebilanz_own_intangible_journals import verify
FIXTURE=APP/'tests/fixtures/own-intangible-r72/2025-development.json'

class OriginalDevelopmentJournals(unittest.TestCase):
 def setUp(self):self.case=json.loads(FIXTURE.read_text());self.proof=self.case['book_proof'];self.journal=self.case['journal']
 def php(self,p,j):
  script='require $argv[1]."/src/autoload.php"; $x=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);try{echo json_encode(Kulturhus\\Verein\\OwnIntangibleJournals::verify($x["proof"],$x["journal"]),JSON_THROW_ON_ERROR);}catch(DomainException $e){exit(42);}'
  run=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],'-r',script,str(APP)],input=json.dumps({'proof':p,'journal':j}),capture_output=True,encoding='utf8')
  self.assertIn(run.returncode,(0,42),run.stderr);self.assertEqual(run.stderr,'',run.stderr);return None if run.returncode==42 else json.loads(run.stdout)
 def bad(self,p,j):
  before=copy.deepcopy((p,j))
  with self.assertRaises((ValueError,TypeError,KeyError)):verify(p,j)
  self.assertIsNone(self.php(p,j));self.assertEqual((p,j),before)
 def test_both_actual_orders_preserve_the_entire_independently_replayed_proof(self):
  for reverse,key in [(False,'actual_debit_first_sha256'),(True,'original_order_sha256')]:
   with self.subTest(reverse=reverse):
    j=copy.deepcopy(self.journal)
    if reverse:j['tax_entries'][-1]['lines'].reverse()
    before=copy.deepcopy((self.proof,j));p=verify(self.proof,j)
    self.assertEqual(p['sha256'],self.case[key]);self.assertEqual(self.php(self.proof,j),p);self.assertEqual((self.proof,j),before)
    self.assertEqual(p['book_calculation']['annual_result_cents'],0);self.assertEqual(p['tax_calculation']['annual_result_cents'],-10000)
    self.assertEqual(p['book_calculation']['accounts'][j['account_key']]['closing_cents'],10000);self.assertEqual(p['tax_calculation']['accounts'][j['account_key']]['closing_cents'],0)
 def test_wrong_reversal_fields_cannot_pass_as_an_equivalent_pair(self):
  cases={
   'wrong_amount':lambda j:j['tax_entries'][-1]['lines'][0].__setitem__('debit_cents',9999),
   'wrong_account':lambda j:j['tax_entries'][-1]['lines'][0].__setitem__('account','equity'),
   'wrong_area':lambda j:j['tax_entries'][-1]['lines'][0].__setitem__('sphere','1'),
   'duplicate_line':lambda j:j['tax_entries'][-1]['lines'].__setitem__(1,copy.deepcopy(j['tax_entries'][-1]['lines'][0])),
   'third_line':lambda j:j['tax_entries'][-1]['lines'].append(copy.deepcopy(j['tax_entries'][-1]['lines'][0])),
   'missing_line':lambda j:j['tax_entries'][-1]['lines'].pop(),
   'wrong_day':lambda j:j['tax_entries'][-1].__setitem__('date','2025-12-30'),
   'wrong_pdf':lambda j:j['tax_entries'][-1]['document'].__setitem__('sha256','0'*64),
   'missing_pdf':lambda j:j['tax_entries'][-1].pop('document'),
   'foreign_event':lambda j:j['tax_links'].__setitem__(next(iter(j['tax_links'])),'0'*32),
   'missing_link':lambda j:j['tax_links'].clear(),
   'tax_link_array':lambda j:j['tax_links'].__setitem__(next(iter(j['tax_links'])),['not-a-scalar-event']),
   'book_link_array':lambda j:j['book_links'].__setitem__(next(iter(j['book_links'])),['not-a-scalar-event']),
   'numeric_tax_link':lambda j:j['tax_links'].__setitem__(next(iter(j['tax_links'])),1),
   'unconfirmed_fiscal':lambda j:j['fiscal_source'].__setitem__('confirmed',False),
   'unconfirmed_journal':lambda j:j.__setitem__('confirmed',False),
  }
  for name,change in cases.items():
   with self.subTest(name=name):
    j=copy.deepcopy(self.journal);change(j);self.bad(self.proof,j)
 def test_caller_cannot_change_original_book_or_independent_tax_values(self):
  for name,change in {
   'book_amount':lambda p,j:j['book_entries'][-1]['lines'][0].__setitem__('debit_cents',9999),
   'cost_ordinal':lambda p,j:p['source_proof']['source']['costs'][0].__setitem__('line_ordinal',1),
   'book_event':lambda p,j:p['book_source']['events'][0].__setitem__('amount_cents',9999),
   'tax_value':lambda p,j:j['fiscal_source'].__setitem__('closing_cents',10000),
   'wrong_year':lambda p,j:j.__setitem__('year',2026),
   'wrong_item':lambda p,j:j.__setitem__('item_id','0'*32),
  }.items():
   with self.subTest(name=name):
    p=copy.deepcopy(self.proof);j=copy.deepcopy(self.journal);change(p,j);self.bad(p,j)

if __name__=='__main__':unittest.main(verbosity=2)
