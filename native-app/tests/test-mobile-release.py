"""Packaging and key-safety checks; synthetic Mach-O fixtures are not an Xcode proof."""
import importlib.util
import json
import os
import plistlib
import struct
import tempfile
import unittest
import zipfile
from pathlib import Path


def module(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).parents[1] / 'tools' / (name + '.py'))
    result = importlib.util.module_from_spec(spec); spec.loader.exec_module(result)
    return result


ios, android = module('ios-release'), module('android-release')


class ReleasePackaging(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='dvm-package-contract-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.app = self.root / 'DVM.app'; self.app.mkdir()
        self.info = {'CFBundleIdentifier': 'de.deinvereinsmanager.app', 'CFBundlePackageType': 'APPL',
                     'CFBundleSupportedPlatforms': ['iPhoneOS'], 'UIDeviceFamily': [1, 2],
                     'CFBundleShortVersionString': '1.0', 'CFBundleVersion': '1',
                     'MinimumOSVersion': '17.0', 'CFBundleExecutable': 'DVM'}
        self.write_info()
        self.executable()
        for locale in ['de', 'en']:
            directory = self.app / (locale + '.lproj'); directory.mkdir()
            for name in ['Localizable.strings', 'InfoPlist.strings']:
                (directory / name).write_bytes(b'"original" = "unmodified";\n')
        (self.app / 'PrivacyInfo.xcprivacy').write_bytes(plistlib.dumps({'NSPrivacyTracking': False}))

    def write_info(self):
        (self.app / 'Info.plist').write_bytes(plistlib.dumps(self.info))

    def executable(self, target=2, cpu=0x100000c):
        (self.app / 'DVM').write_bytes(struct.pack('<8I', 0xfeedfacf, cpu, 0, 2, 1, 24, 0, 0) + struct.pack('<6I', 0x32, 24, target, 0x110000, 0x120000, 0))
        (self.app / 'DVM').chmod(0o755)

    def test_payload_preserves_every_byte_modes_and_internal_symlink(self):
        (self.app / 'original.bin').write_bytes(bytes(range(256)))
        (self.app / 'reference').symlink_to('original.bin')
        before = {p.relative_to(self.app).as_posix(): p.read_bytes() for p in self.app.rglob('*') if p.is_file() and not p.is_symlink()}
        output = self.root / 'DVM.ipa'; result = ios.package(self.app, output)
        with zipfile.ZipFile(output) as z:
            self.assertIsNone(z.testzip())
            for name, data in before.items():
                self.assertEqual(z.read('Payload/DVM.app/' + name), data)
            self.assertEqual(z.read('Payload/DVM.app/reference'), b'original.bin')
            self.assertEqual(z.getinfo('Payload/DVM.app/DVM').external_attr >> 16 & 0o777, 0o755)
        self.assertEqual(before, {p.relative_to(self.app).as_posix(): p.read_bytes() for p in self.app.rglob('*') if p.is_file() and not p.is_symlink()})
        self.assertEqual(json.loads(output.with_suffix('.ipa.json').read_text())['sha256'], result['sha256'])
        self.assertEqual(output.with_suffix('.ipa.sha256').read_text(), result['sha256'] + '  DVM.ipa\n')

    def test_simulator_and_renamed_non_apple_executables_are_rejected(self):
        for target, cpu in [(7, 0x100000c), (2, 0x1000007)]:
            self.executable(target, cpu)
            with self.assertRaises(ValueError): ios.package(self.app, self.root / 'bad.ipa')
        (self.app / 'DVM').write_bytes(b'not a device executable')
        with self.assertRaises(ValueError): ios.package(self.app, self.root / 'bad.ipa')
        self.assertFalse((self.root / 'bad.ipa').exists())

    def test_simulator_metadata_debug_exceptions_and_unresolved_versions_are_rejected(self):
        for key, value in [('CFBundleSupportedPlatforms', ['iPhoneSimulator']), ('UIDeviceFamily', [1]),
                           ('CFBundleVersion', '$(CURRENT_PROJECT_VERSION)'),
                           ('NSAppTransportSecurity', {'NSAllowsLocalNetworking': True})]:
            original = self.info.get(key)
            self.info[key] = value; self.write_info()
            with self.assertRaises(ValueError): ios.inspect(self.app)
            if original is None: del self.info[key]
            else: self.info[key] = original

    def test_escape_symlinks_and_missing_localizations_are_rejected(self):
        (self.app / 'escape').symlink_to('../secret')
        with self.assertRaises(ValueError): ios.inspect(self.app)
        (self.app / 'escape').unlink()
        (self.app / 'en.lproj/InfoPlist.strings').unlink()
        with self.assertRaises(ValueError): ios.inspect(self.app)

    def test_existing_artifacts_and_evidence_are_preserved(self):
        output = self.root / 'DVM.ipa'
        output.write_bytes(b'previous original')
        with self.assertRaises(ValueError): ios.package(self.app, output)
        self.assertEqual(output.read_bytes(), b'previous original')
        output.unlink(); output.with_suffix('.ipa.json').write_bytes(b'previous evidence')
        with self.assertRaises(ValueError): ios.package(self.app, output)
        self.assertFalse(output.exists())

    def test_embedded_simulator_framework_is_not_a_device_release(self):
        framework = self.app / 'Frameworks/Example.framework'; framework.mkdir(parents=True)
        binary = framework / 'Example'
        binary.write_bytes(struct.pack('<8I', 0xfeedfacf, 0x100000c, 0, 6, 1, 24, 0, 0) + struct.pack('<6I', 0x32, 24, 7, 0x110000, 0x120000, 0))
        with self.assertRaises(ValueError): ios.package(self.app, self.root / 'bad-framework.ipa')
        self.assertFalse((self.root / 'bad-framework.ipa').exists())

    def test_output_parent_symlink_cannot_create_archive_inside_its_own_input(self):
        alias = self.root / 'output-alias'; alias.symlink_to(self.app, target_is_directory=True)
        with self.assertRaises(ValueError): ios.package(self.app, alias / 'recursive.ipa')
        self.assertFalse((self.app / 'recursive.ipa').exists())

    def test_signing_rejects_public_permissions_symlinks_and_source_paths(self):
        private = self.root / 'password'; private.write_text('x' * 20); private.chmod(0o600)
        android.private_file(private)
        private.chmod(0o644)
        with self.assertRaises(ValueError): android.private_file(private)
        private.chmod(0o600); link = self.root / 'link'; link.symlink_to(private)
        with self.assertRaises(ValueError): android.private_file(link)
        with self.assertRaises(ValueError): android.private_file(android.ROOT / 'native-app/README.md')


if __name__ == '__main__':
    unittest.main()
