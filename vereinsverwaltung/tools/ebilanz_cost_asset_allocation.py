"""Replay analytical BOOK usage; never split the original tax ownership/plan."""
from ebilanz_asset_allocation import FIELDS,DISPOSALS,cents,capped,require
from ebilanz_asset_valuation import FIELDS as COST_FIELDS,calculate,reconcile

def split(value,weights):
 s=value['source'];require(calculate(s['plan'],s['period'],s['events'],s['previous'])==value)
 return _replay(value,weights,0)

def _replay(value,weights,depth):
 require(depth<=100)
 zero=cents(0,weights);old=value['source']['previous'];previous=_replay(old,weights,depth+1) if old else None
 fields=FIELDS+DISPOSALS+('impairment_cents','writeup_cents');parts={};cost={};accum={};added=cents(value['book']['additions_cents'],weights)
 for u in zero:
  p=previous['parts'][u] if previous else {};parts[u]=dict.fromkeys(fields,0)
  for f in ('cost','accumulated','book'):parts[u][f+'_opening_cents']=p.get(f+'_closing_cents',0)
  parts[u]['additions_cents']=added[u];cost[u]=parts[u]['cost_opening_cents']+added[u];accum[u]=parts[u]['accumulated_opening_cents']
 events={}
 for i,movement in enumerate(value['movements']):
  book={u:n-accum[u] for u,n in cost.items()};m=movement['book'];event={}
  if movement['kind']=='disposal':
   released=value['source']['events'][i]['cost_cents'];lost_book=capped(m['book_disposals_cents'],weights,book);lost_accum=capped(released-m['book_disposals_cents'],weights,accum)
   for u in zero:
    parts[u]['book_disposals_cents']+=lost_book[u];parts[u]['accumulated_disposals_cents']+=lost_accum[u];parts[u]['disposals_cents']+=lost_book[u]+lost_accum[u]
    cost[u]-=lost_book[u]+lost_accum[u];accum[u]-=lost_accum[u];event[u]={'impairment_cents':0,'writeup_cents':0,'book_disposals_cents':lost_book[u]}
  else:
   down=capped(m['impairment_cents'],weights,book);up=capped(m['writeup_cents'],weights,accum)
   for u in zero:
    parts[u]['impairment_cents']+=down[u];parts[u]['writeup_cents']+=up[u];accum[u]+=down[u]-up[u];event[u]={'impairment_cents':down[u],'writeup_cents':up[u],'book_disposals_cents':0}
  events[movement['event_id']]=event
 for u in zero:
  parts[u].update(cost_closing_cents=cost[u],accumulated_closing_cents=accum[u],book_closing_cents=cost[u]-accum[u]);require(min(parts[u].values())>=0)
  p=parts[u];reconcile({f:p[f] for f in COST_FIELDS})
 require(all(sum(p[f] for p in parts.values())==value['book'][f] for f in COST_FIELDS))
 return {'parts':parts,'events':events}

def movements(item,chart,weights):
 from ebilanz_cost_asset_tax import event_account
 value=item['valuation'];projection=split(value,weights);out={};codes=set();retained={}
 for e in value['source']['events']:
  code,group=event_account(value['source']['plan']['kind'],e['kind'],e.get('permanent',True),value['source']['plan'].get('financial_profile'),e.get('counter_code'),value['source']['plan']['account']);key=(item.get('valuation_accounts') or {}).get(code)
  from ebilanz_financial_profile import counter_key
  require(key==counter_key(code,value['source']['plan'].get('financial_profile')) and chart.get(key,{}).get('skr_code')==code and chart[key]['group']==group)
  retained[code]=key;codes.add(code)
  for u,v in projection['events'][e['id']].items():
   n=v['writeup_cents'] if group=='revenue' else v['impairment_cents']+v['book_disposals_cents'];out.setdefault(u,{})[key]=out.get(u,{}).get(key,0)+n
 require(retained==(item.get('valuation_accounts') or {}))
 return out,codes
