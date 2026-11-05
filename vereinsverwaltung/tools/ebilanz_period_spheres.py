"""Independent monthly sphere/tax replay; no journal mutation or transport.

Whole-club BAL contains original gross book values. Taxable net assets and
capital movements belong to the subsidiary computation, not a shadow balance.
"""
from datetime import date, timedelta
import re
from ebilanz_club_scope import digest, mapping, amount
from ebilanz_period import require, check
from ebilanz_period_asset_math import schedule, number, full_months
from ebilanz_asset_types import LINEAR

FIELDS=('on','to_sphere','case','value_cents','remaining_months','residual_cents','document_id','revision','sha256','reason',
        'charitable_use','funds_reviewed','vat_reviewed','tax_reviewed','history_reviewed')
CASES=('exempt_to_exempt','exempt_book','exempt_value','taxable_value','taxable_return')


def month_next(on):
    d=date.fromisoformat(on)
    return date(d.year+(d.month==12),d.month%12+1,1).isoformat()


def clock_value(clock,on):
    a,b=date.fromisoformat(clock['on']),date.fromisoformat(on)
    m=max(0,min(clock['months'],(b.year-a.year)*12+b.month-a.month))
    return clock['basis_cents']-((clock['basis_cents']-clock['residual_cents'])*m+clock['months']//2)//clock['months']


def replay(proof,previous=None):
    require(type(proof) is dict and set(proof)=={'schema','period','plan','changes','previous_sha256','history','book','tax','closing_sphere','sha256'}
            and type(proof['schema']) is int and proof['schema']==1)
    p=proof['plan'];period=check(proof['period']);full_months(period)
    require(p.get('method','linear_monthly')=='linear_monthly' and 'gwg' not in p and 'degressive' not in p
            and p['account'] in LINEAR and p['sphere'] in ('1','2','3','4') and p['acquired_on']<=period['end'])
    book=schedule(p,period,None,None)
    require(type(proof['history']) is list and len(proof['history'])<=120 and type(proof['changes']) is list and len(proof['changes'])<=12)
    if previous is not None:
        # Recursive period verification validates the predecessor itself. Replay
        # also checks exact chained histories and not merely a caller's new hash.
        require(previous['plan']==p and date.fromisoformat(previous['period']['end'])+timedelta(days=1)==date.fromisoformat(period['start']))
        require(proof['previous_sha256']==previous['sha256'] and proof['history']==previous['history']+proof['changes'])
    else:require(proof['previous_sha256'] is None and proof['history']==proof['changes'])
    require(proof['changes']==[c for c in proof['history'] if c['on']>=period['start']])
    moves={};last=''
    for c in proof['history']:
        require(type(c) is dict and set(c)==set(FIELDS) and type(c['on']) is str and re.fullmatch(r'\d{4}-\d{2}-01',c['on']))
        date.fromisoformat(c['on'])
        require(last<c['on']<=period['end'] and c['on']>p['acquired_on'] and c['to_sphere'] in ('1','2','3','4') and c['case'] in CASES)
        for key in ('value_cents','remaining_months','residual_cents'):number(c[key],0,100000000000)
        require(type(c['document_id']) is str and re.fullmatch('[a-f0-9]{32}',c['document_id']) and type(c['sha256']) is str and re.fullmatch('[a-f0-9]{64}',c['sha256']))
        number(c['revision'],1,100000000000)
        require(type(c['reason']) is str and c['reason']==c['reason'].strip() and 0<len(c['reason'])<=1000 and not re.search(r'[\x00-\x1f]',c['reason'])
                and type(c['charitable_use']) is bool and all(c[k] is True for k in ('funds_reviewed','vat_reviewed','tax_reviewed','history_reviewed')))
        moves[c['on']]=c;last=c['on']
    original={'on':p['acquired_on'][:7]+'-01','basis_cents':p['basis_cents'],'residual_cents':p['residual_cents'],'months':p['months']}
    clock=original.copy();sphere=p['sphere'];ever=sphere=='4';exit_case=None;opening_sphere=None;parts=dict.fromkeys(('1','2','3','4'),0);book_moves=[]
    tax=dict.fromkeys(('opening_cents','additions_cents','contributions_cents','withdrawals_cents','valuation_result_cents','depreciation_cents','closing_cents'),0);tax['movements']=[]
    on=original['on']
    while on<=period['end']:
        inside=on>=period['start'];nxt=month_next(on)
        if on==period['start'] or opening_sphere is None and inside:
            opening_sphere=sphere;tax['opening_cents']=clock_value(clock,on) if p['acquired_on']<period['start'] and sphere=='4' else 0
        if inside and on==original['on'] and sphere=='4':tax['additions_cents']+=p['basis_cents']
        if on in moves:
            c=moves[on];target=c['to_sphere'];require(target!=sphere)
            before=clock_value(clock,on);contribution=withdrawal=gain=0
            if sphere!='4' and target!='4':
                require(c['case']=='exempt_to_exempt' and c['value_cents']==c['remaining_months']==c['residual_cents']==0)
            elif sphere=='4':
                require(c['case'] in ('exempt_book','exempt_value') and c['remaining_months']==c['residual_cents']==0)
                require(c['charitable_use'] is True and c['value_cents']==before if c['case']=='exempt_book' else c['charitable_use'] is False)
                withdrawal=c['value_cents'];gain=withdrawal-before;exit_case=c['case']
            else:
                require(c['case']==('taxable_return' if ever and exit_case=='exempt_book' else 'taxable_value'))
                if c['case']=='taxable_return':require(c['value_cents']==before and c['remaining_months']==c['residual_cents']==0)
                else:
                    require(c['value_cents']>0 and 1<=c['remaining_months']<=1200 and c['residual_cents']<c['value_cents'])
                    clock={'on':on,'basis_cents':c['value_cents'],'residual_cents':c['residual_cents'],'months':c['remaining_months']}
                contribution=c['value_cents'];ever=True
            if inside:
                net=clock_value(original,on)
                book_moves.append({'on':on,'from_sphere':sphere,'to_sphere':target,'cost_cents':p['basis_cents'],'accumulated_cents':p['basis_cents']-net,'book_cents':net})
                tax['contributions_cents']+=contribution;tax['withdrawals_cents']+=withdrawal;tax['valuation_result_cents']+=gain
                tax['movements'].append({'on':on,'case':c['case'],'from_sphere':sphere,'to_sphere':target,'before_cents':before if sphere=='4' else 0,
                    'after_cents':clock_value(clock,on) if target=='4' else 0,'contribution_cents':contribution,'withdrawal_cents':withdrawal,'result_cents':gain})
            sphere=target
        if inside:
            parts[sphere]+=clock_value(original,on)-clock_value(original,nxt)
            if sphere=='4':tax['depreciation_cents']+=clock_value(clock,on)-clock_value(clock,nxt)
        on=nxt
    tax['closing_cents']=clock_value(clock,(date.fromisoformat(period['end'])+timedelta(days=1)).isoformat()) if sphere=='4' else 0
    for k,v in tax.items():
        if k!='movements':amount(v)
    require(tax['opening_cents']+tax['additions_cents']+tax['contributions_cents']-tax['withdrawals_cents']+tax['valuation_result_cents']-tax['depreciation_cents']==tax['closing_cents'])
    require(sum(parts.values())==book['depreciation_cents'])
    expected={'schema':1,'period':period,'plan':p,'changes':proof['changes'],'previous_sha256':previous['sha256'] if previous else None,
              'history':proof['history'],'book':{'schedule':book,'opening_sphere':opening_sphere or p['sphere'],'depreciation_by_sphere':parts,'movements':book_moves},'tax':tax,'closing_sphere':sphere}
    require(proof==expected|{'sha256':digest(expected)},'Bereichswechsel: Steuerwerte, Originalplan oder Bewertungsnachweis stimmen nicht überein.')
    if previous:require(previous['closing_sphere']==opening_sphere and previous['tax']['closing_cents']==tax['opening_cents'])
    return proof


def item_bridge(item):
    # Called only after replay against the retained actual predecessor.
    p=item['sphere_change'];b=p['book'];t=p['tax']
    require(p['plan']==item['plan'] and b['schedule']==item['schedule'] and 'disposal' not in item)
    book={'opening_cents':b['schedule']['book_opening_cents'] if b['opening_sphere']=='4' else 0,
          'additions_cents':b['schedule']['additions_cents'] if item['plan']['sphere']=='4' else 0,
          'contributions_cents':0,'withdrawals_cents':0,'depreciation_cents':b['depreciation_by_sphere']['4'],
          'closing_cents':b['schedule']['book_closing_cents'] if p['closing_sphere']=='4' else 0}
    movements=[]
    for m,v,c in zip(b['movements'],t['movements'],p['changes'],strict=True):
        require(m['on']==v['on']==c['on'])
        incoming=m['book_cents'] if m['to_sphere']=='4' else 0;outgoing=m['book_cents'] if m['from_sphere']=='4' else 0
        book['contributions_cents']+=incoming;book['withdrawals_cents']+=outgoing
        movements.append(v|{'book_contribution_cents':incoming,'book_withdrawal_cents':outgoing}|{k:c[k] for k in ('document_id','revision','sha256','reason')})
    require(book['opening_cents']+book['additions_cents']+book['contributions_cents']-book['withdrawals_cents']-book['depreciation_cents']==book['closing_cents'])
    diff=amount(t['valuation_result_cents']+book['depreciation_cents']-t['depreciation_cents'])
    tax_result=t['closing_cents']-t['opening_cents']-t['additions_cents']-t['contributions_cents']+t['withdrawals_cents']
    require(tax_result==t['valuation_result_cents']-t['depreciation_cents'])
    v={'item_id':item['item_id'],'sphere_change_sha256':p['sha256'],'book_taxable':book,'tax':{k:v for k,v in t.items() if k!='movements'},'movements':movements,'valuation_difference_cents':diff}
    return v|{'sha256':digest(v)}


def bridge(source):
    items={k:item_bridge(i) for k,i in mapping(source['asset_schedule']['items']).items() if 'sphere_change' in i}
    return {'items':items,'valuation_difference_cents':amount(sum(i['valuation_difference_cents'] for i in items.values()))} if items else None


def expense_sources(source,valuation):
    """Reconstructed asset tax expense, not an additional accounting entry."""
    from ebilanz_period_assets import DEP
    items=mapping(source['asset_schedule']['items'])
    codes={DEP[i['plan']['account']] for i in items.values() if 'sphere_change' in i}
    sources={}
    if not codes:return codes,sources
    for identifier,item in items.items():
        code=DEP[item['plan']['account']]
        if code not in codes:continue
        tax=valuation['asset_tax']['tax_schedule']['items'][identifier]
        require(tax['original_sha256']==digest(item))
        if 'sphere_change' in item:
            require(tax['sphere_change']==item['sphere_change'])
            cents=item_bridge(item)['tax']['depreciation_cents']
        else:cents=tax['schedule']['depreciation_cents'] if item['plan']['sphere']=='4' else 0
        number(cents,0,100000000000)
        if not cents:continue
        row={'account':None,'group':'expense','cents':cents,'asset_id':identifier,'asset_sha256':digest(item),
             'tax_value_sha256':digest(tax),'name':item['name'],'skr_code':code}
        sources['asset-depreciation:'+digest({'period':source['period'],'source':row})]=row
    return codes,sources
