"""Compile private eXML form metadata from licensed official XSDs, never example values.

This compiler is source-controlled; its SDK inputs and generated catalogs stay private.
Unsupported schema constructs fail explicitly instead of dropping fields.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
from xml.etree import ElementTree as ET

XS = '{http://www.w3.org/2001/XMLSchema}'
ANNUAL = [('kst','KSt','E30','KSt_30','Körperschaftsteuer'),
          ('euer','EUER','E77','EUER','Einnahmenüberschussrechnung'),
          ('ust','USt','E50','USt','Umsatzsteuer'),
          ('gewst','GewSt','E20','GewSt','Gewerbesteuer')]
PERIODIC = [('ustva','UStVA','Anmeldungssteuern','Umsatzsteuer-Voranmeldung / Dauerfristverlängerung'),
            ('lsta','LStA','Anmeldungssteuern','Lohnsteuer-Anmeldung'),
            ('foreign_artist','StAbS50a','StAbS50a','Steuerabzug nach § 50a EStG')]
MESSAGES = [('receipts','Belegnachreichung','Belegnachreichung',24,'Belegnachreichung','Belegnachreichung','Nachweise einreichen',3),
            ('receipts','Belegnachreichung','Belegnachreichung',25,'Belegnachreichung','Belegnachreichung','Nachweise einreichen (nur Test)',4),
            ('message','SonstigeNachrichten','Eing_sonstNachr',23,'Nachricht','SonstigeNachrichten','Sonstige Nachricht',4),
            ('message','Fristverlaengerung','Eing_Fristverl',22,'Fristverlaengerung','Fristverlaengerung','Fristverlängerung',3)]


def documentation(node):
    return ' '.join(' '.join(n.itertext()).strip() for n in node.findall(XS+'annotation/'+XS+'documentation'))


def compile_schema(path, root_name):
    content=path.read_bytes()
    if b'<!DOCTYPE' in content or b'<!ENTITY' in content:
        raise ValueError('Unexpected DTD')
    schema=ET.fromstring(content)
    declarations={n.get('name'):n for n in schema if n.get('name')}
    root=declarations[root_name]
    types={}
    def compile_type(name, stack=()):
        name=name.split(':')[-1]
        if name in types:
            return
        if name in stack or len(stack)>40:
            raise ValueError('Recursive schema type: '+name)
        node=declarations.get(name)
        if node is None:
            if name in ('string','integer','nonNegativeInteger','positiveInteger','decimal','boolean','date','anyURI'):
                types[name]={'simple':True,'base':name};return
            raise ValueError('Unknown type: '+name)
        if any(node.find(XS+k) is not None for k in ('choice','all','group','complexContent')):
            raise ValueError('Unsupported compositor: '+name)
        seq=node.find(XS+'sequence')
        out={'label':documentation(node),'simple':seq is None,'attributes':{}}
        for attr in node.findall(XS+'attribute'):
            if attr.get('fixed') is not None:
                out['attributes'][attr.get('name')]=attr.get('fixed')
            elif attr.get('use')=='required':
                raise ValueError('Required non-fixed attribute: '+name)
        if seq is not None:
            out['children']=[]
            for child in seq:
                if child.tag!=XS+'element' or not child.get('name') or not child.get('type'):
                    raise ValueError('Unsupported child structure: '+name)
                limit=child.get('maxOccurs','1')
                item={'name':child.get('name'),'type':child.get('type').split(':')[-1],
                      'label':documentation(child) or child.get('name'),
                      'min':int(child.get('minOccurs','1')),
                      'max':1000 if limit=='unbounded' else int(limit)}
                compile_type(item['type'],stack+(name,))
                out['children'].append(item)
        else:
            restriction=node.find(XS+'restriction')
            if restriction is None:
                restriction=node.find(XS+'simpleContent/'+XS+'restriction')
            if restriction is None:
                restriction=node.find(XS+'simpleContent/'+XS+'extension')
            if restriction is not None:
                base=restriction.get('base','xs:string').split(':')[-1]
                compile_type(base,stack+(name,))
                out={**types[base],**out}
                enum=restriction.findall(XS+'enumeration')
                if enum:
                    out['enum']=[{'value':e.get('value'),'label':documentation(e) or e.get('value')} for e in enum]
                for key in ('minLength','maxLength','minInclusive','maxInclusive','totalDigits','fractionDigits'):
                    e=restriction.find(XS+key)
                    if e is not None:out[key]=e.get('value')
                patterns=restriction.findall(XS+'pattern')
                if patterns:out['patterns']=[e.get('value') for e in patterns]
            else:
                raise ValueError('No scalar definition: '+name)
        types[name]=out
    root_type=root.get('type').split(':')[-1]
    compile_type(root_type)
    return {'namespace':schema.get('targetNamespace'),'root_name':root_name,'root_type':root_type,
            'types':types,'schema_sha256':hashlib.sha256(content).hexdigest()}


def main():
    os.umask(0o077)
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--documentation',type=Path,required=True)
    p.add_argument('--output',type=Path,required=True)
    p.add_argument('--years',nargs='+',type=int,default=[2025])
    p.add_argument('--periodic-years',nargs='*',type=int,default=[])
    p.add_argument('--messages',action='store_true')
    p.add_argument('--registration',action='store_true')
    args=p.parse_args(); profiles={}; failures=[]
    for year in args.years:
        for family,folder,root,prefix,label in ANNUAL:
            source=args.documentation/'Datenarten/ElsterErklaerung'/folder/'Schema'/str(year)/(root+'-'+str(year)+'.xsd')
            if not source.is_file():
                failures.append({'family':family,'year':year,'reason':'official_schema_missing'});continue
            try:
                form=compile_schema(source,root)
            except (ValueError,KeyError) as exc:
                failures.append({'family':family,'year':year,'reason':str(exc)});continue
            version=prefix+'_'+str(year)
            profiles[version]={**form,'family':family,'label':label+' '+str(year),'year':year,
                'data_art':folder,'verfahren':'ElsterErklaerung','vorgang':'send-Auth',
                'test_marker':'700000004','payload_tag':'{'+form['namespace']+'}'+root,
                'pdf_supported':True,'transport_enabled':False}
    for year in args.periodic_years:
        for family,folder,root,label in PERIODIC:
            source=args.documentation/'Datenarten/ElsterAnmeldung'/folder/'Schema'/str(year)/(folder+'-'+str(year)+'.xsd')
            if not source.is_file():
                failures.append({'family':family,'year':year,'reason':'official_schema_missing'});continue
            try:
                form=compile_schema(source,root)
            except (ValueError,KeyError) as exc:
                failures.append({'family':family,'year':year,'reason':str(exc)});continue
            version=folder+'_'+str(year)
            profiles[version]={**form,'family':family,'label':label+' '+str(year),'year':year,
                'data_art':folder,'verfahren':'ElsterAnmeldung','vorgang':'send-Auth',
                'test_marker':'700000004','payload_tag':'{'+form['namespace']+'}'+root,
                'pdf_supported':True,'transport_enabled':False}
    if args.messages:
        for family,folder,prefix,release,root,data_art,label,attachments in MESSAGES:
            source=args.documentation/'Datenarten/ElsterNachricht'/folder/'Schema'/str(release)/(prefix+'-'+str(release)+'.xsd')
            try:
                form=compile_schema(source,root)
            except (ValueError,KeyError,FileNotFoundError) as exc:
                failures.append({'family':family,'release':release,'reason':str(exc)});continue
            profiles[data_art+'_'+str(release)]={**form,'family':family,'label':label+' · Version '+str(release),'year':None,
                'data_art':data_art,'verfahren':'ElsterNachricht','vorgang':'send-Auth','test_marker':'700000001' if family=='receipts' else '700000004',
                'test_only':family=='receipts' and release==25,
                'payload_tag':'{'+form['namespace']+'}'+root,'attachment_version':attachments,
                'pdf_supported':True,'transport_enabled':False}
    if args.registration:
        source=args.documentation/'Datenarten/ElsterFSE/FsEVerein/Schema/202501/FsE_Verein-202501.xsd'
        try:
            form=compile_schema(source,'FsE_Verein')
            profiles['ElsterFSE_Verein_202501']={**form,'family':'registration','label':'Steuerliche Erfassung · Verein (Stand 202501)','year':None,
                'data_art':'FsEVerein','verfahren':'ElsterFSE','vorgang':'send-Auth','test_marker':'700000004',
                'payload_tag':'{'+form['namespace']+'}FsE_Verein','attachment_version':3,
                'pdf_supported':True,'transport_enabled':False}
        except (ValueError,KeyError,FileNotFoundError) as exc:
            failures.append({'family':'registration','release':202501,'reason':str(exc)})
    payload={'version':1,'profiles':profiles,'unavailable':failures}
    args.output.parent.mkdir(parents=True,exist_ok=True)
    args.output.write_text(json.dumps(payload,ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps({'compiled':list(profiles),'unavailable':failures},ensure_ascii=True))
    return 1 if failures else 0


if __name__=='__main__':
    raise SystemExit(main())
