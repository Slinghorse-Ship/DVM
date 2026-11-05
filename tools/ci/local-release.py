#!/usr/bin/env python3
"""Sequential, resumable local software acceptance; no GitHub API or CLI."""
import argparse
import contextlib
import dataclasses
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import platform
import re
import signal
import shutil
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
PHASES = ('preflight', 'core', 'components', 'calendar', 'browsers', 'package', 'installation')
PLATFORM_BROWSER_FLAGS = {'DVM_ANDROID_APP_BROWSER'}


@dataclasses.dataclass(frozen=True)
class Step:
    id: str
    phase: str
    command: tuple
    environment: dict = dataclasses.field(default_factory=dict)
    native_suite: str | None = None


class Interrupted(KeyboardInterrupt):
    def __init__(self, signum):
        self.signum = signum


@contextlib.contextmanager
def controlled_signals():
    # Background shells may inherit SIGINT=ignored. Explicit handling is needed
    # for an unattended acceptance too; restore the caller's handlers afterwards.
    previous = {signum: signal.getsignal(signum) for signum in (signal.SIGINT, signal.SIGTERM)}
    def stop(signum, frame):
        raise Interrupted(signum)
    try:
        for signum in previous:
            signal.signal(signum, stop)
        yield
    finally:
        for signum, handler in previous.items():
            signal.signal(signum, handler)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()


def registration(root):
    spec = importlib.util.spec_from_file_location('native_registration', root / 'tools/ci/run-github-core.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def verify_browser_variants(sources, registered):
    declared = set()
    for source in sources:
        declared.update(re.findall(r'''getenv\(\s*['"](DVM_[A-Z0-9_]*BROWSER)['"]\s*\)''', source))
    missing = declared - set(registered) - PLATFORM_BROWSER_FLAGS
    if missing:
        raise ValueError('Unregistered browser variants: ' + ', '.join(sorted(missing)))


def plan(root=ROOT):
    source = (root / 'tools/ci/local-native.sh').read_text()
    registered = registration(root)
    suites = registered.suites((root / 'vereinsverwaltung/tests/ci-native.php').read_text())
    loop = re.search(r'for task_case in \\\n(.*?); do', source, re.S)
    if not loop:
        raise ValueError('The complete browser registration is required')
    cases = re.findall(r'\b(DVM_[A-Z0-9_]*BROWSER):([a-z0-9-]+)', loop[1])
    if not cases or len(cases) != len(set(cases)):
        raise ValueError('Browser registrations are empty or duplicated')
    declared = {suite for _, suite in cases} | {'native-webspace-installer'}
    verify_browser_variants((p.read_text() for p in (root / 'vereinsverwaltung/tests').glob('native-*.php')),
                            {flag for flag, _ in cases} | {'DVM_INSTALLER_BROWSER'})
    for path in (root / 'vereinsverwaltung/tests').glob('native-*.php'):
        if re.search(r"getenv\('(DVM_[A-Z0-9_]*BROWSER)'\)\s*===\s*'1'", path.read_text()) and path.stem not in declared:
            raise ValueError('Unregistered browser suite: ' + path.stem)
    steps = [Step('preflight:browser-registration', 'preflight', ('python3', 'tools/ci/test-browser-registration.py')),
             Step('preflight:selection', 'preflight', ('python3', 'tools/ci/test-github-core.py')),
             Step('preflight:local-release', 'preflight', ('python3', 'tools/ci/test-local-release.py')),
             Step('preflight:runtime', 'preflight', ('bash', 'tools/ci/local-native.sh', '--preflight'))]
    for suite in suites:
        phase = 'installation' if suite in registered.OPERATIONAL else 'core'
        steps.append(Step(phase + ':' + suite, phase,
                          ('bash', 'tools/ci/local-native.sh', '--core', suite), native_suite=suite))
    for phase in ('components', 'calendar', 'package'):
        steps.append(Step(phase, phase, ('bash', 'tools/ci/local-native.sh', '--' + phase)))
    for flag, suite in cases:
        if suite not in suites:
            raise ValueError('Browser suite absent from core registrations: ' + suite)
        environment = {flag: '1'}
        if flag == 'DVM_RASTER_PHOTOS_BROWSER':
            environment['DVM_RASTER_WEBSPACE_HTTP'] = '1'
        phase = 'installation' if suite in registered.OPERATIONAL else 'browsers'
        steps.append(Step(phase + ':' + flag + ':' + suite, phase,
                          ('bash', 'tools/ci/local-native.sh', '--core', suite), environment, suite))
    steps.append(Step('installation:browser:installer', 'installation',
                      ('bash', 'tools/ci/local-native.sh', '--core', 'native-webspace-installer'),
                      {'DVM_INSTALLER_BROWSER': '1'}, 'native-webspace-installer'))
    if len({s.id for s in steps}) != len(steps):
        raise ValueError('Acceptance step identifiers must be unique')
    return sorted(steps, key=lambda step: PHASES.index(step.phase))


def full_environment(root, step, temporary, system_temporary=None):
    environment = os.environ.copy()
    source = (root / 'tools/ci/local-native.sh').read_text()
    for line in re.findall(r'^  unset (.+)$', source, re.M):
        for name in line.split():
            environment.pop(name, None)
    for name in list(environment):
        if (re.fullmatch(r'DVM_[A-Z0-9_]*BROWSER', name)
                or re.fullmatch(r'DVM_.*(?:_CASE|_SEGMENT|_SMOKE|_DIAGNOSTIC|_FAST|_ONLY|_ONLY_\w+|_SKIP_\w+)', name)):
            environment.pop(name)
    for name in ('GITHUB_ACTIONS', 'VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP', 'SMTP_DSN',
                 'META_ACCESS_TOKEN', 'EVENTBRITE_TOKEN'):
        environment.pop(name, None)
    environment.update({'KULTURHUS_DISABLE_MAIL': '1', 'KULTURHUS_DISABLE_WEBSITE': '1',
                        'VEREIN_ERIC_DISABLE_TRANSPORT': '1', 'VEREIN_MODE': 'synthetic',
                        'TMPDIR': str(system_temporary or temporary),
                        'DVM_LOCAL_ACCEPTANCE_TMP': str(temporary),
                        'DVM_BROWSER_ARTIFACT_DIR': str(temporary / 'browser-artifacts')})
    environment.update(step.environment)
    if step.environment.get('DVM_INSTALLER_BROWSER') == '1':
        runtime = Path(environment.get('DVM_LOCAL_RUNTIME', '/workspace/.setup'))
        environment['DVM_PLAYWRIGHT_MODULE'] = environment.get('PLAYWRIGHT_MODULE', str(runtime / 'browser/node_modules/playwright/index.mjs'))
        environment['DVM_CHROMIUM'] = environment.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE', '/usr/bin/chromium')
    return environment


def source_identity(root):
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
    if subprocess.check_output(['git', 'status', '--porcelain', '--untracked-files=normal'], cwd=root, text=True).strip():
        raise ValueError('Commit the reviewed source first; acceptance requires an unchanged checkout')
    tree = subprocess.check_output(['git', 'rev-parse', 'HEAD^{tree}'], cwd=root, text=True).strip()
    return {'commit': head, 'tree': tree}


def native_completed(log, suite):
    records = []
    for line in log.read_text(errors='replace').splitlines():
        try:
            value = json.loads(line)
            if isinstance(value, dict):
                records.append(value)
        except ValueError:
            pass
    completed = [r for r in records if r.get('completed_suite') == suite]
    totals = [r for r in records if 'suites' in r and 'failed' in r]
    return bool(completed and totals and completed[-1].get('exit_code') == 0
                and completed[-1].get('cleanup_complete') is True
                and totals[-1].get('suites') == 1 and totals[-1].get('failed') == []
                and totals[-1].get('live_transport') is False)


def save(path, value):
    temporary = path.with_suffix('.tmp')
    with temporary.open('w') as stream:
        os.chmod(temporary, 0o600)
        json.dump(value, stream, indent=2)
        stream.write('\n')
    temporary.replace(path)


def reusable(record, output, step):
    if record.get('status') != 'passed' or record.get('exit_code') != 0:
        return False
    path = output / record.get('log', '')
    if path.parent != output or not path.is_file() or digest(path.read_bytes()) != record.get('log_sha256'):
        return False
    return step.native_suite is None or native_completed(path, step.native_suite)


def process_identity(pid):
    try:
        status = Path('/proc') / str(pid) / 'stat'
        if status.is_file():
            fields = status.read_text().rsplit(')', 1)[1].split()
            if fields[0] == 'Z':
                return None
            started = fields[19]
        else:
            started = subprocess.check_output(['ps', '-p', str(pid), '-o', 'lstart='], text=True,
                                              stderr=subprocess.DEVNULL).strip()
            if not started:
                return None
        return {'pid': pid, 'started': started, 'host': platform.node()}
    except (OSError, subprocess.CalledProcessError):
        return None


def previous_process_running(record):
    identity = record.get('process')
    return bool(identity and identity.get('host') == platform.node()
                and identity == process_identity(identity['pid']))


def terminate_owned(process):
    if process.poll() is not None:
        return
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        process.wait()
        return
    try:
        process.wait(timeout=10)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait()


def execute(root, output, step, attempt, timeout, on_started=None):
    name = step.id.replace(':', '-') + '-attempt-' + str(attempt)
    log = output / (name + '.log')
    temporary = output / (name + '.tmpdir')
    temporary.mkdir(mode=0o700)
    (temporary / 'browser-artifacts').mkdir(mode=0o700)
    started = time.time()
    record = {'status': 'failed', 'attempt': attempt, 'log': log.name, 'started': started}
    # Chromium's SingletonSocket must fit the OS Unix-socket path limit even
    # when the selected evidence directory or registered step name is long.
    # Only scratch files use this short owned directory; evidence stays output.
    with tempfile.TemporaryDirectory(prefix='dvm-la-', dir='/tmp') as system_temporary, log.open('x') as stream:
        os.chmod(log, 0o600)
        process = subprocess.Popen(step.command, cwd=root,
                                   env=full_environment(root, step, temporary, Path(system_temporary)),
                                   stdin=subprocess.DEVNULL, stdout=stream, stderr=subprocess.STDOUT,
                                   start_new_session=True)
        try:
            record['process'] = process_identity(process.pid)
            if on_started:
                on_started(record['process'])
            record['exit_code'] = process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            terminate_owned(process)
            record.update(exit_code=124, reason='timeout')
        except KeyboardInterrupt as error:
            terminate_owned(process)
            record.update(exit_code=128 + getattr(error, 'signum', signal.SIGINT), reason='interrupted')
        except BaseException:
            terminate_owned(process)
            raise
    record.update(duration_seconds=round(time.time() - started, 3), log_sha256=digest(log.read_bytes()))
    if record['exit_code'] == 0:
        if step.native_suite and not native_completed(log, step.native_suite):
            record['reason'] = 'Actual suite completion and cleanup evidence is missing'
        else:
            record['status'] = 'passed'
    return record


def storage_blocker(root, output):
    # A low-space suite is not evidence of hundreds of unrelated feature faults.
    # Stop before the next child, retain its pending status, then resume normally.
    for kind, path, required in [('source', root, 128 * 1024 * 1024),
                                  ('evidence', output, 512 * 1024 * 1024)]:
        free = shutil.disk_usage(path).free
        if free < required:
            return {'reason': 'insufficient_disk_space', 'filesystem': kind,
                    'path': str(path), 'free_bytes': free, 'required_bytes': required}
    return None


def summary(state, steps):
    passed = [s.id for s in steps if state['results'].get(s.id, {}).get('status') == 'passed']
    failed = [s.id for s in steps if state['results'].get(s.id, {}).get('status') == 'failed']
    pending = [s.id for s in steps if s.id not in passed and s.id not in failed]
    return {'scope': 'local-software-tests', 'source': state['source'], 'phases': state['phases'],
            'all_phases_selected': state['phases'] == list(PHASES), 'expected_steps': len(steps),
            'passed': len(passed), 'failed': failed, 'pending': pending,
            'status': 'passed' if len(passed) == len(steps) else 'incomplete',
            'github_calls': 0, 'live_transport': False,
            'environment_blocked': state.get('environment_blocked'),
            'product_release_approval': 'requires separate qualified tax, device and provider acceptance'}


def run(root, output, steps, phases, resume=False, timeout=5400, keep_going=False):
    with controlled_signals():
        return run_controlled(root, output, steps, phases, resume, timeout, keep_going)


def run_controlled(root, output, steps, phases, resume=False, timeout=5400, keep_going=False):
    output = output.resolve()
    if output.is_relative_to(root.resolve()):
        raise ValueError('Keep acceptance evidence outside the source checkout')
    identity = source_identity(root)
    expected = {'schema': 1, 'source': identity, 'phases': phases,
                'plan_sha256': digest(canonical([dataclasses.asdict(s) for s in steps]))}
    output.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (output / 'run.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state_file = output / 'state.json'
        if resume:
            state = json.loads(state_file.read_text())
            if any(state.get(k) != v for k, v in expected.items()):
                raise ValueError('Resume requires the exact same source, phases and registered plan')
        else:
            if any(p.name != 'run.lock' for p in output.iterdir()):
                raise ValueError('Preserve previous evidence: use --resume or a new directory')
            state = {**expected, 'results': {}}
        # A previous green status is accepted only with its unmodified complete log.
        for step in steps:
            record = state['results'].get(step.id, {})
            if record.get('status') == 'running' and previous_process_running(record):
                raise ValueError('An earlier acceptance child is still running; wait for it before resuming')
            if record.get('status') == 'passed' and not reusable(record, output, step):
                record.update(status='failed', reason='Previous complete evidence is missing or changed')
        state.pop('environment_blocked', None)
        save(state_file, state)
        save(output / 'result.json', summary(state, steps))
        for number, step in enumerate(steps, 1):
            prior = state['results'].get(step.id, {})
            if reusable(prior, output, step):
                continue
            blocked = storage_blocker(root, output)
            if blocked:
                state['environment_blocked'] = {**blocked, 'next_step': step.id}
                save(state_file, state)
                save(output / 'result.json', summary(state, steps))
                print('BLOCKED: insufficient disk space; pending steps and evidence are retained. '
                      + json.dumps(blocked), flush=True)
                break
            if source_identity(root) != identity:
                raise ValueError('Source changed during acceptance; previous evidence is retained')
            attempt = int(prior.get('attempt', 0)) + 1
            # Save before launch so an interrupted parent never counts this step as passed.
            state['results'][step.id] = {'status': 'running', 'attempt': attempt}
            save(state_file, state)
            save(output / 'result.json', summary(state, steps))
            print(f'START [{number}/{len(steps)}] {step.id}', flush=True)
            def started(identity):
                state['results'][step.id]['process'] = identity
                save(state_file, state)
            result = execute(root, output, step, attempt, timeout, started)
            try:
                if source_identity(root) != identity:
                    raise ValueError('Source identity changed')
            except ValueError as error:
                result.update(status='failed', reason=str(error))
            state['results'][step.id] = result
            save(state_file, state)
            save(output / 'result.json', summary(state, steps))
            print(f'{result["status"].upper()} {step.id} ({result["duration_seconds"]}s)', flush=True)
            if result['status'] != 'passed' and (not keep_going or result.get('reason') == 'interrupted'):
                break
        result = summary(state, steps)
        save(output / 'result.json', result)
        print(json.dumps({'status': result['status'], 'source': result['source'],
                          'all_phases_selected': result['all_phases_selected'],
                          'passed': result['passed'], 'expected_steps': result['expected_steps'],
                          'failed_steps': len(result['failed']), 'pending_steps': len(result['pending']),
                          'environment_blocked': result.get('environment_blocked'),
                          'result_file': str(output / 'result.json')}), flush=True)
        return 0 if result['status'] == 'passed' else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--phase', choices=PHASES, action='append')
    parser.add_argument('--list', action='store_true')
    parser.add_argument('--artifacts', type=Path)
    parser.add_argument('--resume', action='store_true')
    parser.add_argument('--keep-going', action='store_true')
    parser.add_argument('--timeout', type=int, default=5400)
    args = parser.parse_args()
    phases = [phase for phase in PHASES if not args.phase or phase in args.phase]
    steps = [s for s in plan() if s.phase in phases]
    if args.list:
        print(json.dumps({'phases': phases, 'steps': [dataclasses.asdict(s) for s in steps],
                          'separate_platform_flags': sorted(PLATFORM_BROWSER_FLAGS)}, indent=2))
        return 0
    if args.artifacts is None or args.timeout < 1:
        parser.error('--artifacts outside the checkout and a positive timeout are required')
    try:
        return run(ROOT, args.artifacts, steps, phases, args.resume, args.timeout, args.keep_going)
    except KeyboardInterrupt:
        print('Local acceptance interrupted; existing evidence is retained.', file=sys.stderr)
        return 130
    except (ValueError, OSError) as error:
        print(str(error), file=sys.stderr)
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
