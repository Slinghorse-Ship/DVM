"""Verified SKR42 club capital, reserves, transfers, negative carry and both years."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_builder import EbilanzBuilder,NS,BASE
from ebilanz_mapping import automatic_mapping
EQUITY='bs.eqLiab.equity.'
RESERVE=EQUITY+'revenueRes.'


class Capital(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.cases={}
        for name,file in [('whole','whole-club'),('combined','combined-club'),('taxable','club-scope')]:
            spec=importlib.util.spec_from_file_location(name,ROOT/f'tests/ebilanz-{file}-test.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
            for variant in ('club-capital','club-capital-carry'):
                v=module.fixture(variant);m,p=module.inputs(v);cls.cases[name,variant]=(v,m,p)

    def builds(self):
        for key,(v,m,p) in self.cases.items():
            with self.subTest(case=key):yield key,v,m,p,ET.fromstring(EbilanzBuilder().build(v,m,p,'12345')['xml'])

    def sgeb(self,root):
        contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)};out={}
        for section in ('balanceSheet','IncomeStatement'):
            for f in root.findall('.//de-gaap-ci:'+BASE+section+'.valueOfItem',NS):
                member=contexts[f.get('contextRef')].find('.//xbrldi:explicitMember',NS)
                out[member.text.removeprefix('de-gaap-ci:')]=f.text
        return out

    def test_all_profiles_and_years_reconcile(self):
        for (kind,variant),v,m,p,root in self.builds():
            carry=variant.endswith('carry')
            if kind!='taxable':
                for target,amount in [('bs.ass','1100.12'),(EQUITY+'association','998.50'),(RESERVE+'charityAppropriated.replacement','6.50'),(RESERVE[:-1],'51.50'),(EQUITY+'SurplusOfUnspentFunds','40.12' if carry else '0.00'),('is.netIncome','0.00' if carry else '40.12')]:
                    self.assertEqual(root.find('.//de-gaap-ci:'+target,NS).text,amount,(kind,target))
            if kind!='whole':
                facts=self.sgeb(root)
                for target,amount in [('bs.ass','434.06'),(EQUITY+'association','399.40'),(RESERVE+'charityAppropriated.replacement','2.60'),(RESERVE[:-1],'20.60'),(EQUITY+'SurplusOfUnspentFunds','10.06' if carry else '0.00'),('is.netIncome','0.00' if carry else '10.06')]:self.assertEqual(facts[target],amount,(kind,target))

    def test_whole_bvv_not_increased_by_reserve_transfer(self):
        for variant in ('club-capital','club-capital-carry'):
            v,m,p=self.cases['whole',variant];root=ET.fromstring(EbilanzBuilder().build(v,m,p,'12345')['xml'])
            for name in ('contribution','withdrawalDistrib'):self.assertEqual(root.find('.//de-gaap-ci:BVV.profitLoss.'+name,NS).text,'0.00')
            self.assertEqual(root.find('.//de-gaap-ci:BVV.profitLoss',NS).text,'0.00' if variant.endswith('carry') else '40.12')

    def test_exact_defaults_ambiguous_require_review(self):
        v,_,_=self.cases['whole','club-capital'];auto=automatic_mapping(v)
        self.assertNotIn('bound',auto);self.assertNotIn('free',auto)
        self.assertEqual(auto['equity'],EQUITY+'association')
        self.assertEqual(auto['carry_loss'],EQUITY+'SurplusOfUnspentFunds')

    def test_no_reserve_purpose_inference(self):
        for kind in ('whole','combined','taxable'):
            for account in ('bound','free'):
                v,m,p=copy.deepcopy(self.cases[kind,'club-capital'])
                for section in (('whole','taxable') if kind=='combined' else (None,)):
                    changed=copy.deepcopy(m);target=changed[section] if section else changed;target.pop(account)
                    with self.subTest(kind=kind,account=account,section=section),self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):EbilanzBuilder().build(v,changed,p,'12345')

    def test_other_valid_purpose_sums_without_overlapping_parents(self):
        for kind in ('whole','combined','taxable'):
            v,m,p=copy.deepcopy(self.cases[kind,'club-capital'])
            for target in (m.values() if kind=='combined' else [m]):
                target['bound']=RESERVE+'charityAppropriated.workingCapital';target['free']=RESERVE+'unappropriated.otherMeansDueCourse'
            root=ET.fromstring(EbilanzBuilder().build(v,m,p,'12345')['xml'])
            if kind!='taxable':
                for suffix in ('charityAppropriated.workingCapital','unappropriated.otherMeansDueCourse'):self.assertEqual(root.find('.//de-gaap-ci:'+RESERVE+suffix,NS).text,'10.00')
            if kind!='whole':
                for suffix in ('charityAppropriated.workingCapital','unappropriated.otherMeansDueCourse'):self.assertEqual(self.sgeb(root)[RESERVE+suffix],'4.00')

    def test_valid_but_wrong_capital_meanings_rejected(self):
        for kind in ('whole','combined','taxable'):
            for account,target in [('equity',EQUITY+'retainedEarnings'),('bound',RESERVE+'unappropriated.assetManagement'),('free',RESERVE+'charityAppropriated.project'),('replacement',RESERVE+'other'),('carry_loss',EQUITY+'association')]:
                v,m,p=copy.deepcopy(self.cases[kind,'club-capital'])
                (m['whole'] if kind=='combined' else m)[account]=target
                with self.subTest(kind=kind,account=account),self.assertRaisesRegex(ValueError,'widersprechen'):EbilanzBuilder().build(v,m,p,'12345')

    def test_account_balances_include_negative_carry_not_absolute_value(self):
        for _,_,_,_,root in self.builds():
            tuples=root.findall('.//de-gaap-ci:detailedInformation.accountBalances',NS)
            losses=[t.find('de-gaap-ci:detailedInformation.accountbalances.amount',NS).text for t in tuples if t.find('de-gaap-ci:detailedInformation.accountbalances.accountNumber',NS).text=='29780']
            self.assertTrue(losses);self.assertTrue(all(x in ('-5.00','-2.00') for x in losses))

    def test_xml_is_deterministic_and_does_not_mutate_source(self):
        for key,(v,m,p) in self.cases.items():
            before=copy.deepcopy((v,m,p));a=EbilanzBuilder().build(v,m,p,'12345');b=EbilanzBuilder().build(v,m,p,'12345')
            self.assertEqual(a,b,key);self.assertEqual((v,m,p),before);self.assertEqual(a['transmission'],'unavailable')

if __name__=='__main__':unittest.main()
