"""Dated PHP journals -> independently rebuilt XBRL. No transport or database."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
import xml.etree.ElementTree as ET

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('native_cases',HERE/'ebilanz-native.py')
cases=importlib.util.module_from_spec(spec);spec.loader.exec_module(cases)
from ebilanz_builder import EbilanzBuilder, NS
from ebilanz_club_scope import digest
from ebilanz_period import check

def fixture(variant='cross'):
    php=Path(os.environ['VEREIN_FIXTURE_PHP'])
    if not php.is_absolute() or not php.is_file():raise ValueError('Native PHP required')
    result=subprocess.run([str(php),str(HERE/'fixtures/ebilanz-period-values.php'),variant],capture_output=True,text=True,encoding='utf8',timeout=30)
    if result.returncode or result.stderr.strip():raise RuntimeError(result.stdout+result.stderr)
    return json.loads(result.stdout)

def seal(value):
    value['sha256']=digest({k:v for k,v in value.items() if k not in ('sha256','transmission')})

class DatedExport(unittest.TestCase):
    def setUp(self):self.values=fixture()
    def build(self,values=None,builder=None):
        values=values or self.values
        mapping,profile=cases.inputs(values)
        return (builder or EbilanzBuilder()).build(values,mapping,profile,'12345')
    def test_actual_dates_in_every_context_and_gcd(self):
        for variant in ('cross','short-first','calendar','successor'):
            with self.subTest(variant=variant):
                values=fixture(variant);root=ET.fromstring(self.build(values)['xml']);period=values['period']
                self.assertEqual(root.find('.//b:stichtag',NS).text,period['end'].replace('-',''))
                for context in root.findall('.//xbrli:context',NS):
                    p=context.find('xbrli:period',NS);instant=p.find('xbrli:instant',NS)
                    if instant is not None:self.assertEqual(instant.text,period['end'])
                    else:
                        self.assertEqual(p.find('xbrli:startDate',NS).text,period['start'])
                        self.assertEqual(p.find('xbrli:endDate',NS).text,period['end'])
                for name,part in [('fiscalYearBegin','start'),('fiscalYearEnd','end'),('balSheetClosingDate','end')]:
                    self.assertEqual(root.find('.//de-gcd:genInfo.report.period.'+name,NS).text,period[part])
                previous=values['period_source']['previous']
                for name,part in [('fiscalPreviousYearBegin','start'),('fiscalPreviousYearEnd','end'),('balSheetClosingDatePreviousYear','end')]:
                    field=root.find('.//de-gcd:genInfo.report.period.'+name,NS)
                    self.assertEqual(field.text,previous['period'][part] if previous else None)
                    if not previous:self.assertEqual(field.get('{'+NS['xsi']+'}nil'),'true')
    def test_first_and_short_successor_do_not_share_identity_dates(self):
        first=self.build(fixture());second=self.build(fixture('successor'))
        self.assertNotEqual(first['xml_sha256'],second['xml_sha256'])
        self.assertNotEqual(first['journal_sha256'],second['journal_sha256'])
    def test_independent_totals_not_just_rehash(self):
        for path in [('book','annual_result_cents'),('tax','annual_result_cents'),('accounts','receivables','difference_cents')]:
            self.values=fixture();target=self.values
            for part in path[:-1]:target=target[part]
            target[path[-1]]+=1;seal(self.values)
            with self.subTest(path=path),self.assertRaises(ValueError):self.build()
    def test_out_of_period_original_even_inside_closing_year(self):
        self.values['period_source']['source']['entries'][0]['date']='2026-12-31';seal(self.values)
        with self.assertRaises(ValueError):self.build()
    def test_missing_or_foreign_source(self):
        for key in ('period_source','period'):
            self.values=fixture();del self.values[key];seal(self.values)
            with self.subTest(key=key),self.assertRaises(ValueError):self.build()
        self.values=fixture();self.values['period_source']['source']['closed_sha256']='f'*64;seal(self.values)
        with self.assertRaises(ValueError):self.build()
    def test_carry_cannot_be_removed_or_reassigned(self):
        for mutate in ('missing','gap','amount','journal'):
            self.values=fixture('successor')
            if mutate=='missing':self.values['period_source']['previous']=None
            if mutate=='gap':self.values['period_source']['previous']['period']['end']='2026-05-31';seal(self.values['period_source']['previous'])
            if mutate=='amount':self.values['period_source']['source']['opening'][0]['debit_cents']+=1
            if mutate=='journal':self.values['period_source']['source']['previous_sha256']='f'*64
            seal(self.values)
            with self.subTest(mutate=mutate),self.assertRaises(ValueError):self.build()
    def test_reuse_builder_does_not_leak_dates_into_legacy_calendar(self):
        builder=EbilanzBuilder();self.build(fixture(),builder)
        legacy=cases.fixture();mapping,profile=cases.inputs(legacy)
        self.assertEqual(builder.build(legacy,mapping,profile,'12345'),EbilanzBuilder().build(legacy,mapping,profile,'12345'))
    def test_no_asset_or_whole_club_bypass(self):
        self.values['asset_schedule']['items']={'injected':{}};seal(self.values)
        with self.assertRaises(ValueError):self.build()
        self.values=fixture();mapping,profile=cases.inputs(self.values);profile['kind']='whole_taxable_club'
        with self.assertRaises(ValueError):EbilanzBuilder().build(self.values,mapping,profile,'12345')
    def test_dates_validate_length_leap_and_shape(self):
        self.assertEqual(check({'start':'2024-02-29','end':'2025-02-28'})['end'],'2025-02-28')
        for p in ({'start':'2025-02-29','end':'2025-12-31'}, {'start':'2025-01-01','end':'2026-01-01'},
                  {'start':'2026-01-01','end':'2025-12-31'}, {'start':'2025-01-01','end':'2025-12-31','other':1}):
            with self.subTest(p=p),self.assertRaises(ValueError):check(p)
    def test_generation_never_transmits(self):
        result=self.build()
        self.assertEqual(result['environment'],'test');self.assertEqual(result['transmission'],'unavailable')
        self.assertEqual(ET.fromstring(result['xml']).find('.//e:Testmerker',NS).text,'700000004')

if __name__=='__main__':unittest.main()
