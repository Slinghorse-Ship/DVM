"""Independent original development cost/exclusion source stage only.
No journal postings, depreciation estimate, catalog permission or DB access.
"""
from copy import deepcopy
from datetime import date,timedelta
import hashlib,json,re
def require(ok,message='Originale Entwicklungskosten und Ansatznachweise vollständig prüfen.'):
 if not ok:raise ValueError(message)
def digest(v):return hashlib.sha256(json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def document(v):
 require(type(v) is dict and set(v)=={'document_id','revision','sha256'} and type(v['document_id']) is str and re.fullmatch('[a-f0-9]{32}',v['document_id']) and type(v['revision']) is int and v['revision']>=1 and type(v['sha256']) is str and re.fullmatch('[a-f0-9]{64}',v['sha256']))
def note(v):require(type(v) is str and bool(v.strip()) and len(v)<=2000 and not re.search('[\x00-\x1f]',v))
def day(v):require(type(v) is str and re.fullmatch('[0-9]{4}-[0-9]{2}-[0-9]{2}',v));return date.fromisoformat(v)
def money(v):require(type(v) is int and 0<=v<=100000000000)
def calculate(s,previous=None,_depth=0):
 require(type(_depth) is int and 0<=_depth<2,'Nur die tatsächlich gebundenen Originaljahre 2025/2026 zulassen.')
 require(type(s) is dict and set(s)=={'schema','club_id','item_id','project_id','account','period','product_kind','own_creation','separated_research_development','capitalise','stage','ready_on','life_months','costs','book_document','book_reason','book_confirmed'})
 require(type(s['schema']) is int and s['schema']==1 and all(type(s[k]) is str and re.fullmatch('[a-f0-9]{32}',s[k]) for k in ('item_id','project_id')))
 require(type(s['club_id']) is str and bool(s['club_id'].strip()) and len(s['club_id'])<=128 and not re.search('[\x00-\x1f]',s['club_id']))
 require(s['account'] in ('01440','01450','01460','01470','01480') and s['own_creation'] is True and type(s['capitalise']) is bool and type(s['separated_research_development']) is bool and s['book_confirmed'] is True)
 require(type(s['period']) is dict and set(s['period'])=={'start','end'});start=day(s['period']['start']);end=day(s['period']['end']);require(start<=end and start.year in (2025,2026) and start==date(start.year,1,1) and end==date(start.year,12,31))
 kinds={'01440':{'software'},'01450':{'licence_franchise'},'01460':{'concession_patent'},'01470':{'recipe_procedure_intangible_prototype'},'01480':{'software','licence_franchise','concession_patent','recipe_procedure_intangible_prototype'}}
 require(type(s['product_kind']) is str and s['product_kind'] in kinds[s['account']],'Die ursprüngliche Art des selbst geschaffenen immateriellen Werts muss zum Konto passen.');require(s['stage'] in ('development','ready'))
 if s['capitalise']:require(s['separated_research_development'],'Forschung und Entwicklung müssen verlässlich getrennt sein.')
 if s['stage']=='development':require(s['account']=='01480' and s['ready_on'] is None and s['life_months'] is None)
 else:require(s['account']!='01480' and day(s['ready_on'])<=end and type(s['life_months']) is int and 12<=s['life_months']<=1200 and s['life_months']%12==0)
 document(s['book_document']);note(s['book_reason']);require(type(s['costs']) is list and len(s['costs'])<=200)
 eligible=excluded=0;seen=set();breakdown={}
 allowed={'material','manufacturing','special_manufacturing','material_overhead','manufacturing_overhead','manufacturing_asset_consumption','general_administration_elected','social_facilities_elected','voluntary_social_elected','pension_elected','manufacturing_borrowing_interest_elected'}
 for c in s['costs']:
  require(type(c) is dict and set(c)=={'id','on','phase','category','booked_cents','attributable_cents','journal_id','line_ordinal','document','reason','confirmed'})
  require(type(c['id']) is str and re.fullmatch('[a-f0-9]{32}',c['id']) and c['id'] not in seen);seen.add(c['id']);require(start<=day(c['on'])<=end and c['phase'] in ('research','development') and type(c['category']) is str and c['category'] in allowed|{'research','sales'} and c['confirmed'] is True)
  money(c['booked_cents']);money(c['attributable_cents']);require(c['attributable_cents']<=c['booked_cents']);require(type(c['journal_id']) is str and bool(re.fullmatch('[a-f0-9]{32}',c['journal_id'])) and type(c['line_ordinal']) is int and c['line_ordinal']>=0)
  identity=(c['journal_id'],c['line_ordinal']);require(identity not in seen,'Originale Kostenzeile nicht doppelt berücksichtigen.');seen.add(identity);document(c['document']);note(c['reason'])
  if s['stage']=='ready':require(day(c['on'])<=day(s['ready_on']),'Nachträgliche wesentliche Verbesserung benötigt einen eigenen ursprünglichen Entwicklungsnachweis.')
  amount=c['attributable_cents'];is_eligible=c['phase']=='development' and c['category'] in allowed
  if is_eligible:eligible+=amount
  else:excluded+=amount
  money(eligible);money(excluded)
  breakdown[c['id']]={'eligible':is_eligible,'original_attributed_cents':amount}
 opening=0
 if previous is not None:
  require(type(previous) is dict and set(previous)=={'schema','source','previous','original_eligible_current_cost_cents','original_excluded_current_cost_cents','hgb_original_cost_addition_cents','hgb_cumulative_original_cost_cents','cost_rows','depreciation_cents','journal_verified','export_authorized','sha256'} and type(previous['source']) is dict and (previous['previous'] is None or type(previous['previous']) is dict))
  require(calculate(previous['source'],previous['previous'],_depth+1)==previous);ps=previous['source'];require(all(ps[k]==s[k] for k in ('club_id','item_id','project_id','account','product_kind','capitalise','stage','ready_on','life_months')) and day(ps['period']['end'])+timedelta(days=1)==start);opening=previous['hgb_cumulative_original_cost_cents']
  old_ids={c['id'] for c in ps['costs']};old_lines={(c['journal_id'],c['line_ordinal']) for c in ps['costs']};require(all(c['id'] not in old_ids and (c['journal_id'],c['line_ordinal']) not in old_lines for c in s['costs']),'Vorjahreskosten nicht erneut aktivieren.')
 current=eligible if s['capitalise'] else 0;money(opening+current)
 out={'schema':1,'source':deepcopy(s),'previous':deepcopy(previous),'original_eligible_current_cost_cents':eligible,'original_excluded_current_cost_cents':excluded,'hgb_original_cost_addition_cents':current,'hgb_cumulative_original_cost_cents':opening+current,'cost_rows':breakdown,'depreciation_cents':None,'journal_verified':False,'export_authorized':False}
 return out|{'sha256':digest(out)}
