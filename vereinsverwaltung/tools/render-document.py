"""A4 draft preview; JSON on stdin, PDF on stdout. No network or user-provided paths."""
import base64
import io
import json
import re
import sys
from pathlib import Path
from xml.sax.saxutils import escape
import reportlab
from reportlab.lib import colors
from reportlab.lib.enums import TA_RIGHT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import BaseDocTemplate, Frame, PageTemplate, Paragraph, Spacer, Table, TableStyle, KeepTogether

FONTS = Path(reportlab.__file__).parent / 'fonts'
for name, file in [('KH', 'Vera.ttf'), ('KH-Bold', 'VeraBd.ttf'), ('KH-Italic', 'VeraIt.ttf'), ('KH-BoldItalic', 'VeraBI.ttf')]:
    pdfmetrics.registerFont(TTFont(name, str(FONTS / file)))
pdfmetrics.registerFontFamily('KH', normal='KH', bold='KH-Bold', italic='KH-Italic', boldItalic='KH-BoldItalic')

def markup(text):
    text = escape(str(text))
    text = re.sub(r'\*\*([^*\n]+)\*\*', r'<b>\1</b>', text)
    text = re.sub(r'__([^_\n]+)__', r'<u>\1</u>', text)
    text = re.sub(r'(?<!\*)\*([^*\n]+)\*(?!\*)', r'<i>\1</i>', text)
    return text.replace('\n', '<br/>')

def literal(text):
    return escape(str(text)).replace('\n', '<br/>')

def render(data):
    paper, values, texts = data['paper'], data['values'], data['text']
    # PDF/A requires embedded fonts, including the optional serif letterhead.
    serif = 'Times'
    if data.get('general_invoice') and paper['header_font'] == 'serif':
        font_root = Path(__file__).resolve().parent.parent / 'resources' / 'fonts' / 'liberation-serif'
        for name, suffix in [('KH-Serif', 'Regular'), ('KH-Serif-Bold', 'Bold'), ('KH-Serif-Italic', 'Italic'), ('KH-Serif-BoldItalic', 'BoldItalic')]:
            pdfmetrics.registerFont(TTFont(name, str(font_root / ('LiberationSerif-' + suffix + '.ttf'))))
        pdfmetrics.registerFontFamily('KH-Serif', normal='KH-Serif', bold='KH-Serif-Bold', italic='KH-Serif-Italic', boldItalic='KH-Serif-BoldItalic')
        serif = 'KH-Serif'
    out = io.BytesIO()
    letter = data.get('kind') == 'letter'
    invoice = data.get('kind') == 'invoice'
    draft = not letter and (not invoice or data.get('draft', True))
    body = ParagraphStyle('body', fontName='KH', fontSize=9, leading=13, textColor=colors.HexColor('#1b2738'), spaceAfter=8)
    small = ParagraphStyle('small', parent=body, fontSize=7, leading=9, spaceAfter=0)
    footer = ParagraphStyle('footer', parent=small, fontSize=6.5, leading=8.5)
    assets = {k: ImageReader(io.BytesIO(base64.b64decode(v['base64'], validate=True))) for k, v in (data.get('assets') or {}).items()}

    def bounded_paragraph(canvas, text, x, top, width, height, style, formatted=True):
        p = Paragraph(markup(text) if formatted else literal(text), style)
        w, h = p.wrap(width, height)
        if h > height:
            raise ValueError('Text exceeds reserved letterhead area')
        p.drawOn(canvas, x, top - h)

    # Addresses are business data, not template markup. Keep their type size and
    # move the body below the complete address rather than clipping long names.
    sender = ' · '.join(paper[k] for k in ['sender_name','sender_street','sender_address2','sender_city'] if paper[k])
    recipient = data['recipient'] if letter or invoice else 'Alex Muster\nMusterweg 1\n00000 Musterort'
    sender_paragraph = Paragraph(literal(sender), small)
    _, sender_height = sender_paragraph.wrap(170*mm, A4[1])
    recipient_top = min(231*mm, 244*mm - sender_height - 3*mm)
    recipient_paragraph = Paragraph(literal(recipient), body)
    _, recipient_height = recipient_paragraph.wrap(85*mm, A4[1])
    body_top = min(200*mm, recipient_top - recipient_height - 8*mm)
    if body_top - 46*mm < 40*mm:
        raise ValueError('Address exceeds usable letterhead area')

    def page(canvas, doc):
        canvas.saveState()
        canvas.setTitle(data['title'] if letter or invoice else 'DVM – Dokumentvorschau')
        canvas.setAuthor(str(values.get('VEREINSNAME') or 'DVM – DeinVereinsManager'))
        canvas.setFillColor(colors.HexColor('#edf0f3'))
        canvas.translate(110 * mm, 135 * mm)
        canvas.rotate(35)
        canvas.setFont('KH-Bold', 49)
        if draft: canvas.drawCentredString(0, 0, 'ENTWURF')
        canvas.restoreState()
        canvas.saveState()
        if 'logo' in assets:
            image = assets['logo']; iw, ih = image.getSize()
            width = min(paper['logo_width'], 170 - paper['logo_x']) * mm
            height = min(width * ih / iw, (35 - paper['logo_y']) * mm)
            width = min(width, height * iw / ih)
            canvas.drawImage(image, (20 + paper['logo_x']) * mm, (282 - paper['logo_y']) * mm - height, width, height, mask='auto')
        font = (serif + '-BoldItalic' if paper['header_bold'] and paper['header_italic'] else serif + '-Bold' if paper['header_bold'] else serif + '-Italic' if paper['header_italic'] else ('Times-Roman' if serif == 'Times' else serif)) if paper['header_font'] == 'serif' else ('KH-BoldItalic' if paper['header_bold'] and paper['header_italic'] else 'KH-Bold' if paper['header_bold'] else 'KH-Italic' if paper['header_italic'] else 'KH')
        hs = ParagraphStyle('header', parent=body, fontName=font, fontSize=paper['header_size'], leading=paper['header_size'] * 1.15, textColor=colors.HexColor(paper['header_color']))
        header = escape(paper['header_text']).replace('\n','<br/>')
        if paper['header_underline']: header = '<u>' + header + '</u>'
        hp = Paragraph(header, hs); w, h = hp.wrap((170-paper['text_x'])*mm, (35-paper['text_y'])*mm)
        if h > (35-paper['text_y'])*mm: raise ValueError('Header text does not fit')
        hp.drawOn(canvas, (20+paper['text_x'])*mm, (282-paper['text_y'])*mm-h)
        sender_paragraph.drawOn(canvas, 20*mm, 244*mm - sender_height)
        recipient_paragraph.drawOn(canvas, 20*mm, recipient_top - recipient_height)
        meta = ['Datum: ' + data['date']] if letter else ['Rechnungs-Nr.: ' + values['RECHNUNGSNUMMER']]
        if not letter and paper['show_contact']: meta.append('Mitgl./Kontakt-Nr.: ' + values['MITGLIEDSNUMMER'])
        if not letter: meta.append('Rechnungsdatum: ' + values['RECHNUNGSDATUM'])
        if not letter and paper['show_delivery']: meta.append('Lieferdatum: ' + values['LIEFERDATUM'])
        bounded_paragraph(canvas, '\n'.join(meta), 126*mm, 231*mm, 64*mm, 28*mm, small, formatted=False)
        canvas.setStrokeColor(colors.HexColor('#d9dfe7')); canvas.line(20*mm, 39*mm, 190*mm, 39*mm)
        for i, key in enumerate(['footer_left','footer_middle','footer_right']):
            bounded_paragraph(canvas, paper[key], (20+i*58)*mm, 36*mm, 54*mm, 25*mm, footer)
        canvas.setFont('KH', 6); canvas.setFillColor(colors.HexColor('#687589'))
        if invoice and draft: canvas.drawString(20*mm, 7*mm, 'Entwurf · fehlende Angaben vor Ausgabe ergänzen')
        elif not letter and not invoice: canvas.drawString(20*mm, 7*mm, 'Vorschau mit Musterempfänger · keine versendete Rechnung oder Zuwendungsbestätigung')
        canvas.drawRightString(190*mm, 7*mm, f'Seite {doc.page}')
        canvas.restoreState()

    doc = BaseDocTemplate(out, pagesize=A4, title=data['title'] if letter or invoice else 'DVM – Dokumentvorschau', pageCompression=1)
    frame = Frame(20*mm, 46*mm, 170*mm, body_top - 46*mm, leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    doc.addPageTemplates(PageTemplate(id='letter', frames=frame, onPage=page))
    heading = ParagraphStyle('heading', parent=body, fontName='KH-Bold', fontSize=17, leading=22, spaceAfter=18)
    story = [Paragraph(escape(data['title']), heading)]
    if letter or data.get('preview_style') == 'message' or data['title'] == 'Spendenbegleitschreiben':
        story.extend(Paragraph(markup(part), body) for part in texts['message'].split('\n\n'))
    else:
        if texts['intro']: story.append(Paragraph(markup(texts['intro']), body))
        rows = [[Paragraph(x, small) for x in ['Bezeichnung','Menge','Einheit','MwSt.','Gesamt']]]
        rows += [[Paragraph(literal(r[k]), body if k=='description' else small) for k in ['description','quantity','unit','tax','gross']] for r in data['positions']]
        table = Table(rows, colWidths=[93*mm,14*mm,20*mm,15*mm,28*mm], repeatRows=1, hAlign='LEFT')
        table.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),colors.HexColor('#eff3f7')),('VALIGN',(0,0),(-1,-1),'TOP'),('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8),('LINEBELOW',(0,0),(-1,0),.5,colors.HexColor('#ced6df'))]))
        story += [table,Spacer(1,10*mm)]
        total_rows = [['Jahresmitgliedsbeitrag',data['gross']],['Gesamtbetrag',data['gross']]] if invoice and not data.get('general_invoice') else [['Nettobetrag',data['net']],['Umsatzsteuer',data['tax']],['Gesamtbetrag',data['gross']]]
        totals = Table(total_rows, colWidths=[140*mm,30*mm])
        totals.setStyle(TableStyle([('FONTNAME',(0,0),(-1,-1),'KH'),('FONTSIZE',(0,0),(-1,-1),9),('ALIGN',(0,0),(-1,-1),'RIGHT'),('FONTNAME',(0,-1),(-1,-1),'KH-Bold'),('LINEABOVE',(0,-1),(-1,-1),.5,colors.HexColor('#ced6df')),('TOPPADDING',(0,0),(-1,-1),6),('BOTTOMPADDING',(0,0),(-1,-1),6)]))
        story += [KeepTogether(totals), Spacer(1,9*mm)]
        if texts['closing']: story.append(Paragraph(markup(texts['closing']), body))
    if data.get('payment_reference'):
        story.append(Spacer(1,4*mm))
        story.append(Paragraph('Verwendungszweck bei Überweisung: ' + escape(data['payment_reference']), body))
    # The signature is stored separately for a future approved certificate workflow; never placed on a draft automatically.
    doc.build(story)
    return out.getvalue()

if __name__ == '__main__':
    raw = sys.stdin.buffer.read(6500001)
    if len(raw) > 6500000: raise ValueError('Input limit exceeded')
    sys.stdout.buffer.write(render(json.loads(raw)))
