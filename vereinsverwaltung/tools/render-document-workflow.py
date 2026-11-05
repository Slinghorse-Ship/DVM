"""Documented risk assessment: JSON on stdin, self-contained PDF and appended PDFs on stdout."""
import base64
import io
import json
import sys
from datetime import datetime
from pathlib import Path
from xml.sax.saxutils import escape

import reportlab
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import BaseDocTemplate, Flowable, Frame, Image, PageBreak, PageTemplate, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle
from PIL import Image as PillowImage
from pypdf import PdfReader, PdfWriter
from pypdf.generic import DictionaryObject, ArrayObject, IndirectObject


def append_attachments(raw, data):
    attachments = data.get('attachments', [])
    if len(attachments) > 5 or sum(item['size_bytes'] for item in attachments) > 3145728:
        raise ValueError('Attachment collection limit')
    if not attachments:
        return raw
    readers = [inspect_pdf(item['base64']) for item in attachments]
    if sum(len(reader.pages) for reader in readers) > 200:
        raise ValueError('Combined attachment page limit')
    writer = PdfWriter()
    writer.append(PdfReader(io.BytesIO(raw)))
    for item, reader in zip(attachments, readers):
        writer.append(reader, outline_item=item['name'])
    writer.add_metadata({'/Title': data['document']['title']})
    out = io.BytesIO()
    writer.write(out)
    return out.getvalue()


def render_rwe(data):
    """Same form arrangement as FS-RA-030, German labels; no copied identities, signatures or active PDF objects."""
    doc = data['document']
    values = doc.get('values') or {}
    profile = data['profiles']['rwe_reference_de_v1']
    page_size=(841.92,595.32)
    width=799.8
    blue = colors.HexColor('#1d4477')
    body = ParagraphStyle('rwe-body', fontName='KH', fontSize=3.84, leading=5.04, spaceAfter=0)
    category = ParagraphStyle('rwe-category', parent=body, fontName='KH-Bold', fontSize=3.84, leading=5.04)
    hazard_style=ParagraphStyle('rwe-hazard',parent=body,fontName='KH-Bold',fontSize=4.8,leading=6.24,alignment=1)
    impact_style=ParagraphStyle('rwe-impact',parent=body,fontSize=4.8,leading=6.24,alignment=1)
    risk_style=ParagraphStyle('rwe-risk',parent=body,fontName='KH-Bold',fontSize=4.8,leading=6.24,alignment=1)
    header = ParagraphStyle('rwe-header', parent=body, fontName='KH-Bold', fontSize=3.6, leading=4.1, textColor=colors.white, alignment=1)
    title = ParagraphStyle('rwe-title', parent=header, fontSize=10.32, leading=13.2)
    label = ParagraphStyle('rwe-label', parent=body, fontName='KH-Bold',fontSize=4.2,leading=4.68,textColor=blue, alignment=1)
    ink = ParagraphStyle('rwe-value',parent=body,fontSize=4.8,leading=6.24,textColor=blue)
    p = lambda text, style=body: Paragraph(escape(str(text or '—')).replace('\n', '<br/>'), style)
    date = lambda value: datetime.fromisoformat(value).strftime('%d.%m.%Y %H:%M') + ' UTC'
    date_value = lambda key: datetime.strptime(values[key], '%Y-%m-%d').strftime('%d.%m.%Y') if values.get(key) else '—'
    status = {'draft':'Entwurf','submitted':'In Prüfung','returned':'Zur Überarbeitung','approved':'Freigegeben'}[doc['status']]
    people = data.get('people') or {}
    actual_review = next((event for event in sorted(data['activities'], key=lambda item:item['revision'], reverse=True) if event['action']=='approve'), None)
    approved_by = values.get('approver')
    approved_date = date_value('approval_date')
    if doc['status']=='approved' and actual_review:
        approved_by = people.get(actual_review['actor_id'], actual_review['actor_id'])
        approved_date = date(actual_review['created_at'])
    left_width=421.85
    metadata = [
        [p('KULTURHUS · GEFÄHRDUNGSBEURTEILUNG', title),'','',''],
        [p('Dokument-Nr.',label),p(values.get('document_ref'),ink),p('Titel der Beurteilung',label),p(values.get('assessment_title') or doc['title'],ink)],
        [p('Revision',label),p(doc['version'],ink),'',''],
        [p('Projekt / Standort',label),p(values.get('location'),ink),p('Arbeit / Tätigkeit',label),p(values.get('activity'),ink)],
        [p('Arbeitsmittel / Anlage',label),p(values.get('equipment'),ink),p('Arbeitsschritt',label),p(values.get('task_step') or values.get('work_phase'),ink)],
        [p('Beurteilung durch',label),p(values.get('assessor'),ink),'',''],
        [p('Freigabe durch',label),p(approved_by,ink),'',''],
        [p('Freigabedatum',label),p(approved_date,ink),p('Prüftermin',label),p(date_value('review_date'),ink)],
    ]
    left = Table(metadata,colWidths=[57.96,124.10,62.16,177.63],minRowHeights=[18.12,10.20,10.20,10.08,10.08,20.64,10.20,10.34],hAlign='LEFT')
    left.setStyle(TableStyle([
        ('GRID',(0,0),(-1,-1),.45,blue),('BACKGROUND',(0,0),(-1,0),blue),('SPAN',(0,0),(-1,0)),
        ('SPAN',(2,1),(2,2)),('SPAN',(3,1),(3,2)),('SPAN',(1,5),(-1,5)),('SPAN',(1,6),(-1,6)),
        ('BACKGROUND',(0,1),(0,-1),colors.Color(.91,.91,.894)),('BACKGROUND',(2,1),(2,4),colors.Color(.91,.91,.894)),('BACKGROUND',(2,7),(2,7),colors.Color(.851,.851,.851)),
        ('VALIGN',(0,0),(-1,-1),'MIDDLE'),('LEFTPADDING',(0,0),(-1,-1),3),('RIGHTPADDING',(0,0),(-1,-1),3),
        ('TOPPADDING',(0,0),(-1,-1),.3),('BOTTOMPADDING',(0,0),(-1,-1),.3),
    ]))
    legend_width=377.95
    class ReferenceLegend(Flowable):
        """Rebuilt vectors at the original image's 1020 × 266 reference coordinates."""
        def __init__(self):
            super().__init__()
            self.width=367.42
            self.height=96.103
        def draw(self):
            canvas=self.canv
            canvas.saveState()
            canvas.scale(self.width/1020,self.height/266)

            def text(value,x,top,w,h,size=7,bold=False,colour=colors.black):
                # German wording can need more lines than the English source; fit only the text,
                # never move or resize the measured coloured cells and axes.
                while True:
                    style=ParagraphStyle('reference-label',fontName='KH-Bold' if bold else 'KH',fontSize=size,leading=size*1.16,alignment=1,textColor=colour)
                    paragraph=p(value,style)
                    _,needed=paragraph.wrap(w,h)
                    if needed<=h or size<=4:break
                    size-=.25
                paragraph.drawOn(canvas,x,266-top-(h+needed)/2)

            text('Risikomatrix',115,13,160,20,14,True,blue)
            for value,x,w in [('UMWELT',0,132),('SICHERHEIT',134,144),('S',281,64)]:
                text(value,x,53,w,15,7,True)
            for column,level in enumerate(profile['likelihood']):
                x=375+column*76.8
                text(level['label'],x+2,9,72.8,14,6.5,False,blue)
                text(level['description'],x+2,24,72.8,28,6.5,True)
                text(level['value'],x,53,76.8,15,10,True)
                text(level['frequency'],x,241,76.8,23,6.5,True)
            canvas.setLineWidth(.55)
            for row,level in enumerate(profile['severity']):
                top=69+row*33.8
                text(level['environment'],0,top+1,132,31.8,7)
                text(level['description'],134,top+1,144,31.8,7)
                text(level['label'],281,top+1,64,31.8,7,True)
                text(level['letter'],352,top,23,33.8,10,True)
                for column,likelihood in enumerate(profile['likelihood']):
                    score=level['value']*likelihood['value']
                    band=next(band for band in profile['bands'] if band['min']<=score<=band['max'])
                    x=375+column*76.8
                    canvas.setFillColor(colors.HexColor(band['background']))
                    canvas.setStrokeColor(colors.HexColor('#abb5b1'))
                    canvas.rect(x,266-top-33.8,76.8,33.8,fill=1,stroke=1)
                    canvas.setFillColor(colors.black);canvas.setFont('KH-Bold',9)
                    canvas.drawRightString(x+74.8,266-top-31.8,str(score))
            band_cols=[778,818,854,974,1014]
            for column,value in enumerate(['Risiko','Wert','Bedeutung','Freigabe']):
                text(value,band_cols[column]+1,45,band_cols[column+1]-band_cols[column]-2,24,6.5,True)
            for row,band in enumerate(reversed(profile['bands'])):
                top=69+row*34
                for column,value in enumerate([band['label'],f"{band['min']}–{band['max']}",band['description'],band['approver']]):
                    x=band_cols[column];w=band_cols[column+1]-x
                    canvas.setFillColor(colors.HexColor(band['background']) if column==0 else colors.white)
                    canvas.setStrokeColor(colors.HexColor('#aaaaaa'))
                    canvas.rect(x,266-top-34,w,34,fill=1,stroke=1)
                    text(value,x+2,top+1,w-4,32,6, column<2)
            canvas.restoreState()
    intro=Table([[left,ReferenceLegend()]],colWidths=[left_width,legend_width],minRowHeights=[99.86],hAlign='LEFT')
    intro.setStyle(TableStyle([('BOX',(0,0),(-1,-1),1.5,blue),('LINEAFTER',(0,0),(0,0),1.5,blue),('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(0,0),0),('RIGHTPADDING',(0,0),(0,0),0),('TOPPADDING',(0,0),(0,0),0),('BOTTOMPADDING',(0,0),(0,0),0),('LEFTPADDING',(1,0),(1,0),7.8),('RIGHTPADDING',(1,0),(1,0),2.73),('TOPPADDING',(1,0),(1,0),1.807),('BOTTOMPADDING',(1,0),(1,0),1.95)]))
    story=[intro,Spacer(1,6.12)]
    widths=[57.96,124.10,88.43,27.48,26.08,26.28,213.38,24.84,24.48,24.75,76.31,85.71]
    for field in doc['fields']:
        if field['type']!='risk_register': continue
        rows = [[p('Gefährdungskategorie',header),p('Beschreibung der Gefährdung',header),p('Mögliche Auswirkungen',header),p('Ausgangsrisiko',header),'','',p('Schutzmaßnahmen',header),p('Restrisiko',header),'','',p('Verantwortlich',header),p('Wirksamkeitsprüfung',header)],['','','',p('S'),p('L'),p('R'),'',p('S'),p('L'),p('R'),'','']]
        commands = [('BACKGROUND',(0,0),(-1,1),colors.HexColor('#adafb1')),('BACKGROUND',(3,1),(5,1),colors.white),('BACKGROUND',(7,1),(9,1),colors.white),('SPAN',(3,0),(5,0)),('SPAN',(7,0),(9,0))]
        for col in (0,1,2,6,10,11): commands.append(('SPAN',(col,0),(col,1)))
        for index, record in enumerate(values.get(field['key']) or [{}],2):
            row = [p(record.get('category'),category),p(record.get('hazard'),hazard_style),p(record.get('impact'),impact_style)]
            for key, col in [('initial',3),('residual',7)]:
                if key=='residual':
                    controls=record.get('controls') or ''
                    for date_key,date_label in [('action_deadline','Maßnahmenfrist'),('action_completed_on','Erledigt am')]:
                        if record.get(date_key):controls+='\n'+date_label+': '+record[date_key]
                    row.append(p(controls))
                risk = record.get(key)
                score = risk['severity'] * risk['likelihood'] if risk else None
                row.extend([p(chr(64+risk['severity']) if risk else None,risk_style),p(risk['likelihood'] if risk else None,risk_style),p(score,risk_style)])
                if score:
                    band=next(band for band in profile['bands'] if band['min']<=score<=band['max'])
                    fills={'low':colors.HexColor('#c6efce'),'medium':colors.HexColor('#ffeb9c'),'high':colors.HexColor('#ffc7ce')}
                    commands.append(('BACKGROUND',(col+2,index),(col+2,index),fills[band['key']]))
            row.extend([p(record.get('responsible')),p(record.get('effectiveness'))]);rows.append(row)
        hazards=Table(rows,colWidths=widths,repeatRows=0,rowHeights=[10.2,6.96]+[None]*(len(rows)-2),splitByRow=0,splitInRow=1,hAlign='LEFT')
        hazards.setStyle(TableStyle([('GRID',(0,0),(-1,-1),.4,colors.black),('VALIGN',(0,0),(-1,-1),'MIDDLE'),('LEFTPADDING',(0,0),(-1,-1),1),('RIGHTPADDING',(0,0),(-1,-1),1),('TOPPADDING',(0,0),(-1,-1),1),('BOTTOMPADDING',(0,0),(-1,-1),1)] + commands))
        story.append(hazards)
    # Additional authored fields stay in the output even when an older template used other metadata keys.
    meta_keys={'document_ref','assessment_title','location','activity','equipment','task_step','work_phase','assessor','approver','approval_date','review_date'}
    for field in doc['fields']:
        if field['type']=='risk_register' or field['key'] in meta_keys:continue
        value=values.get(field['key'])
        if field['type']=='risk_matrix' and value:value=f"S: {chr(64+value['severity'])} | L: {value['likelihood']} | R: {value['severity']*value['likelihood']}"
        if value not in (None,''):story.extend([Spacer(1,3),p(field['label'],label),p(value)])
    acknowledgement = 'Ich bestätige, dass ich die Gefährdungen, denen ich ausgesetzt sein kann, sowie ihre möglichen Auswirkungen, Wahrscheinlichkeit und Schwere gelesen und verstanden habe. Ich werde die mitgeteilten Schutzmaßnahmen soweit praktikabel befolgen. Sind die Maßnahmen nicht ausreichend, stelle ich die Arbeit ein und informiere die verantwortliche Leitung.'
    acknowledgement_style=ParagraphStyle('reference-acknowledgement',parent=ink,alignment=1)
    signatures=Table([[p(acknowledgement,acknowledgement_style),'','','','',''],['',p('Name',ink),'',p('Datum',ink),'',p('Unterschrift',ink)]],colWidths=[421.85,140,15,65,45,112.95],rowHeights=[18,None])
    signatures.setStyle(TableStyle([('SPAN',(0,0),(0,1)),('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),2),('RIGHTPADDING',(0,0),(-1,-1),2)]+[('LINEABOVE',(col,1),(col,1),.4,colors.black) for col in (1,3,5)]))
    story.extend([Spacer(1,3),signatures])
    if data.get('attachments'):
        story.extend([Spacer(1,3*mm),p('Anlagen: '+', '.join(item['name'] for item in data['attachments']))])
    def footer(canvas,pdf):
        canvas.saveState();canvas.setFont('KH',4.8);canvas.setFillColor(blue)
        canvas.drawString(20.64,17.28,f"{values.get('document_ref') or doc['id']} | Revision {doc['version']} | {status} | Stand {date(doc['updated_at'])}")
        canvas.drawRightString(820.44,17.28,f'Seite {pdf.page}');canvas.restoreState()
    output=io.BytesIO()
    pdf=BaseDocTemplate(output,pagesize=page_size,leftMargin=20.64,rightMargin=21.48,topMargin=39.84,bottomMargin=40.32,title=doc['title'])
    frame=Frame(pdf.leftMargin,pdf.bottomMargin,pdf.width,pdf.height,leftPadding=0,rightPadding=0,topPadding=0,bottomPadding=0)
    pdf.addPageTemplates(PageTemplate(id='reference-form',frames=[frame],onPage=footer));pdf.build(story)
    return output.getvalue()


def inspect_pdf(encoded):
    raw = base64.b64decode(encoded, validate=True)
    if len(raw) > 2097152 or not raw.startswith(b'%PDF-'):
        raise ValueError('PDF attachment size or type')
    reader = PdfReader(io.BytesIO(raw), strict=True)
    if reader.is_encrypted or not 1 <= len(reader.pages) <= 40:
        raise ValueError('Encrypted, empty or excessive PDF attachment')
    seen = set()

    def visit(obj, depth=0):
        if depth > 80 or len(seen) > 40000:
            raise ValueError('PDF attachment structure limit')
        if isinstance(obj, IndirectObject):
            key = (obj.idnum, obj.generation)
            if key in seen:
                return
            seen.add(key)
            obj = obj.get_object()
        if isinstance(obj, DictionaryObject):
            if any(key in obj for key in ('/JS', '/JavaScript', '/OpenAction', '/AA', '/EmbeddedFiles', '/EF', '/XFA')):
                raise ValueError('Active or embedded attachment content')
            if str(obj.get('/S', '')) in ('/JavaScript', '/Launch', '/SubmitForm', '/ImportData', '/GoToR'):
                raise ValueError('Active PDF action')
            for value in obj.values():
                visit(value, depth + 1)
        elif isinstance(obj, ArrayObject):
            for value in obj:
                visit(value, depth + 1)

    visit(reader.trailer['/Root'])
    for page in reader.pages:
        if not 10 <= float(page.mediabox.width) <= 3000 or not 10 <= float(page.mediabox.height) <= 3000:
            raise ValueError('Invalid PDF page size')
        page.get_contents()  # Fail early for broken content streams; never execute their contents.
    return reader


def signature_image(encoded):
    raw = base64.b64decode(encoded, validate=True)
    if len(raw) > 16384:
        raise ValueError('Signature image limit')
    image = PillowImage.open(io.BytesIO(raw))
    if image.format != 'PNG' or getattr(image, 'n_frames', 1) != 1 or not (80 <= image.width <= 1600 and 30 <= image.height <= 500):
        raise ValueError('Signature image dimensions or type')
    image.verify()
    image = PillowImage.open(io.BytesIO(raw)).convert('RGBA')
    white = PillowImage.new('RGBA', image.size, 'white')
    white.alpha_composite(image)
    rgb = white.convert('RGB')
    # A blank canvas cannot serve as a handwritten acknowledgement.
    ink = rgb.convert('L').point(lambda pixel: 255 if pixel < 180 else 0)
    bbox = ink.getbbox()
    if not bbox or bbox[2] - bbox[0] < 5 or bbox[3] - bbox[1] < 3 or ink.histogram()[255] < 25:
        raise ValueError('Empty signature')
    return rgb


def append_signatures(raw, data):
    signatures = data.get('signatures') or []
    if not signatures:
        return raw
    if len(signatures) > 100:
        raise ValueError('Signature count limit')
    style = ParagraphStyle('signature-proof', fontName='KH', fontSize=10, leading=14, spaceAfter=7)
    heading = ParagraphStyle('signature-heading', parent=style, fontName='KH-Bold', fontSize=15, leading=19)
    p = lambda text, st=style: Paragraph(escape(str(text)), st)
    story = []
    for index, item in enumerate(signatures):
        if index:
            story.append(PageBreak())
        image = signature_image(item['image_base64'])
        image_bytes = io.BytesIO()
        image.save(image_bytes, format='PNG')
        image_bytes.seek(0)
        signed_date = datetime.fromisoformat(item['signed_at']).strftime('%d.%m.%Y %H:%M:%S') + ' UTC'
        story.extend([p('Elektronische Kenntnisnahme', heading), p(data['document']['title']),
                      p(f"Dokumentfassung: Revision {item['revision']}"), p(f"Name: {item['signer_name']}"),
                      p(f"Bestätigt am: {signed_date}"), p(item['statement']), Spacer(1, 5 * mm),
                      Image(image_bytes, width=min(120*mm,35*mm*image.width/image.height), height=min(35*mm,120*mm*image.height/image.width), hAlign='LEFT'),
                      Spacer(1, 3 * mm), p('Unterschrift'),
                      p('SHA-256 der bestätigten Dokumentfassung: ' + item['document_sha256'])])
    evidence = io.BytesIO()
    def footer(canvas, pdf):
        canvas.saveState()
        canvas.setFont('KH', 7)
        canvas.setFillColor(colors.Color(.114,.267,.467))
        canvas.drawString(20*mm, 10*mm, f"Kenntnisnahme | Dokument {data['document']['id']} | Revision {data['document']['version']}")
        canvas.drawRightString(A4[0]-20*mm, 10*mm, f'Seite {pdf.page}')
        canvas.restoreState()
    SimpleDocTemplate(evidence, pagesize=A4, leftMargin=20*mm, rightMargin=20*mm, topMargin=20*mm, bottomMargin=20*mm).build(story,onFirstPage=footer,onLaterPages=footer)
    writer = PdfWriter()
    writer.append(PdfReader(io.BytesIO(raw)))
    writer.append(PdfReader(io.BytesIO(evidence.getvalue())), outline_item='Elektronische Kenntnisnahmen')
    writer.add_metadata({'/Title': data['document']['title']})
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue()


def render(data):
    if 'validate_attachment' in data or 'validate_signature' in data:
        if 'validate_signature' in data:
            signature_image(data['validate_signature'])
        else:
            inspect_pdf(data['validate_attachment'])
        writer = PdfWriter()
        writer.add_blank_page(width=A4[0], height=A4[1])
        result = io.BytesIO()
        writer.write(result)
        return result.getvalue()

    doc = data['document']
    register = any(field['type'] == 'risk_register' for field in doc['fields'])
    page_size = landscape(A4) if register else A4
    width = page_size[0] - 24 * mm
    fonts = Path(reportlab.__file__).parent / 'fonts'
    for name, filename in [('KH', 'Vera.ttf'), ('KH-Bold', 'VeraBd.ttf')]:
        if name not in pdfmetrics.getRegisteredFontNames():
            pdfmetrics.registerFont(TTFont(name, str(fonts / filename)))
    pdfmetrics.registerFontFamily('KH', normal='KH', bold='KH-Bold')
    if register and 'rwe_reference_de_v1' in (data.get('profiles') or {}):
        return append_attachments(append_signatures(render_rwe(data),data),data)
    body = ParagraphStyle('body', fontName='KH', fontSize=9, leading=12, spaceAfter=5, splitLongWords=True)
    cell = ParagraphStyle('cell', parent=body, fontSize=7.5, leading=10, spaceAfter=0)
    small = ParagraphStyle('small', parent=cell, fontSize=7, leading=9)
    legend_text = ParagraphStyle('legend', parent=cell, fontSize=6.5, leading=8)
    head = ParagraphStyle('heading', parent=body, fontName='KH-Bold', fontSize=10.32, leading=13.2, spaceBefore=8, keepWithNext=True)
    register_head = ParagraphStyle('register-heading', parent=head, keepWithNext=False)
    title = ParagraphStyle('title', parent=head, fontSize=18, leading=22, spaceBefore=0)
    p = lambda value, style=cell: Paragraph(escape(str(value)).replace('\n', '<br/>'), style)

    def table(rows, widths, repeats=0, commands=None):
        result = Table(rows, colWidths=widths, repeatRows=repeats, splitByRow=1, splitInRow=1, hAlign='LEFT')
        result.setStyle(TableStyle([
            ('VALIGN', (0, 0), (-1, -1), 'TOP'), ('GRID', (0, 0), (-1, -1), .4, colors.HexColor('#88938c')),
            ('LEFTPADDING', (0, 0), (-1, -1), 4), ('RIGHTPADDING', (0, 0), (-1, -1), 4),
            ('TOPPADDING', (0, 0), (-1, -1), 4), ('BOTTOMPADDING', (0, 0), (-1, -1), 4),
        ] + (commands or [])))
        return result

    statuses = {'draft': 'Entwurf', 'submitted': 'In Prüfung', 'returned': 'Zur Überarbeitung', 'approved': 'Freigegeben'}
    people = data.get('people') or {}
    field_values = doc.get('values') or {}
    name = lambda actor: people.get(actor, actor or '-')
    events = sorted(data['activities'], key=lambda activity: activity['revision'])
    last_edit = next((activity for activity in reversed(events) if activity['action'] in ('save', 'attach', 'detach')), None)
    last_review = next((activity for activity in reversed(events) if activity['action'] in ('approve', 'return')), None)
    date = lambda value: datetime.fromisoformat(value).strftime('%d.%m.%Y %H:%M') + ' UTC'
    story = [p(doc['title'], title), p(f"{statuses[doc['status']]} | Version {doc['version']} | Stand {date(doc['updated_at'])}", body)]
    story.append(p(f"Erstellt von: {name(doc['created_by'])} | Bearbeitet von: {name(last_edit['actor_id'] if last_edit else doc['created_by'])}", small))
    if last_review and doc['status'] in ('approved', 'returned'):
        story.append(p(f"Geprüft von: {name(last_review['actor_id'])} | {date(last_review['created_at'])}", small))
    story.append(Spacer(1, 4 * mm))

    profiles = data.get('profiles') or {}
    ordinary = [field for field in doc['fields'] if field['type'] not in ('risk_register', 'risk_matrix')]
    compact = register and len(profiles) == 1 and 0 < len(ordinary) <= 12
    metadata = None
    if ordinary:
        rows = []
        for field in ordinary:
            value = field_values.get(field['key'], '')
            value = ('Ja' if value else 'Nein') if isinstance(value, bool) else value or '-'
            rows.append([p(field['label']), p(value)])
        meta_width = width * .37 if compact else width
        metadata = table(rows, [meta_width * .37, meta_width * .63])
        if compact and metadata.wrap(meta_width, 10000)[1] > 280:
            compact = False
            metadata = table(rows, [width * .23, width * .77])
        if not compact:
            story.append(metadata)

    legends = []
    legend_width = width * .61 if compact else width
    for profile in profiles.values():
        legends.append(p('Risikomatrix: S = A-E (1-5) | L = 1-5 | R = S × L', small))
        rows = [[p('S / L', legend_text)] + [p(str(level['value']), legend_text) for level in profile['likelihood']]]
        commands = []
        for index, severity in enumerate(profile['severity'], 1):
            values = [p(f"{severity['letter']}", legend_text)]
            for column, likelihood in enumerate(profile['likelihood'], 1):
                score = severity['value'] * likelihood['value']
                band = next(band for band in profile['bands'] if band['min'] <= score <= band['max'])
                values.append(p(score, legend_text))
                commands.append(('BACKGROUND', (column, index), (column, index), colors.HexColor(band['background'])))
            rows.append(values)
        dense_padding = [('LEFTPADDING', (0, 0), (-1, -1), 2), ('RIGHTPADDING', (0, 0), (-1, -1), 2), ('TOPPADDING', (0, 0), (-1, -1), 2), ('BOTTOMPADDING', (0, 0), (-1, -1), 2)]
        matrix_width = legend_width * .20
        matrix = table(rows, [matrix_width / 6] * 6, 1, commands + dense_padding)
        severity_definitions = [p('Schadensschwere S', small)] + [p(f"{level['letter']} ({level['value']}) {level['label']}\n{level['description']}", legend_text) for level in profile['severity']]
        likelihood_definitions = [p('Wahrscheinlichkeit L', small)] + [p(f"{level['value']} {level['label']}\n{level['description']}", legend_text) for level in profile['likelihood']]
        bands = [[p(f"{band['label']} {band['min']}-{band['max']}\n{band['description']}", legend_text)] for band in profile['bands']]
        band_table = table(bands, [legend_width * .24], commands=[('BACKGROUND', (0, i), (0, i), colors.HexColor(band['background'])) for i, band in enumerate(profile['bands'])] + dense_padding)
        compact_legend = Table([[matrix, severity_definitions, likelihood_definitions, band_table]], colWidths=[legend_width * weight for weight in (.20, .28, .28, .24)], hAlign='LEFT')
        compact_legend.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'TOP'), ('LEFTPADDING', (0, 0), (-1, -1), 2), ('RIGHTPADDING', (0, 0), (-1, -1), 2)]))
        legends.append(compact_legend)
    if compact:
        intro = Table([[metadata, '', legends]], colWidths=[width * .37, width * .02, width * .61], hAlign='LEFT')
        intro.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'TOP'), ('LEFTPADDING', (0, 0), (-1, -1), 0), ('RIGHTPADDING', (0, 0), (-1, -1), 0)]))
        story.append(intro)
    else:
        story.extend(legends)

    for field in doc['fields']:
        value = field_values.get(field['key'])
        if field['type'] == 'risk_matrix':
            story.append(p(field['label'], head))
            story.append(p(f"S: {value['severity']} | L: {value['likelihood']} | R: {value['severity'] * value['likelihood']}" if value else 'Bewertung offen', body))
        if field['type'] != 'risk_register':
            continue
        story.append(p(field['label'], register_head))
        headings = ['Nr.', 'Kategorie', 'Gefährdung', 'Mögliche Folgen', 'S', 'L', 'R', 'Schutzmaßnahmen', 'S', 'L', 'R', 'Verantwortlich', 'Wirksamkeitsprüfung']
        rows = [[p(label, small) for label in headings]]
        commands = [('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#e8eee9'))]
        profile = profiles.get(field.get('matrix_profile', ''))
        for index, row in enumerate(value or [], 1):
            values = [str(index), row['category'], row['hazard'], row['impact']]
            for key, start in [('initial', 4), ('residual', 8)]:
                if key == 'residual':
                    values.append(row['controls'])
                risk = row.get(key)
                score = risk['severity'] * risk['likelihood'] if risk else None
                values.extend([chr(64 + risk['severity']) if risk else '-', str(risk['likelihood']) if risk else '-', str(score) if risk else '-'])
                if score and profile:
                    band = next(band for band in profile['bands'] if band['min'] <= score <= band['max'])
                    commands.append(('BACKGROUND', (start + 2, index), (start + 2, index), colors.HexColor(band['background'])))
            values.extend([row['responsible'], row['effectiveness']])
            rows.append([p(item) for item in values])
        if len(rows) == 1:
            rows.append([p('-')] * len(headings))
        widths = [.025, .08, .14, .11, .023, .023, .03, .24, .023, .023, .03, .09, .163]
        story.append(table(rows, [width * weight for weight in widths], 1, commands))
        story.append(p('Ausgangsrisiko: S / L / R vor Maßnahmen. Restrisiko: S / L / R nach Maßnahmen.', small))

    provenance = doc.get('provenance') or {}
    # Preserve legally required reuse attribution, not product-progress or template filling advice.
    if 'Open Government Licence' in provenance.get('licence', ''):
        story.extend([Spacer(1, 3 * mm), p(provenance.get('attribution', ''), small), p(provenance.get('url', ''), small), p(provenance.get('licence', '') + ' | ' + provenance.get('licence_url', ''), small)])

    attachments = data.get('attachments', [])
    if len(attachments) > 5 or sum(item['size_bytes'] for item in attachments) > 3145728:
        raise ValueError('Attachment collection limit')
    readers = [inspect_pdf(item['base64']) for item in attachments]
    if sum(len(reader.pages) for reader in readers) > 200:
        raise ValueError('Combined attachment page limit')
    if attachments:
        story.append(p('Anlagen', head))
        for index, (item, reader) in enumerate(zip(attachments, readers), 1):
            story.append(p(f"{index}. {item['name']} ({len(reader.pages)} Seiten)", body))

    def footer(canvas, pdf):
        canvas.saveState()
        canvas.setFont('KH', 7)
        canvas.setFillColor(colors.HexColor('#48534d'))
        canvas.drawString(12 * mm, 8 * mm, f"Version {doc['version']} | {statuses[doc['status']]} | {doc['id']}")
        canvas.drawRightString(page_size[0] - 12 * mm, 8 * mm, f"Seite {pdf.page}")
        canvas.restoreState()

    output = io.BytesIO()
    pdf = SimpleDocTemplate(output, pagesize=page_size, rightMargin=12 * mm, leftMargin=12 * mm, topMargin=12 * mm, bottomMargin=16 * mm, title=doc['title'], author=name(doc['created_by']))
    pdf.build(story, onFirstPage=footer, onLaterPages=footer)
    return append_attachments(append_signatures(output.getvalue(),data),data)


if __name__ == '__main__':
    try:
        document = json.load(sys.stdin)
        sys.stdout.buffer.write(render(document['document_workflow']))
    except Exception as error:
        print(type(error).__name__, file=sys.stderr)
        sys.exit(1)
