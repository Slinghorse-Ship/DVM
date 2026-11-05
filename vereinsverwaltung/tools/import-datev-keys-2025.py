"""Extract the verified official DATEV 2025 factual BU reference; no club or payment data."""
import collections
import hashlib
import json
from pathlib import Path
import re
import sys
from openpyxl import load_workbook

source = Path(sys.argv[1])
digest = hashlib.sha256(source.read_bytes()).hexdigest()
assert digest == 'b6a71ea6156c55d801b404d182deb89efe36240883afe9ab3f7571611a382fd7', 'Unverified DATEV source bytes'
book = load_workbook(source, data_only=True, read_only=True)
assert book.active['A1'].value == 'Steuerschlüssel'
columns = ['code', 'legacy', 'name', 'rate', 'ustva', 'zm_oss', 'function', 'eu_info', 'skr03_example', 'skr04_example', 'group', 'description']
keys, no_key, notes = [], [], []
for index, values in enumerate(book.active.values, 1):
    if index == 1:
        continue
    row = {k: '' if v is None else str(v).strip() for k, v in zip(columns, values)}
    row['source_row'] = index
    match = re.fullmatch(r'(\d+)(?:\s+(\d\)))?', row['code'])
    if match:
        row['code'], row['note'] = match[1], match[2] or ''
        keys.append(row)
    elif row['code'] == '*':
        no_key.append(row)
    elif row['code']:
        notes.append(row['code'])
duplicates = {code for code, count in collections.Counter(r['code'] for r in keys).items() if count > 1}
assert duplicates <= {'49'}, 'Unexpected duplicate key semantics require review'
data = {'year': 2025, 'source': 'https://wissensplattform.apps.datev.de/api/amr/knowledge-common/v1/entities/st81064837314243723_de.xlsx',
        'documentation': 'https://wissensplattform.apps.datev.de/help/document/0907054', 'edition': '2025-11-07',
        'scope': 'SKR03/SKR04 Regelbesteuerung; im SKR42-Kontenrahmen 2025 ausdrücklich als Schlüsselreferenz verlinkt',
        'sha256': digest, 'keys': keys, 'functions_without_key': no_key, 'notes': notes}
destination = Path(__file__).resolve().parents[1] / 'data/datev-keys-2025.json'
destination.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps({'keys': len(keys), 'duplicate_codes': sorted(duplicates), 'source_sha256': digest}))
