"""Private internal record snapshot: JSON stdin, paginated A4 PDF stdout."""
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
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, CondPageBreak

fonts=Path(reportlab.__file__).parent/'fonts'
pdfmetrics.registerFont(TTFont('KH',str(fonts/'Vera.ttf')))
pdfmetrics.registerFont(TTFont('KHB',str(fonts/'VeraBd.ttf')))
normal=ParagraphStyle('body',fontName='KH',fontSize=9,leading=13,splitLongWords=True)
heading=ParagraphStyle('heading',fontName='KHB',fontSize=12,leading=17,spaceBefore=14,spaceAfter=9)
title=ParagraphStyle('title',fontName='KHB',fontSize=21,leading=26,spaceAfter=13)
small=ParagraphStyle('small',fontName='KH',fontSize=8,leading=12,textColor=colors.HexColor('#65758a'))

def para(text,style=normal):
    return Paragraph(escape(str(text or '')).replace('\n','<br/>'),style)

def build(data):
    r=data['record'];out=io.BytesIO();width=A4[0]-36*mm
    doc=SimpleDocTemplate(out,pagesize=A4,rightMargin=18*mm,leftMargin=18*mm,topMargin=23*mm,bottomMargin=22*mm,title=r['title'],author=r['club'])
    def frame(canvas,document):
        canvas.saveState();canvas.setFont('KH',8);canvas.setFillColor(colors.HexColor('#64748b'))
        canvas.drawString(18*mm,A4[1]-13*mm,r['club'] if r.get('public_copy') is True else 'Kulturhus · Vereinsverwaltung')
        canvas.drawRightString(A4[0]-18*mm,A4[1]-13*mm,'DEIN MITGLIEDSANTRAG' if r.get('public_copy') is True else 'INTERNER NACHWEIS')
        canvas.setStrokeColor(colors.HexColor('#dce3eb'));canvas.line(18*mm,17*mm,A4[0]-18*mm,17*mm)
        canvas.drawString(18*mm,12*mm,r['created_at']);canvas.drawRightString(A4[0]-18*mm,12*mm,f'Seite {document.page}')
        canvas.restoreState()
    story=[para(r['title'],title),para(r['club']),para(r['reference']),Spacer(1,5*mm),para(r['warning'],small)]
    for section in r['sections']:
        count=len(section['columns']);rows=[[para(v,small) for v in section['columns']]]
        rows.extend([[para(v) for v in row] for row in section['rows']])
        if len(rows)==1:rows.append([para('Keine Einträge')]+[para('') for _ in range(count-1)])
        if count==2:widths=[width*.29,width*.71]
        elif section['title']=='Übergaben und Verlauf':widths=[width*.24,width*.19,width*.37,width*.20]
        elif section['title']=='Aufbewahrung':widths=[width*.14,width*.36,width*.29,width*.21]
        elif section['title']=='Mitgliedschaften':widths=[width*.29,width*.23,width*.24,width*.24]
        elif count==4:widths=[width*.40,width*.16,width*.13,width*.31]
        elif count==5:widths=[width*.46,width*.18,width*.12,width*.12,width*.12]
        else:widths=[width/count]*count
        table=Table(rows,colWidths=widths,repeatRows=1,hAlign='LEFT',splitByRow=1,splitInRow=1)
        table.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('BACKGROUND',(0,0),(-1,0),colors.HexColor('#edf2f7')),('LINEBELOW',(0,0),(-1,0),.5,colors.HexColor('#cbd5e1')),('LINEBELOW',(0,1),(-1,-1),.25,colors.HexColor('#e5eaf0')),('LEFTPADDING',(0,0),(-1,-1),8),('RIGHTPADDING',(0,0),(-1,-1),8),('TOPPADDING',(0,0),(-1,-1),4 if r.get('compact') else 7),('BOTTOMPADDING',(0,0),(-1,-1),4 if r.get('compact') else 7)]))
        # Reserve room for a heading, table header and part of the first row;
        # keeping an entire long first row would waste the preceding page.
        h=para(section['title'],heading);story.extend([CondPageBreak(45*mm),h,table])
    doc.build(story,onFirstPage=frame,onLaterPages=frame);return out.getvalue()

if __name__=='__main__':
    sys.stdout.buffer.write(build(json.load(sys.stdin)))
