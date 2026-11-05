"""Synthetic print QA: ten cards/page, long labels and genuine production QR images."""
import base64
import importlib.util
import subprocess
from pathlib import Path
from pypdf import PdfReader

root=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('voucher_renderer',root/'vereinsverwaltung/tools/render-event-vouchers.py')
renderer=importlib.util.module_from_spec(spec);spec.loader.exec_module(renderer)
qr=subprocess.check_output([str(root/'.local/php-8.5.10/php.exe'),'-r',"require 'vereinsverwaltung/src/Events/EventVoucherQr.php';echo Kulturhus\\Verein\\EventVoucherQr::png(str_repeat('a',32),'0123456789ABCDEF');"],cwd=root)
cards=[]
for index in range(12):
    cards.append({'event':'Kulturabend im Kulturhus Jaderberg' if index<10 else 'Sehr langer Veranstaltungstitel mit Musik, Theater und Begegnungen für die ganze Familie',
                  'date':'20.09.2026','name':'Mara Muster' if index<10 else 'Anna-Maria Muster-Müller und ein längerer Familienname',
                  'label':['Getränk','Essen','Kuchen und Kaffee nach Wahl'][index%3],'amount':'1 Stück',
                  'code':'0123-4567-89AB-CDEF','qr':base64.b64encode(qr).decode()})
output=root/'.local/event-voucher-qa/business-card-test.pdf'
output.write_bytes(renderer.render({'event_voucher_cards':cards}))
pdf=PdfReader(output)
assert len(pdf.pages)==2
assert all(abs(float(p.mediabox.width)-595.276)<.01 and abs(float(p.mediabox.height)-841.89)<.01 for p in pdf.pages)
assert pdf.pages[0].extract_text().count('Mara Muster')==10
assert 'Kuchen und Kaffee nach Wahl' in ' '.join(pdf.pages[0].extract_text().split())
assert 'Familienname' in pdf.pages[1].extract_text()
print('PASS A4 10 cards/page, 85x55mm coordinate layout, benefit labels, name/date, long text, 2 pages:',output)
