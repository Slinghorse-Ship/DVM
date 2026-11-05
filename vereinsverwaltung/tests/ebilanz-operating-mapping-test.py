"""Exact operating-account classification, independent PHP journal and XBRL totals.

No credentials, network, bookkeeping writes or transmission.
"""
import copy
import importlib.util
import json
from pathlib import Path
import unittest
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('native_cases',ROOT/'tests/ebilanz-native.py')
cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(cases)
from ebilanz_builder import EbilanzBuilder, NS, BASE, SUPPORTED
from ebilanz_mapping import automatic_mapping

COSTS=json.loads((ROOT/'tests/fixtures/ebilanz-operating-accounts.json').read_text(encoding='utf8'))
def target(suffix):return suffix if suffix.startswith('is.') else 'is.netIncome.regular.operatingTC.'+suffix

def facts(package):
    root=ET.fromstring(package['xml'])
    contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)}
    return {contexts[f.get('contextRef')].find('.//xbrldi:explicitMember',NS).text.removeprefix('de-gaap-ci:'):f.text
            for f in root.findall('.//de-gaap-ci:'+BASE+'IncomeStatement.valueOfItem',NS) if f.text is not None}

class OperatingMapping(unittest.TestCase):
    def test_each_code_and_mixed_accounts_both_years(self):
        for year in (2025,2026):
            for credit in (False,True):
                variant='operating-coverage'+('-credit' if credit else '')+('' if year==2025 else '-2026')
                with self.subTest(year=year,credit=credit):
                    values=cases.fixture(variant);before=copy.deepcopy(values);mapping,profile=cases.inputs(values)
                    automatic=automatic_mapping(values);self.assertEqual(values,before)
                    expected={}
                    for suffix,codes in COSTS['automatic'].items():
                        t=target(suffix);self.assertIn(t,SUPPORTED['expense'])
                        expected[t]=expected.get(t,0)+len(codes)*(-1234 if credit else 1234)
                        for code in codes:self.assertEqual(automatic['code-'+code],t)
                    package=EbilanzBuilder().build(values,mapping,profile,'12345');out=facts(package)
                    for t,amount in expected.items():
                        from ebilanz_builder import money
                        self.assertEqual(out[t],money(amount))
                    self.assertEqual(out['is.netIncome'],money(values['tax']['annual_result_cents']))
                    self.assertEqual(values,before,'mapping/export never alters reviewed tax adjustment or journal')
                    self.assertEqual(package['environment'],'test')
                    self.assertEqual(package['transmission'],'unavailable')

    def test_all_detail_choices_and_missing_or_wrong_choice(self):
        for year in (2025,2026):
            for code,choices in COSTS['details'].items():
                values=cases.fixture('detail-'+code+('' if year==2025 else '-2026'))
                mapping,profile=cases.inputs(values);key='code-'+code
                self.assertNotIn(key,automatic_mapping(values))
                with self.subTest(year=year,code=code),self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):
                    EbilanzBuilder().build(values,mapping,profile,'12345')
                for suffix in choices:
                    t=target(suffix)
                    with self.subTest(year=year,code=code,target=t):
                        package=EbilanzBuilder().build(values,mapping|{key:t},profile,'12345')
                        self.assertEqual(facts(package)[t],'12.34')
                for wrong in (target('otherCost.administration'),'bs.ass.currAss.cashEquiv.bank',target('staff.salaries')):
                    with self.subTest(year=year,code=code,wrong=wrong),self.assertRaises(ValueError):
                        EbilanzBuilder().build(values,mapping|{key:wrong},profile,'12345')

    def test_known_account_semantics_cannot_be_overridden(self):
        values=cases.fixture('operating-coverage');mapping,profile=cases.inputs(values)
        for suffix,codes in COSTS['automatic'].items():
            for code in codes:
                key='code-'+code
                wrong=target('otherCost.insurance')
                with self.subTest(code=code),self.assertRaisesRegex(ValueError,'Kontenbedeutung'):
                    automatic_mapping(values,{key:wrong})
                self.assertEqual(automatic_mapping(values,{key:target(suffix)})[key],target(suffix))

    def test_every_registry_choice_is_supported_and_permitted(self):
        registry=json.loads((ROOT/'resources/ebilanz/skr42-6.9.json').read_text(encoding='utf8'))
        builder=EbilanzBuilder()
        for year in (2025,2026):
            for rule in registry['rules']:
                if year not in rule.get('years',[2025,2026]):continue
                for t in rule.get('allowed_targets',[rule['target']]):
                    with self.subTest(year=year,target=t):
                        self.assertIn(t,SUPPORTED[rule['group']]);self.assertTrue(builder.permitted(t,year))

if __name__=='__main__':unittest.main()
