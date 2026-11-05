"""Private preparation/configuration boundaries; no Java, OCR or ERiC execution."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('DVM_PHP', 'php')


class RuntimeTools(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def run_tool(self, name, *args):
        return subprocess.run([PHP, str(ROOT / 'distribution' / name), *args],
                              text=True, capture_output=True, cwd=ROOT)

    def test_help_has_no_installation_effect(self):
        r = self.run_tool('prepare-runtimes.php', '--help')
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn('ocr,kosit,eric', r.stdout)
        self.assertEqual(list(self.base.iterdir()), [])

    def test_component_and_argument_errors_create_nothing(self):
        for components in ['unknown', 'ocr,ocr', ',ocr', 'ocr,']:
            r = self.run_tool('prepare-runtimes.php', '--target=' + str(self.base / 'new'),
                              '--components=' + components)
            self.assertNotEqual(r.returncode, 0)
            self.assertFalse((self.base / 'new').exists())
        r = self.run_tool('prepare-runtimes.php', '--target=' + str(self.base / 'new'),
                          '--components=ocr', '--activate')
        self.assertNotEqual(r.returncode, 0)
        self.assertFalse((self.base / 'new').exists())

    def test_existing_target_and_checkout_are_preserved(self):
        target = self.base / 'retained'
        target.mkdir()
        (target / 'original').write_bytes(b'original operator data')
        for path in [target, ROOT / 'unwanted-runtime-output', Path('relative')]:
            r = self.run_tool('prepare-runtimes.php', '--target=' + str(path), '--components=kosit')
            self.assertNotEqual(r.returncode, 0)
        self.assertEqual((target / 'original').read_bytes(), b'original operator data')
        self.assertFalse((ROOT / 'unwanted-runtime-output').exists())

    def test_linked_target_is_preserved(self):
        retained = self.base / 'retained'
        retained.mkdir()
        link = self.base / 'link'
        link.symlink_to(retained, target_is_directory=True)
        r = self.run_tool('prepare-runtimes.php', '--target=' + str(link), '--components=kosit')
        self.assertNotEqual(r.returncode, 0)
        self.assertTrue(link.is_symlink())
        self.assertEqual(list(retained.iterdir()), [])

    def test_linked_parent_and_control_characters_are_rejected(self):
        retained = self.base / 'retained'
        retained.mkdir()
        alias = self.base / 'alias'
        alias.symlink_to(retained, target_is_directory=True)
        for target in [alias / 'new', self.base / 'new\nbad']:
            r = self.run_tool('prepare-runtimes.php', '--target=' + str(target), '--components=kosit')
            self.assertNotEqual(r.returncode, 0)
        self.assertFalse((retained / 'new').exists())
        self.assertFalse((self.base / 'new\nbad').exists())

    def fixture(self):
        java = self.base / 'java'
        marker = self.base / 'must-not-execute'
        java.write_text('#!/bin/sh\ntouch "' + str(marker) + '"\nexit 73\n')
        java.chmod(0o700)
        repo = self.base / 'config'
        repo.mkdir()
        (repo / 'scenarios.xml').write_text('<own-configuration/>')
        work = self.base / 'work'
        work.mkdir(mode=0o700)
        out = self.base / 'kosit.json'
        jar = ROOT / 'distribution/packages/linux-runtime/blobs/kosit-validator-1.6.3.jar'
        args = ['--java=' + str(java), '--jar=' + str(jar), '--repository=' + str(repo),
                '--workdir=' + str(work), '--output=' + str(out)]
        return args, out, repo, marker

    def test_configure_does_not_execute_or_overwrite(self):
        args, out, repo, marker = self.fixture()
        r = self.run_tool('configure-kosit.php', *args)
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertFalse(json.loads(r.stdout)['runtime_checked'])
        self.assertFalse(marker.exists())
        original = out.read_bytes()
        self.assertEqual(out.stat().st_mode & 0o777, 0o600)
        self.assertEqual(json.loads(original)['scenarios'], str(repo / 'scenarios.xml'))
        (repo / 'new-rule').write_text('changed input')
        r = self.run_tool('configure-kosit.php', *args)
        self.assertNotEqual(r.returncode, 0)
        self.assertEqual(out.read_bytes(), original)

    def test_changed_validator_and_linked_rules_are_rejected(self):
        args, out, repo, marker = self.fixture()
        wrong = self.base / 'wrong.jar'
        wrong.write_bytes(b'not the reviewed KoSIT jar')
        altered = [('--jar=' + str(wrong)) if a.startswith('--jar=') else a for a in args]
        self.assertNotEqual(self.run_tool('configure-kosit.php', *altered).returncode, 0)
        self.assertFalse(out.exists())
        (repo / 'linked-rule').symlink_to(wrong)
        self.assertNotEqual(self.run_tool('configure-kosit.php', *args).returncode, 0)
        self.assertFalse(out.exists())
        self.assertFalse(marker.exists())

    def test_configuration_requires_private_output_directory(self):
        args, out, _, _ = self.fixture()
        public = self.base / 'public'
        public.mkdir(mode=0o755)
        public.chmod(0o755)
        modified = [('--output=' + str(public / 'kosit.json')) if a.startswith('--output=') else a for a in args]
        self.assertNotEqual(self.run_tool('configure-kosit.php', *modified).returncode, 0)
        self.assertFalse((public / 'kosit.json').exists())
        self.assertFalse(out.exists())


if __name__ == '__main__':
    unittest.main()
