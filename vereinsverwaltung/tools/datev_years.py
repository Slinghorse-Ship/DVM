"""Versioned account purposes and annual change review; no database or transport.

The checked runtime registry is a build artifact. Historic editions pin immutable
purpose revisions, source bytes and taxonomy versions. A candidate never activates
itself. Similar labels, renumbering and changed ranges are NOT automatic matches.
"""
from __future__ import annotations
import copy
import hashlib
import html
import json
from pathlib import Path
import re
from functools import lru_cache
from urllib.parse import urlparse
import datev_statements as statements
import ebilanz_release
import taxonomy_purposes

ROOT=Path(__file__).resolve().parent.parent
LEGACY_SOURCES={
    2025:('dcf8080446f4fc0ddb1d67409fdabc3689dab598ac669d2330322f895402e69d','fdf6151e9a03631aa9c56b85324321d4b5aee4c1eedd31f8cc0c661f9c6302ab'),
    2026:('643c9a0c764b5fa694e10c3a3239faafa8d28d193dba377f43a964c59f89fbe9','66c6be79a64c2294fcafd67c9a094d18c4b59e01a094b2968343d062d3048225'),
}

def digest(value):
    return hashlib.sha256(json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()).hexdigest()

def read_json(path):
    raw=Path(path).read_bytes().replace(b'\r\n',b'\n')
    return json.loads(raw),hashlib.sha256(raw).hexdigest()

def seal(value):
    return value|{'sha256':digest(value)}

def verify(value):
    if not isinstance(value,dict) or value.get('sha256')!=digest({k:v for k,v in value.items() if k!='sha256'}):
        raise ValueError('Prüfstand wurde verändert. Vergleich erneut erstellen.')

def text(value):
    if not isinstance(value,str):raise ValueError('Ungültiger Quelltext.')
    return ' '.join(value.split())

def semantic_text(value):
    """Typography and reviewed orthography, never digits/rates/negation or guesses.

    PDF ligatures/soft hyphens and line wrapping are presentation differences.
    The unmodified source text remains available in each review and catalog.
    """
    return statements.normalized(value)

def semantic_key(row):
    result=copy.deepcopy(row)
    result['name']=semantic_text(result['name'])
    if result.get('parent') and 'name' in result['parent']:
        result['parent']['name']=semantic_text(result['parent']['name'])
    if result.get('statement'):
        result['statement']['label']=semantic_text(result['statement']['label'])
    return result

def code(value):
    if not isinstance(value,str) or not re.fullmatch(r'[0-9]{5}',value):raise ValueError('Ungültige Kontonummer.')
    return value

def official_url(url):
    if not isinstance(url,str):return False
    try:
        parsed=urlparse(url);host=(parsed.hostname or '').lower();port=parsed.port
    except ValueError:return False
    return parsed.scheme=='https' and not parsed.username and not parsed.password and port in (None,443) and any(
        host==domain or host.endswith('.'+domain) for domain in ('datev.de','datev-shop.de'))

def source(data,raw_hash,edition):
    if type(data.get('year')) is not int or not 2000<=data['year']<=2100 or edition not in ('balance','euer'):
        raise ValueError('Jahr und Kontenplanvariante prüfen.')
    legacy=edition=='balance' and LEGACY_SOURCES.get(data['year'])==(data.get('sha256'),raw_hash)
    if not legacy and (data.get('edition')!=edition or data.get('chart')!='SKR42'):
        raise ValueError('Kontenrahmen und Variante fehlen oder stimmen nicht überein. EÜR und Bilanz nicht vermischen.')
    if not re.fullmatch('[a-f0-9]{64}',data.get('sha256','')) or not re.fullmatch('[a-f0-9]{64}',raw_hash):
        raise ValueError('Original-PDF und Katalog-Prüfsumme fehlen.')
    if not official_url(data.get('source')):raise ValueError('Offizielle DATEV-Quellangabe fehlt.')
    return {'year':data['year'],'edition':edition,'pdf_sha256':data['sha256'],'catalog_sha256':raw_hash,'url':data.get('source','')}

def catalog(data):
    """Exact semantic rows, including the parent of '- Restlaufzeit' rows."""
    if not isinstance(data.get('ranges'),list) or not isinstance(data.get('accounts'),list):raise ValueError('Katalog unvollständig.')
    reserved=[];contexts={};parent=None
    for r in data['ranges']:
        start,end=code(r['code']),code(r['end']);name=text(r['name'])
        function=text(r.get('function',''))
        if start>end and not r.get('range_issue'):raise ValueError('Ungültiger Quellbereich.')
        if function=='R':reserved.append((start,end))
        if re.match(r'^[-–]\s',name):
            key=(start,end,name);context=copy.deepcopy(parent) if parent else {'missing':True}
            contexts[key]=context if key not in contexts or contexts[key]==context else {'missing':True}
        else:parent={'code':start,'end':end,'name':name,'function':function}
    rows={}
    for a in data['accounts']:
        number=code(a['code'])
        if number in rows:raise ValueError('Doppelte Kontonummer im Katalog.')
        name=text(a['name']);start,end=code(a['range_start']),code(a['range_end'])
        if not start<=number<=end and not a.get('range_issue'):raise ValueError('Konto liegt außerhalb seines Quellbereichs.')
        blocked=text(a.get('function',''))=='R' or any(k in a for k in ('alternatives','range_issue')) or any(lo<=number<=hi for lo,hi in reserved)
        context=contexts.get((start,end,name)) if re.match(r'^[-–]\s',name) else None
        if re.match(r'^[-–]\s',name) and context is None:context={'missing':True}
        semantic={'name':name,'function':text(a.get('function','')),'range_start':start,'range_end':end,'parent':context}
        if data.get('statement_schema')==1:
            semantic['statement']=statements.meaning(a.get('statement'))
        rows[number]={'code':number,'name':name,'bookable':not blocked and not number.startswith('9'),
                      'semantic':semantic,'signature':digest(semantic_key(semantic)),'page':a.get('page'),
                      'uncertain_context':bool(context and context.get('missing'))}
    return rows

def posting_catalog(data):
    from datev_comparison import apply
    return apply(data,catalog(data))

def edition_catalog(data,edition):
    if "comparison_review" not in edition:return catalog(data)
    from datev_comparison import review
    if edition["comparison_review"]!=review(data):raise ValueError("Geprüfte Vergleichsklassifikation stimmt nicht überein.")
    return posting_catalog(data)

def definition(model,ref):
    try:
        purpose,version=ref.split('@');entry=model['purposes'][purpose]['versions'][version]
    except (KeyError,ValueError,AttributeError):raise ValueError('Unbekannter Kontenzweck: '+str(ref)) from None
    return entry

def mapping(model,ref,taxonomy,projection=None):
    d=definition(model,ref)
    rule=d['mappings'].get(taxonomy)
    if rule is None:
        rule=taxonomy_purposes.projected_rule(d,ref,projection or taxonomy_purposes.load(model,taxonomy))
    if rule is None:raise ValueError('Kontenzweck hat keine geprüfte Zielversion: '+ref)
    rule=copy.deepcopy(rule)
    if rule['group']!=d['group'] or rule['label']!=d['label']:raise ValueError('Kontenzweck und Zielregel widersprechen sich.')
    return rule

def validate_group(model,ref,number):
    # A coarse rejection guard, NEVER a rule to infer a purpose or tax treatment.
    groups={'0':{'fixed_assets'},'1':{'inventory','receivables','other_assets','cash','prepaid'},
            # Class 2 also contains original special posts and unspent donation
            # funds (298xx/2996x/2997x). Their reviewed rules use a passive
            # balance group; capital treatment would corrupt the capital
            # comparison. This remains a rejection guard, never a mapping.
            '2':{'equity','reserves','deferred'},'3':{'provisions','liabilities','deferred'},
            '4':{'revenue'},'5':{'expense'},'6':{'expense'},'7':{'revenue','expense'}}
    entry=definition(model,ref)
    if entry['group']=='result_use':
        from ebilanz_income_use import validate_rule
        rule=entry['mappings'].get('6.9',{});validate_rule(rule)
        if rule.get('income_use_contract')==number+'@1' or rule.get('tax_equalization_contract')==number+'@1':return
    if number in ('13800','13820') and entry['group']=='fixed_assets':
        from ebilanz_financial_profile import validate_rule
        rule=entry['mappings'].get('6.9',{});validate_rule(rule)
        if rule.get('pension_asset_contract')=='original-unnetted-reinsurance-r74-'+number+'@1':return
    if number=='18950' and entry['group']=='liabilities':
        from ebilanz_directional_closing import validate_rule
        rule=entry['mappings'].get('6.9',{});validate_rule(rule)
        if rule.get('directional_balance')=={'debit_targets':['bs.ass.currAss.cashEquiv.bank'],'credit_targets':['bs.eqLiab.liab.bank.other']}:return
    if entry['group'] not in groups.get(code(number)[0],set()):
        raise ValueError('Kontenzweck widerspricht der Kontenklasse: '+number)

def latest(model,purpose,taxonomy,projection=None):
    versions=model['purposes'][purpose]['versions']
    projection=projection or taxonomy_purposes.load(model,taxonomy)
    found=[v for v in versions if taxonomy in versions[v]['mappings'] or
           taxonomy_purposes.projected_rule(versions[v],purpose+'@'+v,projection) is not None]
    return purpose+'@'+max(found,key=int) if found else None

def bindings(snapshot):
    verify(snapshot);result={}
    for ref,codes in snapshot['bindings'].items():
        for number in codes:
            code(number)
            if number in result:raise ValueError('Mehrere Kontenzwecke für ein Konto.')
            result[number]=ref
    return result

def validate_snapshot(snapshot,model,data,raw_hash):
    verify(snapshot)
    if snapshot.get('chart')!='SKR42' or snapshot.get('edition')!=model.get('edition') or model.get('chart')!='SKR42':
        raise ValueError('Kontenrahmen oder Variante stimmt nicht überein.')
    if snapshot['source']!=source(data,raw_hash,snapshot['edition']):raise ValueError('Historischer Katalog wurde verändert.')
    if snapshot['year']!=data['year']:raise ValueError('Katalogjahr stimmt nicht überein.')
    rows=edition_catalog(data,snapshot);bound=bindings(snapshot);projection=taxonomy_purposes.load(model,snapshot['taxonomy'])
    for ref in snapshot['bindings']:
        if snapshot['definitions'].get(ref)!=digest(definition(model,ref)):raise ValueError('Historischer Kontenzweck wurde verändert: '+ref)
        mapping(model,ref,snapshot['taxonomy'],projection)
    for number in bound:
        if number not in rows or not rows[number]['bookable']:raise ValueError('Gesperrtes oder fehlendes Konto ist zugeordnet.')
        validate_group(model,bound[number],number)
    return bound

def snapshot(model,data,raw_hash,taxonomy,bound,provenance):
    rows=posting_catalog(data);groups={};projection=taxonomy_purposes.load(model,taxonomy)
    for number,ref in sorted(bound.items()):
        if number not in rows or not rows[number]['bookable']:raise ValueError('Gesperrtes Konto kann nicht freigegeben werden.')
        mapping(model,ref,taxonomy,projection);validate_group(model,ref,number);groups.setdefault(ref,[]).append(number)
    result={'schema':1,'chart':'SKR42','edition':model['edition'],'year':data['year'],'taxonomy':taxonomy,
            'source':source(data,raw_hash,model['edition']),'bindings':groups,
            'definitions':{ref:digest(definition(model,ref)) for ref in groups},'provenance':provenance}
    from datev_comparison import review
    marker=review(data)
    if marker is not None:result["comparison_review"]=marker
    return seal(result)

def load_editions(model,directory=None):
    """Load explicitly selected additive revisions, retaining every sealed base.

    A new source or changed existing meaning requires its own reviewed migration;
    this path only fills previously unbound accounts in the same original source.
    Merely dropping a newer-looking file into the directory never activates it.
    """
    directory=Path(directory or ROOT/'resources/accounting/years')
    selection_path=directory/'active.json'
    selection=read_json(selection_path)[0] if selection_path.is_file() else {}
    if selection_path.is_file() and (not isinstance(selection,dict) or selection.get('schema')!=1 or set(selection)!={'schema','years'} or not isinstance(selection['years'],dict)):
        raise ValueError('Ungültige Auswahl der Jahresrevisionen.')
    editions=[];catalogs={};seen=set()
    for path in sorted(directory.glob('[0-9][0-9][0-9][0-9].json')):
        base,_=read_json(path);year=int(path.stem);seen.add(str(year))
        if base['year']!=year:raise ValueError('Jahresdatei falsch benannt.')
        catalogs[year]=read_json(ROOT/f'data/skr42-{year}.json')
        validate_snapshot(base,model,*catalogs[year]);edition=base
        selected=selection.get('years',{}).get(str(year),[])
        if not isinstance(selected,list):raise ValueError('Revisionskette erforderlich.')
        used=set()
        for item in selected:
            if not isinstance(item,dict) or set(item)!={'file','sha256'} or not re.fullmatch(str(year)+r'-r[1-9][0-9]*\.json',item.get('file','')) or item['file'] in used:
                raise ValueError('Ungültiger Revisionsverweis.')
            used.add(item['file']);new,_=read_json(directory/item['file'])
            validate_snapshot(new,model,*catalogs[year])
            if new['sha256']!=item['sha256'] or new['source']!=base['source'] or new['taxonomy']!=base['taxonomy'] or new['year']!=year:
                raise ValueError('Jahresrevision und Quelle widersprechen sich.')
            previous=bindings(edition);current=bindings(new);proof=new['provenance']
            if proof.get('previous_sha256')!=edition['sha256'] or not text(proof.get('reason','')):
                raise ValueError('Nachweis der ergänzenden Kontenrevision fehlt.')
            if 'comparison_review' in edition and new.get('comparison_review')!=edition['comparison_review']:raise ValueError('Geprüfte Vergleichsklassifikation muss in Folgerevisionen erhalten bleiben.')
            if proof.get('kind')=='reviewed_comparison_only_correction':
                from datev_comparison import validate_revision
                validate_revision(previous,current,proof,new.get('comparison_review'),catalogs[year][0])
            elif proof.get('kind')=='reviewed_bank_subposition_correction':
                from datev_bank_corrections import validate as validate_bank_correction
                validate_bank_correction(model,previous,current,catalog(catalogs[year][0]),proof)
                if len(current)<=len(previous):raise ValueError('Ergänzende Kontenrevision benötigt neue Originalkonten.')
            elif proof.get('kind')=='reviewed_loan_counter_extension':
                from datev_loan_corrections import validate as validate_loan_correction
                validate_loan_correction(model,previous,current,catalog(catalogs[year][0]),proof)
            elif proof.get('kind')=='reviewed_issuer_classification_correction':
                from datev_issuer_corrections import validate as validate_issuer_correction
                validate_issuer_correction(model,previous,current,catalog(catalogs[year][0]),proof)
            elif proof.get('kind')!='additive_mapping_revision' or any(current.get(number)!=ref for number,ref in previous.items()) or len(current)<=len(previous):
                raise ValueError('Ergänzende Revision darf bestehende Kontenzwecke nicht verändern.')
            edition=new
        editions.append(edition)
    if not editions or set(selection.get('years',{}))-seen:raise ValueError('Geprüfter Ausgangsstand fehlt.')
    return editions,catalogs

def targets_ready(model,taxonomy,year):
    registration=model.get('taxonomies',{}).get(taxonomy)
    if not registration or year not in registration['years']:return False
    try:package=ebilanz_release.package(taxonomy,year)
    except ValueError:return False
    return package['taxonomy']['sha256']==registration['sha256']

@lru_cache(maxsize=4)
def target_builder(taxonomy,taxonomy_hash):
    from ebilanz_builder import EbilanzBuilder
    builder=EbilanzBuilder(taxonomy)
    if hashlib.sha256(builder.taxonomy_bytes.replace(b'\r\n',b'\n')).hexdigest()!=taxonomy_hash:
        raise ValueError('Zielprüfung und amtliche Taxonomiequelle stimmen nicht überein.')
    return builder

def target_validator(model,taxonomy,year):
    if not targets_ready(model,taxonomy,year):raise ValueError('Zieljahr und E-Bilanz-Version benötigen eine geprüfte Programmfreigabe.')
    from ebilanz_builder import SUPPORTED
    builder=target_builder(taxonomy,model['taxonomies'][taxonomy]['sha256'])
    projection=taxonomy_purposes.load(model,taxonomy)
    def validate(ref):
        rule=mapping(model,ref,taxonomy,projection)
        if rule.get('condition')=='balance_direction':
            if (rule['group'] not in ('revenue','expense') or not isinstance(rule.get('negative_target'),str)
                or rule['negative_target']==rule['target']
                or rule.get('allowed_targets')!=[rule['target'],rule['negative_target']]):
                raise ValueError('Saldobezogene Kontenzuordnung benötigt zwei eindeutige geprüfte Ziele.')
        elif 'negative_target' in rule:
            raise ValueError('Saldobezogenes Ziel ohne zugehörige Regel.')
        from ebilanz_account_closing import validate_rule
        validate_rule(rule)
        from ebilanz_directional_closing import validate_rule as validate_direction
        validate_direction(rule)
        from ebilanz_commercial import validate_rule as validate_commercial
        validate_commercial(rule)
        from ebilanz_own_shares import validate_rule as validate_own_shares
        validate_own_shares(rule)
        from ebilanz_reserve_closing import validate_rule as validate_reserve
        validate_reserve(rule)
        from ebilanz_income_use import validate_rule as validate_income_use
        validate_income_use(rule)
        from ebilanz_financial_profile import validate_rule as validate_financial_profile
        validate_financial_profile(rule)
        from ebilanz_original_offsets import validate_rule as validate_original_offsets
        validate_original_offsets(rule)
        from ebilanz_original_depreciation import validate_rule as validate_original_depreciation
        validate_original_depreciation(rule)
        from ebilanz_exceptional_original import validate_rule as validate_exceptional_original
        validate_exceptional_original(rule)
        from ebilanz_original_reclassification import validate_rule as validate_original_split
        validate_original_split(rule)
        from ebilanz_technical_closing import validate_rule as validate_technical_closing
        validate_technical_closing(rule)
        for target in [rule['target'],*rule.get('allowed_targets',[])]:
            if rule['group'] not in ('revenue','expense') and 'directional_balance' not in rule:
                prefix='bs.ass.' if rule['group'] in ('cash','receivables','inventory','prepaid','other_assets','fixed_assets') else 'bs.eqLiab.'
                if rule['group']=='result_use':prefix='bs.eqLiab.' if rule.get('tax_equalization_contract')=='77690@1' else 'incomeUse.'
                if not target.startswith(prefix):raise ValueError('Bilanzbereich und Zielseite widersprechen sich: '+ref)
            if target not in SUPPORTED.get(rule['group'],set()) or not builder.permitted(target,year,book_only=rule.get('book_only',False)):
                raise ValueError('Nicht freigegebene E-Bilanz-Zuordnung: '+ref)
        from ebilanz_working_capital import validate as validate_disclosures
        validate_disclosures(rule,builder.meta)
        if any(not builder.permitted(t,year,book_only=rule.get('book_only',False)) for t in rule.get('disclosures',[])):
            raise ValueError('Nicht freigegebene E-Bilanz-Zusatzangabe: '+ref)
    return validate


def validate_target(model,ref,taxonomy,year):
    target_validator(model,taxonomy,year)(ref)


def compare(model,baseline,old,old_hash,new,new_hash,taxonomy):
    prior=validate_snapshot(baseline,model,old,old_hash)
    if new['year']!=old['year']+1:raise ValueError('Aufeinanderfolgende Jahreskataloge erforderlich.')
    src=source(new,new_hash,model['edition'])
    a=statements.enrich_rows(old,catalog(old));b=statements.enrich_rows(new,catalog(new))
    # Normalize typography on both the old sidecar and new embedded facts.
    for row in [*a.values(),*b.values()]:row['signature']=digest(semantic_key(row['semantic']))
    counts=dict.fromkeys(('unchanged','changed','newly_bookable','no_longer_bookable'),0)
    auto={};pending={};retired=[];legacy=[]
    filing_package=ebilanz_release.status(taxonomy,new['year'])
    ready=targets_ready(model,taxonomy,new['year'])
    check_target=target_validator(model,taxonomy,new['year']) if ready else None
    projection=taxonomy_purposes.load(model,taxonomy)
    # All members of a printed source range must already share one reviewed
    # purpose before a new member may inherit it. No numeric-range guessing.
    family={};purposes={}
    for number,row in a.items():
        if row['bookable']:family.setdefault(row['signature'],[]).append(prior.get(number))
        key=statements.purpose_key(row)
        if row['bookable'] and key and number in prior:purposes.setdefault(key,set()).add(prior[number])
    for number in sorted(set(a)|set(b)):
        before,after=a.get(number),b.get(number)
        old_ok=bool(before and before['bookable']);new_ok=bool(after and after['bookable'])
        if not new_ok:
            if old_ok:counts['no_longer_bookable']+=1;retired.append({'code':number,'name':before['name'],'reason':'removed' if after is None else 'blocked'})
            continue
        unchanged=old_ok and before['signature']==after['signature'] and not after['uncertain_context']
        status='unchanged' if unchanged else ('changed' if old_ok else 'newly_bookable');counts[status]+=1
        ref=prior.get(number) if unchanged else None
        inheritance='same_account'
        if not old_ok and not after['uncertain_context']:
            refs=family.get(after['signature'],[])
            if refs and None not in refs and len(set(refs))==1:ref=refs[0];inheritance='same_reviewed_source_range'
        # New numbers, extended ranges and existing gaps can reuse a reviewed
        # purpose ONLY when description, function, statement and closing marks
        # are identical. Conflicting old purposes never choose a winner.
        same_purpose_context=bool(old_ok and statements.purpose_key(before)
                                  and statements.purpose_key(before)==statements.purpose_key(after))
        if not ref and (unchanged or not old_ok or same_purpose_context):
            refs=purposes.get(statements.purpose_key(after),set())
            if len(refs)==1:
                inherited=next(iter(refs))
                try:validate_group(model,inherited,number)
                except ValueError:pass
                else:ref=inherited;inheritance='same_reviewed_statement_purpose'
        if ref:
            target_ref=latest(model,ref.split('@')[0],taxonomy,projection)
            # A revised purpose is itself a semantic change. Do not silently
            # upgrade it, even if the source account still has the same name.
            if ready and target_ref==ref:
                check_target(target_ref);auto[number]={'purpose':target_ref,'reason':inheritance};continue
        row={'code':number,'before':before['semantic'] if before else None,'after':after['semantic'],
             'previous_purpose':prior.get(number),'suggested_purpose':ref,'status':status,
             'review_reason':('taxonomy_change' if ref and not target_ref else 'purpose_revision') if ref else 'source_change'}
        if unchanged and number not in prior:legacy.append(row);continue
        # Changed families are reviewed once, not once for every expanded number.
        key=digest({k:v for k,v in row.items() if k!='code'})
        if key not in pending:pending[key]={k:v for k,v in row.items() if k!='code'}|{'id':key,'codes':[]}
        pending[key]['codes'].append(number)
    if ebilanz_release.status(taxonomy,new['year'])!=filing_package:raise ValueError('Jahrespaket während des Vergleichs geändert. Erneut prüfen.')
    taxonomy_purposes.load(model,taxonomy) # Recheck projection/source bytes before sealing.
    return seal({'schema':1,'chart':'SKR42','edition':model['edition'],'from_year':old['year'],'year':new['year'],
        'taxonomy':taxonomy,'taxonomy_ready':ready,'filing_package':filing_package,
        'source':src,'baseline_sha256':baseline['sha256'],
        'model_sha256':digest(model),'counts':counts,'automatic':auto,'review_groups':list(pending.values()),
        'existing_gaps':legacy,'retired':retired,
        'statement_coverage':statements.report(new,b),
        'source_notes':{'before':old.get('source_notes',[]),'after':new.get('source_notes',[]),
            'both_available':bool(old.get('source_notes')) and bool(new.get('source_notes')),
            'changed':old.get('source_notes')!=new.get('source_notes'),'previous_source':old['source']},
        'production_authorized':False})

def review_template(plan):
    verify(plan)
    return {'schema':1,'plan_sha256':plan['sha256'],'reviewer':'','note':'','source_and_notes_reviewed':False,
        'taxonomy_reviewed':False,'decisions':{g['id']:{'action':'defer','purpose':g['suggested_purpose'] or '',
        'reason':''} for g in plan['review_groups']},'gap_decisions':{},'successors':{}}

def approve(model,baseline,old,old_hash,new,new_hash,plan,review):
    expected=compare(model,baseline,old,old_hash,new,new_hash,plan['taxonomy'])
    if expected!=plan or review.get('plan_sha256')!=plan['sha256']:raise ValueError('Freigabe gehört nicht zum aktuellen Vergleich.')
    if not plan['taxonomy_ready']:raise ValueError('Zieljahr und E-Bilanz-Version sind noch nicht freigegeben.')
    if review.get('source_and_notes_reviewed') is not True or review.get('taxonomy_reviewed') is not True:
        raise ValueError('Originalquelle, Fußnoten und Zielversion ausdrücklich prüfen.')
    for key in ('reviewer','note'):
        if not isinstance(review.get(key),str) or not review[key].strip() or len(review[key])>500:raise ValueError('Prüfer und Freigabevermerk fehlen.')
    decisions=review.get('decisions',{})
    if set(decisions)!=set(g['id'] for g in plan['review_groups']):raise ValueError('Prüfliste unvollständig oder veraltet.')
    check_target=target_validator(model,plan['taxonomy'],plan['year'])
    bound={c:r['purpose'] for c,r in plan['automatic'].items()};unresolved={}
    for group in plan['review_groups']:
        d=decisions[group['id']]
        if d.get('action') not in ('assign','defer') or not isinstance(d.get('reason'),str) or not d['reason'].strip():
            raise ValueError('Entscheidung und Begründung für jede Änderung erforderlich.')
        if d['action']=='defer':
            for number in group['codes']:unresolved[number]=d['reason']
        else:
            check_target(d.get('purpose',''))
            for number in group['codes']:bound[number]=d['purpose']
    gaps={row['code'] for row in plan['existing_gaps']};gap_decisions=review.get('gap_decisions',{})
    if not isinstance(gap_decisions,dict) or set(gap_decisions)-gaps:raise ValueError('Unbekannte bisherige Zuordnungslücke.')
    for number,d in gap_decisions.items():
        if not isinstance(d.get('reason'),str) or not d['reason'].strip():raise ValueError('Begründung für neue Grundzuordnung fehlt.')
        check_target(d.get('purpose',''));bound[number]=d['purpose']
    for number in gaps-set(gap_decisions):unresolved[number]='Bisherige Grundzuordnung fehlt.'
    successors=review.get('successors',{})
    if not isinstance(successors,dict) or set(successors)-{r['code'] for r in plan['retired']}:raise ValueError('Ungültige Nachfolgerliste.')
    for number,d in successors.items():
        if d.get('code') not in bound or not isinstance(d.get('reason'),str) or not d['reason'].strip():
            raise ValueError('Nachfolger muss im neuen Jahr geprüft und begründet sein.')
    provenance={'kind':'annual_review','baseline_sha256':baseline['sha256'],'plan_sha256':plan['sha256'],
                'filing_package':plan['filing_package'],
                'review_sha256':digest(review),'reviewer':review['reviewer'],'note':review['note'],
                'retired':plan['retired'],'successors':successors,'unresolved':unresolved}
    if ebilanz_release.status(plan['taxonomy'],plan['year'])!=plan['filing_package']:raise ValueError('Jahrespaket während der Prüfung geändert. Erneut prüfen.')
    return snapshot(model,new,new_hash,plan['taxonomy'],bound,provenance)

def compile_registry(model,editions,catalogs,revision):
    if type(revision) is not int or revision<1:raise ValueError('Revisionsnummer erforderlich.')
    taxonomies={e['taxonomy'] for e in editions}
    if len(taxonomies)!=1:raise ValueError('Ein Zielpaket pro Taxonomieversion erforderlich.')
    taxonomy=next(iter(taxonomies));sources={};rules={};years=sorted(e['year'] for e in editions)
    projection=taxonomy_purposes.load(model,taxonomy)
    if len(years)!=len(set(years)):raise ValueError('Jahresstand doppelt.')
    for e in sorted(editions,key=lambda e:e['year']):
        data,raw_hash=catalogs[e['year']];validate_snapshot(e,model,data,raw_hash)
        sources[str(e['year'])]={k:e['source'][k] for k in ('pdf_sha256','catalog_sha256')}
        checked_package=ebilanz_release.status(taxonomy,e['year'])
        check_target=target_validator(model,taxonomy,e['year'])
        for ref,codes in e['bindings'].items():
            check_target(ref);rule=mapping(model,ref,taxonomy,projection)
            key=digest(rule|{'codes':codes})
            if key not in rules:rules[key]=rule|{'codes':codes,'years':[]}
            rules[key]['years'].append(e['year'])
        if ebilanz_release.status(taxonomy,e['year'])!=checked_package:raise ValueError('Jahrespaket während der Erzeugung geändert. Erneut prüfen.')
    rows=list(rules.values())
    for row in rows:
        if row['years']==years:del row['years']
    return {'taxonomy':taxonomy,'revision':revision,'sources':sources,'rules':rows}

def runtime_equivalent(actual,expected):
    def expanded(r):
        result={}
        for year in r['sources']:
            for rule in r['rules']:
                if int(year) not in rule.get('years',[int(year)]):continue
                for number in rule['codes']:
                    key=(year,number)
                    if key in result:raise ValueError('Doppelte Laufzeitregel.')
                    result[key]={k:v for k,v in rule.items() if k not in ('codes','years')}
        return result
    return actual['taxonomy']==expected['taxonomy'] and actual['sources']==expected['sources'] and expanded(actual)==expanded(expected)

def write_new(path,data):
    """Exclusive artifact creation: never replace a previous catalog or approval."""
    path=Path(path)
    path.parent.mkdir(parents=True,exist_ok=True)
    raw=(json.dumps(data,ensure_ascii=False,indent=2)+'\n').encode()
    with path.open('xb') as f:f.write(raw)

def import_directory(base,override,edition,year):
    base=Path(base).resolve()
    destination=(Path(override) if override else base/'data'/'pending'/edition/str(year)).resolve()
    if destination==base/'data' or any(destination==base/p or base/p in destination.parents for p in ('resources','public','src','views')):
        raise ValueError('Kandidaten außerhalb der aktiven Programmverzeichnisse speichern.')
    return destination

def coverage(model,edition,data):
    assigned=bindings(edition);rows=edition_catalog(data,edition)
    projection=taxonomy_purposes.load(model,edition['taxonomy'])
    missing=[c for c,r in rows.items() if r['bookable'] and c not in assigned]
    conditional=[c for c,ref in assigned.items() if mapping(model,ref,edition['taxonomy'],projection).get('condition')]
    return {'bookable':sum(r['bookable'] for r in rows.values()),'assigned':len(assigned),
            'conditional':len(conditional),'missing':sorted(missing),'complete':not missing}

def html_report(plan,model):
    """Offline review form; names are escaped, the downloaded decision is hash-bound."""
    verify(plan);esc=html.escape
    projection=taxonomy_purposes.load(model,plan['taxonomy'])
    options=''.join('<option value="'+esc(ref)+'">'+esc(definition(model,ref)['label']+' · '+ref)+'</option>'
        for ref in (latest(model,p,plan['taxonomy'],projection) for p in model['purposes']) if ref)
    def description(row):
        if not row:return 'Neu im Katalog'
        result='<strong>'+esc(row['name'])+'</strong><small>Funktion: '+esc(row['function'] or 'keine')+' · Quellbereich '+esc(row['range_start']+'–'+row['range_end'])+'</small>'
        if row['parent']:
            result+='<small>Gehört zu: '+esc(row['parent'].get('name','Zuordnung aus der Quelle unklar'))+'</small>'
        if row.get('statement'):
            s=row['statement'];result+='<small>Abschlussposten: '+esc(s['label'] or 'Im Original nicht angegeben')+'</small>'
            if s['marks']:result+='<small>Abschlusszweck / Programmhinweis: '+esc(', '.join(s['marks']))+'</small>'
        return result
    rows=[]
    for g in plan['review_groups']:
        previous=definition(model,g['previous_purpose'])['label'] if g['previous_purpose'] else 'Bisher nicht zugeordnet'
        target_note=('<small>Taxonomieangaben geändert. Der bisherige Kontenzweck ist für diese Zielversion noch nicht freigegeben; die Administration muss die Zweckregel prüfen.</small>'
                     if g['review_reason']=='taxonomy_change' else '')
        rows.append('<tr data-group="'+g['id']+'"><td>'+esc(', '.join(g['codes']))+'<small>'+esc(previous)+'</small></td><td><small>Bisher</small>'+description(g['before'])+'<small>Neu</small>'+description(g['after'])+
            target_note+'</td><td><label>Entscheidung<select class="decision"><option value="defer">Noch nicht zuordnen</option><option value="assign">Kontenzweck festlegen</option></select></label>'+
            '<label>Kontenzweck<select class="purpose"><option value="">Bitte wählen</option>'+options+'</select></label><label>Begründung<input class="reason" required maxlength="500"></label></td></tr>')
    seed=json.dumps(review_template(plan),ensure_ascii=True).replace('<','\\u003c').replace('>','\\u003e').replace('&','\\u0026')
    retired=''.join('<li data-retired="'+r['code']+'">'+esc(r['code']+' · '+r['name'])+
        '<label>Nachfolgekonto (optional)<input class="successor" inputmode="numeric" pattern="[0-9]{5}" maxlength="5"></label>'+
        '<label>Grund für den Nachfolger<input class="successor-reason" maxlength="500"></label></li>' for r in plan['retired']) or '<li>Keine</li>'
    gaps=''.join('<li>'+esc(r['code']+' · '+r['after']['name'])+'</li>' for r in plan['existing_gaps'])
    unsupported='' if plan['taxonomy_ready'] else '<p role="alert">Für dieses Jahr ist die E‑Bilanz-Zielversion noch nicht freigegeben. Der Vergleich ist möglich; ein Zuordnungspaket kann noch nicht freigegeben werden.</p>'
    checked=plan['filing_package']
    if checked['ready']:
        unsupported+='<p>Geprüfte Grundlage: '+esc(checked['id'])+'. Taxonomie '+esc(checked['taxonomy'])+' für '+str(checked['year'])+'. Die Freigabe einer Steuerabgabe wird gesondert geprüft.</p>'
        if checked.get('native_validation_ready') is False:
            unsupported+='<p role="status">Der Kontenplan kann vorbereitet werden. Ausgabeadapter und amtlicher ERiC-Prüfer für diese Taxonomie sind noch nicht abgenommen; eine Steuerabgabe bleibt gesperrt.</p>'
    else:unsupported+='<p>'+esc(checked['reason'])+' Die Administration muss zuerst die passende Programmversion prüfen.</p>'
    notes=plan['source_notes']
    source_links='<p><a href="'+esc(notes['previous_source'],quote=True)+'" target="_blank" rel="noreferrer">Vorheriges Original-PDF</a> · <a href="'+esc(plan['source']['url'],quote=True)+'" target="_blank" rel="noreferrer">Neues Original-PDF</a></p>'
    notes_text='Anhangstexte geändert – Auswirkungen auf die Kontenzwecke prüfen.' if notes['changed'] else 'Auch Hinweise und Fußnoten im Original prüfen.'
    if not notes['both_available']:notes_text+=' Nicht beide Anhangstexte liegen als Text vor.'
    source_notes='<section><details><summary>Originale, Hinweise und Fußnoten</summary>'+source_links+'<p>'+notes_text+'</p>'
    for label,key in [('Bisher','before'),('Neu','after')]:
        source_notes+='<h3>'+label+'</h3>'
        for note in notes[key]:source_notes+='<p>Seite '+esc(str(note['page']))+'</p><pre style="white-space:pre-wrap">'+esc(note['text'])+'</pre>'
    source_notes+='</details></section>'
    return '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kontenplan prüfen</title>'+'''<style>
body{font:18px/1.5 system-ui,sans-serif;color:#172b45;background:#f5f7fb;margin:0}main{max-width:1100px;margin:auto;padding:24px}h1{line-height:1.2}section{background:white;border:1px solid #d7deea;border-radius:12px;padding:20px;margin:20px 0}table{border-collapse:collapse;width:100%}th,td{padding:14px;text-align:left;vertical-align:top;border-bottom:1px solid #d7deea}label{display:block;margin:8px 0}input,select,textarea,button{font:inherit;padding:12px;box-sizing:border-box;max-width:100%;min-height:48px}input:not([type=checkbox]),select,textarea{width:100%}button{background:#173659;color:white;border:0;border-radius:8px;cursor:pointer}summary{cursor:pointer;padding:10px}input[type=checkbox]{min-height:24px;width:24px;vertical-align:middle}small{display:block}#message{color:#9d2020} @media(max-width:700px){main{padding:12px}table,tbody,tr,td{display:block}thead{display:none}td:first-child{font-weight:bold}td{padding:10px}section{padding:12px}}
</style><main>'''+f'<h1>Kontenplan {plan["from_year"]} → {plan["year"]} prüfen</h1><p>SKR42 · {"Bilanz" if plan["edition"]=="balance" else "EÜR"}</p>'+\
        f'<section><h2>Jahresvergleich</h2><p>{plan["counts"]["unchanged"]} unverändert · {plan["counts"]["changed"]} geändert · {plan["counts"]["newly_bookable"]} neu · {plan["counts"]["no_longer_bookable"]} entfallen oder gesperrt.</p><p>{len(plan["automatic"])} geprüfte Zuordnungen werden vorgeschlagen. Alte Buchungen bleiben unverändert.</p>'+unsupported+'</section>'+\
        source_notes+'<section><h2>Änderungen prüfen</h2><table><thead><tr><th>Konten</th><th>Bisher / neu</th><th>Prüfung</th></tr></thead><tbody>'+''.join(rows)+'</tbody></table></section>'+\
        '<section><details><summary>Entfallene oder gesperrte Konten</summary><p>Nur für neue Buchungen im neuen Jahr gesperrt. Ein Nachfolger ist ein Hinweis; bestehende Buchungen werden nicht gelöscht oder umgebucht.</p><ul>'+retired+'</ul></details>'+\
        f'<details><summary>Bisherige Zuordnungslücken ({len(plan["existing_gaps"])})</summary><p>Diese Lücken sind keine neuen Jahresänderungen.</p><ul>'+gaps+'</ul></details></section>'+\
        '<section><h2>Prüfung abschließen</h2><label>Geprüft von<input id="reviewer" required maxlength="500"></label><label>Prüfvermerk<textarea id="note" required maxlength="500"></textarea></label>'+\
        '<label><input id="source" type="checkbox"> Originalquelle und Fußnoten geprüft</label><label><input id="taxonomy" type="checkbox"> Passende E‑Bilanz-Version geprüft</label>'+\
        '<p>Die Prüfdatei wird heruntergeladen. Sie ändert keine Website und erteilt keine Freigabe für eine Steuerabgabe.</p><button id="save" type="button"'+('' if plan['taxonomy_ready'] else ' disabled')+'>Prüfdatei speichern</button><p id="message" role="status"></p></section>'+\
        '<script type="application/json" id="review-seed">'+seed+'</script>'+r'''<script>
document.getElementById('save').addEventListener('click',()=>{const r=JSON.parse(document.getElementById('review-seed').textContent);r.reviewer=document.getElementById('reviewer').value.trim();r.note=document.getElementById('note').value.trim();r.source_and_notes_reviewed=document.getElementById('source').checked;r.taxonomy_reviewed=document.getElementById('taxonomy').checked;
for(const row of document.querySelectorAll('[data-group]'))r.decisions[row.dataset.group]={action:row.querySelector('.decision').value,purpose:row.querySelector('.purpose').value,reason:row.querySelector('.reason').value.trim()};
for(const row of document.querySelectorAll('[data-retired]')){const code=row.querySelector('.successor').value.trim();const reason=row.querySelector('.successor-reason').value.trim();if(code||reason)r.successors[row.dataset.retired]={code,reason};}
if(Object.values(r.successors).some(d=>!/^\d{5}$/.test(d.code)||!d.reason)){document.getElementById('message').textContent='Bitte Nachfolgekonto und Begründung ergänzen oder beide Felder leer lassen.';return;}
if(!r.reviewer||!r.note||!r.source_and_notes_reviewed||!r.taxonomy_reviewed||Object.values(r.decisions).some(d=>!d.reason||(d.action==='assign'&&!d.purpose))){document.getElementById('message').textContent='Bitte Prüfer, Prüfvermerk und alle Entscheidungen ergänzen.';return;}
const url=URL.createObjectURL(new Blob([JSON.stringify(r,null,2)],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download='kontenplan-pruefung.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);document.getElementById('message').textContent='Prüfdatei gespeichert. Noch keine Änderung am aktiven Kontenplan.';});
</script></main></html>'''
