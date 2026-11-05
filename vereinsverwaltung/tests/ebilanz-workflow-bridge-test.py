"""Synthetic generation/transport gates; no SDK and no network."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('fixtures',HERE/'ebilanz-native.py')
fixtures=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixtures)
from ebilanz_builder import SUPPORTED

class Workflow(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.values=fixtures.fixture('asset-mixed');cls.mapping,cls.profile=fixtures.inputs(cls.values)
    def setUp(self):
        self.request={'action':'balance-generate','environment':'test','values':copy.deepcopy(self.values),'mapping':self.mapping,'profile':self.profile}
        self.config={'manufacturer_id':'12345','balance_test_enabled':True,'library':'MUST-NOT-LOAD','scratch':'MUST-NOT-OPEN'}
    def run_bridge(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'runtime.json';path.write_text(json.dumps(self.config),encoding='utf8')
            p=subprocess.run([sys.executable,'-B',str(HERE.parent/'tools/eric-bridge.py'),str(path)],input=json.dumps(self.request).encode(),capture_output=True,timeout=20)
        self.assertFalse(p.stderr,p.stderr.decode(errors='replace'));return json.loads(p.stdout)
    def test_generate_no_sdk_or_network(self):
        r=self.run_bridge();self.assertEqual(r['code'],0);self.assertFalse(r['sent']);self.assertFalse(r['transport_attempted']);self.assertEqual(r['asset_items'],2)
    def test_production_rejected(self):
        self.request['environment']='production';self.assertEqual(self.run_bridge()['code'],-1)
    def test_operator_flag(self):
        self.config['balance_test_enabled']=False;self.assertEqual(self.run_bridge()['code'],-1)
    def test_extra_xml_rejected(self):
        self.request['xml']='<injected/>';self.assertEqual(self.run_bridge()['code'],-1)
    def test_client_manufacturer_rejected(self):
        self.request['manufacturer_id']='99999';self.assertEqual(self.run_bridge()['code'],-1)
    def test_source_hash(self):
        self.request['values']['year']=2026;self.assertEqual(self.run_bridge()['code'],-1)
    def test_generic_send_cannot_bypass_test_gate(self):
        self.request={'action':'send','data_version':'Bilanz_6_9','environment':'production','confirm':'SEND-PRODUCTION'}
        self.config.update(production_enabled=True,production_versions=['Bilanz_6_9'])
        r=self.run_bridge();self.assertEqual(r['code'],-1);self.assertFalse(r['transport_attempted'])
    def test_php_choices_match_supported_python_targets(self):
        command=['docker','run','--rm','--network','none','--mount',f'type=bind,source={fixtures.ROOT.as_posix()},target=/workspace,readonly','-w','/workspace','--entrypoint','php','kulturhus-mysql-regression:php8.4','-r',
                 'require "vereinsverwaltung/src/Finance/Balance/BalanceTaxFiling.php"; echo json_encode(\\Kulturhus\\Verein\\BalanceTaxFiling::targets());']
        native=os.environ.get('VEREIN_FIXTURE_PHP')
        if native:
            if not Path(native).is_absolute() or not Path(native).is_file():raise RuntimeError('Explicit native PHP required')
            command=[native,'-r',command[-1]]
        p=subprocess.run(command,cwd=fixtures.ROOT,capture_output=True,timeout=30);self.assertEqual(p.returncode,0,p.stderr.decode(errors='replace'))
        choices=json.loads(p.stdout);self.assertEqual({g:set(v) for g,v in choices.items()},SUPPORTED)

if __name__=='__main__':unittest.main()
