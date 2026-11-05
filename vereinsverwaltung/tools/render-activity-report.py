"""Separate report audiences; trusted JSON stdin, private PDF stdout, no network."""
import base64
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
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Image, KeepTogether

fonts = Path(reportlab.__file__).parent / 'fonts'
for name, filename in [('KH', 'Vera.ttf'), ('KH-Bold', 'VeraBd.ttf')]:
    pdfmetrics.registerFont(TTFont(name, str(fonts / filename)))
pdfmetrics.registerFontFamily('KH', normal='KH', bold='KH-Bold')


def render(data):
    form = data['activity_review']
    if form['kind'] not in ('tax', 'assembly'):
        raise ValueError('Unknown report audience')
    clean = lambda v: escape(str(v).replace('\u2013', '-').replace('\u2014', '-').replace('\u2011', '-'))
    club = form['club']
    body = ParagraphStyle('body', fontName='KH', fontSize=11, leading=16, spaceAfter=9)
    heading = ParagraphStyle('heading', parent=body, fontName='KH-Bold', fontSize=12, spaceBefore=14, keepWithNext=True)
    title = ParagraphStyle('title', parent=heading, fontSize=20, leading=26, spaceAfter=12)
    small = ParagraphStyle('small', parent=body, fontSize=9, leading=13, textColor=colors.HexColor('#49515b'))
    event_title = ParagraphStyle('event', parent=body, keepWithNext=True)
    output = io.BytesIO()
    doc = SimpleDocTemplate(output, pagesize=A4, leftMargin=23*mm, rightMargin=23*mm,
                            topMargin=20*mm, bottomMargin=22*mm, title=form['title'], author=club.get('name', ''))
    period = str(form['from']) if form['from'] == form['to'] else f"{form['from']} - {form['to']}"
    story = [Paragraph(clean(club.get('name', '')), heading),
             Paragraph(clean(' · '.join(filter(None, [club.get('street', ''), ' '.join(filter(None, [club.get('postcode', ''), club.get('city', '')]))]))), small),
             Spacer(1, 7*mm), Paragraph(clean(form['title']), title), Paragraph('Berichtszeitraum: '+clean(period), body)]
    if not form.get('approved'):
        story.append(Paragraph('ENTWURF - noch nicht freigegeben', small))
    lines = form['body'].replace('\r\n', '\n').split('\n')
    if lines and lines[0].startswith(form['title']):
        lines.pop(0)
    headings = {'Satzungszwecke', 'Rückblick auf das Vereinsjahr', 'Durchgeführte Tätigkeiten',
                'Durchgeführte Veranstaltungen', 'Ergänzungen des Vereins', 'Vereinsentwicklung', 'Dank', 'Ausblick'}
    lines = [line for line in lines if line.strip()]
    dated = lambda line: re.match(r'^(?:\d{2}\.\d{2}\.\d{4}|\d{4}-\d{2}-\d{2})\s', line)
    for index, line in enumerate(lines):
        if not line.strip():
            continue
        # Plain, escaped paragraphs: long text and URLs wrap, no arbitrary markup/images.
        style = heading if line.strip() in headings else body
        if dated(line) and index+1 < len(lines) and not dated(lines[index+1]) and lines[index+1].strip() not in headings:
            style = event_title
        story.append(Paragraph(clean(line), style))
    signature = [Spacer(1, 12*mm), Paragraph(clean(', '.join(filter(None, [form.get('place', ''), form.get('issued', '')]))), body)]
    if data.get('signature_used') and data.get('signature'):
        image = Image(io.BytesIO(base64.b64decode(data['signature'], validate=True)))
        ratio = min(55*mm / image.imageWidth, 20*mm / image.imageHeight)
        image.drawWidth = image.imageWidth * ratio
        image.drawHeight = image.imageHeight * ratio
        image.hAlign = 'LEFT'
        signature.append(image)
    else:
        signature += [Spacer(1, 13*mm), Paragraph('________________________________', body)]
    signature += [Paragraph(clean(form.get('signer', '')), body), Paragraph('1. Vorstand', small)]
    story.append(KeepTogether(signature))

    def footer(canvas, document):
        canvas.saveState()
        canvas.setFont('KH', 8)
        canvas.setFillColor(colors.HexColor('#49515b'))
        canvas.drawString(23*mm, 12*mm, 'Finanzamt' if form['kind'] == 'tax' else 'Mitgliederversammlung')
        canvas.drawRightString(A4[0]-23*mm, 12*mm, f'Seite {document.page}')
        if not form.get('approved'):
            canvas.drawCentredString(A4[0]/2, 12*mm, 'ENTWURF')
        canvas.restoreState()

    doc.build(story, onFirstPage=footer, onLaterPages=footer)
    return output.getvalue()


if __name__ == '__main__':
    sys.stdout.buffer.write(render(json.load(sys.stdin)))
