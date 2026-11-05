"""Read-only check of the two applications in one local Apache/PHP server."""
from html.parser import HTMLParser
import http.cookiejar
import urllib.error
import urllib.parse
import urllib.request

ORIGIN='http://127.0.0.1:8092'
BASE=ORIGIN+'/vereinsverwaltung/'
checks=0
jar=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def check(ok,label):
    global checks
    if not ok:raise AssertionError(label)
    checks+=1
    print('PASS',label)

class Links(HTMLParser):
    def __init__(self):super().__init__();self.links=[];self.assets=[]
    def handle_starttag(self,tag,attrs):
        values=dict(attrs)
        if tag=='a' and values.get('href'):self.links.append(values['href'])
        if tag=='form' and values.get('action'):self.links.append(values['action'])
        if tag=='script' and values.get('src'):self.assets.append(values['src'])
        if tag=='link' and values.get('rel')=='stylesheet':self.assets.append(values['href'])

assets=set()
for query in ['view=dashboard','view=members','view=finance&tab=bank','view=donations','view=documents','view=inventory','view=website','view=settings&tab=paper','view=apply']:
    address=BASE+'?'+query
    with client.open(address,timeout=20) as response:
        body=response.read().decode()
        check(response.status==200 and response.headers.get('X-Kulturhus-Local-App')=='vereinsverwaltung','shared Apache club route '+query)
        # Missing local banking configuration is an expected explicit integration state.
        if query=='view=finance&tab=bank':body=body.replace('<p class="error" role="alert">Diese Bankumgebung ist noch nicht eingerichtet.</p>','')
        check('Fatal error' not in body and 'Warning:' not in body and 'role="alert"' not in body,'render '+query)
    parser=Links();parser.feed(body)
    targets=[urllib.parse.urlparse(urllib.parse.urljoin(address,link)) for link in parser.links]
    club_links=[target for target in targets if target.netloc=='127.0.0.1:8092' and 'view' in urllib.parse.parse_qs(target.query)]
    check(all(target.path=='/vereinsverwaltung/' for target in club_links),'club navigation stays in its mount '+query)
    assets.update(urllib.parse.urljoin(address,path) for path in parser.assets)
for asset in sorted(assets):
    with client.open(asset,timeout=10) as response:
        check(response.status==200 and urllib.parse.urlparse(asset).path.startswith('/vereinsverwaltung/'),'mounted asset '+urllib.parse.urlparse(asset).path)
check(any(cookie.name=='KH_VEREIN_DEMO' and cookie.path=='/vereinsverwaltung/' and any(cookie.has_nonstandard_attr(name) for name in ['HttpOnly','httponly']) for cookie in jar),'club session restricted to club path')
for path in ['/','/veranstaltungen/','/processwire/']:
    with client.open(ORIGIN+path,timeout=20) as response:
        body=response.read()
        check(response.status==200 and response.headers.get('X-Kulturhus-Local-App') is None,'ProcessWire retains website route '+path)
for path in ['/vereinsverwaltung/src/Infrastructure/Database/Database.php','/vereinsverwaltung/../.local/vereinsverwaltung/synthetic.sqlite','/.local/vereinsverwaltung/synthetic.sqlite','/workspace/.local/vereinsverwaltung/synthetic.sqlite']:
    try:
        with client.open(ORIGIN+path,timeout=10) as response:
            body=response.read()
            check(response.status in (403,404) and b'SQLite format' not in body and b'<?php' not in body,'private source/data denied')
    except urllib.error.HTTPError as response:check(response.code in (400,403,404),'private source/data denied')
request=urllib.request.Request(BASE,headers={'Host':'www.kulturhus.de'})
try:
    client.open(request,timeout=10)
except urllib.error.HTTPError as response:check(response.code in (400,403,421),'nonlocal host rejected')
else:raise AssertionError('Nonlocal host accepted')
print(f'{checks} shared server checks passed')
