"""Fill the original Landkreis Wesermarsch AcroForm; signature requires server authorization."""
import sys,json,io,base64
from pathlib import Path
from pypdf import PdfReader,PdfWriter
from reportlab.pdfgen import canvas
from reportlab.lib.utils import ImageReader
raw=sys.stdin.buffer.read(6500001)
if len(raw)>6500000:raise ValueError('Input limit')
data=json.loads(raw);source=Path(__file__).with_name('ehrenamt-wesermarsch.pdf');reader=PdfReader(source);writer=PdfWriter();writer.clone_document_from_reader(reader)
values={f'Text4.{i}':str(value) for i,value in enumerate(data['volunteer_form']['values'])}
if len(values)!=9 or any(len(v)>180 for v in values.values()):raise ValueError('Field limit')
writer.update_page_form_field_values(None,values,auto_regenerate=False)
if data.get('signature'):
 stamp=io.BytesIO();c=canvas.Canvas(stamp,pagesize=(595.32,841.92),invariant=1);c.drawImage(ImageReader(io.BytesIO(base64.b64decode(data['signature'],validate=True))),150,195,width=120,height=32,preserveAspectRatio=True,mask='auto');c.save();writer.pages[0].merge_page(PdfReader(stamp).pages[0])
out=io.BytesIO();writer.write(out);check=PdfReader(io.BytesIO(out.getvalue()));fields=check.get_fields()
if any(fields[k].get('/V')!=v for k,v in values.items()):raise ValueError('Field verification failed')
sys.stdout.buffer.write(out.getvalue())
