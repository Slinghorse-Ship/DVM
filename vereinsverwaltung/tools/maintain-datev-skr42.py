#!/usr/bin/env python3
"""Central annual SKR42 maintenance. Creates review packages, never deploys them.

verify  : reproduce checked runtime rules from immutable annual purpose editions
compare : compare an imported candidate, then open review.html in a browser
prepare : import a downloaded source-check manifest and produce the same review
approve : validate the downloaded review and build a new, isolated candidate package

No database, mail, bank or ELSTER connection. A complete mapping is not a tax-filing
approval. Unsupported target years/taxonomies remain blocked even with a new PDF.
"""
from __future__ import annotations
import argparse
import datetime as dt
import json
from pathlib import Path
import sys
import subprocess
import tempfile
import re
import hashlib

import datev_years as d


def existing_editions(model):
    return d.load_editions(model)


def package(target,items):
    """A fresh directory, outside runtime resources. Existing artifacts never move."""
    target=Path(target).resolve()
    for active in ('data','resources','public','src','views'):
        path=(d.ROOT/active).resolve()
        if target==path or path in target.parents:raise ValueError('Prüfpaket außerhalb der aktiven Programmverzeichnisse speichern.')
    target.parent.mkdir(parents=True,exist_ok=True)
    target.mkdir(exist_ok=False)
    for name,value in items.items():
        if name=='manifest.json':continue
        path=target/name
        if isinstance(value,(str,bytes)):
            with path.open('xb') as file:file.write(value.encode('utf-8') if isinstance(value,str) else value)
        else:d.write_new(path,value)
    if 'manifest.json' in items:
        # The completion marker is written last and binds every delivered file.
        manifest=items['manifest.json']|{'files':{name:hashlib.sha256((target/name).read_bytes()).hexdigest()
            for name in items if name!='manifest.json'}}
        d.write_new(target/'manifest.json',manifest)
    return target


def prepare_source(args,current=None):
    manifest,_=d.read_json(args.manifest)
    row=manifest.get('editions',{}).get('balance',{})
    if row.get('state')!='found' or not d.official_url(row.get('download_url')):
        raise ValueError('Geprüfter DATEV-Bilanz-Download fehlt im Quellbericht.')
    years=re.findall(r'\b20\d{2}\b',row.get('edition') or '')
    if len(years)!=1:raise ValueError('Jahresangabe der DATEV-Quelle ist nicht eindeutig.')
    year=int(years[0]);pdf=Path(row.get('pending_pdf') or '')
    if not pdf.is_file() or pdf.stat().st_size>32*1024*1024:raise ValueError('Heruntergeladenes Original-PDF fehlt oder ist zu groß.')
    raw=pdf.read_bytes()
    if not raw.startswith(b'%PDF-') or hashlib.sha256(raw).hexdigest()!=row.get('pdf_sha256'):
        raise ValueError('Original-PDF stimmt nicht mit dem Quellbericht überein.')
    if current and year==current['year']:
        if row['pdf_sha256']==current['source']['pdf_sha256']:return None,None,None,None
        raise ValueError('Geänderte Quelle im bereits verwendeten Jahr. Separate Revisionsprüfung erforderlich; historischen Katalog nicht ersetzen.')
    if current and year!=current['year']+1:raise ValueError('Aufeinanderfolgende Jahreskataloge erforderlich.')
    with tempfile.TemporaryDirectory(prefix='skr42-candidate-') as temp:
        command=[sys.executable,str(d.ROOT/'tools/import-datev-catalog.py'),str(pdf),'--year',str(year),
            '--edition','balance','--source-url',row['download_url'],'--expected-sha256',row['pdf_sha256'],'--output-dir',temp]
        if args.appendix_pages is not None:command+=['--appendix-pages',str(args.appendix_pages)]
        result=subprocess.run(command,capture_output=True,encoding='utf-8',errors='replace',timeout=180)
        if result.returncode:raise ValueError('PDF-Import benötigt Prüfung: '+result.stderr[-1500:])
        candidate=Path(temp)/f'skr42-{year}.json'
        data,raw_hash=d.read_json(candidate)
        return data,raw_hash,candidate.read_bytes().replace(b'\r\n',b'\n'),raw


def summary(report):
    return {key:value for key,value in report.items() if key!='missing'}|{'missing_count':len(report['missing'])}


def run(args):
    model,_=d.read_json(d.ROOT/'resources/accounting/purposes.json')
    editions,catalogs=existing_editions(model)
    # After a reviewed taxonomy transition, old years retain their old runtime
    # registry. Verify each group independently instead of coercing all years
    # into the latest taxonomy or preventing the next annual comparison.
    registries={};compiled_by_taxonomy={}
    for version in sorted({e['taxonomy'] for e in editions}):
        installed,_=d.read_json(d.ROOT/f'resources/ebilanz/skr42-{version}.json')
        compiled=d.compile_registry(model,[e for e in editions if e['taxonomy']==version],catalogs,installed['revision'])
        if not d.runtime_equivalent(installed,compiled):raise ValueError('Zweckregister und aktive Laufzeitregeln stimmen nicht überein.')
        registries[version]=installed;compiled_by_taxonomy[version]=compiled
    current=registries[max(editions,key=lambda e:e['year'])['taxonomy']]
    if args.command=='enrich':
        enriched,_=d.read_json(args.candidate)
        if enriched['year'] not in catalogs:raise ValueError('Historischer Jahreskatalog fehlt.')
        original,old_hash=catalogs[enriched['year']]
        supplement=d.statements.supplement(original,old_hash,enriched)
        output=package(args.output_dir,{'positions.json':supplement})
        return {'status':'source_supplement','directory':str(output),'year':enriched['year'],
                'positions':d.statements.report(enriched,d.catalog(enriched)),
                'historical_catalog_unchanged':True,'production_authorized':False}
    if args.command=='verify':
        return {'status':'ok','runtime_rules_unchanged':True,'purposes':len(model['purposes']),
                'years':{str(e['year']):summary(d.coverage(model,e,catalogs[e['year']][0])) for e in editions},
                'statement_positions':{str(e['year']):d.statements.report(catalogs[e['year']][0],d.catalog(catalogs[e['year']][0])) for e in editions},
                'production_authorized':False}
    original=None
    if args.command=='prepare':
        current_year=next((e for e in editions if e['year']==args.from_year),None)
        if current_year is None:raise ValueError('Geprüfter Ausgangsstand fehlt.')
        new,new_hash,candidate_bytes,original=prepare_source(args,current_year)
        if new is None:return {'status':'up_to_date','year':args.from_year,'files_changed':False,'production_authorized':False}
    else:
        new,new_hash=d.read_json(args.candidate)
        candidate_bytes=args.candidate.read_bytes().replace(b'\r\n',b'\n')
    if args.command in ('compare','prepare'):
        from_year=args.from_year;taxonomy=args.taxonomy or d.ebilanz_release.catalog_taxonomy(new['year'])
        if taxonomy is None:raise ValueError('Für dieses Jahr fehlt eine ausdrücklich geprüfte Taxonomieauswahl.')
    else:
        plan,_=d.read_json(args.plan);d.verify(plan)
        from_year=plan['from_year'];taxonomy=plan['taxonomy']
    baseline=next((e for e in editions if e['year']==from_year),None)
    if baseline is None:raise ValueError('Geprüfter Vorjahresstand fehlt.')
    old,old_hash=catalogs[from_year]
    if args.command in ('compare','prepare'):
        plan=d.compare(model,baseline,old,old_hash,new,new_hash,taxonomy)
        files={'comparison.json':plan,'review.json':d.review_template(plan),'catalog.json':candidate_bytes,
            'review.html':d.html_report(plan,model)}
        if original is not None:files['original.pdf']=original
        output=package(args.output_dir,files)
        return {'status':'review_required','directory':str(output),'counts':plan['counts'],
            'automatic':len(plan['automatic']),'review_groups':len(plan['review_groups']),
            'existing_gaps':len(plan['existing_gaps']),'taxonomy_ready':plan['taxonomy_ready'],
            'filing_package':plan['filing_package'],
            'statement_positions':plan['statement_coverage'],
            'production_authorized':False}
    review,_=d.read_json(args.review)
    edition=d.approve(model,baseline,old,old_hash,new,new_hash,plan,review)
    selected=[e for e in editions if e['year']!=edition['year'] and e['taxonomy']==taxonomy]+[edition]
    selected_catalogs=catalogs|{edition['year']:(new,new_hash)}
    revision=registries.get(taxonomy,current)['revision']+1
    registry=d.compile_registry(model,selected,selected_catalogs,revision)
    # No candidate may alter any other year's effective account rules.
    for old_edition in editions:
        year=old_edition['year']
        if year==edition['year']:continue
        original=registries[old_edition['taxonomy']]
        a=original|{'sources':{str(year):original['sources'][str(year)]}}
        preserved=registry if old_edition['taxonomy']==taxonomy else compiled_by_taxonomy[old_edition['taxonomy']]
        b=preserved|{'sources':{str(year):preserved['sources'][str(year)]}}
        if not d.runtime_equivalent(a,b):raise ValueError('Kandidat würde historische Laufzeitregeln verändern.')
    report=d.coverage(model,edition,new)
    if d.ebilanz_release.status(taxonomy,edition['year'])!=plan['filing_package']:
        raise ValueError('Jahrespaket während der Paketerstellung geändert. Erneut prüfen.')
    manifest={'schema':1,'created_at':dt.datetime.now(dt.timezone.utc).isoformat(),
        'state':'reviewed_candidate' if report['complete'] else 'incomplete_candidate','chart':'SKR42','edition':edition['edition'],'year':edition['year'],
        'edition_sha256':edition['sha256'],'source':edition['source'],'coverage':report,
        'filing_package':plan['filing_package'],
        'replaces_existing_year':edition['year'] in catalogs,
        'historical_bookings_unchanged':True,'production_authorized':False,
        'requires':([] if report['complete'] else ['resolve_unmapped_accounts'])+['regression_tests','tax_year_and_taxonomy_acceptance','explicit_program_release']}
    if not plan['filing_package'].get('native_validation_ready',False):
        manifest['requires']+=['output_adapter_acceptance','native_eric_acceptance','official_test_feedback']
    items={'edition.json':edition,'skr42-'+taxonomy+'.json':registry,
        'catalog.json':candidate_bytes,
        'purposes.json':model,'manifest.json':manifest,'review.json':review,'comparison.json':plan}
    for version in registries:
        if version==taxonomy:continue
        # Keep the historical registry as a separate unchanged package. A mixed
        # taxonomy registry would relabel old facts with the future schema.
        items['skr42-'+version+'.json']=(d.ROOT/f'resources/ebilanz/skr42-{version}.json').read_bytes().replace(b'\r\n',b'\n')
    if taxonomy not in registries:
        items['taxonomy-'+taxonomy+'.json']=(d.ROOT/f'resources/ebilanz/taxonomy-{taxonomy}.json').read_bytes().replace(b'\r\n',b'\n')
        projection=model.get('taxonomy_projections',{}).get(taxonomy)
        if projection:items['taxonomy-projection-'+taxonomy+'.json']=(d.ROOT/'resources/accounting'/projection['file']).read_bytes().replace(b'\r\n',b'\n')
    output=package(args.output_dir,items)
    return {'status':manifest['state'],'directory':str(output),'coverage':summary(report),'production_authorized':False}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    commands=parser.add_subparsers(dest='command',required=True)
    commands.add_parser('verify',help='Geprüfte Jahresstände und Laufzeitregeln vergleichen')
    enrich=commands.add_parser('enrich',help='Gedruckte Abschlussposten ergänzen; historische Kataloge unverändert lassen')
    enrich.add_argument('--candidate',type=Path,required=True)
    enrich.add_argument('--output-dir',type=Path,required=True)
    compare=commands.add_parser('compare',help='Prüfpaket für einen importierten Jahreskatalog erzeugen')
    compare.add_argument('--from-year',type=int,required=True)
    compare.add_argument('--taxonomy',help='Explizite Zielversion; sonst geprüfte Jahresauswahl')
    prepare=commands.add_parser('prepare',help='DATEV-Download importieren und Jahresvergleich vorbereiten')
    prepare.add_argument('--manifest',type=Path,required=True,help='Quellbericht von check-datev-skr42.py mit --download-dir')
    prepare.add_argument('--from-year',type=int,required=True)
    prepare.add_argument('--taxonomy',help='Explizite Zielversion; sonst geprüfte Jahresauswahl')
    prepare.add_argument('--appendix-pages',type=int,help='Geprüfte Anhangseiten der neuen PDF-Quelle')
    prepare.add_argument('--output-dir',type=Path,required=True)
    approve=commands.add_parser('approve',help='Prüfdatei prüfen und Kandidatenpaket erzeugen')
    approve.add_argument('--plan',type=Path,required=True)
    approve.add_argument('--review',type=Path,required=True)
    for command in (compare,approve):
        command.add_argument('--candidate',type=Path,required=True)
        command.add_argument('--output-dir',type=Path,required=True,help='Neuer, noch nicht vorhandener Ordner')
    args=parser.parse_args()
    try:
        result=run(args)
    except (ValueError,KeyError,TypeError,OSError,subprocess.TimeoutExpired) as error:
        print(json.dumps({'status':'blocked','error':str(error),'production_authorized':False},ensure_ascii=False))
        return 2
    print(json.dumps(result,ensure_ascii=False,indent=2))
    return 0


if __name__=='__main__':
    sys.exit(main())
