"""Original HGB holding costs, independent of fiscal mirror capital.

Source reconstruction only; no inferred tax values, journal or export.
"""
from copy import deepcopy
from datetime import date,timedelta
import re
from ebilanz_partnership_basis import require,document,digest,LIMIT

ACCOUNTS={'08000','08030','08050','08090','08200','08400','08600'}
FIELDS=('cost_opening_cents','additions_cents','disposals_cents','cost_closing_cents',
 'accumulated_opening_cents','impairment_cents','writeup_cents','accumulated_disposals_cents',
 'accumulated_closing_cents','book_opening_cents','book_disposals_cents','book_closing_cents')

def cents(v,minimum=0):
 require(type(v) is int and minimum<=v<=LIMIT,'Ursprüngliche handelsrechtliche Centbeträge prüfen.');return v

def note(v):
 require(type(v) is str and v.strip() and len(v)<=2000 and not re.search(r'[\x00-\x1f]',v),'Handelsrechtliche Bewertung aus dem Originalnachweis begründen.')

def day(v):
 require(type(v) is str and re.fullmatch(r'\d{4}-\d{2}-\d{2}',v));d=date.fromisoformat(v);require(1900<=d.year<=2199);return d

def calculate(source,previous=None,depth=0):
 require(type(depth) is int and 0<=depth<=100)
 require(type(source) is dict and set(source)=={'schema','plan','period','events'} and type(source['schema']) is int and source['schema']==1)
 p=source['plan'];require(type(p) is dict and set(p)=={'holding_id','club_id','account','basis_cents','acquired_on','sphere','document','reason','confirmed'})
 require(type(p['holding_id']) is str and re.fullmatch('[a-f0-9]{32}',p['holding_id']) and type(p['club_id']) is str and p['club_id'].strip() and len(p['club_id'])<=64 and not re.search(r'[\x00-\x1f]',p['club_id']))
 require(p['account'] in ACCOUNTS and p['sphere'] in ('1','2','3','4') and p['confirmed'] is True)
 cost=cents(p['basis_cents'],1);acquired=day(p['acquired_on']);document(p['document']);note(p['reason'])
 period=source['period'];require(type(period) is dict and set(period)=={'start','end'})
 start=day(period['start']);end=day(period['end']);require(start<=end and acquired<=end)
 events=source['events'];require(type(events) is list and len(events)<=200)
 remaining=cost;value=cost;seen=[]
 if previous is not None:
  require(type(previous) is dict and type(previous.get('source')) is dict and calculate(previous['source'],previous.get('previous'),depth+1)==previous)
  prior=previous['source'];require(prior['plan']==p and day(prior['period']['end'])+timedelta(days=1)==start,'Ursprünglichen unmittelbaren HGB-Bewertungsvortrag nachweisen.')
  remaining=previous['book']['cost_closing_cents'];value=previous['book']['book_closing_cents'];seen=list(previous['event_ids'])
 else:require(acquired>=start,'Ältere Beteiligung benötigt ihren vollständig nachgerechneten ursprünglichen HGB-Vortrag.')
 before=acquired<start;opening_cost=remaining if before else 0;opening_value=value if before else 0
 row=dict.fromkeys(FIELDS,0);row.update(cost_opening_cents=opening_cost,book_opening_cents=opening_value,accumulated_opening_cents=opening_cost-opening_value,additions_cents=0 if before else cost)
 last=period['start'];movements=[]
 for e in events:
  require(type(e) is dict and e.get('kind') in ('addition','impairment','writeup','disposal'))
  extra={'addition':{'cost_cents'},'impairment':{'closing_cents','permanent'},'writeup':{'closing_cents'},'disposal':{'cost_cents','carrying_cents','allocation_document','no_proceeds'}}[e['kind']]
  require(set(e)=={'id','on','kind','document','reason','confirmed'}|extra and e['confirmed'] is True)
  require(type(e['id']) is str and re.fullmatch('[a-f0-9]{32}',e['id']) and e['id'] not in seen)
  day(e['on']);require(last<=e['on']<=period['end'] and e['on']>=p['acquired_on'] and remaining>0)
  document(e['document']);note(e['reason']);old=value;cost_delta=0
  if e['kind']=='addition':
   n=cents(e['cost_cents'],1);remaining=cents(remaining+n,1);value=cents(value+n);row['additions_cents']+=n;cost_delta=n
  elif e['kind']=='disposal':
   n=cents(e['cost_cents'],1);v=cents(e['carrying_cents']);document(e['allocation_document']);require(e['no_proceeds'] is True)
   require(n<=remaining and v<=n and v<=value and value-v<=remaining-n and (n!=remaining or v==value),'Tatsächliche ursprüngliche Kosten und Restbuchwerte des abgegangenen Anteils abstimmen.')
   remaining-=n;value-=v;row['disposals_cents']+=n;row['book_disposals_cents']+=v;row['accumulated_disposals_cents']+=n-v;cost_delta=-n
  else:
   value=cents(e['closing_cents']);require(value<=remaining,'HGB-Wertaufholung bleibt durch die ursprünglichen Restkosten begrenzt.')
   if e['kind']=='impairment':require(type(e['permanent']) is bool and value<old);row['impairment_cents']+=old-value
   else:require(value>old);row['writeup_cents']+=value-old
  seen.append(e['id']);last=e['on'];movements.append({'event_id':e['id'],'on':e['on'],'kind':e['kind'],'cost_change_cents':cost_delta,'book_change_cents':value-old})
  for n in row.values():cents(n)
 row.update(cost_closing_cents=remaining,book_closing_cents=value,accumulated_closing_cents=remaining-value)
 require(row['cost_opening_cents']+row['additions_cents']-row['disposals_cents']==remaining)
 require(row['accumulated_opening_cents']+row['impairment_cents']-row['writeup_cents']-row['accumulated_disposals_cents']==row['accumulated_closing_cents'])
 require(opening_value+row['additions_cents']-row['impairment_cents']+row['writeup_cents']-row['book_disposals_cents']==value)
 out={'schema':1,'source':deepcopy(source),'previous':deepcopy(previous),'event_ids':seen,'book':row,'movements':movements,'export_authorized':False}
 return out|{'sha256':digest(out)}
