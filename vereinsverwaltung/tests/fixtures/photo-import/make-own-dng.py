from pathlib import Path
import struct, sys
w,h=(int(sys.argv[2]),int(sys.argv[3])) if len(sys.argv)==4 else (128,96)
if w<2 or h<2 or w*h>120000000: raise ValueError("Own fixture dimensions")
tags=[]
def add(tag,kind,values):
    size={1:1,2:1,3:2,4:4,5:8,10:8}[kind]
    if kind==2:data=values.encode()+b'\0';count=len(data)
    elif kind==1:data=bytes(values);count=len(values)
    elif kind in [5,10]:data=b''.join(struct.pack('<II' if kind==5 else '<ii',*v)for v in values);count=len(values)
    else:data=struct.pack('<'+('H'if kind==3 else 'I')*len(values),*values);count=len(values)
    tags.append([tag,kind,count,data])
for tag,kind,v in [(254,4,[0]),(256,4,[w]),(257,4,[h]),(258,3,[16]),(259,3,[1]),(262,3,[32803]),(273,4,[0]),(277,3,[1]),(278,4,[h]),(279,4,[w*h*2]),(284,3,[1]),(33421,3,[2,2]),(33422,1,[0,1,1,2]),(50706,1,[1,4,0,0]),(50707,1,[1,1,0,0]),(50710,1,[0,1,2]),(50711,3,[1]),(50714,5,[(0,1)]),(50717,4,[65535]),(50721,10,[(1 if i%4==0 else 0,1)for i in range(9)]),(50728,5,[(1,1)]*3),(50778,3,[21])]:add(tag,kind,v)
add(271,2,'Own synthetic camera');add(272,2,'Own DNG test');add(50708,2,'Own synthetic DNG');tags.sort()
offset=8+2+12*len(tags)+4
extra=b'';entries=[]
for tag,kind,count,data in tags:
    if len(data)>4:
        value=struct.pack('<I',offset+len(extra));extra+=data
        if len(extra)%2:extra+=b'\0'
    else:value=data.ljust(4,b'\0')
    entries.append((tag,struct.pack('<HHI',tag,kind,count)+value))
pixel_offset=offset+len(extra)
entries=[(tag,struct.pack('<HHII',273,4,1,pixel_offset)if tag==273 else data)for tag,data in entries]
pixels=bytearray()
for y in range(h):
 for x in range(w):
  color=(y%2)*2+x%2;value=[50000,26000,26000,9000][color];pixels+=struct.pack('<H',value)
out=Path(sys.argv[1]) if len(sys.argv)==4 else Path(__file__).parent/'own-camera.dng';out.write_bytes(b'II*\0'+struct.pack('<I',8)+struct.pack('<H',len(tags))+b''.join(data for _,data in entries)+b'\0'*4+extra+pixels)
print('Own uncompressed CFA DNG',len(pixels),'raw pixel bytes')
