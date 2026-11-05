"""Original fiscal holding matrix, never inferred from net capital or HGB cost.

This source verification alone does not authorize an export or catalog binding.
"""
from copy import deepcopy
from ebilanz_partnership_basis import require,document,digest
from ebilanz_partnership_book import note
from ebilanz_partnership_journals import verify
from taxonomy_sources import source as taxonomy_source

def reconcile(journal_proof,source,previous=None,depth=0):
 require(type(depth) is int and 0<=depth<=100)
 require(type(journal_proof) is dict and verify(journal_proof['sources'],journal_proof['journal'],journal_proof.get('previous'))==journal_proof)
 require(type(source) is dict and set(source)=={'schema','club_id','holding_id','period','taxonomy','reporting','columns','document','reason','legal_basis','confirmed'})
 require(type(source['schema']) is int and source['schema']==1 and source['confirmed'] is True and source['taxonomy']=='6.9' and source['reporting']=='original_fiscal_gross_matrix')
 j=journal_proof['journal'];s=journal_proof['sources'];capital=s['capital_basis']
 require(all(source[k]==j[k] for k in ('club_id','holding_id','period')))
 document(source['document']);note(source['reason']);note(source['legal_basis'])
 _,meta=taxonomy_source('6.9');network=meta['asset_calculations'];keys=set(network)|{c for children in network.values() for c in children}|{'all_Prev_period'}
 columns=source['columns']
 require(type(columns) is dict and set(columns)==keys,'Steuerlichen Original-Anlagenspiegel einschließlich unbekannter Spalten vollständig nachweisen; keine Bruttowerte aus dem Nettowert ableiten.')
 for k,n in columns.items():require(n is None or type(n) is int and abs(n)<=100000000000,'Ursprüngliche Spiegelspalte muss Centwert oder ausdrücklich unbekannt sein; Null ist kein Ersatz für unbekannt.')
 require(columns['all']==capital['closing_cents'] and columns['all_Prev_period']==capital['opening_cents'],'Steuerlicher Original-Anlagenspiegel und festgestellter Spiegelbildwert stimmen nicht überein.')
 for parent,children in network.items():
  known=[(columns[c],weight) for c,weight in children.items() if columns[c] is not None]
  if known:require(type(columns[parent]) is int and columns[parent]==sum(n*w for n,w in known),'Ursprüngliche steuerliche Bewegungsgleichung nicht erfüllt: '+parent)
  else:require(columns[parent] is None,'Werthaltige Summenmussfeldspalte benötigt einen ursprünglichen Unterwert: '+parent)
 # An original tax mirror matrix may contain signed columns. No generic asset
 # cap, zero floor, automatic liability or inferred ordinary write-up applies.
 carry='initial_original_matrix' if capital.get('previous') is None else 'unbound_previous_matrix'
 if previous is not None:
  require(type(previous) is dict and reconcile(previous['journal_proof'],previous['source'],previous.get('previous'),depth+1)==previous)
  require(journal_proof.get('previous')==previous['journal_proof'],'Vorherigen steuerlichen Original-Anlagenspiegel mit dem vollständigen ursprünglichen Vorjahresjournal verbinden.')
  require(columns['all_Prev_period']==previous['source']['columns']['all'])
  carry='verified_original_matrix_journal'
 out={'schema':1,'journal_proof':deepcopy(journal_proof),'source':deepcopy(source),'previous':deepcopy(previous),'carry':carry,'target':s['tax_target'],'columns':deepcopy(columns),'export_authorized':False}
 return out|{'sha256':digest(out)}
