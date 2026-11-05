"""Acceptance must fail closed on missing suites, changed evidence and interruption."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('local_release', HERE / 'local-release.py')
release = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = release
spec.loader.exec_module(release)


def completed(suite):
    return ('print(' + repr(json.dumps({'completed_suite': suite, 'exit_code': 0, 'cleanup_complete': True})) + ');'
            'print(' + repr(json.dumps({'suites': 1, 'failed': [], 'live_transport': False})) + ')')


class LocalAcceptance(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='dvm-local-acceptance-', dir='/tmp')
        self.base = Path(self.temporary.name)
        self.root = self.base / 'source'
        (self.root / 'tools/ci').mkdir(parents=True)
        (self.root / 'tools/ci/local-native.sh').write_text((HERE / 'local-native.sh').read_text())
        (self.root / 'source.txt').write_text('owned original\n')
        self.git('init', '-q')
        self.commit()
        self.output = self.base / 'evidence'

    def tearDown(self):
        self.temporary.cleanup()

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, text=True, stderr=subprocess.DEVNULL)

    def commit(self):
        self.git('add', '.')
        self.git('-c', 'user.name=DVM isolated test', '-c', 'user.email=isolated@example.invalid',
                 'commit', '-qm', 'owned source fixture')

    def step(self, suite='owned', before=''):
        return release.Step('core:owned', 'core', (sys.executable, '-c', before + completed(suite)),
                            native_suite='owned')

    def run_owned(self, step=None, **kwargs):
        with contextlib.redirect_stdout(io.StringIO()):
            return release.run(self.root, self.output, [step or self.step()], ['core'], **kwargs)

    def test_all_registrations_and_installation_are_present_once(self):
        steps = release.plan()
        registry = release.registration(release.ROOT)
        suites = registry.suites((release.ROOT / 'vereinsverwaltung/tests/ci-native.php').read_text())
        core = [s.native_suite for s in steps if not s.environment and s.native_suite]
        self.assertEqual(core, [s for s in suites if s not in registry.OPERATIONAL]
                         + [s for s in suites if s in registry.OPERATIONAL])
        self.assertEqual(len({s.id for s in steps}), len(steps))
        self.assertEqual({s.native_suite for s in steps if s.phase == 'installation' and not s.environment}, registry.OPERATIONAL)
        self.assertTrue(any(s.environment.get('DVM_INSTALLER_BROWSER') == '1' for s in steps))
        self.assertTrue(any(s.environment.get('DVM_UPGRADE_BROWSER') == '1' for s in steps))
        self.assertTrue(all(s.phase == 'installation' for s in steps if s.native_suite in registry.OPERATIONAL))

    def test_exit_zero_without_the_actual_selected_suite_is_not_green(self):
        self.assertEqual(self.run_owned(self.step('wrong-suite')), 1)
        result = json.loads((self.output / 'result.json').read_text())
        self.assertEqual(result['status'], 'incomplete')
        self.assertEqual(result['failed'], ['core:owned'])

    def test_long_evidence_paths_keep_system_socket_paths_short(self):
        self.output = self.base / ('retained-evidence-' + 'x' * 110)
        before = ('import os,socket,tempfile;from pathlib import Path;'
                  'p=Path(tempfile.mkdtemp(prefix="org.chromium.Chromium."));'
                  's=socket.socket(socket.AF_UNIX);s.bind(str(p/"SingletonSocket"));s.close();'
                  'assert len(os.environ["TMPDIR"])<40;'
                  'assert os.environ["DVM_LOCAL_ACCEPTANCE_TMP"].startswith(' + repr(str(self.output)) + ');'
                  'print("owned_system_temporary="+os.environ["TMPDIR"]);')
        self.assertEqual(self.run_owned(self.step(before=before)), 0)
        log = (self.output / 'core-owned-attempt-1.log').read_text()
        temporary = next(line.split('=', 1)[1] for line in log.splitlines()
                         if line.startswith('owned_system_temporary='))
        self.assertFalse(Path(temporary).exists())
        self.assertTrue((self.output / 'core-owned-attempt-1.tmpdir/browser-artifacts').is_dir())

    def test_a_second_variant_of_an_already_registered_suite_cannot_be_omitted(self):
        source = "if(getenv('DVM_FIRST_BROWSER')==='1'||getenv('DVM_SECOND_BROWSER')==='1')runOwnBrowser();"
        with self.assertRaisesRegex(ValueError, 'DVM_SECOND_BROWSER'):
            release.verify_browser_variants([source], {'DVM_FIRST_BROWSER'})
        release.verify_browser_variants([source], {'DVM_FIRST_BROWSER', 'DVM_SECOND_BROWSER'})
        release.verify_browser_variants(["getenv('DVM_ANDROID_APP_BROWSER')"], set())

    def test_reuse_requires_the_exact_unmodified_completed_log(self):
        counter = self.base / 'counter'
        before = ('from pathlib import Path;p=Path(' + repr(str(counter)) + ');'
                  'p.write_text(str(int(p.read_text())+1)if p.exists()else"1");')
        step = self.step(before=before)
        self.assertEqual(self.run_owned(step), 0)
        self.assertEqual(self.run_owned(step, resume=True), 0)
        self.assertEqual(counter.read_text(), '1')
        first = self.output / 'core-owned-attempt-1.log'
        first.write_text('changed incomplete evidence\n')
        self.assertEqual(self.run_owned(step, resume=True), 0)
        self.assertEqual(counter.read_text(), '2')
        self.assertEqual(first.read_text(), 'changed incomplete evidence\n')
        record = json.loads((self.output / 'state.json').read_text())['results']['core:owned']
        self.assertEqual(record['attempt'], 2)
        self.assertTrue((self.output / record['log']).is_file())

    def test_a_new_commit_cannot_reuse_old_green_results(self):
        self.assertEqual(self.run_owned(), 0)
        prior = (self.output / 'state.json').read_bytes()
        (self.root / 'source.txt').write_text('new independently reviewed version\n')
        self.commit()
        with self.assertRaises(ValueError):
            self.run_owned(resume=True)
        self.assertEqual((self.output / 'state.json').read_bytes(), prior)

    def test_low_disk_keeps_unstarted_suites_pending_then_resumes(self):
        counter = self.base / 'started'
        before = 'from pathlib import Path;Path(' + repr(str(counter)) + ').write_text("started");'
        blocked = {'reason': 'insufficient_disk_space', 'filesystem': 'source',
                   'path': str(self.root), 'free_bytes': 1, 'required_bytes': 128 * 1024 * 1024}
        with patch.object(release, 'storage_blocker', return_value=blocked):
            self.assertEqual(self.run_owned(self.step(before=before), keep_going=True), 1)
        self.assertFalse(counter.exists())
        result = json.loads((self.output / 'result.json').read_text())
        self.assertEqual(result['status'], 'incomplete')
        self.assertEqual(result['pending'], ['core:owned'])
        self.assertEqual(result['failed'], [])
        self.assertEqual(result['environment_blocked']['next_step'], 'core:owned')
        with patch.object(release, 'storage_blocker', return_value=None):
            self.assertEqual(self.run_owned(self.step(before=before), resume=True), 0)
        self.assertTrue(counter.exists())
        self.assertIsNone(json.loads((self.output / 'result.json').read_text())['environment_blocked'])
        self.assertFalse((self.output / 'core-owned-attempt-2.log').exists())

    def test_storage_guard_checks_both_filesystems(self):
        with patch.object(release.shutil, 'disk_usage', return_value=type('Usage', (), {'free': 1})):
            self.assertEqual(release.storage_blocker(self.root, self.output)['filesystem'], 'source')
        enough = type('Usage', (), {'free': 2 * 1024 * 1024 * 1024})
        low = type('Usage', (), {'free': 1})
        with patch.object(release.shutil, 'disk_usage', side_effect=[enough, low]):
            self.assertEqual(release.storage_blocker(self.root, self.output)['filesystem'], 'evidence')
        with patch.object(release.shutil, 'disk_usage', return_value=enough):
            self.assertIsNone(release.storage_blocker(self.root, self.output))

    def test_dirty_source_is_refused_before_creating_evidence(self):
        (self.root / 'source.txt').write_text('uncommitted change\n')
        with self.assertRaises(ValueError):
            self.run_owned()
        self.assertFalse(self.output.exists())

    def test_interrupted_running_attempt_is_preserved_and_retried_separately(self):
        self.assertEqual(self.run_owned(), 0)
        state_file = self.output / 'state.json'
        state = json.loads(state_file.read_text())
        state['results']['core:owned'] = {'status': 'running', 'attempt': 1}
        state_file.write_text(json.dumps(state))
        first = (self.output / 'core-owned-attempt-1.log').read_bytes()
        self.assertEqual(self.run_owned(resume=True), 0)
        self.assertEqual((self.output / 'core-owned-attempt-1.log').read_bytes(), first)
        self.assertTrue((self.output / 'core-owned-attempt-2.log').is_file())

    def test_inherited_diagnostics_and_live_transport_cannot_shorten_acceptance(self):
        with patch.dict(os.environ, {'DVM_SOCIAL_SAVE_SMOKE': '1', 'DVM_INVENTORY_FILE_CASE': 'one',
                                    'DVM_CATALOGUE_POLICY_DIAGNOSTIC': 'small', 'DVM_OTHER_BROWSER': '1',
                                    'VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP': '1', 'GITHUB_ACTIONS': 'true',
                                    'DVM_LOCAL_RUNTIME': '/owned/runtime'}):
            step = release.Step('browser', 'browsers', (), {'DVM_SELECTED_BROWSER': '1'})
            environment = release.full_environment(self.root, step, self.base)
        for name in ['DVM_SOCIAL_SAVE_SMOKE', 'DVM_INVENTORY_FILE_CASE', 'DVM_CATALOGUE_POLICY_DIAGNOSTIC',
                     'DVM_OTHER_BROWSER', 'VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP', 'GITHUB_ACTIONS']:
            self.assertNotIn(name, environment)
        self.assertEqual(environment['DVM_SELECTED_BROWSER'], '1')
        self.assertEqual(environment['DVM_LOCAL_RUNTIME'], '/owned/runtime')
        self.assertEqual(environment['VEREIN_ERIC_DISABLE_TRANSPORT'], '1')
        self.assertEqual(environment['KULTURHUS_DISABLE_MAIL'], '1')
        self.assertEqual(environment['DVM_LOCAL_ACCEPTANCE_TMP'], str(self.base))

    def test_a_still_running_previous_child_prevents_a_duplicate_resume(self):
        self.assertEqual(self.run_owned(), 0)
        path = self.output / 'state.json'
        state = json.loads(path.read_text())
        state['results']['core:owned'].update(status='running', process=release.process_identity(os.getpid()))
        path.write_text(json.dumps(state))
        original = path.read_bytes()
        with self.assertRaisesRegex(ValueError, 'still running'):
            self.run_owned(resume=True)
        self.assertEqual(path.read_bytes(), original)
        state['results']['core:owned']['process']['started'] = 'expired owner, not the reused PID'
        path.write_text(json.dumps(state))
        self.assertEqual(self.run_owned(resume=True), 0)

    def test_both_interrupt_signals_work_even_when_sigint_was_inherited_as_ignored(self):
        for signum in [signal.SIGINT, signal.SIGTERM]:
            with self.subTest(signum=signum):
                self.check_interrupt(signum)

    def check_interrupt(self, signum):
        self.output = self.base / ('signal-evidence-' + str(signum))
        child_pid = self.base / ('child-' + str(signum) + '.pid')
        child = 'import os,time;from pathlib import Path;Path(' + repr(str(child_pid)) + ').write_text(str(os.getpid()));time.sleep(60)'
        program = ('import importlib.util,sys,signal;from pathlib import Path;signal.signal(signal.SIGINT,signal.SIG_IGN);'
                   's=importlib.util.spec_from_file_location("owned_release",' + repr(str(HERE / 'local-release.py')) + ');'
                   'm=importlib.util.module_from_spec(s);sys.modules[s.name]=m;s.loader.exec_module(m);'
                   'steps=[m.Step("owned-slow","core",(' + repr(sys.executable) + ',"-c",' + repr(child) + ')),m.Step("unstarted","core",("false",))];'
                   'sys.exit(m.run(Path(' + repr(str(self.root)) + '),Path(' + repr(str(self.output)) + '),steps,["core"],keep_going=True))')
        parent = subprocess.Popen([sys.executable, '-c', program], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        try:
            deadline = time.monotonic() + 10
            while not child_pid.exists() and parent.poll() is None and time.monotonic() < deadline:
                time.sleep(0.03)
            self.assertTrue(child_pid.exists())
            parent.send_signal(signum)
            self.assertNotEqual(parent.wait(timeout=15), 0)
            result = json.loads((self.output / 'result.json').read_text())
            self.assertEqual(result['status'], 'incomplete')
            self.assertEqual(result['failed'], ['owned-slow'])
            self.assertEqual(result['pending'], ['unstarted'])
            with self.assertRaises(ProcessLookupError):
                os.kill(int(child_pid.read_text()), 0)
        finally:
            if parent.poll() is None:
                parent.kill()
                parent.wait()
            if parent.stderr:
                parent.stderr.close()

    def test_the_callers_signal_handlers_are_restored_after_a_run(self):
        before = {s: signal.getsignal(s) for s in [signal.SIGINT, signal.SIGTERM]}
        self.assertEqual(self.run_owned(), 0)
        self.assertEqual({s: signal.getsignal(s) for s in before}, before)

    def test_automatic_hosted_workflows_stay_disabled_while_quota_is_exhausted(self):
        for name in ['native-regressions.yml', 'communication-ui.yml', 'native-apps.yml']:
            source = (release.ROOT / '.github/workflows' / name).read_text()
            events = source.split('on:\n', 1)[1].split('permissions:', 1)[0]
            self.assertIn('  workflow_dispatch:', events)
            self.assertNotIn('  push:', events)
            self.assertNotIn('  pull_request:', events)


if __name__ == '__main__':
    unittest.main()
