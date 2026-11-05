"""Contracts for CI selection, without databases, credentials or external actions."""
import importlib.util
import pathlib
import unittest

HERE = pathlib.Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('github_core', HERE / 'run-github-core.py')
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)


class Selection(unittest.TestCase):
    def setUp(self):
        self.registered = core.suites((core.ROOT / 'vereinsverwaltung/tests/ci-native.php').read_text())

    def test_feature_change_retains_all_nonoperational_contracts(self):
        result = core.plan(self.registered, ['vereinsverwaltung/views/Governance/minutes.php'])
        self.assertEqual(result['selected'], [s for s in self.registered if s not in core.OPERATIONAL])
        self.assertEqual(set(result['deferred_operational_groups']), core.OPERATIONAL)

    def test_regular_inventory_refresh_does_not_start_an_upgrade(self):
        self.assertFalse(core.plan(self.registered, ['distribution/source-files.json',
                         'vereinsverwaltung/native/Installer/PackageVersion.php'])['operational'])

    def test_installer_schema_and_runtime_changes_require_real_operational_contracts(self):
        for name in ['distribution/install.php', 'vereinsverwaltung/native/Installer/Runtime.php',
                     'vereinsverwaltung/native/Delivery/Schema.php', 'tools/cloud/bootstrap.sh',
                     'vereinsverwaltung/migrations/mysql/135_member_cards.sql', 'composer.lock',
                     'vereinsverwaltung/composer.lock']:
            with self.subTest(path=name):
                result = core.plan(self.registered, [name])
                self.assertTrue(result['operational'])
                self.assertEqual(result['selected'], self.registered)

    def test_browser_registration_and_ci_metadata_do_not_repeat_customer_upgrades(self):
        paths = ['tools/ci/local-native.sh', 'tools/ci/run-github-core.py',
                 '.github/workflows/native-regressions.yml',
                 'vereinsverwaltung/tests/ci-native.php']
        self.assertFalse(core.plan(self.registered, paths)['operational'])
        self.assertTrue(core.plan(self.registered, paths, full=True)['operational'])

    def test_explicit_full_acceptance_never_defers_an_operational_group(self):
        result = core.plan(self.registered, [], True)
        self.assertEqual(result['selected'], self.registered)
        self.assertEqual(result['deferred_operational_groups'], [])

    def test_a_duplicate_or_missing_registration_fails(self):
        for source in ["$suites = ['native-installer', 'native-installer'];", '$suites = [];']:
            with self.assertRaises(ValueError):
                core.suites(source)


if __name__ == '__main__':
    unittest.main()
