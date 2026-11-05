"""Checked annual cost asset BAL and counterpart facts; separate from ordinary AfA."""
from copy import deepcopy
import hashlib,json,re
from ebilanz_asset_valuation import calculate,FIELDS,from_book_plan
from ebilanz_asset_types import ACCOUNTS,cost_target
from ebilanz_cost_asset_grid import project
from ebilanz_cost_asset_tax import event_account
from ebilanz_period import require
from ebilanz_financial_profile import counter_key,counter_target
def classified_counter_target(plan,code):return counter_target(code,plan['financial_profile']) if 'financial_profile' in plan else COUNTERPARTS[code]
from ebilanz_whole_club import spheres

ELIGIBLE={'02000','13800','13820','09600', '09700', '09610'} | {'08800', '08100'} | {'08850', '08140'} | {'09000', '09100'} | {'02200', '02100'} | {'08080', '08500', '08040', '09800'} | {'01790', '01700'} | {'08150', '08130', '08830'} | {'07000'} | {'09350', '09900', '09300', '09400'} | {'02150','02350','09200'} | {'07250', '07500', '07700', '07950', '07400', '07050', '07100', '07850', '07200', '07800', '07550', '07350', '07650'}
TARGETS={'bs.ass.fixAss.fin.otherFinAss.reInsurClaim','bs.ass.fixAss.fin.loansToSharehold.gmbh', 'bs.ass.fixAss.fin.loansToSharehold.misc', 'bs.ass.fixAss.fin.otherLoans.other'} | {'bs.ass.fixAss.fin.loansToParticip.corporations', 'bs.ass.fixAss.fin.loansToAffil.corporations', 'bs.ass.fixAss.fin.loansToParticip.soleProprietor', 'bs.ass.fixAss.fin.loansToAffil.partnerships.other', 'bs.ass.fixAss.fin.loansToAffil.soleProprietor', 'bs.ass.fixAss.fin.loansToParticip.partnerships.other'} | {'bs.ass.fixAss.fin.loansToAffil.corporations', 'bs.ass.fixAss.fin.loansToParticip.corporations'} | {'bs.ass.fixAss.fin.securities.bonds', 'bs.ass.fixAss.fin.securities.shares', 'bs.ass.fixAss.fin.securities.convertBond', 'bs.ass.fixAss.fin.securities.other', 'bs.ass.fixAss.fin.securities.warrants', 'bs.ass.fixAss.fin.securities.partCertificate', 'bs.ass.fixAss.fin.securitiesOtherForeign', 'bs.ass.fixAss.fin.securities.mutInvestm', 'bs.ass.fixAss.fin.securities'} | {'bs.ass.fixAss.tan.landBuildings.rightEquivalentToLandWithoutBuildings'} | {'bs.ass.fixAss.fin.sharesInAffilOtherForeign', 'bs.ass.fixAss.fin.particip.corporations', 'bs.ass.fixAss.fin.sharesInAffil.corporations', 'bs.ass.fixAss.fin.participOtherForeign'} | {'bs.ass.fixAss.intan.advPaym'} | {'bs.ass.fixAss.fin.loansToParticip.partnerships.other', 'bs.ass.fixAss.fin.loansToAffil.partnerships.other', 'bs.ass.fixAss.fin.loansToAffil.soleProprietor'} | {'bs.ass.fixAss.fin.otherLoans.other'} | {ACCOUNTS[c]['target'] for c in ('02150','02350','09200')} | {'bs.ass.fixAss.tan.inConstrAdvPaym.buildingUnderConstr', 'bs.ass.fixAss.tan.inConstrAdvPaym.equipmUnderConstr', 'bs.ass.fixAss.tan.inConstrAdvPaym.advPaym'}
TC='is.netIncome.regular.operatingTC.'
COUNTERPARTS={'49110':'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.intan','62100':'is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.except.otherIntan','68960':'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.intan','49100':TC+'otherOpRevenue.revalFixAss.tan','49120':TC+'otherOpRevenue.revalFixAss.fin.other',
    '62300':TC+'deprAmort.fixAss.tan.otherMisc','68950':TC+'otherCost.disposFixAss.bookValue.tan.other',
    '68970':TC+'otherCost.disposFixAss.bookValue.fin.other',
    '72000':'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.other',
    '72010':'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.other'}

TARGETS |= {'bs.ass.fixAss.fin.otherLoans.silent', 'bs.ass.fixAss.fin.particip.silent'}
ELIGIBLE |= {'08300','08000','08090','08200'}

def cost_items(schedule):return {k:v for k,v in (schedule.get('items') or {}).items() if v.get('method',v.get('plan',{}).get('method'))=='cost'}

def counterparts(item,chart):
    retained={}
    for e in item['valuation']['source']['events']:
        code,group=event_account(item['cost_plan']['valuation_kind'],e['kind'],e.get('permanent',True),item['cost_plan'].get('financial_profile'),e.get('counter_code'),item['cost_plan']['account'])
        key=counter_key(code,item['cost_plan'].get('financial_profile'))
        require(chart.get(key,{}).get('skr_code')==code and chart[key]['group']==group,
            'Kostenanlagen: Ursprüngliches Bewertungsgegenkonto fehlt.')
        retained[code]=key
    require(retained==(item.get('valuation_accounts') or {}),'Kostenanlagen: Gegenkontennachweis wurde verändert.')
    return retained

def movement(item,code,side=None):
    side=side or item.get('valuation_side','book');require(side in ('book','tax'))
    value=item['valuation'];n=0
    for event,change in zip(value['source']['events'],value['movements'],strict=True):
        candidate,group=event_account(value['source']['plan']['kind'],event['kind'],event.get('permanent',True),value['source']['plan'].get('financial_profile'),event.get('counter_code'),value['source']['plan']['account'])
        if candidate==code:n+=change[side]['writeup_cents'] if group=='revenue' else change[side]['impairment_cents']+change[side]['book_disposals_cents']
    return n

def mapping(values):
    dated='period' in values
    schedule=(values.get('asset_schedule') if dated else (values.get('asset_tax') or {}).get('tax_schedule')) or values.get('asset_schedule') or {}
    out={};chart=values['tax']['accounts']
    for identifier,item in cost_items(schedule).items():
        if dated:item={'item_id':identifier,'skr_code':item['plan']['account'],'account':item['fixed_account'],
            'cost_plan':item['plan'],'valuation':item['valuation'],'valuation_accounts':item['valuation_accounts']}
        code=item['skr_code'];require(code in ELIGIBLE,'Generische Wertpapiere benötigen eine belegte Art- und Steuerzweckentscheidung vor dem XML-Export.')
        require(item['item_id']==identifier and chart[item['account']]['skr_code']==code)
        out[item['account']]=cost_target(item['cost_plan'])
        for code,key in counterparts(item,chart).items():
            require((code in COUNTERPARTS or 'financial_profile' in item['cost_plan']) and key==counter_key(code,item['cost_plan'].get('financial_profile')) and chart[key]['skr_code']==code)
            out[key]=classified_counter_target(item['cost_plan'],code)
    return out

def prepare(values,mapping,profile,meta,legacy,tax_derived=False):
    schedule=values['asset_schedule'];items=cost_items(schedule);require(items and 'period' not in values)
    side='tax' if tax_derived else 'book';rows={};managed=set();fixed={};expected={};known_fields=set(FIELDS)|{'depreciation_cents'}
    allowed=known_fields|{'item_id','invoice_id','receipt_number','name','account','skr_code','sphere','acquired_on',
        'months','residual_cents','method','basis_cents','expected_depreciation_cents','acquisition_hash','depreciation_hash',
        'cost_plan','valuation','valuation_accounts','valuation_side'}
    for identifier,item in items.items():
        require(not set(item)-allowed,'Kostenanlagen: Kombination benötigt einen eigenen geprüften Bewertungsweg.')
        plan=item['cost_plan'];value=item['valuation'];s=value['source'];code=plan['account'];key=item['account']
        require(code in ELIGIBLE and item['item_id']==identifier and item['skr_code']==code and mapping.get(key)==cost_target(plan))
        require(from_book_plan(plan)==s['plan'] and s['period']=={'start':f"{values['year']}-01-01",'end':f"{values['year']}-12-31"}
            and calculate(s['plan'],s['period'],s['events'],s['previous'])==value)
        require(item['sphere'] in spheres(profile) and item['sphere']==plan['sphere'] and item['depreciation_cents']==0
            and item['months']==0 and item['residual_cents']==0 and item['expected_depreciation_cents']==0
            and item.get('basis_cents',plan['basis_cents'])==plan['basis_cents'] and item['acquired_on']==plan['acquired_on']
            and (item.get('valuation_side','book')==side))
        require(all(item.get(f)==value[side][f] for f in FIELDS))
        for field in ('acquisition_hash','depreciation_hash'):require(type(item.get(field)) is str and re.fullmatch('[a-f0-9]{64}',item[field]))
        managed.add(key);v=value[side]
        aggregate=fixed.setdefault(key,{'code':code,'opening_cents':0,'amount_cents':0,'debit_cents':0,'credit_cents':0});require(aggregate['code']==code)
        for field,n in {'opening_cents':v['book_opening_cents'],'amount_cents':v['book_closing_cents'],
            'debit_cents':v['additions_cents']+v['writeup_cents'],'credit_cents':v['impairment_cents']+v['book_disposals_cents']}.items():aggregate[field]+=n
        for counter,account in counterparts(item,values[side=='tax' and 'tax' or 'book']['accounts']).items():
            require(account==counter_key(counter,plan.get('financial_profile')) and mapping.get(account)==classified_counter_target(plan,counter))
            managed.add(account);expected[account]=expected.get(account,0)+movement(item,counter,side)
        grid=project(value,side,meta['version'])['columns'];leaf=cost_target(plan)
        ancestors=set();pending=list(meta['asset_parents'].get(leaf,[]))
        while pending:
            parent=pending.pop();require(parent!=leaf)
            if parent in ancestors:continue
            ancestors.add(parent);pending.extend(meta['asset_parents'].get(parent,[]))
        require('bs.ass.fixAss' in ancestors)
        for target in {leaf}|ancestors:
            row=rows.setdefault(target,dict.fromkeys(grid,0))
            for column,n in grid.items():row[column]+=n
    for ledger in ('book','tax'):
        for key,v in fixed.items():
            a=values[ledger]['accounts'][key]
            require(a['group']=='fixed_assets' and a['skr_code']==v['code'] and all(a[f]==n for f,n in v.items() if f!='code'),
                'Kostenanlagen: Ursprüngliche Bruttobewegungen und Journal stimmen nicht überein.')
        for key,a in values[ledger]['accounts'].items():
            if key.startswith('src_asset_valuation_'):require(a['amount_cents']==expected.get(key,0),'Kostenanlagen: Originales Bewertungsgegenkonto ist nicht abgestimmt.')
    if not tax_derived:
        require(not any(line['account'] in managed for entry in values['opening_adjustments']+values['current_adjustments'] for line in entry['lines']),
            'Kostenanlagen: Steuerliche Unterschiede benötigen ihren ursprünglichen Bewertungsnachweis.')
    remaining=deepcopy(values);remaining['asset_schedule']['items']={k:i for k,i in schedule['items'].items() if k not in items}
    normal=remaining['asset_schedule']['items'];result={'rows':{},'items':0,'totals':{}}
    retained_fixed=set(fixed)|{i['account'] for i in normal.values()}
    require(all(k in retained_fixed or all(type(a[f]) is int and a[f]==0 for f in ('opening_cents','debit_cents','credit_cents','amount_cents'))
        for k,a in values['tax']['accounts'].items() if a['group']=='fixed_assets'),
        'Kostenanlagen: Vollständiges Anlagenverzeichnis mit allen Anlagenkonten abstimmen.')
    if normal:
        from ebilanz_assets import FIELDS as OLD,DISPOSAL_FIELDS
        fields=OLD+DISPOSAL_FIELDS if any('disposal' in i for i in normal.values()) else OLD
        remaining['asset_schedule']['totals']={f:sum(i.get(f,0) for i in normal.values()) for f in fields}
        for ledger in ('book','tax'):remaining[ledger]['accounts']={k:a for k,a in remaining[ledger]['accounts'].items() if k not in managed}
        result=legacy(remaining,{k:t for k,t in mapping.items() if k not in managed},profile,meta,tax_derived=tax_derived)
    require(set(schedule['totals'])==known_fields and all(type(n) is int and 0<=n<=100000000000
        and n==sum(i.get(f,0) for i in schedule['items'].values()) for f,n in schedule['totals'].items()))
    for target,grid in rows.items():
        row=result['rows'].setdefault(target,dict.fromkeys(grid,0))
        for column,n in grid.items():row[column]+=n
    require(all(row[p]==sum(row[c]*w for c,w in children.items()) for row in result['rows'].values() for p,children in meta['asset_calculations'].items()))
    result.update(items=result['items']+len(items),totals=deepcopy(schedule['totals']),
        sha256=hashlib.sha256(json.dumps(schedule,ensure_ascii=False,separators=(',',':')).encode()).hexdigest())
    return result
