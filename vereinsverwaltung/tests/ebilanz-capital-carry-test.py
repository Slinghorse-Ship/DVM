"""Opposing club capital: independent reconstruction and exact next-year XBRL."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'tools'))
from ebilanz_builder import EbilanzBuilder,NS,BASE
from ebilanz_club_scope import rebuild,digest,calculate


def cases(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/'tests'/('ebilanz-'+name+'-test.py'))
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod


class CapitalCarry(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.profiles={name:cases(name) for name in ('whole-club','combined-club','club-scope')}
        cls.base=cls.profiles['club-scope'].fixture('carry-offset-zero')

    def test_profiles_reconcile_and_validate(self):
        for name,c in self.profiles.items():
            for case,expected in [('zero',0),('gain',-10000),('loss',10000),('cross',20000)]:
                with self.subTest(profile=name,case=case):
                    v=c.fixture('carry-offset-'+case);mapping,p=c.inputs(v)
                    result=EbilanzBuilder().build(v,mapping,p,'12345')
                    root=ET.fromstring(result['xml'])
                    if name!='whole-club':
                        part=v['taxable'] if name=='combined-club' else v
                        self.assertEqual(part['book']['accounts']['result']['opening_cents'],expected)
                        self.assertEqual(part['book']['annual_result_cents'],0)
                        rebuilt=rebuild(part['club_scope'])
                        for key,value in rebuilt.items():self.assertEqual(part[key],value)
                    self.assertEqual(result['transmission'],'unavailable')
                    self.assertEqual(result,EbilanzBuilder().build(v,mapping,p,'12345'))
                    self.assertIn('700000004',result['xml'])
                    self.assertEqual(root.tag,'{http://www.elster.de/elsterxml/schema/v11}Elster')

    def test_net_zero_account_retained_per_unit(self):
        s=self.base['club_scope'];r=rebuild(s,with_segments=True)
        self.assertNotIn('result',[l['account'] for l in s['source']['opening']])
        self.assertEqual(r['segments']['food']['calculation']['accounts']['result']['opening_cents'],-10000)
        self.assertEqual(r['segments']['shop']['calculation']['accounts']['result']['opening_cents'],10000)
        self.assertEqual(r['book']['accounts']['result']['opening_cents'],0)

    def test_bad_capital_inputs_blocked(self):
        for value in (True,1.0,'10000',0,10001,100000000001):
            with self.subTest(value=value):
                s=copy.deepcopy(self.base['club_scope']);s['allocation']['capital_carry']['result']['shop']=value
                with self.assertRaises(ValueError):rebuild(s)

    def test_only_equity_can_have_opposing_carry(self):
        for account in ('cash','liabilities','expense','foreign'):
            with self.subTest(account=account):
                s=copy.deepcopy(self.base['club_scope']);s['allocation']['capital_carry']={account:s['allocation']['capital_carry']['result']}
                with self.assertRaises(ValueError):rebuild(s)

    def test_previous_source_required(self):
        for where in ('previous','hash'):
            s=copy.deepcopy(self.base['club_scope'])
            if where=='previous':s['previous']=None
            else:s['source']['previous_sha256']=None
            with self.assertRaises(ValueError):rebuild(s)

    def test_schema_not_downgradable(self):
        for edit in ('schema','empty','unknown'):
            s=copy.deepcopy(self.base['club_scope'])
            if edit=='schema':s['schema']=1
            elif edit=='empty':s['allocation']['capital_carry']={}
            else:s['allocation']['other']=True
            with self.assertRaises(ValueError):rebuild(s)

    def test_rehashed_balanced_unit_shift_rejected_by_prior(self):
        # All aggregate sums stay identical; each altered unit still balances.
        # Only the independently rebuilt per-unit prior-year proof exposes this.
        s=copy.deepcopy(self.base['club_scope']);parts=rebuild(s,with_segments=True)['segments']
        allocations=s['allocation']['allocations'];capital=s['allocation']['capital_carry']
        cash_key=next(k for k in allocations if k.startswith('opening:') and s['source']['opening'][int(k.split(':')[1])]['account']=='cash')
        for unit,delta in [('food',100),('shop',-100)]:
            allocations[cash_key]['parts'][unit]+=delta;capital['result'][unit]-=delta
            for line in parts[unit]['opening']:
                if line['account']=='cash':line['debit_cents']+=delta
                if line['account']=='result':
                    net=line['debit_cents']-line['credit_cents']-delta
                    line.update(debit_cents=max(0,net),credit_cents=max(0,-net))
            parts[unit]['calculation']=calculate(s['source']['year'],s['source']['accounts'],parts[unit]['opening'],parts[unit]['entries'])
        built={'schema':2,'year':s['source']['year'],'journal_sha256':s['source']['closed_sha256'],'units':s['allocation']['units'],'allocations':allocations,'capital_carry':capital,'segments':parts,'missing':[],'issues':[],'complete':True,'tax_export_ready':False}
        s['allocation']['sha256']=digest(built)
        with self.assertRaises(ValueError):rebuild(s)


if __name__=='__main__':unittest.main()
