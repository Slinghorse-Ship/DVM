import json,os,subprocess,sys,unittest
from pathlib import Path
A=Path(__file__).resolve().parents[1];sys.path.insert(0,str(A/'tools'))
from ebilanz_financial_reserve_xml import assert_exportable
PHP=os.environ['VEREIN_FIXTURE_PHP']
PREFIX='is.netIncome.regular.fin.netParticipation.amortFinanc.'
SUFFIXES=['financialsExcept.participCorp.domesticCorp','financialsExcept.participCorp.foreignCorp','financialsExcept.participCorp.other','other','financialsExcept.loansSharehRelPart.domestic','financialsExcept.loansSharehRelPart.foreign','financialsExcept.loansSharehRelPart.other']
TARGETS={PREFIX+s:s for s in SUFFIXES}|{'is.netIncome.regular.operatingTC.otherCost.miscellaneous':'wrong section'}

class ReserveXml(unittest.TestCase):
    def php(self,account):
        p=subprocess.run([PHP,str(A/'tests/financial-reserve-xml.php')],input=json.dumps(dict(account=account,targets=TARGETS)),encoding='utf8',capture_output=True,check=True)
        return json.loads(p.stdout)
    def test_primary_taxonomy_targets_exist_in_all_four_versions(self):
        for version in ('6.7','6.8','6.9','6.10'):
            metadata=json.loads((A/f'resources/ebilanz/taxonomy-{version}.json').read_text(encoding='utf8'))
            for suffix in SUFFIXES:
                row=metadata['concepts']['de-gaap-ci'][PREFIX+suffix]
                self.assertFalse(row['abstract']);self.assertEqual(row['period'],'duration');self.assertEqual(row['refs']['legalFormKSt'],['true'])
    def test_choices_do_not_mix_operating_costs_or_loans_into_share_reinvestment(self):
        for code,n in (('72500',4),('72550',7)):
            row=self.php(dict(skr_code=code,group='expense',amount_cents=0));self.assertEqual(len(row['choices']),n);self.assertNotIn('is.netIncome.regular.operatingTC.otherCost.miscellaneous',row['choices'])
            row=self.php(dict(skr_code=code,group='revenue',amount_cents=0));self.assertEqual(row['choices'],{})
    def test_missing_generic_menu_entries_do_not_hide_official_choices(self):
        from ebilanz_builder import SUPPORTED
        for code,n in (('72500',4),('72550',7)):
            p=subprocess.run([PHP,str(A/'tests/financial-reserve-xml.php')],input=json.dumps(dict(account=dict(skr_code=code,group='expense',amount_cents=0),targets={})),encoding='utf8',capture_output=True,check=True)
            choices=json.loads(p.stdout)['choices'];self.assertEqual(len(choices),n);self.assertTrue(set(choices)<=SUPPORTED['expense'])
    def test_active_unqualified_amount_cannot_be_exported_by_explicit_choice(self):
        for code in ('72500','72550'):
            for amount in (1,-1,6000000,True,'0',None):
                account=dict(skr_code=code,group='expense',amount_cents=amount)
                with self.subTest(code=code,amount=amount):
                    self.assertFalse(self.php(account)['valid'])
                    with self.assertRaises(ValueError):assert_exportable({'original':account})
            account=dict(skr_code=code,group='expense',amount_cents=0);self.assertTrue(self.php(account)['valid']);assert_exportable({'unused':account})
        account=dict(skr_code='72040',group='expense',amount_cents=100);self.assertTrue(self.php(account)['valid']);assert_exportable({'existing':account})

if __name__=='__main__':unittest.main()
