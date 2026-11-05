"""Generate XBRL from synthetic MySQL test values. No native SDK or transport."""
import json
from pathlib import Path
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'tools'))
from ebilanz_builder import EbilanzBuilder
from ebilanz_mapping import automatic_mapping
v=json.load(sys.stdin)
mapping=automatic_mapping(v)
for key,a in v['tax']['accounts'].items():
    if a['group']=='equity':mapping[key]='bs.eqLiab.equity.retainedEarnings'
    # This fixture has exactly one unpaid supplier invoice. Its derived payable
    # has no SKR code; this is an explicit fixture mapping, never a name guess in production.
    if key.startswith('src_pay_') and a['group']=='liabilities':mapping[key]='bs.eqLiab.liab.trade.genOther'
p={'kind':'single_taxable_business','scope_confirmed':True,'source_scope':'separate_business_ledger',
   'tax_privileged':True,'asset_scope':'single_reported_business','asset_values_unchanged':'asset_tax' not in v,
   'asset_tax_values_reviewed':'asset_tax' in v,'no_retained_fixed_assets':False,'tax_values_sha256':v['sha256'],
   'business_name':'Synthetischer Testbetrieb','supplier':'Synthetischer Test',
   'company':{'tax_number':'5192050001276','name':'Synthetischer Testverein','street':'Testweg','house_number':'1',
              'postal_code':'11111','city':'Teststadt','country':'Deutschland'}}
r=EbilanzBuilder().build(v,mapping,p,'12345')
print(json.dumps({k:r[k] for k in ['environment','transmission','asset_items','xml_sha256']}))
