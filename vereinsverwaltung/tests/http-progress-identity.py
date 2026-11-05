"""Read-only checks against the shared local server; never sends mail or edits users."""
import http.cookiejar
import urllib.request
import urllib.error
from html.parser import HTMLParser

BASE='http://127.0.0.1:8092/vereinsverwaltung/'
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(query):
    try:
        with opener.open(BASE+query, timeout=20) as r: return r.status,r.read().decode('utf-8')
    except urllib.error.HTTPError as e: return e.code,e.read().decode('utf-8')

status,body=get('?view=settings&tab=progress')
assert status==200 and 'Mit Code und Tests abgeglichen' in body
assert 'Gemeinsame' in body or 'gemeinsame' in body
status,body=get('?view=calendar&event=website%3A1057')
assert status==200 and 'Veranstaltung ansehen' in body and 'Tickets öffnen' not in body
status,body=get('?view=website&tab=news&native_new=1')
class Channels(HTMLParser):
    values=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='input' and a.get('name')=='fields[channels][]' and a.get('type')=='checkbox': self.values.append(a['value'])
p=Channels();p.feed(body)
assert status==200 and p.values==['website','rss'],p.values
status,body=get('?view=free');assert status==200 and 'Freie Listen' in body
status,body=get('?view=settings&tab=roles');assert status==200 and 'Gemeinsame ProcessWire-Anmeldung' in body
status,body=get('?login=processwire');assert status==403 and 'Bitte zuerst in ProcessWire anmelden' in body
status,body=get('?view=dashboard');assert status==403 and 'Bitte zuerst in ProcessWire anmelden' in body
print('7 read-only HTTP checks passed; no messages sent or user mappings changed')
