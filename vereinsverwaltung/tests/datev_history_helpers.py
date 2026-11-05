"""Original-source comparison for regression tests; never used to generate a filing."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def original_sources(model,edition):
 import datev_years as d
 from datev_bank_corrections import validate,MATURITY
 current=d.bindings(edition)
 if 'comparison_review' in edition:
  from datev_comparison import validate_revision
  before,_=d.read_json(ROOT/'resources/accounting/years/2025-r81.json')
  correction,_=d.read_json(ROOT/'resources/accounting/years/2025-r82.json')
  data,h=d.read_json(ROOT/'data/skr42-2025.json')
  previous=d.validate_snapshot(before,model,data,h);corrected=d.validate_snapshot(correction,model,data,h)
  validate_revision(previous,corrected,correction['provenance'],correction['comparison_review'],data)
  if '41850' in current or edition['comparison_review']!=correction['comparison_review']:raise ValueError('Originalvergleichskonto wurde erneut als aktuelles Konto freigegeben.')
  current=current|correction['provenance']['retired_bindings']

 if not any(current.get(n,'').startswith('bank-costs-r36-') for n in MATURITY):return current
 original,_=d.read_json(ROOT/f"resources/accounting/years/{edition['year']}-r35.json")
 correction,_=d.read_json(ROOT/f"resources/accounting/years/{edition['year']}-r36.json")
 data,h=d.read_json(ROOT/f"data/skr42-{edition['year']}.json")
 old=d.validate_snapshot(original,model,data,h);new=d.validate_snapshot(correction,model,data,h)
 if correction['source']!=original['source'] or correction['source']!=edition['source'] or correction['provenance']['previous_sha256']!=original['sha256']:raise ValueError('Originalquelle der Bankkorrektur prüfen.')
 changed=validate(model,old,new,d.catalog(data),correction['provenance'])
 if any(current[n]!=new[n] for n in changed):raise ValueError('Geprüfte Bankkorrektur wurde erneut verändert.')
 return current|{n:old[n] for n in changed}

def historical_snapshot(model,edition,data,raw_hash):
 """Test-only reconstruction for original semantics; never current filing generation."""
 import datev_years as d
 bound=original_sources(model,edition);groups={}
 for number,ref in sorted(bound.items()):groups.setdefault(ref,[]).append(number)
 result={k:v for k,v in edition.items() if k not in ('sha256','comparison_review')}
 result['bindings']=groups;result['definitions']={ref:d.digest(d.definition(model,ref)) for ref in groups}
 value=d.seal(result);d.validate_snapshot(value,model,data,raw_hash);return value
