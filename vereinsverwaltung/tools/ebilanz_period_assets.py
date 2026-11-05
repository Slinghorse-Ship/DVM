"""Retained period assets -> independently checked book/tax rollforward and BAL."""
from copy import deepcopy
import hashlib
import re
from ebilanz_club_scope import digest, mapping, amount
from ebilanz_period import require
from ebilanz_period_asset_math import FIELDS, flat, schedule, reconcile, number, reason
from ebilanz_sales import sale_values
from ebilanz_asset_types import DEP, ACCOUNTS

from ebilanz_original_depreciation import code as original_depreciation_code
MANAGED={'62220','62620','62000','62050','62200','62210','62600','48450','48490','48550','68850','68890','68950','68960','48440','48480','68840','68880'}


def calendar_plan_identity(p):
    if p.get('method')=='cost':return p
    # Old flat calendar schedules retained valuation fields, not the plan note.
    # Match PHP BalancePeriodAssetAllocation::plan; keep dated-to-dated plans exact.
    fields=('basis_cents','residual_cents','months','acquired_on','account','sphere','method','gwg','degressive','depreciation_code')
    return {k:p.get(k,'linear_monthly' if k=='method' else None) for k in fields}

def sales(proof,book):
    v=sale_values(proof,book)
    return {'gross_cents':proof['gross_cents'],'tax_cents':proof['tax_cents'],'net_cents':v['net'],'book_cents':book,
            'result_cents':v['net']-book,'gain':v['gain'],'sale_code':v['sale_code'],'book_code':v['book_code'],'group':'revenue' if v['gain'] else 'expense'}

def line(key,net,sphere=''):
    return {'account':key,'debit_cents':max(0,net),'credit_cents':max(0,-net),'sphere':sphere}

def pair(fixed,other,net,sphere):return [line(fixed,net),line(other,-net,sphere)]

def unreversed_turnover(entries):
    """Subtract only exact linked reversals from gross turnover, never the journal."""
    seen={};cancelled=set()
    for entry in entries:
        identifier=entry['id'];require(identifier not in seen)
        seen[identifier]=entry
    for identifier,entry in seen.items():
        target=entry.get('reversal_of')
        if target is not None:
            original=seen.get(target)
            require(original is not None and not original.get('reversal_of') and target not in cancelled and entry['date']>=original['date'],
                    'Anlagen: Ursprüngliche Stornobuchung vollständig nachweisen.')
            reversed_lines=[line(l['account'],l['credit_cents']-l['debit_cents'],l['sphere']) for l in original['lines']]
            require(entry['lines']==reversed_lines,'Anlagen: Storno stimmt nicht mit der Originalbuchung überein.')
            cancelled.update((identifier,target))
    turnover={}
    for identifier,entry in seen.items():
        if identifier in cancelled:continue
        for l in entry['lines']:
            row=turnover.setdefault(l['account'],[0,0]);row[0]=amount(row[0]+l['debit_cents']);row[1]=amount(row[1]+l['credit_cents'])
    return turnover


def rebuild(values):
    source=values['period_source']['source'];review=values['period_source']['review'];previous=values['period_source']['previous']
    period=values['period'];chart=deepcopy(source['accounts']);retained=source['asset_schedule'];plans=mapping(review['asset_tax_plans'])
    require(type(retained) is dict and retained.get('period')==period and retained.get('issues')==[])
    items=mapping(retained.get('items'));require(len(items)<=10000 and not set(plans)-set(items))
    old_items=mapping((previous['asset_schedule'] or {}).get('items',[])) if previous else {}
    old_tax=mapping(previous['asset_tax']['tax_schedule']['items']) if previous else {}
    require(not set(old_items)-set(items) and not set(old_tax)-set(items))
    tax_items={};proofs={};current=[];opening={};managed={};added={};totals=dict.fromkeys(FIELDS,0);book_totals=dict.fromkeys(FIELDS,0)
    if any(i['plan'].get('method')=='cost' for i in items.values()):
        totals.update(impairment_cents=0,writeup_cents=0);book_totals.update(impairment_cents=0,writeup_cents=0)
    books={};taxes={};expected={'book':{},'tax':{}};fixed_totals={'book':{},'tax':{}};dep_totals={'book':{},'tax':{}};seen_sales=set()
    book_turnover=unreversed_turnover(source['entries'])
    def add(side,key,n):expected[side][key]=amount(expected[side].get(key,0)+n)
    def retained_account(item,code,group):
        key=mapping(item.get('disposal_accounts',[])).get(code)
        require(type(key) is str and chart.get(key,{}).get('skr_code')==code and chart[key]['group']==group)
        return key
    for identifier,item in items.items():
        require(type(item) is dict and item.get('item_id')==identifier and item.get('posted') is True)
        p=item['plan'];old=old_items.get(identifier);tax_review=plans.get(identifier);d=item.get('disposal');fixed=item['fixed_account']
        if values.get('period_section')!='books' and p['account']=='01500':
            require((tax_review or p).get('months')==180 and p.get('residual_cents')==0 and p.get('method','linear_monthly')=='linear_monthly','Erworbener Firmenwert: steuerlich 15 Jahre ohne Restwert, abweichende Buchwerte vollständig überleiten.')
        require(p['sphere'] in ('1','2','3','4') and chart.get(fixed,{}).get('group')=='fixed_assets' and chart[fixed]['skr_code']==p['account'])
        if p['acquired_on']<period['start']:require(old is not None and identifier in old_tax)
        if old is not None:
            same_plan=calendar_plan_identity(old['plan'])==calendar_plan_identity(p) if 'calendar_source' in previous else old['plan']==p
            require(same_plan and old['fixed_account']==fixed)
            if 'disposal' in old:require(old['disposal']==d)
        if p.get('method')=='cost':
            from ebilanz_cost_asset_tax import item_projection
            from ebilanz_cost_assets import counterparts,movement
            require(tax_review is None and d is None and 'sphere_change' not in item)
            value=item['valuation'];require(value['source']['previous']==(old.get('valuation') if old else None))
            require(item['schedule']=={'period':period,'depreciation_cents':0}|value['book'])
            flat_item={'item_id':identifier,'account':fixed,'method':'cost','cost_plan':p,'valuation':value,
                'valuation_accounts':item.get('valuation_accounts',[])}|item['schedule']
            derived=item_projection(flat_item,chart,period);selected=counterparts(flat_item,chart)
            cost_side='book' if values.get('period_section')=='books' else 'tax'
            previous_tax=old_tax.get(identifier)
            if old:
                prior=value['source']['previous'];require(previous_tax is not None and previous_tax.get('valuation_sha256')==prior['sha256']
                    and previous_tax['tax_review'] is None and previous_tax['schedule']=={'period':prior['source']['period'],'depreciation_cents':0}|prior[cost_side])
            else:require(previous_tax is None)
            book={'depreciation_cents':0}|value['book'];tax={'depreciation_cents':0}|value[cost_side];books[identifier]=book;taxes[identifier]=tax
            opening[fixed]=opening.get(fixed,0)+(0 if cost_side=='book' else derived['opening_difference_cents'])
            if cost_side=='tax':current.extend(derived['generated_current'])
            for key in derived['managed_accounts']:managed[key]=True
            tax_items[identifier]={'original_sha256':digest(item),'tax_review':None,'schedule':{'period':period}|tax,'valuation_sha256':value['sha256']}
            proofs[identifier]={'original_plan_sha256':digest(p),'cost':derived['proof']}
            if cost_side=='book':proofs[identifier]['cost_side']='book'
            for f in totals:totals[f]+=tax.get(f,0);book_totals[f]+=book.get(f,0)
            for side,amounts in (('book',book),('tax',tax)):
                fixed_totals[side].setdefault(fixed,dict.fromkeys(totals,0))
                for f in totals:fixed_totals[side][fixed][f]+=amounts.get(f,0)
                for code,key in selected.items():add(side,key,movement(flat_item,code,'book' if cost_side=='book' else side))
            continue
        actual=schedule(p,period,old['schedule'] if old else None,d)
        require(actual==item['schedule'],'Anlagenplan, tatsächlicher Zeitraum und ursprüngliche Abschreibung widersprechen sich.')
        book=flat(actual);books[identifier]=book;reconcile(book)
        if old:
            require(all(book[f+'_opening_cents']==old['schedule'][f+'_closing_cents'] for f in ('cost','accumulated','book')))
        tax_plan=deepcopy(p);previous_tax=old_tax.get(identifier)
        sphere=item.get('sphere_change')
        require(not previous_tax or 'sphere_change' not in previous_tax or sphere is not None)
        if sphere is not None:
            require(values.get('period_section') in ('books','subsidiary') and tax_review is None and d is None,
                    'Steuerlichen Bereichswechsel vollständig in Kapitalüberleitung und Anlagenspiegel abstimmen.')
            from ebilanz_period_spheres import replay, item_bridge
            require(not previous_tax or previous_tax['tax_review'] is None)
            require(sphere['plan']==p and sphere['period']==period)
            replay(sphere,previous_tax.get('sphere_change') if previous_tax else None);item_bridge(item)
        if tax_review is not None:
            require(type(tax_review) is dict and set(tax_review)=={'months','depreciation_account','reason','confirmed'}
                    and tax_review['confirmed'] is True and (p['sphere']=='4' or 'whole_club_scope' in values and values.get('period_section')!='books') and p.get('method','linear_monthly')=='linear_monthly'
                    and 'gwg' not in p and 'degressive' not in p)
            m=number(tax_review['months'],12,1200);require(m%12==0 and m!=p['months']);reason(tax_review['reason']);require(len(tax_review['reason'])<=1400)
            tax_plan['months']=m;dep=tax_review['depreciation_account']
            require(chart.get(dep,{}).get('group')=='expense' and chart[dep]['skr_code']==original_depreciation_code(p));managed[dep]=True
            if d and d['kind']=='partial_scrap':tax_raw=schedule(p,period,previous_tax['schedule'] if previous_tax else None,d,m)
            else:tax_raw=schedule(tax_plan,period,previous_tax['schedule'] if previous_tax else None,d)
        else:tax_raw=actual
        tax=flat(tax_raw);taxes[identifier]=tax;reconcile(tax)
        if previous_tax:
            require(previous_tax['tax_review']==tax_review and all(tax[f+'_opening_cents']==previous_tax['schedule'][f+'_closing_cents'] for f in ('cost','accumulated','book')))
        managed[fixed]=True
        diff={'opening_cents':tax['book_opening_cents']-book['book_opening_cents'],
              'depreciation_cents':tax['depreciation_cents']-book['depreciation_cents'],
              'disposal_cents':tax['book_disposals_cents']-book['book_disposals_cents'],
              'closing_cents':tax['book_closing_cents']-book['book_closing_cents']}
        require(diff['opening_cents']-diff['depreciation_cents']-diff['disposal_cents']==diff['closing_cents'])
        opening[fixed]=opening.get(fixed,0)+diff['opening_cents']
        short=hashlib.sha256(identifier.encode()).hexdigest()[:24]
        if diff['depreciation_cents']:
            current.append({'id':'asset-tax-'+short,'reason':'Steuerliche Abschreibung: '+tax_review['reason'],
                            'lines':pair(fixed,tax_review['depreciation_account'],-diff['depreciation_cents'],p['sphere'])})
        sale_proof=None
        if d and period['start']<=d['date']<=period['end']:
            for key in mapping(item['disposal_accounts']).values():managed[key]=True
            if d['kind']=='sale':
                require(d['sale_entry'] not in seen_sales);seen_sales.add(d['sale_entry'])
                bs=sales(d,book['book_disposals_cents']);ts=sales(d,tax['book_disposals_cents']);require(item.get('sale_values')==bs)
                e=item.get('sale_posting');require(type(e) is dict and set(e)=={'entry_id','source_id','source_sha256','account'}
                    and e['source_id']==d['sale_entry'] and type(e['source_sha256']) is str and re.fullmatch('[a-f0-9]{64}',e['source_sha256']))
                original=next((v for v in source['entries'] if v['id']==e['entry_id']),None)
                require(original is not None and original['date']==d['date'] and not original.get('reversal_of')
                        and not any(v.get('reversal_of')==e['entry_id'] for v in source['entries']))
                revenue=e['account'];require(chart.get(revenue,{}).get('skr_code')==bs['sale_code'] and chart[revenue]['group']==bs['group'])
                require([l for l in original['lines'] if l['account']==revenue]==[line(revenue,-bs['net_cents'],p['sphere'])])
                selected={bs['book_code']:retained_account(item,bs['book_code'],bs['group']),bs['sale_code']:revenue}
                for code in (ts['book_code'],ts['sale_code']):
                    if code not in selected:
                        key='tax_period_disposal_'+code;require(key not in source['accounts'])
                        added[key]={'name':'Steuerlicher Anlagenverkauf · '+code,'group':ts['group'],'skr_code':code};chart[key]=added[key];selected[code]=key
                for key in selected.values():managed[key]=True
                net={fixed:bs['book_cents']-ts['book_cents']}
                for code,n in ((bs['book_code'],-bs['book_cents']),(ts['book_code'],ts['book_cents']),(bs['sale_code'],bs['net_cents']),(ts['sale_code'],-ts['net_cents'])):
                    key=selected[code];net[key]=net.get(key,0)+n
                lines=[line(k,n,'' if k==fixed else p['sphere']) for k,n in net.items() if n]
                if lines:current.append({'id':'asset-sale-'+short,'reason':'Steuerlicher Anlagenverkauf: '+tax_review['reason'],'lines':lines})
                sale_proof={'book':bs,'tax':ts,'accounts':selected}
                for side,sv in (('book',bs),('tax',ts)):
                    add(side,selected[sv['sale_code']],sv['net_cents'] if sv['gain'] else -sv['net_cents'])
                    add(side,selected[sv['book_code']],-sv['book_cents'] if sv['gain'] else sv['book_cents'])
            else:
                code='68960' if d['kind']=='software_end' else '68950';key=retained_account(item,code,'expense')
                if diff['disposal_cents']:current.append({'id':'asset-disposal-'+short,'reason':'Steuerlicher Restbuchwert: '+tax_review['reason'],
                                                        'lines':pair(fixed,key,-diff['disposal_cents'],p['sphere'])})
                add('book',key,book['book_disposals_cents']);add('tax',key,tax['book_disposals_cents'])
        # Retain all fields of the original arithmetic, including degressive state.
        tax_complete=dict.fromkeys(FIELDS,0)|tax_raw
        tax_items[identifier]={'original_sha256':digest(item),'tax_review':tax_review,'schedule':tax_complete}
        proofs[identifier]={'original_plan_sha256':digest(p),'tax_review':tax_review,'difference':diff,'sale':sale_proof}
        if sphere is not None:
            tax_items[identifier]['sphere_change']=sphere;proofs[identifier]['sphere_change_sha256']=sphere['sha256']
        for f in totals:totals[f]+=tax.get(f,0);book_totals[f]+=book.get(f,0)
        for side,amounts in (('book',book),('tax',tax)):
            fixed_totals[side].setdefault(fixed,dict.fromkeys(totals,0))
            for f in totals:fixed_totals[side][fixed][f]+=amounts.get(f,0)
            dep_code=original_depreciation_code(p);dep_totals[side][dep_code]=dep_totals[side].get(dep_code,0)+amounts['depreciation_cents']
    if 'totals' in retained:require(retained['totals']==book_totals)
    proof={'period':period,'items':proofs or [],'desired_opening':opening or [],'generated_current':current,
           'managed_accounts':list(managed),'additional_accounts':added or [],
           'tax_schedule':{'period':period,'items':tax_items or [],'totals':totals}}
    require(values['asset_tax']==proof,'Anlagen-Steuerwerte, Nachweise oder erzeugte Wertänderungen stimmen nicht überein.')
    for side in ('book','tax'):
        accounts=values[side]['accounts']
        for key,a in accounts.items():
            if a['group']=='fixed_assets':
                amounts=fixed_totals[side].get(key,dict.fromkeys(FIELDS,0))
                for field,column in (('opening_cents','book_opening_cents'),('amount_cents','book_closing_cents')):
                    require(a[field]==amounts[column])
                debits=amounts['additions_cents']+amounts.get('writeup_cents',0);credits=amounts['depreciation_cents']+amounts.get('impairment_cents',0)+amounts['book_disposals_cents']
                require(a['debit_cents']-a['credit_cents']==debits-credits)
                if side=='book':require(book_turnover.get(key,[0,0])==[debits,credits])
                expected[side][key]=a['amount_cents']
            elif a['skr_code'] in MANAGED-(set(DEP.values())|{'62220','62620'}) or key.startswith('src_asset_valuation_'):
                require(a['amount_cents']==expected[side].get(key,0));expected[side].setdefault(key,0)
        for code in (set(DEP.values())|{'62220','62620'}):
            peers={k:a for k,a in accounts.items() if a['skr_code']==code}
            require(all(a['group']=='expense' for a in peers.values()) and sum(a['amount_cents'] for a in peers.values())==dep_totals[side].get(code,0))
            for key,a in peers.items():expected[side][key]=a['amount_cents']
    return {'proof':proof,'books':books,'taxes':taxes,'expected':expected,'book_totals':book_totals}

def prepare(values,account_mapping,profile,meta):
    from ebilanz_assets import ASSETS, MEASURES, GWG, INTANGIBLE, TANGIBLE
    from ebilanz_whole_club import asset_scope, spheres
    checked=rebuild(values);items=mapping(values['asset_schedule']['items']);rows={};cost_rows={}
    if items:
        require(profile.get('asset_scope')==asset_scope(profile) and all(i['plan']['sphere'] in spheres(profile) for i in items.values()))
        changed=values.get('period_section')!='books' and (bool(mapping(values['period_source']['review']['asset_tax_plans'])) or any(
            i['cost']['opening_difference_cents'] or i['cost']['closing_difference_cents'] or any(e['asset_difference_cents'] for e in mapping(i['cost']['events']).values())
            for i in mapping(checked['proof']['items']).values() if 'cost' in i))
        require(profile.get('asset_tax_values_reviewed') is True and profile.get('asset_values_unchanged') is False if changed else profile.get('asset_values_unchanged') is True)
    for identifier,item in items.items():
        target=account_mapping.get(item['fixed_account']);p=item['plan']
        if p.get('method')=='cost':
            from ebilanz_cost_assets import ELIGIBLE,ACCOUNTS,classified_counter_target,cost_target
            from ebilanz_cost_asset_grid import project
            require(p['account'] in ELIGIBLE and target==cost_target(p))
            for code,key in mapping(item['valuation_accounts']).items():require(account_mapping.get(key)==classified_counter_target(p,code))
            measures=project(item['valuation'],'book' if values.get('period_section')=='books' else 'tax',meta['version'])['columns'];row=cost_rows.setdefault(target,dict.fromkeys(measures,0))
            for c,n in measures.items():row[c]+=n
            continue
        require(target in ASSETS)
        from ebilanz_asset_types import DEP, accepts_target
        from ebilanz_asset_types import ACCOUNTS
        expected=ACCOUNTS[p['account']]['expense']
        require(accepts_target(p['account'],target) and expected in ASSETS.values())
        rows.setdefault(target,dict.fromkeys(FIELDS,0))
        for f,n in checked['taxes'][identifier].items():rows[target][f]+=n
    leaves=dict(rows)
    for leaf,amounts in leaves.items():
        seen=set();pending=list(meta['asset_parents'].get(leaf,[]))
        while pending:
            parent=pending.pop();require(parent!=leaf and parent not in leaves)
            if parent in seen:continue
            seen.add(parent);pending+=meta['asset_parents'].get(parent,[])
        require('bs.ass.fixAss' in seen)
        for parent in seen:
            rows.setdefault(parent,dict.fromkeys(FIELDS,0))
            for f,n in amounts.items():rows[parent][f]+=n
    columns=set(meta['asset_calculations'])|{c for children in meta['asset_calculations'].values() for c in children}|{'all_Prev_period'};result={}
    for target,amounts in rows.items():
        measures=dict.fromkeys(columns,0);measures.update({MEASURES[k]:v for k,v in amounts.items() if k in MEASURES})
        measures.update({'gross.release':amounts['disposals_cents'],'accDepr.release':amounts['accumulated_disposals_cents'],'accDepr.DeprPeriod':amounts['depreciation_cents']})
        require(all(measures[parent]==sum(measures[c]*weight for c,weight in children.items()) for parent,children in meta['asset_calculations'].items()))
        result[target]=measures
    for leaf,measures in cost_rows.items():
        seen=set();pending=list(meta['asset_parents'].get(leaf,[]))
        while pending:
            parent=pending.pop();require(parent!=leaf and parent not in cost_rows)
            if parent in seen:continue
            seen.add(parent);pending.extend(meta['asset_parents'].get(parent,[]))
        require('bs.ass.fixAss' in seen)
        for target in {leaf}|seen:
            row=result.setdefault(target,dict.fromkeys(measures,0))
            for column,n in measures.items():row[column]+=n
    require(all(row[p]==sum(row[c]*w for c,w in children.items()) for row in result.values() for p,children in meta['asset_calculations'].items()))
    return {'rows':result,'items':len(items),'totals':checked['proof']['tax_schedule']['totals'],'sha256':digest(checked['proof']['tax_schedule'])}
