"""Exercise actual CI startup decisions using an isolated Docker command double."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).with_name("start-mysql.sh")
DIGEST = "sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242"
DOUBLE = '''#!/usr/bin/env python3
import json, os, sys
a=sys.argv[1:]
with open(os.environ['OWN_DOCKER_CALLS'],'a') as f: f.write(json.dumps(a)+'\\n')
mode=os.environ['OWN_DOCKER_MODE']
if a[0]=='pull':
 if mode=='all-fail' or (mode=='fallback' and not a[1].startswith('docker.io/')): sys.exit(1)
 print('own exact pinned image'); sys.exit(0)
if a[:2]==['container','inspect']: sys.exit(0 if mode=='occupied' else 1)
if a[0]=='inspect': print('unhealthy' if mode=='unhealthy' else 'healthy'); sys.exit(0)
if a[0]=='run': print('own ephemeral container'); sys.exit(0)
if a[0]=='logs': print('own health diagnostics'); sys.exit(0)
raise SystemExit('Unexpected Docker command')
'''


class Startup(unittest.TestCase):
    def run_start(self, mode="first", actions="true"):
        with tempfile.TemporaryDirectory(prefix="kh-own-ci-docker-") as directory:
            root = Path(directory)
            docker = root / "docker"
            docker.write_text(DOUBLE)
            docker.chmod(0o700)
            calls = root / "calls.jsonl"
            env = dict(os.environ, PATH=directory + os.pathsep + os.environ["PATH"],
                       GITHUB_ACTIONS=actions, GITHUB_RUN_ID="123456",
                       OWN_DOCKER_MODE=mode, OWN_DOCKER_CALLS=str(calls))
            result = subprocess.run(["bash", str(SCRIPT)], env=env,
                                    capture_output=True, text=True, timeout=15)
            data = [json.loads(line) for line in calls.read_text().splitlines()] if calls.exists() else []
            return result, data

    def assert_databases(self, calls):
        runs = [call for call in calls if call[0] == "run"]
        self.assertEqual(len(runs), 2)
        for call, name, port, password in zip(runs,
            ["kh-native-ci-mysql", "kh-calendar-ci-mysql"],
            ["13371", "13372"], ["synthetic-ci-only", "isolated-regression-only"]):
            self.assertEqual(call[call.index("--name") + 1], name)
            self.assertIn("com.kulturhus.ci.run=123456", call)
            self.assertIn("127.0.0.1:" + port + ":3306", call)
            self.assertIn("MYSQL_ROOT_PASSWORD=" + password, call)
            images = [value for value in call if value.endswith("@" + DIGEST)]
            self.assertEqual(len(images), 1)
            self.assertEqual(call[call.index(images[0]) + 1:],
                             ["--binlog-expire-logs-seconds=3600", "--max-binlog-size=104857600"])
        self.assertFalse(any(call[0] in ["rm", "stop", "kill"] for call in calls))

    def test_no_local_or_shared_database_start(self):
        result, calls = self.run_start(actions="false")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(calls, [])

    def test_first_registry_only_one_pull(self):
        result, calls = self.run_start()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len([c for c in calls if c[0] == "pull"]), 1)
        self.assert_databases(calls)

    def test_real_rate_limit_decision_falls_back_without_changing_digest(self):
        result, calls = self.run_start("fallback")
        self.assertEqual(result.returncode, 0, result.stderr)
        pulls = [c[1] for c in calls if c[0] == "pull"]
        self.assertEqual(len(pulls), 3)
        self.assertTrue(all(ref.endswith("@" + DIGEST) for ref in pulls))
        self.assertTrue(pulls[-1].startswith("docker.io/library/mysql:"))
        self.assertEqual(result.stdout.count("::warning::"), 2)
        self.assert_databases(calls)

    def test_all_registries_fail_without_any_database_mutation(self):
        result, calls = self.run_start("all-fail")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(len(calls), 3)
        self.assertTrue(all(c[0] == "pull" for c in calls))

    def test_occupied_name_never_replaced(self):
        result, calls = self.run_start("occupied")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(any(c[0] in ["run", "rm", "stop", "kill"] for c in calls))

    def test_unhealthy_start_fails_with_exact_owned_diagnostics(self):
        result, calls = self.run_start("unhealthy")
        self.assertNotEqual(result.returncode, 0)
        self.assert_databases(calls)
        self.assertEqual([c[-1] for c in calls if c[0] == "logs"],
                         ["kh-native-ci-mysql", "kh-calendar-ci-mysql"])


if __name__ == "__main__":
    unittest.main()
