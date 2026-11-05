"""Actual Pillow decoder MIME allowlist; OCR execution replaced with fixed text."""
import importlib.util,io,json,sys,tempfile
from pathlib import Path
from PIL import Image,UnidentifiedImageError
root=Path(__file__).resolve().parents[1];sys.path.insert(0,str(root/'tools'))
spec=importlib.util.spec_from_file_location('recognition',root/'tools'/'recognize-invoice.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
# Linux test workers still need their real sandbox; this fixture is Windows only.
if sys.platform!='win32':raise SystemExit('Run this decoder fixture with the isolated Windows test runtime.')
calls=[]
m.execute=lambda args: calls.append(args) or 'Rechnung Gesamtbetrag: 119,00 EUR'
m.tool=lambda config,key: 'synthetic-tesseract-not-executed'
original_stdin=sys.stdin
try:
 with tempfile.TemporaryDirectory() as temp:
  m.BASE=Path(temp)
  for fmt,mime,accepted in [('PNG','image/png',True),('JPEG','image/jpeg',True),('GIF','image/png',False),('PNG','image/jpeg',False),('JPEG','image/png',False)]:
   Image.new('RGB',(4,4),'white').save(m.BASE/'input.bin',format=fmt)
   sys.stdin=io.StringIO(json.dumps({'runtime':{'require_sandbox':False},'mime':mime}));before=len(calls)
   try:r=m.main()
   except UnidentifiedImageError:assert not accepted and len(calls)==before
   else:assert accepted and r['state']=='ready' and len(calls)==before+1
   print('PASS',fmt,mime,'accepted' if accepted else 'rejected before OCR')
finally:sys.stdin=original_stdin
