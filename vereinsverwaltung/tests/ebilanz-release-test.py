"""Annual package/SDK contract tests; synthetic data, no database or network."""
import copy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch, Mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / 'tools'))
import ebilanz_release as release
import datev_years as years


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


annual = load('annual_release_fixture', HERE / 'datev-years-test.py')
bridge = load('release_bridge', HERE.parent / 'tools/eric-bridge.py')


def version_xml(api='44, 3, 4, 0', plugin='44, 3, 4, 0', platform='windows'):
    names = ['ericapi.dll', 'checkbilanz_6_9.dll'] if platform == 'windows' else ['libericapi.so', 'libcheckbilanz_6_9.so']
    return '<EricVersion xmlns="http://www.elster.de/EricXML/1.0/EricVersion">' + ''.join(
        f'<Bibliothek><Name>{n}</Name><Produktversion>{v}</Produktversion></Bibliothek>'
        for n, v in zip(names, [api, plugin])) + '</EricVersion>'


class ReleasePackages(unittest.TestCase):
    def setUp(self):
        self.original = release.ROOT
        self.temp = tempfile.TemporaryDirectory(prefix='ebilanz-release-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.resources = self.root / 'resources/ebilanz'
        self.resources.mkdir(parents=True)
        self.registry = json.loads((self.original / 'resources/ebilanz/releases.json').read_text(encoding='utf8'))
        (self.resources / 'taxonomy-6.9.json').write_bytes((self.original / 'resources/ebilanz/taxonomy-6.9.json').read_bytes())
        self.save()
        self.override = patch.object(release, 'ROOT', self.root)
        self.override.start()
        self.addCleanup(self.override.stop)

    def save(self):
        (self.resources / 'releases.json').write_text(json.dumps(self.registry), encoding='utf8')

    def test_current_packages_are_explicitly_not_a_production_release(self):
        for year in (2025, 2026):
            s = release.status('6.9', year)
            self.assertTrue(s['ready'])
            self.assertFalse(s['production_authorized'])
            self.assertEqual(s['stage'], 'catalog_and_local_validation')
            self.assertEqual(len(s['sha256']), 64)
        for taxonomy, year in [('6.9', 2027), ('6.10', 2026), ('6.9', '2026'), ('6.9', True)]:
            self.assertFalse(release.status(taxonomy, year)['ready'])

    def test_json_year_list_cannot_enlarge_implemented_adapter(self):
        self.registry['packages'][0]['years'].append(2027)
        self.save()
        self.assertFalse(release.status('6.9', 2027)['ready'])
        model, _ = years.read_json(years.ROOT / 'resources/accounting/purposes.json')
        model['taxonomies']['6.9']['years'].append(2027)
        self.assertFalse(years.targets_ready(model, '6.9', 2027))

    def test_missing_ambiguous_and_malformed_packages_fail_closed(self):
        for packages in [[], [self.registry['packages'][0]] * 2, [None]]:
            with self.subTest(packages=packages):
                self.registry['packages'] = packages
                self.save()
                self.assertFalse(release.status('6.9', 2026)['ready'])
        (self.resources / 'releases.json').write_text('{', encoding='utf8')
        self.assertFalse(release.status('6.9', 2026)['ready'])
        (self.resources / 'releases.json').unlink()
        self.assertFalse(release.status('6.9', 2026)['ready'])

    def test_wrong_taxonomy_source_and_adapter_do_not_validate(self):
        original = copy.deepcopy(self.registry)
        for field, value in [('adapter', 'future'), ('production_authorized', True), ('stage', 'production')]:
            self.registry = copy.deepcopy(original)
            self.registry['packages'][0][field] = value
            self.save()
            self.assertFalse(release.status('6.9', 2026)['ready'])
        for field in ('source', 'sha256', 'date'):
            self.registry = copy.deepcopy(original)
            self.registry['packages'][0]['taxonomy'][field] = 'changed'
            self.save()
            self.assertFalse(release.status('6.9', 2026)['ready'])

    def test_changed_metadata_is_not_hidden_by_cache(self):
        self.assertTrue(release.status('6.9', 2026)['ready'])
        path = self.resources / 'taxonomy-6.9.json'
        path.write_bytes(path.read_bytes() + b' ')
        self.assertFalse(release.status('6.9', 2026)['ready'])

    def test_sdk_api_and_plugin_both_need_review(self):
        p = release.package('6.9', 2026)
        for platform in ('windows', 'linux'):
            self.assertEqual(release.check_runtime(p, version_xml(platform=platform)), p['eric']['reviewed_versions'][0])
        for xml in [version_xml(api='44, 3, 6, 0'), version_xml(plugin='44, 3, 6, 0'),
                    version_xml().replace('checkbilanz_6_9', 'checkbilanz_6_10'),
                    version_xml().replace('checkbilanz_6_9', 'ericapi'), '<broken>',
                    '<!DOCTYPE test>' + version_xml()]:
            with self.subTest(xml=xml), self.assertRaises(ValueError):
                release.check_runtime(p, xml)

    def test_new_sdk_package_invalidates_existing_annual_review(self):
        annual.AnnualTest.setUpClass()
        fixture = annual.AnnualTest()
        old, base, new = fixture.pair()
        plan = fixture.plan(old, base, new)
        review = fixture.review(plan)
        approved = fixture.approve(old, base, new, plan, review)
        self.assertEqual(approved['provenance']['filing_package'], plan['filing_package'])
        self.registry['packages'][0]['id'] = 'verein-6.9-2025-2026-r2'
        self.save()
        with self.assertRaisesRegex(ValueError, 'aktuellen Vergleich'):
            fixture.approve(old, base, new, plan, review)
        self.assertNotEqual(fixture.plan(old, base, new)['filing_package']['sha256'], plan['filing_package']['sha256'])

    def test_large_account_family_checks_package_per_operation(self):
        annual.AnnualTest.setUpClass()
        fixture = annual.AnnualTest()
        old, base, new = fixture.pair(fixture.fixture(tuple(str(n) for n in range(18000, 18500))))
        with patch.object(release, 'package', wraps=release.package) as check:
            plan = fixture.plan(old, base, new)
        self.assertEqual(len(plan['automatic']), 500)
        self.assertLessEqual(check.call_count, 4)

    def test_package_change_during_comparison_rejects_result(self):
        annual.AnnualTest.setUpClass()
        fixture = annual.AnnualTest()
        old, base, new = fixture.pair()
        real = years.target_validator
        def changed(*args):
            validator = real(*args)
            def validate(ref):
                self.registry['packages'][0]['id'] = 'changed-during-review'
                self.save()
                return validator(ref)
            return validate
        with patch.object(years, 'target_validator', side_effect=changed), self.assertRaisesRegex(ValueError, 'während'):
            fixture.plan(old, base, new)

    def test_generator_and_native_xml_check_share_exact_scope(self):
        fixtures = load('release_values', HERE / 'ebilanz-native.py')
        values = fixtures.fixture()
        mapping, profile = fixtures.inputs(values)
        from ebilanz_builder import EbilanzBuilder
        xml = EbilanzBuilder().build(values, mapping, profile, '12345')['xml']
        self.assertEqual(release.xml_package(xml, 'Bilanz_6_9')['id'], self.registry['packages'][0]['id'])
        for altered, version in [(xml, 'Bilanz_6_10'), (xml.replace('2025-12-31', '2027-12-31'), 'Bilanz_6_9'),
                                 (xml.replace('de-gcd-2025-04-01', 'de-gcd-2026-04-01'), 'Bilanz_6_9')]:
            with self.subTest(version=version), self.assertRaises(ValueError):
                release.xml_package(altered, version)
        self.registry['packages'] = []
        self.save()
        with self.assertRaises(ValueError):
            EbilanzBuilder().build(values, mapping, profile, '12345')

    def test_unreviewed_sdk_stops_before_native_validation_or_transport(self):
        fake = object.__new__(bridge.Eric)
        fake.version = object()
        fake.buffer_call = Mock(return_value=(0, version_xml(plugin='45, 1, 0, 0')))
        fake.allocate = Mock()
        fake.process = Mock()
        with patch.object(release, 'xml_package', return_value=release.package('6.9', 2026)):
            with self.assertRaises(bridge.Rejected):
                fake.run('<synthetic/>', 'Bilanz_6_9', crypto=object())
        fake.allocate.assert_not_called()
        fake.process.assert_not_called()


if __name__ == '__main__':
    unittest.main(verbosity=2)
