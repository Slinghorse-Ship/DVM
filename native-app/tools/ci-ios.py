#!/usr/bin/env python3
"""Run the real locked Xcode project on both available simulator families."""
import argparse
import json
import platform
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PROJECT = ROOT / 'native-app/ios/DVM.xcodeproj'
LOCK = PROJECT / 'project.xcworkspace/xcshareddata/swiftpm/Package.resolved'


def targets(devices):
    selected = {}
    runtimes = sorted(devices.get('devices', {}), key=lambda s: tuple(map(int, re.findall(r'\d+', s))), reverse=True)
    for runtime in runtimes:
        if '.iOS-' not in runtime:
            continue
        for device in devices['devices'][runtime]:
            if not device.get('isAvailable'):
                continue
            family = next((kind for kind in ('iPhone', 'iPad') if device.get('name', '').startswith(kind)), None)
            if family and family not in selected:
                if not re.fullmatch(r'[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{4}){3}-[A-Fa-f0-9]{12}', device.get('udid', '')):
                    raise ValueError('A real simulator identifier is required')
                selected[family] = {'name': device['name'], 'id': device['udid'], 'runtime': runtime}
    if set(selected) != {'iPhone', 'iPad'}:
        raise ValueError('Both an available iPhone and an available iPad simulator are required')
    return selected


def confirmed_tests(summary):
    if not isinstance(summary.get('passedTests'), int) or summary['passedTests'] < 6:
        raise ValueError('All four Origin methods, bundled language resources and actual UIKit correction must pass')
    if summary.get('failedTests') != 0 or summary.get('skippedTests') != 0:
        raise ValueError('Failed or skipped XCTest methods are not a successful acceptance')
    return summary['passedTests']


def pins(path):
    return sorted((p['identity'], p['state']['version'], p['state']['revision']) for p in json.loads(path.read_text())['pins'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--artifacts', type=Path, required=True)
    args = parser.parse_args()
    if platform.system() != 'Darwin':
        parser.error('Actual macOS and Xcode are required; Linux source inspection is not a build proof')
    output = args.artifacts.resolve()
    if output.is_relative_to(ROOT) or (output / 'result.json').exists():
        parser.error('Use a fresh artifact directory outside the source checkout')
    output.mkdir(parents=True, exist_ok=True)
    original_pins = pins(LOCK)
    result = {'suite': 'native-ios-simulators', 'status': 'failed', 'physical_device': False,
              'production_signing': False, 'live_payments': False, 'source_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(), 'pins': original_pins, 'devices': {}}

    def run(command, name, capture=False):
        with (output / (name + '.log')).open('w') as log:
            process = subprocess.Popen(command, cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, errors='replace')
            chunks = []
            for line in process.stdout:
                log.write(line)
                if capture:
                    chunks.append(line)
                else:
                    print(line, end='', flush=True)
            if process.wait():
                raise RuntimeError('Actual command failed: ' + name)
            return ''.join(chunks)

    try:
        run(['xcodebuild', '-version'], 'xcode-version')
        available = json.loads(run(['xcrun', 'simctl', 'list', 'devices', 'available', '--json'], 'simulators', True))
        selected = targets(available)
        (output / 'selected-simulators.json').write_text(json.dumps(selected, indent=2) + '\n')
        common = ['-project', str(PROJECT), '-scheme', 'DVM', '-clonedSourcePackagesDirPath', str(output / 'SourcePackages'), '-onlyUsePackageVersionsFromResolvedFile']
        run(['xcodebuild', '-resolvePackageDependencies', *common], 'resolve-locked-sdk')
        if pins(LOCK) != original_pins:
            raise RuntimeError('The locked SDK version or manufacturer revision changed')
        checkout = output / 'SourcePackages/checkouts/sumup-ios-sdk'
        revision = subprocess.check_output(['git', '-C', str(checkout), 'rev-parse', 'HEAD'], text=True).strip()
        if revision != next(pin[2] for pin in original_pins if pin[0] == 'sumup-ios-sdk'):
            raise RuntimeError('The actual SDK checkout differs from the locked manufacturer revision')
        result['actual_sdk_revision'] = revision
        failed = False
        release_phase = 'unsigned_device_release_build'
        try:
            run(['xcodebuild', 'build', *common, '-configuration', 'Release', '-destination', 'generic/platform=iOS', '-derivedDataPath', str(output / 'DeviceRelease'), 'CODE_SIGNING_ALLOWED=NO'], 'unsigned-device-release')
            result[release_phase] = 'passed'
            release_phase = 'unsigned_altstore_package'
            run([sys.executable, str(ROOT / 'native-app/tools/ios-release.py'), 'package', '--app', str(output / 'DeviceRelease/Build/Products/Release-iphoneos/DVM.app'), '--output', str(output / 'DVM.ipa')], 'unsigned-altstore-package')
            result[release_phase] = 'passed'
            result['altstore_ipa'] = json.loads((output / 'DVM.ipa.json').read_text())
        except RuntimeError as error:
            failed = True
            result[release_phase] = {'status': 'failed', 'error': str(error)}
        for family, device in selected.items():
            bundle = output / (family + '.xcresult')
            try:
                run(['xcodebuild', 'test', *common, '-destination', 'platform=iOS Simulator,id=' + device['id'], '-destination-timeout', '120', '-derivedDataPath', str(output / 'DerivedData'), '-resultBundlePath', str(bundle), '-only-testing:DVMTests', 'CODE_SIGNING_ALLOWED=NO'], family)
                summary = json.loads(run(['xcrun', 'xcresulttool', 'get', 'test-results', 'summary', '--path', str(bundle)], family + '-summary', True))
                (output / (family + '-summary.json')).write_text(json.dumps(summary, indent=2) + '\n')
                result['devices'][family] = {**device, 'status': 'passed', 'passed_tests': confirmed_tests(summary)}
            except (RuntimeError, ValueError, KeyError) as error:
                failed = True
                result['devices'][family] = {**device, 'status': 'failed', 'error': str(error)}
            if bundle.exists():
                subprocess.run(['xcrun', 'simctl', 'io', device['id'], 'screenshot', str(output / (family + '-diagnostic.png'))], check=False)
        if failed or pins(LOCK) != original_pins:
            raise RuntimeError('Both actual simulator runs and the locked SDK must pass')
        result['status'] = 'passed'
        return 0
    except (RuntimeError, ValueError, OSError, subprocess.SubprocessError) as error:
        result['error'] = str(error)
        print(str(error), file=sys.stderr)
        return 1
    finally:
        (output / 'result.json').write_text(json.dumps(result, indent=2) + '\n')
        print(json.dumps(result))


if __name__ == '__main__':
    sys.exit(main())
