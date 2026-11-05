"""Original legacy aggregate/combined receipts into two dated short periods."""
import copy, importlib.util, json, os, subprocess, unittest
from pathlib import Path

def module(name,file):
    s=importlib.util.spec_from_file_location(name,Path(__file__).with_name(file));m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
combined=module('calendar_combined_cases','ebilanz-period-combined-test.py');partial=combined.partial
from ebilanz_period_club import verify
from ebilanz_calendar_club import record

def fixture(kind):
    r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(Path(__file__).parent/'fixtures/ebilanz-period-club-calendar.php'),kind],capture_output=True,text=True,encoding='utf8',timeout=90)
    if r.returncode or r.stderr.strip():raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

def build(v):return combined.build(v) if 'taxable' in v else partial.build(v)

class CalendarClub(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.cases={k:fixture(k) for k in ('book','tax','book-successor','tax-successor','combined-book','combined-tax','combined-book-successor','combined-tax-successor')}
    def test_original_calendar_and_actual_period_dates(self):
        for case,v in self.cases.items():
            with self.subTest(case=case):
                out=build(v);t=v.get('taxable',v);self.assertEqual(out['period'],v['period'])
                self.assertEqual(t['result_difference_cents'],600 if 'tax' in case else 0)
                self.assertEqual(len(t['period_club']['whole']['asset_schedule']['items']),2)
                self.assertEqual(out['taxable_asset_items'] if 'taxable' in v else out['asset_items'],1)
                if not case.endswith('successor'):self.assertIn('2025-12-31',out['xml'])
    def test_resealed_original_evidence_not_replaceable(self):
        for case in ('source','allocation','values','review','whole','combined','extra','previous','club','clearing','schema'):
            v=copy.deepcopy(self.cases['combined-tax']);old=v['taxable']['period_club']['previous'];c=old['calendar_club']
            if case=='source':c['report']['closed_sha256']='0'*64
            if case=='allocation':c['income']['scope']['review']['allocation']['revision']+=1;partial.seal(c['income'])
            if case=='values':old['taxable']['tax']['income_cents']+=1;partial.seal(old['taxable'])
            if case=='review':c['review']['asset_tax_plans']={}
            if case=='whole':old['whole']['book']['income_cents']+=1;partial.seal(old['whole'])
            if case=='combined':old['combined']['whole']=self.cases['combined-book']['whole'];partial.seal(old['combined'])
            if case=='extra':c['ignored']='bad'
            if case=='previous':c['income']['scope']['previous']={};partial.seal(c['income'])
            if case=='club':c['income']['scope']['club_id']='unrelated-club';partial.seal(c['income'])
            if case=='clearing':c['income']['scope']['clearing']['previous']={};partial.seal(c['income'])
            if case=='schema':c['income']['scope']['schema']=2;partial.seal(c['income'])
            with self.subTest(case=case),self.assertRaises((ValueError,KeyError)):record(old)
    def test_latest_dated_chain_bound_to_legacy_proof(self):
        v=copy.deepcopy(self.cases['tax-successor']);v['period_club']['previous']=self.cases['book'];partial.seal(v)
        with self.assertRaises(ValueError):verify(v)

if __name__=='__main__':unittest.main()
