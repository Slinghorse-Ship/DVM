"""Offline inventory check; listing all tests is not a fiscal release approval.

MySQL entry points require an isolated fixture. This inventory never launches
tests or loads production configuration, credentials or transport adapters.
"""
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent


class BalanceSuiteInventory(unittest.TestCase):
    def test_explicit_inventory_covers_every_accounting_entry_point(self):
        manifest = json.loads((ROOT / 'balance-suite.json').read_text(encoding='utf8'))
        self.assertEqual(manifest['schema'], 1)
        self.assertEqual(manifest['scope'], 'accounting-and-annual-skr42')
        php = sorted(p.name for p in ROOT.iterdir() if p.is_file() and
                     (re.fullmatch(r'(balance-|asset-|catalog)[a-z0-9-]*\.php', p.name)
                      or p.name in ('tax-runtime-fingerprint.php','datev-statements.php')))
        python = sorted(p.name for p in ROOT.iterdir() if p.is_file() and
                        (re.fullmatch(r'ebilanz-[a-z0-9-]+-test\.py', p.name)
                         or p.name in ('datev-years-test.py', 'datev-sources-test.py', 'datev-table-test.py','datev-statements-test.py','datev-web-test.py')))
        for key, expected in [('mysql', php), ('offline_python', python)]:
            with self.subTest(group=key):
                actual = manifest[key]
                self.assertEqual(actual, expected, 'New or removed tests must update the explicit suite.')
                self.assertEqual(actual, sorted(set(actual)), 'No duplicates or order-dependent selection.')
                for name in actual:
                    self.assertRegex(name, r'^[a-z0-9-]+\.(php|py)$')
                    self.assertFalse((ROOT / name).is_symlink())
        self.assertGreater(len(php), 150)
        self.assertGreater(len(python), 35)


if __name__ == '__main__':
    unittest.main(verbosity=2)
