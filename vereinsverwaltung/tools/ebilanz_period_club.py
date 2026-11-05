"""Reconstruct ALL dated taxable units and retained assets from original books.

DB authorisation/history are checked by PHP; numbers are recalculated here.
No bank, certificate, transmission, synthetic balancing position or subset input.
"""
from copy import deepcopy
import re
from ebilanz_club_scope import amount, calculate, digest, mapping
from ebilanz_period import require, verify as period_verify


def raw(values):
    v={k:n for k,n in values.items() if k not in ('period_club','sha256','transmission')}
    return v|{'sha256':digest(v),'transmission':'unavailable'}


def project(income, prior=None, calendar_scope=None):
    require(type(income) is dict and income.get('sha256')==digest({k:n for k,n in income.items() if k!='sha256'}))
    scope=income['scope'];s=scope['source'];a=scope['allocation'];units=a['units'];period=s['period'];chart=s['accounts']
    require(scope['schema'] in (2,3) and scope['kind']=='club_income_reconciliation' and income['period']==period and income['year']==s['year'])
    require(s['state']=='closed' and s['issues']==[] and a['missing']==[] and a['year']==s['year'] and a['period']==period and a['journal_sha256']==s['closed_sha256'])
    require(a['sha256']==digest({k:n for k,n in a.items() if k!='sha256'}) and 1<=len(units)<=32)
    require(calculate(s['year'],chart,s['opening'],s['entries'],period)==s['calculation'])
    names=set()
    for key,u in units.items():
        require(re.fullmatch('[a-z][a-z0-9_-]{0,39}',key) and set(u)=={'name','sphere'} and u['sphere'] in ('1','2','3','4'))
        require(type(u['name']) is str and u['name']==u['name'].strip() and u['name'] and u['name'].casefold() not in names);names.add(u['name'].casefold())
    selected=[key for key,u in units.items() if u['sphere']=='4']
    require(selected and income['selected_units']==selected)
    for case in mapping(scope['clearing']['cases']).values():
        require(case['from'] in units and case['to'] in units and (case['from'] in selected)==(case['to'] in selected),
                'Bereichsübergreifende Vorschüsse benötigen eine getrennte steuerliche Nebenrechnung.')
    opening=[];entries={};unit_lines={u:{} for u in units};used=set();capital=mapping(a.get('capital_carry',[]));alloc=mapping(a['allocations'])
    def line(key,net,sphere=''):return {'account':key,'debit_cents':max(0,net),'credit_cents':max(0,-net),'sphere':sphere}
    originals=[{'id':'opening','date':period['start'],'description':'Anfangsbestände','lines':s['opening']}]+s['entries']
    for entry in originals:
        for i,l in enumerate(entry['lines']):
            key=entry['id']+':'+str(i)
            if entry['id']=='opening' and l['account'] in capital:
                require(key not in alloc);continue
            row=alloc[key];used.add(key);parts=mapping(row['parts']);require(set(row)=={'parts','reason'} and parts and not set(parts)-set(units))
            require(type(row['reason']) is str and row['reason'].strip() and sum(amount(n) for n in parts.values())==l['debit_cents']+l['credit_cents'])
            for u,n in parts.items():
                require(n>0 and (not l['sphere'] or units[u]['sphere']==l['sphere']))
                unit_lines[u].setdefault(entry['id'],[]).append(line(l['account'],n if l['debit_cents'] else -n,l['sphere']))
            n=sum(parts.get(u,0) for u in selected)
            if not n:continue
            target=line(l['account'],n if l['debit_cents'] else -n,l['sphere'])
            if entry['id']=='opening':opening.append(target)
            else:entries.setdefault(entry['id'],{'id':entry['id'],'date':entry['date'],'description':entry.get('description',entry['id']),'lines':[]})['lines'].append(target)
    require(set(alloc)==used)
    for key,parts in capital.items():
        require(s['previous_sha256'] is not None and chart[key]['group']=='equity' and parts and not set(parts)-set(units))
        require(min(parts.values())<0<max(parts.values()) and all(amount(n)!=0 for n in parts.values()))
        require(sum(parts.values())==sum(l['debit_cents']-l['credit_cents'] for l in s['opening'] if l['account']==key))
        n=sum(parts.get(u,0) for u in selected)
        if n:opening.append(line(key,n))
    for key,t in mapping(a.get('transfers',[])).items():
        parts=t['parts'];account=t['account'];require(set(t)=={'account','parts','reason'} and chart[account]['group']=='equity')
        expected={u:sum(l['credit_cents']-l['debit_cents'] for l in rows.get(key,[])) for u,rows in unit_lines.items()}
        require(parts=={u:n for u,n in expected.items() if n} and len(parts)>=2 and sum(parts.values())==0)
        n=sum(parts.get(u,0) for u in selected)
        if n:entries[key]['lines'].append(line(account,n))
    for entry in s['entries']:
        if entry.get('reversal_of') is None:continue
        key=entry['id'];target=entry['reversal_of']
        if key not in entries and target not in entries:continue
        require(key in entries and target in entries)
        require(entries[key]['lines']==[l|{'debit_cents':l['credit_cents'],'credit_cents':l['debit_cents']} for l in entries[target]['lines']])
        entries[key]['reversal_of']=target
    calc=calculate(s['year'],chart,opening,list(entries.values()),period)
    require(calc['annual_result_cents']==income['result']['taxable_book_cents']==s['calculation']['spheres']['4']['result_cents'])
    schedule=deepcopy(s['asset_schedule']);require(schedule['period']==period and schedule['issues']==[])
    original=mapping(schedule['items']);require(all('sphere_change' not in i for i in original.values()))
    selected_items={key:i for key,i in original.items() if i['plan']['sphere']=='4'}
    totals={}
    from ebilanz_period_asset_math import FIELDS
    for key,item in selected_items.items():
        fs=FIELDS
        if item['plan'].get('method')=='cost':
            from ebilanz_cost_asset_allocation import split
            from ebilanz_asset_valuation import from_book_plan,FIELDS as COST_FIELDS
            value=item['valuation'];require(from_book_plan(item['plan'])==value['source']['plan'] and value['source']['period']==period)
            require(item['schedule']=={'period':period,'depreciation_cents':0}|value['book'])
            share=s['assets']['shares'][key];require(share['item_sha256']==digest(item) and all(units[u]['sphere']==item['plan']['sphere'] for u in share['weights']))
            parts=split(value,share['weights'])['parts']
            require(all(mapping(s['assets']['schedules'][u]['items']).get(key,{}).get(f)==n for u,row in parts.items() for f,n in row.items()))
            fs=FIELDS+tuple(f for f in COST_FIELDS if f not in FIELDS)
        for f in fs:
            require(sum(mapping(s['assets']['schedules'][u]['items']).get(key,{}).get(f,0) for u in selected)==item['schedule'].get(f,0))
        for f in ('opening','closing'):totals.setdefault(item['fixed_account'],{}).setdefault(f,0);totals[item['fixed_account']][f]+=item['schedule']['book_'+f+'_cents']
    for key,row in calc['accounts'].items():
        if row['group']=='fixed_assets':require(all(totals.get(key,{}).get(f,0)==row[f+'_cents'] for f in ('opening','closing')))
    schedule['items']=selected_items or [];schedule.pop('totals',None)
    require((scope['previous'] is None)==(prior is None))
    proof={'schema':1,'income':income}
    if calendar_scope is not None:proof['calendar_scope']=calendar_scope
    return {'year':s['year'],'state':'closed','closed_sha256':digest(proof),'previous_sha256':prior['closed_sha256'] if prior else None,
            'result_account':s['result_account'],'issues':[],'accounts':chart,'opening':opening,'entries':list(entries.values()),'calculation':calc,'asset_schedule':schedule,'period':period}


def verify(values,depth=0):
    require(depth<=100 and type(values) is dict and values.get('transmission')=='unavailable'
            and values.get('sha256')==digest({k:n for k,n in values.items() if k not in ('sha256','transmission')}))
    p=values.get('period_club');require(type(p) is dict and set(p)=={'income','whole','previous'})
    from ebilanz_calendar_club import record, packet, scope
    prior=p['previous'];prior_source=None;whole_prior=None
    if prior is not None:
        if 'calendar_club' in prior:
            prior_source=record(prior)
            require(p['income']['scope']['previous']==prior['calendar_club']['income']['scope'] and values['period_source']['previous']==packet(prior,'club'))
            whole_prior=packet(prior,'club_books')
        else:
            verify(prior,depth+1);prior_source=prior['period_source']['source']
            require(p['income']['scope']['previous']==prior['period_club']['income']['scope'] and values['period_source']['previous']==raw(prior))
            whole_prior=prior['period_club']['whole']
    else:require(values['period_source']['previous'] is None)
    source=project(p['income'],prior_source,scope(prior))
    require(source==values['period_source']['source'])
    w=p['whole'];original=p['income']['scope']['source'];whole_source={k:original[k] for k in w['period_source']['source']}
    require(whole_source==w['period_source']['source'] and w['period_source']['previous']==whole_prior)
    period_verify(w,depth,whole=True,section='books')
    return period_verify(raw(values),depth)
