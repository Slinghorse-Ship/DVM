"""Original PHP journal -> automatic mapping -> XML in both reporting profiles.

No database, no network, no certificate or transmission. Names intentionally
contain misspellings: verified account meaning must not depend on free text.
"""
import copy,json,os,subprocess,sys,unittest
from pathlib import Path
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import datev_years as d
from ebilanz_builder import EbilanzBuilder,NS,BASE,money,SUPPORTED
from ebilanz_sales import CONTRA
from ebilanz_mapping import automatic_mapping

REVIEW='operating-r14'
SPECS=json.loads((ROOT/f'resources/accounting/reviews/{REVIEW}.json').read_text(encoding='utf8'))['years']
def fixture(year,index,mode='normal',whole=False):
    php=Path(os.environ['VEREIN_FIXTURE_PHP'])
    if not php.is_absolute() or not php.is_file():raise RuntimeError('Explicit test PHP required.')
    p=subprocess.run([str(php),str(ROOT/'tests/fixtures/ebilanz-operating-extended.php'),str(year),str(index),mode,'whole' if whole else 'single',REVIEW],capture_output=True,encoding='utf8',timeout=60)
    if p.returncode or p.stderr.strip():raise RuntimeError(p.stdout+p.stderr)
    return json.loads(p.stdout)

def profile(values,whole=False):
    return {'kind':'whole_taxable_club' if whole else 'single_taxable_business',
        'source_scope':'complete_club_ledger' if whole else 'separate_business_ledger',
        'scope_confirmed':True,'tax_privileged':not whole,'asset_scope':'whole_club' if whole else 'single_reported_business',
        'asset_values_unchanged':True,'no_retained_fixed_assets':True,'tax_values_sha256':values['sha256'],
        'business_name':'Synthetic operating coverage','supplier':'Synthetic test',
        'company':{'tax_number':'5192050001276','name':'Synthetic club','street':'Testweg','house_number':'1','postal_code':'11111','city':'Teststadt','country':'Deutschland'}}

def build(values,mapping=None,whole=False):return EbilanzBuilder().build(values,mapping or {},profile(values,whole),'12345')

def facts(package,whole=False):
    root=ET.fromstring(package['xml'])
    if whole:return {n.tag.split('}',1)[-1]:n.text for n in root.iter() if n.tag.startswith('{'+NS['de-gaap-ci']+'}') and n.text is not None}
    contexts={c.get('id'):c for c in root.findall('.//xbrli:context',NS)}
    return {contexts[f.get('contextRef')].find('.//xbrldi:explicitMember',NS).text.removeprefix('de-gaap-ci:'):f.text
        for tag in ('balanceSheet.valueOfItem','IncomeStatement.valueOfItem') for f in root.findall('.//de-gaap-ci:'+BASE+tag,NS) if f.text is not None}

class OperatingExtended(unittest.TestCase):
    def test_sources_are_exact_and_all_choices_are_official_in_both_profiles(self):
        b=EbilanzBuilder();model,_=d.read_json(ROOT/'resources/accounting/purposes.json')
        for edition in SPECS:
            year=edition['year'];data,h=d.read_json(ROOT/f'data/skr42-{year}.json');rows=d.catalog(data)
            self.assertEqual(edition['source'],d.source(data,h,'balance'))
            seen=set()
            for spec in edition['groups']:
                for item in spec['sources']:
                    self.assertNotIn(item['code'],seen);seen.add(item['code'])
                    self.assertEqual(item['semantic'],rows[item['code']]['semantic'])
                    self.assertEqual(item['signature'],rows[item['code']]['signature'])
                    self.assertTrue(rows[item['code']]['bookable'])
                for whole in (False,True):
                    b.whole_club=whole
                    for target in spec['choices']:
                        self.assertIn(target,SUPPORTED[spec['group']]);self.assertTrue(b.permitted(target,year))

    def test_every_added_account_normal_negative_and_cancelled(self):
        for edition in SPECS:
            year=edition['year']
            for index,spec in enumerate(edition['groups']):
                for whole in (False,True):
                    for mode in ('normal','negative','cancelled'):
                        with self.subTest(year=year,index=index,whole=whole,mode=mode):
                            values=fixture(year,index,mode,whole);before=copy.deepcopy(values)
                            mapping={'code-'+row['code']:spec['choices'][0] for row in spec['sources']} if spec['conditional'] else {}
                            auto=automatic_mapping(values);t=spec['choices'][0] if mapping else spec.get('negative_target',spec['target']) if mode=='negative' else spec['target']
                            for row in spec['sources']:
                                key='code-'+row['code']
                                if spec['conditional']:self.assertNotIn(key,auto)
                                else:self.assertEqual(auto[key],t)
                            amount=sum(1234+i for i in range(len(spec['sources'])))
                            if mode=='negative':amount=-amount
                            if mode=='cancelled':amount=0
                            if t in CONTRA:amount=-amount
                            result=build(values,mapping,whole);out=facts(result,whole)
                            self.assertEqual(out[t],money(amount))
                            for k,v in [('bs.ass','asset_total_cents'),('bs.eqLiab','passive_total_cents'),('is.netIncome','annual_result_cents')]:self.assertEqual(out[k],money(values['tax'][v]))
                            self.assertEqual(values,before);self.assertEqual(result['transmission'],'unavailable')

    def test_each_detail_choice_and_missing_choice(self):
        for edition in SPECS:
            for index,spec in enumerate(edition['groups']):
                if not spec['conditional']:continue
                values=fixture(edition['year'],index);keys=['code-'+s['code'] for s in spec['sources']]
                with self.assertRaisesRegex(ValueError,'Zuordnung fehlt'):build(values)
                for target in spec['choices']:
                    with self.subTest(year=edition['year'],index=index,target=target):build(values,dict.fromkeys(keys,target))
                with self.assertRaisesRegex(ValueError,'Kontenbedeutung'):build(values,dict.fromkeys(keys,'is.netIncome.regular.operatingTC.otherCost.administration'))

    def test_annual_revisions_and_automatic_case_counts(self):
        model,_=d.read_json(ROOT/'resources/accounting/purposes.json');editions,catalogs=d.load_editions(model)
        for edition in editions:
            r=d.coverage(model,edition,catalogs[edition['year']][0])
            self.assertGreater(r['assigned'],870)
            self.assertFalse(r['complete'])
            base,_=d.read_json(ROOT/f"resources/accounting/years/{edition['year']}.json")
            self.assertEqual(len(d.bindings(base)),522)

if __name__=='__main__':unittest.main(verbosity=2)
