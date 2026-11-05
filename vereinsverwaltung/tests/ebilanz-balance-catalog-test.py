"""Exact annual account coverage and independent PHP -> XBRL totals, no transport."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_builder import EbilanzBuilder,NS,BASE,money,SUPPORTED
from ebilanz_mapping import automatic_mapping
from ebilanz_club_scope import digest

SPECS=json.loads((ROOT/'tests/fixtures/ebilanz-balance-accounts.json').read_text(encoding='utf8'))

def fixture(year,index,release=False):
    php=os.environ.get('VEREIN_FIXTURE_PHP')
    if not php or not Path(php).is_absolute() or not Path(php).is_file():raise RuntimeError('Explicit test PHP executable required.')
    p=subprocess.run([php,str(ROOT/'tests/fixtures/ebilanz-balance-accounts.php'),str(year),str(index),'release' if release else ''],capture_output=True,text=True,encoding='utf8',timeout=60)
    if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout[-1000:]+p.stderr[-1000:])
    return json.loads(p.stdout)

def build(values, mapping=None):
    profile={'kind':'single_taxable_business','scope_confirmed':True,'source_scope':'separate_business_ledger',
             'tax_privileged':True,'asset_scope':'single_reported_business','asset_values_unchanged':True,
             'no_retained_fixed_assets':True,'tax_values_sha256':values['sha256'],'business_name':'Synthetic account classification',
             'supplier':'Synthetic software test','company':{'tax_number':'5192050001276','name':'Synthetic club','street':'Testweg',
                'house_number':'1','postal_code':'11111','city':'Teststadt','country':'Deutschland'}}
    return EbilanzBuilder().build(values,mapping or {},profile,'12345')

def facts(package):
    root=ET.fromstring(package['xml']);contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)}
    return {contexts[f.get('contextRef')].find('.//xbrldi:explicitMember',NS).text.removeprefix('de-gaap-ci:'):f.text
            for tag in ('balanceSheet.valueOfItem','IncomeStatement.valueOfItem')
            for f in root.findall('.//de-gaap-ci:'+BASE+tag,NS) if f.text is not None}

class BalanceCatalog(unittest.TestCase):
    def test_each_added_code_both_years_and_zero_closing_movements(self):
        for year in (2025,2026):
            seen=set()
            for i,spec in enumerate(SPECS):
                for release in (False,True):
                    with self.subTest(year=year,index=i,release=release):
                        values=fixture(year,i,release);before=copy.deepcopy(values)
                        mapping={'code-'+code:spec['target'] for code in spec['codes']} if spec.get('requires_review') else {}
                        if mapping:
                            unreviewed=automatic_mapping(values)
                            self.assertFalse(set(mapping)&set(unreviewed))
                            with self.assertRaises(ValueError):build(values)
                        auto=automatic_mapping(values,mapping)|mapping
                        for code in spec['codes']:
                            seen.add(code);key='code-'+code
                            self.assertEqual(auto[key],spec['target'])
                            self.assertIn(spec['target'],SUPPORTED[spec['group']])
                            with self.assertRaisesRegex(ValueError,'Kontenbedeutung'):
                                automatic_mapping(values,{key:'bs.ass.currAss.cashEquiv.bank'})
                        expected=sum(1234+j for j in range(len(spec['codes']))) if not release else 0
                        package=build(values,mapping);out=facts(package)
                        self.assertEqual(out[spec['target']],money(expected))
                        self.assertEqual(out['bs.ass'],money(values['tax']['asset_total_cents']))
                        self.assertEqual(out['bs.eqLiab'],money(values['tax']['passive_total_cents']))
                        self.assertEqual(out['is.netIncome'],'0.00')
                        self.assertEqual(values,before)
                        self.assertEqual(package['transmission'],'unavailable')
            self.assertEqual(len(seen),232)

    def test_official_target_is_permitted_in_both_years(self):
        b=EbilanzBuilder()
        for year in (2025,2026):
            for spec in SPECS:self.assertTrue(b.permitted(spec['target'],year))
        self.assertFalse(b.permitted('bs.ass.currAss.receiv.other.silent',2026))

    def test_zero_result_does_not_bypass_missing_accounts_or_reconciliation(self):
        original=fixture(2026,0,True)
        for field in ('annual_result_cents','asset_total_cents','passive_total_cents'):
            values=copy.deepcopy(original);values['tax'][field]+=1
            values['sha256']=digest({k:v for k,v in values.items() if k not in ('sha256','transmission')})
            with self.subTest(field=field),self.assertRaises(ValueError):build(values)
        # A zero ending balance still has movements and cannot omit its account.
        values=copy.deepcopy(original);key='code-10010'
        values['tax']['accounts'][key]['skr_code']='15000'
        values['tax']['accounts'][key]['group']='other_assets'
        values['sha256']=digest({k:v for k,v in values.items() if k not in ('sha256','transmission')})
        with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):build(values)

    def test_release_audit_reports_every_unmapped_original_account(self):
        php=os.environ['VEREIN_FIXTURE_PHP']
        p=subprocess.run([php,str(ROOT/'tools/balance-mapping-audit.php')],capture_output=True,text=True,encoding='utf8',timeout=60)
        self.assertFalse(p.stderr.strip());report=json.loads(p.stdout)
        self.assertFalse(report['production_authorized'])
        any_missing=False
        for year,data in report['years'].items():
            self.assertEqual(data['bookable'],data['automatic']+data['conditional']+data['missing'])
            self.assertEqual(len(data['missing_accounts']),data['missing'])
            self.assertEqual(len(data['conditional_accounts']),data['conditional'])
            missing={row['code'] for row in data['missing_accounts']}
            conditional={row['code'] for row in data['conditional_accounts']}
            self.assertFalse(missing&conditional)
            self.assertFalse(missing&{code for s in SPECS for code in s['codes']})
            any_missing |= bool(missing)
        self.assertEqual(report['mapping_complete'],not any_missing)
        self.assertEqual(p.returncode,2 if any_missing else 0)

if __name__=='__main__':unittest.main()
