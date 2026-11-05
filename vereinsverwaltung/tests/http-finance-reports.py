"""Loopback-only report rendering/download checks using synthetic personas; no data writes."""
import http.cookiejar
import io
import re
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

BASE = 'http://127.0.0.1:8092/vereinsverwaltung/'
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = 0


def request(params, data=None):
    query = urllib.parse.urlencode(params)
    payload = urllib.parse.urlencode(data).encode() if data else None
    try:
        with opener.open(BASE + '?' + query, payload, timeout=60) as response:
            return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()


def check(value, label):
    global checks
    assert value, label
    checks += 1
    print('PASS', label, flush=True)


base = dict(view='finance', tab='reports', from_='2026-01-01', to='2026-12-31')
base['from'] = base.pop('from_')
for mode in ('table', 'chart'):
    for resolution in ('week', 'month', 'year'):
        status, _, raw = request(dict(base, report_display=mode, resolution=resolution, compare='1'))
        body = raw.decode()
        check(status == 200 and 'Vereinsergebnis' in body and 'Mit Vorjahr vergleichen' in body
              and 'Fatal error' not in body, f'{mode}/{resolution} report renders with comparison')
status, _, raw = request(dict(base, report_type='journal'))
check(status == 200 and 'Buchungsjournal' in raw.decode(), 'journal renders')
_, _, bank_html = request({'view': 'finance', 'tab': 'bank'})
account = re.search(r'account=([a-f0-9]{32})', bank_html.decode()).group(1)
status, _, raw = request(dict(base, account=account))
body = raw.decode()
check(status == 200 and f'name="account" value="{account}"' in body, 'account restriction survives report form')
check('Konto:' in body and re.search(r'href="[^"]*account=' + account + r'[^"]*download=finance-report-xlsx', body),
      'account restriction is visible and retained in export link')
status, _, raw = request(dict(base, **{'tags[0]': 'not-existing'}))
check('ohne Tagfilter' in raw.decode() and 'report-resultbar' not in raw.decode(), 'unsupported tag filter is never silently ignored')
for report_type in ('income', 'journal'):
    status, headers, data = request(dict(base, report_type=report_type, compare='1', download='finance-report-xlsx'))
    check(status == 200 and 'spreadsheetml.sheet' in headers.get('Content-Type', ''), f'{report_type} XLSX content type')
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        check(archive.testzip() is None and 'xl/workbook.xml' in archive.namelist(), f'{report_type} real XLSX container')
        sheet = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
        check(len(list(sheet.iter('{http://schemas.openxmlformats.org/spreadsheetml/2006/main}row'))) >= 1,
              f'{report_type} worksheet contains cells')
status, _, _ = request(dict(base, to='2025-01-01', download='finance-report-xlsx'))
check(status == 400, 'invalid export dates rejected')
status, _, _ = request(dict(base, report_type='unknown', download='finance-report-xlsx'))
check(status == 400, 'invalid report type rejected')
status, _, _ = request(dict(base, tags='unavailable', download='finance-report-xlsx'))
check(status == 400, 'scalar tag filter rejected rather than silently ignored')
bad_date = dict(base, download='finance-report-xlsx')
bad_date.pop('from')
bad_date['from[]'] = '2026-01-01'
status, _, _ = request(bad_date)
check(status == 400, 'array date filter rejected rather than silently defaulted')
_, _, raw = request({'view': 'dashboard'})
csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', raw.decode()).group(1)
request({'view': 'dashboard'}, {'csrf': csrf, 'action': 'persona', 'actor': 'demo-member'})
for params in (base, dict(base, download='finance-report-xlsx'), {'download': 'finance-report-xlsx'}):
    status, _, raw = request(params)
    check(status == 403 and not raw.startswith(b'PK'), 'member cannot read or download reports')
print(f'{checks} report HTTP checks passed; no financial data changed or mail sent')
