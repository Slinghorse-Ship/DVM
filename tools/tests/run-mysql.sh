#!/bin/bash
# Existing finance/member/document tests, on their own synthetic MySQL service.
set -euo pipefail
task_repo=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
cd -- "$task_repo"
if [[ $# == 0 ]]; then echo 'Aufruf: bash tools/tests/run-mysql.sh balance-ledger.php ...' >&2; exit 2; fi
for task_test in "$@"; do
  [[ "$task_test" =~ ^[a-z0-9-]+\.php$ && -f "vereinsverwaltung/tests/$task_test" ]] || { echo 'Ungültiger Testname.' >&2; exit 2; }
done
export PATH=/workspace/.setup/bin:$PATH
DVM_LOCAL_TEST_DATABASES=1 python3 tools/cloud/start-test-databases.py --calendar
php -r 'new PDO("mysql:host=127.0.0.1;port=13372", "root", "isolated-regression-only");'
export VEREIN_ERIC_DISABLE_TRANSPORT=1
export VEREIN_TEST_MYSQL=isolated-regression-only VEREIN_TEST_MYSQL_HOST=127.0.0.1 VEREIN_TEST_MYSQL_PORT=13372
export VEREIN_MODE=synthetic KULTURHUS_DISABLE_MAIL=1 KULTURHUS_DISABLE_WEBSITE=1
export VEREIN_TEST_TIMEOUT_SECONDS=${VEREIN_TEST_TIMEOUT_SECONDS:-900}
if [[ -x /workspace/.setup/invoice-runtime/python/run ]]; then
  export VEREIN_PYTHON=/workspace/.setup/invoice-runtime/python/run
fi
for task_kind in OCR KOSIT HYBRID; do
  task_config=/workspace/.setup/invoice-private/runtime-config/${task_kind,,}.json
  if [[ -f "$task_config" ]]; then export "VEREIN_${task_kind}_RUNTIME=$task_config";
  else unset "VEREIN_${task_kind}_RUNTIME"; fi
done
export VEREIN_TEST_RESULTS="$task_repo/.local/test-results"
mkdir -p "$VEREIN_TEST_RESULTS"
unset VEREIN_DATABASE SMTP_DSN META_ACCESS_TOKEN EVENTBRITE_TOKEN
php vereinsverwaltung/tests/run-mysql-suite.php "$@"
