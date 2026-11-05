"""Offline annual maintenance regression tests, no database or transmission."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))
import datev_years as d


def load_tool(name):
    spec=importlib.util.spec_from_file_location(name,d.ROOT/'tools'/f'{name}.py')
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module


import sys as history_sys
history_sys.path.insert(0,str(Path(__file__).resolve().parent))
from datev_history_helpers import original_sources,historical_snapshot

class AnnualTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.model,_=d.read_json(d.ROOT/'resources/accounting/purposes.json')
        cls.registry,_=d.read_json(d.ROOT/'resources/ebilanz/skr42-6.9.json')
        cls.catalogs={y:d.read_json(d.ROOT/f'data/skr42-{y}.json') for y in (2025,2026)}
        cls.editions={y:d.read_json(d.ROOT/f'resources/accounting/years/{y}.json')[0] for y in (2025,2026)}
        cls.cash=d.bindings(cls.editions[2025])['18000']
        cls.inventory=d.bindings(cls.editions[2025])['10000']
        cls.cli=load_tool('maintain-datev-skr42')

    def fixture(self,numbers=('18000',),start=None,end=None,year=2025):
        ranges=[{'code':start or n,'end':end or n,'name':'Bankguthaben','function':'','page':1} for n in numbers]
        accounts=[{'code':n,'range_start':r['code'],'range_end':r['end'],'name':r['name'],'function':'','page':1} for n,r in zip(numbers,ranges)]
        return {'chart':'SKR42','edition':'balance','year':year,'source':'https://www.datev.de/example.pdf',
            'sha256':'a'*64,'ranges':ranges,'accounts':accounts}

    def pair(self,old=None,bound=None):
        old=old or self.fixture();bound=bound if bound is not None else {a['code']:self.cash for a in old['accounts']}
        base=d.snapshot(self.model,old,d.digest(old),'6.9',bound,{'kind':'synthetic_test'})
        new=copy.deepcopy(old);new['year']+=1;new['sha256']='b'*64
        return old,base,new

    def plan(self,old,base,new,model=None,taxonomy='6.9'):
        return d.compare(model or self.model,base,old,d.digest(old),new,d.digest(new),taxonomy)

    def review(self,plan):
        result=d.review_template(plan)
        result.update(reviewer='Synthetic reviewer',note='Isolated automated test',source_and_notes_reviewed=True,taxonomy_reviewed=True)
        for decision in result['decisions'].values():decision['reason']='Manual review still required'
        return result

    def approve(self,old,base,new,plan,review,model=None):
        return d.approve(model or self.model,base,old,d.digest(old),new,d.digest(new),plan,review)

    def test_all_current_rules_reproduce_without_semantic_changes(self):
        selected,catalogs=d.load_editions(self.model)
        compiled=d.compile_registry(self.model,selected,catalogs,self.registry['revision'])
        self.assertTrue(d.runtime_equivalent(self.registry,compiled))
        # The sealed original base remains identical, despite additive releases.
        original=d.compile_registry(self.model,list(self.editions.values()),self.catalogs,13)
        original_codes={y:set(d.bindings(self.editions[y])) for y in self.editions}
        # Reconstruct prior source meanings only after validating every pinned
        # bank correction, its source signatures and immutable definitions.
        historic=[]
        for e in selected:
            data,h=catalogs[e['year']]
            historic.append(historical_snapshot(self.model,e,data,h))
        filtered=copy.deepcopy(d.compile_registry(self.model,historic,catalogs,self.registry['revision']))
        filtered['rules']=[rule|{'codes':[n for n in rule['codes'] if n in original_codes[y]],'years':[y]}
            for y in self.editions for rule in filtered['rules'] if y in rule.get('years',[2025,2026])]
        self.assertTrue(d.runtime_equivalent(original,filtered))
        for y,missing in [(2025,1167),(2026,1157)]:
            report=d.coverage(self.model,self.editions[y],self.catalogs[y][0])
            self.assertEqual((report['assigned'],report['conditional'],len(report['missing'])),(522,28,missing))
            self.assertFalse(report['complete'])

    def test_real_year_comparison_no_guessing(self):
        plan=d.compare(self.model,self.editions[2025],*self.catalogs[2025],*self.catalogs[2026],'6.9')
        self.assertEqual(plan['counts']['newly_bookable'],8)
        self.assertEqual(plan['counts']['no_longer_bookable'],18)
        self.assertEqual(sum(plan['counts'][key] for key in ('unchanged','changed','newly_bookable')),1679)
        self.assertFalse(plan['production_authorized'])
        self.assertGreater(len(plan['automatic']),490)
        self.assertGreater(len(plan['existing_gaps']),1000)
        self.assertNotIn('38430',plan['automatic']) # changed VAT account source text
        before=d.digest(self.editions)
        approved=d.approve(self.model,self.editions[2025],*self.catalogs[2025],*self.catalogs[2026],plan,self.review(plan))
        self.assertEqual(d.digest(self.editions),before)
        self.assertEqual(len(d.bindings(approved)),len(plan['automatic']))
        self.assertFalse(d.coverage(self.model,approved,self.catalogs[2026][0])['complete'])

    def test_page_movement_and_whitespace_are_not_semantic_changes(self):
        old,base,new=self.pair()
        new['accounts'][0].update(page=55,name=' Bankguthaben\n ')
        new['ranges'][0].update(page=55,name='Bankguthaben  ')
        plan=self.plan(old,base,new)
        self.assertEqual(plan['counts']['unchanged'],1)
        self.assertEqual(plan['automatic']['18000']['purpose'],self.cash)

    def test_pdf_typography_does_not_need_an_accountant_but_tax_changes_do(self):
        old,base,new=self.pair()
        old['accounts'][0]['name']=old['ranges'][0]['name']='Bank-Fi\ufb01nanzierung'
        base=d.snapshot(self.model,old,d.digest(old),'6.9',{'18000':self.cash},{'kind':'synthetic_test'})
        new['accounts'][0]['name']=new['ranges'][0]['name']='BANK\u2011Fi\u00adfinanzierung'
        self.assertIn('18000',self.plan(old,base,new)['automatic'])
        for a,b in [('mit Vorsteuer','ohne Vorsteuer'),('7 % Vorsteuer','19 % Vorsteuer'),('§ 4 Nr. 1a','§ 4 Nr. 1b'),('steuerbar','nicht steuerbar'),('Forderung','Verbindlichkeit'),('9,0 %','7,8 %')]:
            self.assertNotEqual(d.semantic_text(a),d.semantic_text(b))

    def test_selected_revisions_preserve_source_and_all_old_assignments(self):
        selected,_=d.load_editions(self.model)
        for edition in selected:
            base=self.editions[edition['year']]
            self.assertEqual(base['source'],edition['source'])
            self.assertTrue(d.bindings(base).items()<=original_sources(self.model,edition).items())
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            for y,edition in self.editions.items():d.write_new(root/f'{y}.json',edition)
            malicious={'schema':1,'years':{'2025':[{'file':'../purposes.json','sha256':'a'*64}]}}
            d.write_new(root/'active.json',malicious)
            with self.assertRaisesRegex(ValueError,'Revisionsverweis'):d.load_editions(self.model,root)

    def test_changed_label_function_or_range_requires_review(self):
        for key,value in [('name','Anderes Guthaben'),('function','S'),('range_end','18090')]:
            with self.subTest(key=key):
                old,base,new=self.pair();new['accounts'][0][key]=value
                plan=self.plan(old,base,new)
                self.assertEqual(plan['automatic'],{})
                self.assertEqual(plan['counts']['changed'],1)

    def test_revision_chain_cannot_rewrite_history_or_auto_activate_a_file(self):
        selected,_=d.load_editions(self.model)
        newest={e['year']:e for e in selected}
        for change in ('unselected','source','previous','changed_binding','removed_binding','hash','empty','array','bad_years'):
            with self.subTest(change=change),tempfile.TemporaryDirectory() as temp:
                directory=Path(temp)
                for y,edition in self.editions.items():d.write_new(directory/f'{y}.json',edition)
                revision=copy.deepcopy(newest[2025]);name='2025-r14.json'
                if change=='source':revision['source']['url']='https://www.datev.de/changed.pdf'
                if change=='previous':revision['provenance']['previous_sha256']='a'*64
                if change in ('changed_binding','removed_binding'):
                    revision['bindings'][self.cash].remove('18000')
                    if change=='changed_binding':revision['bindings'][self.inventory].append('18000')
                revision=d.seal({k:v for k,v in revision.items() if k!='sha256'})
                d.write_new(directory/name,revision)
                if change=='unselected':
                    editions,_=d.load_editions(self.model,directory)
                    self.assertEqual([d.bindings(e) for e in editions],[d.bindings(e) for e in self.editions.values()])
                    continue
                selection={'schema':1,'years':{'2025':[{'file':name,'sha256':'b'*64 if change=='hash' else revision['sha256']}]}}
                if change=='empty':selection={}
                if change=='array':selection=[]
                if change=='bad_years':selection['years']=[]
                d.write_new(directory/'active.json',selection)
                with self.assertRaises(ValueError):d.load_editions(self.model,directory)

    def test_changed_parent_and_missing_parent_require_review(self):
        old=self.fixture(numbers=('18000','18010'))
        old['ranges'][1]['name']=old['accounts'][1]['name']='- Restlaufzeit bis 1 Jahr'
        old,base,new=self.pair(old)
        new['ranges'][0]['name']=new['accounts'][0]['name']='Neuer Hauptbegriff'
        self.assertEqual(len(self.plan(old,base,new)['review_groups']),2)
        old= self.fixture();old['ranges'][0]['name']=old['accounts'][0]['name']='- Restlaufzeit bis 1 Jahr'
        old,base,new=self.pair(old)
        self.assertEqual(self.plan(old,base,new)['automatic'],{})

    def test_changed_family_is_one_review_not_one_per_account(self):
        old=self.fixture(('18000','18010','18020'),start='18000',end='18090')
        old,base,new=self.pair(old)
        for row in new['accounts']:row['name']='Geänderter Zweck'
        plan=self.plan(old,base,new)
        self.assertEqual(len(plan['review_groups']),1)
        self.assertEqual(plan['review_groups'][0]['codes'],['18000','18010','18020'])

    def test_new_number_only_inherits_an_exact_fully_reviewed_family(self):
        old=self.fixture(('18000',),start='18000',end='18090')
        old,base,new=self.pair(old)
        new['accounts'].append(new['accounts'][0]|{'code':'18010'})
        plan=self.plan(old,base,new)
        self.assertEqual(plan['automatic']['18010']['reason'],'same_reviewed_source_range')
        # Identical labels alone do not justify a different source range.
        new['accounts'][1].update(range_start='18010',range_end='18010')
        self.assertNotIn('18010',self.plan(old,base,new)['automatic'])
        # Incomplete or conflicting previous classification never propagates.
        old=self.fixture(('18000','18010'),start='18000',end='18090')
        for bound in ({'18000':self.cash},{'18000':self.cash,'18010':d.bindings(self.editions[2025])['16000']}):
            old,base,new=self.pair(old,bound);new['accounts'].append(new['accounts'][0]|{'code':'18020'})
            self.assertNotIn('18020',self.plan(old,base,new)['automatic'])

    def test_retired_reserved_and_ambiguous_rows_never_auto_bind(self):
        for kind in ('removed','reserved','alternatives','range_issue'):
            with self.subTest(kind=kind):
                old,base,new=self.pair()
                if kind=='removed':new['accounts']=[]
                elif kind=='reserved':new['ranges'][0]['function']='R'
                else:new['accounts'][0][kind]=[] if kind=='alternatives' else 'bad'
                plan=self.plan(old,base,new)
                self.assertEqual(plan['counts']['no_longer_bookable'],1)
                approved=self.approve(old,base,new,plan,self.review(plan))
                self.assertEqual(d.bindings(approved),{})
                self.assertEqual(d.bindings(base),{'18000':self.cash})

    def test_unknown_legacy_variant_year_and_duplicate_are_rejected(self):
        for change in ('edition','chart','no_edition','year','duplicate','range'):
            with self.subTest(change=change):
                old,base,new=self.pair()
                if change=='edition':new['edition']='euer'
                if change=='chart':new['chart']='SKR41'
                if change=='no_edition':del new['edition']
                if change=='year':new['year']=2028
                if change=='duplicate':new['accounts'].append(new['accounts'][0])
                if change=='range':new['accounts'][0]['range_end']='17990'
                with self.assertRaises(ValueError):self.plan(old,base,new)

    def test_new_purpose_revision_requires_review_and_keeps_history(self):
        old,base,new=self.pair();model=copy.deepcopy(self.model)
        purpose,version=self.cash.split('@')
        new_definition=copy.deepcopy(d.definition(model,self.cash))
        new_definition['label']='Geprüfte neue Definition'
        new_definition['mappings']['6.9']['label']=new_definition['label']
        model['purposes'][purpose]['versions']['2']=new_definition
        plan=self.plan(old,base,new,model)
        self.assertEqual(plan['automatic'],{})
        self.assertEqual(plan['review_groups'][0]['review_reason'],'purpose_revision')
        review=self.review(plan)
        for row in review['decisions'].values():row.update(action='assign',purpose=purpose+'@2')
        approved=self.approve(old,base,new,plan,review,model)
        self.assertEqual(d.bindings(approved)['18000'],purpose+'@2')
        self.assertEqual(d.validate_snapshot(base,model,old,d.digest(old))['18000'],self.cash)
        model['purposes'][purpose]['versions'][version]['label']='Overwritten history'
        with self.assertRaises(ValueError):d.validate_snapshot(base,model,old,d.digest(old))

    def test_future_year_or_taxonomy_cannot_unlock_filing(self):
        for year,taxonomy in [(2026,'6.9'),(2025,'7.0')]:
            old,base,new=self.pair(self.fixture(year=year))
            plan=self.plan(old,base,new,taxonomy=taxonomy)
            self.assertFalse(plan['taxonomy_ready']);self.assertEqual(plan['automatic'],{})
            with self.assertRaises(ValueError):self.approve(old,base,new,plan,self.review(plan))
            self.assertIn(' disabled',d.html_report(plan,self.model))

    def test_changed_sources_plans_reviews_and_missing_acknowledgement_block(self):
        for change in ('new','old','plan','review_hash','reviewer','note','source','taxonomy','missing_decision','reason'):
            with self.subTest(change=change):
                old,base,new=self.pair();new['accounts'][0]['name']='Changed'
                plan=self.plan(old,base,new);review=self.review(plan)
                if change=='new':new['accounts'][0]['name']='Later edit'
                if change=='old':old['source']='https://www.datev.de/other.pdf'
                if change=='plan':plan['automatic']['18000']={'purpose':self.cash,'reason':'forged'}
                if change=='review_hash':review['plan_sha256']='c'*64
                if change in ('reviewer','note'):review[change]=''
                if change=='source':review['source_and_notes_reviewed']=False
                if change=='taxonomy':review['taxonomy_reviewed']=False
                if change=='missing_decision':review['decisions']={}
                if change=='reason':next(iter(review['decisions'].values()))['reason']=''
                with self.assertRaises(ValueError):self.approve(old,base,new,plan,review)

    def test_assignments_need_known_purpose_valid_target_and_account_class(self):
        old,base,new=self.pair();new['accounts'][0]['name']='Changed'
        for ref in ('unknown@1',d.bindings(self.editions[2025])['01350']):
            plan=self.plan(old,base,new);review=self.review(plan)
            next(iter(review['decisions'].values())).update(action='assign',purpose=ref)
            with self.assertRaises(ValueError):self.approve(old,base,new,plan,review)
        model=copy.deepcopy(self.model)
        d.definition(model,self.cash)['mappings']['6.9']['target']='invalid.target'
        with self.assertRaises(ValueError):d.validate_target(model,self.cash,'6.9',2026)

    def test_existing_gaps_can_be_reviewed_but_are_never_auto_completed(self):
        old,base,new=self.pair(bound={});plan=self.plan(old,base,new)
        self.assertEqual(len(plan['existing_gaps']),1)
        review=self.review(plan)
        approved=self.approve(old,base,new,plan,review)
        self.assertIn('18000',approved['provenance']['unresolved'])
        review['gap_decisions']={'18000':{'purpose':self.cash,'reason':'Explicit base classification'}}
        approved=self.approve(old,base,new,plan,review)
        self.assertEqual(d.bindings(approved),{'18000':self.cash})
        review['gap_decisions']['99999']=review['gap_decisions']['18000']
        with self.assertRaises(ValueError):self.approve(old,base,new,plan,review)

    def test_successors_are_metadata_not_a_historical_rebooking(self):
        old,base,new=self.pair(self.fixture(('18000','18100')))
        new['accounts']=new['accounts'][1:];plan=self.plan(old,base,new);review=self.review(plan)
        review['successors']={'18000':{'code':'18100','reason':'Explicit successor'}}
        approved=self.approve(old,base,new,plan,review)
        self.assertEqual(d.bindings(base),{'18000':self.cash,'18100':self.cash})
        self.assertEqual(d.bindings(approved),{'18100':self.cash})
        review['successors']['18000']['code']='19999'
        with self.assertRaises(ValueError):self.approve(old,base,new,plan,review)

    def test_artifacts_cannot_replace_active_or_existing_files(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'stand.json';d.write_new(path,{'first':True})
            with self.assertRaises(FileExistsError):d.write_new(path,{'first':False})
            self.assertEqual(d.read_json(path)[0],{'first':True})
            self.cli.package(Path(directory)/'new',{'a.json':{'first':True}})
            with self.assertRaises(FileExistsError):self.cli.package(Path(directory)/'new',{})
        for path in ('data','resources','public','views','src'):
            with self.assertRaises(ValueError):self.cli.package(d.ROOT/path/'not-created-by-test',{})

    def test_html_escapes_source_and_uses_explicit_local_review(self):
        old,base,new=self.pair();new['accounts'][0]['name']='</script><img src=x onerror=alert(1)>'
        plan=self.plan(old,base,new);page=d.html_report(plan,self.model)
        self.assertNotIn('<img src=x',page)
        self.assertIn('&lt;img',page)
        self.assertIn('Funktion:',page)
        self.assertIn('Prüfdatei speichern',page)
        self.assertNotIn('fetch(',page)

    def test_cli_compare_approve_and_verify_keep_repository_unchanged(self):
        watched=[d.ROOT/f'data/skr42-{y}.json' for y in (2025,2026)]+[d.ROOT/'resources/ebilanz/skr42-6.9.json']
        before={str(p):p.read_bytes() for p in watched}
        def run(*args,ok=True):
            result=subprocess.run([sys.executable,'-X','utf8',str(d.ROOT/'tools/maintain-datev-skr42.py'),*map(str,args)],capture_output=True,encoding='utf-8')
            self.assertEqual(result.returncode,0 if ok else 2,result.stderr+result.stdout)
            return json.loads(result.stdout)
        with tempfile.TemporaryDirectory() as directory:
            directory=Path(directory);comparison=directory/'comparison';candidate=d.ROOT/'data/skr42-2026.json'
            result=run('compare','--from-year','2025','--candidate',candidate,'--output-dir',comparison)
            self.assertEqual(result['status'],'review_required')
            plan,_=d.read_json(comparison/'comparison.json');review=self.review(plan)
            review_file=directory/'reviewed.json';d.write_new(review_file,review)
            run('compare','--from-year','2025','--candidate',candidate,'--output-dir',comparison,ok=False)
            result=run('approve','--plan',comparison/'comparison.json','--review',review_file,'--candidate',candidate,'--output-dir',directory/'approved')
            self.assertFalse(result['production_authorized']);self.assertFalse(result['coverage']['complete'])
            self.assertEqual(result['status'],'incomplete_candidate')
            manifest,_=d.read_json(directory/'approved/manifest.json')
            self.assertIn('resolve_unmapped_accounts',manifest['requires'])
            for name,value in manifest['files'].items():
                self.assertEqual(d.hashlib.sha256((directory/'approved'/name).read_bytes()).hexdigest(),value)
            package_catalog,package_hash=d.read_json(directory/'approved/catalog.json')
            edition,_=d.read_json(directory/'approved/edition.json')
            d.validate_snapshot(edition,self.model,package_catalog,package_hash)
            self.assertEqual(run('verify')['runtime_rules_unchanged'],True)
        self.assertEqual({str(p):p.read_bytes() for p in watched},before)


if __name__=='__main__':unittest.main(verbosity=2)
