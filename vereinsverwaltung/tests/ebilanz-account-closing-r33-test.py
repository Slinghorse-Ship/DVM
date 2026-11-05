"""Actual PHP original journals: allowance pools and per-sphere calculative pairs."""
import copy,importlib.util,json,os,subprocess,unittest
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('closing_r33_shared',ROOT/'tests/ebilanz-operating-extended-test.py');shared=importlib.util.module_from_spec(s);s.loader.exec_module(shared)
shared.REVIEW='account-closing-r33';shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/account-closing-r33.json').read_text())['years']
MODES=('normal','opening','cancelled')

@lru_cache(maxsize=300)
def fixture(year,index,mode='normal',whole=False,dated=False,bad=''):
    p=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(ROOT/'tests/fixtures/ebilanz-account-closing-r33.php'),str(year),str(index),mode,'whole' if whole else 'single','dated' if dated else 'annual',bad],capture_output=True,encoding='utf8',timeout=60)
    if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
    return json.loads(p.stdout)

def selections(v,groups,target=None):
    out={}
    for g in groups:
        chosen=target or g['target']
        for s in g['sources']:
            if any(v['tax']['accounts']['code-'+s['code']][f] for f in ('opening_cents','debit_cents','credit_cents')):out['code-'+s['code']]=chosen
            if 'gross-'+s['code'] in v['tax']['accounts']:out['gross-'+s['code']]=chosen
    return out

def cases(dated=False):
    for e in shared.SPECS:
        for whole in (False,True):
            for mode in MODES:
                for i,g in enumerate(e['groups']):
                    v=fixture(e['year'],i,mode,whole,dated)
                    for t in g['choices']:yield {'year':e['year'],'whole':whole,'mode':mode,'index':i,'target':t,'dated':dated,'values':v,'mapping':selections(v,[g],t)}
                v=fixture(e['year'],-1,mode,whole,dated)
                yield {'year':e['year'],'whole':whole,'mode':mode,'index':-1,'target':'reviewed-mixed','dated':dated,'values':v,'mapping':selections(v,e['groups'])}

def inactive_cases():
    for e in shared.SPECS:
        for whole in (False,True):
            for dated in (False,True):
                for i in (0,4):
                    g=e['groups'][i];v=fixture(e['year'],i,'inactive',whole,dated)
                    yield {'year':e['year'],'whole':whole,'mode':'inactive','index':i,'target':g['target'],'dated':dated,'values':v,'mapping':selections(v,[g])}

class AccountClosingR33(unittest.TestCase):
    def test_inactive_original_chart_requires_no_zero_balance_mapping(self):
        for row in inactive_cases():
            v=row['values'];self.assertTrue(all(key not in row['mapping'] for key,a in v['tax']['accounts'].items() if a['skr_code'] in ('12460','69700','69800')))
            f=shared.facts(shared.build(v,row['mapping'],row['whole']),row['whole'])
            self.assertEqual(f['bs.ass'],shared.money(v['tax']['asset_total_cents']));self.assertEqual(f['is.netIncome'],'0.00')

    def test_all_years_choices_movements_and_net_disclosures(self):
        count=0
        for row in cases():
            v=row['values'];before=copy.deepcopy(v);f=shared.facts(shared.build(v,row['mapping'],row['whole']),row['whole'])
            self.assertEqual(f['bs.ass'],shared.money(v['tax']['asset_total_cents']));self.assertEqual(f['bs.eqLiab'],shared.money(v['tax']['passive_total_cents']));self.assertEqual(f['is.netIncome'],shared.money(v['tax']['annual_result_cents']))
            if row['index']!=-1:
                g=next(e for e in shared.SPECS if e['year']==row['year'])['groups'][row['index']]
                if g['contra_receivable_maturity']:
                    expected=sum(a['amount_cents'] for a in v['tax']['accounts'].values() if a['group']=='receivables')
                    self.assertEqual(f[row['target']],shared.money(expected));self.assertEqual(f[g['disclosures'][0]],shared.money(expected))
                else:
                    self.assertEqual(f[row['target']],'0.00');self.assertEqual(v['tax']['annual_result_cents'],0)
            self.assertEqual(v,before);count+=1
        self.assertEqual(count,240)
    def test_dated_original_journals_and_book_tax_opening(self):
        for year in (2025,2026):
            for whole in (False,True):
                for index in (0,4,-1):
                    v=fixture(year,index,'opening',whole,True);e=next(e for e in shared.SPECS if e['year']==year);gs=e['groups'] if index==-1 else [e['groups'][index]]
                    f=shared.facts(shared.build(v,selections(v,gs),whole),whole)
                    self.assertEqual(f['bs.ass'],shared.money(v['tax']['asset_total_cents']));self.assertEqual(v['book'],v['tax'])
    def test_original_netting_and_tax_rejections(self):
        for year in (2025,2026):
            for whole in (False,True):
                for bad in ('positive','too-high','wrong-maturity'):
                    with self.assertRaisesRegex(RuntimeError,'Wertberichtigung'):fixture(year,0,'normal',whole,False,bad)
                for bad in ('unpaired','tax-unpaired'):
                    with self.assertRaisesRegex(RuntimeError,'[Kk]alkulatorisch'):fixture(year,4,'normal',whole,False,bad)
                if whole:
                    with self.assertRaisesRegex(RuntimeError,'[Kk]alkulatorisch'):fixture(year,4,'normal',True,False,'cross-sphere')
                v=fixture(year,0,'normal',whole);g=next(e for e in shared.SPECS if e['year']==year)['groups'][0];m=selections(v,[g]);m['code-12460']=g['choices'][1]
                with self.assertRaisesRegex(ValueError,'Schuldnergruppe'):shared.build(v,m,whole)
                with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):shared.build(v,{},whole)
    def test_source_definitions_history_and_metadata_guards(self):
        model,_=shared.d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=shared.d.load_editions(model)
        for e in editions:
            old,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r32.json");new,_=shared.d.read_json(ROOT/f"resources/accounting/years/{e['year']}-r33.json")
            before=shared.d.validate_snapshot(old,model,*catalogs[e['year']]);after=shared.d.validate_snapshot(new,model,*catalogs[e['year']]);self.assertEqual(len(after)-len(before),20);self.assertEqual({k:after[k] for k in before},before)
        shared.OperatingExtended('test_sources_are_exact_and_all_choices_are_official_in_both_profiles').test_sources_are_exact_and_all_choices_are_official_in_both_profiles()
        from ebilanz_account_closing import validate_rule,is_allowance,receivables,calculative_journal
        for change in ({'group':'other_assets'},{'disclosures':[]},{'allowed_targets':['bs.ass.currAss.receiv.other.other']},{'contra_receivable_maturity':'guessed'}):
            rule=copy.deepcopy(model['purposes']['account-closing-r33-001']['versions']['1']['mappings']['6.9']);rule.update(change)
            with self.assertRaises(ValueError):validate_rule(rule)
        self.assertFalse(is_allowance(2025,{'skr_code':'12460','group':'cash'}));self.assertFalse(is_allowance(2024,{'skr_code':'12460','group':'receivables'}))
        for year in (2025,2026):
            v=fixture(year,0,'opening',True);bad=copy.deepcopy(v['book']['accounts']);bad['code-12460']['opening_cents']=-10001
            with self.assertRaises(ValueError):receivables(year,bad)
            v=fixture(year,4,'normal',True);entries=copy.deepcopy(v['whole_club_scope']['source']['entries']);entries[0]['lines'][-1]['sphere']='2'
            with self.assertRaisesRegex(ValueError,'Sphäre'):calculative_journal(year,v['book']['accounts'],entries)
if __name__=='__main__':unittest.main(verbosity=2)
