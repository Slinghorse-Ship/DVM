"""Validate our own synthetic journal export with installed ERiC; cannot send.

Run with --config PRIVATE_RUNTIME_JSON. Licensed runtime is never distributed.
The Docker fixture calculates the tax balance using the actual PHP accounting core.
"""
import argparse
import ctypes as c
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT/'vereinsverwaltung/tools'))
from ebilanz_builder import EbilanzBuilder
from ebilanz_assets import TANGIBLE, INTANGIBLE, GWG
from ebilanz_mapping import automatic_mapping


def fixture(variant='adjusted',mode='values'):
    cmd=['docker','run','--rm','--network','none','--mount',f'type=bind,source={ROOT.as_posix()},target=/workspace,readonly',
         '-w','/workspace','--entrypoint','php','kulturhus-mysql-regression:php8.4','vereinsverwaltung/tests/fixtures/ebilanz-values.php',variant,mode]
    native=os.environ.get('VEREIN_FIXTURE_PHP')
    if native:
        executable=Path(native)
        if not executable.is_absolute() or not executable.is_file():raise RuntimeError('Explicit existing native PHP executable required.')
        cmd=[str(executable),str(ROOT/'vereinsverwaltung/tests/fixtures/ebilanz-values.php'),variant,mode]
    p=subprocess.run(cmd, capture_output=True, text=True, encoding='utf-8', timeout=60)
    if p.returncode or p.stderr.strip():
        raise RuntimeError(p.stdout[-1000:]+p.stderr[-1000:])
    return json.loads(p.stdout)


def inputs(values):
    mapping={'bank':'bs.ass.currAss.cashEquiv.bank', 'capital':'bs.eqLiab.equity.retainedEarnings',
             'receivables':'bs.ass.currAss.receiv.trade.other', 'liabilities':'bs.eqLiab.liab.trade.genOther',
             'sales':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1',
             'expense':'is.netIncome.regular.operatingTC.otherCost.administration'}
    for key,account in values['tax']['accounts'].items():
        if key.startswith('equipment-'):mapping[key]=TANGIBLE if key.endswith('depreciation') else ('bs.ass.fixAss.tan.otherEquipm.office' if account['skr_code']=='06500' else 'bs.ass.fixAss.tan.otherEquipm.factory')
        if key.startswith('software-') and account['skr_code']=='62000':mapping[key]=INTANGIBLE
        if key.startswith('software-') and account['skr_code']=='01350':mapping[key]='bs.ass.fixAss.intan.concessionBrands.software'
        if key=='bank2':mapping[key]=mapping['bank']
        if account['skr_code']=='06700':mapping[key]='bs.ass.fixAss.tan.otherEquipm.gwg'
        if account['skr_code']=='62600':mapping[key]=GWG
    profile={'kind':'single_taxable_business','scope_confirmed':True,'source_scope':'separate_business_ledger',
             'tax_privileged':True,'asset_scope':'single_reported_business','asset_values_unchanged':'asset_tax' not in values,
             'asset_tax_values_reviewed':'asset_tax' in values,
             'no_retained_fixed_assets':values.get('asset_schedule') is None,
             'tax_values_sha256':values['sha256'],'business_name':'Synthetischer Test-Geschäftsbetrieb',
             'supplier':'Synthetischer Softwaretest',
             'company':{'tax_number':'5192050001276','name':'Synthetischer Testverein','street':'Testweg','house_number':'1',
                        'postal_code':'11111','city':'Teststadt','country':'Deutschland'}}
    return mapping,profile


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--config',type=Path,required=True)
    parser.add_argument('--report',type=Path,help='Optional synthetic test summary; never includes XML, certificates or credentials.')
    parser.add_argument('--reviewed-codes',action='store_true',help='Additionally validate every unconditional registry code in each eligible year.')
    parser.add_argument('--revisions',action='store_true',help='Also validate all implemented filing revision choices without transmission.')
    parser.add_argument('--operating',action='store_true',help='Validate operating-cost codes and every reviewed detail choice in both catalog years.')
    parser.add_argument('--cases',nargs='+',default=['adjusted','unchanged','loss','zero','cents','2026','multiple-bank',
                        'asset-december','asset-prior','asset-fully','asset-residual','asset-multiple','asset-software','asset-mixed','asset-cents',
                        'asset-office','mapping-coverage','tax-normal','accruals','accruals-2026','accruals-release','accruals-tax-change'])
    args=parser.parse_args()
    costs=json.loads((ROOT/'vereinsverwaltung/tests/fixtures/ebilanz-operating-accounts.json').read_text(encoding='utf8'))
    if args.operating:
        for year in (2025,2026):
            suffix='' if year==2025 else '-2026'
            args.cases += ['code-'+code+suffix for codes in costs['automatic'].values() for code in codes]
            args.cases += ['detail-'+code+'-'+str(index)+suffix for code,choices in costs['details'].items() for index in range(len(choices))]
            args.cases += ['operating-coverage'+suffix,'operating-coverage-credit'+suffix]
    if args.reviewed_codes:
        registry=json.loads((ROOT/'vereinsverwaltung/resources/ebilanz/skr42-6.9.json').read_text(encoding='utf8'))
        for year in (2025,2026):
            args.cases += ['code-'+code+('' if year==2025 else '-2026') for rule in registry['rules']
                if not rule.get('condition') and rule['group']!='fixed_assets' and year in rule.get('years',[2025,2026]) for code in rule['codes']]
    cfg=json.loads(args.config.read_text(encoding='utf-8-sig'))
    spec=importlib.util.spec_from_file_location('bridge',ROOT/'vereinsverwaltung/tools/eric-bridge.py')
    bridge=importlib.util.module_from_spec(spec);spec.loader.exec_module(bridge)
    results=[]
    with tempfile.TemporaryDirectory(prefix='own-ebalance-validation-') as directory:
        eric=bridge.Eric(cfg,Path(directory))
        diagnostics=[]
        callback_type=(c.WINFUNCTYPE if sys.platform=='win32' else c.CFUNCTYPE)(None,c.c_char_p,c.c_int,c.c_char_p,c.c_void_p)
        callback=callback_type(lambda category,level,message,user: diagnostics.append((message or b'').decode('utf8',errors='replace')))
        eric.log_callback_api(c.cast(callback,c.c_void_p),0,None)
        try:
            original_process=eric.process
            def validation_only(xml,version,flags,*rest):
                assert flags==2, 'Native test must never send or use certificates.'
                return original_process(xml,version,flags,*rest)
            eric.process=validation_only
            for variant in args.cases:
                values=fixture(variant);mapping,profile=inputs(values)
                if variant.startswith('detail-'):
                    code,index=variant.split('-')[1:3]
                    choice=costs['details'][code][int(index)]
                    mapping['code-'+code]='is.netIncome.regular.operatingTC.'+choice
                mapping={k:v for k,v in mapping.items() if k not in automatic_mapping(values)}
                if variant=='tax-normal':profile['tax_privileged']=False
                package=EbilanzBuilder().build(values,mapping,profile,cfg['manufacturer_id'])
                result=eric.run(package['xml'],'Bilanz_6_9')
                summary={'source':'own-journal-generator','case':variant,'code':result['code'],'facts':package['fact_count'],
                         'asset_facts':package['asset_fact_count'],'asset_items':package['asset_items'],
                         'book_profit_cents':values['book']['annual_result_cents'],'tax_profit_cents':values['tax']['annual_result_cents'],
                         'xml_sha256':package['xml_sha256'],'transmitted':False}
                results.append(summary);print(json.dumps(summary),flush=True)
                if result['code']:
                    print(result['message']);print(result['response_xml'][:22000])
                    print('\n'.join(s for s in diagnostics if any(word in s.lower() for word in ['error','fehler','invalid','element','prefix'])).replace(cfg['manufacturer_id'],'[manufacturer]')[:8000])
                assert result['code']==0, result['message']
            values=fixture();mapping,profile=inputs(values)
            if args.revisions:
                for status in ('E','B','G','BG','sK'):
                    source=None if status=='E' else {'filing_id':'a'*32,'attempt_id':'b'*32,'xml_sha256':'c'*64,'transfer_ticket':'synthetic-ticket','journal_sha256':'d'*64,'approval_sha256':'e'*64}
                    profile['filing_revision']={'status':status,'reason':'Synthetic revision review','legal_basis':'' if status=='E' else 'Synthetic legal review','confirmed':True,'source':source,'journal_sha256':'f'*64,'identity_sha256':'1'*64}
                    package=EbilanzBuilder().build(values,mapping,profile,cfg['manufacturer_id'])
                    result=eric.run(package['xml'],'Bilanz_6_9')
                    summary={'case':'revision-'+status,'code':result['code'],'xml_sha256':package['xml_sha256'],'transmitted':False}
                    results.append(summary);print(json.dumps(summary),flush=True)
                    assert result['code']==0,result['message']+' '+result.get('response_xml','')[:4000]
                del profile['filing_revision']
            package=EbilanzBuilder().build(values,mapping,profile,cfg['manufacturer_id'])
            bad=package['xml'].replace('>1550.00</de-gaap-ci:', '>1550.01</de-gaap-ci:',1)
            assert bad!=package['xml']
            rejected=eric.run(bad,'Bilanz_6_9')
            assert rejected['code']==610001002, 'ERiC must reject a changed balance-sheet total.'
            errors=ET.fromstring(rejected['response_xml'])
            error_ids=[e.text for e in errors.iter() if e.tag.endswith('}FachlicheFehlerId')]
            messages=[e.text for e in errors.iter() if e.tag.endswith('}Text')]
            assert any('summ' in s.lower() or 'aktiva' in s.lower() for s in messages), messages
            results.append({'case':'tampered-total','code':rejected['code'],'error_ids':error_ids,'expected_rejection':True,'transmitted':False})
            print(json.dumps(results[-1]),flush=True)
            if args.report:
                args.report.write_text(json.dumps({'native_profile':'Bilanz_6_9','flags':2,'results':results},ensure_ascii=False,indent=2)+'\n',encoding='utf8')
        finally:
            eric.end()
