#!/usr/bin/env python3
"""Own disposable installation only; no provider transport or existing services."""
import argparse
import hashlib
import http.client
import json
import os
import re
import secrets
import subprocess
import time
import urllib.parse

parser = argparse.ArgumentParser()
parser.add_argument('--image', required=True)
args = parser.parse_args()
env = {k: v for k, v in os.environ.items() if k not in {'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'}}
prefix = 'dvm-check-' + secrets.token_hex(5)
label = 'dvm.test.owner=' + prefix
containers, volumes = [], []
network = None
checks = 0


def docker(*command, data=None, check=True, timeout=60):
    result = subprocess.run(['docker', '--host=unix:///var/run/docker.sock', *command], input=data, capture_output=True, text=True, env=env, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError('Owned Docker operation failed: ' + result.stderr[-1800:])
    return result.stdout.strip() + ('\n' + result.stderr.strip() if command[0] == 'logs' else '')


def require(value, message):
    global checks
    if not value:
        raise AssertionError(message)
    checks += 1
    print('PASS ' + message, flush=True)


def own_volume(suffix):
    name = prefix + '-' + suffix
    docker('volume', 'create', '--label', label, name)
    volumes.append(name)
    return name


def app(volume, suffix, trusted=None):
    command = ['run', '-d', '--name', prefix + '-' + suffix, '--label', label, '--network', network, '-p', '127.0.0.1::80', '-v', volume + ':/var/lib/dvm']
    if trusted:
        command += ['-e', 'DVM_TRUSTED_PROXIES=' + trusted]
    identity = docker(*command, args.image)
    containers.append(identity)
    # Internal Docker networks intentionally have no published ingress. Exercise
    # real Apache via its own loopback, with all provider egress still isolated.
    port = identity
    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        try:
            request(port, '/setup/', secure=False)
            return identity, port
        except (OSError, http.client.HTTPException, RuntimeError):
            time.sleep(.2)
    raise RuntimeError('Own Apache not ready: ' + docker('logs', identity))


def request(port, path, fields=None, secure=True, cookie=None):
    headers = {'Host': 'dvm.example.invalid'}
    if secure:
        headers['X-Forwarded-Proto'] = 'https'
    if cookie:
        headers['Cookie'] = cookie
    body = urllib.parse.urlencode(fields) if fields is not None else None
    if body is not None:
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
        headers['Origin'] = 'https://dvm.example.invalid'
    script = 'import sys,json,http.client;d=json.load(sys.stdin);c=http.client.HTTPConnection("127.0.0.1",80,timeout=45);c.request(d["method"],d["path"],d["body"],d["headers"]);r=c.getresponse();print(json.dumps([r.status,dict(r.getheaders()),r.read().decode("utf-8",errors="replace")]));c.close()'
    raw = docker('exec', '-i', port, 'python3', '-c', script, data=json.dumps({'method': 'POST' if fields is not None else 'GET', 'path': path, 'body': body, 'headers': headers}))
    return json.loads(raw)


def php(identity, source):
    return docker('exec', '--user', 'www-data', '-i', identity, 'php', '-r', source)


try:
    require(bool(docker('image', 'inspect', args.image)), 'reviewed local image exists')
    network = docker('network', 'create', '--internal', '--label', label, prefix + '-net')
    gateway = json.loads(docker('network', 'inspect', network))[0]['IPAM']['Config'][0]['Gateway']
    primary = own_volume('app')
    first, port = app(primary, 'default')
    require(php(first, 'echo !is_dir("/opt/dvm/seed/private")&&!is_file("/opt/dvm/seed/SETUP-KEY.txt")?"clean":"unsafe";') == 'clean', 'image seed contains no installation-private directory or packaged setup key')
    require(request(port, '/setup/', secure=False)[0] == 403 and request(port, '/setup/')[0] == 403, 'HTTP and forged proxy HTTPS are refused by default')
    token = docker('exec', first, 'dvm', 'setup-key')
    require(bool(re.fullmatch('[a-f0-9]{64}', token)), 'new installation receives its own strong setup key')
    require(token not in docker('logs', first), 'setup key is not printed in container logs')
    docker('rm', '-f', first)
    containers.remove(first)
    other, unused_port = app(own_volume('other'), 'other')
    require(docker('exec', other, 'dvm', 'setup-key') != token, 'same image gives a second installation a different setup key')
    docker('rm', '-f', other)
    containers.remove(other)
    current, port = app(primary, 'trusted', '127.0.0.1/32')
    require(docker('exec', current, 'dvm', 'setup-key') == token, 'recreated container retains its original setup key')
    require(php(current, 'echo extension_loaded("imap")&&extension_loaded("pdo_mysql")&&extension_loaded("intl")?"ready":"missing";') == 'ready', 'IMAP, MySQL and internationalization extensions are present')
    require(docker('exec', current, '/usr/local/bin/node', '--version').startswith('v22.'), 'photo runtime uses Node 22')
    require(docker('exec', current, '/opt/dvm/python/bin/python3', '-c', 'import reportlab,PIL,pypdf,pathlib;assert reportlab.Version=="4.4.9";assert all((pathlib.Path(reportlab.__file__).parent/"fonts"/n).is_file()for n in ["Vera.ttf","VeraBd.ttf","VeraIt.ttf","VeraBI.ttf"]);print("ready")') == 'ready', 'normal PDF runtime includes exact ReportLab 4.4.9 with required fonts, Pillow and pypdf')
    require(docker('exec', current, '/opt/dvm/python/bin/python3', '-c', 'import io;from reportlab.pdfgen.canvas import Canvas;from pypdf import PdfReader;b=io.BytesIO();c=Canvas(b);c.drawString(40,40,"Synthetic Docker PDF");c.save();print(len(PdfReader(io.BytesIO(b.getvalue())).pages))') == '1', 'PDF runtime renders and independently reads a real one-page synthetic document')
    hse_render = 'require "/var/lib/dvm/app/vereinsverwaltung/src/autoload.php";$d=["document_workflow"=>["document"=>["id"=>str_repeat("a",32),"title"=>"Synthetische Docker-Gefährdungsbeurteilung","version"=>1,"status"=>"draft","updated_at"=>"2026-11-05T10:00:00Z","created_by"=>"docker-test","fields"=>[["key"=>"risks","label"=>"Risiken","type"=>"risk_register"]],"values"=>[]],"activities"=>[],"people"=>["docker-test"=>"Synthetischer Prüfer"],"profiles"=>[],"attachments"=>[]]];$p=Kulturhus\\Verein\\DocumentPreview::pdf($d);echo strlen($p)>1000&&str_starts_with($p,"%PDF-")?"rendered":"bad";'
    require(php(current, hse_render) == 'rendered', 'actual packaged HSE renderer produces its draft PDF through PHP with embedded Vera fonts and private temporary files')
    db_image = 'mysql:8.4@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242'
    db_password = secrets.token_hex(24)
    db = docker('run', '-d', '--name', prefix + '-db', '--label', label, '--network', network, '--network-alias', 'db', '-v', own_volume('mysql') + ':/var/lib/mysql', '-e', 'MYSQL_ROOT_PASSWORD=' + db_password, '-e', 'MYSQL_DATABASE=dvm', '-e', 'MYSQL_USER=dvm', '-e', 'MYSQL_PASSWORD=' + db_password, db_image, '--log-bin-trust-function-creators=1')
    containers.append(db)
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        if docker('exec', '-e', 'MYSQL_PWD=' + db_password, db, 'mysqladmin', 'ping', '-h', '127.0.0.1', '--silent', check=False) == 'mysqld is alive':
            break
        time.sleep(.3)
    else:
        raise RuntimeError('Own synthetic MySQL not ready')
    status, headers, html = request(port, '/setup/')
    require(status == 200 and 'DVM einrichten' in html, 'exact trusted proxy enables the real HTTPS-gated setup page')
    csrf = re.search('name="csrf" value="([a-f0-9]+)"', html)[1]
    cookie = headers['Set-Cookie'].split(';', 1)[0]
    require('secure' in headers['Set-Cookie'].lower() and 'httponly' in headers['Set-Cookie'].lower(), 'setup uses secure HttpOnly session cookies')
    fields = {'csrf': csrf, 'token': token, 'action': 'begin', 'club_id': 'docker-synthetic', 'club_name': 'Synthetischer Dockerverein', 'db_host': 'db', 'db_name': 'dvm', 'db_user': 'dvm', 'db_password': db_password, 'admin_email': 'synthetic@example.invalid', 'admin_password': secrets.token_hex(20), 'scheduler_php': '/usr/local/bin/php'}
    status, _, body = request(port, '/setup/', fields, cookie=cookie)
    state = json.loads(body)
    require(status == 200 and state.get('phase') == 'verify', 'actual setup accepts only the private key and own empty MySQL database')
    for step in range(100):
        status, _, body = request(port, '/setup/', {'csrf': csrf, 'token': token, 'action': 'step'}, cookie=cookie)
        state = json.loads(body)
        if status != 200 or 'error' in state:
            raise RuntimeError('Own installer failed after step ' + str(step) + ': ' + str(state.get('error', status)) + '\n' + docker('logs', current)[-1400:])
        if state.get('phase') == 'installed':
            break
    else:
        raise RuntimeError('Bounded setup did not finish')
    require(state['phase'] == 'installed', 'real installer completes all schema and native account steps without a custom seed')
    require(request(port, '/setup/')[0] == 410, 'completed setup remains locked')
    require(request(port, '/login/')[0] == 200, 'installed HTTPS login renders through the generated Apache front controller')
    for path in ['/private/environment.json', '/app/composer.json', '/SETUP-KEY.txt', '/../private/private.key']:
        status, _, body = request(port, path)
        require(status in (400, 403, 404) and token not in body and db_password not in body, 'private installation file cannot be fetched: ' + path)
    original_files = '["installation.json","private/environment.json","private/runtime.php","private/worker.php","private/auth.key","private/private.key","private/member-bank-keys/v1.key","private/app-files.json"]'
    hash_originals = '$r="/var/lib/dvm/";$out=[];foreach(' + original_files + 'as$f)$out[$f]=hash_file("sha256",$r.$f);echo json_encode($out);'
    originals = php(current, hash_originals)
    require(len(json.loads(originals)) == 8, 'eight complete configuration, inventory and key originals are recorded for restart verification')
    media_name = '/var/lib/dvm/private/media/docker-isolation-test.txt'
    docker('exec', '--user', 'www-data', current, 'php', '-r', 'umask(0077);file_put_contents($argv[1],"synthetic persistent media");', media_name)
    docker('restart', current)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            if request(port, '/login/')[0] == 200:
                break
        except (OSError, http.client.HTTPException, RuntimeError):
            pass
        time.sleep(.2)
    later = php(current, hash_originals)
    require(later == originals, 'restart preserves exact original installation, database configuration and private keys')
    require(docker('exec', current, 'cat', media_name) == 'synthetic persistent media', 'restart preserves actual original media in the installation volume')
    require(not php(current, 'echo is_file("/var/lib/dvm/SETUP-KEY.txt")?"exists":"";'), 'completed cleartext setup key is removed without generating a replacement')
    require(json.loads(docker('exec', current, 'dvm', 'status'))['installed'], 'container status distinguishes installed application from its image seed')
    print(json.dumps({'checks': checks, 'status': 'passed', 'external_actions': 0, 'existing_services_changed': 0}), flush=True)
finally:
    # Failed docker run may leave a created container before returning its ID.
    # The unguessable label belongs exclusively to this invocation.
    for identity in docker('ps', '-aq', '--filter', 'label=' + label, check=False).splitlines():
        docker('rm', '-f', identity, check=False)
    for identity in reversed(containers):
        docker('rm', '-f', identity, check=False)
    for name in reversed(volumes):
        docker('volume', 'rm', name, check=False)
    if network:
        docker('network', 'rm', network, check=False)
