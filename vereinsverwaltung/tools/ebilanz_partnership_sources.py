"""Join independently replayed original HGB costs and assessed tax capital.

No journal, grid column or loss deduction is inferred. This source join alone
does not qualify a catalog binding or authorize export.
"""
from copy import deepcopy
from ebilanz_partnership_basis import calculate as capital,require,document,digest
from ebilanz_partnership_book import calculate as book,note

def reconcile(book_basis,capital_basis,relationship):
 require(type(book_basis) is dict and type(capital_basis) is dict)
 require(book(book_basis['source'],book_basis.get('previous'))==book_basis and capital(capital_basis['source'],capital_basis.get('previous'))==capital_basis)
 p=book_basis['source']['plan'];s=capital_basis['source']
 require(all(p[k]==s[k] for k in ('holding_id','club_id','account')) and book_basis['source']['period']==s['period'],'Unabhängige HGB- und Steuerquellen müssen dieselbe ursprüngliche Beteiligung desselben Vereins und denselben Zeitraum betreffen.')
 require(type(relationship) is dict and set(relationship)=={'kind','document','reason','confirmed'} and relationship['confirmed'] is True)
 document(relationship['document']);note(relationship['reason'])
 code=p['account'];allowed=('parent_company',) if code in ('08050','08090') else (('affiliated','parent_company') if code in ('08000','08030') else ('participation',))
 require(relationship['kind'] in allowed,'Ursprüngliches Beteiligungsverhältnis und bestätigtes Konto widersprechen sich.')
 affiliated=relationship['kind'] in ('affiliated','parent_company');prefix='bs.ass.fixAss.fin.'+('sharesInAffil' if affiliated else 'particip')
 atypical=s['classification']=='atypical_silent_partner'
 book_target=prefix+('.silent' if atypical else '.partnerships')
 tax_target=prefix+'.partnerships.commPart.'+('silentAtyp' if atypical else 'other')
 out={'schema':1,'holding_id':p['holding_id'],'club_id':p['club_id'],'account':code,'sphere':p['sphere'],'period':deepcopy(s['period']),
  'book_basis':deepcopy(book_basis),'capital_basis':deepcopy(capital_basis),'relationship':deepcopy(relationship),'book_target':book_target,'tax_target':tax_target,
  'parent_disclosures':[prefix+'.parentComp'] if relationship['kind']=='parent_company' else [],
  'opening_difference_cents':capital_basis['opening_cents']-book_basis['book']['book_opening_cents'],
  'closing_difference_cents':capital_basis['closing_cents']-book_basis['book']['book_closing_cents'],'export_authorized':False}
 return out|{'sha256':digest(out)}
