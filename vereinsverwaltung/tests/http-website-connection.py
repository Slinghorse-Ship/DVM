"""Real local VV -> ProcessWire -> public test-site round trip, no live access.
Restores the only page changed and leaves one labelled unpublished synthetic news draft.
"""
from pathlib import Path
import os
import html
import http.cookiejar
import json
import re
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT=Path(__file__).resolve().parents[2]
BASE='http://127.0.0.1:8092/vereinsverwaltung/'
SITE='http://127.0.0.1:8092'
key=Path(os.environ.get('KULTURHUS_TEST_BRIDGE_KEY',str(ROOT/'.local/processwire/bridge.key'))).read_text('utf-8').strip()
checks=0

def check(ok,label):
    global checks
    if not ok:raise AssertionError(label)
    checks+=1;print('PASS',label)

def api(action,fields=None,secret=None):
    body=json.dumps({'action':action,**(fields or {})}).encode()
    request=urllib.request.Request(SITE+'/verein-bridge.php',body,{'Content-Type':'application/json','X-Kulturhus-Local-Key':key if secret is None else secret})
    try:
        with urllib.request.urlopen(request,timeout=12) as response:return response.status,json.load(response)
    except urllib.error.HTTPError as response:return response.code,json.load(response)

def browser():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
client=browser()

def page(query,fields=None,opener=None):
    try:response=(opener or client).open(BASE+'?'+query,data=urllib.parse.urlencode(fields).encode() if fields else None,timeout=20)
    except urllib.error.HTTPError as error:
        if error.code!=403:raise
        response=error
    with response:
        content=response.read().decode()
        check(not any(error in content for error in ['Fatal error','Parse error','Warning:']),'render '+query)
        return response.url,content

def hidden(content,name):
    match=re.search(r'name="'+re.escape(name)+r'" value="([^"]*)"',content)
    if not match:raise AssertionError('Missing form field '+name)
    return html.unescape(match[1])

status,data=api('status');check(status==200 and data['data']['environment']=='local-synthetic','authentic local ProcessWire connection')
status,_=api('status',secret='0'*64);check(status==403,'invalid bridge key rejected')
status,_=api('page/get',{'path':'/processwire/'});check(status in (400,403,404),'admin pages cannot be accessed through content bridge')
_,content=page('view=website&tab=technical');check('Website verbunden' in content and key not in content,'connection visible without browser secret')
for page_key in ['home','club','membership','contact','imprint','privacy','deletion','terms']:
    _,content=page('view=website&tab=pages&connected_page='+page_key)
    check('name="expected_version"' in content,'ProcessWire content loaded '+page_key)
status,data=api('page/get',{'path':'/verein/'});original=data['data']
changed=False
try:
    _,content=page('view=website&tab=pages&connected_page=club')
    fields={name:hidden(content,name) for name in ['csrf','kind','page_key','local_id','expected_version','request_key']}
    fields['action']='website-local-save'
    fields.update(title='Verein · Verbindungstest '+uuid.uuid4().hex[:8],summary=original['summary'],body=original['body'])
    changed=True  # A committed response may be lost; restore responsibility starts before POST.
    _,saved=page('view=website&tab=pages&connected_page=club',fields)
    alerts=re.findall(r'<(?:section|div)[^>]*role="alert"[^>]*>(.*?)</(?:section|div)>',saved,re.S)
    error=' '.join(html.unescape(re.sub('<[^>]+>',' ',alert)).strip() for alert in alerts)
    check('Website-Inhalt gespeichert.' in saved,'VV form writes to real local ProcessWire'+(' — '+error if error else ''))
    _,data=api('page/get',{'path':'/verein/'});current=data['data']
    check(current['title']==fields['title'] and current['body_html']==original['body_html'],'saved title with unchanged rich content')
    with urllib.request.urlopen(SITE+'/verein/',timeout=15) as response:public=response.read().decode()
    check(fields['title'] in html.unescape(public),'change appears on the public local test page')
    _,retry=page('view=website&tab=pages&connected_page=club',fields)
    _,data=api('page/get',{'path':'/verein/'});check(data['data']['version']==current['version'],'repeated submit is idempotent')
    fields['request_key']=uuid.uuid4().hex
    _,stale=page('view=website&tab=pages&connected_page=club',fields)
    check('inzwischen geändert' in stale,'concurrent stale content rejected in VV')
finally:
    if changed:
        _,data=api('page/get',{'path':'/verein/'});latest=data['data']
        if latest['title']==fields['title']:
            status,_=api('page/save',{'path':'/verein/','expected_version':latest['version'],'request_key':uuid.uuid4().hex,'title':original['title'],'summary':latest['summary'],'body':latest['body']})
            check(status==200,'original test page title restored')
        else:check(latest['title']==original['title'],'page unchanged or concurrent edit requires review')

_,content=page('view=website&tab=news&new_connected_article=1')
fields={name:hidden(content,name) for name in ['csrf','kind','page_key','local_id','expected_version','request_key']}
fields['action']='website-local-save'
title='Unveröffentlichter Verbindungstest · Testdaten '+uuid.uuid4().hex[:8]
fields.update(title=title,summary='Synthetischer Test ohne Versand',body='<script>Test</script>\nNur lokale Testdaten.')
destination,content=page('view=website&tab=news&new_connected_article=1',fields)
check('connected_news=' in destination and '&lt;script&gt;Test&lt;/script&gt;' in content,'news created through VV and escaped in preview')
news_id=int(urllib.parse.parse_qs(urllib.parse.urlparse(destination).query)['connected_news'][0])
_,data=api('news/get',{'id':news_id});check(not data['data']['published'],'new test article remains unpublished')
check('<script>' not in data['data']['body_html'],'plain text cannot become active markup')
for actor in ['demo-finance','demo-member','demo-editor']:
    user=browser();_,content=page('view=website',opener=user)
    page('view=website',{'action':'persona','csrf':hidden(content,'csrf'),'actor':actor},user)
    _,content=page('view=website&tab=pages&connected_page=club',opener=user)
    if actor=='demo-editor':check('name="expected_version"' in content,'editor may manage local website')
    else:
        check('name="expected_version"' not in content,'role denied connected content '+actor)
        fields.update(csrf=hidden(content,'csrf'),title='Unauthorized write')
        _,denied=page('view=website&tab=news&new_connected_article=1',fields,user)
        check(('Keine Berechtigung' in denied or 'nicht freigegeben' in denied),'connected write denied '+actor)
print(f'{checks} local website connection checks passed')
