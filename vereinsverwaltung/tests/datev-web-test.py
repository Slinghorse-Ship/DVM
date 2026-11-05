"""Annual web adapter, offline: real reviewed catalogs, no network or activation."""
import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
import datev_web as web
import datev_years as d


class AnnualWebTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='annual-web-test-')
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name)
        self.request = {'operation': 'fetch', 'from_year': 2025, 'year': 2026}
        self.pdf = b'%PDF-1.7\nSynthetic offline catalog\n'
        self.source = web.module('check-datev-skr42')
        self.load = web.module

    def fetch(self, year=2026, pdf=None):
        raw = self.pdf if pdf is None else pdf
        with patch.object(web, 'module', side_effect=lambda name: self.source if name == 'check-datev-skr42' else self.load(name)), \
             patch.object(self.source, 'fetch', return_value=f'<p>Stand Januar {year}</p><a href="https://download.datev-shop.de/catalog.pdf">Kostenfreier Download</a>'.encode()), \
             patch.object(self.source, 'download_pdf', return_value=('https://download.datev-shop.de/catalog.pdf', raw)) as download:
            result = web.run(self.request, self.path)
            return result, download.call_count

    def compare(self):
        (self.path / 'original.pdf').write_bytes(self.pdf)
        request = self.request | {'operation': 'compare', 'source': 'https://www.datev.de/catalog.pdf',
                                 'pdf_sha256': hashlib.sha256(self.pdf).hexdigest(), 'appendix_pages': 2}
        def parsed(command, **kwargs):
            destination = Path(command[command.index('--output-dir') + 1])
            destination.mkdir()
            (destination / 'skr42-2026.json').write_bytes((d.ROOT / 'data/skr42-2026.json').read_bytes())
            return SimpleNamespace(returncode=0)
        with patch.object(web.subprocess, 'run', side_effect=parsed):
            return web.run(request, self.path)

    def review(self):
        review = json.loads((self.path / 'review.json').read_text(encoding='utf8'))
        review.update(reviewer='Synthetic admin', note='Synthetic offline review',
                      source_and_notes_reviewed=True, taxonomy_reviewed=True)
        for row in review['decisions'].values():
            row['reason'] = 'Keep unresolved pending substantive review'
        (self.path / 'submitted-review.json').write_text(json.dumps(review), encoding='utf8')
        return review

    def test_unpublished_year_never_downloads_another_year(self):
        self.request.update(from_year=2026, year=2027)
        result, calls = self.fetch()
        self.assertEqual((result['state'], result['available_year'], calls), ('unavailable', 2026, 0))
        self.assertEqual(list(self.path.iterdir()), [])

    def test_download_is_byte_exact_and_unknown_layout_not_guessed(self):
        result, calls = self.fetch()
        self.assertEqual(calls, 1)
        self.assertEqual(result['state'], 'downloaded')
        self.assertEqual((self.path / 'original.pdf').read_bytes(), self.pdf)
        self.assertEqual(result['pdf_sha256'], hashlib.sha256(self.pdf).hexdigest())
        self.assertIsNone(result['appendix_pages'])

    def test_large_original_rejected_without_saved_file(self):
        with self.assertRaises(ValueError):
            self.fetch(pdf=b'%PDF-' + b'x' * web.LIMIT)
        self.assertEqual(list(self.path.iterdir()), [])

    def test_only_strict_adjacent_known_year_allowed(self):
        for change in ({'from_year': '2025'}, {'from_year': 2025.0}, {'year': True}, {'year': '2026'},
                       {'year': 2027}, {'from_year': 2024, 'year': 2025}):
            with self.subTest(change=change), self.assertRaises(ValueError):
                web.run(self.request | change, self.path)

    def test_altered_pdf_never_reaches_parser(self):
        (self.path / 'original.pdf').write_bytes(self.pdf)
        request = self.request | {'operation': 'compare', 'pdf_sha256': '0' * 64, 'appendix_pages': 2, 'source': 'https://datev.de/a.pdf'}
        with patch.object(web.subprocess, 'run') as parser, self.assertRaises(ValueError):
            web.run(request, self.path)
        parser.assert_not_called()

    def test_source_and_layout_are_checked_before_parser(self):
        (self.path / 'original.pdf').write_bytes(self.pdf)
        request = self.request | {'operation': 'compare', 'pdf_sha256': hashlib.sha256(self.pdf).hexdigest(), 'appendix_pages': 2, 'source': 'https://datev.de/a.pdf'}
        for change in ({'appendix_pages': None}, {'appendix_pages': True}, {'appendix_pages': 21}, {'source': 'https://datev.de.evil.invalid/a'}):
            with self.subTest(change=change), patch.object(web.subprocess, 'run') as parser, self.assertRaises(ValueError):
                web.run(request | change, self.path)
            parser.assert_not_called()

    def test_parser_failure_does_not_create_review(self):
        (self.path / 'original.pdf').write_bytes(self.pdf)
        request = self.request | {'operation': 'compare', 'pdf_sha256': hashlib.sha256(self.pdf).hexdigest(), 'appendix_pages': 2, 'source': 'https://datev.de/a.pdf'}
        with patch.object(web.subprocess, 'run', return_value=SimpleNamespace(returncode=2)), self.assertRaises(ValueError):
            web.run(request, self.path)
        self.assertFalse((self.path / 'comparison.json').exists())

    def test_private_hosting_launcher_is_used_for_the_pdf_child(self):
        (self.path / 'original.pdf').write_bytes(self.pdf)
        request = self.request | {'operation': 'compare', 'pdf_sha256': hashlib.sha256(self.pdf).hexdigest(), 'appendix_pages': 2, 'source': 'https://datev.de/a.pdf'}
        with patch.dict(web.os.environ, {'KULTURHUS_CATALOG_PYTHON': '/private/catalog/python-wrapper'}), \
             patch.object(web.subprocess, 'run', return_value=SimpleNamespace(returncode=2)) as parser, self.assertRaises(ValueError):
            web.run(request, self.path)
        self.assertEqual(parser.call_args.args[0][:3], ['/private/catalog/python-wrapper', '-X', 'utf8'])

    def test_compare_uses_current_revision_not_original_base_only(self):
        before = hashlib.sha256((d.ROOT / 'resources/ebilanz/skr42-6.9.json').read_bytes()).hexdigest()
        result = self.compare()
        self.assertEqual(result['state'], 'review_required')
        self.assertGreater(result['automatic'], 1000)
        self.assertLess(result['existing_gaps'], 700)
        self.assertFalse(result['production_authorized'])
        plan = json.loads((self.path / 'comparison.json').read_text(encoding='utf8'))
        d.verify(plan)
        self.assertEqual(plan['from_year'], 2025)
        self.assertEqual(plan['year'], 2026)
        self.assertEqual(before, hashlib.sha256((d.ROOT / 'resources/ebilanz/skr42-6.9.json').read_bytes()).hexdigest())

    def test_approve_packages_declared_files_and_keeps_gaps_blocked(self):
        self.compare(); self.review()
        result = web.run(self.request | {'operation': 'approve'}, self.path)
        self.assertEqual(result['state'], 'incomplete_candidate')
        self.assertFalse(result['coverage']['complete'])
        self.assertFalse(result['production_authorized'])
        with zipfile.ZipFile(self.path / 'package.zip') as archive:
            manifest = json.loads(archive.read('manifest.json'))
            self.assertEqual(set(archive.namelist()), set(manifest['files']) | {'manifest.json'})
            for name, expected in manifest['files'].items():
                self.assertEqual(hashlib.sha256(archive.read(name)).hexdigest(), expected)
            self.assertTrue(manifest['historical_bookings_unchanged'])
            self.assertIn('explicit_program_release', manifest['requires'])

    def test_approval_request_must_match_saved_year(self):
        self.compare(); self.review()
        with self.assertRaisesRegex(ValueError, 'anderen Jahresvergleich'):
            web.run({'operation': 'approve', 'from_year': 2026, 'year': 2027}, self.path)
        self.assertFalse((self.path / 'package.zip').exists())

    def test_stale_review_rejected(self):
        self.compare(); review = self.review(); review['plan_sha256'] = '0' * 64
        (self.path / 'submitted-review.json').write_text(json.dumps(review), encoding='utf8')
        with self.assertRaises(ValueError):
            web.run(self.request | {'operation': 'approve'}, self.path)
        self.assertFalse((self.path / 'package.zip').exists())

    def test_cli_errors_do_not_expose_paths_or_tracebacks(self):
        (self.path / 'request.json').write_text(json.dumps({'operation': 'nonsense', 'from_year': 2025, 'year': 2026}), encoding='utf8')
        result = subprocess.run([sys.executable, str(d.ROOT / 'tools/datev_web.py'), str(self.path)], capture_output=True, encoding='utf8')
        self.assertEqual(result.returncode, 2)
        self.assertEqual(json.loads(result.stdout)['state'], 'failed')
        self.assertEqual(result.stderr, '')
        self.assertNotIn(str(self.path), result.stdout)


if __name__ == '__main__':
    unittest.main(verbosity=2)
