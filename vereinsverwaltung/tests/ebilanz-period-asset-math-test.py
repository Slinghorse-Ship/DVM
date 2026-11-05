"""Independent full-month PHP/Python arithmetic parity, including cent boundaries."""
import importlib.util
import copy
import json
import os
from pathlib import Path
import subprocess
import unittest

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('period_base',HERE/'ebilanz-period-test.py')
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
from ebilanz_period_asset_math import schedule, partial, reconcile

class AssetArithmetic(unittest.TestCase):
    def test_independent_exact_arithmetic_grid(self):
        php=Path(os.environ['VEREIN_FIXTURE_PHP'])
        self.assertTrue(php.is_absolute() and php.is_file())
        result=subprocess.run([str(php),str(HERE/'fixtures/ebilanz-period-asset-math.php')],capture_output=True,text=True,encoding='utf8',timeout=30)
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertEqual(result.stderr,'')
        rows=json.loads(result.stdout);self.assertEqual(len(rows),585)
        for row in rows:
            with self.subTest(method=row['plan']['method'],basis=row['plan']['basis_cents'],life=row['plan']['months'],period=row['period']):
                actual=schedule(row['plan'],row['period'],row['previous'],row['disposal'])
                self.assertEqual(actual,row['schedule']);reconcile(actual)
                if 'tax_schedule' in row:
                    self.assertEqual(partial(row['plan'],row['disposal'],row['period'],60 if row['plan']['months']==120 else 120),row['tax_schedule'])
    def test_unknown_movements_or_partial_month_not_silently_ignored(self):
        plan={'basis_cents':120001,'residual_cents':0,'months':60,'acquired_on':'2025-07-01','sphere':'4','account':'06300','reason':'Synthetic','method':'linear_monthly'}
        p={'start':'2025-07-01','end':'2026-06-30'}
        for field in ('sphere_changes','disposal','impairment','partial_plan'):
            with self.subTest(field=field),self.assertRaises(ValueError):schedule(plan|{field:[]},p)
        with self.assertRaises(ValueError):schedule(plan,p|{'end':'2026-06-15'})
        with self.assertRaises(ValueError):schedule(plan|{'basis_cents':True},p)
    def test_degressive_carry_cannot_be_guessed_or_changed(self):
        p={'basis_cents':100001,'residual_cents':0,'months':60,'acquired_on':'2025-09-18','sphere':'4','account':'06300','reason':'Synthetic','method':'degressive',
           'degressive':{'rate_basis_points':3000,'movable':True,'same_book_tax':True,'switch_to_linear':True}}
        first={'start':'2025-07-01','end':'2026-06-30'};second={'start':'2026-07-01','end':'2026-12-31'}
        original=schedule(p,first)
        with self.assertRaises(ValueError):schedule(p,second)
        for change in ('amount','months','gap','invented_switch'):
            previous=copy.deepcopy(original)
            if change=='amount':previous['book_closing_cents']+=1
            if change=='months':previous['degressive_state']['elapsed_months']+=1
            if change=='gap':previous['period']['end']='2026-05-31'
            if change=='invented_switch':previous['degressive_state']['switched_period']='20250701-20260630'
            with self.subTest(change=change),self.assertRaises(ValueError):schedule(p,second,previous)

if __name__=='__main__':unittest.main()
