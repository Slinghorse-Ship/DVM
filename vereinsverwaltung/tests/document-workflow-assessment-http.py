"""Actual multipart PDF uploads and protected downloads; loopback synthetic data only."""
import io
import importlib.util
import json
import urllib.parse
import urllib.request
from pathlib import Path
from pypdf import PdfReader, PdfWriter
from reportlab.pdfgen.canvas import Canvas
spec = importlib.util.spec_from_file_location('workflow_http', Path(__file__).with_name('document-workflow-http.py'))
wf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wf)


def multipart(fields, filename, payload):
    boundary = 'KulturhusSyntheticPdfBoundary'
    parts = []
    for name, value in fields.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    parts.extend([f'--{boundary}\r\nContent-Disposition: form-data; name="wf_pdf"; filename="{filename}"\r\nContent-Type: application/pdf\r\n\r\n'.encode(), payload, f'\r\n--{boundary}--\r\n'.encode()])
    req = urllib.request.Request(wf.BASE, data=b''.join(parts), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        response = wf.opener.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode('utf8')


def download(query):
    try:
        response = wf.opener.open(wf.BASE + '?' + urllib.parse.urlencode(query), timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, dict(response.headers), response.read()


def run():
    wf.post('workflow-demo-actor', {'actor': 'wf-author'})
    fields = {'wf_title': 'PDF upload test', 'wf_version': '0', 'wf_description': 'DO NOT PRINT product hints', 'wf_fields[0][key]': 'activity', 'wf_fields[0][label]': 'Tätigkeit', 'wf_fields[0][type]': 'text', 'wf_fields[0][help]': 'DO NOT PRINT filling help'}
    status, url, _, page = wf.post('document-workflow-template-save', fields)
    wf.check(status == 200, 'PDF fixture template saved')
    template = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['wf_template'][0]
    wf.request(data=wf.form(page, 'document-workflow-template-publish'))
    status, url, _, page = wf.post('document-workflow-document-create', {'wf_template': template, 'wf_title': 'PDF HTTP Test', 'wf_folder': ''})
    doc = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['wf_doc'][0]
    fields = wf.form(page, 'document-workflow-document-save')
    fields.update({'wf_title': 'PDF HTTP Test', 'wf_folder': '', 'wf_values[activity]': 'Veranstaltung - nur Testdaten'})
    _, _, _, page = wf.request(data=fields)
    output = io.BytesIO()
    canvas = Canvas(output)
    canvas.drawString(50, 700, 'PDF-Anlage - nur synthetische Testdaten')
    canvas.save()
    attachment = output.getvalue()
    status, page = multipart(wf.form(page, 'document-workflow-attachment-add'), 'lageplan.pdf', attachment)
    wf.check(status == 200 and 'lageplan.pdf' in page, 'real multipart PDF upload succeeds')
    data = json.loads(wf.document_export(doc)[3])['document']
    version = data['version']
    aid = data['attachments'][0]['id']
    status, headers, pdf = download({'wf_export': doc, 'wf_format': 'pdf'})
    reader = PdfReader(io.BytesIO(pdf))
    text = '\n'.join(page.extract_text() or '' for page in reader.pages)
    wf.check(status == 200 and headers.get('Content-Type') == 'application/pdf' and len(reader.pages) == 2, 'PDF download includes attachment page')
    wf.check('DO NOT PRINT' not in text and 'PDF-Anlage - nur synthetische Testdaten' in text, 'PDF omits product hints and includes actual attachment content')
    wf.check(download({'wf_doc': doc, 'wf_attachment': aid})[2] == attachment, 'protected download preserves original bytes')
    malicious = PdfWriter()
    malicious.add_blank_page(width=595, height=842)
    malicious.add_js("app.alert('Synthetic test only')")
    stream = io.BytesIO()
    malicious.write(stream)
    _, _, _, page = wf.request('?wf_doc=' + doc)
    wf.check(multipart(wf.form(page, 'document-workflow-attachment-add'), 'active.pdf', stream.getvalue())[0] == 422, 'active JavaScript PDF rejected')
    encrypted = PdfWriter()
    encrypted.add_blank_page(width=595, height=842)
    encrypted.encrypt('test-only-password')
    stream = io.BytesIO()
    encrypted.write(stream)
    wf.check(multipart(wf.form(page, 'document-workflow-attachment-add'), 'encrypted.pdf', stream.getvalue())[0] == 422, 'password-protected PDF rejected')
    wf.check(json.loads(wf.document_export(doc)[3])['document']['version'] == version, 'rejected PDFs do not create document revisions')
    wf.post('workflow-demo-actor', {'actor': 'wf-assignee'})
    _, _, _, page = wf.request('?wf_doc=' + doc)
    transition = wf.form(page, 'document-workflow-document-transition')
    transition['wf_transition'] = 'submit'
    _, _, _, page = wf.request(data=transition)
    transition = wf.form(page, 'document-workflow-document-transition')
    transition['wf_transition'] = 'approve'
    status, _, _, page = wf.request(data=transition)
    wf.check(status == 200 and 'Freigegeben' in page, 'another writer can submit and approve through UI forms')
    wf.post('workflow-demo-actor', {'actor': 'wf-reader'})
    wf.check(download({'wf_doc': doc, 'wf_attachment': aid})[0] == 200, 'authorized reader downloads attachment')
    wf.check(multipart({'csrf': wf.csrf(wf.request()[3]), 'action': 'document-workflow-attachment-add', 'wf_id': doc, 'wf_version': str(version)}, 'reader.pdf', attachment)[0] == 422, 'reader cannot upload through HTTP')
    wf.post('workflow-demo-actor', {'actor': 'wf-author'})
    # RWE form gets its own new synthetic document; the existing preview stays unchanged.
    registration = json.loads((Path(__file__).resolve().parents[2] / '.local/document-workflows-20261007/rwe-import.json').read_text(encoding='utf8'))
    status, url, _, page = wf.post('document-workflow-document-create', {'wf_template': registration['template'], 'wf_title': 'RWE Formular - synthetische Prüfung', 'wf_folder': ''})
    rwe_doc = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['wf_doc'][0]
    save = wf.form(page, 'document-workflow-document-save')
    save.update({'wf_title':'RWE Formular - synthetische Prüfung','wf_folder':''})
    for key, value in {'document_ref':'TEST-GB-001','assessment_title':'Testveranstaltung','location':'Testort','activity':'Testaufbau','equipment':'Testkabel','task_step':'Vorbereitung','assessor':'Testperson','approver':'Keine echte Freigabe','review_date':'2026-10-10','approval_date':''}.items():
        save[f'wf_values[{key}]'] = value
    for key, value in {'category':'Aufbau','hazard':'Lose Kabel','impact':'Sturz','controls':'Kabelbrücken verwenden','responsible':'Testperson','effectiveness':'Sichtprüfung vor Beginn'}.items():
        save[f'wf_values[risk_register][0][{key}]'] = value
    for key in ('initial','residual'):
        save[f'wf_values[risk_register][0][{key}][severity]']='2'
        save[f'wf_values[risk_register][0][{key}][likelihood]']='2'
    status, _, _, page = wf.request(data=save)
    wf.check(status == 200, 'translated RWE header and register saved through controller')
    status, _, pdf = download({'wf_export':rwe_doc,'wf_format':'pdf'})
    reader = PdfReader(io.BytesIO(pdf)); text = '\n'.join(p.extract_text() or '' for p in reader.pages)
    wf.check(status == 200 and len(reader.pages)==1 and 'UMWELT' in text and 'SICHERHEIT' in text and 'Unterschrift' in text, 'German RWE PDF preserves header, matrix axes and signature section on one page')
    wf.check('/OpenAction' not in reader.trailer['/Root'] and '/AcroForm' not in reader.trailer['/Root'], 'RWE reference scripts and filled signature widgets not copied')
    wf.check(multipart(wf.form(page,'document-workflow-attachment-add'),'RWE-anlage.pdf',attachment)[0] == 200, 'RWE form accepts private PDF attachment')
    _, _, pdf = download({'wf_export':rwe_doc,'wf_format':'pdf'})
    reader = PdfReader(io.BytesIO(pdf))
    wf.check(len(reader.pages)==2 and 'PDF-Anlage - nur synthetische Testdaten' in (reader.pages[-1].extract_text() or ''), 'RWE export includes original attached page')
    wf.post('workflow-demo-actor', {'actor':'wf-assignee'})
    _, _, _, page = wf.request('?wf_doc='+rwe_doc)
    transition=wf.form(page,'document-workflow-document-transition');transition['wf_transition']='submit'
    _, _, _, page=wf.request(data=transition)
    transition=wf.form(page,'document-workflow-document-transition');transition['wf_transition']='approve'
    wf.check(wf.request(data=transition)[0]==200,'another module writer reviews and approves RWE form')
    _, _, pdf = download({'wf_export':rwe_doc,'wf_format':'pdf'})
    text=PdfReader(io.BytesIO(pdf)).pages[0].extract_text() or ''
    wf.check('Demo · Beauftragte Person' in text and 'Keine echte Freigabe' not in text and 'Freigegeben' in text,'RWE approval header uses actual recorded reviewer, not manually claimed approval')
    wf.post('workflow-demo-actor', {'actor':'wf-author'})
    report = {'suite': 'assessment-http', 'status': 'passed', 'checks': len(wf.checks), 'passed': wf.checks, 'document_id': doc, 'production_changed': False}
    output = Path(__file__).resolve().parents[2] / 'audit' / 'document-workflow-assessment-http-20261008.json'
    output.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding='utf8')
    print(json.dumps(report, ensure_ascii=False))


if __name__ == '__main__':
    run()
