"""Native ERiC validation of synthetic club aggregates. No transport/certificates."""
import argparse
import importlib.util
import json
from pathlib import Path
import sys
import tempfile

ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_builder import EbilanzBuilder


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--config',type=Path,required=True);parser.add_argument('--report',type=Path)
    parser.add_argument('--whole-club',action='store_true',help='Validate complete non-tax-privileged club, not SGEB')
    parser.add_argument('--combined-club',action='store_true',help='Validate whole-club B/GuV plus taxable SGEB')
    parser.add_argument('--subsidiary-club',action='store_true',help='Validate whole-club B/GuV plus reviewed subsidiary result')
    parser.add_argument('--shared-disposals',action='store_true',help='Validate original shared disposals and carry, without sending')
    parser.add_argument('--revisions',action='store_true',help='Validate revision choices for this profile; no transport')
    args=parser.parse_args()
    if sum((args.whole_club,args.combined_club,args.subsidiary_club))>1:parser.error('Select one profile')
    spec=importlib.util.spec_from_file_location('club_cases',ROOT/('tests/ebilanz-subsidiary-test.py' if args.subsidiary_club else 'tests/ebilanz-combined-club-test.py' if args.combined_club else 'tests/ebilanz-whole-club-test.py' if args.whole_club else 'tests/ebilanz-club-scope-test.py'));cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(cases)
    spec=importlib.util.spec_from_file_location('bridge',ROOT/'tools/eric-bridge.py');bridge=importlib.util.module_from_spec(spec);spec.loader.exec_module(bridge)
    cfg=json.loads(args.config.read_text(encoding='utf-8-sig'));results=[]
    with tempfile.TemporaryDirectory(prefix='club-aggregate-validation-') as directory:
        eric=bridge.Eric(cfg,Path(directory))
        try:
            original=eric.process
            def validation_only(xml,version,flags,*rest):
                assert flags==2,'This test never transmits or uses certificates'
                return original(xml,version,flags,*rest)
            eric.process=validation_only
            variants=('','settled','valuation','off-balance','assets','tax-assets','carry') if args.subsidiary_club else ('','assets','tax-assets','fully','carry','reversal','loss','club-capital','club-capital-carry','carry-offset-zero','carry-offset-gain','carry-offset-loss','carry-offset-cross','transfer-cross','transfer-taxable','transfer-cross-reverse','transfer-taxable-reverse','shared-assets','shared-assets-tax','shared-assets-carry','shared-assets-funding','shared-assets-funding-carry','shared-assets-gwg','shared-assets-gwg-carry','shared-assets-degressive','shared-assets-degressive-carry')+(('capital','tax-assets-all') if args.whole_club else ())
            if args.shared_disposals:
                variants=(tuple('shared-disposal-'+k for k in ('scrap','sale-gain','sale-loss','software','partial','partial-tax','sale-loss-tax','software-tax')) if args.subsidiary_club else tuple('shared-disposal-'+k+s for k in ('scrap','gwg-scrap','degressive-scrap','sale-gain','sale-loss','sale-exempt','software','partial','partial-tax','scrap-tax','sale-gain-tax','sale-loss-tax','software-tax') for s in ('','-carry')))
            if args.revisions:variants=('E','B','G','BG','sK')
            for variant in variants:
                values=cases.fixture('' if args.revisions else variant);mapping,profile=cases.inputs(values)
                if args.revisions:
                    source=None if variant=='E' else {'filing_id':'a'*32,'attempt_id':'b'*32,'xml_sha256':'c'*64,'transfer_ticket':'synthetic-ticket','journal_sha256':'d'*64,'approval_sha256':'e'*64}
                    profile['filing_revision']={'status':variant,'reason':'Synthetic reviewed revision','legal_basis':'' if variant=='E' else 'Synthetic reviewed permission','confirmed':True,'source':source,'journal_sha256':'f'*64,'identity_sha256':'1'*64}
                generated=EbilanzBuilder().build(values,mapping,profile,cfg['manufacturer_id'])
                response=eric.run(generated['xml'],'Bilanz_6_9')
                result={'case':variant or 'two-businesses-four-spheres','code':response['code'],'sent':False,'transport_attempted':False,'xml_sha256':generated['xml_sha256'],'journal_sha256':values['journal_sha256'],'asset_items':generated['asset_items']}
                results.append(result);print(json.dumps(result),flush=True)
                if response['code']!=0:raise RuntimeError('Native validation failed: '+str(response.get('message',''))[:1600]+' '+str(response.get('response_xml',''))[:16000])
        finally:eric.end()
    if args.report:args.report.write_text(json.dumps({'passed':True,'tests':results,'validation_only':True},indent=2)+'\n',encoding='utf8')
