"""Run PHP regression suites with explicit skipped/failed evidence; never deploy or send mail."""
import argparse
import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[2]
NATIVE = {'gallery-order.php', 'meta-event-archive.php', 'processwire-accounts-local.php',
          'portal-security.php', 'password-reset-processwire.php', 'recovery-backup.php'}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--python', default=sys.executable)
    parser.add_argument('--report', default=str(ROOT / '.local/regression-check'))
    parser.add_argument('--native', action='store_true', help='Requires the isolated local ProcessWire container')
    args = parser.parse_args()
    if args.native and (os.getenv('KULTURHUS_LOCAL_DEMO') != '1' or not Path('/var/www/html/index.php').is_file()):
        parser.error('--native requires the explicitly isolated ProcessWire runtime')
    report = Path(args.report).resolve()
    report.mkdir(parents=True, exist_ok=True)
    env = os.environ.copy()
    env.update(KULTURHUS_DISABLE_MAIL='1', SMTP_DSN='', PRESS_SMTP_DSN='',
               VEREIN_PYTHON=str(Path(args.python).resolve()))
    env.pop('VEREIN_REGISTRATION_AUDIT_BUNDLE', None)
    openssl_config = Path(args.php).resolve().parent / 'extras/ssl/openssl.cnf'
    if os.name == 'nt' and not env.get('OPENSSL_CONF') and openssl_config.is_file():
        env['OPENSSL_CONF'] = str(openssl_config)
    def run(path):
        if path.name in NATIVE and not args.native:
            return {'suite': path.name, 'state': 'skipped', 'reason': 'isolated ProcessWire runtime required'}
        started = time.monotonic()
        try:
            result = subprocess.run([args.php, str(path)], cwd=ROOT, env=env,
                                    capture_output=True, timeout=120)
            output = result.stdout + result.stderr
            (report / (path.stem + '.log')).write_bytes(output)
            lines = output.decode('utf-8', 'replace').splitlines()
            skipped = any(line.startswith('SKIP ') for line in lines)
            return {'suite': path.name, 'state': 'skipped' if skipped else ('passed' if result.returncode == 0 else 'failed'),
                    'exit': result.returncode, 'seconds': round(time.monotonic()-started, 2),
                    'pass_lines': sum(line.startswith('PASS ') for line in lines)}
        except subprocess.TimeoutExpired:
            return {'suite': path.name, 'state': 'failed', 'reason': 'timeout'}
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(run, sorted((ROOT / 'vereinsverwaltung/tests').glob('*.php'))))
    summary = {state: sum(r['state'] == state for r in results) for state in ['passed', 'failed', 'skipped']}
    (report/'results.json').write_text(json.dumps({'summary': summary, 'suites': results}, indent=2), encoding='utf-8')
    print(json.dumps({'summary': summary, 'unpassed': [r for r in results if r['state'] != 'passed']}, indent=2))
    return 1 if summary['failed'] else (2 if summary['skipped'] else 0)

if __name__ == '__main__':
    raise SystemExit(main())
