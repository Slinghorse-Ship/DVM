"""Earned annual profit and approved original capital usage remain separate."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from functools import lru_cache
from pathlib import Path
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
def load(name):
 s=importlib.util.spec_from_file_location('use49_'+name,ROOT/'tests'/name);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
shared=load('ebilanz-operating-extended-test.py')
from ebilanz_builder import EbilanzBuilder
from ebilanz_income_use import CONTRACTS,check_values
from ebilanz_mapping import automatic_mapping
@lru_cache(maxsize=256)
def fixture(year,code,mode,whole):
 p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-income-use-r49.php'),str(year),code,mode,'whole' if whole else 'single'],capture_output=True,encoding='utf8',timeout=60)
 if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
 return json.loads(p.stdout)
def cases():
 for year in (2025,2026):
  for code,c in CONTRACTS.items():
   for choice in range(len(c['targets'])):
    for mode in (('normal','cancelled','tax') if code=='77490' else ('normal','cancelled')):
     for whole in (False,True):yield {'year':year,'code':code,'choice':choice,'mode':mode,'whole':whole,'values':fixture(year,code,mode,whole)}
def inputs(row):
 v=row['values'];c=CONTRACTS[row['code']];m={'use':c['targets'][row['choice']]}
 if 'counter' in v['tax']['accounts']:m['counter']=c['fixture_counter_target']
 return m,shared.profile(v,row['whole'])
def facts(package,whole):
 result=shared.facts(package,whole)
 result.update({n.tag.split('}',1)[-1]:n.text for n in ET.fromstring(package['xml']).iter() if n.tag.startswith('{'+shared.NS['de-gaap-ci']+'}incomeUse.') and n.text is not None})
 return result
class IncomeUse(unittest.TestCase):
 def test_all_original_source_choices_profit_and_reversal(self):
  seen=set()
  for row in cases():
   with self.subTest(**{k:row[k] for k in ('year','code','choice','mode','whole')}):
    v=row['values'];before=copy.deepcopy(v);m,p=inputs(row)
    if not row['whole'] and row['mode']=='normal' and m['use'].startswith('incomeUse.gainLoss.'):
     with self.assertRaises(ValueError):EbilanzBuilder().build(v,m,p,'12345')
     seen.add(row['code']);continue
    package=EbilanzBuilder().build(v,m,p,'12345');f=facts(package,row['whole']);self.assertEqual(v,before);self.assertEqual(v['tax']['annual_result_cents'],40000);self.assertEqual(f['is.netIncome'],shared.money(40000))
    use=check_values(v,m);self.assertEqual(use['active'],row['mode']!='cancelled');target=m['use'];seen.add(row['code'])
    if use['active']:
     self.assertEqual(f[target],shared.money(use['facts'][target]));self.assertEqual(f['bs.ass'],f['bs.eqLiab'])
     if target.startswith('incomeUse.gainLoss.'):
      self.assertEqual(f['incomeUse.gainLoss'],shared.money(40000+use['balance_effect']));self.assertEqual(f['bs.eqLiab.equity.profitLoss'],f['incomeUse.gainLoss'])
     else:self.assertEqual(f['incomeUse.resultFoundations'],shared.money(40000+use['balance_effect']))
    if row['mode']=='tax':
     self.assertEqual(check_values(v,m,book_only=True)['balance_effect'],1234);self.assertEqual(use['balance_effect'],2468)
    auto=automatic_mapping(v);self.assertNotIn('use',auto)
  self.assertEqual(seen,set(CONTRACTS))
 def test_original_closed_source_and_exact_counterpart_required(self):
  row=next(r for r in cases() if r['whole']);v=row['values'];m,p=inputs(row)
  for mutate in ('missing_source','changed_year','changed_book','wrong_counter','wrong_use_choice'):
   bad=copy.deepcopy(v);mapping=dict(m)
   if mutate=='missing_source':del bad['income_use_source']
   elif mutate=='changed_year':bad['income_use_source']['source']['year']=2026
   elif mutate=='changed_book':bad['book']['accounts']['use']['closing_cents']+=1
   elif mutate=='wrong_counter':mapping['counter']='bs.eqLiab.equity.capRes'
   else:mapping['use']='incomeUse.gainLoss.releaseCapital'
   with self.subTest(mutate=mutate),self.assertRaises(ValueError):EbilanzBuilder().build(bad,mapping,p,'12345')
 def test_original_catalog_additions_and_changed_future_network(self):
  model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
  for e in editions:
   old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r48.json");a=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);sealed,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r49.json");b=shared.d.validate_snapshot(sealed,model,*catalogs[e['year']]);self.assertEqual({c:shared.d.bindings(e)[c] for c in b},b);self.assertEqual({c:b[c] for c in a},a);self.assertEqual(set(b)-set(a),set(CONTRACTS));self.assertNotIn('77690',set(b)-set(a))
  review,_=shared.d.read_json(ROOT/'resources/accounting/reviews/taxonomy-6.10.json');new={ref for ref in review['blocked'] if ref.startswith('income-use-r49-')};self.assertEqual(len(new),14)
if __name__=='__main__':unittest.main(verbosity=2)
