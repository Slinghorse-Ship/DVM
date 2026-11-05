"""Synthetic inventory contracts; no operational source or secrets are copied."""
import hashlib
import importlib.util
import json
import pathlib
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('source_manifest', pathlib.Path(__file__).with_name('build-source-manifest.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class InventoryContracts(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temporary.name)
        self.write('distribution/packages/php-runtime/manifest.json', json.dumps({'components': []}).encode())
        self.write('distribution/packages/linux-runtime/manifest.json', json.dumps({'components': [], 'sources': []}).encode())

    def tearDown(self):
        self.temporary.cleanup()

    def write(self, name, content):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)

    def test_native_code_assets_and_license_are_included(self):
        paths = ['vereinsverwaltung/native/Auth/Service.php', 'vereinsverwaltung/native/Web/assets/image-export.js',
                 'vereinsverwaltung/resources/mediabunny/library.mjs', 'vereinsverwaltung/resources/mediabunny/LICENSE']
        for name in paths:
            self.write(name, b'synthetic source\n')
        self.assertEqual({entry['path'] for entry in module.inventory(self.root, paths)['files']}, set(paths))

    def test_only_explicit_reviewed_paths_are_read(self):
        self.write('vereinsverwaltung/native/test.php', b'source\n')
        self.write('vereinsverwaltung/.local/private.key', b'synthetic-private-excluded')
        self.write('.local/runtime.json', b'synthetic-runtime-excluded')
        result = module.inventory(self.root, ['vereinsverwaltung/native/test.php'])
        self.assertEqual(len(result['files']), 1)
        self.assertEqual(result['files'][0]['path'], 'vereinsverwaltung/native/test.php')

    def test_text_normalization_and_binary_integrity(self):
        self.write('vereinsverwaltung/native/test.php', b'a\r\nb\r\n')
        self.write('vereinsverwaltung/resources/test.png', b'\x89PNG\x00\r\n')
        entries = {entry['path']: entry for entry in module.inventory(self.root, [
            'vereinsverwaltung/native/test.php', 'vereinsverwaltung/resources/test.png'])['files']}
        self.assertEqual(entries['vereinsverwaltung/native/test.php']['sha256'], hashlib.sha256(b'a\nb\n').hexdigest())
        self.assertTrue(entries['vereinsverwaltung/native/test.php']['normalized_lf'])
        self.assertFalse(entries['vereinsverwaltung/resources/test.png']['normalized_lf'])
        self.assertEqual(entries['vereinsverwaltung/resources/test.png']['bytes'], 7)

    def test_licensed_operational_institutions_are_excluded(self):
        self.write('banking/fints-institutions.json', b'synthetic excluded catalog')
        self.assertEqual(module.inventory(self.root, ['banking/fints-institutions.json'])['files'], [])

    def test_inventory_does_not_include_itself(self):
        self.write('distribution/source-files.json', b'{}')
        result = module.inventory(self.root, ['distribution/source-files.json'])
        self.assertEqual(result['installer_files'], [])

    def test_missing_and_symlinked_sources_fail_closed(self):
        with self.assertRaises(RuntimeError):
            module.inventory(self.root, ['vereinsverwaltung/native/missing.php'])
        self.write('vereinsverwaltung/native/original.php', b'original')
        path = self.root / 'vereinsverwaltung/native/link.php'
        path.symlink_to(path.with_name('original.php'))
        with self.assertRaises(RuntimeError):
            module.inventory(self.root, ['vereinsverwaltung/native/link.php'])


if __name__ == '__main__':
    unittest.main()
