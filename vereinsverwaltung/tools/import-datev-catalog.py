"""Rebuild factual reference catalogs from pinned public DATEV sources.

Usage: python import-datev-catalog.py SKR42.pdf Steuerschluessel.xlsx
       python import-datev-catalog.py SKR42.pdf --year 2025
Requires pdfplumber/openpyxl. No network, credentials, or club data used.
Every import is a pending candidate. Existing files are never overwritten.
Ranges stay explicit; only main accounts ending in 0 are expanded for selection.
Ambiguous duplicate entries remain visible and are blocked for new classification.
"""
import collections
import hashlib
import json
import pathlib
import re
import sys
import argparse
import pdfplumber
from openpyxl import load_workbook
from datev_table import account_column, check_coverage, clean, statement_cells, statement_at
from datev_years import write_new, official_url, import_directory

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('pdf', type=pathlib.Path)
parser.add_argument('xlsx', type=pathlib.Path, nargs='?')
parser.add_argument('--year', type=int, default=2026)
parser.add_argument('--source-url', help='Official DATEV PDF URL for a year not yet pinned in this importer')
parser.add_argument('--expected-sha256', help='Required SHA-256 for an unpinned official PDF')
parser.add_argument('--candidate', action='store_true', help='Compatibility option: every import is now a pending candidate')
parser.add_argument('--edition', choices=['balance','euer'], default='balance', help='Keep Bilanz and EÜR sources separate')
parser.add_argument('--appendix-pages', type=int, help='Reviewed number of appendix pages at the end; required for unpinned PDFs')
parser.add_argument('--output-dir', type=pathlib.Path, help='Write the result to this directory (useful for isolated review runs)')
args = parser.parse_args()
pdf_path, xlsx_path, year = args.pdf, args.xlsx, args.year
base = pathlib.Path(__file__).resolve().parents[1]
try:destination=import_directory(base,args.output_dir,args.edition,year)
except ValueError as error:parser.error(str(error))
if year < 2000 or year > 2100:
    parser.error('Jahreszahl zwischen 2000 und 2100 erforderlich.')
sources = {
    2025: {'source':'https://help-center.apps.datev.de/api/amr/knowledge-common/v1/entities/st45264272267_de.pdf',
           'download_source':'https://tsg-sulzbach.de/wp-content/uploads/fix/DATEV-SKR42.pdf',
           'sha256':'dcf8080446f4fc0ddb1d67409fdabc3689dab598ac669d2330322f895402e69d'},
    2026: {'source':'https://download.datev-shop.de/X7xkGEfqdeYuynPtuXCf5YKaSZyEji6f/12021',
           'sha256':'643c9a0c764b5fa694e10c3a3239faafa8d28d193dba377f43a964c59f89fbe9'},
}
source = dict(sources.get(year, {})) if args.edition=='balance' else {}
if args.source_url:
    source['source'] = args.source_url
if args.expected_sha256:
    source['sha256'] = args.expected_sha256
if not source.get('source') or not source.get('sha256'):
    parser.error('Für einen neuen Jahrgang offizielle PDF-URL und erwartete SHA-256 angeben.')
if not official_url(source['source']):parser.error('Offizielle DATEV-Quelladresse mit HTTPS erforderlich.')
if args.edition!='balance' and source['sha256'] in {row['sha256'] for row in sources.values()}:
    parser.error('Dieses bekannte PDF gehört zum Bilanz-Kontenplan, nicht zur EÜR-Variante.')
if hashlib.sha256(pdf_path.read_bytes()).hexdigest() != source['sha256']:parser.error('PDF-Prüfsumme stimmt nicht überein.')
pinned=args.edition=='balance' and sources.get(year,{}).get('sha256')==source['sha256']
appendix_pages=args.appendix_pages if args.appendix_pages is not None else (2 if pinned else None)
if appendix_pages is None or not 0<=appendix_pages<=20:
    parser.error('Für eine neue Quelle Anzahl der Anhangseiten prüfen und mit --appendix-pages angeben.')
if xlsx_path and year != 2026:
    parser.error('Historical BU-key sources have not been verified; do not relabel the 2026 table.')
account_url = source['source']
key_url = 'https://wissensplattform.apps.datev.de/api/amr/knowledge-common/v1/entities/st63880282379_de.xlsx'

def save(name, data):
    write_new(destination / name, data)

# Fail before parsing anything if this would overwrite an earlier candidate.
for name in [f'skr42-{year}.json'] + (['datev-keys-2026.json'] if xlsx_path else []):
    if (destination/name).exists():parser.error('Prüfdatei existiert bereits; neuen Ausgabeordner verwenden: '+str(destination/name))

entries, ignored, printed = [], [], set()
with pdfplumber.open(pdf_path) as pdf:
    if len(pdf.pages)<8:raise ValueError('Unbekannter Aufbau des Original-PDFs.')
    if f'Gültig für {year}' not in (pdf.pages[0].extract_text() or ''):raise ValueError('PDF-Jahr stimmt nicht überein.')
    if appendix_pages>=len(pdf.pages):raise ValueError('Anzahl der Anhangseiten ist ungültig.')
    table_end=len(pdf.pages)-appendix_pages
    source_notes=[{'page':table_end+1+i,'text':page.extract_text() or ''} for i,page in enumerate(pdf.pages[table_end:])]
    # DATEV changes the number of appendix pages between editions. The last
    # two pages are explanatory material in the pinned 2025/2026 sources.
    # A new source requires an explicit check, never blindly dropping its last
    # two pages (which might now contain additional accounts).
    for pn, page in enumerate(pdf.pages[:table_end], 1):
        page = page.dedupe_chars()
        words = page.extract_words(x_tolerance=1)
        for left, right in [(100, 323), (340, 573)]:
            # The 2025 account table extends to y=807; a y=756 crop silently
            # lost the last accounts and cut off long descriptions. Use the
            # actual table divider/footer boundary, not a shortened text box.
            layout = account_column(page, words, left, right)
            if layout is None:
                continue
            anchor, box, expected = layout
            try:
                positions = statement_cells(page, words, anchor, box) if args.edition=='balance' else None
            except ValueError as error:
                raise ValueError(f'Seite {pn}, Kontenspalte {anchor}: {error}') from error
            # Independent word-coordinate coverage, before cropping text lines:
            # every printed five-digit account (or range endpoint) must survive.
            printed.update((pn, code) for code in expected)
            column = page.crop(box)
            lines = column.extract_text_lines(x_tolerance=1)
            current = None
            for line in lines:
                text = line['text'].strip()
                match = re.match(r'^(?:(S/AV|S/AM|AV|AM|S|F|R)\s+)?(\d{4})\s+(\d)(?:\s+(.*))?$', text)
                # Description references can also look like account numbers.
                starts_at_anchor = abs(line['x0']-anchor) < 2 or (line['x0'] < anchor and match and match[1])
                if match and starts_at_anchor:
                    if current and current['function']=='R' and match[3]=='9' and not match[1] and not match[4]:
                        current['end']=match[2]+match[3]
                        continue
                    current = {'code': match[2]+match[3], 'end': match[2]+match[3], 'function': match[1] or '', 'parts': [match[4] or ''], 'page': pn}
                    if positions is not None:
                        current['statement'] = statement_at(positions, line)
                    entries.append(current)
                    continue
                match = re.match(r'^-(\d{2,4})\s+(\d)(?:\s+(.*))?$', text)
                if match and current and anchor-6 < line['x0'] < anchor+10:
                    current['end'] = current['code'][:4-len(match[1])]+match[1]+match[2]
                    if match[3]: current['parts'].append(match[3])
                    continue
                if current and line['x0'] >= anchor+18:
                    current['parts'].append(text)
                else:
                    ignored.append({'page':pn,'text':text})

for entry in entries:
    entry['name'] = clean(entry.pop('parts')) or 'Reservierter Kontenbereich'
    if int(entry['end']) < int(entry['code']):
        entry['range_issue'] = 'Absteigender Bereich in Quelle; nicht erweitert'
accounts = {}
if not entries or not printed:raise ValueError('Keine vollständig erkannten Kontentabellen. Original manuell prüfen.')
check_coverage(entries, printed)
for entry in entries:
    numbers = [int(entry['code'])] + list(range((int(entry['code'])//10+1)*10, int(entry['end'])+1, 10))
    for number in numbers:
        code = f'{number:05}'
        row = dict(entry, code=code, range_start=entry['code'], range_end=entry['end'], derived=code!=entry['code'])
        row.pop('end')
        if code in accounts and accounts[code]['name'] != row['name']:
            accounts[code].setdefault('alternatives', []).append(row)
        else:
            accounts[code] = row
account_catalog={'chart':'SKR42','edition':args.edition,'year':year,**source,'source_entries':len(entries),'ranges':entries,'accounts':list(accounts.values()),'unassigned_lines':ignored,'source_notes':source_notes}
if args.edition=='balance':account_catalog['statement_schema']=1

if not xlsx_path:
    save(f'skr42-{year}.json',account_catalog)
    print(json.dumps({'year':year,'entries':len(entries),'accounts':len(accounts),'ambiguous':[c for c,r in accounts.items() if 'alternatives' in r],'unassigned_lines':len(ignored)},ensure_ascii=False))
    sys.exit(0)

book = load_workbook(xlsx_path, data_only=True)
if book.active['A1'].value!='Steuerschlüssel':raise ValueError('Unbekannter Aufbau der Buchungsschlüsselquelle.')
keys, no_key, notes = [], [], []
columns = ['code','legacy','name','rate','ustva','zm_oss','function','eu_info','skr03_example','skr04_example','group','description']
for index, values in enumerate(book.active.values,1):
    if index == 1: continue
    row = {k:('' if v is None else str(v).strip()) for k,v in zip(columns,values)}
    row['source_row'] = index
    # Footnote markers are kept separately; do not treat them as part of a BU key.
    match = re.fullmatch(r'(\d+)(?:\s+(\d\)))?', row['code'])
    if match:
        row['code']=match[1];row['note']=match[2] or '';keys.append(row)
    elif row['code']=='*': no_key.append(row)
    elif row['code']: notes.append(row['code'])
if {code for code,count in collections.Counter(r['code'] for r in keys).items() if count>1}!={'49'}:raise ValueError('Geänderte Buchungsschlüsselvarianten prüfen.')
save(f'skr42-{year}.json',account_catalog)
save('datev-keys-2026.json', {'year':2026,'source':key_url,'documentation':'https://wissensplattform.apps.datev.de/help/document/0907048','edition':'2025-12-29','scope':'SKR03/SKR04 Regelbesteuerung; vom SKR42-Kontenrahmen als Schlüsselreferenz verlinkt','sha256':hashlib.sha256(xlsx_path.read_bytes()).hexdigest(),'keys':keys,'functions_without_key':no_key,'notes':notes})
print(json.dumps({'entries':len(entries),'accounts':len(accounts),'ambiguous':[c for c,r in accounts.items() if 'alternatives' in r],'unassigned_lines':len(ignored),'keys':len(keys),'functions_without_key':len(no_key)},ensure_ascii=False))
