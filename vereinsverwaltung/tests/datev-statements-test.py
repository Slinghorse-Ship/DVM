"""PDF-column and annual-purpose regressions; synthetic/local sources only."""
import copy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))
import datev_years as d
import datev_statements as s
from datev_table import merged_intervals, statement_at, statement_cells

spec=importlib.util.spec_from_file_location('annual_tests',Path(__file__).with_name('datev-years-test.py'))
annual=importlib.util.module_from_spec(spec);spec.loader.exec_module(annual)


def position(label='Bankguthaben', **changes):
    return {'kind':'balance','label':label,'marks':[],'status':'printed','bbox':[50,120,110,300]}|changes


class StatementTests(unittest.TestCase):
    def test_borders_merge_only_when_continuous(self):
        self.assertEqual(merged_intervals([(120,130),(130.2,140),(145,160)]),[[120,140],[145,160]])

    def test_boxed_positions_do_not_leak_into_gaps_or_next_box(self):
        layout={'kind':'guv','cells':[{'label':'Sonstige Erträge','bbox':[50,120,110,300]},
                {'label':'Umsatzerlöse','bbox':[50,306,110,325]}], 'marks':[]}
        for top,wanted in [(121,'Sonstige Erträge'),(288,'Sonstige Erträge'),(301,''),(308,'Umsatzerlöse')]:
            self.assertEqual(statement_at(layout,{'top':top,'bottom':top+4})['label'],wanted)

    def test_marker_applies_to_own_row_not_neighbor(self):
        layout={'kind':'balance','cells':[],'marks':[{'text':'HB','top':151}]}
        self.assertEqual(statement_at(layout,{'top':150,'bottom':157})['marks'],['HB'])
        self.assertEqual(statement_at(layout,{'top':160,'bottom':167})['marks'],[])

    def test_overlapping_boxes_are_rejected(self):
        layout={'kind':'guv','cells':[{'label':'A','bbox':[50,120,110,200]},
                {'label':'B','bbox':[50,150,110,220]}], 'marks':[]}
        with self.assertRaises(ValueError):statement_at(layout,{'top':160,'bottom':170})

    def test_pdf_spaced_hb_sb_letters_are_one_marker(self):
        for first in ('H','S'):
            layout={'kind':'balance','cells':[],'marks':[{'text':first,'top':150,'x0':115},{'text':'B','top':150,'x0':121}]}
            self.assertEqual(statement_at(layout,{'top':150,'bottom':157})['marks'],[first+'B'])

    def test_geometric_extraction_keeps_multiline_label(self):
        words=[{'text':'Posten2)','x0':60,'x1':85,'top':90},
               {'text':'GuV-','x0':60,'x1':80,'top':81},
               {'text':'4833','x0':160,'x1':175,'top':150}]
        edges=[{'width':0,'height':b-a,'x0':x,'top':a,'bottom':b}
               for x in (55,110) for a,b in ((120,180),(180,220))]
        page=SimpleNamespace(edges=edges,crop=lambda box:SimpleNamespace(extract_text_lines=lambda **kw:[{'text':'Sonstige betriebliche'},{'text':'Erträge'}]))
        layout=statement_cells(page,words,160,(140,116,300,750))
        self.assertEqual(layout['kind'],'guv')
        self.assertEqual(layout['cells'][0]['label'],'Sonstige betriebliche Erträge')
        # Removing a border cannot quietly classify a normal account as blank.
        page.edges=edges[:2]
        with self.assertRaises(ValueError):statement_cells(page,words,160,(140,116,300,750))

    def test_statistical_column_with_no_statement_boxes_is_explicit(self):
        words=[{'text':'Posten2)','x0':60,'x1':85,'top':90},{'text':'9000','x0':160,'x1':175,'top':150}]
        result=statement_cells(SimpleNamespace(edges=[]),words,160,(140,116,300,750))
        self.assertEqual(result,{'kind':'statistical','cells':[],'marks':[]})

    def test_invalid_context_is_not_treated_as_unknown(self):
        for value in (None,{},position(bbox=None),position(status='not_printed'),position(kind='guess')):
            with self.assertRaises(ValueError):s.meaning(value)

    def test_source_layout_and_typography_not_tax_semantics(self):
        self.assertEqual(s.meaning(position()),s.meaning(position('BANKGUTHABEN\n',bbox=[70,150,130,330])))
        for changed in (position(marks=['HB']),position('Verbindlichkeiten'),position('Bankguthaben 7 %')):
            self.assertNotEqual(s.meaning(position()),s.meaning(changed))

    def test_reviewed_orthographic_variant_not_tax_change(self):
        self.assertEqual(d.semantic_text('nahe stehende Personen'),d.semantic_text('nahestehende Personen'))
        for a,b in [('7 %','19 %'),('abziehbar','nicht abziehbar'),('HB','SB'),('§ 4 Nr. 1a','§ 4 Nr. 1b'),('Kapital8)','Kapital')]:
            self.assertNotEqual(d.semantic_text(a),d.semantic_text(b))

    def test_both_real_years_have_complete_printed_fact_coverage(self):
        for y,total,printed in ((2025,1689,1688),(2026,1679,1677)):
            data,h=d.read_json(d.ROOT/f'data/skr42-{y}.json');before=s.fingerprint(data)
            report=s.report(data,d.catalog(data))
            self.assertTrue(report['source_facts_complete'])
            self.assertEqual((report['bookable'],report['printed_positions']),(total,printed))
            self.assertEqual(s.contexts(data)['48330']['label'],'Umsatzerlöse')
            self.assertEqual(s.contexts(data)['48340']['label'],'Sonstige betriebliche Erträge')
            self.assertEqual(s.contexts(data)['48250']['marks'],['HB'])
            self.assertEqual(s.fingerprint(data),before)

    def test_changed_supplement_cannot_reuse_original_hash(self):
        data,_=d.read_json(d.ROOT/'data/skr42-2026.json')
        item,_=d.read_json(d.ROOT/'resources/accounting/statements/2026.json')
        item['positions'][item['accounts']['48330']]['statement']['label']='Wrong'
        with tempfile.TemporaryDirectory() as temp:
            d.write_new(Path(temp)/'2026.json',item)
            with self.assertRaises(ValueError):s.contexts(data,temp)

    def test_supplement_rejects_other_pdf_and_changed_accounts(self):
        original={'year':2026,'sha256':'a'*64,'ranges':[{'code':'18000'}],
                  'accounts':[{'code':'18000','name':'Bank'}]}
        enriched=copy.deepcopy(original)|{'chart':'SKR42','edition':'balance','statement_schema':1}
        enriched['accounts'][0]['statement']=position()
        self.assertEqual(len(s.supplement(original,'b'*64,enriched)['accounts']),1)
        for key,value in (('sha256','c'*64),('year',2027)):
            with self.assertRaises(ValueError):s.supplement(original,'b'*64,enriched|{key:value})
        enriched['accounts'][0]['name']='Other'
        with self.assertRaises(ValueError):s.supplement(original,'b'*64,enriched)


class AnnualPositionTests(annual.AnnualTest):
    # Inherited historical regressions also run with the new source columns.
    def source_pair(self):
        source=self.fixture();source['statement_schema']=1
        for row in source['accounts']+source['ranges']:row['statement']=position()
        return self.pair(source)

    def test_same_number_new_position_or_closing_mark_requires_review(self):
        for p in (position('Verbindlichkeiten'),position(marks=['HB']),position('',status='not_printed',bbox=None)):
            old,base,new=self.source_pair();new['accounts'][0]['statement']=p
            self.assertNotIn('18000',self.plan(old,base,new)['automatic'])

    def test_box_moves_but_meaning_stays_automatic(self):
        old,base,new=self.source_pair();new['accounts'][0]['statement']['bbox']=[55,200,115,450]
        self.assertIn('18000',self.plan(old,base,new)['automatic'])

    def test_new_number_with_exact_context_reuses_reviewed_meaning(self):
        old,base,new=self.source_pair()
        new['accounts'].append(new['accounts'][0]|{'code':'18100','range_start':'18100','range_end':'18100'})
        plan=self.plan(old,base,new)
        self.assertEqual(plan['automatic']['18100']['purpose'],self.cash)
        self.assertEqual(plan['automatic']['18100']['reason'],'same_reviewed_statement_purpose')
        new['accounts'][1]['statement']=position('Verbindlichkeiten')
        self.assertNotIn('18100',self.plan(old,base,new)['automatic'])

    def test_extended_range_does_not_need_reentering_all_accounts(self):
        old,base,new=self.source_pair();new['accounts'][0]['range_end']='18090'
        new['accounts'].append(new['accounts'][0]|{'code':'18010'})
        self.assertEqual(set(self.plan(old,base,new)['automatic']),{'18000','18010'})

    def test_no_tax_word_or_number_is_fuzzy_corrected(self):
        old,base,new=self.source_pair()
        new['accounts'].append(new['accounts'][0]|{'code':'18100','name':'Bankguthaben ohne Steuer','range_start':'18100','range_end':'18100'})
        self.assertNotIn('18100',self.plan(old,base,new)['automatic'])

    def test_conflicting_reviewed_purposes_cannot_propagate(self):
        old,base,new=self.source_pair()
        old['accounts'].append(old['accounts'][0]|{'code':'18100','range_start':'18100','range_end':'18100'})
        base=d.snapshot(self.model,old,d.digest(old),'6.9',{'18000':self.cash,'18100':d.bindings(self.editions[2025])['16000']},{'kind':'synthetic_test'})
        new=copy.deepcopy(old);new['year']=2026;new['sha256']='b'*64
        new['accounts'].append(new['accounts'][0]|{'code':'18200','range_start':'18200','range_end':'18200'})
        self.assertNotIn('18200',self.plan(old,base,new)['automatic'])

    def test_review_shows_actual_printed_position_and_hb_sb(self):
        old,base,new=self.source_pair();new['accounts'][0]['statement']=position('Forderung',marks=['HB'])
        html=d.html_report(self.plan(old,base,new),self.model)
        self.assertIn('Abschlussposten: forderung',html)
        self.assertIn('Abschlusszweck / Programmhinweis: hb',html)


if __name__=='__main__':unittest.main(verbosity=2)
