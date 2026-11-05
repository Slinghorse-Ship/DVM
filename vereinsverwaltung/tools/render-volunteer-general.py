"""General voluntary-work certificate; trusted JSON via stdin, PDF via stdout."""
import base64
import io
import json
import sys
from datetime import date
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
    form = data['volunteer_general']
    member = form['membership']
    passive = member['status'] == 'passiv'
    last, first, birthday, street, city, club, club_street, club_city, issued = form['values']
    text = lambda value: escape(str(value)).replace('\n', '<br/>')
    body = ParagraphStyle('body', fontName='KH', fontSize=11, leading=17, spaceAfter=10)
    title = ParagraphStyle('title', parent=body, fontName='KH-Bold', fontSize=21, leading=27, spaceAfter=18)
    small = ParagraphStyle('small', parent=body, fontSize=9, leading=13)
    out = io.BytesIO()
    doc = SimpleDocTemplate(out, pagesize=A4, leftMargin=23*mm, rightMargin=23*mm,
                            topMargin=22*mm, bottomMargin=22*mm,
                            title='Mitgliedsbestätigung' if passive else 'Mitgliedschaft und ehrenamtliche Mitarbeit', author=club, invariant=1)
    story = [Paragraph(text(club), title), Paragraph(text(club_street+'\n'+club_city), small),
             Spacer(1, 14*mm), Paragraph('Bestätigung der<br/>passiven Mitgliedschaft' if passive else 'Bestätigung der Mitgliedschaft<br/>und ehrenamtlichen Mitarbeit', title)]
    story.append(Paragraph('<b>Für das Kalenderjahr '+text(form['year'])+'</b> · Stand: '+text(issued), body))
    if not data.get('signature_used'):
        story.append(Paragraph('VORLAGE - Unterschrift des ersten Vorstands erforderlich', small))
    person = text(first+' '+last)
    if birthday:
        person += ', geboren am '+text(birthday)
    if street or city:
        person += '<br/>'+text('\n'.join(v for v in [street, city] if v))
    story.extend([Paragraph('Hiermit bestätigt '+text(club)+' die Mitgliedschaft von:', body),
                  Paragraph('<b>'+person+'</b>', body)])
    since = date.fromisoformat(member['starts_on']).strftime('%d.%m.%Y')
    if member.get('starts_precision') == 'year':
        since = member['starts_on'][:4]
    story.append(Paragraph('Die Person ist seit '+text(since)+' '+('passives' if passive else 'aktives')+' Mitglied unseres Vereins.<br/>Mitgliedsnummer: '+text(member['member_number']), body))
    if not passive:
        story.append(Paragraph('<b>Mitarbeit im Kalenderjahr '+text(form['year'])+'</b><br/>'+text(form['activity']), body))
        if form['effort']:
            story.append(Paragraph('<b>Zeitlicher Umfang</b><br/>'+text(form['effort']), body))
        story.append(Paragraph('Die genannten Aufgaben wurden im Rahmen der ehrenamtlichen Vereinsarbeit übernommen. Wir danken für dieses Engagement.', body))
    else:
        story.append(Paragraph('Wir danken für die Unterstützung durch die passive Mitgliedschaft.', body))
    signature = [Spacer(1, 10*mm), Paragraph(text(form['place']+', '+issued), body)]
    if data.get('signature_used'):
        img = Image(io.BytesIO(base64.b64decode(data['signature'], validate=True)))
        ratio = min(55*mm/img.imageWidth, 22*mm/img.imageHeight)
        img.drawWidth = img.imageWidth*ratio; img.drawHeight = img.imageHeight*ratio
        img.hAlign = 'LEFT'; signature.append(img)
    else:
        signature.append(Spacer(1, 16*mm))
    signature.append(Paragraph('_________________________________<br/>1. Vorstand<br/>'+text(form['signer']['name']), small))
    story.append(KeepTogether(signature))

    def page(canvas, document):
        canvas.setStrokeColor(colors.HexColor('#b9c3ce'))
        canvas.line(23*mm, 17*mm, 187*mm, 17*mm)
        canvas.setFont('KH', 8)
        canvas.drawRightString(187*mm, 12*mm, 'Seite '+str(document.page))

    doc.build(story, onFirstPage=page, onLaterPages=page)
    return out.getvalue()


if __name__ == '__main__':
    sys.stdout.buffer.write(render(json.load(sys.stdin)))
