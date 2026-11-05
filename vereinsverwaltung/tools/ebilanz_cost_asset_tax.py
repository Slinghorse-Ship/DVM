"""Independent projection of evidenced cost valuations into tax-journal differences."""
from copy import deepcopy
import hashlib
import json
from ebilanz_asset_valuation import calculate, FIELDS, from_book_plan
from ebilanz_asset_types import ACCOUNTS
from ebilanz_period import check, require

def digest(value):
    return hashlib.sha256(json.dumps(value,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()

def event_account(kind,event,permanent=True,profile=None,original=None,account=None):
    require(kind in ('land','financial','construction','intangible_advance'))
    require(original is None or profile is not None)
    if profile is not None:
        from ebilanz_financial_profile import counter_code
        require(kind=='financial');return counter_code(event,permanent,profile,original,account),('revenue' if event=='writeup' else 'expense')
    if event=='writeup':return '49110' if kind=='intangible_advance' else '49100' if kind!='financial' else '49120','revenue'
    if event=='disposal':return '68960' if kind=='intangible_advance' else '68950' if kind!='financial' else '68970','expense'
    require(event=='impairment')
    return ('62100' if kind=='intangible_advance' else '62300' if kind!='financial' else '72000' if permanent else '72010'),'expense'

def pair(fixed,other,net,sphere):
    return [{'account':fixed,'debit_cents':max(0,net),'credit_cents':max(0,-net),'sphere':''},
            {'account':other,'debit_cents':max(0,-net),'credit_cents':max(0,net),'sphere':sphere}]

def item_projection(item,chart,period):
    p=item['cost_plan'];v=item['valuation'];s=v['source'];period=check(period)
    require(from_book_plan(p)==s['plan'] and s['period']==period and calculate(s['plan'],period,s['events'],s['previous'])==v)
    require(all(item.get(f)==v['book'][f] for f in FIELDS))
    fixed=item['account'];definition=ACCOUNTS.get(p['account'],{})
    require(definition.get('kind')==p['valuation_kind'] and chart.get(fixed,{}).get('group')=='fixed_assets' and chart[fixed]['skr_code']==p['account'])
    current=[];managed={fixed:True};difference={}
    for e,m in zip(s['events'],v['movements'],strict=True):
        require(e['id']==m['event_id']);code,group=event_account(p['valuation_kind'],e['kind'],e.get('permanent',True),p.get('financial_profile'),e.get('counter_code'),p['account'])
        key=item['valuation_accounts'].get(code)
        require(type(key) is str and chart.get(key,{}).get('group')==group and chart[key]['skr_code']==code)
        managed[key]=True;book,tax=m['book'],m['tax']
        net=tax['writeup_cents']-book['writeup_cents']-(tax['impairment_cents']-book['impairment_cents'])-(tax['book_disposals_cents']-book['book_disposals_cents'])
        difference[e['id']]={'account':key,'asset_difference_cents':net}
        if net:current.append({'id':'cost-tax-'+hashlib.sha256((item['item_id']+e['id']).encode()).hexdigest()[:24],
            'reason':'Steuerliche Anlagenbewertung: '+e['reason'][:1300],'lines':pair(fixed,key,net,p['sphere'])})
    opening=v['tax']['book_opening_cents']-v['book']['book_opening_cents'];closing=v['tax']['book_closing_cents']-v['book']['book_closing_cents']
    require(opening+sum(e['asset_difference_cents'] for e in difference.values())==closing)
    tax_item=deepcopy(item);tax_item.update(v['tax']);tax_item['valuation_side']='tax'
    return {'tax_item':tax_item,'generated_current':current,'managed_accounts':list(managed),'opening_difference_cents':opening,
        'proof':{'kind':'cost','book_item_sha256':digest(item),'valuation_sha256':v['sha256'],
            'opening_difference_cents':opening,'closing_difference_cents':closing,'events':difference or []}}
