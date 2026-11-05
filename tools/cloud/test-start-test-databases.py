"""Run the real local entry point against an isolated Docker protocol double."""
import json
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).with_name('start-test-databases.py')
DIGEST = 'sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242'
IMAGE_ID = 'sha256:' + 'a' * 64


def container(name, port, password):
    return {'Id': hashlib.sha256(name.encode()).hexdigest(), 'Name': '/' + name, 'Image': IMAGE_ID,
            'Config': {'User': '', 'Env': ['MYSQL_ROOT_PASSWORD=' + password, 'MYSQL_ROOT_HOST=%']},
            'HostConfig': {'PortBindings': {'3306/tcp': [{'HostIp': '127.0.0.1', 'HostPort': port}]},
                           'NetworkMode': 'default', 'Privileged': False}}


DOUBLE = '''#!/usr/bin/env python3
import json,os,sys
from pathlib import Path
args=sys.argv[1:]
assert args.pop(0)=='--host=unix:///var/run/docker.sock'
assert not any(k in os.environ for k in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH'])
root=Path(os.environ['OWN_DOCKER_ROOT']);state=json.loads((root/'state.json').read_text())
with (root/'calls.jsonl').open('a')as f:f.write(json.dumps(args)+'\\n')
if args==['info']:sys.exit(1 if os.environ.get('OWN_DOCKER_FAIL')=='info' else 0)
if args[:2]==['container','inspect']:
 if os.environ.get('OWN_DOCKER_FAIL')=='inspect':print('permission denied',file=sys.stderr);sys.exit(1)
 if args[2] not in state:print('Error response from daemon: No such container: '+args[2],file=sys.stderr);sys.exit(1)
 print(json.dumps([state[args[2]]]))
elif args[:2]==['image','inspect']:
 print(json.dumps([{'Id':args[2],'RepoDigests':['mysql@'+os.environ['OWN_DIGEST']]}]))
elif args[0]=='run':
 name=args[args.index('--name')+1];binding=args[args.index('-p')+1]
 password=next(v.split('=',1)[1]for v in args if v.startswith('MYSQL_ROOT_PASSWORD='))
 state[name]={'Name':'/'+name,'Image':os.environ['OWN_IMAGE_ID'],'Config':{'User':'','Env':['MYSQL_ROOT_PASSWORD='+password,'MYSQL_ROOT_HOST=%']},'HostConfig':{'PortBindings':{'3306/tcp':[{'HostIp':binding.split(':')[0],'HostPort':binding.split(':')[1]}]},'NetworkMode':'default','Privileged':False}}
 (root/'state.json').write_text(json.dumps(state));print('own-created-container')
elif args[0]=='start':assert args[1] in state
elif args[0]=='update':
 if os.environ.get('OWN_DOCKER_FAIL')=='update':sys.exit(1)
 name=next(name for name,c in state.items()if c['Id']==args[-1])
 assert args[1:-1]==['--memory=4g','--memory-swap=4g']
 state[name]['HostConfig'].update(Memory=4294967296,MemorySwap=4294967296)
 if os.environ.get('OWN_DOCKER_FAIL')=='budget':state[name]['HostConfig']['Memory']=2147483648
 if os.environ.get('OWN_DOCKER_FAIL')=='identity':state[name]['Id']='foreign-replacement'
 (root/'state.json').write_text(json.dumps(state))
elif args[0]=='exec':print('1')
else:raise SystemExit('Unexpected Docker operation')
'''


class LocalDatabases(unittest.TestCase):
    def run_entry(self, state, mode='--all', failure='', **extra):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'state.json').write_text(json.dumps(state))
            (root / 'docker').write_text(DOUBLE)
            (root / 'docker').chmod(0o700)
            environment = dict(os.environ, PATH=directory + os.pathsep + os.environ['PATH'],
                               DVM_LOCAL_TEST_DATABASES='1', GITHUB_ACTIONS='',
                               DOCKER_HOST='tcp://foreign.invalid:2375', DOCKER_CONTEXT='foreign',
                               DOCKER_TLS='1', DOCKER_TLS_VERIFY='1', DOCKER_CERT_PATH='/foreign',
                               OWN_DOCKER_ROOT=directory, OWN_DIGEST=DIGEST, OWN_IMAGE_ID=IMAGE_ID,
                               OWN_DOCKER_FAIL=failure)
            environment.update(extra)
            result = subprocess.run(['python3', str(SCRIPT), mode], env=environment,
                                    capture_output=True, text=True, timeout=15)
            calls = [json.loads(line) for line in (root / 'calls.jsonl').read_text().splitlines()] if (root / 'calls.jsonl').exists() else []
            return result, calls

    def test_fresh_both_servers_use_exact_pinned_loopback_spec(self):
        result, calls = self.run_entry({})
        self.assertEqual(result.returncode, 0, result.stderr)
        runs = [call for call in calls if call[0] == 'run']
        self.assertEqual(len(runs), 2)
        for run, port, memory in zip(runs, ['13371', '13372'], ['4g', '2g']):
            self.assertIn('mysql:8.4@' + DIGEST, run)
            self.assertEqual(run[run.index('-p') + 1], '127.0.0.1:' + port + ':3306')
            self.assertIn('--memory=' + memory, run)
            self.assertIn('--memory-swap=' + memory, run)
        self.assertFalse(any(call[0] in ['rm', 'stop', 'kill'] for call in calls))

    def test_existing_legacy_servers_are_reused_without_schema_or_seed(self):
        state = {name: container(name, port, password) for name, port, password in [
            ('kulturhus-native-ci-mysql', '13371', 'synthetic-ci-only'),
            ('kulturhus-calendar-ci-mysql', '13372', 'isolated-regression-only')]}
        result, calls = self.run_entry(state)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(any(call[0] == 'run' for call in calls))
        self.assertEqual(len([c for c in calls if c[0] == 'start']), 2)
        for call in calls:
            if call[0] == 'exec':
                self.assertIn('--execute=SELECT 1', call)

    def test_existing_finance_alias_is_shared_by_calendar_tests(self):
        name = 'kulturhus-finance-ci-mysql'
        result, calls = self.run_entry({name: container(name, '13372', 'isolated-regression-only')}, '--calendar')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(['start', name], calls)
        self.assertFalse(any(call[0] == 'run' for call in calls))

    def test_only_owned_exact_native_old_default_is_expanded_by_identity(self):
        name = 'kulturhus-native-ci-mysql'
        c = container(name, '13371', 'synthetic-ci-only')
        c['Config']['Labels'] = {'kulturhus.purpose': 'local-synthetic-ci'}
        c['HostConfig'].update(Memory=2147483648, MemorySwap=2147483648)
        result, calls = self.run_entry({name: c}, '--native')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([call for call in calls if call[0] == 'update'],
                         [['update', '--memory=4g', '--memory-swap=4g', c['Id']]])
        self.assertLess(next(i for i, call in enumerate(calls) if call[0] == 'update'),
                        calls.index(['start', name]))
        self.assertFalse(any(call[0] in ['run', 'rm', 'stop', 'kill'] for call in calls))

    def test_custom_unlimited_larger_unlabelled_and_calendar_budgets_are_preserved(self):
        for memory, swap, label, mode in [
                (0, 0, True, '--native'), (1073741824, 1073741824, True, '--native'),
                (4294967296, 4294967296, True, '--native'),
                (8589934592, 8589934592, True, '--native'),
                (2147483648, -1, True, '--native'),
                (2147483648, 2147483648, False, '--native'),
                (2147483648, 2147483648, True, '--calendar')]:
            with self.subTest(memory=memory, swap=swap, label=label, mode=mode):
                name = 'kulturhus-native-ci-mysql' if mode == '--native' else 'kulturhus-calendar-ci-mysql'
                c = container(name, '13371' if mode == '--native' else '13372',
                              'synthetic-ci-only' if mode == '--native' else 'isolated-regression-only')
                c['Config']['Labels'] = {'kulturhus.purpose': 'local-synthetic-ci'} if label else None
                c['HostConfig'].update(Memory=memory, MemorySwap=swap)
                result, calls = self.run_entry({name: c}, mode)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertFalse(any(call[0] in ['update', 'run', 'rm', 'stop', 'kill'] for call in calls))

    def test_failed_or_unconfirmed_budget_never_starts_or_replaces_runtime(self):
        name = 'kulturhus-native-ci-mysql'
        for failure in ['update', 'budget', 'identity']:
            with self.subTest(failure=failure):
                c = container(name, '13371', 'synthetic-ci-only')
                c['Config']['Labels'] = {'kulturhus.purpose': 'local-synthetic-ci'}
                c['HostConfig'].update(Memory=2147483648, MemorySwap=2147483648)
                result, calls = self.run_entry({name: c}, '--native', failure)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(any(call[0] in ['start', 'exec', 'run', 'rm', 'stop', 'kill'] for call in calls))

    def test_foreign_or_public_runtime_blocks_all_mutations(self):
        name = 'kulturhus-calendar-ci-mysql'
        for change in ['password', 'port', 'digest', 'privileged']:
            with self.subTest(change=change):
                c = container(name, '13372', 'isolated-regression-only')
                extra = {}
                if change == 'password': c['Config']['Env'] = ['MYSQL_ROOT_PASSWORD=foreign']
                if change == 'port': c['HostConfig']['PortBindings']['3306/tcp'][0]['HostIp'] = '0.0.0.0'
                if change == 'digest': extra['OWN_DIGEST'] = 'sha256:' + 'b' * 64
                if change == 'privileged': c['HostConfig']['Privileged'] = True
                native = container('kulturhus-native-ci-mysql', '13371', 'synthetic-ci-only')
                native['Config']['Labels'] = {'kulturhus.purpose': 'local-synthetic-ci'}
                native['HostConfig'].update(Memory=2147483648, MemorySwap=2147483648)
                result, calls = self.run_entry({name: c, 'kulturhus-native-ci-mysql': native}, **extra)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(any(call[0] in ['run', 'start', 'exec', 'update'] for call in calls))

    def test_ambiguous_calendar_and_finance_containers_are_preserved(self):
        state = {name: container(name, '13372', 'isolated-regression-only') for name in [
            'kulturhus-calendar-ci-mysql', 'kulturhus-finance-ci-mysql']}
        result, calls = self.run_entry(state)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(any(call[0] in ['run', 'start', 'exec'] for call in calls))

    def test_daemon_or_inspection_failure_is_never_treated_as_absence(self):
        for failure in ['info', 'inspect']:
            result, calls = self.run_entry({}, failure=failure)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(any(call[0] in ['run', 'start', 'exec'] for call in calls))

    def test_explicit_local_guard_rejects_actions_and_missing_authorization(self):
        for guard in [{'GITHUB_ACTIONS': 'true'}, {'DVM_LOCAL_TEST_DATABASES': '0'}]:
            result, calls = self.run_entry({}, **guard)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(calls, [])


if __name__ == '__main__':
    unittest.main()
