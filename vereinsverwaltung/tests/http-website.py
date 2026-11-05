"""Local website routes, content escaping and editorial permission separation.
Only the labelled synthetic article is changed. No requests to kulturhus.de.
"""
import http.cookiejar, urllib.request, urllib.parse, urllib.error, re, uuid
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
tests=0
def check(ok,label):
    global tests
    assert ok,label
    tests+=1
    print('PASS',label)
def request(query,fields=None,errors=False):
    try:r=client.open('http://127.0.0.1:8092/vereinsverwaltung/?'+query, data=urllib.parse.urlencode(fields).encode() if fields else None)
    except urllib.error.HTTPError as error:
        if not errors or error.code!=403:raise
        r=error
    page=r.read().decode()
    check(not any(s in page for s in ['Fatal error','Parse error','Warning:']) and (errors or 'role="alert"' not in page),'render '+query)
    return r,page
def csrf(page): return re.search(r'name="csrf" value="([a-f0-9]+)"',page)[1]
_,page=request('view=website')
check('Abteilungen</a>' not in page and 'Verbandsmeldung</a>' not in page,'unneeded navigation hidden')
for tab in ['pages','news','gallery','events','newsletter','recipients','social','press','technical']:
    request('view=website&tab='+tab)
for tab in ['departments','association']:
    r,_=request('view=members-extra&tab='+tab)
    check('list=current' in r.url,'old module link redirects')
r,_=request('view=settings&section=structure');check('section=structure' not in r.url,'structure settings hidden')
request('view=website&tab=pages&site_page=home')
_,page=request('view=website&tab=news&new_article=1')
fields={'csrf':csrf(page),'action':'website-save','draft_id':'','version':0,'kind':'news','page_key':'','title':'Websiteprüfung · Testdaten '+uuid.uuid4().hex[:6],'summary':'Synthetischer Test','body':'<script>alert(1)</script>\nText','status':'draft'}
r,page=request('view=website&tab=news&new_article=1',fields)
id_=urllib.parse.parse_qs(urllib.parse.urlparse(r.url).query)['draft'][0]
check('&lt;script&gt;alert(1)&lt;/script&gt;' in page and '<script>alert(1)</script>' not in page,'stored text safely escaped in editor and preview')
fields.update(draft_id=id_,version=1,status='review');_,page=request('view=website&tab=news&draft='+id_,fields)
check('Version 2' in page and 'Zur Prüfung' in page,'saved version and review status visible')
request('view=website&tab=news&draft='+id_,fields,errors=True)
fields.update(version=2,status='archived');request('view=website&tab=news&draft='+id_,fields)
_,page=request('view=website&tab=news&draft_state=archived');check(id_ in page,'archive retains test article')
for actor in ['demo-editor','demo-finance','demo-member']:
    _,page=request('view=website',errors=True)
    r,page=request('view=website',{'csrf':csrf(page),'action':'persona','actor':actor},errors=True)
    if actor=='demo-editor':
        check('view=website' in r.url and 'Keine Berechtigung' not in page,'editor lands in website workspace')
        _,page=request('view=website&tab=news&draft='+id_)
        request('view=website&tab=technical',errors=True)
        check('tab=technical' not in page,'technical navigation hidden for editor')
        _,denied=request('view=finance&tab=reports',errors=True);check(('Keine Berechtigung' in denied or 'nicht freigegeben' in denied),'editor denied club finance')
        _,denied=request('view=documents',errors=True);check(('Keine Berechtigung' in denied or 'nicht freigegeben' in denied),'editor denied documents')
    else:
        _,page=request('view=website&tab=news&draft='+id_,errors=True)
        check(fields['title'] not in page,'non-editor cannot read article')
        fields.update(csrf=csrf(page),version=3,status='review')
        _,page=request('view=website&tab=news&draft='+id_,fields,errors=True)
        check(('Keine Berechtigung' in page or 'nicht freigegeben' in page),'non-editor write denied')
print(f'{tests} website HTTP checks passed')
