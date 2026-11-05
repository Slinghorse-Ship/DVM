"""Exact legacy aggregate/whole evidence at a dated boundary; no replaced hash."""
from ebilanz_club_scope import require, digest, mapping, rebuild, validate, calculate
from ebilanz_calendar_source import asset_values

def bind_scope(scope, original, depth=0):
    """Bind every legacy allocation/review to the unchanged original chain."""
    require(depth<=100 and type(scope) is dict and set(scope)=={'schema','kind','club_id','source','allocation','clearing','review','previous'})
    require(scope['schema']==1 and scope['kind']=='club_income_reconciliation' and 'period' not in scope['source'])
    require(scope['source']==original['source'] and scope['review']['allocation']==original['review'])
    a=original['allocation'];b=scope['allocation']
    require(a['sha256']==b['sha256'] and all(a[k]==b[k] for k in a if k!='sha256'))
    require(scope['clearing']['club_id']==scope['club_id'] and not mapping(scope['clearing']['cases']))
    p=scope['previous'];q=original['previous'];require((p is None)==(q is None))
    if p is None:
        require(scope['source']['previous_sha256'] is None and scope['clearing']['previous'] is None)
    else:
        require(p['club_id']==scope['club_id'] and scope['clearing']['previous']==p['clearing']
                and scope['source']['previous_sha256']==p['source']['closed_sha256'])
        bind_scope(p,q,depth+1)

def record(v):
    require(type(v) is dict and set(v)=={'calendar_club','taxable','whole'}|({'combined'} if 'combined' in v else set()))
    c=v['calendar_club'];require(type(c) is dict and set(c)=={'income','report','review'})
    inc=c['income'];r=c['report'];t=v['taxable'];w=v['whole'];s=inc['scope']
    require('period' not in inc and inc['sha256']==digest({k:n for k,n in inc.items() if k!='sha256'}))
    rebuilt=rebuild(r['club_scope'],with_source=True);original=r['club_scope']['source'];source=rebuilt['source']
    expected={'year':source['year'],'state':'closed','closed_sha256':rebuilt['journal_sha256'],
              'previous_sha256':digest(r['club_scope']['previous']) if r['club_scope']['previous'] else None,
              'result_account':original['result_account'],'issues':[],'accounts':source['accounts'],'opening':source['opening'],'entries':source['entries'],
              'calculation':rebuilt['book'],'asset_schedule':rebuilt['asset_schedule'],'club_scope':r['club_scope']}
    require(r==expected);bind_scope(s,r['club_scope'])
    for values in (t,w):require(values['sha256']==digest({k:n for k,n in values.items() if k not in ('sha256','transmission')}) and values['transmission']=='unavailable')
    profile={'kind':'club_taxable_businesses','source_scope':'approved_club_allocation','tax_privileged':True,'asset_scope':'single_reported_business'}
    validate(t,profile);require(t['club_scope']==r['club_scope'])
    review=c['review'];e=t.get('asset_tax',{})
    require(type(review) is dict and {'year','journal_sha256','complete','opening_adjustments','current_adjustments'}<=set(review)<= {'year','journal_sha256','complete','opening_adjustments','current_adjustments','asset_tax_plans'})
    require(review['year']==r['year'] and review['journal_sha256']==r['closed_sha256'] and review['complete'] is True)
    require(mapping(review.get('asset_tax_plans',[]))=={k:i['plan'] for k,i in mapping(e.get('items',[])).items() if 'plan' in i})
    for field,generated in [('opening_adjustments','generated_opening'),('current_adjustments','generated_current')]:require(review[field]+e.get(generated,[])==t[field])
    validate(w,{'kind':'whole_taxable_club','source_scope':'complete_club_ledger','tax_privileged':False,'asset_scope':'whole_club'},book_only=True)
    require(w['whole_club_scope']['source']==s['source'] and w['book']==w['tax'] and w['opening_adjustments']==w['current_adjustments']==[] and 'asset_tax' not in w)
    if 'combined' in v:
        full=v['combined'];require(full['whole']==w and full['taxable']==t)
        from ebilanz_combined_club import validate as combined_validate
        combined_validate(full,{'kind':'tax_privileged_club_with_sgeb','source_scope':'complete_club_with_approved_allocation','tax_privileged':True,'scope_confirmed':True,
                               'asset_scope':'whole_and_taxable','whole_book_standard':'HGBM','tax_values_sha256':full['sha256'],
                               'asset_values_unchanged':not bool(e),'asset_tax_values_reviewed':bool(e)})
    return r

def packet(v,section):
    r=record(v);require(section in ('club','club_books'));c=v['calendar_club'];books=section=='club_books'
    source=c['income']['scope']['source'] if books else r
    values=v['whole' if books else 'taxable'];plans={} if books else mapping(c['review'].get('asset_tax_plans',[]))
    retained,tax=asset_values(source,None,values,plans)
    return {'calendar_source':v,'section':section,'values':values,'asset_schedule':retained,'asset_tax':tax}

def verify(p,section):
    require(packet(p['calendar_source'],section)==p)
    c=p['calendar_source']['calendar_club'];source=c['income']['scope']['source'] if section=='club_books' else c['report']
    return {'start':str(source['year'])+'-01-01','end':str(source['year'])+'-12-31'},source,p['values']

def scope(v):
    for _ in range(101):
        if v is None:return None
        if 'calendar_club' in v:return record(v)['club_scope']
        v=v.get('period_club',{}).get('previous')
    raise ValueError('Kalender-Vortragskette prüfen.')
