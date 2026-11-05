"""Retained MySQL originals exercise the actual PHP service and the former XML bypass."""
from original_manufacturer import original_manufacturer_id
import copy,json,os,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_builder import EbilanzBuilder
FIXTURE=json.loads((ROOT/'tests/fixtures/ebilanz-leasing-scope.json').read_text(encoding='utf8'))

def php(row,different):
    p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-leasing-scope.php')],input=json.dumps(dict(original=row['original'],different=different)),capture_output=True,encoding='utf8',timeout=60)
    if p.returncode or p.stderr:raise RuntimeError(p.stdout+p.stderr)
    return json.loads(p.stdout)

class LeasingScope(unittest.TestCase):
    def test_original_mysql_values_replay_unchanged_through_php_and_xml(self):
        self.assertTrue(FIXTURE['synthetic_only'])
        for row in FIXTURE['rows']:
            with self.subTest(year=row['year']):
                result=php(row,False)
                self.assertTrue(result['accepted']);self.assertTrue(result['original_unchanged'])
                self.assertEqual(result['values'],row['original'])
                before=copy.deepcopy(row['original']);profile=dict(row['profile'],asset_values_unchanged=True,tax_values_sha256=row['original']['sha256'])
                xml=EbilanzBuilder().build(row['original'],row['mapping'],profile,original_manufacturer_id())
                self.assertIn('is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other',xml['xml'])
                self.assertEqual(row['original'],before)
    def test_actual_php_service_refuses_a_confirmed_different_leasing_tax_life(self):
        for row in FIXTURE['rows']:
            with self.subTest(year=row['year']):
                result=php(row,True)
                self.assertFalse(result['accepted']);self.assertTrue(result['original_unchanged'])
                self.assertIn('Kaufleasing',result['error'])
    def test_retained_former_xml_counterexamples_are_rejected_without_mutation(self):
        for row in FIXTURE['rows']:
            with self.subTest(year=row['year']):
                self.assertNotEqual(row['different']['book'],row['different']['tax'])
                before=copy.deepcopy(row['different'])
                with self.assertRaisesRegex(ValueError,'Kaufleasing'):
                    EbilanzBuilder().build(row['different'],row['mapping'],row['profile'],original_manufacturer_id())
                self.assertEqual(row['different'],before)

if __name__=='__main__':unittest.main(verbosity=2)
