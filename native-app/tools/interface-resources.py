#!/usr/bin/env python3
"""Generate only fixed DE/EN interface resources; original payloads are never input."""
import argparse
import json
import re
import sys
from pathlib import Path
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]


def outputs():
    catalogue = json.loads((ROOT / 'shared/interface-messages.json').read_text())
    messages = catalogue['messages']
    references = set()
    for source in (ROOT / 'android/app/src').rglob('*.java'):
        references.update(re.findall(r'R\.string\.(native_[a-z0-9_]+)', source.read_text()))
    for source in (ROOT / 'ios').rglob('*.m'):
        references.update(re.findall(r'NSLocalizedString\(@"(native_[a-z0-9_]+)"', source.read_text()))
    references.update(catalogue['privacy'].values())
    if not references <= messages.keys():
        raise ValueError('Every native interface reference must declare both translations')
    for key, entry in messages.items():
        if not re.fullmatch(r'native_[a-z0-9_]+', key) or set(entry) != {'de', 'en'}:
            raise ValueError('Declared native interface key and both languages required')
        for value in entry.values():
            if not isinstance(value, str) or not value or '\0' in value:
                raise ValueError('Complete fixed interface text required')
        if re.findall(r'%(?:[0-9]+\$)?[@sdf]', entry['de']) != re.findall(r'%(?:[0-9]+\$)?[@sdf]', entry['en']):
            raise ValueError('Interface placeholders must retain their meaning')
    result = {}
    for locale in ('de', 'en'):
        tree = ET.Element('resources')
        if locale == 'de':
            ET.SubElement(tree, 'string', name='app_name', translatable='false').text = 'DVM'
        for key, entry in messages.items():
            ET.SubElement(tree, 'string', name=key).text = entry[locale].replace('\\', '\\\\').replace("'", "\\'").replace('"', '\\"')
        ET.indent(tree)
        folder = 'values' if locale == 'de' else 'values-en'
        result[ROOT / 'android/app/src/main/res' / folder / 'strings.xml'] = '<?xml version="1.0" encoding="utf-8"?>\n' + ET.tostring(tree, encoding='unicode') + '\n'
        quote = lambda text: json.dumps(text, ensure_ascii=False)
        result[ROOT / 'ios/DVM' / (locale + '.lproj') / 'Localizable.strings'] = ''.join(quote(key) + ' = ' + quote(entry[locale]) + ';\n' for key, entry in messages.items())
        result[ROOT / 'ios/DVM' / (locale + '.lproj') / 'InfoPlist.strings'] = ''.join(quote(key) + ' = ' + quote(messages[value][locale]) + ';\n' for key, value in catalogue['privacy'].items())
    locales = ET.Element('locale-config', {'xmlns:android': 'http://schemas.android.com/apk/res/android'})
    for locale in ('de', 'en'):
        ET.SubElement(locales, 'locale', {'android:name': locale})
    ET.indent(locales)
    result[ROOT / 'android/app/src/main/res/xml/locales_config.xml'] = '<?xml version="1.0" encoding="utf-8"?>\n' + ET.tostring(locales, encoding='unicode') + '\n'
    script = ROOT / 'shared/native-client.js'
    code = script.read_text()
    used = set(re.findall(r"nativeText\('(native_[a-z0-9_]+)'\)", code))
    if not used or not used <= messages.keys():
        raise ValueError('Every shared interface call must have both declared translations')
    selected = {locale: {key: messages[key][locale] for key in sorted(used)} for locale in ('de', 'en')}
    replacement = '  /* DVM_INTERFACE_START */\n  const interfaceMessages=' + json.dumps(selected, ensure_ascii=False, separators=(',', ':')) + ';\n  /* DVM_INTERFACE_END */'
    code, count = re.subn(r'  /\* DVM_INTERFACE_START \*/.*?/\* DVM_INTERFACE_END \*/', lambda _: replacement, code, count=1, flags=re.S)
    if count != 1:
        raise ValueError('The one fixed interface resource region is required')
    result[script] = code
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    expected = outputs()
    stale = []
    for path, value in expected.items():
        if args.check:
            if not path.is_file() or path.read_text() != value:
                stale.append(str(path.relative_to(ROOT)))
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(value)
    if stale:
        print(json.dumps({'status': 'stale', 'files': stale}))
        return 1
    print(json.dumps({'status': 'current', 'languages': ['de', 'en'], 'resource_files': len(expected), 'human_payloads_translated': False}))
    return 0


if __name__ == '__main__':
    sys.exit(main())
