"""Original leasing decision is explicit; no economic ownership inferred from account62500."""
import re
def check(plan):
 from ebilanz_asset_types import MOVABLE
 from ebilanz_period import require
 c=plan.get('depreciation_code');p=plan.get('original_leasing')
 if c!='62500':require(p is None,'Originaler Kaufleasingnachweis gehört zum Gegenkonto62500.');return
 require(plan.get('method','linear_monthly')=='linear_monthly' and plan.get('skr_code',plan.get('account')) in MOVABLE and not {'gwg','degressive'}&plan.keys())
 require(isinstance(p,dict) and set(p)=={'schema','hgb_economic_owner','tax_economic_owner','ordinary_only','document'} and type(p['schema']) is int and p['schema']==1 and p['hgb_economic_owner']==p['tax_economic_owner']=='club' and p['ordinary_only'] is True,'Kaufleasing: ursprüngliche wirtschaftliche Zurechnung für HGB und Steuer getrennt bestätigen.')
 d=p['document'];require(isinstance(d,dict) and set(d)=={'document_id','revision','sha256'} and type(d['revision']) is int and d['revision']>=1 and isinstance(d['document_id'],str) and re.fullmatch('[a-f0-9]{32}',d['document_id']) and isinstance(d['sha256'],str) and re.fullmatch('[a-f0-9]{64}',d['sha256']))
