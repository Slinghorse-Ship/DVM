"""Printed DATEV statement positions, separate from reviewed XML export rules.

Historical account catalogs remain byte-identical. A source-bound supplement
adds the columns the old importer omitted. New imports contain them directly.
No database, network, automatic tax-rate inference or permission to transmit.
"""
from __future__ import annotations
import copy
import hashlib
import json
from pathlib import Path
import re
import unicodedata

ROOT = Path(__file__).resolve().parents[1]


def normalized(value):
    value = unicodedata.normalize('NFKC', value).replace('\u00ad', '')
    value = value.replace('\u2010', '-').replace('\u2011', '-')
    value=' '.join(value.split()).casefold()
    # Reviewed orthographic variants. No fuzzy matching and no removal of
    # numbers, footnotes, negation or tax-rate words. Raw wording is retained.
    value=re.sub(r'\bnahe stehend(e|en|er|es|em)?\b',r'nahestehend\1',value)
    return value


def fingerprint(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True,
                                     separators=(',', ':')).encode()).hexdigest()


def meaning(statement):
    if not isinstance(statement, dict) or set(statement) != {'kind','label','marks','status','bbox'}:
        raise ValueError('Abschlussposten unvollständig.')
    if statement['kind'] not in ('balance','guv','statistical') or statement['status'] not in ('printed','not_printed'):
        raise ValueError('Unbekannter Abschlussposten.')
    if not isinstance(statement['label'], str) or not isinstance(statement['marks'], list) or any(not isinstance(s,str) for s in statement['marks']):
        raise ValueError('Ungültige Beschriftung des Abschlusspostens.')
    label = normalized(statement['label'])
    if statement['status']=='printed':
        box=statement['bbox']
        if not label or not isinstance(box,list) or len(box)!=4 or any(type(n) not in (int,float) for n in box) or not (0<=box[0]<box[2]<1000 and 0<=box[1]<box[3]<1500):
            raise ValueError('Quellrahmen des Abschlusspostens fehlt.')
    elif label or statement['bbox'] is not None:
        raise ValueError('Nicht gedruckter Abschlussposten darf keine Zuordnung behaupten.')
    return {k:statement[k] for k in ('kind','status')} | {'label':label, 'marks':sorted(set(map(normalized,statement['marks'])))}


def supplement(original, catalog_hash, enriched):
    """Only add source facts from exactly the SAME PDF; never rewrite history."""
    if enriched.get('statement_schema')!=1 or original['year']!=enriched['year'] or original['sha256']!=enriched['sha256']:
        raise ValueError('Ergänzung gehört nicht zum gleichen Original-PDF.')
    if enriched.get('edition')!='balance' or enriched.get('chart')!='SKR42':
        raise ValueError('Bilanzquelle erforderlich.')
    def strip(row):
        out={k:v for k,v in row.items() if k not in ('statement','alternatives')}
        if 'alternatives' in row:out['alternatives']=[strip(r) for r in row['alternatives']]
        return out
    for key in ('ranges','accounts'):
        if [strip(r) for r in original[key]] != [strip(r) for r in enriched[key]]:
            raise ValueError('Kontentexte oder Bereiche verändert; keine reine Quellergänzung.')
    rows={};positions=[];by_value={}
    for row in enriched['accounts']:
        meaning(row.get('statement'))
        if row['code'] in rows:raise ValueError('Doppeltes Quellkonto.')
        alternatives=[a['statement'] for a in row.get('alternatives',[])]
        for item in alternatives:meaning(item)
        item={'statement':row['statement'],'alternatives':alternatives};key=fingerprint(item)
        if key not in by_value:by_value[key]=len(positions);positions.append(item)
        rows[row['code']]=by_value[key]
    body={'schema':1,'chart':'SKR42','edition':'balance','year':original['year'],
          'pdf_sha256':original['sha256'],'catalog_sha256':catalog_hash,'positions':positions,'accounts':rows}
    return body|{'sha256':fingerprint(body)}


def contexts(data, directory=None):
    """Return raw printed facts; no guesses for absent/ambiguous source fields."""
    if data.get('statement_schema')==1:
        result={a['code']:a['statement'] for a in data['accounts']}
        if len(result)!=len(data['accounts']):raise ValueError('Doppelte Kontenzeile.')
        for value in result.values():meaning(value)
        return result
    path=Path(directory or ROOT/'resources/accounting/statements')/f"{data['year']}.json"
    if not path.is_file():return {}
    item=json.loads(path.read_text(encoding='utf-8'))
    # Synthetic/third-party candidates must never inherit a local year's data.
    if item.get('pdf_sha256')!=data.get('sha256'):return {}
    raw=(ROOT/f"data/skr42-{data['year']}.json").read_bytes().replace(b'\r\n',b'\n')
    if json.loads(raw)!=data or hashlib.sha256(raw).hexdigest()!=item.get('catalog_sha256'):
        raise ValueError('Abschlussposten und historischer Katalog stimmen nicht überein.')
    if item.get('sha256')!=fingerprint({k:v for k,v in item.items() if k!='sha256'}) or item.get('year')!=data['year'] or item.get('schema')!=1:
        raise ValueError('Quellergänzung verändert.')
    if set(item['accounts'])!={r['code'] for r in data['accounts']}:
        raise ValueError('Quellergänzung deckt nicht alle Konten ab.')
    if any(type(index) is not int or not 0<=index<len(item['positions']) for index in item['accounts'].values()):
        raise ValueError('Ungültiger Abschlussposten-Verweis.')
    for row in item['positions']:
        meaning(row['statement'])
        for alternative in row['alternatives']:meaning(alternative)
    result={code:item['positions'][index]['statement'] for code,index in item['accounts'].items()}
    for value in result.values():meaning(value)
    return result


def enrich_rows(data, rows):
    positions=contexts(data)
    result=copy.deepcopy(rows)
    for code,row in result.items():
        if code in positions:
            row['semantic']['statement']=meaning(positions[code])
            row['signature']=fingerprint(row['semantic'])
    return result


def purpose_key(row):
    """Exact source meaning independent of account number/page/range length.

    Used only WITH printed statement context and an existing reviewed purpose.
    No spelling-distance matching, parent-only fallback or class-digit guesses.
    """
    s=row['semantic'];position=s.get('statement')
    if row['uncertain_context'] or not position or position['status']!='printed':return None
    parent=s.get('parent')
    if parent:parent={k:normalized(v) for k,v in parent.items() if k in ('name','function')}
    return fingerprint({'name':normalized(s['name']),'function':normalized(s['function']),
                        'parent':parent,'statement':position})


def report(data, rows):
    positions=contexts(data);bookable={c:r for c,r in rows.items() if r['bookable']}
    with_label=[c for c in bookable if c in positions and positions[c]['status']=='printed']
    without=[{'code':c,'name':r['name'],'reason':'Kein Abschlussposten im Original gedruckt.'}
             for c,r in bookable.items() if c in positions and positions[c]['status']=='not_printed']
    return {'bookable':len(bookable),'printed_positions':len(with_label),
            'not_printed':without,'not_extracted':[c for c in bookable if c not in positions],
            'source_facts_complete':len(with_label)+len(without)==len(bookable)}
