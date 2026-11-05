"""Local role-session checks. Only switches the synthetic test persona; no data writes/mail."""
import http.cookiejar
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:8092/vereinsverwaltung/'
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = 0

def request(query='', data=None):
    payload = None if data is None else urllib.parse.urlencode(data).encode()
    try:
        with opener.open(BASE + query, payload, timeout=60) as response:
            return response.status, response.read().decode('utf-8')
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode('utf-8')

def check(ok, label):
    global checks
    assert ok, label
    checks += 1
    print('PASS', label, flush=True)

_, body = request('?view=dashboard')
csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)
status, body = request('?view=dashboard', {'csrf': csrf, 'action': 'persona', 'actor': 'demo-member'})
check(status == 200 and 'Mein Vereinsbereich' in body, 'dedicated member home')
nav = re.search(r'<nav aria-label="Hauptnavigation">(.*?)</nav>', body, re.S).group(1)
for text in ['Finanzen', 'Spenden', 'Alle Kontakte', 'Aktuelle Mitglieder', 'Website', 'Rollen', 'Material', 'Ausleihen', 'Vereinsprofil']:
    check(text not in nav, 'member navigation excludes ' + text)
check('Finanzübersicht' not in body and 'Jubiläumsliste' not in body, 'member dashboard has no administration tiles')
for query in ['?view=material', '?view=inventory&tab=loans', '?view=finance', '?view=finance&tab=contribution-mails', '?view=donations', '?view=inventory&tab=assets', '?view=members', '?view=members&list=contacts', '?view=members&id=another-person', '?view=settings&tab=roles', '?view=documents', '?view=website&tab=press', '?view=portal&tab=application']:
    status, body = request(query)
    check(status == 403 and 'PHP Fatal' not in body, 'direct route denied ' + query)
for query in ['?view=minutes', '?view=calendar', '?view=free', '?view=portal&tab=own']:
    status, body = request(query)
    check(status == 200 and 'PHP Fatal' not in body, 'member route works ' + query)
for query in ['?document_file=missing', '?inventory_asset=missing', '?view=minutes&minute_file=missing', '?donation_original=money']:
    status, _ = request(query)
    check(status == 403, 'private download denied ' + query)
status, body = request('?view=portal&tab=own')
own_link = re.search(r'href="([^"]*view=members[^"]*id=[a-f0-9]+)"', body)
check(own_link is not None, 'own profile remains linked')
own_query = '?' + own_link.group(1).split('?', 1)[1].replace('&amp;', '&')
status, body = request(own_query)
check(status == 200 and 'Inventar, Aufbewahrung &amp; Ausleihen' not in body and 'Inventar, Aufbewahrung & Ausleihen' not in body and 'Offene Ausleihen' not in body and 'tab=loans' not in body, 'own profile hides inventory and loans')
status, body = request('?view=portal')
check(status == 200 and '<h1>Mein Profil</h1>' in body and 'Noch nicht vollständig nutzbar' not in body, 'retired club profile route opens own profile')
status, body = request('?view=settings&tab=account')
check(status == 200, 'own account settings remain available')
tabs = re.search(r'<nav[^>]+(?:aria-label="Einstellungsbereiche"|class="module-tabs")[^>]*>(.*?)</nav>', body, re.S)
if tabs:
    check('Rollen' not in tabs.group(1) and 'Spenden' not in tabs.group(1), 'account subtabs hide administrative settings')
_, body = request('?view=dashboard')
csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)
status, body = request('?view=dashboard', {'csrf': csrf, 'action': 'persona', 'actor': 'demo-manager'})
check(status == 200, 'restore manager test persona')
print(f'{checks} HTTP member access checks passed; no club data changed or mail sent')
