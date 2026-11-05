"""Synthetic native-PDF regression: spatial labels, never guessed column order."""
import importlib.util
import io
from pathlib import Path

import pypdfium2 as pdfium
from reportlab.pdfgen import canvas

spec=importlib.util.spec_from_file_location('recognition',Path(__file__).resolve().parents[1]/'tools'/'recognize-invoice.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
labels=['Rechnungsnummer','Bestellnummer','Kundennummer','Bestelldatum','Rechnungsdatum','Referenz']
values=['SYNTH-2025-009','ORDER.2025.008','12345678','19.07.2025','23.07.2025','']

def read_header(values, conflicting=None):
    stream=io.BytesIO();pdf=canvas.Canvas(stream,pagesize=(595,842));pdf.setFont('Helvetica',10)
    # Draw the complete label column first to reproduce native PDF text order.
    for index,label in enumerate(labels): pdf.drawString(280,750-index*16,label+':')
    for index,value in enumerate(values):
        if value: pdf.drawString(400,750-index*16,value)
    if conflicting:
        for index,(label,value) in enumerate(conflicting): pdf.drawString(280,590-index*16,label+': '+value)
    pdf.save()
    with pdfium.PdfDocument(stream.getvalue()) as document:
        page=document[0]
        try:
            textpage=page.get_textpage()
            try: text=textpage.get_text_range();hints=m.pdf_header_hints(textpage)
            finally: textpage.close()
        finally: page.close()
    return text,hints,m.fields_from_text(text,hints)

text,hints,r=read_header(values)
assert r['fields']['Rechnungsnummer']=='SYNTH-2025-009' and r['fields']['Rechnungsdatum']=='2025-07-23'
assert set(hints)=={'Rechnungsnummer: SYNTH-2025-009','Rechnungsdatum: 23.07.2025'}
assert 'Rechnungsnummer' not in m.fields_from_text(text)['fields']
assert 'Rechnungsdatum' not in m.fields_from_text(text)['fields']

for missing in range(len(labels)):
    changed=values.copy();changed[missing]=''
    # A date-like reference must never shift into the missing invoice date.
    changed[5]='25.07.2025'
    _,hints,r=read_header(changed)
    if missing==0: assert 'Rechnungsnummer' not in r['fields']
    else: assert r['fields']['Rechnungsnummer']=='SYNTH-2025-009'
    if missing==4: assert 'Rechnungsdatum' not in r['fields']
    else: assert r['fields']['Rechnungsdatum']=='2025-07-23'
    assert all('25.07.2025' not in hint for hint in hints)

changed=values.copy();changed[4]='31.02.2025'
assert 'Rechnungsdatum' not in read_header(changed)[2]['fields']
_,_,r=read_header(values,[('Rechnungsnummer','SYNTH-2025-010'),('Rechnungsdatum','24.07.2025')])
assert 'Rechnungsnummer' not in r['fields'] and 'Rechnungsdatum' not in r['fields']
assert any('mehrere Angaben' in warning for warning in r['warnings'])
changed=values.copy();changed[0]='SYNTH-2025-009 12345678'
assert 'Rechnungsnummer' not in read_header(changed)[2]['fields']
print('PASS native PDF geometry: labelled invoice number/date; all six missing-value cases; date-like reference never substituted; invalid dates, conflicting values and non-unique row withheld')
