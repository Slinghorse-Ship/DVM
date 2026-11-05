"""85 x 55 mm personal vouchers, ten per A4 sheet. JSON stdin/PDF stdout; no network."""
import base64
import io
import json
import sys
from pathlib import Path
from xml.sax.saxutils import escape

import reportlab
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import Paragraph

fonts = Path(reportlab.__file__).parent / 'fonts'
pdfmetrics.registerFont(TTFont('Voucher', str(fonts / 'Vera.ttf')))
pdfmetrics.registerFont(TTFont('VoucherBold', str(fonts / 'VeraBd.ttf')))


def text_box(canvas, value, x, top, width, height, size=10, bold=False):
    text = escape(str(value).replace('\u2011', '-').replace('\u2013', '-').replace('\u2014', '-'))
    for step in range(17):
        font_size = max(6, size - step * .25)
        style = ParagraphStyle('v', fontName='VoucherBold' if bold else 'Voucher', fontSize=font_size,
                               leading=font_size * 1.15, textColor=colors.HexColor('#17352f'), splitLongWords=True)
        paragraph = Paragraph(text, style)
        _, actual = paragraph.wrap(width, height)
        if actual <= height:
            paragraph.drawOn(canvas, x, top - actual)
            return
    raise ValueError('Text does not fit on voucher; shorten event title or benefit label')


def render(data):
    cards = data['event_voucher_cards']
    if not cards or len(cards) > 200:
        raise ValueError('Invalid voucher count')
    out = io.BytesIO()
    canvas = Canvas(out, pagesize=A4, pageCompression=1, invariant=1)
    canvas.setTitle('Persönliche Veranstaltungsgutscheine - 85 x 55 mm')
    canvas.setAuthor('Vereinsverwaltung')
    width, height = 85*mm, 55*mm
    for index, card in enumerate(cards):
        if index and index % 10 == 0:
            canvas.showPage()
        column = index % 2
        row = (index % 10) // 2
        x = (17.5 + column*90)*mm
        y = A4[1] - (11 + (row+1)*55)*mm
        canvas.saveState()
        canvas.translate(x, y)
        canvas.setStrokeColor(colors.HexColor('#87958c'))
        canvas.setLineWidth(.3)
        for xx in [0, width]:
            for yy in [0, height]:
                canvas.line(xx-1.5*mm, yy, xx+1.5*mm, yy)
                canvas.line(xx, yy-1.5*mm, xx, yy+1.5*mm)
        canvas.setFillColor(colors.HexColor('#f4b83f'))
        canvas.rect(4*mm, 51*mm, 77*mm, 1*mm, stroke=0, fill=1)
        text_box(canvas, card['event'], 4*mm, 49.5*mm, 77*mm, 12*mm, 10, True)
        text_box(canvas, card['date'], 4*mm, 36.5*mm, 43*mm, 4*mm, 8)
        text_box(canvas, card['name'], 4*mm, 30*mm, 44*mm, 12*mm, 10, True)
        text_box(canvas, card['label']+' · '+card['amount'], 4*mm, 17*mm, 44*mm, 10*mm, 10, True)
        png = base64.b64decode(card['qr'], validate=True)
        if len(png) > 20000 or not png.startswith(b'\x89PNG\r\n\x1a\n'):
            raise ValueError('Invalid QR image')
        canvas.drawImage(ImageReader(io.BytesIO(png)), 52*mm, 7*mm, 29*mm, 29*mm, mask='auto')
        canvas.setFillColor(colors.HexColor('#17352f'))
        canvas.setFont('Voucher', 7)
        canvas.drawString(4*mm, 3.5*mm, card['code'])
        canvas.setFont('Voucher', 5.5)
        canvas.drawRightString(81*mm, 3.5*mm, 'Nur diese Person und Veranstaltung')
        canvas.restoreState()
        if index % 10 == 0:
            canvas.setFont('Voucher', 6.5)
            canvas.setFillColor(colors.HexColor('#44564d'))
            canvas.drawCentredString(A4[0]/2, 4*mm, '85 x 55 mm | In Originalgröße (100 %) drucken | An den Schnittmarken schneiden')
    canvas.save()
    return out.getvalue()


if __name__ == '__main__':
    sys.stdout.buffer.write(render(json.load(sys.stdin)))
