"""Book working paper from one authorized MySQL snapshot; stdin JSON, stdout PDF."""
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
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

SPHERES={'1':'Ideeller Bereich','2':'Vermögensverwaltung','3':'Zweckbetrieb','4':'Wirtschaftlicher Geschäftsbetrieb'}


def money(value):
    if type(value) is not int or abs(value)>100_000_000_000:
        raise ValueError('Invalid integer book amount')
    sign='−' if value<0 else ''
    euros,cents=divmod(abs(value),100)
    return f'{sign}{euros:,}'.replace(',','.')+f',{cents:02d} €'


def render(document):
    r=document['balance_report']
    if r['schema']!=1 or r['state'] not in ('open','closed') or r['tax_filing_approved'] is not False:
        raise ValueError('Book working-paper scope required')
    c=r['calculation']
    if c['difference_cents']!=0 or c['asset_total_cents']!=c['passive_total_cents']:
        raise ValueError('Unbalanced book snapshot')
    fonts=Path(reportlab.__file__).parent/'fonts'
    for name,file in [('KH','Vera.ttf'),('KH-Bold','VeraBd.ttf')]:
        if name not in pdfmetrics.getRegisteredFontNames():
            pdfmetrics.registerFont(TTFont(name,str(fonts/file)))
    pdfmetrics.registerFontFamily('KH',normal='KH',bold='KH-Bold')
    body=ParagraphStyle('body',fontName='KH',fontSize=9,leading=13,spaceAfter=7)
    small=ParagraphStyle('small',parent=body,fontSize=7,leading=10,textColor=colors.HexColor('#49515b'))
    heading=ParagraphStyle('heading',parent=body,fontName='KH-Bold',fontSize=13,leading=18,spaceBefore=13,keepWithNext=True)
    title=ParagraphStyle('title',parent=heading,fontSize=21,leading=27)
    cell=ParagraphStyle('cell',parent=body,fontSize=8,leading=11,spaceAfter=0,splitLongWords=True)
    head_cell=ParagraphStyle('table_head',parent=cell,fontSize=7,leading=10)
    clean=lambda v:escape(str(v))
    paragraph=lambda v:Paragraph(clean(v),cell)
    width=174*mm

    def table(rows,widths):
        t=Table([[Paragraph(clean(v),head_cell) if i==0 else paragraph(v) for v in row] for i,row in enumerate(rows)],colWidths=widths,repeatRows=1,hAlign='LEFT')
        t.setStyle(TableStyle([
            ('BACKGROUND',(0,0),(-1,0),colors.HexColor('#e8eef5')),
            ('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),5),
            ('RIGHTPADDING',(0,0),(-1,-1),5),('TOPPADDING',(0,0),(-1,-1),6),
            ('BOTTOMPADDING',(0,0),(-1,-1),6),
            ('LINEBELOW',(0,0),(-1,-1),.3,colors.HexColor('#d4dae2'))
        ]))
        return t

    story=[Paragraph(clean(r['club']),body),Paragraph('Vereinsbilanz '+clean(r['year']),title)]
    label='Festgeschriebener Buchabschluss' if r['state']=='closed' and not r['issues'] else 'Arbeitsstand – Prüfung noch offen'
    if r['state']=='closed' and r['issues']:label='Festgeschriebener Buchstand – Quellenprüfung offen'
    story.append(Paragraph(clean(label),heading))
    if r['synthetic']:story.append(Paragraph('TEST · ausschließlich erfundene Daten',body))
    if r['correction']:story.append(Paragraph('Berichtigter Abschlussstand',body))
    story.append(Paragraph('Buchbilanz und Gewinn- und Verlustrechnung des Gesamtvereins. Steuerliche Überleitung, Kontenzuordnung und XML-Freigabe werden gesondert geprüft. Diese Datei bestätigt keine Steuerabgabe.',small))
    if r['issues']:
        story.append(Paragraph('Offene Prüfungen',heading))
        for issue in r['issues']:story.append(Paragraph(clean(issue),body))
    story.append(Paragraph('Bilanz · Aktiva',heading))
    rows=[['Bilanzposten','Betrag']]+[[a['name'],money(a['amount_cents'])] for a in c['assets']]
    rows.append(['Summe Aktiva',money(c['asset_total_cents'])]);story.append(table(rows,[width-36*mm,36*mm]))
    story.append(Paragraph('Bilanz · Passiva',heading))
    rows=[['Bilanzposten','Betrag']]+[[a['name'],money(a['amount_cents'])] for a in c['capital']+c['liabilities']]
    rows.extend([['Jahresergebnis',money(c['annual_result_cents'])],['Summe Passiva',money(c['passive_total_cents'])]])
    story.append(table(rows,[width-36*mm,36*mm]))
    story.append(Paragraph('Gewinn- und Verlustrechnung · Vereinsbereiche',heading))
    rows=[['Vereinsbereich','Erträge','Aufwendungen','Ergebnis']]
    for sphere in SPHERES:
        s=c['spheres'][sphere];rows.append([SPHERES[sphere],money(s['income_cents']),money(s['expense_cents']),money(s['result_cents'])])
    rows.append(['Summe',money(c['income_cents']),money(c['expense_cents']),money(c['annual_result_cents'])])
    story.append(table(rows,[67*mm,35*mm,37*mm,35*mm]))
    story.append(Paragraph('Kontennachweis',heading))
    story.append(Paragraph('Salden: Soll positiv, Haben negativ. SKR42-Kontenzuordnung und steuerliche Bewertung werden hier nicht neu festgelegt.',small))
    rows=[['Konto / SKR42','Anfang','Soll','Haben','Ende']]
    for a in c['accounts'].values():
        rows.append([a['name']+(' · '+a['skr_code'] if a['skr_code'] else ''),*[money(a[k]) for k in ('opening_cents','debit_cents','credit_cents','closing_cents')]])
    story.append(table(rows,[62*mm,28*mm,28*mm,28*mm,28*mm]))
    story.append(Paragraph('Anlagenverzeichnis',heading))
    if not r['assets']:story.append(Paragraph('Kein Anlagevermögen im Bilanzjournal.',body))
    else:
        story.append(Paragraph('AfA: planmäßige Abschreibung. Minderung: außerplanmäßige Wertminderung. Aufholung: Wertaufholung. Abgang: Restbuchwert des abgegangenen Gegenstands.',small))
        rows=[['Gegenstand / Beleg','Anfang','Zugänge','AfA','Minderung','Aufholung','Abgang','Ende']]
        for a in r['assets'].values():
            rows.append([(a.get('name') or a['item_id'])+' · '+a.get('receipt_number',''),*[money(a.get(k,0)) for k in ('book_opening_cents','additions_cents','depreciation_cents','impairment_cents','writeup_cents','book_disposals_cents','book_closing_cents')]])
        rows.append(['Summe',*[money(r['asset_totals'].get(k,0)) for k in ('book_opening_cents','additions_cents','depreciation_cents','impairment_cents','writeup_cents','book_disposals_cents','book_closing_cents')]])
        story.append(table(rows,[48*mm]+[18*mm]*7))
    story.append(Spacer(1,7*mm))
    story.append(Paragraph('Stand '+clean(r['version'])+' · Nachweis '+clean(r['snapshot_sha256']),small))
    if r['closed_sha256']:story.append(Paragraph('Festschreibungsnachweis '+clean(r['closed_sha256']),small))
    output=io.BytesIO()
    doc=SimpleDocTemplate(output,pagesize=A4,leftMargin=18*mm,rightMargin=18*mm,topMargin=17*mm,bottomMargin=19*mm,title='Vereinsbilanz '+str(r['year']),author=r['club'])
    def footer(canvas,doc):
        canvas.saveState();canvas.setFont('KH',7);canvas.setFillColor(colors.HexColor('#49515b'))
        canvas.drawString(18*mm,11*mm,'Buchabschluss · keine Bestätigung einer Steuerabgabe')
        canvas.drawRightString(192*mm,11*mm,'Seite '+str(doc.page));canvas.restoreState()
    doc.build(story,onFirstPage=footer,onLaterPages=footer)
    return output.getvalue()


if __name__=='__main__':
    sys.stdout.buffer.write(render(json.load(sys.stdin)))
