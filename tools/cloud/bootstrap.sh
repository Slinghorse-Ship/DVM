#!/bin/bash
# Repository-owned entry point; no published cloud snapshot is required.
set -euo pipefail
umask 077
task_repo=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
task_mode=${1:---setup}
if [[ $# -gt 1 || ! "$task_mode" =~ ^--(setup|start|verify|check|help)$ ]]; then
  echo 'Aufruf: bash tools/cloud/bootstrap.sh --setup|--start|--verify|--check' >&2
  exit 2
fi
if [[ "$task_mode" == --help ]]; then
  echo '--setup: Werkzeuge aus Git einrichten, Datenbank und gemeinsamen Manager starten; vorhandene eigene Fixtures erhalten.'
  echo '--start: vorhandenen Manager ohne Befüllung, Schemaarbeit oder neue Schlüssel starten.'
  echo '--verify: Inventar, Offline-Pakete sowie alle nativen Kern-, Kalender- und Browserprüfungen mit eigenen frischen Beständen ausführen.'
  echo '--check: Voraussetzungen, eingerichtete Laufzeit und gemeinsamen lokalen Manager prüfen.'
  exit 0
fi
if [[ "$task_repo" != /workspace/Kulturhus && "$task_mode" != --verify ]]; then
  echo 'Diese geprüfte Codex-Linux-Einrichtung benötigt den Checkout unter /workspace/Kulturhus.' >&2
  exit 1
fi
cd -- "$task_repo"
for task_command in python3 docker curl node npm /usr/bin/git; do
  command -v "$task_command" >/dev/null || { echo "Basiswerkzeug fehlt: $task_command" >&2; exit 1; }
done
unset DOCKER_CONTEXT DOCKER_TLS DOCKER_TLS_VERIFY DOCKER_CERT_PATH
export DOCKER_HOST=unix:///var/run/docker.sock
docker info >/dev/null 2>&1 || { echo 'Die Cloud-Basis muss eine erreichbare Docker-Laufzeit bereitstellen.' >&2; exit 1; }
test -x /usr/bin/chromium || { echo 'Die geprüfte Browserlaufzeit benötigt /usr/bin/chromium in der Cloud-Basis.' >&2; exit 1; }
# Verification owns fresh databases and does not need or alter the preview state.
if [[ "$task_mode" == --verify ]]; then
  exec bash "$task_repo/tools/ci/local-native.sh" --all
fi
if [[ "$task_mode" == --setup ]]; then
  command -v apt-get >/dev/null
  command -v dpkg-deb >/dev/null
  test -f /usr/share/keyrings/debian-archive-keyring.gpg
  bash "$task_repo/tools/cloud/install.sh"
  python3 - <<'PY'
from pathlib import Path
import hashlib,json,subprocess
root=Path('/workspace/Kulturhus')
state={'format':1,'installer_sha256':hashlib.sha256((root/'tools/cloud/install.sh').read_bytes()).hexdigest(),
       'setup_commit':subprocess.check_output(['/usr/bin/git','rev-parse','HEAD'],cwd=root,text=True).strip()}
Path('/workspace/.setup/completed.json').write_text(json.dumps(state)+'\n')
PY
fi
if [[ "$task_mode" == --setup ]]; then
  python3 - <<'PY'
from pathlib import Path
import hashlib,json
root=Path('/workspace/Kulturhus');marker=Path('/workspace/.setup/completed.json')
if not marker.is_file(): raise SystemExit('Einrichtung fehlt: bash tools/cloud/bootstrap.sh --setup ausführen.')
state=json.loads(marker.read_text())
if state.get('installer_sha256')!=hashlib.sha256((root/'tools/cloud/install.sh').read_bytes()).hexdigest():
 raise SystemExit('Einrichtungsanweisungen geändert: --setup erneut ausführen; eigene Fixtures bleiben erhalten.')
PY
fi
export PATH=/workspace/.setup/bin:$PATH
export PHPRC=/workspace/.setup/php-conf/php.ini
export VEREIN_PYTHON=/workspace/.setup/invoice-runtime/python/run
export LD_LIBRARY_PATH=/workspace/.setup/runtime/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}
if ! php -r 'require "vendor/autoload.php"; exit(class_exists(\Brick\Math\BigRational::class) ? 0 : 1);'; then
  echo 'PHP-Projektabhängigkeiten fehlen oder sind veraltet; --setup ausführen. Vorhandene eigene Fixtures bleiben erhalten.' >&2
  exit 1
fi
if [[ "$task_mode" == --start ]]; then
  bash "$task_repo/tools/cloud/resume-existing.sh"
fi
test -x /workspace/.setup/bin/php || { echo 'PHP-Einrichtung fehlt; --setup ausführen.' >&2; exit 1; }
php -r 'foreach (["pdo_mysql","gd","mbstring","sodium","zip","dom"] as $e) if (!extension_loaded($e)) {fwrite(STDERR,"PHP-Erweiterung fehlt: ".$e."\n");exit(1);} new PDO("mysql:host=127.0.0.1;port=13371","root","synthetic-ci-only"); echo "PHP und isolierte MySQL-Entwicklungslaufzeit bereit.\n";'
curl --fail --silent --connect-timeout 2 --max-time 5 http://127.0.0.1:8111/login/ | python3 -c 'import sys; s=sys.stdin.read(); assert "Anmelden und Vereinsmanager öffnen" in s and "Fatal error" not in s'
echo 'Bereit: Manager http://127.0.0.1:8111/login/'
echo 'Private synthetische Anmeldeangaben: .local/native-cms-integration/integrated-manager-runtime/browser-credentials.json'
