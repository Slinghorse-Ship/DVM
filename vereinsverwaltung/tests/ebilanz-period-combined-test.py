"""Dated complete books and all taxable businesses in one generated XBRL."""
import copy, importlib.util, json, os, subprocess, sys, unittest
from pathlib import Path
import xml.etree.ElementTree as ET
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))
from ebilanz_builder import EbilanzBuilder, NS
from ebilanz_club_scope import digest
from ebilanz_combined_club import validate
spec=importlib.util.spec_from_file_location('partial_cases',Path(__file__).with_name('ebilanz-period-club-test.py'))
partial=importlib.util.module_from_spec(spec);spec.loader.exec_module(partial)

def fixture(kind):
    r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(Path(__file__).parent/'fixtures/ebilanz-period-combined.php'),kind],capture_output=True,text=True,encoding='utf8',timeout=90)
    if r.returncode or r.stderr.strip():raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

def profile(v):
    return partial.profile(v['taxable'])|{'kind':'tax_privileged_club_with_sgeb','source_scope':'complete_club_with_approved_allocation','asset_scope':'whole_and_taxable',
                                       'tax_values_sha256':v['sha256'],'whole_business_name':'Synthetische Vereinsarbeit','whole_book_standard':'HGBM'}

def build(v):
    m={'cash':'bs.ass.currAss.cashEquiv.bank','revenue':'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'}
    if not v['taxable']['tax']['accounts']['equity']['skr_code']:m|={'equity':'bs.eqLiab.equity.retainedEarnings','expense':'is.netIncome.regular.operatingTC.otherCost.administration'}
    return EbilanzBuilder().build(v,{'whole':m,'taxable':m},profile(v),'12345')

class PeriodCombined(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.cases={k:fixture(k) for k in ('book','tax','book-successor','tax-successor','reversal','reversal-successor','internal-advance')}
    def test_full_original_with_separate_taxable_contexts(self):
        for name,v in self.cases.items():
            with self.subTest(name=name):
                original=copy.deepcopy(v);out=build(v);self.assertEqual(v,original)
                self.assertEqual(out['period'],v['period']);self.assertEqual(out['asset_items'],0 if name=='internal-advance' else 2)
                self.assertEqual(out['taxable_asset_items'],0 if name=='internal-advance' else 1)
                tree=ET.fromstring(out['xml']);contexts=tree.findall('.//{'+NS['xbrli']+'}context')
                self.assertEqual(len(contexts),len({c.get('id') for c in contexts}))
                for c in contexts:
                    p=c.find('{'+NS['xbrli']+'}period');date=p.find('{'+NS['xbrli']+'}endDate')
                    if date is not None:self.assertEqual(date.text,v['period']['end'])
                if name!='internal-advance':
                    self.assertIn('Anlagen des zusammengefassten steuerpflichtigen Bereichs',out['xml'])
                    self.assertIn('1200.01',out['xml'])
                self.assertEqual(out['transmission'],'unavailable')
    def test_forged_combined_payload_cannot_promote_wrong_original(self):
        for kind in ('whole','period','reconciliation','tax','schema','extra','unlinked-whole','predecessor'):
            v=copy.deepcopy(self.cases['tax-successor'])
            if kind=='whole':v['whole']['book']['income_cents']+=1;partial.seal(v['whole'])
            if kind=='period':v['period']['start']='2026-08-01'
            if kind=='reconciliation':v['reconciliation']['result']['whole']+=1
            if kind=='tax':v['taxable']['tax']['annual_result_cents']+=1;partial.seal(v['taxable'])
            if kind=='schema':v['schema']=1
            if kind=='extra':v['extra']=1
            if kind=='unlinked-whole':v['whole']=self.cases['book']['whole']
            if kind=='predecessor':v['taxable']['period_club']['previous']=None;partial.seal(v['taxable'])
            partial.seal(v)
            with self.subTest(kind=kind),self.assertRaises((ValueError,KeyError)):validate(v,profile(v))
    def test_profile_confirmation_and_asset_flags_required(self):
        v=self.cases['tax']
        for key,value in [('asset_values_unchanged',True),('asset_tax_values_reviewed',False),('kind','whole_taxable_club'),('tax_privileged',False),('whole_book_standard',''),('scope_confirmed',False)]:
            p=profile(v)|{key:value}
            with self.subTest(key=key),self.assertRaises(ValueError):validate(v,p)

if __name__=='__main__':unittest.main()
