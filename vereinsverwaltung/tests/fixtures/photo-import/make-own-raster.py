#!/usr/bin/env python3
"""Stream an owned, deterministic 48MP RGBA PNG without loading it into Python."""
from pathlib import Path
import sys,struct,zlib,random,json,hashlib
out=Path(sys.argv[1]);assert out.is_absolute() and out.parent.is_dir() and not out.exists()
w,h=8000,6000;rng=random.Random(20261018)
def chunk(f,kind,data):
    f.write(struct.pack('>I',len(data)));f.write(kind);f.write(data);f.write(struct.pack('>I',zlib.crc32(kind+data)&0xffffffff))
with out.open('xb') as f:
    f.write(b'\x89PNG\r\n\x1a\n');chunk(f,b'IHDR',struct.pack('>IIBBBBB',w,h,8,6,0,0,0));compressor=zlib.compressobj(0)
    for row in range(h):
        data=compressor.compress(b'\0'+rng.randbytes(w*4))
        if data:chunk(f,b'IDAT',data)
    data=compressor.flush()
    if data:chunk(f,b'IDAT',data)
    chunk(f,b'IEND',b'')
print(json.dumps({'file':out.name,'width':w,'height':h,'bytes':out.stat().st_size,'sha256':hashlib.file_digest(out.open('rb'),'sha256').hexdigest()}))
