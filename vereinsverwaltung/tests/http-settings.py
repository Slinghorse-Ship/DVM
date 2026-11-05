"""Local-only smoke test. Own cookie jar; no configuration writes or real identity."""
import http.cookiejar
import json
import re
import sys
import urllib.parse
import urllib.request
from pathlib import Path

BASE='http://127.0.0.1:8092/vereinsverwaltung/'
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
tests=0
def check(ok,label):
    global tests
    if not ok: raise AssertionError(label)
    tests+=1;print('PASS',label)
def get(query):
    response=client.open(BASE+'?'+query,timeout=20)
    body=response.read().decode('utf-8')
    check(response.status==200 and 'Fatal error' not in body and 'Warning:' not in body and 'role="alert"' not in body,'GET '+query)
    return body

for query in ['view=dashboard']+['view=settings&tab='+tab for tab in ['','account','roles','numbers','rates','services','texts','paper']]+['view=settings&section='+section for section in ['salutations','bank','tax','mail','structure','fields']]+['view=settings&tab=texts&group='+group for group in ['invoices','donations']]+['view=settings&tab=rates&new_catalog=1&kind=donation','view=settings&tab=services&new_catalog=1&kind=service','view=settings&tab=roles&new_role=1']:
    get(query)
body=get('view=settings&tab=paper')
csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body).group(1)
version=re.search(r'name="version" value="(\d+)"',body).group(1)
data=json.loads((Path(sys.argv[1]) if len(sys.argv)>1 else Path(__file__).parent/'fixtures'/'letterhead.json').read_text('utf-8'))['paper']
fields={'csrf':csrf,'action':'settings-save','section':'paper','version':version,'intent':'pdf'}
fields.update({'data['+key+']':('1' if val else '0') if isinstance(val,bool) else str(val) for key,val in data.items()})
response=client.open(BASE+'?view=settings&tab=paper',data=urllib.parse.urlencode(fields).encode(),timeout=25)
pdf=response.read();check(response.status==200 and response.headers.get_content_type()=='application/pdf' and pdf.startswith(b'%PDF-'),'PDF through protected web controller')
check('attachment' in response.headers['Content-Disposition'],'PDF download headers')
fields['csrf']='wrong'
try: client.open(BASE+'?view=settings&tab=paper',data=urllib.parse.urlencode(fields).encode(),timeout=20)
except urllib.error.HTTPError as error: check(error.code==403,'CSRF validation on PDF POST')
else: raise AssertionError('CSRF bypass')
# Change only this test session, not the user's browser session or actors table.
body=get('view=dashboard');csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body).group(1)
client.open(BASE,data=urllib.parse.urlencode({'csrf':csrf,'action':'persona','actor':'demo-member'}).encode(),timeout=20).read()
try: body=client.open(BASE+'?view=settings&tab=paper',timeout=20).read().decode()
except urllib.error.HTTPError as error:
    check(error.code==403,'member settings returns forbidden');body=error.read().decode()
check('data-paper-editor' not in body and 'name="data[logo]"' not in body,'member cannot open letterhead settings')
try: body=client.open(BASE+'?view=settings&section=bank',timeout=20).read().decode()
except urllib.error.HTTPError as error:
    check(error.code==403,'member bank settings returns forbidden');body=error.read().decode()
check('name="data[iban]"' not in body,'member cannot read bank settings')
print(f'{tests} HTTP checks passed')
