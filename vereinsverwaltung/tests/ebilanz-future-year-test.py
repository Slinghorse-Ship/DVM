"""2027 preparation contracts; synthetic annual catalogs, no native send or DB.

The published 6.10 schema is real. Annual 2027 catalog rows below are explicitly
synthetic and never saved as an active DATEV catalog or mistaken for its PDF.
"""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import zipfile
from html.parser import HTMLParser

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / 'tools'))
import datev_years as d
import ebilanz_release as release
import taxonomy_purposes as projections
from ebilanz_builder import EbilanzBuilder


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    out = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(out)
    return out


annual = module('future_annual_fixture', HERE / 'datev-years-test.py')
maintainer = module('future_maintainer', HERE.parent / 'tools/maintain-datev-skr42.py')
web = module('future_web', HERE.parent / 'tools/datev_web.py')


class FutureYear(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        annual.AnnualTest.setUpClass()
        cls.model = annual.AnnualTest.model
        cls.projection = projections.load(cls.model, '6.10')

    def pair(self, purpose=None):
        f = annual.AnnualTest()
        old = f.fixture(year=2026)
        ref = purpose or f.cash
        old['accounts'][0]['code'] = old['accounts'][0]['range_start'] = old['accounts'][0]['range_end'] = '18000' if purpose is None else '10000'
        number = old['accounts'][0]['code']
        old['ranges'][0]['code'] = old['ranges'][0]['end'] = number
        base = d.snapshot(self.model, old, d.digest(old), '6.9', {number: ref}, {'kind': 'synthetic_test'})
        new = copy.deepcopy(old)
        new.update(year=2027, sha256='b' * 64)
        return old, base, new

    def plan(self, old, base, new):
        return d.compare(self.model, base, old, d.digest(old), new, d.digest(new), '6.10')

    def test_published_source_and_catalog_only_capability_are_bound(self):
        p = release.package('6.10', 2027)
        self.assertEqual(p['taxonomy']['date'], '2026-04-01')
        self.assertEqual(p['taxonomy']['sha256'], self.model['taxonomies']['6.10']['sha256'])
        self.assertEqual(release.catalog_taxonomy(2027), '6.10')
        self.assertIsNone(release.catalog_taxonomy(2028))
        self.assertEqual(p['eric']['reviewed_versions'], [])
        self.assertFalse(release.status('6.10', 2027)['native_validation_ready'])
        self.assertFalse(release.status('6.10', 2028)['ready'])
        self.assertFalse(release.status('6.9', 2027)['ready'])

    def test_projection_is_reproducible_and_withholds_changed_concepts(self):
        review, old, new, _ = self.projection
        expected = projections.compile_projection(self.model, old, new)
        self.assertEqual(review, expected | {'sha256': projections.digest(expected)})
        self.assertEqual(len(review['eligible']), 686)
        self.assertEqual(len(review['blocked']), 21)
        for ref in review['eligible']:
            rule = d.mapping(self.model, ref, '6.10', self.projection)
            self.assertEqual(rule, d.mapping(self.model, ref, '6.9'))
            d.validate_target(self.model, ref, '6.10', 2027)
        for ref in review['blocked']:
            with self.assertRaises(ValueError):
                d.mapping(self.model, ref, '6.10', self.projection)

    def test_historic_editions_and_registry_remain_reproducible(self):
        editions, catalogs = d.load_editions(self.model)
        current, _ = d.read_json(d.ROOT / 'resources/ebilanz/skr42-6.9.json')
        self.assertEqual([e['year'] for e in editions], [2025, 2026])
        self.assertTrue(d.runtime_equivalent(current, d.compile_registry(self.model, editions, catalogs, current['revision'])))

    def test_unchanged_purpose_reused_across_taxonomies_without_new_definition(self):
        old, base, new = self.pair()
        before = copy.deepcopy(self.model['purposes'])
        plan = self.plan(old, base, new)
        self.assertTrue(plan['taxonomy_ready'])
        self.assertEqual(plan['automatic']['18000']['purpose'], annual.AnnualTest.cash)
        review = annual.AnnualTest().review(plan)
        result = d.approve(self.model, base, old, d.digest(old), new, d.digest(new), plan, review)
        self.assertEqual(result['taxonomy'], '6.10')
        self.assertFalse(result['provenance']['filing_package']['native_validation_ready'])
        self.assertEqual(self.model['purposes'], before)
        compiled = d.compile_registry(self.model, [result], {2027: (new, d.digest(new))}, 20)
        self.assertEqual(set(compiled['sources']), {'2027'})
        self.assertEqual(compiled['taxonomy'], '6.10')

    def test_changed_taxonomy_never_automatically_inherits_prior_purpose(self):
        old, base, new = self.pair('purpose-0005@1')
        plan = self.plan(old, base, new)
        self.assertEqual(plan['automatic'], {})
        self.assertEqual(plan['review_groups'][0]['review_reason'], 'taxonomy_change')
        review = annual.AnnualTest().review(plan)
        for choice in review['decisions'].values():
            choice.update(action='assign', purpose='purpose-0005@1', reason='Synthetic attempted bypass')
        with self.assertRaises(ValueError):
            d.approve(self.model, base, old, d.digest(old), new, d.digest(new), plan, review)

    def test_review_ui_distinguishes_preparation_from_native_acceptance(self):
        plan = self.plan(*self.pair())
        html = d.html_report(plan, self.model)
        self.assertIn('amtlicher ERiC-Prüfer', html)
        self.assertIn('noch nicht abgenommen', html)
        self.assertIn('eine Steuerabgabe bleibt gesperrt', html)
        self.assertNotIn('value="purpose-0005@1"', html)
        changed=d.html_report(self.plan(*self.pair('purpose-0005@1')),self.model)
        self.assertIn('Taxonomieangaben geändert',changed)
        class Decisions(HTMLParser):
            def __init__(self):super().__init__();self.active=False;self.options=[]
            def handle_starttag(self,tag,attributes):
                attributes=dict(attributes)
                if tag=='select':self.active=attributes.get('class')=='decision'
                if tag=='option' and self.active:self.options.append(attributes.get('value'))
            def handle_endtag(self,tag):
                if tag=='select':self.active=False
        decisions=Decisions();decisions.feed(changed)
        self.assertEqual(decisions.options,['defer','assign'])

    def test_preparation_cannot_build_xml_or_validate_against_fake_sdk(self):
        with self.assertRaisesRegex(ValueError, 'Ausgabeadapter'):
            EbilanzBuilder('6.10').build({}, {}, {}, '12345')
        with self.assertRaisesRegex(ValueError, 'native ERiC'):
            release.check_runtime(release.package('6.10', 2027), '<EricVersion/>')

    def test_only_json_cannot_add_native_support_or_future_years(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            directory = root / 'resources/ebilanz'
            directory.mkdir(parents=True)
            registry, _ = d.read_json(release.ROOT / 'resources/ebilanz/releases.json')
            original = copy.deepcopy(registry)
            (directory / 'taxonomy-6.10.json').write_bytes((release.ROOT / 'resources/ebilanz/taxonomy-6.10.json').read_bytes())
            for change in ('stage', 'version', 'year'):
                registry = copy.deepcopy(original)
                p = next(p for p in registry['packages'] if p['taxonomy']['version'] == '6.10')
                if change == 'stage': p['stage'] = 'catalog_and_local_validation'
                if change == 'version': p['eric']['reviewed_versions'] = [{'ericapi': '45.0.0.0', 'checkbilanz': '45.0.0.0'}]
                if change == 'year': p['years'].append(2028)
                (directory / 'releases.json').write_text(json.dumps(registry), encoding='utf8')
                with patch.object(release, 'ROOT', root):
                    self.assertFalse(release.status('6.10', 2027)['ready'])

    def test_changed_source_definition_invalidates_projection_even_after_cache(self):
        ref = annual.AnnualTest.cash
        original = d.definition(self.model, ref)
        self.assertIsNotNone(projections.projected_rule(original, ref, self.projection))
        changed = copy.deepcopy(original)
        changed['mappings']['6.9']['target'] = 'bs.ass.currAss.cashEquiv.cash'
        with self.assertRaises(ValueError):
            projections.projected_rule(changed, ref, self.projection)

    def test_changed_projection_bytes_reject_old_review(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            path = root / 'resources/accounting/reviews'
            path.mkdir(parents=True)
            original = projections.ROOT / 'resources/accounting/reviews/taxonomy-6.10.json'
            (path / original.name).write_bytes(original.read_bytes() + b' ')
            with patch.object(projections, 'ROOT', root), self.assertRaises(ValueError):
                projections.load(self.model, '6.10')

    def test_stale_package_version_invalidates_annual_review(self):
        old, base, new = self.pair()
        plan = self.plan(old, base, new)
        changed = plan['filing_package'] | {'sha256': '0' * 64}
        with patch.object(release, 'status', return_value=changed), self.assertRaises(ValueError):
            d.approve(self.model, base, old, d.digest(old), new, d.digest(new), plan, annual.AnnualTest().review(plan))

    def test_synthetic_web_chain_keeps_taxonomies_separate_and_native_pending(self):
        editions, catalogs = d.load_editions(self.model)
        old = catalogs[2026][0]
        positions = d.statements.contexts(old)
        candidate = copy.deepcopy(old)
        candidate.update(year=2027, edition='balance', chart='SKR42', statement_schema=1,
                         source='https://www.datev.de/synthetic-2027-test-only.pdf', sha256='b' * 64)
        for account in candidate['accounts']:
            account['statement'] = positions[account['code']]
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            pdf = b'%PDF-1.7\nSynthetic 2027 test only, not an official publication'
            (directory / 'original.pdf').write_bytes(pdf)
            def parser(command, **kwargs):
                output = Path(command[command.index('--output-dir') + 1])
                output.mkdir()
                (output / 'skr42-2027.json').write_text(json.dumps(candidate, ensure_ascii=False), encoding='utf8')
                return SimpleNamespace(returncode=0)
            request = {'operation': 'compare', 'from_year': 2026, 'year': 2027, 'source': candidate['source'],
                       'pdf_sha256': hashlib.sha256(pdf).hexdigest(), 'appendix_pages': 2}
            with patch.object(web.subprocess, 'run', side_effect=parser):
                result = web.run(request, directory)
            self.assertTrue(result['taxonomy_ready'])
            plan = json.loads((directory / 'comparison.json').read_text(encoding='utf8'))
            self.assertEqual(plan['taxonomy'], '6.10')
            self.assertGreater(len(plan['automatic']), 1000)
            self.assertTrue(any(g['review_reason'] == 'taxonomy_change' for g in plan['review_groups']))
            review = annual.AnnualTest().review(plan)
            (directory / 'submitted-review.json').write_text(json.dumps(review), encoding='utf8')
            result = web.run(request | {'operation': 'approve'}, directory)
            self.assertEqual(result['state'], 'incomplete_candidate')
            with zipfile.ZipFile(directory / 'package.zip') as archive:
                manifest = json.loads(archive.read('manifest.json'))
                self.assertFalse(manifest['production_authorized'])
                self.assertFalse(manifest['filing_package']['native_validation_ready'])
                self.assertIn('native_eric_acceptance', manifest['requires'])
                self.assertIn('official_test_feedback', manifest['requires'])
                self.assertEqual(json.loads(archive.read('skr42-6.10.json'))['sources'].keys(), {'2027'})
                self.assertEqual(archive.read('skr42-6.9.json'), (d.ROOT / 'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n', b'\n'))
                for name, sha in manifest['files'].items():
                    self.assertEqual(hashlib.sha256(archive.read(name)).hexdigest(), sha)
        self.assertEqual([e['year'] for e in d.load_editions(self.model)[0]], [2025, 2026])


if __name__ == '__main__':
    unittest.main(verbosity=2)
