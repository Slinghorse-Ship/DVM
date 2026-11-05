"""Linked original whole-club B/GuV and taxable SGEB; generation only, no I/O.

Both sections are rebuilt by the checked generator. No caller-supplied XML is
accepted. Distinct contexts retain whole books versus taxable reviewed values.
"""
from copy import deepcopy
from ebilanz_club_scope import require, digest, amount, validate as validate_scope


KIND='tax_privileged_club_with_sgeb'

def assets_changed(section):
    if 'period' not in section:return 'asset_tax' in section or 'own_intangible_sources' in section
    if section.get('period_section')=='books':return False
    from ebilanz_club_scope import mapping
    return bool(section['period_source']['review']['asset_tax_plans']) or any(
        p['cost']['opening_difference_cents'] or p['cost']['closing_difference_cents'] or any(e['asset_difference_cents'] for e in mapping(p['cost']['events']).values())
        for p in mapping(section['asset_tax']['items']).values() if 'cost' in p)


def validate(values,profile):
    dated='period' in values
    require(isinstance(values,dict) and set(values)=={'schema','kind','year','journal_sha256','whole','taxable','reconciliation','sha256','transmission'}|({'period'} if dated else set()))
    require(type(values['schema']) is int and values['schema']==(2 if dated else 1) and values['kind']==KIND and values['transmission']=='unavailable')
    require(values['sha256']==digest({k:v for k,v in values.items() if k not in ('sha256','transmission')}))
    require(profile.get('kind')==KIND and profile.get('source_scope')=='complete_club_with_approved_allocation' and profile.get('tax_privileged') is True
            and profile.get('scope_confirmed') is True and profile.get('tax_values_sha256')==values['sha256']
            and profile.get('asset_scope')=='whole_and_taxable' and profile.get('whole_book_standard')=='HGBM')
    w,t=values['whole'],values['taxable']
    profiles=[]
    for section,kind,source,privileged,asset in [(w,'whole_taxable_club','complete_club_ledger',False,'whole_club'),(t,'club_taxable_businesses','approved_club_allocation',True,'single_reported_business')]:
        require(isinstance(section,dict) and section.get('year')==values['year'] and section.get('transmission')=='unavailable')
        require(section.get('sha256')==digest({k:v for k,v in section.items() if k not in ('sha256','transmission')}))
        # Fixed internal section types, not caller-controlled profile promotion.
        changed=assets_changed(section)
        p=profile|{'kind':kind,'source_scope':source,'tax_privileged':privileged,'asset_scope':asset,'tax_values_sha256':section['sha256'],
                   'asset_values_unchanged':not changed,'asset_tax_values_reviewed':changed}
        if kind=='whole_taxable_club':p['business_name']=profile.get('whole_business_name')
        validate_scope(section,p,book_only=kind=='whole_taxable_club');profiles.append(p)
    if dated:
        require(w['period']==t['period']==values['period'] and t['period_club']['whole']==w)
        original=t['period_club']['income']['scope']['source']
        require(w['whole_club_scope']['source']=={k:original[k] for k in w['whole_club_scope']['source']})
        prior=None  # Both complete predecessor chains were independently replayed above.
    else:
        require(w['whole_club_scope']['source']==t['club_scope']['source'])
        prior=t['club_scope']['previous']
    require(w['journal_sha256']==values['journal_sha256'])
    source=w['whole_club_scope']['source']
    if not dated and source['previous_sha256'] is not None:
        require(isinstance(prior,dict) and source['previous_sha256']==prior['source']['closed_sha256'])
        p=prior['source']['calculation']
        carried={k:amount(a['closing_cents']-(p['annual_result_cents'] if k==source['result_account'] else 0)) for k,a in p['accounts'].items() if a['group'] not in ('expense','revenue')}
        require({k:v for k,v in carried.items() if v}=={l['account']:l['debit_cents']-l['credit_cents'] for l in source['opening']})
    require(w['opening_adjustments']==[] and w['current_adjustments']==[] and (dated or 'asset_tax' not in w) and w['book']==w['tax'])
    require(not set(t['source_spheres'])-{'4'})
    changed=assets_changed(t)
    if changed:require(profile.get('asset_tax_values_reviewed') is True and profile.get('asset_values_unchanged') is False)
    else:require(profile.get('asset_values_unchanged') is True)
    rows={};eliminations={};allocation=t['period_club']['income']['scope']['allocation'] if dated else t['club_scope']['allocation']
    for transfer in (allocation.get('transfers') or {}).values():
        net=sum(n for unit,n in transfer['parts'].items() if allocation['units'][unit]['sphere']=='4')
        account=transfer['account'];eliminations[account]=amount(eliminations.get(account,0)+abs(net))
    for key,a in w['book']['accounts'].items():
        b=t['book']['accounts'][key];tax=t['tax']['accounts'][key]
        rows[key]={field:{'whole':a[field],'taxable_book':b[field],'other_areas':amount(a[field]-b[field]),'tax_adjustment':amount(tax[field]-b[field]),'taxable_tax':tax[field]} for field in ('opening_cents','debit_cents','credit_cents','amount_cents')}
        if allocation.get('transfers'):
            for field,r in rows[key].items():
                eliminated=eliminations.get(key,0) if field in ('debit_cents','credit_cents') else 0
                r['other_areas']=amount(r['other_areas']+eliminated);r['internal_elimination']=eliminated
    total,book,tax=(s['annual_result_cents'] for s in (w['book'],t['book'],t['tax']))
    require(values['reconciliation']=={'accounts':rows,'result':{'whole':total,'taxable_book':book,'other_areas':amount(total-book),'tax_adjustment':amount(tax-book),'taxable_tax':tax}})
    return profiles


def build(builder,values,mapping,profile,manufacturer):
    from ebilanz_builder import EbilanzBuilder,NS,node
    wp,tp=validate(values,profile)
    require(isinstance(mapping,dict) and set(mapping)=={'whole','taxable'})
    # Keep both complete checked trees until the defined SGEB nodes are copied.
    root,balance,meta=builder._build_tree(values['whole'],mapping['whole'],wp,manufacturer,book_only=True)
    other=EbilanzBuilder(environment=builder.environment);_,_,taxmeta=other._build_tree(values['taxable'],mapping['taxable'],tp,manufacturer)
    x=builder.x
    def q(prefix,name):return '{'+NS[prefix]+'}'+name
    def choice(name,suffix):
        current=x.find(q('de-gcd',name));require(current is not None)
        current.clear();node(current,'de-gcd',name+'.'+suffix,'',contextRef='D')
    choice('genInfo.company.id.entityWithTaxablePurposeBusiness','taxPrivileged')
    p=node(x,'de-gcd','genInfo.company.id.transmissionOptionofCorpWithSpecialCaseProfitDetermination')
    node(p,'de-gcd','genInfo.company.id.transmissionOptionofCorpWithSpecialCaseProfitDetermination.umbrellaBodyTable','',contextRef='D')
    # Original whole-club accounts are commercial values, while SGEB is tax-only.
    choice('genInfo.report.id.accountingStandard','accountingStandard.HGBM')
    report=x.find(q('de-gcd','genInfo.report.id.reportElement'));require(report is not None)
    for suffix in ('BVV','SGEB'):
        f=report.find(q('de-gcd','genInfo.report.id.reportElement.reportElements.'+suffix));require(f is not None)
        if suffix=='BVV':f.set(q('xsi','nil'),'true');f.text=None
        else:f.attrib.pop(q('xsi','nil'),None);f.text=''
    # The gross-asset hypercube has NO BgAKey dimension. The structured schedule
    # belongs to the whole corporation. Retain taxable asset detail as an XHTML
    # footnote linked to its SGEB balance fact, never a second whole-club schedule.
    for ctx in x.findall(q('xbrli','context')):
        if ctx.get('id','').startswith('FA'):
            for dim in ctx.iter(q('xbrldi','explicitMember')):
                if dim.get('dimension')=='de-gaap-ci:dim_taxTrans':dim.text='de-gaap-ci:dim_comBal'
    context_ids={c.get('id') for c in other.x.findall(q('xbrli','context'))}
    require(len(context_ids)==len(other.x.findall(q('xbrli','context'))))
    used={f.get('contextRef') for e in other.x if e.tag.startswith('{'+NS['de-gaap-ci']+'}') for f in e.iter() if f.get('contextRef') and not f.get('contextRef').startswith('FA')}
    for element in other.x:
        if element.tag==q('xbrli','context') or element.tag.startswith('{'+NS['de-gaap-ci']+'}'):
            if element.tag==q('xbrli','context') and element.get('id') not in used:continue
            if element.get('contextRef','').startswith('FA'):continue
            e=deepcopy(element)
            if e.tag==q('xbrli','context'):
                old=e.get('id');e.set('id','T_'+old)
            for f in e.iter():
                if 'contextRef' in f.attrib:
                    require(f.get('contextRef') in context_ids);f.set('contextRef','T_'+f.get('contextRef'))
            x.append(e)
    if taxmeta['asset_items']:asset_footnote(x,values['taxable'])
    ids=[c.get('id') for c in x.findall(q('xbrli','context'))];require(len(ids)==len(set(ids)))
    meta|={'profile':KIND,'tax_values_sha256':values['sha256'],'journal_sha256':values['journal_sha256'],
           'fact_count':meta['fact_count']+taxmeta['fact_count'],
           'taxable_asset_items':taxmeta['asset_items'],'taxable_asset_schedule_sha256':taxmeta['asset_schedule_sha256'],
           'taxable_asset_presentation':'sgeb_footnote' if taxmeta['asset_items'] else 'no_assets',
           'sections':{'whole':values['whole']['sha256'],'taxable':values['taxable']['sha256']}}
    return builder._serialize(root,balance,meta)


def asset_footnote(x,values):
    """Typed generated XHTML; no HTML/XML from invoice names or external input."""
    from ebilanz_builder import NS,BASE,node,money
    def q(prefix,name):return '{'+NS[prefix]+'}'+name
    contexts={c.get('id'):c for c in x.findall(q('xbrli','context'))}
    candidates=[]
    for f in x.findall(q('de-gaap-ci',BASE+'balanceSheet.valueOfItem')):
        ctx=contexts[f.get('contextRef')];dim=ctx.find('.//'+q('xbrldi','explicitMember'))
        if dim is not None and dim.text=='de-gaap-ci:bs.ass.fixAss':candidates.append(f)
    require(len(candidates)==1);candidates[0].set('id','taxable_asset_evidence')
    attrs=lambda **kw:{q('xlink',k):v for k,v in kw.items()}
    link=node(x,'link','footnoteLink',**attrs(type='extended',role='http://www.xbrl.org/2003/role/link'))
    node(link,'link','loc',**attrs(type='locator',href='#taxable_asset_evidence',label='taxable_assets'))
    foot=node(link,'link','footnote',**attrs(type='resource',label='asset_evidence',role='http://www.xbrl.org/2003/role/footnote'))
    foot.set('xml:lang','de')
    # Local default namespace avoids altering any existing QName serialization.
    import xml.etree.ElementTree as ET
    html=ET.SubElement(foot,'html',{'xmlns':'http://www.w3.org/1999/xhtml'});head=ET.SubElement(html,'head');ET.SubElement(head,'title').text='Anlagen des steuerpflichtigen Bereichs'
    body=ET.SubElement(html,'body');ET.SubElement(body,'p').text='Anlagen des zusammengefassten steuerpflichtigen Bereichs. Die folgenden Werte sind nicht zusätzlich in die Gesamtbilanz einzurechnen. Alle Beträge in EUR.'
    fields=[('cost_opening_cents','Anschaffungskosten Anfang'),('additions_cents','Zugänge'),('disposals_cents','Abgänge Anschaffungskosten'),('cost_closing_cents','Anschaffungskosten Ende'),('accumulated_opening_cents','Abschreibung bisher'),('depreciation_cents','Abschreibung im Jahr'),('accumulated_disposals_cents','Abgänge bisherige Abschreibung'),('accumulated_closing_cents','Abschreibung Ende'),('book_opening_cents','Buchwert Anfang'),('book_disposals_cents','Restbuchwert Abgänge'),('book_closing_cents','Buchwert Ende')]
    schedule=values.get('asset_tax',{}).get('tax_schedule',values['asset_schedule'])
    for id,item in sorted(schedule['items'].items()):
        book=values['asset_schedule']['items'][id]
        if 'period' in values:
            # Dated schedules retain original plan and period measurements separately.
            original=book
            book=original['schedule']|{'skr_code':original['plan']['account'],'acquired_on':original['plan']['acquired_on']}
            item=item['schedule']|{'skr_code':original['plan']['account'],'acquired_on':original['plan']['acquired_on']}
        ET.SubElement(body,'p').text='Beleg '+str(item.get('receipt_number',book.get('receipt_number','')))+'; Konto '+str(item['skr_code'])+'; angeschafft am '+str(item['acquired_on'])
        table=ET.SubElement(body,'table');header=ET.SubElement(table,'tr')
        for title in ('Wertentwicklung','Buchwert','Steuerwert'):ET.SubElement(header,'th').text=title
        for key,label in fields:
            row=ET.SubElement(table,'tr')
            for value in (label,money(book.get(key,0)),money(item.get(key,0))):ET.SubElement(row,'td').text=value
    node(link,'link','footnoteArc',**attrs(type='arc',arcrole='http://www.xbrl.org/2003/arcrole/fact-footnote',**{'from':'taxable_assets','to':'asset_evidence'}),order='1.0')
