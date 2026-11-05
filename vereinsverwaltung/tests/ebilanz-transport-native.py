"""Own synthetic E-Bilanz: generate, native-validate, optionally send ONE test.

Requires a private licensed runtime and explicit --send-test plus official test
certificate and PIN on stdin. No production mode, no retries. The exclusively
created claim survives crashes so an uncertain send cannot silently repeat.
"""
import argparse
import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('native_fixtures',Path(__file__).with_name('ebilanz-native.py'))
fixtures=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixtures)

def invoke(config,request):
    p=subprocess.run([sys.executable,'-B',str(ROOT/'vereinsverwaltung/tools/eric-bridge.py'),str(config)],
                     input=json.dumps(request).encode('utf8'),capture_output=True,timeout=110)
    try: result=json.loads(p.stdout)
    except ValueError: raise RuntimeError('No reliable bridge response; do not repeat a claimed send.') from None
    if p.returncode not in (0,2):raise RuntimeError('Bridge terminated; do not repeat a claimed send.')
    return result

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--config',type=Path,required=True)
    ap.add_argument('--private-output',type=Path,required=True)
    ap.add_argument('--report',type=Path,required=True)
    ap.add_argument('--send-test',action='store_true')
    ap.add_argument('--test-certificate',type=Path)
    args=ap.parse_args()
    if args.send_test and not args.test_certificate:ap.error('Explicit test certificate required.')
    cfg=json.loads(args.config.read_text(encoding='utf-8-sig'))
    cfg.update(production_enabled=False,production_versions=[],balance_test_enabled=True)
    cfg['profiles']={'Bilanz_6_9':{'family':'balance','label':'E-Bilanz 6.9 TEST','year':None,'test_only':True,
        'data_art':'Bilanz','verfahren':'ElsterBilanz','vorgang':'send-Auth','test_marker':'700000004',
        'payload_tag':'{http://rzf.fin-nrw.de/RMS/EBilanz/2016/XMLSchema}EBilanz','pdf_supported':False,'transport_enabled':True}}
    args.private_output.mkdir(parents=True,exist_ok=True)
    # Fixed case: own PHP journal, equipment + software, known synthetic tax office.
    values=fixtures.fixture('asset-mixed');mapping,profile=fixtures.inputs(values)
    summary={'source':'own-generator','case':'asset-mixed','data_version':'Bilanz_6_9','environment':'test','sent':False}
    with tempfile.TemporaryDirectory(prefix='ebalance-transport-',dir=args.private_output) as work:
        config=Path(work)/'runtime.json';config.write_text(json.dumps(cfg),encoding='utf8');config.chmod(0o600)
        package=invoke(config,{'action':'balance-generate','environment':'test','values':values,'mapping':mapping,'profile':profile})
        if package.get('code')!=0:raise RuntimeError('Own generation failed: '+str(package.get('message')))
        xml=package['xml'];digest=hashlib.sha256(xml.encode('utf8')).hexdigest()
        assert digest==package['xml_sha256'] and package['tax_values_sha256']==values['sha256']
        request={'environment':'test','data_version':'Bilanz_6_9','xml':xml,'xml_sha256':digest}
        validated=invoke(config,request|{'action':'validate'})
        summary.update(validation_code=validated['code'],xml_sha256=digest,asset_items=package['asset_items'],asset_facts=package['asset_fact_count'])
        if validated['code']!=0:
            (args.private_output/'validation-failure.json').write_text(json.dumps(validated),encoding='utf8')
            raise RuntimeError('Native validation failed; no transmission.')
        if args.send_test:
            pin=sys.stdin.readline().rstrip('\r\n')
            if not pin:raise RuntimeError('Test PIN must be supplied on stdin.')
            cert=base64.b64encode(args.test_certificate.read_bytes()).decode('ascii')
            # Native bridge verifies Testzertifikat=true before its send call.
            claim=args.private_output/('claimed-'+digest+'.json')
            with claim.open('x',encoding='utf8') as out:json.dump({'xml_sha256':digest,'state':'claimed-do-not-repeat'},out)
            claim.chmod(0o600)
            sent=invoke(config,request|{'action':'send','confirm':'SEND-TEST','synthetic_confirmed':True,'certificate_b64':cert,'pin':pin})
            del pin,cert
            (args.private_output/'response.json').write_text(json.dumps(sent,ensure_ascii=False,indent=2),encoding='utf8')
            ticket=sent.get('transfer_ticket','')
            accepted=(sent.get('code')==0 and sent.get('sent') is True and bool(ticket) and sent.get('xml_sha256')==digest and sent.get('environment')=='test')
            summary.update(sent=accepted,transport_code=sent.get('code'),transport_attempted=sent.get('transport_attempted'),
                           transfer_ticket=ticket,server_response_sha256=hashlib.sha256(sent.get('server_xml','').encode()).hexdigest(),
                           response_kind='technical-acceptance-not-tax-assessment')
            if accepted:
                root=ET.fromstring(sent['server_xml'])
                summary['response_codes']=[n.text for n in root.iter() if n.tag.rsplit('}',1)[-1]=='Code']
            args.report.write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
            print(json.dumps(summary,ensure_ascii=True))
            if not accepted:raise RuntimeError('Test not reliably accepted. Inspect private response, do not repeat.')
        else:
            args.report.write_text(json.dumps(summary,indent=2)+'\n',encoding='utf8');print(json.dumps(summary))

if __name__=='__main__':main()
