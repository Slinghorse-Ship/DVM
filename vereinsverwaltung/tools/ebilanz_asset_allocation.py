"""Independently verify internal shared-asset workpapers, not tax ownership choices."""
import re
from datetime import date
from ebilanz_degressive import depreciation
from ebilanz_sales import sale_values, sale_codes, movement, choose_account, retained_accounts
from ebilanz_partial_assets import item_values as partial_values
from ebilanz_asset_types import DISPOSABLE, LINEAR, DEP

FIELDS=('cost_opening_cents','additions_cents','cost_closing_cents','accumulated_opening_cents','depreciation_cents','accumulated_closing_cents','book_opening_cents','book_closing_cents')
DISPOSALS=('disposals_cents','accumulated_disposals_cents','book_disposals_cents')
LIMIT=100000000000

def require(ok):
    if not ok:raise ValueError('Anlagen-Nutzungsaufteilung und Originalwerte stimmen nicht überein.')

def fields(item):
    present=set(DISPOSALS)&set(item)
    require(not present or present==set(DISPOSALS))
    valuation={'impairment_cents','writeup_cents'}&set(item)
    require(not valuation or valuation=={'impairment_cents','writeup_cents'} and present==set(DISPOSALS))
    return FIELDS+(DISPOSALS if present else ())+ (('impairment_cents','writeup_cents') if valuation else ())

def disposal_proof(item,acquired,year):
    if 'disposal' not in item:
        require(not {'basis_cents','disposal_hash','disposal_accounts','partial_plan'}&set(item))
        require(all(type(item[f]) is int and item[f]==0 for f in DISPOSALS if f in item))
        return None
    p=item['disposal'];require(isinstance(p,dict) and p.get('kind') in ('scrap','sale','software_end','partial_scrap'))
    partial=p['kind']=='partial_scrap';require(partial or 'partial_plan' not in item)
    require(fields(item)==FIELDS+DISPOSALS and isinstance(item.get('disposal_hash'),str) and re.fullmatch('[a-f0-9]{64}',item['disposal_hash']))
    require(p.get('complete') is (False if partial else True) and isinstance(p.get('reason'),str) and p['reason'].strip() and len(p['reason'])<=2000)
    require(isinstance(p.get('document_id'),str) and re.fullmatch('[a-f0-9]{32}',p['document_id']) and type(p.get('revision')) is int and p['revision']>0)
    require(isinstance(p.get('sha256'),str) and re.fullmatch('[a-f0-9]{64}',p['sha256']) and isinstance(p.get('date'),str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}',p['date']))
    disposed=date.fromisoformat(p['date']);require(disposed>=acquired and disposed.year<=year)
    if partial:partial_values(item,year)
    elif p['kind']=='sale':sale_values(p,0)
    else:
        keys={'kind','date','reason','document_id','revision','sha256','complete','no_proceeds'}
        if p['kind']=='software_end':keys.add('rights_ended');require(p.get('rights_ended') is True and item['skr_code']=='01350' and item.get('method','linear_monthly')=='linear_monthly')
        require(set(p)==keys and p['no_proceeds'] is True)
    require(item['skr_code'] in (('01350',) if p['kind']=='software_end' else DISPOSABLE))
    return disposed

def annual_values(item,year):
    """Recompute the original whole asset before dividing any value or tax limit."""
    method=item.get('method','linear_monthly');code=item.get('skr_code')
    require(method in ('linear_monthly','gwg_immediate','degressive') and (method=='gwg_immediate' or 'gwg' not in item) and (method=='degressive' or 'degressive' not in item))
    cost=item.get('basis_cents') if 'disposal' in item else item.get('cost_closing_cents');months=item.get('months');residual=item.get('residual_cents');acquired=item.get('acquired_on')
    require(type(cost) is int and 0<cost<=LIMIT and type(months) is int and type(residual) is int and 0<=residual<cost)
    require(isinstance(acquired,str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}',acquired))
    acquired=date.fromisoformat(acquired)
    require(type(year) is int and 1900<=year<=2200 and 1900<=acquired.year<=2200)
    disposed=disposal_proof(item,acquired,year);through_month=disposed.month if disposed and disposed.year==year else 12
    if item.get('disposal',{}).get('kind')=='partial_scrap':return partial_values(item,year)
    if method=='gwg_immediate':
        p=item.get('gwg');require(isinstance(p,dict) and set(p)=={'net_cents','vat_cents','deductible_cents','independent_movable','no_pool'})
        require(all(type(p[k]) is int and p[k]>=0 for k in ('net_cents','vat_cents','deductible_cents')))
        require(acquired.year in (2025,2026) and 0<p['net_cents']<=80000 and p['vat_cents']<=(p['net_cents']*19+50)//100 and p['deductible_cents']<=p['vat_cents'])
        require(cost==p['net_cents']+p['vat_cents']-p['deductible_cents'] and p['independent_movable'] is True and p['no_pool'] is True)
        require(code=='06700' and months==1 and residual==0)
        ao=cost if year>acquired.year else 0;ac=cost if year>=acquired.year else 0
    else:
        from ebilanz_original_depreciation import code as original_depreciation_code,ordinary_gwg
        original_depreciation_code(item)
        require((code in LINEAR or ordinary_gwg(item)) and 12<=months<=1200 and months%12==0)
        if method=='degressive':ao,ac=depreciation(cost,acquired,months,item.get('degressive'),year,code,residual,through_month)
        else:
            before=max(0,min(months,(year-acquired.year)*12-(acquired.month-1)))
            through=max(0,min(months,(year-acquired.year)*12+through_month-(acquired.month-1)))
            ao=((cost-residual)*before+months//2)//months;ac=((cost-residual)*through+months//2)//months
    before=year>acquired.year
    out=dict(zip(FIELDS,(cost if before else 0,0 if before else cost,cost,ao,ac-ao,ac,cost-ao if before else 0,cost-ac)))
    if fields(item)!=FIELDS:out.update(dict.fromkeys(DISPOSALS,0))
    if disposed:
        if disposed.year<year:return dict.fromkeys(fields(item),0)
        out.update(cost_closing_cents=0,accumulated_closing_cents=0,book_closing_cents=0,disposals_cents=cost,accumulated_disposals_cents=ac,book_disposals_cents=cost-ac)
    return out

def cost_value(item,period=None):
    from ebilanz_asset_valuation import from_book_plan,calculate,FIELDS as COST_FIELDS
    p=item.get('cost_plan');value=item.get('valuation');require(isinstance(p,dict) and isinstance(value,dict))
    s=value.get('source');require(isinstance(s,dict) and from_book_plan(p)==s['plan'])
    require(period is None or s['period']==period)
    require(calculate(s['plan'],s['period'],s['events'],s['previous'])==value)
    require(item.get('skr_code')==p['account'] and item.get('sphere')==p['sphere'] and type(item.get('depreciation_cents')) is int and item['depreciation_cents']==0 and type(item.get('expected_depreciation_cents')) is int and item['expected_depreciation_cents']==0)
    require(all(type(item.get(f)) is int and item[f]==value['book'][f] for f in COST_FIELDS))
    return value

def check_year(item,year):
    require(isinstance(item,dict))
    if item.get('method')=='cost':
        cost_value(item,{'start':f'{year}-01-01','end':f'{year}-12-31'})
        return
    expected=annual_values(item,year)
    require(int(item['acquired_on'][:4])<=year)
    require(all(type(item.get(f)) is int and item[f]==n for f,n in expected.items()) and type(item.get('expected_depreciation_cents')) is int and item['expected_depreciation_cents']==expected['depreciation_cents'])

def cents(total,weights):
    require(type(total) is int and 0<=total<=LIMIT and isinstance(weights,dict) and 1<=len(weights)<=32)
    for key,w in weights.items():require(isinstance(key,str) and re.fullmatch('[a-z][a-z0-9_-]{0,39}',key) and type(w) is int and 1<=w<=1000000)
    weights=dict(sorted(weights.items()));denominator=sum(weights.values())
    out={k:total*w//denominator for k,w in weights.items()}
    for _ in range(total-sum(out.values())):
        winner=next(iter(weights))
        for k,w in weights.items():
            if w*(out[winner]+1)>weights[winner]*(out[k]+1):winner=k
        out[winner]+=1
    return out

def capped(total,weights,caps):
    result=cents(0,weights);require(set(caps)==set(result) and type(total) is int and 0<=total<=LIMIT)
    require(all(type(n) is int and 0<=n<=LIMIT for n in caps.values()) and total<=sum(caps.values()))
    active=dict(sorted(weights.items()))
    while active:
        denominator=sum(active.values());saturated=next((u for u,w in active.items() if caps[u]*denominator<=total*w),None)
        if saturated is None:break
        result[saturated]=caps[saturated];total-=caps[saturated];del active[saturated]
    if not active:return result
    denominator=sum(active.values());remainder=total
    for u,w in active.items():result[u]=total*w//denominator;remainder-=result[u]
    for _ in range(remainder):
        winner=None
        for u,w in active.items():
            if result[u]<caps[u] and (winner is None or w*(result[winner]+1)>active[winner]*(result[u]+1)):winner=u
        require(winner is not None);result[winner]+=1
    return result

def partial_parts(item,weights,year):
    check_year(item,year);p=item['partial_plan'];proof=item['disposal'];event_year=int(proof['date'][:4])
    event=partial_values(item,event_year);acquired=date.fromisoformat(p['acquired_on']);disposed=date.fromisoformat(proof['date'])
    months=max(0,min(p['months'],(disposed.year-acquired.year)*12+disposed.month-acquired.month+1))
    total_dep=((p['basis_cents']-p['residual_cents'])*months+p['months']//2)//p['months']
    cost=cents(p['basis_cents'],weights);dep=cents(total_dep,weights);book={u:cost[u]-dep[u] for u in cost}
    lost_dep=capped(event['accumulated_disposals_cents'],weights,dep);lost_book=capped(event['book_disposals_cents'],weights,book)
    remaining_cost={u:cost[u]-lost_dep[u]-lost_book[u] for u in cost};remaining_dep={u:dep[u]-lost_dep[u] for u in cost};capacity={u:remaining_cost[u]-remaining_dep[u] for u in cost}
    after=capped(item['accumulated_closing_cents']-sum(remaining_dep.values()),weights,capacity)
    old=event_year<year;now=event_year==year;new=acquired.year==year
    before=capped(item['accumulated_opening_cents']-sum(remaining_dep.values()),weights,capacity) if old else cents(item['accumulated_opening_cents'],weights)
    out={}
    for u,c in cost.items():
        co=0 if new else remaining_cost[u] if old else c;ao=remaining_dep[u]+before[u] if old else before[u];ac=remaining_dep[u]+after[u];dc=lost_dep[u]+lost_book[u] if now else 0;dd=lost_dep[u] if now else 0
        out[u]=dict(zip(FIELDS+DISPOSALS,(co,c if new else 0,remaining_cost[u],ao,ac-ao+dd,ac,co-ao,remaining_cost[u]-ac,dc,dd,dc-dd)))
        require(min(out[u].values())>=0)
    require(all(sum(r[f] for r in out.values())==item[f] for f in FIELDS+DISPOSALS))
    return out

def item_parts(item,weights,year=None):
    require(isinstance(item,dict) and type(item.get('expected_depreciation_cents')) is int)
    if item.get('method')=='cost':
        from ebilanz_cost_asset_allocation import split
        if year is not None:check_year(item,year)
        return split(cost_value(item),weights)['parts']
    require(not {'impairment_cents','writeup_cents'}&set(item))
    fs=fields(item);v={f:item.get(f) for f in fs}
    require(all(type(n) is int and 0<=n<=LIMIT for n in v.values()))
    require(v['cost_opening_cents']+v['additions_cents']-v.get('disposals_cents',0)==v['cost_closing_cents'] and v['accumulated_opening_cents']+v['depreciation_cents']-v.get('accumulated_disposals_cents',0)==v['accumulated_closing_cents'])
    require(v.get('disposals_cents',0)-v.get('accumulated_disposals_cents',0)==v.get('book_disposals_cents',0))
    require(v['cost_opening_cents']-v['accumulated_opening_cents']==v['book_opening_cents'] and v['cost_closing_cents']-v['accumulated_closing_cents']==v['book_closing_cents'] and item.get('expected_depreciation_cents')==v['depreciation_cents'])
    annual_values(item,2200)
    if item.get('disposal',{}).get('kind')=='partial_scrap':
        require(type(year) is int)
        return partial_parts(item,weights,year)
    split={f:cents(v[f],weights) for f in ('cost_opening_cents','cost_closing_cents','accumulated_opening_cents','accumulated_closing_cents')}
    before_cost=cents(v['cost_opening_cents']+v['additions_cents'],weights);before_dep=cents(v['accumulated_opening_cents']+v['depreciation_cents'],weights)
    out={}
    for u in split['cost_closing_cents']:
        co,cc,ao,ac=(split[f][u] for f in split)
        row=dict(zip(FIELDS,(co,before_cost[u]-co,cc,ao,before_dep[u]-ao,ac,co-ao,cc-ac)))
        if fs!=FIELDS:row.update(disposals_cents=before_cost[u]-cc,accumulated_disposals_cents=before_dep[u]-ac,book_disposals_cents=before_cost[u]-cc-before_dep[u]+ac)
        require(min(row.values())>=0);out[u]=row
    for f in fs:require(sum(r[f] for r in out.values())==v[f])
    return out

def disposal_movements(item,chart,weights,parts,year):
    p=item.get('disposal')
    if not p:return {},set()
    if p['kind']=='sale':gain,loss=sale_codes(p);codes={gain:'revenue','48550':'revenue',loss:'expense','68950':'expense'}
    else:codes={('68960' if p['kind']=='software_end' else '68950'):'expense'}
    out={}
    for code,group in codes.items():
        key=choose_account(item,chart,code,group);amount=movement(item,code,year) or 0
        split={u:(r['book_disposals_cents'] if amount else 0) for u,r in parts.items()} if code in ('48550','68950','68960') else cents(abs(amount),weights)
        for u,n in split.items():out.setdefault(u,{})[key]=(-1 if amount<0 else 1)*n
    return out,set(codes)

def rebuild_assets(source,units,segments,shares,digest):
    schedule=source.get('asset_schedule',{});items=schedule.get('items')
    require(isinstance(items,dict) and 1<=len(items)<=10000 and schedule.get('year')==source['year'] and schedule.get('issues')==[])
    require(isinstance(shares,dict) and set(shares)==set(items))
    fs=fields(schedule['totals']);out={u:{'items':{},'totals':dict.fromkeys(fs,0)} for u in units};normalized={};whole=dict.fromkeys(fs,0);account_totals={};disposal_amounts={};disposal_codes=set()
    for key,item in items.items():
        require(isinstance(item,dict) and isinstance(key,str) and 1<=len(key.encode('utf8'))<=160 and not re.search(r'[\x00-\x1f]',key) and item.get('item_id')==key)
        account=source['accounts'].get(item.get('account'),{})
        require(account.get('group')=='fixed_assets' and account.get('skr_code')==item.get('skr_code'))
        item_parts(item,{'validation':1},source['year'])
        check_year(item,source['year'])
        require(fields(item)==fs or len(fs)==13 and not set(fields(item))-set(fs))
        for f in fs:whole[f]+=item.get(f,0)
        target=account_totals.setdefault(item['account'],dict(opening_cents=0,closing_cents=0))
        target['opening_cents']+=item['book_opening_cents'];target['closing_cents']+=item['book_closing_cents']
        s=shares[key]
        require(isinstance(s,dict) and list(s)==['item_sha256','weights','measure','reason'] and s['item_sha256']==digest(item))
        weights=s['weights'];require(isinstance(weights,dict) and bool(weights) and not set(weights)-set(units))
        require(s['measure'] in ('hours','days','uses','area') and isinstance(s['reason'],str) and s['reason'].strip() and len(s['reason'])<=1000 and not re.search(r'[\x00-\x1f]',s['reason']))
        require(all(units[u]['sphere']==item.get('sphere') for u in weights))
        weights=dict(sorted(weights.items()));parts=item_parts(item,weights,source['year'])
        if item.get('method')=='cost':
            from ebilanz_cost_asset_allocation import movements
            amounts,codes=movements(item,source['accounts'],weights)
        else:amounts,codes=disposal_movements(item,source['accounts'],weights,parts,source['year'])
        disposal_codes.update(codes)
        for u,rows in amounts.items():
            target=disposal_amounts.setdefault(u,{})
            for k,n in rows.items():target[k]=target.get(k,0)+n
        normalized[key]={'item_sha256':s['item_sha256'],'weights':weights,'measure':s['measure'],'reason':s['reason'].strip()}
        for u,row in parts.items():
            row={f:row.get(f,0) for f in fs}
            out[u]['items'][key]=row
            for f in fs:out[u]['totals'][f]+=row[f]
    require(all(schedule['totals'].get(f)==whole[f] for f in fs))
    for account,r in source['calculation']['accounts'].items():
        if r['group']=='fixed_assets':require(all(account_totals.get(account,{}).get(f,0)==r[f] for f in ('opening_cents','closing_cents')))
    for u,s in out.items():
        totals={};dep={}
        for key,row in s['items'].items():
            item=items[key];target=totals.setdefault(item['account'],dict(opening_cents=0,closing_cents=0))
            target['opening_cents']+=row['book_opening_cents'];target['closing_cents']+=row['book_closing_cents']
            if item.get('method')!='cost':
                from ebilanz_original_depreciation import code as original_depreciation_code
                code=original_depreciation_code(item);dep[code]=dep.get(code,0)+row['depreciation_cents']
        rows=segments[u]['calculation']['accounts']
        for key,r in rows.items():
            if r['group']=='fixed_assets':require(all(totals.get(key,{}).get(f,0)==r[f] for f in ('opening_cents','closing_cents')))
            if r['skr_code'] in disposal_codes:require(disposal_amounts.get(u,{}).get(key,0)==r['amount_cents'])
        for code in ('62000','62200','62600','62220','62620','62500'):require(sum(r['closing_cents'] for r in rows.values() if r['group']=='expense' and r['skr_code']==code)==dep.get(code,0))
        s['items']=dict(sorted(s['items'].items())) or []
    return dict(sorted(normalized.items())),out
