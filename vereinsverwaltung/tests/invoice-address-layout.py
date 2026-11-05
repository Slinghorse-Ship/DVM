"""Read real PDF text and positions; no network or external recipients."""
import base64
import copy
import importlib.util
import io
import json
import re
import sys
import subprocess
import xml.etree.ElementTree as ET
from pathlib import Path
from pypdf import PdfReader

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('address_renderer', root / 'tools/render-document.py')
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)
checks = 0

def check(ok, label):
    global checks
    if not ok:
        raise AssertionError(label)
    checks += 1
    print('PASS ' + label)

def compact(text):
    return re.sub(r'\s+', '', text)

def inspect(pdf, recipient, position, label, sender=None):
    reader = PdfReader(io.BytesIO(pdf))
    check(bool(reader.pages), label + ' has real PDF pages')
    bounding = ET.fromstring(subprocess.run(['pdftotext','-bbox','-','-'], input=pdf, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout)
    pages = bounding.findall('.//{http://www.w3.org/1999/xhtml}page')
    check(len(pages) == len(reader.pages), label + ' independent Poppler layout pages agree')
    for index, page in enumerate(reader.pages):
        text = page.extract_text()
        check(compact(recipient) in compact(text), label + ' complete literal recipient page ' + str(index+1))
        if sender is not None:
            check(compact(sender) in compact(text), label + ' complete literal sender page ' + str(index+1))
        check('Seite ' + str(index+1) in text, label + ' repeats page number')
        words = pages[index].findall('.//{http://www.w3.org/1999/xhtml}word')
        address = []
        wanted = compact(recipient)
        address_words = [word for word in words if 55 <= float(word.attrib['xMin']) and float(word.attrib['xMax']) <= 300]
        for start in range(len(address_words)):
            joined = ''
            for end in range(start, len(address_words)):
                joined += compact(address_words[end].text or '')
                if not wanted.startswith(joined):
                    break
                if joined == wanted:
                    address = address_words[start:end+1]
                    break
            if address:
                break
        check(bool(address), label + ' independent PDF bounds contain complete recipient')
        address_bottom = max(float(word.attrib['yMax']) for word in address)
        body = [word for word in words if word not in address and word.text in ['Rechnung','Weitere','Literal'] and float(word.attrib['yMin']) > 150]
        if body:
            check(min(float(word.attrib['yMin']) for word in body) - address_bottom >= 15,
                  label + ' address and body do not overlap')
        check(all(0 <= float(word.attrib['xMin']) <= float(word.attrib['xMax']) <= 596
                  and 0 <= float(word.attrib['yMin']) <= float(word.attrib['yMax']) <= 843 for word in words), label + ' text remains on A4')
    all_text = ''.join(page.extract_text() for page in reader.pages)
    check(compact(position) in compact(all_text), label + ' complete literal invoice position')
    return reader

base = json.loads((root / 'tests/fixtures/letterhead.json').read_text())
base.update(kind='invoice', draft=False, date='08.09.2026', recipient='Firma * Original * & <Name>\nTestweg 1\n12345 Musterstadt')
base['paper']['sender_name'] = 'Verein __Original__'
base['positions'][0]['description'] = 'Leistung **Original** & <Position>'
literal_pdf = inspect(renderer.render(base), base['recipient'], base['positions'][0]['description'], 'literal data', base['paper']['sender_name'])
check('Anschrift' in literal_pdf.pages[0].extract_text() and '**Anschrift**' not in literal_pdf.pages[0].extract_text(), 'intentional footer template emphasis remains formatted')
for kind in ['invoice', 'letter']:
    large = copy.deepcopy(base)
    large['kind'] = kind
    large['recipient'] = ('Name ' * 40).strip() + '\n' + ('Straße ' * 28).strip() + '\n12345 ' + ('Stadt ' * 30).strip()
    for key, value in [('sender_name', 'Verein '), ('sender_street', 'Straße '), ('sender_address2', 'Zusatz '), ('sender_city', 'Stadt ')]:
        large['paper'][key] = (value * 24).strip()
    if kind == 'letter':
        large['text']['message'] = 'Literal brief body\n\n' + 'Weitere vollständige Zeile.\n' * 100
        position = 'Literal brief body'
    else:
        large['text']['closing'] += '\n' + 'Weitere vollständige Zeile.\n' * 100
        position = large['positions'][0]['description']
    result = inspect(renderer.render(large), large['recipient'], position, 'large ' + kind, large['paper']['sender_name'])
    check(len(result.pages) > 1, kind + ' complete long body paginates below address')
zero = copy.deepcopy(base)
zero['recipient'] = '0\n0\n0'
inspect(renderer.render(zero), zero['recipient'], zero['positions'][0]['description'], 'literal zero')
unsafe = copy.deepcopy(base)
unsafe['recipient'] = 'Unusable address\n' * 100
try:
    renderer.render(unsafe)
except ValueError:
    check(True, 'unusable address is refused without clipping')
else:
    raise AssertionError('unusable address must not overlap body or footer')
if len(sys.argv) > 1:
    for item in json.loads(Path(sys.argv[1]).read_text()):
        inspect(base64.b64decode(item['pdf'], validate=True), item['recipient'], item['position'], item['label'], item['sender'])
print(json.dumps({'suite':'invoice-address-layout-pdf', 'checks':checks, 'external_actions':0}))
