"""Actual retained MySQL papers: amounts, disclosure, pagination and inert captions."""
import copy
import importlib.util
import io
import json
import os
import subprocess
import unittest
from pathlib import Path
from pypdf import PdfReader
import pdfplumber

APP=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('balance_renderer',APP/'tools/render-balance-report.py')
renderer=importlib.util.module_from_spec(spec);spec.loader.exec_module(renderer)
CASES=json.loads((APP/'tests/fixtures/balance-pdf-mysql-values.json').read_text(encoding='utf8'))


class BalancePdfTests(unittest.TestCase):
    def text(self,paper):
        before=copy.deepcopy(paper);data=renderer.render(paper)
        self.assertEqual(paper,before)
        with pdfplumber.open(io.BytesIO(data)) as pdf:
            for page in pdf.pages:
                for char in page.chars:
                    self.assertGreaterEqual(char['x0'],49)
                    self.assertLessEqual(char['x1'],page.width-49)
                    self.assertGreaterEqual(char['top'],35)
                    self.assertLessEqual(char['bottom'],page.height-25)
        pages=PdfReader(io.BytesIO(data)).pages
        return '\n'.join(p.extract_text() for p in pages),len(pages)

    def test_retained_native_mysql_amounts_and_four_spheres(self):
        for year,row in CASES.items():
            with self.subTest(year=year):
                text,pages=self.text(row['closed'])
                self.assertGreaterEqual(pages,2)
                for label in (*renderer.SPHERES.values(),'Summe Aktiva','Summe Passiva','Anlagenverzeichnis','120,00 €','22,00 €','98,00 €','−22,00 €','TEST','Festgeschriebener Buchabschluss','keine Bestätigung einer Steuerabgabe'):
                    self.assertIn(label,text)

    def test_open_mysql_paper_is_not_an_approved_closing(self):
        for row in CASES.values():
            text,_=self.text(row['open'])
            self.assertIn('Arbeitsstand – Prüfung noch offen',text)
            self.assertNotIn('Festgeschriebener Buchabschluss',text)
            self.assertIn('Offene Prüfungen',text)

    def test_long_captions_large_values_and_repeated_account_headers(self):
        p=copy.deepcopy(CASES['2025']['closed']);r=p['balance_report'];c=r['calculation']
        c['accounts']={str(i):dict(next(iter(c['accounts'].values())),name=f'Konto {i} · '+('lange Bezeichnung ' * 7),opening_cents=100_000_000_000,closing_cents=-100_000_000_000) for i in range(75)}
        a=next(iter(r['assets'].values()));a['name']='Original <b>nicht fett</b> & <script>kein Skript</script> '+'langer Name '*10
        a['impairment_cents']=2200;a['writeup_cents']=1000
        text,pages=self.text(p)
        self.assertGreater(pages,5)
        self.assertGreater(text.count('Konto / SKR42'),3)
        self.assertIn('Konto 74',text)
        self.assertIn('<b>nicht fett</b>',text)
        self.assertIn('<script>kein Skript</script>',text)
        self.assertIn('Minderung',text);self.assertIn('Aufholung',text)

    def test_unbalanced_and_wrong_scope_never_render(self):
        for change in ('balance','scope','float'):
            p=copy.deepcopy(CASES['2025']['closed']);r=p['balance_report']
            if change=='balance':r['calculation']['passive_total_cents']+=1
            elif change=='scope':r['tax_filing_approved']=True
            else:r['calculation']['assets'][0]['amount_cents']=98.0
            with self.assertRaises(ValueError):renderer.render(p)

    def test_visible_link_binds_the_exact_native_mysql_report(self):
        for year,row in CASES.items():
            for state,paper in row.items():
                with self.subTest(year=year,state=state):
                    self.assertIn('asset_schedule',paper['_reported_native_ledger'])
                    p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(APP/'tests/fixtures/balance-pdf-view.php')],input=json.dumps(paper),capture_output=True,encoding='utf8',timeout=30)
                    self.assertEqual(p.returncode,0,p.stdout+p.stderr);self.assertEqual(p.stderr,'')
                    self.assertIn('balance_pdf_hash='+paper['balance_report']['snapshot_sha256'],p.stdout)


if __name__=='__main__':unittest.main()
