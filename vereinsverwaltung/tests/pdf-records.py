"""Render regression: large paragraphs, tables, repeated headings and no trailing blank page."""
from pathlib import Path
import importlib.util
import io
from pypdf import PdfReader
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('record_renderer',ROOT/'tools/render-record.py')
renderer=importlib.util.module_from_spec(spec);spec.loader.exec_module(renderer)
tests=0
def check(ok,label):
    global tests
    if not ok:raise AssertionError(label)
    tests+=1;print('PASS',label)
record={'record':{'title':'Vorgangsnachweis · Testdaten','club':'Kulturhus · Testverein','reference':'Vorgang <script> & ÄÖÜ','created_at':'2026-09-05 12:00:00 UTC','warning':'Interner Nachweis','sections':[
    {'title':'Langer Vermerk','columns':['Angabe','Wert'],'rows':[['Beschreibung',('Zeile für Grüße und Rückgaben.\n'*100)+'ENDE DES VERMERKS']]},
    {'title':'Gegenstände','columns':['Gegenstand','Vereinbart','Zurück','Verlust','Offen'],'rows':[[f'Gegenstand {i} · ÄÖÜ','12 Stück','3','0','9'] for i in range(80)]}]}}
pdf=renderer.build(record);reader=PdfReader(io.BytesIO(pdf));check(len(reader.pages)>4,'long notes and table paginate')
texts=[p.extract_text() for p in reader.pages]
check('ENDE DES VERMERKS' in '\n'.join(texts) and 'Gegenstand 79' in texts[-1],'last note and final table row preserved')
check('<script>' in texts[0] and '& ÄÖÜ' in texts[0],'text escaped without HTML interpretation')
check('Langer Vermerk' in texts[0] and 'Zeile für Grüße' in texts[0],'long first row uses remaining first page space')
check(all('INTERNER NACHWEIS' in t and f'Seite {n+1}' in t for n,t in enumerate(texts)),'header and page numbering on all pages')
check(all(len(t.split())>15 for t in texts),'no empty page containing only header and footer')
check(all('Gegenstand\n' in t for t in texts if 'Gegenstand 2' in t or 'Gegenstand 79' in t),'table headings repeated after page breaks')
out=ROOT.parent/'tmp/pdfs/documents';out.mkdir(parents=True,exist_ok=True);(out/'long-record.pdf').write_bytes(pdf)
print(f'{tests} record PDF checks passed')
