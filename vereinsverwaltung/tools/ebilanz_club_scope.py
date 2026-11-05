"""Independently reconstruct the approved whole-club allocation, without network I/O.

The taxable SGEB comprises ALL sphere-4 units (§64(2) AO), not selected businesses.
Original sides, opening capital, reversals and zero-book-value assets are retained.
The PHP service separately verifies current authorization and immutable DB history.
"""
from datetime import date
import hashlib
import json
import re

LIMIT = 100000000000
SIDES = dict.fromkeys(('fixed_assets','inventory','receivables','cash','other_assets','prepaid'),'asset') | dict.fromkeys(('equity','reserves','result_use'),'capital') | dict.fromkeys(('provisions','liabilities','member_payables','deferred'),'liability') | {'revenue':'income','expense':'expense'}
FIELDS = ('cost_opening_cents','additions_cents','cost_closing_cents','accumulated_opening_cents','depreciation_cents','accumulated_closing_cents','book_opening_cents','book_closing_cents')
DISPOSALS = ('disposals_cents','accumulated_disposals_cents','book_disposals_cents')


def require(ok):
    if not ok:
        raise ValueError('Vereinsaufteilung: Herkunft, Vollständigkeit, Anlagen oder Kontensummen stimmen nicht überein.')


def digest(value):
    return hashlib.sha256(json.dumps(value,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()


def amount(value):
    require(type(value) is int and abs(value)<=LIMIT)
    return value


def mapping(value):
    if value == []: return {}
    require(isinstance(value,dict))
    return value


def lines(rows,chart,opening=False):
    require(isinstance(rows,list) and len(rows)<=1000 and (opening or len(rows)>=2))
    seen=set(); debit=credit=0
    for row in rows:
        require(isinstance(row,dict) and set(row)=={'account','debit_cents','credit_cents','sphere'})
        key=row['account']; require(isinstance(key,str) and key in chart)
        d,c=amount(row['debit_cents']),amount(row['credit_cents'])
        require(d>=0 and c>=0 and (d>0)!=(c>0))
        pl=chart[key]['group'] in ('revenue','expense')
        require(not opening or (not pl and key not in seen));seen.add(key)
        require(row['sphere'] in ('1','2','3','4') if pl else row['sphere']=='')
        debit=amount(debit+d);credit=amount(credit+c)
    require(debit==credit)


def calculate(year,chart,opening,entries,period=None):
    require(type(year) is int and 2000<=year<=2026 and isinstance(chart,dict) and 1<=len(chart)<=500)
    if period is not None:
        from ebilanz_period import check
        period = check(period,year)
    rows={}
    for key,a in sorted(chart.items()):
        require(isinstance(key,str) and re.fullmatch('[a-z][a-z0-9_-]{0,39}',key) and set(a)=={'name','group','skr_code'})
        require(isinstance(a['name'],str) and a['name'].strip() and len(a['name'])<=160 and not re.search(r'[\x00-\x1f]',a['name']) and a['group'] in SIDES)
        require(isinstance(a['skr_code'],str) and (not a['skr_code'] or re.fullmatch('[0-9]{5}',a['skr_code'])))
        rows[key]={'name':a['name'].strip(),'group':a['group'],'skr_code':a['skr_code'],'id':key,'opening_cents':0,'debit_cents':0,'credit_cents':0,'closing_cents':0}
    lines(opening,chart,True)
    for line in opening: rows[line['account']]['opening_cents']=line['debit_cents']-line['credit_cents']
    require(isinstance(entries,list) and len(entries)<=10000)
    spheres={s:{'income_cents':0,'expense_cents':0,'result_cents':0} for s in ('1','2','3','4')}
    seen=set();debit=credit=0
    for entry in entries:
        key=entry['id'];require(isinstance(key,str) and re.fullmatch('[a-f0-9]{32}',key) and key not in seen);seen.add(key)
        require(isinstance(entry['date'],str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}',entry['date']))
        actual = date.fromisoformat(entry['date']).isoformat()
        require(period['start']<=actual<=period['end'] if period is not None else date.fromisoformat(actual).year==year)
        lines(entry['lines'],chart)
        for line in entry['lines']:
            r=rows[line['account']];d,c=line['debit_cents'],line['credit_cents']
            r['debit_cents']=amount(r['debit_cents']+d);r['credit_cents']=amount(r['credit_cents']+c)
            debit=amount(debit+d);credit=amount(credit+c)
            if r['group'] in ('revenue','expense'):
                field='income_cents' if r['group']=='revenue' else 'expense_cents'
                spheres[line['sphere']][field]=amount(spheres[line['sphere']][field]+(c-d if field=='income_cents' else d-c))
    assets=[];capital=[];liabilities=[];pl=[];at=ct=lt=income=expense=trial=0
    for r in rows.values():
        r['closing_cents']=amount(r['opening_cents']+r['debit_cents']-r['credit_cents']);trial=amount(trial+r['closing_cents'])
        side=SIDES[r['group']];v=r['closing_cents'] if side in ('asset','expense') else -r['closing_cents'];r['amount_cents']=v
        from ebilanz_directional_closing import presentation
        display=presentation(year,r) or r;side=SIDES[display['group']];v=display['amount_cents']
        from ebilanz_account_closing import is_allowance
        from ebilanz_original_offsets import is_inventory_contra
        from ebilanz_original_reclassification import is_contra as original_split_contra
        require(side not in ('asset','liability') or v>=0 or is_allowance(year,r) or is_inventory_contra(year,r) or original_split_contra(year,r))
        if side=='asset':assets.append(display);at=amount(at+v)
        elif side=='capital':capital.append(display);ct=amount(ct+v)
        elif side=='liability':liabilities.append(display);lt=amount(lt+v)
        else:
            pl.append(display)
            if side=='income':income=amount(income+v)
            else:expense=amount(expense+v)
    from ebilanz_account_closing import receivables
    receivables(year,rows)
    from ebilanz_original_offsets import check_rows
    check_rows(year,rows)
    from ebilanz_original_reclassification import check_rows as original_split_rows
    original_split_rows(year,rows)
    from ebilanz_technical_closing import check_rows as technical_rows
    technical_rows(year,rows)
    for s in spheres.values():s['result_cents']=s['income_cents']-s['expense_cents']
    profit=amount(income-expense);passive=amount(ct+lt+profit);require(trial==0 and debit==credit and at==passive)
    return {'year':year,'accounts':rows,'assets':assets,'capital':capital,'liabilities':liabilities,'profit_and_loss':pl,'spheres':spheres,
            'asset_total_cents':at,'capital_total_cents':ct,'liability_total_cents':lt,'income_cents':income,'expense_cents':expense,
            'annual_result_cents':profit,'passive_total_cents':passive,'debit_cents':debit,'credit_cents':credit,'difference_cents':0,'issues':[],'entry_count':len(entries)} | ({'period':period} if period is not None else {})


def rebuild(scope,depth=0,with_source=False,with_segments=False):
    require(depth<=100 and isinstance(scope,dict) and set(scope)=={'schema','kind','source','allocation','review','previous'} and type(scope['schema']) is int and scope['schema'] in (1,2,3,4) and scope['kind']=='tax_privileged_aggregate')
    source=scope['source'];a=scope['allocation'];review=scope['review']
    require(isinstance(source,dict) and isinstance(a,dict) and isinstance(review,dict))
    keys={1:{'units','allocations','sha256'},2:{'units','allocations','capital_carry','sha256'},3:{'units','allocations','capital_carry','transfers','sha256'}}
    keys[4]=keys[3]|{'asset_shares'}
    require(set(a)==keys[scope['schema']] and set(review)=={'revision','sha256'} and type(review['revision']) is int and review['revision']>=2 and isinstance(review['sha256'],str) and re.fullmatch('[a-f0-9]{64}',review['sha256']))
    require({'year','accounts','state','issues','closed_sha256','opening','entries','calculation','result_account','asset_schedule'}<=set(source))
    year=source['year'];chart=source['accounts'];require(source['state']=='closed' and source['issues']==[] and isinstance(source['closed_sha256'],str) and re.fullmatch('[a-f0-9]{64}',source['closed_sha256']))
    require(isinstance(chart,dict) and isinstance(source['result_account'],str) and chart.get(source['result_account'],{}).get('group')=='equity')
    require(calculate(year,chart,source['opening'],source['entries'])==source['calculation'])
    units=a['units'];require(isinstance(units,dict) and 1<=len(units)<=32);names=set()
    for key,unit in units.items():
        require(isinstance(key,str) and re.fullmatch('[a-z][a-z0-9_-]{0,39}',key) and set(unit)=={'name','sphere'} and unit['sphere'] in ('1','2','3','4'))
        name=unit['name'];require(isinstance(name,str) and name==name.strip() and name and len(name)<=120 and not re.search(r'[\x00-\x1f]',name) and name.lower() not in names);names.add(name.lower())
    units=dict(sorted(units.items()));selected={k for k,u in units.items() if u['sphere']=='4'};require(bool(selected))
    capital={}
    if scope['schema']>=3:require(isinstance(a['capital_carry'],dict) or a['capital_carry']==[])
    if scope['schema']==2 or a.get('capital_carry'):
        require(isinstance(a['capital_carry'],dict) and bool(a['capital_carry']) and isinstance(source.get('previous_sha256'),str) and re.fullmatch('[a-f0-9]{64}',source['previous_sha256']))
        original_net={l['account']:l['debit_cents']-l['credit_cents'] for l in source['opening']}
        for account,parts in sorted(a['capital_carry'].items()):
            require(isinstance(account,str) and chart.get(account,{}).get('group')=='equity' and isinstance(parts,dict) and bool(parts) and not set(parts)-set(units))
            for value in parts.values():require(amount(value)!=0)
            require(min(parts.values())<0<max(parts.values()) and sum(parts.values())==original_net.get(account,0))
            capital[account]=dict(sorted(parts.items()))
    beginnings={u:[] for u in units};vouchers={u:{} for u in units};opening=[];entries={};normalized={}
    allocations=mapping(a['allocations']);used=set()
    sources=[{'id':'opening','date':str(year)+'-01-01','description':'Anfangsbestände','lines':source['opening']}]+source['entries']
    for entry in sources:
        for ordinal,line in enumerate(entry['lines']):
            if entry['id']=='opening' and line['account'] in capital:continue
            key=entry['id']+':'+str(ordinal);require(key in allocations);used.add(key);row=allocations[key]
            require(set(row)=={'parts','reason'} and isinstance(row['parts'],dict) and row['parts'] and not set(row['parts'])-set(units))
            reason=row['reason'];require(isinstance(reason,str) and reason==reason.strip() and reason and len(reason)<=1000 and not re.search(r'[\x00-\x1f]',reason))
            parts=dict(sorted(row['parts'].items()));total=line['debit_cents']+line['credit_cents'];included=0
            require(sum(amount(p) for p in parts.values())==total)
            def append(target_open,target_entries,value):
                l=dict(line);l['debit_cents']=value if line['debit_cents'] else 0;l['credit_cents']=value if line['credit_cents'] else 0
                if entry['id']=='opening':target_open.append(l)
                else:target_entries.setdefault(entry['id'],{'id':entry['id'],'date':entry['date'],'description':entry.get('description',entry['id']),'lines':[]})['lines'].append(l)
            for unit,p in parts.items():
                require(0<p<=total and (not line['sphere'] or line['sphere']==units[unit]['sphere']))
                append(beginnings[unit],vouchers[unit],p)
                if unit in selected:included+=p
            if included:append(opening,entries,included)
            normalized[key]={'parts':parts,'reason':reason}
    require(set(allocations)==used)
    def capital_line(account,net):
        return {'account':account,'debit_cents':max(0,net),'credit_cents':max(0,-net),'sphere':''}
    for account,parts in capital.items():
        for unit,net in parts.items():beginnings[unit].append(capital_line(account,net))
        net=sum(parts.get(unit,0) for unit in selected)
        if net:opening.append(capital_line(account,net))
    transfers={};eliminations={}
    if scope['schema']>=3:
        raw_transfers=mapping(a['transfers']);require(len(raw_transfers)<=10000 and (scope['schema']==4 or bool(raw_transfers)))
        for voucher,t in sorted(raw_transfers.items()):
            require(isinstance(voucher,str) and re.fullmatch('[a-f0-9]{32}',voucher) and isinstance(t,dict) and set(t)=={'account','parts','reason'})
            account=t['account'];reason=t['reason'];require(isinstance(account,str) and chart.get(account,{}).get('group')=='equity')
            require(isinstance(reason,str) and reason.strip() and len(reason)<=1000 and not re.search(r'[\x00-\x1f]',reason))
            require(isinstance(t['parts'],dict) and len(t['parts'])>=2 and not set(t['parts'])-set(units))
            expected={}
            for unit in units:
                rows=vouchers[unit].get(voucher,{}).get('lines',[])
                net=amount(sum(l['credit_cents']-l['debit_cents'] for l in rows))
                if net:expected[unit]=net
            parts=dict(sorted(t['parts'].items()))
            require(all(amount(v)!=0 for v in parts.values()) and parts==expected and sum(parts.values())==0)
            for unit,net in parts.items():vouchers[unit][voucher]['lines'].append(capital_line(account,net))
            debit=sum(max(0,parts.get(u,0)) for u in selected);credit=sum(max(0,-parts.get(u,0)) for u in selected)
            if debit!=credit:entries[voucher]['lines'].append(capital_line(account,debit-credit))
            eliminations[account]=eliminations.get(account,0)+sum(max(0,n) for n in parts.values())
            transfers[voucher]={'account':account,'parts':parts,'reason':reason.strip()}
    segments={k:u|{'opening':beginnings[k],'entries':list(vouchers[k].values()),'unbalanced':[],
                  'calculation':calculate(year,chart,beginnings[k],list(vouchers[k].values()))} for k,u in units.items()}
    for account,original in source['calculation']['accounts'].items():
        for field in ('opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'):
            total=sum(s['calculation']['accounts'][account][field] for s in segments.values())
            if field in ('debit_cents','credit_cents'):total-=eliminations.get(account,0)
            require(total==original[field])
    asset_shares={};asset_schedules={}
    if scope['schema']==4:
        from ebilanz_asset_allocation import rebuild_assets
        asset_shares,asset_schedules=rebuild_assets(source,units,segments,a['asset_shares'],digest)
    built={'schema':4 if asset_shares else (3 if transfers else (2 if capital else 1)),'year':year,'journal_sha256':source['closed_sha256'],'units':units,'allocations':dict(sorted(normalized.items())) or []}
    if capital or transfers or asset_shares:built['capital_carry']=capital or []
    if transfers or asset_shares:built['transfers']=transfers or []
    if asset_shares:built['asset_shares']=asset_shares
    built['segments']=segments
    if asset_shares:built['asset_schedules']=asset_schedules
    built.update(missing=[],issues=[],complete=True,tax_export_ready=False)
    require(digest(built)==a['sha256'])
    book=calculate(year,chart,opening,list(entries.values()))
    schedule=source.get('asset_schedule');require(isinstance(schedule,dict) and schedule.get('year')==year and schedule.get('issues')==[])
    fields=FIELDS+(DISPOSALS if 'disposals_cents' in schedule['totals'] else ())+(('impairment_cents','writeup_cents') if 'impairment_cents' in schedule['totals'] else ());require(set(schedule['totals'])==set(fields))
    fields=tuple(schedule['totals'])
    sums=dict.fromkeys(fields,0);totals=dict(sums);items={};all_sums={};selected_sums={}
    for key,item in mapping(schedule['items']).items():
        require(item['item_id']==key and item['sphere'] in ('1','2','3','4') and item['account'] in chart and chart[item['account']]['group']=='fixed_assets' and item['skr_code']==chart[item['account']]['skr_code'])
        for field in fields:
            v=amount(item.get(field,0) if item.get('method')!='cost' and field in DISPOSALS+('impairment_cents','writeup_cents') else item[field]);require(v>=0);sums[field]=amount(sums[field]+v)
            if item['sphere']=='4':totals[field]=amount(totals[field]+v)
        require(item['cost_opening_cents']+item['additions_cents']-item.get('disposals_cents',0)==item['cost_closing_cents'] and item['accumulated_opening_cents']+item['depreciation_cents']+item.get('impairment_cents',0)-item.get('writeup_cents',0)-item.get('accumulated_disposals_cents',0)==item['accumulated_closing_cents'])
        if item.get('method')=='cost':
            from ebilanz_asset_valuation import calculate as valuation,from_book_plan,FIELDS as COST_FIELDS
            value=item['valuation'];v=value['source']
            require(from_book_plan(item['cost_plan'])==v['plan'] and v['period']=={'start':f'{year}-01-01','end':f'{year}-12-31'} and valuation(v['plan'],v['period'],v['events'],v['previous'])==value)
            require(all(item[f]==value['book'][f] for f in COST_FIELDS) and item['depreciation_cents']==0 and item['sphere']==item['cost_plan']['sphere'] and item['skr_code']==item['cost_plan']['account'])
        require(item['cost_opening_cents']-item['accumulated_opening_cents']==item['book_opening_cents'] and item['cost_closing_cents']-item['accumulated_closing_cents']==item['book_closing_cents'] and item.get('disposals_cents',0)-item.get('accumulated_disposals_cents',0)==item.get('book_disposals_cents',0))
        for out in [all_sums]+([selected_sums] if item['sphere']=='4' else []):
            r=out.setdefault(item['account'],{'opening_cents':0,'closing_cents':0})
            r['opening_cents']+=item['book_opening_cents'];r['closing_cents']+=item['book_closing_cents']
        if item['sphere']=='4':items[key]=item
    require(sums==schedule['totals'])
    for calc,by_account in [(source['calculation'],all_sums),(book,selected_sums)]:
        for key,account in calc['accounts'].items():
            if account['group']=='fixed_assets':
                require(all(account[f]==by_account.get(key,{}).get(f,0) for f in ('opening_cents','closing_cents')))
    prior=scope['previous']
    if source.get('previous_sha256') is not None:
        require(isinstance(prior,dict));p=rebuild(prior,depth+1,with_segments=True)
        require(year==prior['source']['year']+1 and source['previous_sha256']==prior['source']['closed_sha256'] and source['result_account']==prior['source']['result_account'])
        require(chart[source['result_account']]['group']=='equity')
        for key,account in prior['source']['accounts'].items():require(key in chart and all(chart[key][f]==account[f] for f in ('group','skr_code')))
        carried={k:r['closing_cents']-(p['book']['annual_result_cents'] if k==source['result_account'] else 0) for k,r in p['book']['accounts'].items() if r['group'] not in ('expense','revenue')}
        require({k:v for k,v in carried.items() if v}=={l['account']:l['debit_cents']-l['credit_cents'] for l in opening})
        # Reconcile EACH unit, not just the taxable total. Opposing capital must
        # not be reassigned between businesses by resealing otherwise valid sums.
        for unit,previous in p['segments'].items():
            require(unit in units and units[unit]['sphere']==previous['sphere'])
            calc=previous['calculation']
            carried={k:r['closing_cents']-(calc['annual_result_cents'] if k==source['result_account'] else 0) for k,r in calc['accounts'].items() if r['group'] not in ('expense','revenue')}
            require({k:v for k,v in carried.items() if v}=={l['account']:l['debit_cents']-l['credit_cents'] for l in beginnings[unit]})
        for unit in set(units)-set(p['segments']):require(not beginnings[unit])
        for key,share in mapping(prior['allocation'].get('asset_shares',[])).items():
            current=asset_shares.get(key);require(isinstance(current,dict) and current['weights']==share['weights'] and current['measure']==share['measure'])
            old=prior['source']['asset_schedule']['items'][key];new=source['asset_schedule']['items'].get(key)
            require(isinstance(new,dict) and all(new.get(f)==old[f] for f in ('account','sphere','acquisition_hash')))
            require(all(new.get(f)==old.get(f) for f in ('skr_code','acquired_on','months','residual_cents','gwg','degressive')) and new.get('method','linear_monthly')==old.get('method','linear_monthly'))
            if 'disposal' in old:require(all(new.get(f)==old.get(f) for f in ('basis_cents','disposal','disposal_hash','disposal_accounts','partial_plan')))
            require(all(new.get(f+'_opening_cents')==old[f+'_closing_cents'] for f in ('cost','accumulated','book')))
    else:require(prior is None)
    result={'book':book,'asset_schedule':schedule|{'items':items or [],'totals':totals},'journal_sha256':digest(scope)}
    if with_source:result['source']={'year':year,'accounts':chart,'opening':opening,'entries':list(entries.values())}
    if with_segments:result['segments']=segments
    return result


def validate(values,profile,*,book_only=False):
    if 'period_club' in values:
        require(profile.get('kind')=='club_taxable_businesses' and profile.get('source_scope')=='approved_club_allocation' and profile.get('tax_privileged') is True and not book_only)
        from ebilanz_period_club import verify
        verify(values)
        return
    if profile.get('kind')=='whole_taxable_club':
        from ebilanz_whole_club import validate as validate_whole
        validate_whole(values,profile,book_only=book_only)
        return
    require('whole_club_scope' not in values)
    if profile.get('kind')=='club_taxable_businesses':
        require(profile.get('source_scope')=='approved_club_allocation' and profile.get('tax_privileged') is True and isinstance(values.get('club_scope'),dict))
        expected=rebuild(values['club_scope'],with_source=True)
        original=expected.pop('source')
        require(all(values.get(k)==v for k,v in expected.items()))
        from ebilanz_whole_club import validate_tax
        validate_tax(values,original)
    else:
        require(profile.get('kind')=='single_taxable_business' and profile.get('source_scope')=='separate_business_ledger' and 'club_scope' not in values)
