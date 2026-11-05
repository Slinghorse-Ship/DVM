"""Dated gross assets and independently rebuilt tax adjustments, never transmission."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest
import xml.etree.ElementTree as ET

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('period_base',HERE/'ebilanz-period-test.py')
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
from ebilanz_period_assets import rebuild
from ebilanz_mapping import automatic_mapping
from ebilanz_builder import EbilanzBuilder, NS

def fixture(variant='book'):
    php=Path(os.environ['VEREIN_FIXTURE_PHP'])
    if not php.is_absolute() or not php.is_file():raise ValueError('Native PHP required')
    result=subprocess.run([str(php),str(HERE/'fixtures/ebilanz-period-assets.php'),variant],capture_output=True,text=True,encoding='utf8',timeout=30)
    if result.returncode or result.stderr.strip():raise RuntimeError(result.stdout+result.stderr)
    return json.loads(result.stdout)

def inputs(values):
    _,profile=base.cases.inputs(values)
    changed=bool(values['period_source']['review']['asset_tax_plans'])
    profile['asset_values_unchanged']=not changed;profile['asset_tax_values_reviewed']=changed
    automatic=automatic_mapping(values)
    # The source is general cost 63000, not a manually invented administration
    # subcategory. Newly reviewed original accounts must use their real meaning.
    if 'expense' in values['accounts']:
        if values['accounts']['expense']['skr_code']!='63000' or automatic.get('expense')!='is.netIncome.regular.operatingTC.otherCost.otherOrdinary':
            raise ValueError('Synthetic general cost must use the reviewed original account.')
    mapping={k:v for k,v in {'receivables':'bs.ass.currAss.receiv.trade.other'}.items()
             if k in values['accounts'] and k not in automatic}
    missing=set(values['tax']['accounts'])-set(automatic)-set(mapping)
    if missing:raise ValueError('Fixture accounts require reviewed mapping: '+str(sorted(missing)))
    return mapping,profile

def build(values):
    mapping,profile=inputs(values)
    return EbilanzBuilder().build(values,mapping,profile,'12345')

class DatedAssets(unittest.TestCase):
    def test_general_cost_is_automatic_and_cannot_be_overridden(self):
        v=fixture('book');mapping,p=inputs(v)
        self.assertNotIn('expense',mapping)
        self.assertEqual(automatic_mapping(v)['expense'],'is.netIncome.regular.operatingTC.otherCost.otherOrdinary')
        with self.assertRaisesRegex(ValueError,'Kontenbedeutung'):
            EbilanzBuilder().build(v,{'expense':'is.netIncome.regular.operatingTC.otherCost.administration'},p,'12345')
    def test_fully_written_off_gwg_and_degressive_keep_gross_asset_statement(self):
        for kind in ('gwg','fully','degressive'):
            for suffix in ('','-successor'):
                with self.subTest(variant=kind+suffix):
                    v=fixture(kind+suffix);p=build(v);s=v['asset_tax']['tax_schedule']['totals']
                    self.assertEqual(p['asset_items'],1)
                    self.assertGreater(p['asset_fact_count'],0)
                    self.assertGreater(s['cost_closing_cents'],0)
                    if kind!='degressive':self.assertEqual(s['book_closing_cents'],0)
                    if suffix:self.assertEqual(s['additions_cents'],0)
    def test_book_and_tax_first_and_short_successor(self):
        for variant in ('book','tax','book-successor','tax-successor'):
            with self.subTest(variant=variant):
                v=fixture(variant);checked=rebuild(v);package=build(v)
                self.assertEqual(package['asset_items'],7)
                self.assertGreater(package['asset_fact_count'],0)
                self.assertEqual(checked['proof'],v['asset_tax'])
                self.assertEqual(package['period'],v['period'])
                self.assertEqual(package['transmission'],'unavailable')
                self.assertEqual(package['environment'],'test')
                if variant.endswith('successor'):
                    self.assertEqual(checked['proof']['tax_schedule']['totals']['book_disposals_cents'],0)
                    for key,item in v['asset_tax']['tax_schedule']['items'].items():
                        for name in ('cost','accumulated','book'):
                            self.assertEqual(item['schedule'][name+'_opening_cents'],v['period_source']['previous']['asset_tax']['tax_schedule']['items'][key]['schedule'][name+'_closing_cents'])
    def test_tax_gain_can_become_loss_without_changing_original_sale(self):
        v=fixture('tax');original=copy.deepcopy(v['period_source']['source']);p=rebuild(v)['proof']
        self.assertTrue(p['items']['switch']['sale']['book']['gain'])
        self.assertFalse(p['items']['switch']['sale']['tax']['gain'])
        self.assertEqual(v['result_difference_cents'],17500)
        self.assertEqual(v['period_source']['source'],original)
        self.assertEqual(build(v)['asset_items'],7)
        root=ET.fromstring(build(v)['xml'])
        names=[e.text for e in root.findall('.//de-gaap-ci:detailedInformation.accountbalances.accountDescription',NS)]
        self.assertTrue(any('Steuerlicher Anlagenverkauf - ' in n for n in names))
        self.assertTrue(any('·' in a['name'] for a in v['tax']['accounts'].values()))
    def test_resealed_asset_and_tax_tampering_is_rejected(self):
        for kind in ('cost','tax','component','sale','plan','posting','missing'):
            v=fixture('tax');item=v['period_source']['source']['asset_schedule']['items']['partial']
            if kind=='cost':item['schedule']['cost_closing_cents']+=1
            if kind=='tax':v['asset_tax']['tax_schedule']['items']['linear']['schedule']['depreciation_cents']+=1
            if kind=='component':item['disposal']['allocation']['cost_cents']+=1
            if kind=='sale':v['period_source']['source']['asset_schedule']['items']['switch']['sale_values']['net_cents']+=1
            if kind=='plan':item['plan']['months']=72
            if kind=='posting':v['period_source']['source']['asset_schedule']['items']['switch']['sale_posting']['entry_id']='f'*32
            if kind=='missing':del v['period_source']['source']['asset_schedule']['items']['linear']
            base.seal(v)
            with self.subTest(kind=kind),self.assertRaises(ValueError):build(v)
    def test_tax_confirmation_and_retained_previous_required(self):
        v=fixture('tax');mapping,profile=inputs(v);profile['asset_tax_values_reviewed']=False
        with self.assertRaises(ValueError):EbilanzBuilder().build(v,mapping,profile,'12345')
        v=fixture('tax-successor');v['period_source']['previous']=None;base.seal(v)
        with self.assertRaises(ValueError):build(v)
    def test_sphere_transfer_cannot_disappear_in_flat_asset_export(self):
        v=fixture('book')
        v['period_source']['source']['asset_schedule']['items']['linear']['sphere_change']={'schema':1}
        base.seal(v)
        with self.assertRaisesRegex(ValueError,'Bereichswechsel'):rebuild(v)
    def test_every_asset_context_uses_real_period(self):
        v=fixture('tax');root=ET.fromstring(build(v)['xml'])
        for context in root.findall('.//xbrli:context',NS):
            p=context.find('xbrli:period',NS)
            for k,field in [('start','startDate'),('end','endDate'),('end','instant')]:
                el=p.find('xbrli:'+field,NS)
                if el is not None:self.assertEqual(el.text,v['period'][k])

if __name__=='__main__':unittest.main()
