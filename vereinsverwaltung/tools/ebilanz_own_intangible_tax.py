"""Independent original fiscal exclusion; HGB source never supplies assumed tax values."""
from copy import deepcopy
from datetime import timedelta
from ebilanz_own_intangible_book import calculate_book
from ebilanz_own_intangible_sources import require,document,note,day,digest

def assess(book_proof,source,previous=None,depth=0):
 require(type(depth) is int and 0<=depth<2)
 require(type(book_proof) is dict and set(book_proof)>={'source_proof','book_source','previous'} and calculate_book(book_proof['source_proof'],book_proof['book_source'],book_proof['previous'])==book_proof)
 require(type(source) is dict and set(source)=={'schema','club_id','item_id','period','book_proof_sha256','basis','opening_cents','closing_cents','document','reason','confirmed'} and type(source['schema']) is int and source['schema']==1 and source['confirmed'] is True)
 s=book_proof['source_proof']['source'];require(all(source[k]==s[k] for k in ('club_id','item_id','period')) and source['book_proof_sha256']==book_proof['sha256'] and source['basis']=='EStG_5_2_not_acquired')
 require(type(source['opening_cents']) is int and source['opening_cents']==0 and type(source['closing_cents']) is int and source['closing_cents']==0,'Ursprüngliche steuerliche Ansätze unabhängig bestätigen; unbekannte Werte bleiben unbekannt.')
 document(source['document']);note(source['reason'])
 if previous is None:require(book_proof['previous'] is None,'Ursprüngliche steuerliche Vorjahresfeststellung für den gespeicherten HGB-Vortrag fehlt.')
 else:
  require(type(previous) is dict and set(previous)=={'schema','book_proof','source','previous','original_opening_cents','original_closing_cents','assessment_verified','export_authorized','sha256'} and assess(previous['book_proof'],previous['source'],previous['previous'],depth+1)==previous and book_proof['previous']==previous['book_proof'])
  require(all(previous['source'][k]==source[k] for k in ('club_id','item_id')) and day(previous['source']['period']['end'])+timedelta(days=1)==day(source['period']['start']) and source['opening_cents']==previous['original_closing_cents'])
 out={'schema':1,'book_proof':deepcopy(book_proof),'source':deepcopy(source),'previous':deepcopy(previous),'original_opening_cents':source['opening_cents'],'original_closing_cents':source['closing_cents'],'assessment_verified':False,'export_authorized':False};return out|{'sha256':digest(out)}
