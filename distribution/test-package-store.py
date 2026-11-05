"""Exercise the real PHP package extractor with POSIX archives and shipped FinTS."""
import hashlib
import io
import json
import os
import pathlib
import shutil
import subprocess
import tarfile
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent


class PackageContracts(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.path = pathlib.Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def archive(self, names, format=tarfile.PAX_FORMAT):
        archive = self.path / 'test.tar.gz'
        with tarfile.open(archive, 'w:gz', format=format) as stream:
            for name in names:
                entry = tarfile.TarInfo(name)
                content = b'actual synthetic bytes\n'
                entry.size = len(content)
                stream.addfile(entry, io.BytesIO(content))
        return archive

    def extract(self, archive, restricted=False):
        code = 'set_error_handler(static function($level,$message){throw new RuntimeException($message);}); require $argv[1]; try { PackageStore::extract($argv[2],$argv[3]); } catch (Throwable $e) { exit(2); }'
        options = ['-d', 'open_basedir=' + str(self.path) + os.pathsep + str(ROOT / 'distribution/src')] if restricted else []
        return subprocess.run([
            os.environ.get('PHP_CLI_BINARY', shutil.which('php') or 'php'), *options, '-r', code,
            str(ROOT / 'distribution/src/PackageStore.php'), str(archive), str(self.path / 'out')
        ], capture_output=True)

    def test_real_fints_archive_preserves_every_path_and_byte(self):
        base = ROOT / 'distribution/packages/php-runtime'
        result = self.extract(base / 'banking-vendor.tar.gz')
        self.assertEqual(result.returncode, 0, result.stderr)
        for entry in json.loads((base / 'banking-vendor.files.json').read_text()):
            data = (self.path / 'out' / entry['path']).read_bytes()
            self.assertEqual(len(data), entry['bytes'])
            self.assertEqual(hashlib.sha256(data).hexdigest(), entry['sha256'])

    def test_pax_long_path_preserved(self):
        name = 'vendor/' + 'long-path-' * 16 + '/actual.php'
        self.assertEqual(self.extract(self.archive([name])).returncode, 0)
        self.assertEqual((self.path / 'out' / name).read_bytes(), b'actual synthetic bytes\n')

    def test_gnu_long_path_preserved(self):
        name = 'vendor/' + 'long-path-' * 16 + '/actual.php'
        self.assertEqual(self.extract(self.archive([name], tarfile.GNU_FORMAT)).returncode, 0)
        self.assertTrue((self.path / 'out' / name).is_file())

    def test_pax_traversal_rejected_before_any_member(self):
        archive = self.archive(['safe.php', 'directory/' + 'x' * 110 + '/../../outside.php'])
        self.assertEqual(self.extract(archive).returncode, 2)
        self.assertFalse((self.path / 'out' / 'safe.php').exists())

    def test_duplicate_extended_path_rejected(self):
        name = 'directory/' + 'x' * 110 + '/same.php'
        self.assertEqual(self.extract(self.archive([name, name])).returncode, 2)
        self.assertFalse((self.path / 'out' / name).exists())

    def test_links_rejected(self):
        archive = self.path / 'test.tar.gz'
        with tarfile.open(archive, 'w:gz') as stream:
            entry = tarfile.TarInfo('link'); entry.type = tarfile.SYMTYPE; entry.linkname = '/etc/passwd'
            stream.addfile(entry)
        self.assertEqual(self.extract(archive).returncode, 2)

    def test_existing_file_preserved(self):
        (self.path / 'out').mkdir(); (self.path / 'out' / 'safe.php').write_bytes(b'preserve')
        self.assertEqual(self.extract(self.archive(['safe.php'])).returncode, 2)
        self.assertEqual((self.path / 'out' / 'safe.php').read_bytes(), b'preserve')

    def test_restricted_hosting_extracts_without_probing_inaccessible_parents(self):
        result = self.extract(self.archive(['nested/actual.php']), restricted=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.path / 'out/nested/actual.php').read_bytes(), b'actual synthetic bytes\n')

    def test_restricted_hosting_still_rejects_linked_destination(self):
        (self.path / 'actual').mkdir()
        (self.path / 'out').symlink_to(self.path / 'actual', target_is_directory=True)
        result = self.extract(self.archive(['safe.php']), restricted=True)
        self.assertEqual(result.returncode, 2, result.stderr)
        self.assertFalse((self.path / 'actual/safe.php').exists())


if __name__ == '__main__':
    unittest.main()
