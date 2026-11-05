"""Personal assessment signing on the gated local preview; synthetic data, no external sends."""
import base64
import io
import json
import re
import uuid
import urllib.parse
import urllib.request
import urllib.error
from pathlib import Path
from PIL import Image, ImageDraw
from pypdf import PdfReader
import importlib.util

spec = importlib.util.spec_from_file_location('wf', Path(__file__).with_name('document-workflow-http.py'))
wf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wf)


def pdf(query):
    try:
        response = wf.opener.open(wf.BASE + '?' + urllib.parse.urlencode(query), timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read()


def transition(page, kind):
    fields = wf.form(page, 'document-workflow-document-transition')
    fields['wf_transition'] = kind
    response = wf.request(data=fields)
    wf.check(response[0] == 200, 'transition ' + kind + ' succeeds')
    return response[3]


def run():
    wf.post('workflow-demo-actor', {'actor': 'wf-author'})
    registration = json.loads((Path(__file__).resolve().parents[2] / '.local/document-workflows-20261007/rwe-import.json').read_text(encoding='utf8'))
    title='Online-Kenntnisnahme · Testdaten '+uuid.uuid4().hex[:8]
    _, url, _, page = wf.post('document-workflow-document-create', {'wf_template':registration['template'],'wf_title':title,'wf_folder':''})
    doc = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['wf_doc'][0]
    save = wf.form(page, 'document-workflow-document-save')
    save.update({'wf_title':title,'wf_folder':''})
    for key, value in {'document_ref':'KH-SIGN-TEST','assessment_title':'Testveranstaltung','location':'Testort','activity':'Testaufbau','equipment':'Testkabel','task_step':'Vorbereitung','assessor':'Testperson','approver':'','review_date':'2026-10-10','approval_date':''}.items():
        save[f'wf_values[{key}]'] = value
    for key, value in {'category':'Aufbau','hazard':'Lose Kabel','impact':'Sturz','controls':'Kabelbrücken verwenden','responsible':'Testperson','effectiveness':'Sichtprüfung vor Beginn'}.items():
        save[f'wf_values[risk_register][0][{key}]'] = value
    for part, severity, likelihood in [('initial','3','3'),('residual','2','1')]:
        save[f'wf_values[risk_register][0][{part}][severity]'] = severity
        save[f'wf_values[risk_register][0][{part}][likelihood]'] = likelihood
    status, _, _, page = wf.request(data=save)
    wf.check(status == 200, 'synthetic German assessment saved')
    page = transition(page, 'submit')
    page = transition(page, 'approve')
    request = wf.form(page, 'document-workflow-signature-request')
    request['wf_recipients[]'] = 'wf-member'
    _, _, _, page = wf.request(data=request)
    wf.check('Unterschrift offen' in page, 'writer request visible')
    request = wf.form(page, 'document-workflow-signature-request')
    request['wf_recipients[]'] = 'wf-reader'
    _, _, _, page = wf.request(data=request)
    request['wf_recipients[]'] = 'wf-assignee'
    wf.check(wf.request(data={**request,'csrf':'invalid'})[0] == 403, 'signature requests require CSRF')
    wf.post('workflow-demo-actor', {'actor': 'wf-member'})
    _, _, _, own = wf.request('?wf_own=1')
    wf.check('Online-Kenntnisnahme' in own and 'Vorlagen &amp; Editor' not in own, 'member personal list excludes module workspace')
    request_id = re.search(r'wf_sign=([a-f0-9]{32})', own).group(1)
    status, _, _, page = wf.request('?wf_sign=' + request_id)
    wf.check(status == 200 and 'data-dwf-signature-canvas' in page, 'member signature form available without module right')
    wf.check(wf.document_export(doc)[0] == 422, 'member cannot export general module documents')
    fields = wf.form(page, 'document-workflow-signature-sign')
    status, original = pdf({'wf_signature_pdf':request_id})
    wf.check(status == 200 and len(PdfReader(io.BytesIO(original)).pages) == 1, 'assigned member downloads original German PDF')
    image = Image.new('RGB', (600,180),'white')
    ImageDraw.Draw(image).line([(20,130),(70,20),(120,140),(160,50),(220,120),(300,60),(370,140),(450,30),(540,110)], fill=(29,68,119),width=3)
    stream = io.BytesIO();image.save(stream,format='PNG')
    fields.update({'wf_accepted':'1','wf_signature_png':'data:image/png;base64,'+base64.b64encode(stream.getvalue()).decode()})
    wf.check(wf.request(data={**fields,'csrf':'invalid'})[0] == 403, 'signing requires CSRF')
    wf.check(wf.request(data={**fields,'wf_document_hash':'0'*64})[0] == 422, 'wrong document fingerprint blocked')
    blank = Image.new('RGB',(600,180),'white');stream=io.BytesIO();blank.save(stream,format='PNG')
    wf.check(wf.request(data={**fields,'wf_signature_png':'data:image/png;base64,'+base64.b64encode(stream.getvalue()).decode()})[0] == 422, 'blank canvas rejected by server')
    wf.check(wf.request(data={**fields,'wf_accepted':'0'})[0] == 422, 'unchecked acknowledgement rejected')
    status, _, _, signed = wf.request(data=fields)
    wf.check(status == 200 and 'Unterschrieben von Mara Muster' in signed and 'data-dwf-signature-canvas' not in signed, 'member signs and form becomes read-only')
    wf.check(wf.request(data=fields)[0] == 422, 'repeat signature rejected through HTTP')
    status, signed_pdf = pdf({'wf_signature_pdf':request_id})
    pages = PdfReader(io.BytesIO(signed_pdf)).pages
    text = '\n'.join(p.extract_text() or '' for p in pages)
    wf.check(status == 200 and len(pages) == 2 and 'Elektronische Kenntnisnahme' in text and fields['wf_document_hash'] in text and 'Mara Muster' in text, 'PDF includes signature identity timestamp and exact revision fingerprint')
    wf.post('workflow-demo-actor', {'actor': 'wf-reader'})
    wf.check(wf.request('?wf_sign='+request_id)[0] == 403 and pdf({'wf_signature_pdf':request_id})[0] == 422, 'other member cannot open or download foreign request')
    status, _, _, page = wf.request('?wf_doc='+doc)
    wf.check('Unterschriften anfordern' not in page and 'Unterschrieben' in page, 'reader sees evidence but cannot request signatures')
    wf.post('workflow-demo-actor', {'actor': 'wf-author'})
    _, _, _, page = wf.request('?wf_doc='+doc)
    page = transition(page,'reopen')
    wf.post('workflow-demo-actor', {'actor':'wf-reader'})
    _, _, _, own = wf.request('?wf_own=1')
    reader_id = re.search(r'wf_sign=([a-f0-9]{32})',own).group(1)
    _, _, _, page = wf.request('?wf_sign='+reader_id)
    wf.check('data-dwf-signature-canvas' not in page and 'geändert oder zurückgezogen' in page, 'unsigned older revision no longer signable')
    wf.post('workflow-demo-actor', {'actor':'wf-member'})
    _, _, _, page = wf.request('?wf_sign='+request_id)
    wf.check('Unterschrieben von Mara Muster' in page, 'previous signed revision preserved after reopening')
    wf.post('workflow-demo-actor', {'actor':'wf-author'})
    _, _, _, page = wf.request('?wf_doc='+doc)
    page = transition(page,'submit'); page = transition(page,'approve')
    for member in ('wf-member','wf-reader'):
        request = wf.form(page,'document-workflow-signature-request');request['wf_recipients[]']=member
        _, _, _, page = wf.request(data=request)
    wf.post('workflow-demo-actor', {'actor':'wf-member'})
    _, _, _, own = wf.request('?wf_own=1')
    wf.check(own.count(title) == 2, 'each document version requires separate acknowledgement')
    pending_id = re.search(r'wf_sign=([a-f0-9]{32})',own).group(1)
    # The browser test uses this pending request; never alters the existing preview assessment.
    root=Path(__file__).resolve().parents[2]
    (root/'.local/document-workflows-20261007/signature-browser.json').write_text(json.dumps({'document':doc,'request':pending_id}),encoding='utf8')
    report={'suite':'document-workflow-signatures-http','status':'passed','checks':len(wf.checks),'labels':wf.checks,'production_changed':False}
    (root/'.local/test-results').mkdir(parents=True,exist_ok=True)
    (root/'.local/test-results/document-workflow-signatures-http.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf8')
    print(json.dumps(report,ensure_ascii=False,indent=2))


if __name__=='__main__':run()
