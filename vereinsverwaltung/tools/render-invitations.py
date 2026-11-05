"""Private invitation mail merge. Each member starts on a fresh sheet, also for duplex printing."""
import sys,json,io
from pathlib import Path
from xml.sax.saxutils import escape
from reportlab.pdfgen import canvas
from reportlab.platypus import SimpleDocTemplate,Paragraph,Spacer
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from pypdf import PdfReader,PdfWriter

def render(data):
    import reportlab
    fonts=Path(reportlab.__file__).parent/'fonts'
    for label,file in [('KH','Vera.ttf'),('KH-Bold','VeraBd.ttf')]:
        pdfmetrics.registerFont(TTFont(label,str(fonts/file)))
    body=ParagraphStyle('Body',fontName='KH',fontSize=10,leading=15,spaceAfter=9)
    heading=ParagraphStyle('Heading',parent=body,fontName='KH-Bold',fontSize=14,leading=18,spaceAfter=18)
    writer=PdfWriter();club=data['club'];sender=' · '.join(str(club.get(k,'')) for k in ['name','street','postcode','city'] if club.get(k))
    def para(text,style):return Paragraph(escape(str(text)).replace('\n','<br/>'),style)
    for index,recipient in enumerate(data['recipients']):
        out=io.BytesIO()
        def page(c,doc):
            c.saveState();c.setFont('KH-Bold',12);c.drawString(20*mm,280*mm,str(club.get('name') or 'Kulturhus Jaderberg e. V.'))
            c.setFont('KH',7);c.drawString(20*mm,266*mm,sender[:145]);c.setFont('KH',8);c.drawRightString(190*mm,18*mm,f"Seite {doc.page}");c.restoreState()
        doc=SimpleDocTemplate(out,pagesize=A4,leftMargin=20*mm,rightMargin=20*mm,topMargin=45*mm,bottomMargin=28*mm)
        story=[para(recipient['name']+'\n'+recipient['address'],body),Spacer(1,8*mm),para(data['date'],body),para(recipient.get('title',data['title']),heading),para('' if recipient.get('has_salutation') else 'Guten Tag '+recipient['name']+',',body),para(recipient.get('body',data['body']),body)]
        doc.build(story,onFirstPage=page,onLaterPages=page);reader=PdfReader(io.BytesIO(out.getvalue()))
        for p in reader.pages:writer.add_page(p)
        if len(reader.pages)%2 and index<len(data['recipients'])-1:writer.add_blank_page(width=A4[0],height=A4[1])
    result=io.BytesIO();writer.write(result);return result.getvalue()
if __name__=='__main__':
    raw=sys.stdin.buffer.read(6500001)
    if len(raw)>6500000:raise ValueError('Input too large')
    data=json.loads(raw)
    if not 1<=len(data['recipients'])<=250:raise ValueError('Recipient limit')
    sys.stdout.buffer.write(render(data))
