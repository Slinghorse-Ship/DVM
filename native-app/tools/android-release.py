#!/usr/bin/env python3
"""Sign an actual DVM release APK with a private, persistent Android key."""
import argparse
import hashlib
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def private_file(path):
    if path.is_symlink() or not path.is_file() or path.resolve().is_relative_to(ROOT):
        raise ValueError('Keys and password files must be regular files outside the checkout')
    details = path.stat()
    if details.st_mode & 0o077 or details.st_uid != os.getuid():
        raise ValueError('Private signing files must belong to this user and have mode 0600')


def key_create(key, password_file, keytool):
    private_file(password_file)
    password = password_file.read_text().rstrip('\r\n')
    if len(password) < 16 or '\n' in password or '\r' in password or '\0' in password:
        raise ValueError('Use a single private password of at least 16 characters')
    if key.exists() or key.is_symlink() or key.resolve().is_relative_to(ROOT):
        raise ValueError('Use a new private keystore path outside the checkout; existing keys are never replaced')
    key.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    environment = {**os.environ, 'DVM_SIGNING_PASSWORD': password}
    # Generate privately, then publish without overwriting a concurrent key.
    with tempfile.TemporaryDirectory(prefix='.dvm-key-', dir=key.parent) as directory:
        temporary = Path(directory) / 'release.p12'
        subprocess.run([str(keytool), '-genkeypair', '-keystore', str(temporary), '-storetype', 'PKCS12',
                        '-alias', 'dvm-release', '-keyalg', 'RSA', '-keysize', '3072', '-validity', '10000',
                        '-dname', 'CN=DVM Android Release', '-storepass:env', 'DVM_SIGNING_PASSWORD',
                        '-keypass:env', 'DVM_SIGNING_PASSWORD', '-noprompt'], env=environment, check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        temporary.chmod(0o600)
        os.link(temporary, key)
    return {'keystore_created': True, 'alias': 'dvm-release', 'backup_required': True}


def sign(apk, output, tools, key, password_file, alias, flavor):
    private_file(key); private_file(password_file)
    if not re.fullmatch(r'[A-Za-z0-9_.-]{1,80}', alias):
        raise ValueError('Invalid key alias')
    if output.suffix != '.apk' or output.exists() or not apk.is_file() or apk.resolve() == output.absolute():
        raise ValueError('An existing input APK and a fresh output APK path are required')
    for suffix in ('.apk.json', '.apk.sha256'):
        if output.with_suffix(suffix).exists():
            raise ValueError('Existing release evidence is never overwritten')
    build = tools / 'android-sdk/build-tools/36.0.0'
    apksigner, zipalign, aapt = (build / name for name in ('apksigner', 'zipalign', 'aapt'))
    java = tools / 'jdk/bin'
    environment = {**os.environ, 'JAVA_HOME': str(tools / 'jdk'), 'PATH': str(java) + os.pathsep + os.environ.get('PATH', '')}
    def run(command, binary=False):
        return subprocess.check_output([str(x) for x in command], env=environment, text=not binary, stderr=subprocess.PIPE)
    badging = run([aapt, 'dump', 'badging', apk])
    package = re.search(r"^package: name='([^']+)' versionCode='(\d+)' versionName='([^']+)'", badging, re.M)
    if not package or package[1] != 'de.deinvereinsmanager.app' or 'application-debuggable' in badging:
        raise ValueError('Only a non-debuggable DVM release APK may be distributed')
    minimum = re.search(r"^sdkVersion:'(\d+)'", badging, re.M)
    target = re.search(r"^targetSdkVersion:'(\d+)'", badging, re.M)
    if not minimum or int(minimum[1]) < 26 or not target or int(target[1]) < 36:
        raise ValueError('The release APK does not meet the DVM Android platform requirements')
    certificate = run([java / 'keytool', '-exportcert', '-keystore', key, '-alias', alias,
                       '-storepass:file', password_file], binary=True)
    expected = hashlib.sha256(certificate).hexdigest()
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.dvm-apk-', dir=output.parent) as directory:
        aligned, signed = Path(directory) / 'aligned.apk', Path(directory) / 'DVM.apk'
        run([zipalign, '-P', '16', '4', apk, aligned])
        run([apksigner, 'sign', '--ks', key, '--ks-key-alias', alias, '--ks-pass', 'file:' + str(password_file),
             '--v4-signing-enabled', 'false', '--out', signed, aligned])
        run([zipalign, '-c', '-P', '16', '4', signed])
        verified = run([apksigner, 'verify', '--verbose', '--print-certs', signed])
        actual = re.findall(r'^Signer #\d+ certificate SHA-256 digest: ([a-fA-F0-9]+)$', verified, re.M)
        if actual != [expected] or not re.search(r'Verified using v2 scheme.*: true', verified):
            raise ValueError('The APK must verify with exactly the selected persistent release certificate and v2 signing')
        result = {'format': 1, 'platform': 'android', 'flavor': flavor, 'package': package[1],
                  'versionCode': int(package[2]), 'version': package[3], 'minSdk': int(minimum[1]),
                  'targetSdk': int(target[1]), 'release_signed': True, 'certificate_sha256': expected,
                  'input_sha256': digest(apk),
                  'bytes': signed.stat().st_size, 'sha256': digest(signed),
                  'device_acceptance': 'separate', 'live_payments': False}
        os.link(signed, output)
    with output.with_suffix('.apk.json').open('x') as f:
        json.dump(result, f, indent=2); f.write('\n')
    with output.with_suffix('.apk.sha256').open('x') as f:
        f.write(result['sha256'] + '  ' + output.name + '\n')
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    create = commands.add_parser('key-create')
    create.add_argument('--keytool', type=Path, default=Path('keytool'))
    sign_parser = commands.add_parser('sign')
    sign_parser.add_argument('--tools', type=Path, required=True)
    sign_parser.add_argument('--input', type=Path, required=True)
    sign_parser.add_argument('--output', type=Path, required=True)
    sign_parser.add_argument('--flavor', choices=['standard', 'terminal'], required=True)
    sign_parser.add_argument('--alias', default='dvm-release')
    for sub in (create, sign_parser):
        sub.add_argument('--keystore', type=Path, required=True)
        sub.add_argument('--password-file', type=Path, required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        result = key_create(args.keystore, args.password_file, args.keytool) if args.command == 'key-create' else sign(
            args.input, args.output, args.tools, args.keystore, args.password_file, args.alias, args.flavor)
        print(json.dumps(result))
        return 0
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        # A tool's captured stderr can contain private paths or supplied credentials.
        print(str(error) if not isinstance(error, subprocess.SubprocessError) else 'Android signing tool failed; no successful release proof was issued', file=__import__('sys').stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
