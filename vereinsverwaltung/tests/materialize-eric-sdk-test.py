"""Synthetic product-runtime archive integrity and recovery; never loads a native library."""
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('eric_originals', Path(__file__).parents[1] / 'tools/materialize-eric-sdk.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class Originals(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.base = self.root / 'packages'
        self.base.mkdir()
        self.destination = module.private_directory(self.root / 'private')
        self.original = b'own complete\x00original\xffbytes'
        pieces = [self.original[:8], self.original[8:]]
        parts = []
        for i, data in enumerate(pieces):
            name = str(i) + '.part'
            (self.base / name).write_bytes(data)
            parts.append({'file': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
        self.entry = {'original_name': 'ERiC-own.tar.gz', 'parts': parts, 'bytes': len(self.original), 'sha256': hashlib.sha256(self.original).hexdigest()}

    def tearDown(self):
        self.temporary.cleanup()

    def test_actual_bytes_and_replay(self):
        first = module.restore(self.base, self.destination, self.entry)
        self.assertEqual((self.destination / 'ERiC-own.tar.gz').read_bytes(), self.original)
        self.assertFalse(first['retained'])
        self.assertTrue(module.restore(self.base, self.destination, self.entry)['retained'])

    def test_changed_original_preserved(self):
        p = self.destination / 'ERiC-own.tar.gz'
        p.write_bytes(b'original already present')
        with self.assertRaises(ValueError):
            module.restore(self.base, self.destination, self.entry)
        self.assertEqual(p.read_bytes(), b'original already present')

    def test_changed_part_no_partial_publication_and_real_resume(self):
        p = self.base / '1.part'
        previous = p.read_bytes()
        p.write_bytes(b'changed')
        with self.assertRaises(ValueError):
            module.restore(self.base, self.destination, self.entry)
        self.assertEqual(list(self.destination.iterdir()), [])
        p.write_bytes(previous)
        module.restore(self.base, self.destination, self.entry)
        self.assertEqual((self.destination / 'ERiC-own.tar.gz').read_bytes(), self.original)

    def test_combined_hash_checked(self):
        self.entry['sha256'] = '0' * 64
        with self.assertRaises(ValueError):
            module.restore(self.base, self.destination, self.entry)
        self.assertEqual(list(self.destination.iterdir()), [])

    def test_archive_path_and_symlink_refused(self):
        for name in ['../outside.jar', '/ERiC-outside.jar', 'ERiC-own.tar.gz/other', 'ERiC-own.exe', 'ERiC-original.jar']:
            with self.subTest(name=name), self.assertRaises(ValueError):
                module.restore(self.base, self.destination, self.entry | {'original_name': name})
        source = self.base / '0.part'
        source.rename(self.base / 'retained')
        source.symlink_to(self.base / 'retained')
        with self.assertRaises(ValueError):
            module.restore(self.base, self.destination, self.entry)
        self.assertEqual(list(self.destination.iterdir()), [])

    def test_private_destination_required(self):
        with self.assertRaises(ValueError):
            module.private_directory(Path('relative'))
        link = self.root / 'linked'
        link.symlink_to(self.destination, target_is_directory=True)
        with self.assertRaises(ValueError):
            module.private_directory(link / 'new')
        self.assertFalse((self.destination / 'new').exists())


if __name__ == '__main__':
    unittest.main()
