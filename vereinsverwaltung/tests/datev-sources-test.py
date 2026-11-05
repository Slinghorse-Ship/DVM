"""Source selection/import boundary checks with synthetic inputs only."""
import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import runpy
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch,MagicMock

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))
import datev_years as d


def load(name):
    spec=importlib.util.spec_from_file_location(name,d.ROOT/'tools'/f'{name}.py')
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module


class SourceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.check=load('check-datev-skr42');cls.maintain=load('maintain-datev-skr42')

    def test_official_source_requires_https_exact_domain_and_no_credentials(self):
        for url in ('https://download.datev-shop.de/abc/12021','https://apps.datev.de/file.pdf'):
            self.assertTrue(d.official_url(url))
        for url in ('https://notdatev.de/file.pdf','https://datev.de.evil.test/file.pdf','http://datev.de/file.pdf',
                    'https://u:p@www.datev.de/file.pdf','https://www.datev.de:444/file.pdf','https://www.datev.de:bad/file.pdf','javascript:alert(1)',None):
            self.assertFalse(d.official_url(url))

    def test_product_pdf_not_header_remote_support_download(self):
        page='https://www.datev.de/web/de/shop/produkt-details/12901'
        self.assertEqual(self.check.official_download(page,['https://download.datev.de/','/contact',
            'https://download.datev-shop.de/product/12021','https://download.datev-shop.de/product/12021']),
            'https://download.datev-shop.de/product/12021')
        self.assertIsNone(self.check.official_download(page,['https://download.datev.de/','/contact']))
        self.assertIsNone(self.check.official_download(page,['https://download.datev-shop.de/one','https://www.datev.de/two.pdf']))
        self.assertIsNone(self.check.official_download(page,['https://notdatev.de/file.pdf']))

    def test_label_selects_product_download_among_preview_pdf_and_support(self):
        parsed=self.check.Links()
        parsed.feed('<a href="https://download.datev.de/">Fernbetreuung</a><a href="/image.pdf">kostenfreier Download</a>'
            '<a href="https://download.datev-shop.de/product"><span>kostenfreier</span> Download</a>')
        self.assertEqual(self.check.official_download('https://www.datev.de/product',parsed.hrefs,parsed.download_hrefs),
                         'https://download.datev-shop.de/product')

    def test_redirect_outside_official_source_is_rejected_before_fetch(self):
        with self.assertRaises(ValueError):self.check.OfficialRedirect().redirect_request(None,None,302,'',{},'https://example.test/a')
        with patch.object(self.check.urllib.request,'build_opener') as opener:
            with self.assertRaises(ValueError):self.check.fetch('http://datev.de/file.pdf')
            opener.assert_not_called()

    def test_fetch_size_limit_and_final_url(self):
        for url,content in [('https://datev.de/a',b'x'*11),('https://example.test/a',b'x')]:
            response=MagicMock();response.__enter__.return_value=response
            response.geturl.return_value=url;response.read.return_value=content
            with patch.object(self.check.urllib.request,'build_opener') as opener:
                opener.return_value.open.return_value=response
                with self.assertRaises(ValueError):self.check.fetch('https://www.datev.de/a',10)

    def run_check(self,directory,pdf):
        page=b'<p>Stand Januar 2026</p><a href="https://download.datev-shop.de/abc">Download</a>'
        def fetch(url,*args):return pdf if 'download.datev-shop.de' in url else page
        manifest=directory/'status.json'
        with patch.object(sys,'argv',['check','--output',str(manifest),'--download-dir',str(directory/'downloads')]),\
                patch.object(self.check,'fetch',side_effect=fetch),contextlib.redirect_stdout(io.StringIO()):
            status=self.check.main()
        return status,json.loads(manifest.read_text(encoding='utf-8'))

    def test_non_pdf_response_is_an_error_not_success(self):
        with tempfile.TemporaryDirectory() as directory:
            status,manifest=self.run_check(Path(directory),b'<html>server error</html>')
            self.assertEqual(status,2)
            self.assertTrue(all(r['state']=='error' for r in manifest['editions'].values()))
            self.assertEqual(list(Path(directory).rglob('*.pdf')),[])

    def test_shop_delivery_html_follows_one_observed_link_without_script(self):
        url='https://download.datev-shop.de/product'
        html=b'<script>location.href="https://other.test"</script><a href="/product/12021">Link</a>'
        with patch.object(self.check,'fetch',side_effect=[html,b'%PDF-synthetic']) as fetch:
            target,raw=self.check.download_pdf(url)
            self.assertEqual(target,url+'/12021');self.assertEqual(raw,b'%PDF-synthetic')
            self.assertEqual(fetch.call_count,2)

    def test_downloads_are_immutable_repeated_identical_run_is_safe(self):
        with tempfile.TemporaryDirectory() as directory:
            directory=Path(directory);pdf=b'%PDF-synthetic-source'
            status,manifest=self.run_check(directory,pdf);self.assertEqual(status,0)
            target=Path(manifest['editions']['balance']['pending_pdf']);self.assertTrue(target.is_absolute())
            self.assertEqual(target.read_bytes(),pdf)
            self.assertEqual(self.run_check(directory,pdf)[0],0)
            target.write_bytes(b'changed synthetic fixture')
            self.assertEqual(self.run_check(directory,pdf)[0],2)
            self.assertEqual(target.read_bytes(),b'changed synthetic fixture')

    def test_default_import_is_pending_and_variants_do_not_collide(self):
        self.assertEqual(d.import_directory(d.ROOT,None,'balance',2027),d.ROOT/'data/pending/balance/2027')
        self.assertNotEqual(d.import_directory(d.ROOT,None,'balance',2027),d.import_directory(d.ROOT,None,'euer',2027))
        for path in ('data','public','resources/ebilanz','views','src'):
            with self.assertRaises(ValueError):d.import_directory(d.ROOT,d.ROOT/path,'balance',2026)

    def import_fake_pdf(self,pdf,directory,extra=(),empty=False):
        pages=[]
        for index in range(8):
            page=MagicMock();page.dedupe_chars.return_value=page;page.crop.return_value=page
            page.extract_text.return_value='Gültig für 2026 · synthetic appendix'
            number=f'{1800+index}';page.extract_text_lines.return_value=[] if empty else [{'text':number+' 0 Bankguthaben','x0':174,'top':130,'bottom':137}]
            pages.append(page)
        def column(page,words,left,right):
            if right==573 or empty:return None
            return 174,(0,0,100,100),{f'{1800+pages.index(page)}0'}
        args=['import',str(pdf),'--year','2026','--source-url','https://www.datev.de/test.pdf',
              '--expected-sha256',hashlib.sha256(pdf.read_bytes()).hexdigest(),'--output-dir',str(directory),*extra]
        document=MagicMock();document.__enter__.return_value=SimpleNamespace(pages=pages)
        # This test isolates immutable import artifacts; geometry is exercised
        # by datev-statements-test.py and by both complete real PDF imports.
        positions={'kind':'balance','cells':[{'label':'Bankguthaben','bbox':[50,120,110,200]}],'marks':[]}
        with patch.object(sys,'argv',args),patch('pdfplumber.open',return_value=document),\
                patch('datev_table.account_column',side_effect=column),patch('datev_table.statement_cells',return_value=positions),\
                contextlib.redirect_stdout(io.StringIO()),contextlib.redirect_stderr(io.StringIO()):
            try:runpy.run_path(str(d.ROOT/'tools/import-datev-catalog.py'),run_name='__main__')
            except SystemExit as error:return error.code
        return 0

    def test_import_preserves_notes_and_never_overwrites_candidate(self):
        with tempfile.TemporaryDirectory() as directory:
            directory=Path(directory);pdf=directory/'synthetic.pdf';pdf.write_bytes(b'%PDF-synthetic')
            out=directory/'candidate'
            self.assertEqual(self.import_fake_pdf(pdf,out,['--appendix-pages','2']),0)
            path=out/'skr42-2026.json';before=path.read_bytes();data,_=d.read_json(path)
            self.assertEqual(data['edition'],'balance');self.assertEqual(data['chart'],'SKR42')
            self.assertEqual([n['page'] for n in data['source_notes']],[7,8])
            self.assertEqual(len(data['accounts']),6)
            self.assertEqual(data['statement_schema'],1)
            self.assertTrue(all(r['statement']['label']=='Bankguthaben' for r in data['accounts']))
            self.assertEqual(self.import_fake_pdf(pdf,out,['--appendix-pages','2']),2)
            self.assertEqual(path.read_bytes(),before)

    def test_unknown_source_requires_appendix_review_and_empty_tables_fail(self):
        with tempfile.TemporaryDirectory() as directory:
            directory=Path(directory);pdf=directory/'synthetic.pdf';pdf.write_bytes(b'%PDF-synthetic')
            self.assertEqual(self.import_fake_pdf(pdf,directory/'missing'),2)
            with self.assertRaises(ValueError):self.import_fake_pdf(pdf,directory/'empty',['--appendix-pages','2'],empty=True)
            self.assertFalse((directory/'empty/skr42-2026.json').exists())

    def test_prepare_binds_source_manifest_and_import_output(self):
        with tempfile.TemporaryDirectory() as directory:
            directory=Path(directory);pdf=directory/'synthetic.pdf';pdf.write_bytes(b'%PDF-synthetic')
            manifest=directory/'source.json'
            row={'state':'found','edition':'Januar 2026','download_url':'https://www.datev.de/test.pdf',
                 'pending_pdf':str(pdf),'pdf_sha256':hashlib.sha256(pdf.read_bytes()).hexdigest()}
            d.write_new(manifest,{'editions':{'balance':row}})
            args=SimpleNamespace(manifest=manifest,appendix_pages=2)
            current={'year':2026,'source':{'pdf_sha256':row['pdf_sha256']}}
            with patch.object(self.maintain.subprocess,'run') as process:
                self.assertEqual(self.maintain.prepare_source(args,current),(None,None,None,None))
                process.assert_not_called()
                with self.assertRaises(ValueError):self.maintain.prepare_source(args,{'year':2026,'source':{'pdf_sha256':'c'*64}})
                process.assert_not_called()
            def run(command,**kwargs):
                output=Path(command[command.index('--output-dir')+1])
                d.write_new(output/'skr42-2026.json',{'year':2026,'synthetic':True})
                self.assertIn('--appendix-pages',command)
                return SimpleNamespace(returncode=0)
            with patch.object(self.maintain.subprocess,'run',side_effect=run):
                data,raw_hash,raw,original=self.maintain.prepare_source(args)
            self.assertTrue(data['synthetic']);self.assertEqual(hashlib.sha256(raw).hexdigest(),raw_hash)
            self.assertEqual(original,pdf.read_bytes())
            pdf.write_bytes(b'%PDF-changed')
            with self.assertRaises(ValueError):self.maintain.prepare_source(args)


if __name__=='__main__':unittest.main(verbosity=2)
