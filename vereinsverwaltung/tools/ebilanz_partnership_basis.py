"""Original assessed partner capital; prototype, not integrated or export-qualified.

This validates source arithmetic. It does not determine Mitunternehmer status,
loss deductibility, supplement/special-business inclusion, or asset-grid measures.
It never derives fiscal capital from a purchase price or a tax deduction.
"""
from copy import deepcopy
from datetime import date,timedelta
import hashlib,json,re

LIMIT=100000000000
COMPONENTS=('capital_share_cents','supplementary_share_cents','special_share_cents')

def require(ok,message='Originalen steuerlichen Beteiligungsansatz vollständig nachweisen.'):
 if not ok:raise ValueError(message)

def digest(v):return hashlib.sha256(json.dumps(v,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()

def document(v):
 require(type(v) is dict and set(v)=={'document_id','revision','sha256'} and type(v['document_id']) is str and re.fullmatch('[a-f0-9]{32}',v['document_id']) and type(v['revision']) is int and v['revision']>=1 and type(v['sha256']) is str and re.fullmatch('[a-f0-9]{64}',v['sha256']))

def components(v,allow_negative=False):
 require(type(v) is dict and set(v)==set(COMPONENTS))
 require(all(type(n) is int and -LIMIT<=n<=LIMIT for n in v.values()))
 total=sum(v.values());require((-LIMIT if allow_negative else 0)<=total<=LIMIT,'Negativen Beteiligungsansatz mit eigenständigem Kapital-/Verbindlichkeitsnachweis behandeln; nicht auf null setzen.')
 return total

def calculate(source,previous=None,depth=0):
 require(type(depth) is int and 0<=depth<=100,'Steuerliche Kapitalvorträge sind zu umfangreich.')
 require(type(source) is dict and set(source)-{'negative_basis'}=={'schema','holding_id','club_id','period','account','classification','basis_scope','opening','closing','movements','document','reason','confirmed'})
 require(type(source['schema']) is int and source['schema']==1 and source['confirmed'] is True and source['basis_scope']=='original_assessed_components_no_duplicate_assets')
 require(type(source['holding_id']) is str and re.fullmatch('[a-f0-9]{32}',source['holding_id']) and type(source['club_id']) is str and source['club_id'].strip() and len(source['club_id'])<=64 and not re.search(r'[\x00-\x1f]',source['club_id']))
 require(source['account'] in ('08000','08030','08050','08090','08200','08400','08600'))
 require(source['classification'] in ('commercial_partner','atypical_silent_partner') and (source['account']!='08400' or source['classification']=='atypical_silent_partner'))
 require(type(source['reason']) is str and source['reason'].strip() and len(source['reason'])<=2000 and not re.search(r'[\x00-\x1f]',source['reason']))
 period=source['period'];require(type(period) is dict and set(period)=={'start','end'} and all(type(v) is str and re.fullmatch(r'\d{4}-\d{2}-\d{2}',v) for v in period.values()));start=date.fromisoformat(period['start']);end=date.fromisoformat(period['end']);require(start<=end and 1900<=start.year<=end.year<=2199)
 negative=source.get('negative_basis');allow_negative=negative is not None
 if allow_negative:
  require(type(negative) is dict and set(negative)=={'reporting','document','reason','confirmed'} and negative['reporting']=='original_signed_mirror_asset' and negative['confirmed'] is True,'Negativen ursprünglichen Spiegelbildansatz mit eigenständigem Originalnachweis ausdrücklich bestätigen.')
  document(negative['document']);require(type(negative['reason']) is str and negative['reason'].strip() and len(negative['reason'])<=2000 and not re.search(r'[\x00-\x1f]',negative['reason']))
 document(source['document']);opening=components(source['opening'],allow_negative);closing=components(source['closing'],allow_negative)
 if previous is not None:
  require(type(previous) is dict and previous.get('source') is not None and calculate(previous['source'],previous.get('previous'),depth+1)==previous)
  prior=previous['source'];require(prior['holding_id']==source['holding_id'] and prior['club_id']==source['club_id'] and prior['account']==source['account'] and prior['classification']==source['classification'] and prior['basis_scope']==source['basis_scope'] and prior['closing']==source['opening'] and date.fromisoformat(prior['period']['end'])+timedelta(days=1)==start,'Unveränderten unmittelbaren steuerlichen Kapitalvortrag verwenden.')
 movements=source['movements'];require(type(movements) is list and len(movements)<=200)
 running=dict(source['opening']);seen=set(previous['event_ids']) if previous else set();last=period['start'];deltas=[]
 for e in movements:
  require(type(e) is dict and set(e)=={'id','on','kind','components','document','reason','confirmed'})
  require(type(e['id']) is str and re.fullmatch('[a-f0-9]{32}',e['id']) and e['id'] not in seen and e['confirmed'] is True)
  require(type(e['on']) is str and re.fullmatch(r'\d{4}-\d{2}-\d{2}',e['on']) and last<=e['on']<=period['end']);date.fromisoformat(e['on']);document(e['document'])
  require(e['kind'] in ('contribution','withdrawal','assessed_profit','assessed_loss','assessed_basis_correction'))
  require(type(e['reason']) is str and e['reason'].strip() and len(e['reason'])<=2000 and not re.search(r'[\x00-\x1f]',e['reason']) and type(e['components']) is dict and set(e['components'])==set(COMPONENTS))
  require(all(type(n) is int and -LIMIT<=n<=LIMIT for n in e['components'].values()));delta=sum(e['components'].values());require(delta!=0 and -LIMIT<=delta<=LIMIT)
  if e['kind'] in ('contribution','assessed_profit'):require(delta>0)
  if e['kind'] in ('withdrawal','assessed_loss'):require(delta<0)
  for k,n in e['components'].items():running[k]+=n
  components(running,allow_negative);seen.add(e['id']);last=e['on'];deltas.append({'event_id':e['id'],'basis_change_cents':delta})
 require(running==source['closing'] and opening+sum(x['basis_change_cents'] for x in deltas)==closing,'Originale Kapitalanteile, Ergänzungs-/Sonderwerte und Bewegungen stimmen nicht mit dem Abschlussnachweis überein.')
 result={'schema':1,'source':deepcopy(source),'previous':deepcopy(previous),'opening_cents':opening,'closing_cents':closing,'event_ids':list(previous['event_ids'])+[e['id'] for e in movements] if previous else [e['id'] for e in movements],'movements':deltas,'export_authorized':False}
 return result|{'sha256':digest(result)}
