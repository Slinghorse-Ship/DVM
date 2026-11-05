"""Synthetic different tax lives: independent generation and adversarial inputs."""
import copy
import importlib.util
from pathlib import Path
import unittest

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('base_tests',HERE/'ebilanz-builder-test.py')
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)


class TaxAssets(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixtures={case:base.cases.fixture('asset-tax-'+case) for case in ('slower','faster','prior','software','cents','fully','carry',
            'scrap-same','scrap-prior','scrap-faster','scrap-book-zero','scrap-tax-zero','scrap-cents','scrap-after',
            'sale-cross','sale-gain','sale-loss','sale-faster','sale-book-zero','sale-tax-zero','sale-cents','sale-after','sale-even','sale-vat-zero',
            'mixed-cross','mixed-gain','mixed-loss','mixed-zero','mixed-faster','mixed-cents','mixed-after')}
    def setUp(self):self.values=copy.deepcopy(self.fixtures['slower'])
    def build(self):
        base.seal(self.values);mapping,profile=base.cases.inputs(self.values)
        return base.EbilanzBuilder().build(self.values,mapping,profile,'12345')
    def test_all_supported_cases(self):
        for case,values in self.fixtures.items():
            with self.subTest(case=case):
                self.values=copy.deepcopy(values);result=self.build()
                self.assertEqual(result['asset_items'],len(values['asset_schedule']['items']));self.assertEqual(result['environment'],'test')
                self.assertEqual(result['asset_schedule_sha256'],base.hashlib.sha256(base.json.dumps(values['asset_tax']['tax_schedule'],ensure_ascii=False,separators=(',',':')).encode()).hexdigest())
    def test_automatic_mapping_tax_depreciation(self):
        for values in self.fixtures.values():
            mapping=base.automatic_mapping(values)
            dep='software-depreciation' if 'software-0' in values['accounts'] else 'equipment-depreciation'
            if values['tax']['accounts'][dep]['amount_cents']:self.assertIn(dep,mapping)
    def test_disposal_proof_and_value_tampering(self):
        for field,value in [('disposal_difference_cents',1),('disposal_account','expense')]:
            self.values=copy.deepcopy(self.fixtures['scrap-same']);self.values['asset_tax']['items']['asset-0'][field]=value
            with self.assertRaises(ValueError):self.build()
        for field in ('book_disposals_cents','accumulated_disposals_cents','disposals_cents'):
            self.values=copy.deepcopy(self.fixtures['scrap-same']);self.values['asset_tax']['tax_schedule']['items']['asset-0'][field]+=1
            with self.assertRaises(ValueError):self.build()
    def test_disposal_original_evidence_validated_independently(self):
        for field,value in [('no_proceeds',False),('complete',False),('kind','gift'),('date','2024-01-01')]:
            self.values=copy.deepcopy(self.fixtures['scrap-same'])
            original=self.values['asset_schedule'];item=original['items']['asset-0'];item['disposal'][field]=value
            evidence=self.values['asset_tax'];evidence['book_schedule_sha256']=base.hashlib.sha256(base.json.dumps(original,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
            evidence['items']['asset-0']['book_item_sha256']=base.hashlib.sha256(base.json.dumps(item,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
            evidence['tax_schedule']['items']['asset-0']['disposal'][field]=value
            with self.assertRaises(ValueError):self.build()
    def test_rest_value_entry_cannot_be_removed_or_reclassified(self):
        self.values=copy.deepcopy(self.fixtures['scrap-same']);self.values['current_adjustments'].pop()
        with self.assertRaises(ValueError):self.build()
        self.values=copy.deepcopy(self.fixtures['scrap-same']);self.values['current_adjustments'][-1]['id']='manual-scrap'
        with self.assertRaises(ValueError):self.build()
    def test_no_resurrection_after_scrapping(self):
        self.values=copy.deepcopy(self.fixtures['scrap-after']);self.build()
        self.assertEqual(self.values['current_adjustments'],[])
        self.values['asset_tax']['tax_schedule']['items']['asset-0']['book_closing_cents']=1
        with self.assertRaises(ValueError):self.build()
    def test_sale_values_and_accounts_are_recomputed(self):
        for part,field,value in [('tax','book_cents',1),('book','gain',1),('tax','gain',True),
                ('tax','sale_code','48450'),('tax','net_cents',0),('tax','tax_cents',True),('accounts','68950','expense')]:
            with self.subTest(part=part,field=field):
                self.values=copy.deepcopy(self.fixtures['sale-cross'])
                self.values['asset_tax']['items']['asset-0']['sale'][part][field]=value
                with self.assertRaises(ValueError):self.build()
    def test_frozen_disposal_account_binding_is_validated_not_just_its_hash(self):
        for mode in ('missing','empty','same-code','wrong-code','unknown','wrong-type'):
            with self.subTest(mode=mode):
                self.values=copy.deepcopy(self.fixtures['mixed-cross']);original=self.values['asset_schedule'];item=original['items']['scrapped']
                if mode=='missing':item.pop('disposal_accounts')
                elif mode=='empty':item['disposal_accounts']={}
                elif mode=='same-code':item['disposal_accounts']['68950']=original['items']['sold']['disposal_accounts']['68950']
                elif mode=='wrong-code':item['disposal_accounts']['68950']='expense'
                elif mode=='unknown':item['disposal_accounts']['12345']='expense'
                else:item['disposal_accounts']['68950']=True
                evidence=self.values['asset_tax']
                evidence['book_schedule_sha256']=base.hashlib.sha256(base.json.dumps(original,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
                evidence['items']['scrapped']['book_item_sha256']=base.hashlib.sha256(base.json.dumps(item,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
                with self.assertRaises(ValueError):self.build()
    def test_tax_correction_cannot_move_rest_value_between_same_skr_codes(self):
        self.values=copy.deepcopy(self.fixtures['mixed-cross'])
        old=self.values['asset_schedule']['items']['scrapped']['disposal_accounts']['68950']
        wrong=self.values['asset_schedule']['items']['sold']['disposal_accounts']['68950']
        for entry in self.values['current_adjustments']:
            if entry['id'].startswith('asset-scrap-'):
                for line in entry['lines']:
                    if line['account']==old:line['account']=wrong
        with self.assertRaises(ValueError):self.build()
    def test_sale_reclassification_cannot_be_removed_relabelled_or_reversed(self):
        for mode in ('remove','relabel','reverse','extra'):
            self.values=copy.deepcopy(self.fixtures['sale-cross']);entries=self.values['current_adjustments']
            if mode=='remove':entries.pop()
            elif mode=='relabel':entries[-1]['id']='manual-sale'
            elif mode=='extra':entries.append(dict(copy.deepcopy(entries[-1]),id='manual-sale'))
            else:
                for line in entries[-1]['lines']:line['debit_cents'],line['credit_cents']=line['credit_cents'],line['debit_cents']
            with self.assertRaises(ValueError):self.build()
    def test_sale_original_evidence_is_not_trusted_even_with_new_hash(self):
        for field,value in [('complete',False),('tax_cents',0),('rate',7),('sale_hash',''),('kind','gift')]:
            self.values=copy.deepcopy(self.fixtures['sale-cross']);original=self.values['asset_schedule'];item=original['items']['asset-0']
            item['disposal'][field]=value;evidence=self.values['asset_tax']
            evidence['book_schedule_sha256']=base.hashlib.sha256(base.json.dumps(original,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
            evidence['items']['asset-0']['book_item_sha256']=base.hashlib.sha256(base.json.dumps(item,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
            evidence['tax_schedule']['items']['asset-0']['disposal'][field]=value
            with self.assertRaises(ValueError):self.build()
    def test_no_resurrection_or_sale_rebooking_next_year(self):
        self.values=copy.deepcopy(self.fixtures['sale-after']);self.build()
        self.assertEqual(self.values['current_adjustments'],[])
        self.assertIsNone(self.values['asset_tax']['items']['asset-0']['sale'])
        self.values['asset_tax']['tax_schedule']['items']['asset-0']['book_closing_cents']=1
        with self.assertRaises(ValueError):self.build()
    def test_confirmations(self):
        for field,value in [('asset_values_unchanged',True),('asset_tax_values_reviewed',False),('asset_scope','all')]:
            with self.subTest(field=field):
                mapping,profile=base.cases.inputs(self.values);profile[field]=value
                with self.assertRaises(ValueError):base.EbilanzBuilder().build(self.values,mapping,profile,'12345')
    def test_plan_tampering(self):
        for field,value in [('months',36),('months',True),('confirmed',False),('reason',''),('depreciation_account','expense')]:
            with self.subTest(field=field,value=value):
                self.setUp();self.values['asset_tax']['items']['asset-0']['plan'][field]=value
                with self.assertRaises(ValueError):self.build()
    def test_schedule_tampering(self):
        for field in ('months','book_closing_cents','cost_closing_cents','residual_cents','depreciation_cents'):
            with self.subTest(field=field):
                self.setUp();self.values['asset_tax']['tax_schedule']['items']['asset-0'][field]+=1
                with self.assertRaises(ValueError):self.build()
    def test_source_hashes(self):
        self.values['asset_tax']['book_schedule_sha256']='0'*64
        with self.assertRaises(ValueError):self.build()
    def test_missing_generated_entry(self):
        self.values['current_adjustments']=[]
        with self.assertRaises(ValueError):self.build()
    def test_duplicate_generated_entry(self):
        self.values['current_adjustments']*=2
        with self.assertRaises(ValueError):self.build()
    def test_manual_overlap(self):
        entry=copy.deepcopy(self.values['current_adjustments'][0]);entry['id']='manual';self.values['current_adjustments'].append(entry)
        with self.assertRaises(ValueError):self.build()
    def test_reversed_entry(self):
        for line in self.values['current_adjustments'][0]['lines']:line['debit_cents'],line['credit_cents']=line['credit_cents'],line['debit_cents']
        with self.assertRaises(ValueError):self.build()
    def test_changed_ledger(self):
        self.values['tax']['accounts']['equipment-0']['debit_cents']+=1
        with self.assertRaises(ValueError):self.build()
    def test_changed_comparison(self):
        self.values['accounts']['equipment-0']['difference_cents']+=1
        with self.assertRaises(ValueError):self.build()
    def test_changed_carry(self):
        self.values=copy.deepcopy(self.fixtures['carry']);self.values['opening_adjustments'][0]['lines'][0]['credit_cents']+=1
        with self.assertRaises(ValueError):self.build()
    def test_unknown_evidence(self):
        self.values['asset_tax']['extra']='unsupported'
        with self.assertRaises(ValueError):self.build()


if __name__=='__main__':unittest.main()
