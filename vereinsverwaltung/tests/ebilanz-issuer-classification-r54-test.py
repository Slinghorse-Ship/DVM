"""Reviewed correction rejects unrelated remapping and unproved foreign branch classification."""
import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import datev_years as d
from datev_issuer_corrections import validate,CODES,CHANGES
from ebilanz_financial_profile import profiles,target,rule_targets
class IssuerClassification(unittest.TestCase):
 def test_exact_source_correction_and_tampering_rejection(self):
  model,_=d.read_json(ROOT/'resources/accounting/purposes.json')
  for y in (2025,2026):
   old,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r53.json');new,_=d.read_json(ROOT/f'resources/accounting/years/{y}-r54.json');data,h=d.read_json(ROOT/f'data/skr42-{y}.json');before=d.validate_snapshot(old,model,data,h);after=d.validate_snapshot(new,model,data,h);rows=d.catalog(data);proof=new['provenance']
   self.assertEqual(validate(model,before,after,rows,proof),CHANGES);self.assertEqual({c:after[c] for c in before if c not in CHANGES},{c:before[c] for c in before if c not in CHANGES})
   for field in ('previous_purpose','new_purpose','source_signature','previous_definition_sha256','new_definition_sha256'):
    changed=copy.deepcopy(proof);changed['corrections']['09000'][field]='forged'
    with self.subTest(year=y,field=field),self.assertRaises(ValueError):validate(model,before,after,rows,changed)
   changed=dict(after);changed['18000']=after['09000']
   with self.assertRaises(ValueError):validate(model,before,changed,rows,proof)
   changed=copy.deepcopy(proof);changed['production_authorized']=True
   with self.assertRaises(ValueError):validate(model,before,after,rows,changed)
   changed=copy.deepcopy(proof);del changed['corrections']['09000']
   with self.assertRaises(ValueError):validate(model,before,after,rows,changed)
 def test_issuer_country_preserves_actual_balance_type(self):
  for code in CODES:
   for profile in profiles(code):
    domestic=profile.removesuffix('_ab')+'_de' if profile.endswith('_ab') else 'corp_domestic' if profile=='corp_foreign' else profile
    self.assertEqual(target(code,profile),target(code,domestic));self.assertFalse(target(code,profile).endswith('OtherForeign'))
   self.assertFalse(any(t.endswith('OtherForeign') for t in rule_targets(code)))
if __name__=='__main__':unittest.main(verbosity=2)
