"""Source families, signed automatic choices and unchanged historical years."""
import copy,importlib.util,json,os,shutil,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('r16_shared',ROOT/'tests/ebilanz-operating-extended-test.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
shared.REVIEW='operating-r16'
shared.SPECS=json.loads((ROOT/'resources/accounting/reviews/operating-r16.json').read_text(encoding='utf8'))['years']

import sys as history_sys
history_sys.path.insert(0,str(Path(__file__).resolve().parent))
from datev_history_helpers import original_sources

class OperatingR16(shared.OperatingExtended):
    def test_preserves_every_previous_source_binding_and_definition(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json')
        editions,catalogs=d.load_editions(model)
        registry,_=d.read_json(ROOT/'resources/ebilanz/skr42-6.9.json')
        self.assertTrue(d.runtime_equivalent(registry,d.compile_registry(model,editions,catalogs,16)))
        for current in editions:
            edition,_=d.read_json(ROOT/f"resources/accounting/years/{current['year']}-r16.json")
            old,_=d.read_json(ROOT/f"resources/accounting/years/{edition['year']}-r15.json")
            previous=d.validate_snapshot(old,model,*catalogs[edition['year']]);new=d.bindings(edition)
            self.assertEqual({n:new[n] for n in previous},previous)
            self.assertEqual(len(new)-len(previous),102)
            self.assertEqual({n:v for n,v in original_sources(model,current).items() if n in new},new)

    def test_balance_direction_cannot_be_overridden_by_manual_mapping(self):
        for edition in shared.SPECS:
            for index,s in enumerate(edition['groups']):
                if 'negative_target' not in s:continue
                for mode in ('normal','negative','cancelled'):
                    v=shared.fixture(edition['year'],index,mode)
                    wrong=s['target'] if mode=='negative' else s['negative_target']
                    keys=['code-'+row['code'] for row in s['sources']]
                    with self.assertRaisesRegex(ValueError,'Kontensaldo'):
                        shared.build(v,dict.fromkeys(keys,wrong))
                    v['tax']['accounts'][keys[0]]['amount_cents']=True
                    with self.assertRaisesRegex(ValueError,'Kontensaldo'):
                        shared.automatic_mapping(v)

    def test_kst_mapping_never_uses_sole_trader_or_supplementary_leaves(self):
        for edition in shared.SPECS:
            for s in edition['groups']:
                for t in s['choices']:
                    self.assertNotIn('merchandiseWithdrawals',t)
                    self.assertNotIn('privateUseVehicles',t)
                    self.assertNotIn('otherOpRevenue.statutoryDuties',t)
        # A same-caption account without its official number must not inherit.
        v=shared.fixture(2026,0);key='code-40000'
        v['tax']['accounts'][key]['skr_code']=''
        self.assertNotIn(key,shared.automatic_mapping(v))

    def test_no_presentation_rule_calculates_deductions_or_changes_values(self):
        for edition in shared.SPECS:
            for index,s in enumerate(edition['groups']):
                v=shared.fixture(edition['year'],index)
                self.assertEqual(v['book'],v['tax'])
                self.assertEqual(v['current_adjustments'],[])
                self.assertEqual(v['opening_adjustments'],[])

    def test_cached_sources_are_pinned_to_contents_not_only_year(self):
        from ebilanz_mapping import checked_source
        raw=(ROOT/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n')
        catalog=(ROOT/'data/skr42-2026.json').read_bytes().replace(b'\r\n',b'\n')
        checked_source(2026,raw,catalog);before=checked_source.cache_info()
        checked_source(2026,raw,catalog)
        self.assertEqual(checked_source.cache_info().hits,before.hits+1)
        altered=json.loads(raw);altered['rules'].append(copy.deepcopy(altered['rules'][0]))
        with self.assertRaisesRegex(ValueError,'widersprechen'):
            checked_source(2026,json.dumps(altered).encode(),catalog)
        with self.assertRaisesRegex(ValueError,'Kontenplan geändert'):
            checked_source(2026,raw,catalog+b' ')
        checked_source(2026,raw,catalog)

    def test_php_request_cache_keeps_content_and_balance_checks(self):
        # A wholly disposable copied tree, never edits the working catalog.
        with tempfile.TemporaryDirectory(prefix='synthetic-mapping-cache-') as temp:
            p=Path(temp)
            # Exercise the real dependency graph, including evidenced cost assets.
            # A copied file allowlist silently became incomplete when mapping grew.
            shutil.copytree(ROOT/'src',p/'src')
            shutil.copytree(ROOT/'resources',p/'resources')
            for rel in ('data/skr42-2026.json',):
                target=p/rel;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/rel,target)
            env=os.environ.copy();env['SYNTHETIC_SOURCE_ROOT']=str(p)
            script='''<?php
            $root=getenv('SYNTHETIC_SOURCE_ROOT');
            require $root.'/src/TaxCatalog.php'; require $root.'/src/BalanceTaxMapping.php';
            $a=['x'=>['name'=>'Synthetic','group'=>'revenue','skr_code'=>'48000','amount_cents'=>100]];
            $p=Kulturhus\\Verein\\BalanceTaxMapping::preview(2026,$a);
            $a['x']['amount_cents']=-100;
            $q=Kulturhus\\Verein\\BalanceTaxMapping::preview(2026,$a);
            if($p['mapping']===$q['mapping'])throw new RuntimeException('Cached user balance');
            $path=$root.'/resources/ebilanz/skr42-6.9.json';$original=file_get_contents($path);
            $rules=json_decode($original,true,64,JSON_THROW_ON_ERROR);$rules['rules'][]=$rules['rules'][0];
            file_put_contents($path,json_encode($rules,JSON_THROW_ON_ERROR));
            try{Kulturhus\\Verein\\BalanceTaxMapping::preview(2026,$a);throw new RuntimeException('Stale rule cache');}catch(DomainException){}
            file_put_contents($path,$original);$path=$root.'/data/skr42-2026.json';$original=file_get_contents($path);
            file_put_contents($path,$original.' ');
            try{Kulturhus\\Verein\\BalanceTaxMapping::preview(2026,$a);throw new RuntimeException('Stale catalog cache');}catch(DomainException){}
            echo 'verified';'''
            r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP']],input=script,encoding='utf8',env=env,capture_output=True,timeout=60)
            self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertEqual(r.stdout,'verified');self.assertEqual(r.stderr,'')

    def test_annual_direction_rule_must_retain_both_permitted_targets(self):
        d=shared.d;model,_=d.read_json(ROOT/'resources/accounting/purposes.json')
        s=next(s for s in shared.SPECS[0]['groups'] if 'negative_target' in s)
        for invalid in ({'negative_target':s['target']},{'allowed_targets':[s['target']]},{'negative_target':'made.up'}):
            m=copy.deepcopy(model);p,v=s['purpose'].split('@')
            m['purposes'][p]['versions'][v]['mappings']['6.9'].update(invalid)
            with self.assertRaises(ValueError):d.validate_target(m,s['purpose'],'6.9',2025)

if __name__=='__main__':unittest.main(verbosity=2)
