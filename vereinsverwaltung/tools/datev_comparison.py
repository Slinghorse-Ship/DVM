"""Original2025 footnote11: retain history, reject current posting meanings."""
import hashlib,json
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
PATH='resources/accounting/reviews/comparison-only-2025-r82.json'
SHA256='b41d22e8864060d72724c25d23b556c6d36724ddd62c149e164b9df190279fe4'
CODES=('41850','74600','74640','75600','75610','75630')
def review(data):
 if data.get('year')!=2025 or data.get('sha256')!='dcf8080446f4fc0ddb1d67409fdabc3689dab598ac669d2330322f895402e69d' or data.get('source')!='https://help-center.apps.datev.de/api/amr/knowledge-common/v1/entities/st45264272267_de.pdf':return None
 raw=(ROOT/PATH).read_bytes().replace(b'\r\n',b'\n');e=json.loads(raw)
 if hashlib.sha256(raw).hexdigest()!=SHA256 or e['codes']!=list(CODES) or e['pdf_sha256']!=data['sha256'] or e['catalog_sha256']!=hashlib.sha256((ROOT/'data/skr42-2025.json').read_bytes().replace(b'\r\n',b'\n')).hexdigest() or e['source']!=data['source']:
  raise ValueError('Originalnachweis der Vorjahresvergleichskonten wurde geändert.')
 rows={a['code']:a for a in data['accounts']}
 if sorted(c for c,a in rows.items() if '11)' in a['name'])!=list(CODES):raise ValueError('Vergleichskonten und Originalfußnote stimmen nicht überein.')
 return dict(file=PATH,sha256=SHA256)
def apply(data,rows):
 if review(data) is not None:
  for c in CODES:rows[c]=rows[c]|dict(bookable=False,comparison_only=True)
 return rows
def validate_revision(previous,current,proof,marker,data):
 expected=review(data)
 if expected is None or marker!=expected or proof.get('kind')!='reviewed_comparison_only_correction' or proof.get('production_authorized') is not False or proof.get('comparison_review')!=expected or proof.get('retired_bindings')!={'41850':previous.get('41850')} or '41850' not in previous or current!={c:r for c,r in previous.items() if c!='41850'}:
  raise ValueError('Vorjahresvergleichskorrektur muss exakt das Originalkonto41850 aus aktuellen Zuordnungen entfernen und alle anderen Zwecke erhalten.')
