#!/usr/bin/env python3
"""Build an unsigned iOS device app and package it for AltStore Classic."""
import argparse
import datetime
import hashlib
import json
import os
import platform
import plistlib
import re
import struct
import subprocess
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def device_executable(path, main=False):
    """Require an actual ARM64 iOS Mach-O, never a simulator or a renamed file."""
    data = path.read_bytes()
    slices = [data]
    if data[:4] in (b'\xca\xfe\xba\xbe', b'\xca\xfe\xba\xbf'):
        wide = data[:4] == b'\xca\xfe\xba\xbf'
        count = struct.unpack_from('>I', data, 4)[0]
        if not 1 <= count <= 16:
            raise ValueError('Invalid universal executable')
        slices = []
        for i in range(count):
            values = struct.unpack_from('>IIQQII' if wide else '>IIIII', data, 8 + i * (32 if wide else 20))
            offset, size = values[2:4]
            if offset + size > len(data) or size < 32:
                raise ValueError('Invalid executable slice')
            slices.append(data[offset:offset + size])
    arm64 = False
    for item in slices:
        if item[:4] != b'\xcf\xfa\xed\xfe' or len(item) < 32:
            raise ValueError('A 64-bit Apple device executable is required')
        _, cpu, _, kind, count, commands_size, _, _ = struct.unpack_from('<8I', item)
        if cpu != 0x100000c or kind not in ((2,) if main else (2, 6, 8)) or count > 10000 or 32 + commands_size > len(item):
            raise ValueError('Only ARM64 device executables are supported')
        position, ios = 32, False
        for _ in range(count):
            command, size = struct.unpack_from('<II', item, position)
            if size < 8 or position + size > 32 + commands_size:
                raise ValueError('Invalid Mach-O command')
            if command == 0x32:
                if size < 24 or struct.unpack_from('<I', item, position + 8)[0] != 2:
                    raise ValueError('Simulator and non-iOS binaries cannot be installed with AltStore')
                ios = True
            elif command == 0x25:
                ios = True
            position += size
        if position != 32 + commands_size or not ios:
            raise ValueError('The executable must identify the iOS device platform')
        arm64 = True
    if not arm64:
        raise ValueError('ARM64 is required')


def inspect(app):
    if app.suffix != '.app' or app.is_symlink() or not app.is_dir():
        raise ValueError('A real device .app directory is required')
    info = plistlib.loads((app / 'Info.plist').read_bytes())
    expected = {'CFBundleIdentifier': 'de.deinvereinsmanager.app', 'CFBundlePackageType': 'APPL'}
    if any(info.get(k) != v for k, v in expected.items()) or info.get('CFBundleSupportedPlatforms') != ['iPhoneOS']:
        raise ValueError('Use the DVM Release-iphoneos product, never a simulator bundle')
    if set(info.get('UIDeviceFamily', [])) != {1, 2}:
        raise ValueError('Both iPhone and iPad must be supported')
    for field in ('CFBundleShortVersionString', 'CFBundleVersion', 'MinimumOSVersion'):
        if not re.fullmatch(r'\d+(?:\.\d+){0,2}', str(info.get(field, ''))):
            raise ValueError('Compiled version metadata is required: ' + field)
    transport=info.get('NSAppTransportSecurity', {})
    if not isinstance(transport, dict) or any(transport.get(name) for name in ('NSAllowsArbitraryLoads', 'NSAllowsArbitraryLoadsInWebContent', 'NSAllowsArbitraryLoadsForMedia', 'NSAllowsLocalNetworking')):
        raise ValueError('Debug HTTP exceptions are not permitted in the release package')
    executable = info.get('CFBundleExecutable', '')
    if not re.fullmatch(r'[A-Za-z0-9_.-]+', executable):
        raise ValueError('Invalid app executable')
    device_executable(app / executable, main=True)
    for file in app.rglob('*'):
        if file.is_symlink():
            target = os.readlink(file)
            if os.path.isabs(target) or not file.resolve().is_relative_to(app.resolve()) or not file.exists():
                raise ValueError('Bundle symlinks must resolve inside the app')
        elif not file.is_file() and not file.is_dir():
            raise ValueError('Unsupported bundle entry')
        if file.is_file() and (file.suffix == '.dylib' or (file.parent.suffix == '.framework' and file.name == file.parent.stem)):
            device_executable(file)
    for locale in ('de', 'en'):
        for filename in ('Localizable.strings', 'InfoPlist.strings'):
            if not (app / (locale + '.lproj') / filename).is_file():
                raise ValueError('Both language bundles are required')
    if not (app / 'PrivacyInfo.xcprivacy').is_file():
        raise ValueError('The privacy manifest is required')
    return info


def package(app, output):
    app, output = app.absolute(), output.absolute()
    info = inspect(app)
    if output.suffix != '.ipa' or output.exists() or output.is_symlink() or output.resolve().is_relative_to(app.resolve()):
        raise ValueError('Use a new .ipa path outside the app')
    for suffix in ('.ipa.json', '.ipa.sha256'):
        if output.with_suffix(suffix).exists() or output.with_suffix(suffix).is_symlink():
            raise ValueError('Existing release evidence is never overwritten')
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.dvm-ipa-', dir=output.parent) as temporary:
        archive = Path(temporary) / 'DVM.ipa'
        with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=6) as z:
            for file in sorted(app.rglob('*')):
                name = 'Payload/' + app.name + '/' + file.relative_to(app).as_posix()
                entry = zipfile.ZipInfo(name + ('/' if file.is_dir() and not file.is_symlink() else ''), (2026, 1, 1, 0, 0, 0))
                entry.create_system = 3
                entry.external_attr = file.lstat().st_mode << 16
                entry.compress_type = zipfile.ZIP_DEFLATED
                z.writestr(entry, os.readlink(file).encode() if file.is_symlink() else b'' if file.is_dir() else file.read_bytes())
        with zipfile.ZipFile(archive) as z:
            if z.testzip() is not None:
                raise ValueError('IPA integrity check failed')
        result = {'format': 1, 'platform': 'ios', 'distribution': 'AltStore Classic',
                  'bundleIdentifier': info['CFBundleIdentifier'], 'version': info['CFBundleShortVersionString'],
                  'build': info['CFBundleVersion'], 'minimumOS': info['MinimumOSVersion'],
                  'bytes': archive.stat().st_size, 'sha256': digest(archive),
                  'signing': 'AltStore signs during installation; Apple account and AltServer required',
                  'device_acceptance': 'separate', 'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat()}
        # Hard linking publishes without overwriting an existing artifact, even on a race.
        os.link(archive, output)
    with output.with_suffix('.ipa.json').open('x') as f:
        json.dump(result, f, indent=2); f.write('\n')
    with output.with_suffix('.ipa.sha256').open('x') as f:
        f.write(result['sha256'] + '  ' + output.name + '\n')
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    pack = commands.add_parser('package', help='Package an already compiled Release-iphoneos .app')
    pack.add_argument('--app', type=Path, required=True)
    pack.add_argument('--output', type=Path, required=True)
    build = commands.add_parser('build', help='Build with Xcode and package for AltStore Classic')
    build.add_argument('--artifacts', type=Path, required=True)
    args = parser.parse_args()
    try:
        if args.command == 'package':
            result = package(args.app, args.output)
        else:
            if platform.system() != 'Darwin':
                raise ValueError('A real macOS host with Xcode is required to compile an iOS device app')
            artifacts = args.artifacts.resolve()
            if artifacts.is_relative_to(ROOT):
                raise ValueError('Build artifacts must stay outside the source checkout')
            artifacts.mkdir(parents=True, exist_ok=False)
            project = ROOT / 'native-app/ios/DVM.xcodeproj'
            lock = project / 'project.xcworkspace/xcshareddata/swiftpm/Package.resolved'
            before = lock.read_bytes()
            command = ['xcodebuild', 'build', '-project', str(project), '-scheme', 'DVM',
                       '-configuration', 'Release', '-destination', 'generic/platform=iOS',
                       '-derivedDataPath', str(artifacts / 'DeviceRelease'),
                       '-clonedSourcePackagesDirPath', str(artifacts / 'SourcePackages'),
                       '-onlyUsePackageVersionsFromResolvedFile', 'CODE_SIGNING_ALLOWED=NO']
            with (artifacts / 'xcodebuild.log').open('x') as log:
                subprocess.run(command, cwd=ROOT, stdout=log, stderr=subprocess.STDOUT, check=True)
            if lock.read_bytes() != before:
                raise ValueError('The locked manufacturer SDK changed')
            result = package(artifacts / 'DeviceRelease/Build/Products/Release-iphoneos/DVM.app', artifacts / 'DVM.ipa')
        print(json.dumps(result))
        return 0
    except (OSError, ValueError, struct.error, subprocess.SubprocessError) as error:
        print(str(error), file=__import__('sys').stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
