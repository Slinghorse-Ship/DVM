"""Reproduce the applicable BMF Anlage 14 wording and its itemized appendix.

No signature is drawn; UTF-8 JSON from the authorized service only. No URLs,
images, HTML or file paths from input are opened.
"""
import io
import json
import re
import sys
from pathlib import Path
from xml.sax.saxutils import escape
import reportlab
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak

for name, filename in [('Collection', 'Vera.ttf'), ('CollectionBold', 'VeraBd.ttf')]:
    pdfmetrics.registerFont(TTFont(name, str(Path(reportlab.__file__).parent / 'fonts' / filename)))
STYLE = ParagraphStyle('body', fontName='Collection', fontSize=9, leading=12, spaceAfter=8)
TITLE = ParagraphStyle('title', parent=STYLE, fontName='CollectionBold', fontSize=14, leading=18, spaceAfter=12)
SMALL = ParagraphStyle('small', parent=STYLE, fontSize=8, leading=10)

def text(value, limit=2000):
    if not isinstance(value, str) or len(value) > limit or any(ord(c) < 32 and c != '\n' for c in value):
        raise ValueError('Invalid bounded text')
    return escape(value).replace('\n', '<br/>')

def money(cents):
    if type(cents) is not int or not 0 < cents <= 9999999999:
        raise ValueError('Invalid amount')
    return f'{cents // 100:,}'.replace(',', '.') + f',{cents % 100:02d}'

def date(value):
    if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', value):
        raise ValueError('Invalid date')
    return value[8:] + '.' + value[5:7] + '.' + value[:4]

def render(data):
    collection = data['collection']; form = collection['form']; rows = collection['rows']
    if not isinstance(rows, list) or not 1 <= len(rows) <= 100:
        raise ValueError('Invalid appendix length')
    issued = bool(data.get('receipt_number'))
    number = data.get('receipt_number', '')
    if issued and (not re.fullmatch(r'ZB-\d{4}-[A-F0-9]{12}', number) or data.get('signature_mode') != 'handwritten'):
        raise ValueError('Invalid certificate metadata')
    if sum(r['amount_cents'] for r in rows) != collection['amount_cents']:
        raise ValueError('Appendix does not balance')
    p = lambda s, style=STYLE: Paragraph(s, style)
    story = [p(text(number) if issued else 'ENTWURF - NICHT ZUR VORLAGE BEIM FINANZAMT', SMALL), p('Aussteller', SMALL), p(text(form['issuer'])), p('Sammelbestätigung über Geldzuwendungen/Mitgliedsbeiträge', TITLE), p('im Sinne des § 10b des Einkommensteuergesetzes an eine der in § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes bezeichneten Körperschaften, Personenvereinigungen oder Vermögensmassen'), p('Name und Anschrift des Zuwendenden:', SMALL), p(text(collection['donor']))]
    words = ['null','eins','zwei','drei','vier','fünf','sechs','sieben','acht','neun']
    amount = collection['amount_cents']; spelled = ' - '.join(words[int(x)] for x in str(amount // 100)) + ' Euro ' + ' - '.join(words[int(x)] for x in f'{amount % 100:02d}') + ' Cent'
    story += [p('Gesamtbetrag: ' + money(amount) + ' EUR<br/>In Buchstaben: X ' + spelled + ' X<br/>Zeitraum: ' + date(collection['from']) + ' bis ' + date(collection['to']))]
    if form['notice_type'] in ('exemption', 'corporate'):
        story += [p('Wir sind wegen Förderung ' + text(form['purpose']) + ' nach dem Freistellungsbescheid bzw. nach der Anlage zum Körperschaftsteuerbescheid des Finanzamtes ' + text(form['tax_office']) + ', StNr. ' + text(form['tax_number']) + ', vom ' + text(form['notice_date']) + ' für den letzten Veranlagungszeitraum ' + text(form['assessment_period']) + ' nach § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes von der Körperschaftsteuer und nach § 3 Nr. 6 des Gewerbesteuergesetzes von der Gewerbesteuer befreit.')]
    elif form['notice_type'] == 'assessment':
        story += [p('Die Einhaltung der satzungsmäßigen Voraussetzungen nach den §§ 51, 59, 60 und 61 AO wurde vom Finanzamt ' + text(form['tax_office']) + ', StNr. ' + text(form['tax_number']) + ', mit Bescheid vom ' + text(form['notice_date']) + ' nach § 60a AO gesondert festgestellt. Wir fördern nach unserer Satzung ' + text(form['purpose']) + '.')]
    else:
        raise ValueError('Missing tax authority')
    story += [p('Es wird bestätigt, dass die Zuwendung nur zur Förderung ' + text(form['purpose']) + ' verwendet wird.')]
    if collection['non_deductible_memberships']:
        story += [p('Es wird bestätigt, dass es sich nicht um einen Mitgliedsbeitrag handelt, dessen Abzug nach § 10b Abs. 1 des Einkommensteuergesetzes ausgeschlossen ist.')]
    story += [p('Es wird bestätigt, dass über die in der Gesamtsumme enthaltenen Zuwendungen keine weiteren Bestätigungen, weder formelle Zuwendungsbestätigungen noch Beitragsquittungen oder Ähnliches ausgestellt wurden und werden.'), p('Ob es sich um den Verzicht auf Erstattung von Aufwendungen handelt, ist der Anlage zur Sammelbestätigung zu entnehmen.'), Spacer(1, 12), p(text(form['signature_place_date']) + '  ______________________________', SMALL), p('(Ort, Datum und Unterschrift des Zuwendungsempfängers)', SMALL), p('Hinweis: Wer vorsätzlich oder grob fahrlässig eine unrichtige Zuwendungsbestätigung erstellt oder veranlasst, dass Zuwendungen nicht zu den in der Zuwendungsbestätigung angegebenen steuerbegünstigten Zwecken verwendet werden, haftet für die entgangene Steuer (§ 10b Abs. 4 EStG, § 9 Abs. 3 KStG, § 9 Nr. 5 GewStG).', SMALL), p('Diese Bestätigung wird nicht als Nachweis für die steuerliche Berücksichtigung der Zuwendung anerkannt, wenn das Datum des Freistellungsbescheides länger als 5 Jahre bzw. das Datum der Feststellung der Einhaltung der satzungsmäßigen Voraussetzungen nach § 60a Abs. 1 AO länger als 3 Jahre seit Ausstellung des Bescheides zurückliegt (§ 63 Abs. 5 AO).', SMALL), PageBreak(), p('Anlage zur Sammelbestätigung', TITLE), p(text(number) + '<br/>' + text(collection['donor']) + '<br/>Zeitraum: ' + date(collection['from']) + ' bis ' + date(collection['to']))]
    grid = [[p('Datum', SMALL), p('Art der Zuwendung', SMALL), p('Verzicht auf Erstattung von Aufwendungen', SMALL), p('Betrag EUR', SMALL)]]
    for row in rows:
        if row['type'] not in ('Geldzuwendung', 'Mitgliedsbeitrag') or type(row['waiver']) is not bool:
            raise ValueError('Invalid contribution type')
        grid.append([p(date(row['date']), SMALL), p(text(row['type']), SMALL), p('Ja' if row['waiver'] else 'Nein', SMALL), p(money(row['amount_cents']), SMALL)])
    grid.append([p('Gesamtsumme', SMALL), '', '', p(money(amount), SMALL)])
    table = Table(grid, colWidths=[85, 155, 155, 104], repeatRows=1, hAlign='LEFT')
    table.setStyle(TableStyle([('GRID',(0,0),(-1,-1),0.4,colors.grey),('VALIGN',(0,0),(-1,-1),'TOP'),('BACKGROUND',(0,0),(-1,0),colors.HexColor('#eef2f6')),('LEFTPADDING',(0,0),(-1,-1),7),('RIGHTPADDING',(0,0),(-1,-1),7),('TOPPADDING',(0,0),(-1,-1),7),('BOTTOMPADDING',(0,0),(-1,-1),5)]))
    story.append(table)
    out = io.BytesIO()
    def footer(canvas, doc):
        canvas.setFont('Collection', 8);canvas.drawString(48, 23, (number or 'Entwurf') + ' · Seite ' + str(doc.page))
    SimpleDocTemplate(out, pagesize=A4, rightMargin=48, leftMargin=48, topMargin=34, bottomMargin=40, title='Sammelzuwendungsbestätigung', author='Vereinsverwaltung').build(story,onFirstPage=footer,onLaterPages=footer)
    return out.getvalue()

if __name__ == '__main__':
    raw = sys.stdin.buffer.read(1048577)
    if len(raw) > 1048576: raise ValueError('Input too large')
    sys.stdout.buffer.write(render(json.loads(raw.decode('utf-8'))))
