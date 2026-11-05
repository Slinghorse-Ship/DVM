"""Synthetic regression tests: no network, PDFs, credentials or club data."""
import pathlib
import sys
import unittest
from types import SimpleNamespace

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / 'tools'))
from datev_table import account_column, check_coverage, clean


class DatevTableTests(unittest.TestCase):
    def setUp(self):
        self.page = SimpleNamespace(height=842, edges=[
            {'height': 722.5, 'width': 0, 'x0': 311.4, 'x1': 311.4, 'bottom': 807.5},
            {'height': 0, 'width': 510.5, 'x0': 56.6, 'x1': 567.1, 'bottom': 122},
        ])
        self.words = []
        for number, top in [('6530', 753.94), ('6540', 763.3), ('6550', 772.54), ('6560', 781.9)]:
            self.words.extend([
                {'text': number, 'x0': 174.48, 'x1': 189.48, 'top': top},
                {'text': '0', 'x0': 191.4, 'x1': 195.4, 'top': top},
            ])

    def test_bottom_accounts_are_retained(self):
        anchor, box, codes = account_column(self.page, self.words, 100, 323)
        self.assertEqual(anchor, 174)
        self.assertEqual(box, (155, 116, 310.4, 807.5))
        self.assertEqual(codes, {'65300', '65400', '65500', '65600'})

    def test_right_column_is_not_clipped(self):
        words = [dict(w, x0=w['x0']+255, x1=w['x1']+255) for w in self.words]
        self.assertEqual(account_column(self.page, words, 340, 573)[1], (410, 116, 566.1, 807.5))

    def test_footer_year_is_not_an_account(self):
        words = self.words + [{'text': '2025', 'x0': 174.48, 'x1': 189.48, 'top': 821}]
        self.assertEqual(len(account_column(self.page, words, 100, 323)[2]), 4)

    def test_reference_inside_description_is_not_an_account(self):
        words = self.words + [{'text': '9999', 'x0': 210, 'x1': 225, 'top': 200},
                              {'text': '0', 'x0': 226, 'x1': 230, 'top': 200}]
        self.assertNotIn('99990', account_column(self.page, words, 100, 323)[2])

    def test_missing_table_divider_is_rejected(self):
        self.page.edges = self.page.edges[1:]
        with self.assertRaises(ValueError):
            account_column(self.page, self.words, 100, 323)

    def test_changed_table_bottom_is_rejected(self):
        self.page.edges[0]['bottom'] = 730
        with self.assertRaises(ValueError):
            account_column(self.page, self.words, 100, 323)

    def test_empty_column(self):
        self.assertIsNone(account_column(self.page, [], 100, 323))

    def test_range_endpoint_counts_as_covered(self):
        check_coverage([{'page': 2, 'code': '01000', 'end': '01099'}], {(2, '01000'), (2, '01099')})

    def test_missing_or_wrong_page_account_is_rejected(self):
        for page in [2, 3]:
            with self.assertRaises(ValueError):
                check_coverage([{'page': 2, 'code': '01000', 'end': '01099'}], {(page, '01200')})

    def test_line_break_hyphenation_is_joined(self):
        self.assertEqual(clean(['Vermögensgegen-', 'stände']), 'Vermögensgegenstände')

    def test_coordination_hyphen_is_preserved(self):
        for word in ['und', 'oder', 'sowie']:
            self.assertEqual(clean(['Miet-', word+' Pachtkosten']), 'Miet- '+word+' Pachtkosten')


if __name__ == '__main__':
    unittest.main()
