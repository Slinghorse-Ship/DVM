"""Exact source-bound correction: issuer country is not a foreign branch fallback."""
CODES={'08040','08080','08500','09800','09000','09100'}
COUNTERS={'49120': 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.investmentShares', '68970': 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.investmentShares', '72000': 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.investementShares', '72010': 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.investementShares'}
CHANGES=CODES|set(COUNTERS)
def require(ok):
 if not ok:raise ValueError('Quellengebundene Korrektur der Emittentenart vollständig prüfen.')
def validate(model,previous,current,rows,proof):
 import datev_years as d
 from ebilanz_financial_profile import rule_targets,validate_rule
 require(proof.get('kind')=='reviewed_issuer_classification_correction' and proof.get('production_authorized') is False and proof.get('evidence')=='resources/accounting/reviews/foreign-classification-r54.json')
 require(set(previous)==set(current));changed={c for c in previous if previous[c]!=current[c]};evidence=proof.get('corrections')
 require(changed==CHANGES and type(evidence) is dict and set(evidence)==CHANGES)
 for c in sorted(CHANGES):
  item=evidence[c];old_ref=previous[c];new_ref=current[c]
  require(type(item) is dict and set(item)=={'previous_purpose','new_purpose','source_signature','previous_definition_sha256','new_definition_sha256'})
  require(old_ref.endswith('@1') and new_ref==old_ref[:-1]+'2' and item['previous_purpose']==old_ref and item['new_purpose']==new_ref and rows[c]['bookable'] and item['source_signature']==rows[c]['signature'])
  require(item['previous_definition_sha256']==d.digest(d.definition(model,old_ref)) and item['new_definition_sha256']==d.digest(d.definition(model,new_ref)))
  old=d.mapping(model,old_ref,'6.9');new=d.mapping(model,new_ref,'6.9');validate_rule(old);validate_rule(new)
  if c in COUNTERS:
   require(old['condition']==new['condition']=='cost_schedule' and old['group']==new['group']==('revenue' if c=='49120' else 'expense') and new['target']==old['target'] and new.get('allowed_targets')==[old['target'],COUNTERS[c]] and 'allowed_targets' not in old and 'disclosures' not in new and set(new)==set(old)|{'allowed_targets'})
  else:
   require(old['group']==new['group']=='fixed_assets' and old['condition']==new['condition']=='cost_schedule' and old['financial_profile_contract']==c+'@1' and new['financial_profile_contract']==c+'@2' and new['allowed_targets']==rule_targets(c) and new['target']==rule_targets(c)[0])
   require(new.get('financial_parent_disclosures',[])==old.get('financial_parent_disclosures',[]) and 'directional_balance' not in new and 'negative_target' not in new)
   require(not any(t.endswith(('securitiesOtherForeign','participOtherForeign','sharesInAffilOtherForeign')) for t in new['allowed_targets']))
 return changed
