from pathlib import Path
import sys,io
sys.path.insert(0,str(Path('vereinsverwaltung/tools').resolve()))
from pypdf import PdfWriter
from invoice_embedded_xml import extract
xml=b'<CrossIndustryInvoice xmlns="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"/>' 
def pdf(entries):
 w=PdfWriter();w.add_blank_page(width=595,height=842)
 for name,data in entries:w.add_attachment(name,data)
 b=io.BytesIO();w.write(b);return b.getvalue()
good=pdf([('factur-x.xml',xml)])
import base64
assert base64.b64decode(extract(good)['xml_base64'])==xml
assert extract(pdf([]))['embedded'] is False
for entries in [[('a.xml',xml),('b.xml',xml)],[('a.xml',b'x'*2097153)],[(str(i)+'.txt',b'x') for i in range(17)]]:
 try: extract(pdf(entries));raise AssertionError('must reject')
 except ValueError:pass
print('PASS embedded PDF exact bytes, no XML, multiple XML, decompressed size, attachment count: 5')
