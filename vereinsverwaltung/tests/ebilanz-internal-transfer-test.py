"""Independent cross-area funding checks; no certificate or network."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest

ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_club_scope import rebuild
from ebilanz_builder import EbilanzBuilder

def load(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/'tests'/('ebilanz-'+name+'-test.py'))
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod

class InternalTransfer(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.profiles={name:load(name) for name in ('club-scope','whole-club','combined-club')}
        cls.base=cls.profiles['club-scope'].fixture('transfer-cross')

    def test_exact_reconstruction_and_xbrl(self):
        for name,c in self.profiles.items():
            for variant in ('transfer-cross','transfer-taxable','transfer-cross-reverse','transfer-taxable-reverse'):
                with self.subTest(profile=name,variant=variant):
                    v=c.fixture(variant);mapping,profile=c.inputs(v)
                    result=EbilanzBuilder().build(v,mapping,profile,'12345')
                    self.assertEqual(result['transmission'],'unavailable')
                    self.assertEqual(result,EbilanzBuilder().build(v,mapping,profile,'12345'))
                    if name!='whole-club':
                        p=v['taxable'] if name=='combined-club' else v
                        for key,value in rebuild(p['club_scope']).items():self.assertEqual(p[key],value)
                    if name=='combined-club':
                        for account in v['reconciliation']['accounts'].values():
                            for field,r in account.items():
                                self.assertEqual(r['whole'],r['taxable_book']+r['other_areas']-r['internal_elimination'])
                                if field in ('debit_cents','credit_cents'):self.assertGreaterEqual(r['other_areas'],0)
                    self.assertNotIn('Synthetic final allocation of funds',result['xml'])

    def test_reject_tampered_transfers(self):
        voucher='a'*32
        for value in (True,1.0,'334',0,1,334,-335,100000000001):
            with self.subTest(value=value):
                s=copy.deepcopy(self.base['club_scope']);s['allocation']['transfers'][voucher]['parts']['food']=value
                with self.assertRaises(ValueError):rebuild(s)

    def test_no_third_party_debt_or_profit(self):
        for account in ('cash','expense','revenue','liabilities','foreign'):
            s=copy.deepcopy(self.base['club_scope']);s['allocation']['transfers']['a'*32]['account']=account
            with self.assertRaises(ValueError):rebuild(s)

    def test_no_downgrade_or_invented_opening(self):
        for action in ('downgrade','empty','opening','foreign','missing-source','no-proof'):
            s=copy.deepcopy(self.base['club_scope']);a=s['allocation'];t=a['transfers']
            if action=='downgrade':s['schema']=2
            elif action=='empty':a['transfers']={}
            elif action=='opening':t['opening']=t.pop('a'*32)
            elif action=='foreign':t['a'*32]['parts']['foreign']=1
            elif action=='missing-source':del a['allocations']['a'*32+':1']
            else:t['a'*32]['reason']=''
            with self.subTest(action=action),self.assertRaises(ValueError):rebuild(s)

if __name__=='__main__':unittest.main()
