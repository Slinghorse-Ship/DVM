"""Layout and content checks against the actual renderer; no database or real recipients."""
from pathlib import Path
import importlib.util
import io
import json
import sys
from pypdf import PdfReader

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('render_document', root / 'tools' / 'render-document.py')
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)
data = json.loads((Path(sys.argv[1]) if len(sys.argv)>1 else Path(__file__).parent/'fixtures'/'letterhead.json').read_text('utf-8'))
tests = 0
def check(value, label):
    global tests
    if not value: raise AssertionError(label)
    tests += 1
    print('PASS', label)

pdf = renderer.render(data)
reader = PdfReader(io.BytesIO(pdf))
check(len(reader.pages) == 1, 'default document one page')
check(abs(float(reader.pages[0].mediabox.width) - 595.276) < .01 and abs(float(reader.pages[0].mediabox.height) - 841.89) < .01, 'A4 size')
text = reader.pages[0].extract_text()
for needle in ['Kulturhus Jaderberg', '120,00 €', 'ENTWURF', 'Alex Muster', 'Bankverbindung', 'Seite 1']:
    check(needle in text, 'PDF text '+needle)
image = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAQCAIAAAD4YuoOAAAAJ0lEQVR4nGMUMYpioCVgoqnpDKMWEAFG44AgGA0igmA0iAiCoR9EAC/GAMBRsiUSAAAAAElFTkSuQmCC'
with_logo=json.loads(json.dumps(data));with_logo['assets']={'logo':{'mime':'image/png','base64':image}}
logo_pdf = PdfReader(io.BytesIO(renderer.render(with_logo)))
check(len(logo_pdf.pages[0].images)==1, 'uploaded image embedded')
long_doc=json.loads(json.dumps(data));long_doc['text']['closing']='Änderungen für Grüße und Beiträge.\n' * 75
long_pdf=PdfReader(io.BytesIO(renderer.render(long_doc)))
check(len(long_pdf.pages)>=3, 'long text paginates')
for i,page in enumerate(long_pdf.pages):
    text=page.extract_text()
    check('Bankverbindung' in text and 'ENTWURF' in text and f'Seite {i+1}' in text, 'repeating footer and page number '+str(i+1))
for field in ['header_text','footer_left']:
    bad=json.loads(json.dumps(data));bad['paper'][field]='Überlange Kopf- oder Fußzeile\n'*50
    try: renderer.render(bad)
    except ValueError: check(True,'overflow blocked '+field)
    else: raise AssertionError('overflow not rejected')
donation=json.loads(json.dumps(data));donation['title']='Spendenbegleitschreiben';donation['text']['message']='Danke für eure Unterstützung.\n**Viele Grüße**'
donation_pdf=PdfReader(io.BytesIO(renderer.render(donation)))
check('Danke für eure Unterstützung.' in donation_pdf.pages[0].extract_text(), 'donation message uses separate document body')
check('Vereinsbeitrag · Muster' not in donation_pdf.pages[0].extract_text(),'donation letter does not include invoice positions')
print(f'{tests} PDF checks passed')
