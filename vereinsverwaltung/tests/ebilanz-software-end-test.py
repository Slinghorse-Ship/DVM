"""Purchased license termination: independent XBRL calculations and hostile evidence."""
import copy
import importlib.util
from pathlib import Path
import unittest

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('base_tests',HERE/'ebilanz-builder-test.py')
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
from ebilanz_assets import SOFTWARE_END, SCRAPPING


class SoftwareEnd(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixtures={case:base.cases.fixture('asset-'+case) for case in (
            'software-end','software-end-prior','software-end-fully','software-end-carry',
            'tax-software-end-same','tax-software-end-prior','tax-software-end-faster',
            'tax-software-end-book-zero','tax-software-end-tax-zero','tax-software-end-cents','tax-software-end-after')}

    def build(self, values):
        base.seal(values);mapping,profile=base.cases.inputs(values)
        return base.EbilanzBuilder().build(values,mapping,profile,'12345')

    def test_all_cases_and_automatic_intangible_mapping(self):
        for case,values in self.fixtures.items():
            with self.subTest(case=case):
                self.assertEqual(self.build(copy.deepcopy(values))['asset_items'],1)
                self.assertEqual(values['book']['accounts']['scrapping']['skr_code'],'68960')
                mapping=base.automatic_mapping(values)
                if values['book']['accounts']['scrapping']['amount_cents'] or values['tax']['accounts']['scrapping']['amount_cents']:
                    self.assertEqual(mapping['scrapping'],SOFTWARE_END)

    def tamper(self, case, mutate):
        values=copy.deepcopy(self.fixtures[case]);schedule=values['asset_schedule'];item=schedule['items']['asset-0']
        mutate(item)
        if 'asset_tax' in values:
            evidence=values['asset_tax']
            digest=lambda v:base.hashlib.sha256(base.json.dumps(v,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
            evidence['book_schedule_sha256']=digest(schedule);evidence['items']['asset-0']['book_item_sha256']=digest(item)
            evidence['tax_schedule']['items']['asset-0']['disposal']=copy.deepcopy(item['disposal'])
        with self.assertRaises(ValueError):self.build(values)

    def test_explicit_rights_and_no_proceeds_cannot_be_forged(self):
        for case in ('software-end','tax-software-end-same'):
            for key,value in (('rights_ended',False),('rights_ended',1),('no_proceeds',False),('complete',False),
                              ('kind','scrap'),('kind','gift'),('kind','sale'),('date','2024-01-01'),('compensation_cents',1)):
                with self.subTest(case=case,field=key,value=value):
                    self.tamper(case,lambda item:item['disposal'].__setitem__(key,value))
            self.tamper(case,lambda item:item['disposal'].pop('rights_ended'))

    def test_no_switch_to_equipment_or_self_created_software(self):
        for code in ('06300','06500','01440','06700'):
            self.tamper('software-end',lambda item:item.__setitem__('skr_code',code))

    def test_account_identity_and_manual_mapping_cannot_hide_wrong_cost_type(self):
        self.tamper('tax-software-end-same',lambda item:item.__setitem__('disposal_accounts',{'68950':'scrapping'}))
        values=copy.deepcopy(self.fixtures['software-end']);base.seal(values)
        mapping,profile=base.cases.inputs(values);mapping['scrapping']=SCRAPPING
        with self.assertRaises(ValueError):base.EbilanzBuilder().build(values,mapping,profile,'12345')

    def test_zero_values_and_next_year(self):
        for case in ('software-end-carry','tax-software-end-after'):
            values=self.fixtures[case]
            self.assertEqual(sum(values['asset_schedule']['totals'].values()),0)
            self.assertEqual(values['current_adjustments'],[])
            self.tamper(case,lambda item:item.__setitem__('book_closing_cents',1))
        values=self.fixtures['tax-software-end-book-zero']
        self.assertEqual(values['asset_schedule']['items']['asset-0']['book_disposals_cents'],0)
        self.assertGreater(values['asset_tax']['tax_schedule']['items']['asset-0']['book_disposals_cents'],0)

    def test_tax_entry_cannot_be_removed_or_altered(self):
        for mode in ('remove','relabel','account','amount'):
            values=copy.deepcopy(self.fixtures['tax-software-end-same'])
            entry=values['current_adjustments'][-1]
            if mode=='remove':values['current_adjustments'].pop()
            elif mode=='relabel':entry['reason']='Steuerlicher Restwert bei Ausmusterung'
            elif mode=='account':entry['lines'][-1]['account']='expense'
            else:entry['lines'][-1]['debit_cents']+=1
            with self.assertRaises(ValueError):self.build(values)

    def test_simultaneous_sale_scrap_and_software_keep_separate_accounts(self):
        for case in ('cross','zero','faster','cents','after'):
            with self.subTest(case=case):
                values=base.cases.fixture('asset-tax-mixed-software-'+case)
                self.assertEqual(self.build(values)['asset_items'],3)
                item=values['asset_schedule']['items']['software']
                self.assertEqual(item['disposal_accounts'],{'68960':'software-scrapping'})
                tax=values['asset_tax']['tax_schedule']['items']['software']
                self.assertEqual(values['tax']['accounts']['software-scrapping']['amount_cents'],tax['book_disposals_cents'])
                if case!='after':
                    entry=next(e for e in values['current_adjustments'] if e['reason'].startswith('Steuerlicher Restwert bei Lizenzende:'))
                    entry['lines'][-1]['account']='scrapped-scrapping'
                    with self.assertRaises(ValueError):self.build(values)


if __name__=='__main__':unittest.main()
