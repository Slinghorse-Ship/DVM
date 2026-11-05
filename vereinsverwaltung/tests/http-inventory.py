"""Loopback smoke checks; reads existing synthetic fixtures with a private session."""
import http.cookiejar
import html as html_tools
import re
import urllib.parse
import urllib.request
import urllib.error

BASE='http://127.0.0.1:8092/vereinsverwaltung/'
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
tests=0
def check(ok,label):
    global tests
    if not ok: raise AssertionError(label)
    tests+=1;print('PASS',label)
def get(query,allow_alert=False):
    try:
        r=client.open(BASE+'?'+query,timeout=20)
    except urllib.error.HTTPError as error:
        if not allow_alert or error.code != 403:
            raise
        r=error
    s=r.read().decode('utf-8')
    check(r.status==(403 if allow_alert else 200) and not any(t in s for t in ['Fatal error','Warning:','Parse error']) and (allow_alert or 'role="alert"' not in s),'GET '+query)
    return s
def persona(actor):
    html=get('view=dashboard');token=re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
    r=client.open(BASE,data=urllib.parse.urlencode({'csrf':token,'action':'persona','actor':actor}).encode(),timeout=20);r.read()
    check(r.status==200,'persona '+actor)

for q in ['view=inventory&tab='+t for t in ['where','items','locations','loans','assets']]+['view=inventory&tab=items&new_item=1','view=inventory&tab=locations&new_location=1','view=inventory&tab=loans&new_loan=1','view=settings&tab=roles&new_role=1','view=settings&tab=progress']:
    get(q)
assets_page=get('view=inventory&tab=assets')
source_links=set(re.findall(r'href="/\?(view=(?:donations|finance)&amp;tab=(?:goods|bank|receipts)&amp;(?:goods|account|entry)=[a-f0-9]+)"',assets_page))
for link in sorted(source_links):
    source_page=get(html_tools.unescape(link))
    check('Verknüpftes Inventar' in source_page,'reverse inventory link from financial source')
html=get('view=inventory&tab=items')
check('Verein wechseln' not in html and '>Webinare<' not in html,'single-club navigation')
items=list(dict.fromkeys(re.findall(r'href="/\?view=inventory&amp;tab=items&amp;item=([a-f0-9]+)"',html)))
for item in items[:8]:
    page=get('view=inventory&tab=items&item='+item)
    check('Bestand bewegen' in page and 'Zuständigkeit & Stammdatenverlauf' in page,'inventory item forms and history')
loans_html=get('view=inventory&tab=loans')
loans=list(dict.fromkeys(re.findall(r'href="/\?view=inventory&amp;tab=loans&amp;loan=([a-f0-9]+)"',loans_html)))
for loan in loans[:8]:
    page=get('view=inventory&tab=loans&loan='+loan)
    check('Drucken / PDF speichern' in page,'handover print control')
get('view=members&id=9e84cab9b67483b249ef3c80a02191fe')
page=get('view=inventory&tab=items');token=re.search(r'name="csrf" value="([a-f0-9]+)"',page).group(1)
r=client.open(BASE+'?view=inventory&tab=items',data=urllib.parse.urlencode({'csrf':token,'action':'inventory-export','export_kind':'items'}).encode(),timeout=20)
check(r.headers.get_content_type()=='text/csv' and b'Inventarnummer' in r.read(),'authorized CSV response')
try:client.open(BASE+'?view=inventory',data=urllib.parse.urlencode({'csrf':'wrong','action':'inventory-location'}).encode(),timeout=20)
except urllib.error.HTTPError as e:check(e.code==403,'inventory CSRF protection')
else:raise AssertionError('CSRF accepted')
persona('demo-member')
page=get('view=inventory&tab=items',True);check('nicht freigegeben' in page and 'inventory-item' not in page,'member blocked from full inventory')
page=get('view=inventory&tab=loans',True);check('nicht freigegeben' in page and 'inventory-reserve' not in page,'member has no loan access')
page=get('view=material',True);check('nicht freigegeben' in page,'member has no material access')
page=get('view=members&id=9e84cab9b67483b249ef3c80a02191fe');check('Inventar, Aufbewahrung & Ausleihen' not in page and 'Offene Ausleihen' not in page,'own profile hides inventory')
page=get('view=inventory&tab=assets',True);check('nicht freigegeben' in page and 'Wert je Einheit ×' not in page,'member cannot read asset values')
persona('demo-finance')
page=get('view=inventory&tab=assets');check('Dokumentierte Gegenstandswerte' in page and 'inventory-item' not in page,'treasurer asset overview without inventory writes')
persona('demo-editor')
page=get('view=inventory&tab=where',True);check('nicht freigegeben' in page and 'iv-where' not in page,'editor separated from inventory')
print(f'{tests} HTTP inventory checks passed')
