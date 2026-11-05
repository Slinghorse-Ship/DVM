"""Published 6.7–6.10 sources and fiscal start-year choices; no DB or native send."""
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
import taxonomy_sources as sources
from ebilanz_builder import EbilanzBuilder, NS
import ebilanz_release as release


class TaxonomySources(unittest.TestCase):
    def test_all_four_official_sources_are_independent_and_byte_bound(self):
        entries = sources.registry()
        hashes = []
        for version in ('6.7','6.8','6.9','6.10'):
            raw, meta = sources.source(version)
            hashes.append(hashlib.sha256(raw).hexdigest())
            self.assertEqual(hashes[-1], entries[version]['sha256'])
            self.assertEqual(meta['version'], version)
            self.assertEqual(meta['source'], entries[version]['source'])
            self.assertEqual(len(meta['source_files_sha256']), 14)
            self.assertIn('bs.ass.currAss.cashEquiv.bank', meta['concepts']['de-gaap-ci'])
        self.assertEqual(len(set(hashes)), 4)

    def test_2025_has_68_regular_and_69_optional_but_never_610(self):
        self.assertEqual(sources.choices(2025), {'regular':'6.8','optional':'6.9'})
        self.assertEqual(release.catalog_taxonomy(2025), '6.9')
        self.assertEqual(sources.choices(2024), {'regular':'6.7','optional':'6.8'})
        self.assertEqual(sources.choices(2026), {'regular':'6.9','optional':'6.10'})
        self.assertEqual(sources.choices(2027), {'regular':'6.10','optional':None})

    def test_unknown_future_years_are_not_guessed_and_scalar_years_are_strict(self):
        self.assertEqual(sources.choices(2028), {'regular':None,'optional':None})
        for year in ('2025', True, 2025.0, None, 1900, 2101):
            with self.subTest(year=year), self.assertRaises(ValueError):
                sources.choices(year)

    def test_metadata_availability_does_not_enable_unaccepted_output_adapters(self):
        for version in ('6.7','6.8','6.10'):
            builder = EbilanzBuilder(version)
            self.assertEqual(builder.meta['version'], version)
            with self.assertRaisesRegex(ValueError, 'Ausgabeadapter'):
                builder.build({}, {}, {}, '12345')
        self.assertEqual(NS['de-gcd'], 'http://www.xbrl.de/taxonomies/de-gcd-2025-04-01')

    def test_unknown_source_versions_do_not_reach_filesystem_paths(self):
        for version in ('6.11','../../private', None, [], 6.9):
            with self.subTest(version=version), self.assertRaises(ValueError):
                sources.source(version)

    def test_source_tampering_is_rejected_before_use(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            target = root / 'resources/ebilanz'
            target.mkdir(parents=True)
            original = sources.ROOT / 'resources/ebilanz'
            (target / 'taxonomies.json').write_bytes((original / 'taxonomies.json').read_bytes())
            (target / 'taxonomy-6.8.json').write_bytes((original / 'taxonomy-6.8.json').read_bytes() + b' ')
            with patch.object(sources, 'ROOT', root), self.assertRaisesRegex(ValueError, 'verändert'):
                sources.source('6.8')

    def test_current_annual_packages_match_the_same_official_registry(self):
        for version, year in [('6.9',2025),('6.9',2026),('6.10',2027)]:
            p = release.package(version, year)
            entry = sources.registry()[version]
            self.assertEqual(p['taxonomy'], {'version':version, 'date':entry['date'], 'source':entry['source'], 'sha256':entry['sha256']})


if __name__ == '__main__':
    unittest.main(verbosity=2)
