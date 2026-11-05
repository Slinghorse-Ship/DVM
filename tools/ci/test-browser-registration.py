"""Fail a complete local acceptance when a native UI browser suite is omitted."""
import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[2]
runner = (ROOT / 'tools/ci/local-native.sh').read_text()
loop = re.search(r'for task_case in \\\n(.*?); do', runner, re.S)
assert loop, 'Complete browser invocation list missing'
cases = re.findall(r'\b(DVM_[A-Z0-9_]*BROWSER):([a-z0-9-]+)', loop[1])
assert len(cases) == len(set(cases)), 'Duplicate browser invocation'
registered = {suite for _, suite in cases}
# The installer deliberately has its own final invocation and browser arguments.
assert re.search(r'DVM_INSTALLER_BROWSER=1[^\n]*ci-native\.php native-webspace-installer', runner)
registered.add('native-webspace-installer')
missing = []
declared = []
declared_flags = set()
for file in sorted((ROOT / 'vereinsverwaltung/tests').glob('native-*.php')):
    flags = re.findall(r"getenv\('(DVM_[A-Z0-9_]*BROWSER)'\)\s*===\s*'1'", file.read_text())
    declared_flags.update(re.findall(r'''getenv\(\s*['"](DVM_[A-Z0-9_]*BROWSER)['"]\s*\)''', file.read_text()))
    if flags:
        declared.append(file.stem)
        if file.stem not in registered:
            missing.append({'suite': file.stem, 'flags': sorted(set(flags))})
assert not missing, 'Native browser suites absent from full acceptance: ' + json.dumps(missing)
platform_flags = {'DVM_ANDROID_APP_BROWSER'}
missing_flags = declared_flags - {flag for flag, _ in cases} - {'DVM_INSTALLER_BROWSER'} - platform_flags
assert not missing_flags, 'Browser variants absent from full acceptance: ' + json.dumps(sorted(missing_flags))
assert all((ROOT / 'vereinsverwaltung/tests' / (suite + '.php')).is_file() for suite in registered)
print(json.dumps({'native_browser_entries': len(declared), 'registered_browser_cases': len(cases),
                  'missing': [], 'separate_platform_flags': sorted(platform_flags), 'external_actions': 0}))
