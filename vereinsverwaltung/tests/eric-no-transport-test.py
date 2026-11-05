"""Actual bridge subprocess must refuse network actions before loading any native SDK."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


class NoTransport(unittest.TestCase):
    def test_real_bridge_network_gate_for_each_action_and_environment(self):
        bridge = Path(__file__).parents[1] / 'tools/eric-bridge.py'
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / 'runtime.json'
            # Deliberately open every other operator switch. This gate must still
            # win before a native load, certificate, XML processing or network call.
            config = {'manufacturer_id': '12345', 'library': str(root / 'never-loaded.so'),
                      'scratch': str(root), 'production_enabled': True,
                      'production_versions': ['Own_2026'],
                      'profiles': {'Own_2026': {'transport_enabled': True}}}
            for gate in ['environment', 'configuration']:
                for environment in ['test', 'production']:
                    for action in ['send', 'feedback-fetch', 'feedback-ack']:
                        with self.subTest(gate=gate, environment=environment, action=action):
                            path.write_text(json.dumps(config | {'transport_disabled': gate == 'configuration'}))
                            env = os.environ.copy()
                            env.pop('VEREIN_ERIC_DISABLE_TRANSPORT', None)
                            if gate == 'environment':
                                env['VEREIN_ERIC_DISABLE_TRANSPORT'] = '1'
                            request = {'action': action, 'environment': environment,
                                       'data_version': 'Own_2026', 'synthetic_confirmed': True,
                                       'confirm': 'SEND-' + environment.upper()}
                            result = subprocess.run([sys.executable, str(bridge), str(path)],
                                                    input=json.dumps(request), capture_output=True,
                                                    text=True, timeout=10, env=env)
                            self.assertEqual(result.returncode, 2)
                            data = json.loads(result.stdout)
                            self.assertEqual(data['code'], -1)
                            self.assertEqual(data['message'], 'ELSTER-Netzwerkzugriffe sind in dieser Umgebung gesperrt.')
                            self.assertIs(data['transport_attempted'], False)
                            self.assertIs(data['sent'], False)
                            self.assertEqual(result.stderr, '')


if __name__ == '__main__':
    unittest.main()
