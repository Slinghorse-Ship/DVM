"""Validate dated synthetic output with licensed ERiC. No certificate or send API."""
import argparse
import ctypes as c
import importlib.util
import json
from pathlib import Path
import tempfile
import sys
import xml.etree.ElementTree as ET

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('period_cases',HERE/'ebilanz-period-test.py')
cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(cases)
spec=importlib.util.spec_from_file_location('period_asset_cases',HERE/'ebilanz-period-assets-test.py')
asset_cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(asset_cases)
spec=importlib.util.spec_from_file_location('period_whole_cases',HERE/'ebilanz-period-whole-test.py')
whole_cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(whole_cases)
spec=importlib.util.spec_from_file_location('period_subsidiary_cases',HERE/'ebilanz-period-subsidiary-test.py')
subsidiary_cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(subsidiary_cases)
spec=importlib.util.spec_from_file_location('calendar_bridge_cases',HERE/'ebilanz-period-calendar-test.py')
calendar_cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(calendar_cases)
spec=importlib.util.spec_from_file_location('bridge',HERE.parent/'tools/eric-bridge.py')
bridge=importlib.util.module_from_spec(spec);spec.loader.exec_module(bridge)

def run(config):
    results=[]
    with tempfile.TemporaryDirectory(prefix='synthetic-dated-ebalance-') as directory:
        eric=bridge.Eric(config,Path(directory));diagnostics=[]
        callback_type=(c.WINFUNCTYPE if sys.platform=='win32' else c.CFUNCTYPE)(None,c.c_char_p,c.c_int,c.c_char_p,c.c_void_p)
        callback=callback_type(lambda category,level,message,user: diagnostics.append((message or b'').decode('utf8',errors='replace')))
        eric.log_callback_api(c.cast(callback,c.c_void_p),0,None)
        original_process=eric.process
        def validation_only(xml,version,flags,*rest):
            if flags!=2:raise RuntimeError('Only ERiC validation, never transmission')
            return original_process(xml,version,flags,*rest)
        eric.process=validation_only
        try:
            variants=[(v,cases.fixture,cases.cases.inputs) for v in ('cross','short-first','calendar','successor')]
            variants += [(v,asset_cases.fixture,asset_cases.inputs) for v in ('book','tax','book-successor','tax-successor')]
            variants += [(v+s,asset_cases.fixture,asset_cases.inputs) for v in ('gwg','degressive','fully') for s in ('','-successor')]
            variants += [(v,whole_cases.fixture,whole_cases.inputs) for v in ('book','tax','book-successor','tax-successor')]
            variants += [(v,subsidiary_cases.fixture,subsidiary_cases.inputs) for v in ('book','tax','off-balance','book-successor','tax-successor','off-balance-successor')]
            variants += [(v,calendar_cases.fixture,calendar_cases.inputs) for v in ('book','tax')]
            for variant,fixture,inputs in variants:
                values=fixture(variant);mapping,profile=inputs(values)
                package=cases.EbilanzBuilder().build(values,mapping,profile,config['manufacturer_id'])
                diagnostics.clear();result=eric.run(package['xml'],'Bilanz_6_9')
                row={'variant':variant,'profile':profile['kind'],'period':package['period'],'previous_period':package['previous_period'],
                     'xml_sha256':package['xml_sha256'],'asset_items':package['asset_items'],
                     'code':result['code'],'sent':False,'transport_attempted':False}
                if result['code']!=0:
                    # Only synthetic validator diagnostics. No config, certificate or XML.
                    row['message']=result.get('message','');row['diagnostics']=diagnostics[-15:]
                    response=ET.fromstring(result['response_xml'])
                    row['validation_errors']=[e.text for e in response.iter() if e.tag.rsplit('}',1)[-1] in ('Text','FachlicheFehlerId')]
                results.append(row);print(json.dumps(row,ensure_ascii=False),flush=True)
        finally:eric.end()
    return {'passed':all(r['code']==0 for r in results),'sent':False,'transport_attempted':False,'cases':results}

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config',required=True,type=Path)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args();result=run(json.loads(args.config.read_text(encoding='utf-8-sig')))
    if args.report:args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf8')
    raise SystemExit(0 if result['passed'] else 1)
