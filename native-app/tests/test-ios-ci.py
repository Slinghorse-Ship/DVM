"""Acceptance guards must never turn missing devices or skipped tests green."""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('ios_ci', Path(__file__).parents[1] / 'tools/ci-ios.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class AcceptanceGuards(unittest.TestCase):
    def test_both_actual_families_are_required(self):
        with self.assertRaises(ValueError):
            runner.targets({'devices': {}})

    def test_unavailable_device_is_not_a_substitute(self):
        with self.assertRaises(ValueError):
            runner.targets({'devices': {'com.apple.CoreSimulator.SimRuntime.iOS-18-2': [
                {'name': 'iPhone 16', 'udid': 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA', 'isAvailable': True},
                {'name': 'iPad Pro', 'udid': 'BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB', 'isAvailable': False}]}})

    def test_highest_available_ios_runtime_preserves_both_real_identifiers(self):
        source = {'devices': {
            'com.apple.CoreSimulator.SimRuntime.iOS-18-2': [{'name': 'iPhone 16', 'udid': 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA', 'isAvailable': True}],
            'com.apple.CoreSimulator.SimRuntime.iOS-26-0': [
                {'name': 'iPhone 17', 'udid': 'BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB', 'isAvailable': True},
                {'name': 'iPad Pro', 'udid': 'CCCCCCCC-CCCC-CCCC-CCCC-CCCCCCCCCCCC', 'isAvailable': True}]}}
        selected = runner.targets(source)
        self.assertEqual(selected['iPhone']['id'], 'BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB')
        self.assertEqual(selected['iPad']['id'], 'CCCCCCCC-CCCC-CCCC-CCCC-CCCCCCCCCCCC')

    def test_no_tests_or_partial_execution_cannot_pass(self):
        for summary in [{}, {'passedTests': 5, 'failedTests': 0, 'skippedTests': 0},
                        {'passedTests': 6, 'failedTests': 1, 'skippedTests': 0},
                        {'passedTests': 6, 'failedTests': 0, 'skippedTests': 1}]:
            with self.assertRaises(ValueError):
                runner.confirmed_tests(summary)
        self.assertEqual(runner.confirmed_tests({'passedTests': 6, 'failedTests': 0, 'skippedTests': 0}), 6)


if __name__ == '__main__':
    unittest.main()
