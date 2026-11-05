"""Original HGB-only values, nonadditive own-creation disclosures and gross grid."""
from copy import deepcopy
from ebilanz_own_intangible_sources import require,digest
from ebilanz_own_intangible_book_journals import verify
CODES={'01440','01450','01460','01470','01480'}

TARGET='bs.ass.fixAss.intan.selfmade'
TC='is.netIncome.regular.operatingTC.'
COUNTERS={'48250':TC+'grossTradingProfit.totalOutput.ownWork.intan','62010':TC+'deprAmort.fixAss.otherIntan','62110':TC+'deprAmort.fixAss.specific.except.otherIntan','49110':TC+'otherOpRevenue.revalFixAss.intan','68960':TC+'otherCost.disposFixAss.bookValue.intan'}

CONTRACTS={f'original-own-intangible-r72-{code}@1':(code,'fixed_assets',TARGET) for code in sorted(CODES)}
CONTRACTS.update({f'original-own-intangible-r72-{code}@1':(code,'revenue' if code=='48250' else 'expense',COUNTERS[code]) for code in ('48250','62010','62110')})

def validate_rule(rule):
 ref=rule.get('own_intangible_contract')
 require(ref in CONTRACTS,'Selbst geschaffene Werte: ursprünglichen Entwicklungs-, Register- und Journalvertrag prüfen.')
 code,group,target=CONTRACTS[ref]
 require(rule.get('group')==group and rule.get('condition')=='own_intangible_original' and rule.get('target')==target and rule.get('allowed_targets')==[target] and rule.get('book_only') is True)
 require(rule.get('own_source_disclosures',[])==([TARGET+'.finished',TARGET+'.underConstr'] if group=='fixed_assets' else []))
 require(not any(k in rule for k in ('commercial_contract','partnership_income_contract','partnership_book_target','partnership_book_disclosures','commercial_income_direction','negative_target','directional_balance')) and not rule.get('disclosures'))
 if 'codes' in rule:require(rule['codes']==[code])

def register(item,p):
 h=p['book_proof']['hgb'];s=p['book_proof']['source_proof']['source'];plan=item['own_plan'];first=p['book_proof']
 while first['previous'] is not None:first=first['previous']
 initial=first['source_proof'];original=initial['source']
 require(set(plan)=={'basis_cents','residual_cents','acquired_on','date_role','months','sphere','account','reason','method','own_source'} and plan['own_source']==original)
 require(plan['basis_cents']==initial['hgb_original_cost_addition_cents'] and plan['residual_cents']==0 and plan['acquired_on']==original['period']['start'] and plan['date_role']=='original_cost_period_start' and plan['months']==(original['life_months'] or 0) and plan['sphere'] in ('1','2','3','4') and plan['account']==s['account'] and plan['reason']==original['book_reason'] and plan['method']=='own_intangible_hgb')
 amounts={'cost_opening_cents':h['cost_opening_cents'],'cost_closing_cents':h['cost_closing_cents'],'additions_cents':h['additions_cents'],'accumulated_opening_cents':h['amort_opening_cents']+h['impairment_opening_cents'],'accumulated_closing_cents':h['amort_closing_cents']+h['impairment_closing_cents'],'book_opening_cents':h['book_opening_cents'],'book_closing_cents':h['book_closing_cents'],'depreciation_cents':h['amortisation_cents'],'expected_depreciation_cents':h['amortisation_cents'],'impairment_cents':h['impairment_cents'],'writeup_cents':h['writeup_cents'],'disposals_cents':h['disposed_cost_cents'],'accumulated_disposals_cents':h['disposed_amort_cents']+h['disposed_impairment_cents'],'book_disposals_cents':h['disposed_book_cents']}
 require(all(type(item.get(k)) is int and item[k]==n for k,n in amounts.items()))
 identity={'item_id':s['item_id'],'invoice_id':None,'receipt_number':'Originales Entwicklungsregister','account':p['journal']['account_key'],'skr_code':s['account'],'sphere':plan['sphere'],'acquired_on':plan['acquired_on'],'date_role':plan['date_role'],'months':plan['months'],'residual_cents':0,'method':'own_intangible_hgb','acquisition_hash':None}
 require(all(item.get(k)==v for k,v in identity.items()) and type(item.get('depreciation_hash')) is str and __import__('re').fullmatch('[a-f0-9]{64}',item['depreciation_hash']))
 require(set(item)==set(identity)|set(amounts)|{'own_plan','own_book','own_accounts','own_event_ids','depreciation_hash','name'})
 expected={trace['original']['id']:trace['original'] for trace in p['book_proof']['events']};accounts={expected[event]['counter_code']:trace['counter_key'] for event,trace in p['original_book_lines'].items()}
 require(item['own_accounts']==(accounts or []) and item['own_event_ids']==[event['original']['id'] for event in p['book_proof']['events']])
 return {k:n for k,n in amounts.items() if k!='expected_depreciation_cents'}

def verify_values(values):
 active={k for k,a in values['book']['accounts'].items() if a['skr_code'] in CODES and any(a[f] for f in ('opening_cents','debit_cents','credit_cents'))}
 if 'own_hgb_sources' not in values:
  require(not active,'Ursprünglichen vollständigen HGB-Journalnachweis selbst geschaffener Werte übernehmen.')
  return None
 e=values['own_hgb_sources'];require(type(e) is dict and set(e)=={'schema','club_id','year','journal_sha256','schedule_sha256','sources','export_authorized','sha256'} and type(e['schema']) is int and e['schema']==1 and e['export_authorized'] is False and digest({k:v for k,v in e.items() if k!='sha256'})==e['sha256'])
 require(values.get('annual_section')=='books' and 'whole_club_scope' in values and not any(k in values for k in ('period','club_scope','asset_tax','partnership_holdings','own_intangible_sources')))
 r=values['whole_club_scope']['source'];require(e['year']==values['year']==r['year'] and e['journal_sha256']==values['journal_sha256']==r['closed_sha256'] and e['schedule_sha256']==digest(r['asset_schedule']) and values['asset_schedule']==r['asset_schedule'])
 require(values['opening_adjustments']==values['current_adjustments']==[] and values['book']==values['tax'])
 items=r['asset_schedule']['items'];rows=e['sources'];require(type(items) is dict and type(rows) is dict and len(items)==len(rows)==1 and set(items)==set(rows))
 for identifier,p in rows.items():
  item=items[identifier];require(item['method']=='own_intangible_hgb' and verify(p['book_proof'],p['journal'],p['previous'])==p and p['book_proof']==item['own_book'])
  j=p['journal'];register(item,p);require(j['club_id']==e['club_id'] and j['item_id']==identifier and j['year']==r['year'] and j['account_key']==item['account'] and j['chart']==r['accounts'] and j['result_account']==r['result_account'] and j['book_opening']==r['opening'])
  docs={};events={row['original']['id']:row['original'] for row in p['book_proof']['events']}
  for cost in p['book_proof']['source_proof']['source']['costs']:
   key=cost['journal_id'];require(key not in docs or docs[key]==cost['document']);docs[key]=cost['document']
  for voucher,event in j['book_links'].items():require(voucher not in docs and event in events);docs[voucher]=events[event]['document']
  original=deepcopy(r['entries']);found=set()
  for entry in original:
   require('document' not in entry)
   if entry['id'] in docs:entry['document']=docs[entry['id']];found.add(entry['id'])
  require(found==set(docs) and original==j['book_entries'] and {k:v for k,v in p['book_calculation'].items() if k!='period'}==values['book'] and (r['previous_sha256'] is None)==(p['previous'] is None))
 require(active<={p['journal']['account_key'] for p in rows.values()})
 require(r['asset_schedule']['issues']==[] and set(r['asset_schedule']['totals'])==set(register(next(iter(items.values())),next(iter(rows.values())))) and all(type(n) is int and n==sum(register(items[key],rows[key])[field] for key in items) for field,n in r['asset_schedule']['totals'].items()))
 return e

def mapping(values):
 if 'own_hgb_sources' not in values:return {}
 e=verify_values(values);out={}
 for p in e['sources'].values():
  key=p['journal']['account_key'];out[key]=TARGET;sums={}
  for trace in p['original_book_lines'].values():
   counter=trace['counter_key'];code=p['journal']['chart'][counter]['skr_code'];require(code in COUNTERS)
   require(counter not in out or out[counter]==COUNTERS[code]);out[counter]=COUNTERS[code]
   n=sums.setdefault(counter,[0,0])
   for line in trace['original']['lines']:
    if line['account']==counter:n[0]+=line['debit_cents'];n[1]+=line['credit_cents']
  for key,(debit,credit) in sums.items():
   a=values['book']['accounts'][key];require(a['opening_cents']==0 and a['debit_cents']==debit and a['credit_cents']==credit,'Gemeinsame Gegenkonten brauchen alle ursprünglichen Bewegungen.')
 return out

def disclosures(values,book_only):
 if not book_only:
  require('own_hgb_sources' not in values);return {}
 e=verify_values(values)
 if e is None:return {}
 out={}
 for p in e['sources'].values():
  s=p['book_proof']['source_proof']['source'];target=TARGET+('.underConstr' if s['stage']=='development' else '.finished')
  out[target]=out.get(target,0)+p['book_proof']['hgb']['book_closing_cents']
 return out

def grid(p,meta):
 h=p['book_proof']['hgb'];eq=meta['asset_calculations']
 require(eq['all']=={'accDepr':-1,'gross':1,'revaluation':1} and eq['accDepr'].get('accDepr.release')==-1 and eq['accDepr.DeprPeriod'].get('accDepr.DeprPeriod.extraordinary')==1 and eq['accDepr.DeprPeriod'].get('accDepr.DeprPeriod.regular')==1)
 cols=set(eq)|{c for children in eq.values() for c in children}|{'all_Prev_period'};v=dict.fromkeys(cols,0)
 v.update({'grossCost.beginning':h['cost_opening_cents'],'gross.addition':h['additions_cents'],'gross.release':h['disposed_cost_cents'],'gross':h['cost_closing_cents'],
  'accDepr.beginning':h['amort_opening_cents']+h['impairment_opening_cents'],'accDepr.DeprPeriod.regular':h['amortisation_cents'],'accDepr.DeprPeriod.extraordinary':h['impairment_cents'],'accDepr.DeprPeriod':h['amortisation_cents']+h['impairment_cents'],
  'accDepr.release':h['disposed_amort_cents']+h['disposed_impairment_cents'],'accDepr':h['amort_closing_cents']+h['impairment_closing_cents']+h['writeup_cents'],'revaluation':h['writeup_cents'],'all_Prev_period':h['book_opening_cents'],'all':h['book_closing_cents']})
 require(all(type(n) is int and 0<=n<=100000000000 for n in v.values()) and all(v[parent]==sum(v[c]*w for c,w in children.items()) for parent,children in eq.items()),'Ursprüngliche HGB-Bruttobewegungen erfüllen den amtlichen Spiegel nicht.')
 require(v['grossCost.beginning']-v['accDepr.beginning']==v['all_Prev_period'])
 return v

def prepare(values,targets,profile,meta):
 e=verify_values(values);require(e is not None and profile.get('kind')=='whole_taxable_club' and profile.get('asset_scope')=='whole_club' and profile.get('asset_values_unchanged') is True)
 original=mapping(values);require(all(targets.get(k)==t for k,t in original.items()))
 rows={}
 for p in e['sources'].values():
  v=grid(p,meta);pending=list(meta['asset_parents'].get(TARGET,[]));ancestors=set()
  while pending:
   parent=pending.pop();require(parent!=TARGET)
   if parent in ancestors:continue
   ancestors.add(parent);pending.extend(meta['asset_parents'].get(parent,[]))
  require('bs.ass.fixAss' in ancestors and meta['asset_parents'].get(TARGET+'.finished')==[TARGET] and meta['asset_parents'].get(TARGET+'.underConstr')==[TARGET])
  # Main amount and its ancestors are additive; the two 'davon' leaves only
  # disclose the existing main amount and must not aggregate into it again.
  for target in {TARGET}|ancestors:
   row=rows.setdefault(target,dict.fromkeys(v,0))
   for col,n in v.items():row[col]+=n
  s=p['book_proof']['source_proof']['source'];leaf=TARGET+('.underConstr' if s['stage']=='development' else '.finished');rows[leaf]=deepcopy(v)
  for key,a in values['book']['accounts'].items():
   if a['group']=='fixed_assets' and key!=p['journal']['account_key']:require(all(a[f]==0 for f in ('opening_cents','debit_cents','credit_cents','amount_cents')))
 return {'rows':rows,'items':len(e['sources']),'totals':deepcopy(values['asset_schedule']['totals']),'sha256':digest({'original_hgb_sources_sha256':e['sha256'],'rows':rows})}
