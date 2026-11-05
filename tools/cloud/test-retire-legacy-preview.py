"""Guard retirement against unrelated PHP servers and reused process IDs."""
import importlib.util
from pathlib import Path
import signal
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('retire_preview', Path(__file__).with_name('retire-legacy-preview.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
ARGV = b'/workspace/.setup/runtime/usr/bin/php8.4\0-c\0/workspace/.setup/php-conf/php.ini\0-S\0' + b'127.0.0.1:8107\0-t\0vereinsverwaltung/native/Web\0vereinsverwaltung/native/Web/router.php\0'


class Retirement(unittest.TestCase):
    def test_only_exact_owned_legacy_server(self):
        self.assertTrue(module.is_legacy_server(ARGV, 123, 123))
        for args, owner in [(ARGV, 456), (ARGV.replace(b'8107', b'8111'), 123),
                            (ARGV.replace(b'php8.4', b'python'), 123),
                            (ARGV.replace(b'native/Web/router.php', b'public/index.php'), 123),
                            (ARGV + b'other\0', 123)]:
            self.assertFalse(module.is_legacy_server(args, owner, 123))

    def test_signals_bound_descriptor_only(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / '123').mkdir()
            (root / '123' / 'cmdline').write_bytes(ARGV)
            (root / '456').mkdir()
            (root / '456' / 'cmdline').write_bytes(b'unrelated\0')
            with patch.object(module, 'Path', return_value=root), \
                 patch.object(module.os, 'pidfd_open', return_value=98) as opened, \
                 patch.object(module.signal, 'pidfd_send_signal') as sent, \
                 patch.object(module.os, 'close') as closed:
                self.assertEqual(module.retire(), 1)
                opened.assert_called_once_with(123)
                sent.assert_called_once_with(98, signal.SIGTERM)
                closed.assert_called_once_with(98)

    def test_recheck_before_signal(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / '123').mkdir()
            command = root / '123' / 'cmdline'
            command.write_bytes(ARGV)
            def reused(pid):
                command.write_bytes(b'unrelated\0')
                return 98
            with patch.object(module, 'Path', return_value=root), \
                 patch.object(module.os, 'pidfd_open', side_effect=reused), \
                 patch.object(module.signal, 'pidfd_send_signal') as sent, \
                 patch.object(module.os, 'close') as closed:
                self.assertEqual(module.retire(), 0)
                sent.assert_not_called()
                closed.assert_called_once_with(98)


if __name__ == '__main__':
    unittest.main()
