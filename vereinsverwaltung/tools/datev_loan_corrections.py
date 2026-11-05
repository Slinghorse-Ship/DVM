"""Exact source-bound loan/counter capability extension, retaining all old reviewed choices."""
ADDED={'08100','08140','08800','08850'}
COUNTERS={
 '49120':['is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.domesticLoan.unrelatedParty','is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.foreignLoan.unrelatedParty'],
 '72040':['is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.receivCorp25pt.domestic','is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.receivCorp25pt.foreign'],
 '72010':['is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.participCorp.domesticCorp','is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.participCorp.foreignCorp','is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.receivCorp25pt.domestic','is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.receivCorp25pt.foreign']}
def require(ok):
 if not ok:raise ValueError('Quellengebundene Darlehens- und Gegenkontoerweiterung vollständig prüfen.')
def validate(model,previous,current,rows,proof):
 import datev_years as d
 from ebilanz_financial_profile import LOANS,rule_targets,validate_rule
 require(proof.get('kind')=='reviewed_loan_counter_extension' and proof.get('production_authorized') is False and proof.get('evidence')=='resources/accounting/reviews/corporate-loans-r55.json')
 require(set(current)-set(previous)==ADDED and set(previous)<=set(current));changed={c for c in previous if previous[c]!=current[c]};require(changed==set(COUNTERS));evidence=proof.get('corrections');require(type(evidence) is dict and set(evidence)==set(COUNTERS))
 for c in COUNTERS:
  item=evidence[c];old_ref=previous[c];new_ref=current[c];pid,v=old_ref.rsplit('@',1)
  require(type(item) is dict and set(item)=={'previous_purpose','new_purpose','source_signature','previous_definition_sha256','new_definition_sha256'} and new_ref==pid+'@'+str(int(v)+1) and item['previous_purpose']==old_ref and item['new_purpose']==new_ref and rows[c]['bookable'] and item['source_signature']==rows[c]['signature'])
  require(item['previous_definition_sha256']==d.digest(d.definition(model,old_ref)) and item['new_definition_sha256']==d.digest(d.definition(model,new_ref)))
  old=d.mapping(model,old_ref,'6.9');new=d.mapping(model,new_ref,'6.9');require(new['target']==old['target'] and new['group']==old['group'] and new['condition']==old['condition'] and new['allowed_targets']==old['allowed_targets']+COUNTERS[c])
  require({k:v for k,v in new.items() if k not in ('allowed_targets','target_labels','review_message')}=={k:v for k,v in old.items() if k not in ('allowed_targets','target_labels','review_message')})
  require(all(new.get('target_labels',{}).get(k)==v for k,v in old.get('target_labels',{}).items()))
 for c in ADDED:
  rule=d.mapping(model,current[c],'6.9');validate_rule(rule);require(rows[c]['bookable'] and rule['group']=='fixed_assets' and rule['condition']=='cost_schedule' and rule['target']==rule_targets(c)[0] and rule['allowed_targets']==rule_targets(c) and rule['financial_profile_contract']==c+'@1')
 return changed
