"""Verify the local manager session across ProcessWire bootstrap; no club data writes."""
import http.cookiejar
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:8092/vereinsverwaltung/'
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
checks = 0


def request(query, data=None):
    payload = None if data is None else urllib.parse.urlencode(data).encode()
    try:
        with opener.open(BASE + query, payload, timeout=30) as response:
            return response.status, response.read().decode('utf-8')
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode('utf-8')


def token(body):
    return re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)


def check(ok, label):
    global checks
    assert ok, label
    checks += 1
    print('PASS', label, flush=True)


_, body = request('?view=dashboard')
_, body = request('?view=dashboard', {'csrf': token(body), 'action': 'persona', 'actor': 'demo-member'})
original_token = token(body)
session_id = next(c.value for c in jar if c.name == 'KH_VEREIN_DEMO')
check('Mein Vereinsbereich' in body, 'synthetic member identity selected')
status, body = request('?view=settings&tab=roles')
check(status == 403, 'ProcessWire directory bootstrap keeps member permissions')
check(token(body) == original_token, 'CSRF token survives second session bootstrap')
check(next(c.value for c in jar if c.name == 'KH_VEREIN_DEMO') == session_id,
      'manager session cookie is retained')
_, body = request('?view=settings&tab=account')
check(token(body) == original_token and 'Dein Zugang' in body, 'account form retains same session')
# Invalid intent exercises the normal account form CSRF gate without saving any settings.
_, body = request('?view=settings&tab=account', {
    'csrf': original_token, 'action': 'settings-save', 'section': 'account', 'intent': 'continuity-test',
})
check('Ungültige Formularaktion.' in body and 'Formular abgelaufen' not in body,
      'account POST accepts the token after ProcessWire bootstrap')
status, body = request('?view=settings&tab=account', {
    'csrf': '0' * 64, 'action': 'settings-save', 'section': 'account', 'intent': 'continuity-test',
})
check(status == 403 and 'Formular abgelaufen' in body, 'invalid CSRF remains rejected')
_, body = request('?view=dashboard')
check('Mein Vereinsbereich' in body, 'invalid form never changes the member identity')
print(f'{checks} session continuity checks passed; no account data changed or mail sent')
