#!/usr/bin/env python3
"""Retire only the exact former, owned cloud CMS server after Manager readiness."""
import os
from pathlib import Path
import signal


def is_legacy_server(arguments: bytes, owner: int, current_owner: int) -> bool:
    expected = [
        b'/workspace/.setup/runtime/usr/bin/php8.4',
        b'-c', b'/workspace/.setup/php-conf/php.ini',
        b'-S', b'127.0.0.1:8107',
        b'-t', b'vereinsverwaltung/native/Web',
        b'vereinsverwaltung/native/Web/router.php',
    ]
    return owner == current_owner and arguments.split(b'\0') == expected + [b'']


def retire() -> int:
    retired = 0
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():
            continue
        descriptor = None
        try:
            if entry.stat().st_uid != os.getuid():
                continue
            if not is_legacy_server((entry / 'cmdline').read_bytes(), entry.stat().st_uid, os.getuid()):
                continue
            # Bind the signal to this process, even if its PID is reused meanwhile.
            descriptor = os.pidfd_open(int(entry.name))
            if not is_legacy_server((entry / 'cmdline').read_bytes(), entry.stat().st_uid, os.getuid()):
                continue
            signal.pidfd_send_signal(descriptor, signal.SIGTERM)
            retired += 1
        except (FileNotFoundError, ProcessLookupError):
            pass
        finally:
            if descriptor is not None:
                os.close(descriptor)
    return retired


if __name__ == '__main__':
    print(f'Alte eigene CMS-Vorschau beendet: {retire()}; Bestände bleiben erhalten.')
