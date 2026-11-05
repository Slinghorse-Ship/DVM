"""Source-pinned correction of original bank sum positions; immutable prior meanings."""
ORDINARY='bs.eqLiab.liab.bank.other'
MATURITY={'31500':None,'31510':'upTo1year','31520':'above1year','31530':'above1year','31800':None,'31810':'upTo1year','31820':'above1year','31830':'above1year','32100':None}
def require(ok):
 if not ok:raise ValueError('Quellengebundene Bank-Unterpositionskorrektur und ursprüngliche Jahreszwecke stimmen nicht überein.')
def validate(model,previous,current,rows,proof):
 import datev_years as d
 require(proof.get('kind')=='reviewed_bank_subposition_correction' and proof.get('production_authorized') is False and proof.get('evidence')=='resources/accounting/reviews/bank-costs-r36.json')
 changes={n for n,ref in previous.items() if current.get(n)!=ref};evidence=proof.get('corrections')
 require(changes==set(MATURITY) and type(evidence) is dict and set(evidence)==changes and set(previous)<=set(current))
 for number in sorted(changes):
  item=evidence[number];old_ref=previous[number];new_ref=current[number]
  require(type(item) is dict and set(item)=={'previous_purpose','new_purpose','source_signature','previous_definition_sha256','new_definition_sha256'} and item['previous_purpose']==old_ref and item['new_purpose']==new_ref and rows[number]['bookable'] and item['source_signature']==rows[number]['signature'])
  require(item['previous_definition_sha256']==d.digest(d.definition(model,old_ref)) and item['new_definition_sha256']==d.digest(d.definition(model,new_ref)))
  old=d.mapping(model,old_ref,'6.9');new=d.mapping(model,new_ref,'6.9')
  require(old['group']==new['group']=='liabilities' and old['target']=='bs.eqLiab.liab.bank' and 'condition' not in old and 'directional_balance' not in old and 'disclosures' not in old)
  require(new['target']==ORDINARY and new.get('condition')=='detail_choice' and new.get('allowed_targets')==[ORDINARY] and new.get('disclosures',[])==(['bs.eqLiab.liab.bank.'+MATURITY[number]] if MATURITY[number] else []) and 'directional_balance' not in new and 'negative_target' not in new)
 return changes
