"""Recorded KOST1 values survive real input, export and sphere aggregation."""
import json,os,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class Kost1Spheres(unittest.TestCase):
 def test_recorded_positions_csv_reversals_and_parent_aggregation(self):
  php=os.environ.get('VEREIN_FIXTURE_PHP','php')
  p=subprocess.run([php,str(ROOT/'tests/fixtures/kost1-spheres.php')],capture_output=True,encoding='utf8',check=True)
  self.assertEqual(p.stderr,'');proof=json.loads(p.stdout)
  self.assertTrue(proof['passed']);self.assertFalse(proof['database_connected'])
  self.assertEqual(proof['cost_centers'],41);self.assertEqual(proof['normalized_positions'],82)
  self.assertEqual(proof['outgoing_positions'],41);self.assertEqual(proof['csv_rows'],164);self.assertEqual(proof['annual_rows'],164)
if __name__=='__main__':unittest.main(verbosity=2)
