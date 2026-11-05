"""Independent whole-club book AND tax reconstruction; no sphere filtering or I/O."""
from copy import deepcopy
import hashlib
import re
from ebilanz_club_scope import require, calculate, lines, amount


def is_whole(profile):
    return profile.get('kind') == 'whole_taxable_club'


def asset_scope(profile):
    return 'whole_club' if is_whole(profile) else 'single_reported_business'


def spheres(profile):
    return ('1','2','3','4') if is_whole(profile) else ('4',)


def validate(values,profile,*,book_only=False):
    require(is_whole(profile) and profile.get('source_scope')=='complete_club_ledger' and profile.get('tax_privileged') is False and profile.get('asset_scope')=='whole_club' and 'club_scope' not in values)
    if 'period' in values:
        from ebilanz_period import verify
        verify(values,whole=True,section='books' if book_only else None)
        return
    scope=values.get('whole_club_scope')
    schedule=values.get('asset_schedule')
    require(isinstance(schedule,dict))
    items=schedule.get('items');require(isinstance(items,dict) or items==[])
    cost=any(isinstance(i,dict) and (i.get('method') in ('cost','own_intangible_hgb') or i.get('skr_code')=='01500') for i in items.values()) if isinstance(items,dict) else False
    cost=cost or (isinstance(values.get('pension_offsets'),dict) and values['pension_offsets'].get('fiscal') is False)
    require((values.get('annual_section')=='books' if cost else 'annual_section' not in values) if book_only else 'annual_section' not in values)
    require(isinstance(scope,dict) and set(scope)=={'schema','kind','source'} and type(scope['schema']) is int and scope['schema']==1 and scope['kind']=='whole_taxable_club')
    r=scope['source']
    require(isinstance(r,dict) and set(r)=={'year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule'})
    require(r['state']=='closed' and r['issues']==[] and r['year']==values['year'] and isinstance(r['closed_sha256'],str) and re.fullmatch('[a-f0-9]{64}',r['closed_sha256']) and r['closed_sha256']==values['journal_sha256'])
    require(r['previous_sha256'] is None or (isinstance(r['previous_sha256'],str) and re.fullmatch('[a-f0-9]{64}',r['previous_sha256'])))
    chart=r['accounts'];require(isinstance(chart,dict) and isinstance(r['result_account'],str) and chart.get(r['result_account'],{}).get('group')=='equity')
    book=calculate(r['year'],chart,r['opening'],r['entries'])
    require(book==r['calculation'] and book==values['book'] and r['asset_schedule']==values.get('asset_schedule') and isinstance(r['asset_schedule'],dict))
    validate_tax(values,r)


def validate_tax(values,r):
    """Replay reviewed changes from retained original lines, also for SGEB."""
    chart=r['accounts'];book=calculate(r['year'],chart,r['opening'],r['entries'])
    require(book==values['book'])
    opening={k:a['opening_cents'] for k,a in book['accounts'].items()};entries=deepcopy(r['entries']);seen=set()
    require(isinstance(values.get('opening_adjustments'),list) and isinstance(values.get('current_adjustments'),list) and len(values['opening_adjustments'])+len(values['current_adjustments'])<=200)
    for period in ('opening_adjustments','current_adjustments'):
        for entry in values[period]:
            require(isinstance(entry,dict) and set(entry)=={'id','reason','lines'} and isinstance(entry['id'],str) and re.fullmatch('[a-z0-9_-]{1,40}',entry['id']) and entry['id'] not in seen)
            require(isinstance(entry['reason'],str) and entry['reason'].strip() and len(entry['reason'])<=1500);seen.add(entry['id'])
            lines(entry['lines'],chart,period=='opening_adjustments')
            if period=='opening_adjustments':
                for line in entry['lines']:opening[line['account']]=amount(opening[line['account']]+line['debit_cents']-line['credit_cents'])
            else:
                # Only a calculator-local ID, not a replacement for retained evidence.
                entries.append({'id':hashlib.sha256(('tax-adjustment-'+entry['id']).encode()).hexdigest()[:32],'date':str(r['year'])+'-12-31','lines':entry['lines']})
    opening=[{'account':k,'debit_cents':max(0,v),'credit_cents':max(0,-v),'sphere':''} for k,v in opening.items() if v]
    tax=calculate(r['year'],chart,opening,entries);require(tax==values['tax'])
    source_spheres=sorted({l['sphere'] for e in entries for l in e['lines'] if l['sphere']})
    require(source_spheres==values['source_spheres'] and values['result_difference_cents']==tax['annual_result_cents']-book['annual_result_cents'])
    rows={k:{'name':a['name'],'group':a['group'],'skr_code':a['skr_code'],'book_opening_cents':a['opening_cents'],'tax_opening_cents':tax['accounts'][k]['opening_cents'],'book_cents':a['amount_cents'],'tax_cents':tax['accounts'][k]['amount_cents'],'difference_cents':tax['accounts'][k]['amount_cents']-a['amount_cents']} for k,a in book['accounts'].items()}
    require(rows==values['accounts'])


def bvv(values):
    """Rebuild each capital line and its reviewed treatment, including internal transfers."""
    accounts=values['tax']['accounts'];capital={k:a for k,a in accounts.items() if a['group'] in ('equity','reserves','result_use')}
    opening_book=-sum(values['book']['accounts'][k]['opening_cents'] for k in capital)
    opening=-sum(a['opening_cents'] for a in capital.values())
    ending=values['tax']['capital_total_cents']+values['tax']['annual_result_cents'];sources={}
    proof=values.get('capital_comparison');require(isinstance(proof,dict))
    for kind,entries in [('journal',values['whole_club_scope']['source']['entries']),('tax',values['current_adjustments'])]:
        for entry in entries:
            for ordinal,line in enumerate(entry['lines']):
                if line['account'] not in capital:continue
                voucher=kind+':'+entry['id'];key=voucher+':'+str(ordinal)
                sources[key]={'voucher':voucher,'date':entry.get('date',values.get('period',{}).get('end',str(values['year'])+'-12-31')),'description':entry.get('description',entry.get('reason',entry['id'])),'account':line['account'],'debit_cents':line['debit_cents'],'credit_cents':line['credit_cents']}
    decisions=proof.get('decisions');decisions={} if decisions==[] else decisions
    require(isinstance(decisions,dict) and set(decisions)==set(sources) and len(sources)<=2000)
    contributions=withdrawals=0;internal={};normalized={}
    for key,source in sources.items():
        d=decisions[key];effect=source['credit_cents']-source['debit_cents']
        require(isinstance(d,dict) and set(d)=={'kind','reason'} and d['kind'] in ('contribution','withdrawal','reclassification') and isinstance(d['reason'],str) and d['reason'].strip()==d['reason'] and d['reason'] and len(d['reason'])<=1000 and not re.search(r'[\x00-\x1f]',d['reason']))
        if d['kind']=='contribution':require(effect>0);contributions=amount(contributions+effect)
        elif d['kind']=='withdrawal':require(effect<0);withdrawals=amount(withdrawals-effect)
        else:internal[source['voucher']]=amount(internal.get(source['voucher'],0)+effect)
        normalized[key]={'kind':d['kind'],'reason':d['reason']}
    require(not any(internal.values()) and ending-opening+withdrawals-contributions==values['tax']['annual_result_cents'])
    expected={'year':values['year'],'book_opening_cents':opening_book,'opening_adjustment_cents':opening-opening_book,'tax_opening_cents':opening,'tax_closing_cents':ending,'contributions_cents':contributions,'withdrawals_cents':withdrawals,'profit_cents':values['tax']['annual_result_cents'],'sources':dict(sorted(sources.items())) or [],'decisions':dict(sorted(normalized.items())) or [],'issues':[],'complete':True}
    if 'period' in values:expected['period']=values['period']
    require(expected==proof)
    base='BVV.profitLoss'
    return {base:ending-opening+withdrawals-contributions,base+'.assetsCurrentYear':ending,base+'.assetsPreviousYear':opening,base+'.assetsPreviousYear.assets':opening,base+'.assetsPreviousYear.capAdjustments':0,base+'.contribution':contributions,base+'.withdrawalDistrib':withdrawals}
