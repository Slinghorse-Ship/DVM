"""Independent dated gross-asset arithmetic; no I/O or tax eligibility inference."""
from calendar import monthrange
from copy import deepcopy
from datetime import date, timedelta
import re
from ebilanz_period import check, require
from ebilanz_partial_assets import number, document, reason, values as partial_year
from ebilanz_asset_types import ACCOUNTS, DISPOSABLE

FIELDS = ('cost_opening_cents','additions_cents','cost_closing_cents',
          'accumulated_opening_cents','depreciation_cents','accumulated_closing_cents',
          'book_opening_cents','book_closing_cents','disposals_cents',
          'accumulated_disposals_cents','book_disposals_cents')

def flat(s):
    return {f:number(s.get(f,0)) for f in FIELDS}

def full_months(period):
    p=check(period);a=date.fromisoformat(p['start']);b=date.fromisoformat(p['end'])
    require(a.day==1 and b.day==monthrange(b.year,b.month)[1], 'Anlagen: Angebrochene Monate gesondert prüfen.')
    return (b.year-a.year)*12+b.month-a.month+1

def elapsed(start,end):
    a=date.fromisoformat(start);b=date.fromisoformat(end)
    return (b.year-a.year)*12+b.month-a.month

def rounded(basis,used,months):
    return (basis*used+months//2)//months

def plan_check(p):
    require(type(p) is dict and set(p)<= {'basis_cents','residual_cents','months','acquired_on','sphere','account','method','reason','gwg','degressive','depreciation_code'})
    from ebilanz_original_depreciation import code as original_depreciation_code,ordinary_gwg
    original_depreciation_code(p)
    cost=number(p.get('basis_cents'),1);residual=number(p.get('residual_cents'),0,cost-1)
    require(type(p.get('acquired_on')) is str and re.fullmatch(r'\d{4}-\d{2}-\d{2}',p['acquired_on']))
    acquired=date.fromisoformat(p['acquired_on']);require(1900<=acquired.year<=2026)
    require(p.get('sphere') in ('1','2','3','4') and p.get('account') in ACCOUNTS)
    method=p.get('method','linear_monthly');months=number(p.get('months'),1,1200)
    if method=='gwg_immediate':
        g=p.get('gwg');require(type(g) is dict and set(g)=={'net_cents','vat_cents','deductible_cents','independent_movable','no_pool'})
        net,vat,deductible=(number(g[k]) for k in ('net_cents','vat_cents','deductible_cents'))
        require(months==1 and residual==0 and p['account']=='06700' and 'degressive' not in p
                and 0<net<=80000 and vat<=(net*19+50)//100 and deductible<=vat and cost==net+vat-deductible
                and g['independent_movable'] is True and g['no_pool'] is True and acquired.year in (2025,2026))
    elif method=='degressive':
        from ebilanz_degressive import depreciation
        require('gwg' not in p)
        depreciation(cost,acquired,months,p.get('degressive'),acquired.year,p['account'],residual)
    else:
        require(method=='linear_monthly' and months>=12 and months%12==0 and 'gwg' not in p and 'degressive' not in p and (ACCOUNTS[p['account']]['kind'] in ('software','intangible','movable','building','goodwill','immovable_fixture') or ordinary_gwg(p)))
    if ACCOUNTS[p['account']]['kind']=='goodwill':require(method=='linear_monthly' and residual==0)
    return cost,residual,months,method

def ordinary(p,period,previous=None):
    period=check(period);active_period=full_months(period);cost,residual,months,method=plan_check(p)
    acquired=p['acquired_on'];before=acquired<period['start'];present=acquired<=period['end']
    state=None
    if method=='degressive':
        used=elapsed(acquired,period['start']) if before else 0
        linear=switched=None;opening_dep=0;opening=cost if present else 0
        if before:
            require(previous is not None and date.fromisoformat(previous['period']['end'])+timedelta(days=1)==date.fromisoformat(period['start']))
            s=previous.get('degressive_state');require(type(s) is dict and set(s)=={'elapsed_months','linear','switched_period'} and s['elapsed_months']==used)
            require(previous['cost_closing_cents']==cost)
            opening_dep=number(previous['accumulated_closing_cents']);opening=number(previous['book_closing_cents']);require(opening_dep+opening==cost)
            linear=deepcopy(s['linear']);switched=s['switched_period']
            if linear is not None:
                require(type(linear) is dict and set(linear)=={'basis','months','used'})
                number(linear['basis'],1,cost);number(linear['months'],1,months);number(linear['used'],0,linear['months'])
                require(type(switched) is str and re.fullmatch('[0-9]{8}-[0-9]{8}',switched)
                        and p['degressive']['switch_to_linear'] and linear['basis']-rounded(linear['basis'],linear['used'],linear['months'])==opening)
            else:require(switched is None)
        else:require(previous is None)
        active=min(active_period,max(0,elapsed(acquired,period['end'])+1-used));charge=0
        if opening:
            declining=(opening*p['degressive']['rate_basis_points']*active+60000)//120000
            remaining=max(0,months-used);straight=rounded(opening,min(remaining,active),remaining) if remaining else opening
            if linear is None and p['degressive']['switch_to_linear'] and straight>=declining:
                linear={'basis':opening,'months':max(1,remaining),'used':0};switched=period['start'].replace('-','')+'-'+period['end'].replace('-','')
            if linear is not None:
                old=linear['used'];linear['used']=min(linear['months'],old+active)
                charge=rounded(linear['basis'],linear['used'],linear['months'])-rounded(linear['basis'],old,linear['months'])
            else:charge=declining
            charge=min(opening,charge)
        closing_dep=opening_dep+charge;state={'elapsed_months':used+active,'linear':linear,'switched_period':switched}
    elif method=='gwg_immediate':opening_dep=cost if before else 0;closing_dep=cost if present else 0
    else:
        opening_dep=rounded(cost-residual,max(0,min(months,elapsed(acquired,period['start']))),months) if before else 0
        closing_dep=rounded(cost-residual,max(0,min(months,elapsed(acquired,period['end'])+1)),months) if present else 0
    copen=cost if before else 0;cclose=cost if present else 0
    result={'period':period,'cost_opening_cents':copen,'additions_cents':cclose-copen,'cost_closing_cents':cclose,
            'accumulated_opening_cents':opening_dep,'depreciation_cents':closing_dep-opening_dep,'accumulated_closing_cents':closing_dep,
            'book_opening_cents':copen-opening_dep,'book_closing_cents':cclose-closing_dep}
    if state is not None:result['degressive_state']=state
    return result

def disposal_check(p,d):
    require(type(d) is dict and d.get('kind') in ('scrap','partial_scrap','software_end','sale'))
    document(d);reason(d.get('reason'));require(type(d.get('date')) is str and re.fullmatch(r'\d{4}-\d{2}-\d{2}',d['date']))
    date.fromisoformat(d['date']);require(d['date']>=p['acquired_on'])
    if d['kind']=='partial_scrap':partial_year(p,d,int(d['date'][:4]));return
    require(d.get('complete') is True)
    common={'kind','date','reason','document_id','revision','sha256','complete'}
    if d['kind']=='software_end':
        require(set(d)==common|{'no_proceeds','rights_ended'} and d['no_proceeds'] is True and d['rights_ended'] is True
                and p['account']=='01350' and p.get('method','linear_monthly')=='linear_monthly')
    else:
        require(p['account'] in DISPOSABLE)
        if d['kind']=='scrap':require(set(d)==common|{'no_proceeds'} and d['no_proceeds'] is True)
        else:
            from ebilanz_sales import sale_values
            sale_values(d,0)

def partial(p,d,period,tax_months=None):
    partial_year(p,d,int(period['end'][:4]),tax_months) # Independently checked component evidence.
    cost=p['basis_cents'];residual=p['residual_cents'];months=tax_months or p['months'];a=d['allocation']
    def cumulative(n):
        total=rounded(cost-residual,max(0,min(months,n)),months)
        part=rounded(total,a['cost_cents']-a['residual_cents'],cost-residual)
        return total,part,total-part
    before=cumulative(elapsed(p['acquired_on'],period['start']));after=cumulative(elapsed(p['acquired_on'],period['end'])+1)
    event=cumulative(elapsed(p['acquired_on'],d['date'])+1);old=d['date']<period['start'];acquired=p['acquired_on']>=period['start']
    remaining=cost-a['cost_cents'];copen=0 if acquired else remaining if old else cost;accopen=0 if acquired else before[2 if old else 0]
    dep=after[2]-before[2] if old else event[0]-before[0]+after[2]-event[2]
    return {'period':period,'cost_opening_cents':copen,'additions_cents':cost if acquired else 0,'disposals_cents':0 if old else a['cost_cents'],
            'cost_closing_cents':remaining,'accumulated_opening_cents':accopen,'depreciation_cents':dep,
            'accumulated_disposals_cents':0 if old else event[1],'accumulated_closing_cents':after[2],
            'book_opening_cents':copen-accopen,'book_disposals_cents':0 if old else a['cost_cents']-event[1],
            'book_closing_cents':remaining-after[2],'remaining_residual_cents':residual-a['residual_cents']}

def schedule(p,period,previous=None,disposal=None,tax_months=None):
    period=check(period);full_months(period);plan_check(p)
    require(p['acquired_on']<=period['end'])
    if disposal is None:return ordinary(p,period,previous)
    disposal_check(p,disposal);require(disposal['date']<=period['end'])
    if disposal['kind']=='partial_scrap':result=partial(p,disposal,period,tax_months)
    elif disposal['date']<period['start']:result={'period':period}|dict.fromkeys(FIELDS,0)
    else:
        d=date.fromisoformat(disposal['date']);end=date(d.year,d.month,monthrange(d.year,d.month)[1]).isoformat()
        result=ordinary(p,{'start':period['start'],'end':end},previous);result['period']=period
        result.update(disposals_cents=result['cost_closing_cents'],accumulated_disposals_cents=result['accumulated_closing_cents'],book_disposals_cents=result['book_closing_cents'])
        result.update(cost_closing_cents=0,accumulated_closing_cents=0,book_closing_cents=0);result.pop('degressive_state',None)
    if previous is not None:
        require(date.fromisoformat(previous['period']['end'])+timedelta(days=1)==date.fromisoformat(period['start']))
        require(all(previous[f+'_closing_cents']==result[f+'_opening_cents'] for f in ('cost','accumulated','book')))
    else:require(p['acquired_on']>=period['start'])
    reconcile(flat(result));return result

def reconcile(s):
    s=flat(s)
    require(s['cost_opening_cents']+s['additions_cents']-s['disposals_cents']==s['cost_closing_cents']
            and s['accumulated_opening_cents']+s['depreciation_cents']-s['accumulated_disposals_cents']==s['accumulated_closing_cents']
            and s['cost_opening_cents']-s['accumulated_opening_cents']==s['book_opening_cents']
            and s['cost_closing_cents']-s['accumulated_closing_cents']==s['book_closing_cents']
            and s['disposals_cents']-s['accumulated_disposals_cents']==s['book_disposals_cents']
            and s['book_opening_cents']+s['additions_cents']-s['depreciation_cents']-s['book_disposals_cents']==s['book_closing_cents'])
