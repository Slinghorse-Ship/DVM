from original_manufacturer import original_manufacturer_id
"""Separate local E-Bilanz envelopes; actual pre-change test originals stay exact.

Synthetic source values only. No database, licensed runtime, certificate or send.
"""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

APP = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(APP / 'tools'))
from ebilanz_builder import EbilanzBuilder, NS
from ebilanz_release import digest, package


def metadata_hash(result):
    original = {key: value for key, value in result.items() if key not in ('xml', 'xml_sha256')}
    return hashlib.sha256(json.dumps(original, ensure_ascii=False, sort_keys=True,
                                    separators=(',', ':')).encode()).hexdigest()


class Environments(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.originals = json.loads((APP / 'tests/fixtures/ebilanz-environments-originals.json').read_text())
        cls.cases = []
        for original in cls.originals['cases']:
            if 'fixture' in original:
                path = APP / original['fixture']
                if hashlib.sha256(path.read_bytes()).hexdigest() != original['fixture_sha256']:
                    raise AssertionError('Previously captured original fixture changed')
                request = json.loads(path.read_text()) | {'manufacturer_id': original_manufacturer_id()}
            else:
                request = original['request']
            cls.cases.append((original, request))
        spec = importlib.util.spec_from_file_location('own_environment_envelope', APP / 'tools/eric-bridge.py')
        cls.bridge = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.bridge)

    @staticmethod
    def build(request, environment='test'):
        return EbilanzBuilder(environment=environment).build(
            request['values'], request['mapping'], request['profile'], request['manufacturer_id'])

    def assert_original(self, actual, original):
        self.assertEqual(actual['xml_sha256'], original['xml_sha256'])
        self.assertEqual(hashlib.sha256(actual['xml'].encode()).hexdigest(), original['xml_sha256'])
        self.assertEqual(metadata_hash(actual), original['metadata_sha256'])
        self.assertEqual(actual['environment'], 'test')
        self.assertNotIn('release_id', actual)
        self.assertNotIn('release_sha256', actual)

    def test_default_and_explicit_test_keep_all_original_xml_and_metadata(self):
        self.assertEqual(len(self.cases), 20)
        for original, request in self.cases:
            with self.subTest(case=original['label']):
                before = copy.deepcopy(request)
                default = EbilanzBuilder().build(request['values'], request['mapping'],
                                               request['profile'], request['manufacturer_id'])
                explicit = self.build(request)
                self.assert_original(default, original)
                self.assertEqual(explicit, default)
                self.assertEqual(request, before)

    def test_production_changes_only_explicit_envelope_and_release_metadata(self):
        envelope = {'data_art':'Bilanz', 'verfahren':'ElsterBilanz', 'vorgang':'send-Auth',
                    'test_marker':'700000004', 'payload_tag':'{'+NS['b']+'}EBilanz'}
        for original, request in self.cases:
            with self.subTest(case=original['label']):
                before = copy.deepcopy(request)
                test = self.build(request)
                self.assert_original(test, original)
                production = self.build(request, 'production')
                marker = '<Testmerker>700000004</Testmerker>'
                self.assertEqual(test['xml'].count(marker), 1)
                xml = test['xml'].replace(marker, '', 1)
                release = package('6.9', request['values']['year'])
                expected = test | {'xml':xml, 'xml_sha256':hashlib.sha256(xml.encode()).hexdigest(),
                                   'environment':'production', 'release_id':release['id'],
                                   'release_sha256':digest(release)}
                self.assertEqual(production, expected)
                self.assertFalse(release['production_authorized'])
                self.assertEqual(production['transmission'], 'unavailable')
                self.bridge.check_envelope(test['xml'], envelope, request['manufacturer_id'], 'test')
                self.bridge.check_envelope(production['xml'], envelope, request['manufacturer_id'], 'production')
                with self.assertRaises(self.bridge.Rejected):
                    self.bridge.check_envelope(test['xml'], envelope, request['manufacturer_id'], 'production')
                with self.assertRaises(self.bridge.Rejected):
                    self.bridge.check_envelope(production['xml'], envelope, request['manufacturer_id'], 'test')
                self.assertEqual(request, before)

    def test_secondary_combined_builder_receives_current_environment(self):
        request = next(request for _, request in self.cases
                       if request['profile']['kind'] == 'tax_privileged_club_with_sgeb')
        observed = []

        class ObservedBuilder(EbilanzBuilder):
            def __init__(self, taxonomy='6.9', *, environment='test'):
                observed.append(environment)
                super().__init__(taxonomy, environment=environment)

        for environment in ('test', 'production'):
            observed.clear()
            primary = EbilanzBuilder(environment=environment)
            with patch('ebilanz_builder.EbilanzBuilder', ObservedBuilder):
                primary.build(request['values'], request['mapping'], request['profile'], request['manufacturer_id'])
            self.assertEqual(observed, [environment])

    def test_environment_is_closed_and_does_not_enable_unaccepted_taxonomy(self):
        for invalid in (None, '', 'TEST', 'Production', 'live', True, 1, [], {}):
            with self.subTest(environment=invalid), self.assertRaises(ValueError):
                EbilanzBuilder(environment=invalid)
        with self.assertRaises(TypeError):
            EbilanzBuilder('6.9', 'production')
        with self.assertRaises(ValueError):
            EbilanzBuilder('6.10', environment='production').build({}, {}, {}, '12345')

    def test_existing_test_only_cli_stdout_keeps_actual_original_bytes(self):
        request = next(request for original, request in self.cases if original['label'] == 'single-adjusted')
        cli = subprocess.run([sys.executable, str(APP / 'tools/ebilanz_builder.py')],
                             input=json.dumps(request, ensure_ascii=False), text=True,
                             capture_output=True, check=True, timeout=60)
        self.assertEqual(cli.stderr, '')
        self.assertEqual(len(cli.stdout.encode()), self.originals['cli_stdout_bytes'])
        self.assertEqual(hashlib.sha256(cli.stdout.encode()).hexdigest(), self.originals['cli_stdout_sha256'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
