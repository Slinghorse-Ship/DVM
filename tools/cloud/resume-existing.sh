#!/bin/bash
# Resume only prepared local fixtures. No seeding, key generation or schema work.
set -euo pipefail
umask 077
task_repo=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
[[ "$task_repo" == /workspace/Kulturhus ]] || { echo 'Vorbereiteter Cloud-Checkout fehlt.' >&2; exit 1; }
task_runtime=/workspace/.setup
for task_file in "$task_runtime/bin/php" "$task_runtime/php-conf/php.ini" "$task_runtime/integrated-manager-router.php" "$task_repo/.local/native-cms-integration/integrated-manager.json" "$task_repo/.local/native-cms-integration/private.key"; do
  [[ -f "$task_file" && ! -L "$task_file" ]] || { echo 'Vorbereitete eigene Laufzeit unvollständig; keine Bestände neu anlegen.' >&2; exit 1; }
done
export PATH="$task_runtime/bin:$PATH"
export PHPRC="$task_runtime/php-conf/php.ini"
python3 - <<'PYUPLOAD'
from pathlib import Path
import re
path=Path('/workspace/.setup/php-conf/php.ini')
text=path.read_text()
for name,value in [('upload_max_filesize','256M'),('post_max_size','520M'),('max_file_uploads','50'),('max_input_vars','8192')]:
 match=re.search(r'^\s*'+name+r'\s*=\s*([^;\r\n]+)',text,re.M|re.I)
 if not match:text+='\n'+name+'='+value+'\n'
 elif name=='max_input_vars' and re.fullmatch(r'[+-]?[0-9]+',match[1].strip()):
  # PHP rejects negative values and zero does not mean unlimited fields.
  if int(match[1].strip())<int(value):text=text[:match.start(1)]+match[1].replace(match[1].strip(),value,1)+text[match.end(1):]
 elif name!='max_file_uploads':
  def amount(raw):
   found=re.fullmatch(r'([0-9]+)\s*([KMG]?)',raw.strip(),re.I)
   return int(found[1])*({'':1,'K':1024,'M':1024**2,'G':1024**3}[found[2].upper()]) if found else -1
  current=amount(match[1]);wanted=amount(value)
  if 0<current<wanted:text=text[:match.start(1)]+value+text[match.end(1):]
if path.read_text()!=text:path.write_text(text)
PYUPLOAD

export LD_LIBRARY_PATH="$task_runtime/runtime/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
export VEREIN_ERIC_DISABLE_TRANSPORT=1
export VEREIN_PYTHON="$task_runtime/invoice-runtime/python/run"
export VEREIN_OCR_RUNTIME="$task_runtime/invoice-private/runtime-config/ocr.json"
export VEREIN_KOSIT_RUNTIME="$task_runtime/invoice-private/runtime-config/kosit.json"
export VEREIN_HYBRID_RUNTIME="$task_runtime/invoice-private/runtime-config/hybrid.json"
php -r '$c=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if(($c["owner"]??"")!=="cloud-onboarding-synthetic"||($c["label"]??"")!=="integrated-manager"||($c["host"]??"")!=="127.0.0.1"||($c["port"]??0)!==13371||!preg_match("/^kh_native_[a-f0-9]{24}$/D",$c["database"]??""))throw new RuntimeException("Vorhandene eigene lokale Konfiguration erforderlich.");' "$task_repo/.local/native-cms-integration/integrated-manager.json"
DVM_LOCAL_TEST_DATABASES=1 python3 "$task_repo/tools/cloud/start-test-databases.py" --native
php -r 'new PDO("mysql:host=127.0.0.1;port=13371","root","synthetic-ci-only");'
cd -- "$task_repo"
if ! curl --fail --silent --connect-timeout 2 --max-time 5 http://127.0.0.1:8111/login/ > /dev/null; then
  [[ -z "$(ss -H -ltn '( sport = :8111 )')" ]] || { echo 'Port 8111 belegt; bestehender Prozess bleibt erhalten.' >&2; exit 1; }
  KULTURHUS_DISABLE_MAIL=1 KULTURHUS_DISABLE_WEBSITE=1 nohup php -S 127.0.0.1:8111 -t vereinsverwaltung/public "$task_runtime/integrated-manager-router.php" > "$task_runtime/integrated-resumed.log" 2>&1 < /dev/null &
fi
for task_attempt in {1..40}; do
  if curl --fail --silent --connect-timeout 2 --max-time 5 http://127.0.0.1:8111/login/ > /dev/null; then break; fi
  sleep .2
done
curl --fail --silent --connect-timeout 2 --max-time 5 http://127.0.0.1:8111/login/ | python3 -c 'import sys; s=sys.stdin.read(); assert "Anmelden und Vereinsmanager öffnen" in s and "Fatal error" not in s'
python3 tools/cloud/retire-legacy-preview.py
echo 'Gemeinsamen Manager wieder aufgenommen; keine Befüllung, Schemaänderung oder neuen Schlüssel.'
