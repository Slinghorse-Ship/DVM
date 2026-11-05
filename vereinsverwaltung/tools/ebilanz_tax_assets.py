"""Independent proof of reviewed different linear tax lives, never a tax election.

The original book schedule is validated separately. Only the duration changes;
acquisition, residual, business, source hashes and closed journal remain intact.
"""
from copy import deepcopy
from datetime import date
import hashlib
import json
import re
from ebilanz_whole_club import asset_scope, spheres
from ebilanz_asset_types import LINEAR, MOVABLE, DEP


def digest(value):
    return hashlib.sha256(json.dumps(value,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()


def fail():
    raise ValueError('Steuerlicher Anlagenplan, Herkunft, Wertüberleitung oder Bestätigung stimmt nicht überein.')


def pair(asset,other,difference,sphere):
    return [{'account':asset,'debit_cents':max(0,difference),'credit_cents':max(0,-difference),'sphere':''},
            {'account':other,'debit_cents':max(0,-difference),'credit_cents':max(0,difference),'sphere':sphere}]


def sale_adjustment(item,changed,accounts,year,reason):
    """Independently reverse book sale classification and post the evidenced tax sale."""
    from ebilanz_sales import sale_values,choose_account
    disposal=item['disposal']
    if date.fromisoformat(disposal['date']).year<year:return None,None,set()
    amounts=[]
    for remaining in (item['book_disposals_cents'],changed['book_disposals_cents']):
        v=sale_values(disposal,remaining)
        amounts.append({'gross_cents':disposal['gross_cents'],'tax_cents':disposal['tax_cents'],
            'net_cents':v['net'],'book_cents':remaining,'result_cents':v['net']-remaining,
            'gain':v['gain'],'sale_code':v['sale_code'],'book_code':v['book_code'],
            'group':'revenue' if v['gain'] else 'expense'})
    book,tax=amounts;selected={}
    for values in amounts:
        for kind in ('sale_code','book_code'):
            code=values[kind]
            selected[code]=choose_account(item,accounts,code,values['group'])
    net={item['account']:book['book_cents']-tax['book_cents']}
    for code,amount in ((book['book_code'],-book['book_cents']),(tax['book_code'],tax['book_cents']),
                        (book['sale_code'],book['net_cents']),(tax['sale_code'],-tax['net_cents'])):
        key=selected[code];net[key]=net.get(key,0)+amount
    lines=[{'account':key,'debit_cents':max(0,n),'credit_cents':max(0,-n),
            'sphere':'' if key==item['account'] else item['sphere']} for key,n in net.items() if n]
    entry={'id':'asset-sale-'+hashlib.sha256(item['item_id'].encode()).hexdigest()[:24],
           'reason':'Steuerlicher Anlagenverkauf: '+reason,'lines':lines} if lines else None
    return {'book':book,'tax':tax,'accounts':selected},entry,set(selected.values())


def verify(values,profile,mapping):
    from ebilanz_assets import TANGIBLE,INTANGIBLE,BUILDING,GOODWILL,GWG,SCRAPPING,SOFTWARE_END,GAIN_SALE,GAIN_BOOK,LOSS_SALE
    from ebilanz_sales import choose_account,check_movements
    evidence=values.get('asset_tax'); book=values['book']['accounts']; tax=values['tax']['accounts']
    if (not isinstance(evidence,dict) or set(evidence)!={'book_schedule_sha256','items','equity_account','generated_opening','generated_current','tax_schedule'}
        or profile.get('asset_values_unchanged') is not False or profile.get('asset_tax_values_reviewed') is not True
        or profile.get('asset_scope')!=asset_scope(profile)):fail()
    original=values.get('asset_schedule')
    if not isinstance(original,dict) or digest(original)!=evidence['book_schedule_sha256']:fail()
    check_movements(original.get('items') or {},book,values['year'])
    proofs=evidence['items']
    if not isinstance(proofs,dict) or not 1<=len(proofs)<=200:fail()
    expected=deepcopy(original); generated={'opening_adjustments':[],'current_adjustments':[]}; managed=set(); desired={}
    from ebilanz_cost_asset_tax import item_projection
    cost_ids=[identifier for identifier,item in (original.get('items') or {}).items() if item.get('method')=='cost']
    if any(identifier not in proofs for identifier in cost_ids):fail()
    for identifier in cost_ids:
        item=original['items'][identifier]
        derived=item_projection(item,book,{'start':f"{values['year']}-01-01",'end':f"{values['year']}-12-31"})
        if proofs[identifier]!=derived['proof']:fail()
        expected['items'][identifier]=derived['tax_item']
        for key in expected['totals']:expected['totals'][key]+=derived['tax_item'].get(key,0)-item.get(key,0)
        generated['current_adjustments'].extend(derived['generated_current']);managed.update(derived['managed_accounts'])
        opening=derived['opening_difference_cents'];asset=item['account'];desired[asset]=desired.get(asset,0)+opening
        if opening:
            equity=evidence['equity_account']
            if not isinstance(equity,str) or book.get(equity,{}).get('group')!='equity':fail()
            generated['opening_adjustments'].append({'id':'cost-open-'+hashlib.sha256(identifier.encode()).hexdigest()[:24],
                'reason':'Steuerlicher Bewertungsvortrag: '+item['cost_plan']['reason'][:1300],'lines':pair(asset,equity,opening,'')})
    for identifier,proof in sorted(proofs.items()):
        if identifier in cost_ids:continue
        item=(original.get('items') or {}).get(identifier)
        disposal=item.get('disposal') if isinstance(item,dict) else None
        sale=isinstance(disposal,dict) and disposal.get('kind')=='sale'
        partial=isinstance(disposal,dict) and disposal.get('kind')=='partial_scrap'
        software_end=isinstance(disposal,dict) and disposal.get('kind')=='software_end'
        fields={'book_item_sha256','plan','opening_difference_cents','depreciation_difference_cents'}
        if disposal is not None:fields.update(('disposal_difference_cents','sale' if sale else 'disposal_account'))
        if (not isinstance(item,dict) or not isinstance(proof,dict)
            or set(proof)!=fields
            or digest(item)!=proof['book_item_sha256']):fail()
        plan=proof['plan']
        if not isinstance(plan,dict) or set(plan)!={'months','depreciation_account','reason','confirmed'}:fail()
        months=plan['months']; reason=plan['reason']; dep=plan['depreciation_account']; asset=item['account']
        from ebilanz_original_depreciation import code as original_depreciation_code
        code=original_depreciation_code(item,False)
        if (type(months) is not int or not 12<=months<=1200 or months%12 or months==item['months']
            or plan['confirmed'] is not True or not isinstance(reason,str) or not reason.strip() or len(reason)>1400
            or re.search(r'[\x00-\x08\x0b\x0c\x0e-\x1f]',reason) or not isinstance(dep,str)
            or not code or dep not in book or book[dep]['group']!='expense' or book[dep]['skr_code']!=code
            or item.get('method','linear_monthly')!='linear_monthly' or any(k in item for k in ('gwg','degressive'))
            or (disposal is not None and (not isinstance(disposal,dict) or disposal.get('kind') not in ('scrap','sale','partial_scrap','software_end') or item['skr_code'] not in (('01350',) if software_end else MOVABLE)))
            or item['sphere'] not in spheres(profile)):fail()
        acquired=date.fromisoformat(item['acquired_on']); year=values['year']; cost=item['basis_cents'] if disposal is not None else item['cost_closing_cents']; residual=item['residual_cents']
        disposed=date.fromisoformat(disposal['date']) if disposal is not None else None
        if type(cost) is not int or cost<=0 or (disposed and (disposed<acquired or disposed.year>year)):fail()
        before=max(0,min(months,(year-acquired.year)*12-(acquired.month-1)))
        end_month=disposed.month if disposed is not None and disposed.year==year else 12
        through=max(0,min(months,(year-acquired.year)*12+end_month-(acquired.month-1)))
        opening_dep=((cost-residual)*before+months//2)//months
        closing_dep=((cost-residual)*through+months//2)//months
        changed=dict(item,months=months,accumulated_opening_cents=opening_dep,
                     depreciation_cents=closing_dep-opening_dep,expected_depreciation_cents=closing_dep-opening_dep,
                     accumulated_closing_cents=closing_dep,book_opening_cents=cost-opening_dep if acquired.year<year else 0,
                     book_closing_cents=cost-closing_dep)
        disposal_delta=0;loss=None;sale_entry=None
        if disposed is not None:
            if disposed.year<year:
                from ebilanz_assets import FIELDS,DISPOSAL_FIELDS
                for field in FIELDS+DISPOSAL_FIELDS:changed[field]=0
                changed['expected_depreciation_cents']=0
            else:
                changed.update(cost_closing_cents=0,accumulated_closing_cents=0,book_closing_cents=0,
                               disposals_cents=cost,accumulated_disposals_cents=closing_dep,book_disposals_cents=cost-closing_dep)
            if partial:
                from ebilanz_partial_assets import item_values
                original_values=item_values(item,year)
                if any(item.get(k)!=v for k,v in original_values.items()):fail()
                changed.update(item_values(dict(item,months=months),year,tax_derived=True))
                changed['expected_depreciation_cents']=changed['depreciation_cents']
            disposal_delta=changed['book_disposals_cents']-item['book_disposals_cents']
            if sale:
                sale_proof,sale_entry,sale_accounts=sale_adjustment(item,changed,book,year,reason)
                # JSON comparison distinguishes booleans from integers in signed evidence.
                if json.dumps(proof['sale'],sort_keys=True)!=json.dumps(sale_proof,sort_keys=True):fail()
                managed.update(sale_accounts)
            elif disposal_delta:
                loss=choose_account(item,book,'68960' if software_end else '68950','expense');managed.add(loss)
            if type(proof['disposal_difference_cents']) is not int or proof['disposal_difference_cents']!=disposal_delta:fail()
            if not sale and proof['disposal_account']!=loss:fail()
        expected['items'][identifier]=changed
        for key in expected['totals']:expected['totals'][key]+=changed.get(key,0)-item.get(key,0)
        opening=changed['book_opening_cents']-item['book_opening_cents']; delta=changed['depreciation_cents']-item['depreciation_cents']
        if (type(proof['opening_difference_cents']) is not int or proof['opening_difference_cents']!=opening
            or type(proof['depreciation_difference_cents']) is not int or proof['depreciation_difference_cents']!=delta):fail()
        managed.update((asset,dep));desired[asset]=desired.get(asset,0)+opening
        suffix=hashlib.sha256(identifier.encode()).hexdigest()[:24]
        if opening:
            equity=evidence['equity_account']
            if not isinstance(equity,str) or book.get(equity,{}).get('group')!='equity':fail()
            generated['opening_adjustments'].append({'id':'asset-opening-'+suffix,'reason':'Steuerlicher Anfangswert: '+reason,'lines':pair(asset,equity,opening,'')})
        if delta:generated['current_adjustments'].append({'id':'asset-tax-'+suffix,'reason':'Steuerliche Abschreibung: '+reason,'lines':pair(asset,dep,-delta,item['sphere'])})
        if sale_entry:generated['current_adjustments'].append(sale_entry)
        elif disposal_delta and not sale:generated['current_adjustments'].append({'id':'asset-scrap-'+suffix,'reason':('Steuerlicher Restwert bei Lizenzende: ' if software_end else 'Steuerlicher Restwert bei Ausmusterung: ')+reason,'lines':pair(asset,loss,-disposal_delta,item['sphere'])})
    if expected!=evidence['tax_schedule']:fail()
    # Check every journal movement, not merely the net asset balance.
    nets={key:dict.fromkeys(book,0) for key in ('opening','debit','credit')}; seen=set(); carry={}; has_carry=False
    for period in generated:
        entries=values.get(period)
        if not isinstance(entries,list) or len(entries)>200:fail()
        for entry in entries:
            if (not isinstance(entry,dict) or set(entry)!={'id','reason','lines'} or not isinstance(entry['id'],str)
                or not re.fullmatch('[a-z0-9_-]{1,40}',entry['id']) or entry['id'] in seen
                or not isinstance(entry['reason'],str) or not entry['reason'].strip() or len(entry['reason'])>1500
                or not isinstance(entry['lines'],list) or not 2<=len(entry['lines'])<=1000):fail()
            seen.add(entry['id']); balanced=0
            is_carry=period=='opening_adjustments' and entry['id'].startswith('carry-');has_carry|=is_carry
            is_generated=entry in generated[period]
            for line in entry['lines']:
                if not isinstance(line,dict) or set(line)!={'account','debit_cents','credit_cents','sphere'}:fail()
                account=line['account']; d=line['debit_cents']; c=line['credit_cents']
                if (not isinstance(account,str) or account not in book or type(d) is not int or type(c) is not int
                    or min(d,c)<0 or max(d,c)>100000000000 or (d>0)==(c>0)):fail()
                income=book[account]['group'] in ('expense','revenue')
                if (line['sphere'] not in spheres(profile) if income else line['sphere']!='') or (period=='opening_adjustments' and income):fail()
                balanced+=d-c
                if period=='opening_adjustments':nets['opening'][account]+=d-c
                else:nets['debit'][account]+=d;nets['credit'][account]+=c
                asset_movement=book[account]['group']=='fixed_assets' or mapping.get(account) in (TANGIBLE,INTANGIBLE,BUILDING,GOODWILL,GWG,SCRAPPING,SOFTWARE_END,GAIN_SALE,GAIN_BOOK,LOSS_SALE)
                if (account in managed or asset_movement) and not is_generated:
                    if not is_carry or account not in desired:fail()
                    carry[account]=carry.get(account,0)+d-c
            if balanced:fail()
    if has_carry:
        if any(carry.get(account,0)!=amount for account,amount in desired.items()):fail()
        generated['opening_adjustments']=[]
    if evidence['generated_opening']!=generated['opening_adjustments'] or evidence['generated_current']!=generated['current_adjustments']:fail()
    for period,entries in generated.items():
        actual=[e for e in values[period] if e['id'].startswith(('asset-opening-','asset-tax-','asset-scrap-','asset-sale-','cost-open-','cost-tax-'))]
        if actual!=entries:fail()
    if set(book)!=set(tax) or set(book)!=set(values['accounts']):fail()
    credit_groups={'equity','reserves','result_use','provisions','liabilities','member_payables','deferred','revenue'}
    for account,b in book.items():
        t=tax[account];row=values['accounts'][account]
        if any(t[k]!=b[k] or row[k]!=b[k] for k in ('name','group','skr_code')):fail()
        for field,kind in (('opening_cents','opening'),('debit_cents','debit'),('credit_cents','credit')):
            if type(t[field]) is not int or t[field]!=b[field]+nets[kind][account]:fail()
        closing=t['opening_cents']+t['debit_cents']-t['credit_cents'];amount=-closing if b['group'] in credit_groups else closing
        if t['closing_cents']!=closing or t['amount_cents']!=amount:fail()
        if (row['book_opening_cents']!=b['opening_cents'] or row['tax_opening_cents']!=t['opening_cents']
            or row['book_cents']!=b['amount_cents'] or row['tax_cents']!=amount or row['difference_cents']!=amount-b['amount_cents']):fail()
    for ledger in ('book','tax'):
        accounts=values[ledger]['accounts']
        profit=sum(a['amount_cents']*(1 if a['group']=='revenue' else -1) for a in accounts.values() if a['group'] in ('revenue','expense'))
        if values[ledger]['annual_result_cents']!=profit:fail()
    if values['result_difference_cents']!=values['tax']['annual_result_cents']-values['book']['annual_result_cents']:fail()
    return expected


def prepare_tax_assets(values,mapping,profile,meta,prepare):
    schedule=verify(values,profile,mapping)
    same_profile=dict(profile,asset_values_unchanged=True)
    original=deepcopy(values);original.pop('asset_tax');original['tax']=deepcopy(original['book'])
    original['opening_adjustments']=[];original['current_adjustments']=[]
    prepare(original,mapping,same_profile,meta,book_derived=True)
    derived=deepcopy(values);derived.pop('asset_tax');derived['asset_schedule']=schedule
    # Corrections were checked in full above. Gross movement comparisons below
    # use the asset rollforward rather than journal reversal debits/credits.
    derived['opening_adjustments']=[];derived['current_adjustments']=[]
    for key,account in derived['tax']['accounts'].items():
        if account['group']!='fixed_assets':continue
        items=[i for i in schedule['items'].values() if i['account']==key]
        account['debit_cents']=sum(i['additions_cents']+i.get('writeup_cents',0) for i in items)
        account['credit_cents']=sum(i['depreciation_cents']+i.get('impairment_cents',0)+i.get('book_disposals_cents',0) for i in items)
    derived['book']=deepcopy(derived['tax'])
    return prepare(derived,mapping,same_profile,meta,tax_derived=True)
