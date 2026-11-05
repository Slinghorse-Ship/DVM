"""Synthetic pixels only; no OCR/network/process invocation."""
import importlib.util, pathlib, math, io
from PIL import Image
path=pathlib.Path(__file__).resolve().parents[1]/'tools'/'recognize-invoice.py'
spec=importlib.util.spec_from_file_location('recognize',path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
n=0
def check(value):
 global n
 assert value
 n+=1
scale=m.raster_scale(595.28,841.89);check(abs(scale-300/72)<0.001)
for width,height in [(100000,100000),(1,100000),(595.28,841.89)]:
 scale=m.raster_scale(width,height);check(width*scale<=3508.001 and height*scale<=3508.001 and width*height*scale*scale<=15000000)
for w,h in [(0,5),(math.inf,5),(5,math.nan)]:
 try:m.raster_scale(w,h);raise AssertionError('invalid dimension accepted')
 except m.RecognitionError:n+=1
for fmt in ['JPEG','PNG']:
 with Image.new('RGB',(80,40),'white') as source:
  exif=source.getexif();exif[274]=6;stream=io.BytesIO();source.save(stream,format=fmt,exif=exif)
  stream.seek(0)
  with Image.open(stream) as loaded:
   with m.prepare_ocr_image(loaded) as prepared:check(prepared.size==(40,80) and not prepared.getexif())
with Image.new('RGBA',(100,100),(0,0,0,0)) as source:
 with m.prepare_ocr_image(source) as prepared:check(prepared.getpixel((0,0))==(255,255,255))
with Image.new('RGB',(4000,4000),'white') as source:
 with m.prepare_ocr_image(source) as prepared:check(prepared.size==(3508,3508))
print(f'{n} image preparation checks passed; no external services')
