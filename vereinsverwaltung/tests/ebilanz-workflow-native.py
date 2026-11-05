"""Native validation of exact synthetic XML from the persisted MySQL workflow.

No certificate, sending, or rewriting/regenerating the retained output.
"""
import argparse
import ctypes as c
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('bridge', HERE.parent / 'tools/eric-bridge.py')
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)
NAMES = ['first', 'correction', 'successor', 'assets-first', 'assets-successor', 'calendar',
         'whole-first', 'whole-correction', 'whole-successor', 'whole-assets-first', 'whole-assets-successor',
         'whole-calendar', 'whole-calendar-correction', 'whole-calendar-assets', 'whole-calendar-assets-successor']


def run(config, directory, names=None):
    names = NAMES if names is None else names
    if not names or len(set(names)) != len(names) or any(n not in NAMES for n in names):
        raise ValueError('Select distinct known synthetic cases')
    results = []
    with tempfile.TemporaryDirectory(prefix='synthetic-workflow-ebalance-') as scratch:
        eric = bridge.Eric(config, Path(scratch))
        diagnostics = []
        cb_type = (c.WINFUNCTYPE if sys.platform == 'win32' else c.CFUNCTYPE)(None, c.c_char_p, c.c_int, c.c_char_p, c.c_void_p)
        callback = cb_type(lambda category, level, message, user: diagnostics.append((message or b'').decode('utf8', errors='replace')))
        eric.log_callback_api(c.cast(callback, c.c_void_p), 0, None)
        original = eric.process

        def validation_only(xml, version, flags, *rest):
            if flags != 2:
                raise RuntimeError('Validation only; transmission is prohibited')
            return original(xml, version, flags, *rest)

        eric.process = validation_only
        try:
            for name in names:
                raw = (directory / ('balance-period-workflow-' + name + '.xml')).read_bytes()
                xml = raw.decode('utf8')
                tree = ET.fromstring(xml)
                local = lambda node: node.tag.rsplit('}', 1)[-1]
                if [n.text for n in tree.iter() if local(n) == 'Testmerker'] != ['700000004']:
                    raise ValueError('Only explicitly marked synthetic test XML is permitted')
                diagnostics.clear()
                result = eric.run(xml, 'Bilanz_6_9')
                row = {'case': name, 'xml_sha256': hashlib.sha256(raw).hexdigest(), 'code': result['code'], 'sent': False, 'transport_attempted': False}
                if result['code'] != 0:
                    row['message'] = result.get('message', '')
                    row['diagnostics'] = diagnostics[-15:]
                    response = ET.fromstring(result['response_xml'])
                    row['validation_errors'] = [n.text for n in response.iter() if local(n) in ('Text', 'FachlicheFehlerId')]
                results.append(row)
                print(json.dumps(row, ensure_ascii=False), flush=True)
        finally:
            eric.end()
    return {'passed': len(results) == len(names) and all(r['code'] == 0 for r in results), 'sent': False, 'transport_attempted': False, 'cases': results}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True, type=Path)
    parser.add_argument('--xml-directory', required=True, type=Path)
    parser.add_argument('--report', required=True, type=Path)
    parser.add_argument('--case', action='append', choices=NAMES, dest='cases')
    args = parser.parse_args()
    result = run(json.loads(args.config.read_text(encoding='utf-8-sig')), args.xml_directory, args.cases)
    args.report.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf8')
    raise SystemExit(0 if result['passed'] else 1)
