"""Opt-in synthetic mapper -> exact installed schema -> native ERiC validation. Never sends."""
import argparse, hashlib, json, os, subprocess, sys
from pathlib import Path
from xml.etree import ElementTree as ET

p = argparse.ArgumentParser()
p.add_argument('--php', required=True)
p.add_argument('--runtime', required=True)
a = p.parse_args()
if os.environ.get('VEREIN_ERIC_TEST') != 'synthetic-only':
    raise SystemExit('Explicit synthetic-only test mode required')
repo = Path(__file__).resolve().parents[2]
env = {**os.environ, 'VEREIN_ERIC_RUNTIME': str(Path(a.runtime).resolve())}
def php(script, data=None, args=()):
    return subprocess.run([a.php, str(repo/'vereinsverwaltung/tests'/script), *args],
                          input=data, capture_output=True, check=True, env=env, timeout=30).stdout
mapped = json.loads(php('tax-auto-mapping.php', args=('--json',)))
assert mapped['ready'], mapped['issues']
source = {k: mapped[k] for k in ('data_version', 'fields', 'counts', 'header')}
xml = php('eric-form-roundtrip.php', json.dumps(source).encode()).decode('utf-8-sig')
request = {'action': 'validate', 'environment': 'test', 'data_version': mapped['data_version'],
           'xml': xml, 'xml_sha256': hashlib.sha256(xml.encode()).hexdigest()}
run = subprocess.run([sys.executable, '-B', str(repo/'vereinsverwaltung/tools/eric-bridge.py'), a.runtime],
                     input=json.dumps(request).encode(), capture_output=True, timeout=110)
result = json.loads(run.stdout)
errors = []
if result.get('response_xml'):
    errors = [n.text for n in ET.fromstring(result['response_xml']).iter()
              if n.tag.rsplit('}', 1)[-1] in ('Text', 'Feldidentifikator')]
print(json.dumps({'code': result.get('code'), 'fields': len(mapped['fields']), 'errors': errors,
                  'sent': False, 'synthetic_only': True}, ensure_ascii=True))
if result.get('code') != 0 or result.get('xml_sha256') != request['xml_sha256']:
    raise SystemExit(1)
