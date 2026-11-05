"""Own lossless JPEG XL CFA DNG with two declared JPEG previews; dev fixtures only."""
from pathlib import Path
import hashlib, json, struct, subprocess, tempfile
root = Path(__file__).parent
width, height = 1024, 768
pixels = b''.join(struct.pack('<H', [50000, 26000, 26000, 9000][(y % 2) * 2 + x % 2]) for y in range(height) for x in range(width))
with tempfile.TemporaryDirectory(prefix='own-jxl-dng-') as temporary:
    directory = Path(temporary); (directory/'sensor.gray').write_bytes(pixels)
    subprocess.run(['ffmpeg','-hide_banner','-loglevel','error','-f','rawvideo','-pixel_format','gray16le','-video_size',f'{width}x{height}','-i',str(directory/'sensor.gray'),'-frames:v','1','-c:v','libjxl','-distance','0','-effort','3',str(directory/'sensor.jxl')],check=True)
    encoded = (directory/'sensor.jxl').read_bytes()
    subprocess.run(['ffmpeg','-hide_banner','-loglevel','error','-i',str(directory/'sensor.jxl'),'-frames:v','1','-f','rawvideo','-pix_fmt','gray16le',str(directory/'restored.gray')],check=True)
    assert (directory/'restored.gray').read_bytes()==pixels, 'Independent JPEG XL lossless sensor proof required'
    previews=[]
    for w,h in [(128,96),(640,480)]:
        script='$c=imagecreatetruecolor((int)$argv[1],(int)$argv[2]);imagefilledrectangle($c,0,0,((int)$argv[1]/2)-1,(int)$argv[2]-1,imagecolorallocate($c,255,55,95));imagefilledrectangle($c,(int)$argv[1]/2,0,(int)$argv[1]-1,(int)$argv[2]-1,imagecolorallocate($c,45,90,220));imagejpeg($c,$argv[3],95);'
        output=directory/f'preview-{w}.jpg'
        subprocess.run(['php','-r',script,str(w),str(h),str(output)],check=True)
        previews.append(output.read_bytes())

def entry(tag,kind,values):
    if kind==1: data=bytes(values);n=len(values)
    elif kind==2: data=values.encode()+b'\0';n=len(data)
    elif kind in (5,10): data=b''.join(struct.pack('<II' if kind==5 else '<ii',*value) for value in values);n=len(values)
    else: data=struct.pack('<'+('H' if kind==3 else 'I')*len(values),*values);n=len(values)
    return [tag,kind,n,data]
raw=[entry(tag,kind,values) for tag,kind,values in [(254,4,[0]),(256,4,[width]),(257,4,[height]),(258,3,[16]),(259,3,[52546]),(262,3,[32803]),(273,4,[0]),(274,3,[6]),(277,3,[1]),(278,4,[height]),(279,4,[len(encoded)]),(284,3,[1]),(330,4,[0,0]),(33421,3,[2,2]),(33422,1,[0,1,1,2]),(50706,1,[1,7,0,0]),(50707,1,[1,7,0,0]),(50710,1,[0,1,2]),(50711,3,[1]),(50714,5,[(0,1)]),(50717,4,[65535]),(50721,10,[(1 if i%4==0 else 0,1) for i in range(9)]),(50728,5,[(1,1)]*3),(50778,3,[21])]]
raw += [entry(271,2,'Own synthetic camera'),entry(272,2,'Own JPEG XL RAW test'),entry(50708,2,'Own JPEG XL CFA DNG')]
ifds=[sorted(raw)]
for (w,h),jpeg in zip([(128,96),(640,480)],previews):
    ifds.append(sorted(entry(tag,kind,value) for tag,kind,value in [(254,4,[1]),(256,4,[w]),(257,4,[h]),(258,3,[8,8,8]),(259,3,[7]),(262,3,[2]),(273,4,[0]),(274,3,[6]),(277,3,[3]),(278,4,[h]),(279,4,[len(jpeg)]),(284,3,[1])]))
offsets=[];position=8
for rows in ifds: offsets.append(position);position+=2+len(rows)*12+4
extra=bytearray();directories=[]
for index,rows in enumerate(ifds):
    directory=bytearray(struct.pack('<H',len(rows)))
    for tag,kind,n,data in rows:
        if tag==330: data=struct.pack('<II',*offsets[1:])
        if len(data)>4:
            value=struct.pack('<I',position+len(extra));extra+=data
            if len(extra)%2: extra+=b'\0'
        else: value=data.ljust(4,b'\0')
        directory+=struct.pack('<HHI',tag,kind,n)+value
    directory+=b'\0'*4;directories.append(directory)
payloads=[encoded,*previews];at=position+len(extra)
for rows,directory,payload in zip(ifds,directories,payloads):
    for index,(tag,kind,n,data) in enumerate(rows):
        if tag==273: directory[2+index*12+8:2+index*12+12]=struct.pack('<I',at)
    at+=len(payload)
result=b'II*\0'+struct.pack('<I',8)+b''.join(directories)+bytes(extra)+b''.join(payloads)
(root/'own-jxl-preview.dng').write_bytes(result)
(root/'own-jxl-preview.json').write_text(json.dumps({'raw_compression':52546,'sensor_width':width,'sensor_height':height,'sensor_sha256':hashlib.sha256(pixels).hexdigest(),'jxl_sha256':hashlib.sha256(encoded).hexdigest(),'lossless_independent_ffmpeg_decode':True,'preview_width':640,'preview_height':480,'orientation':6,'dng_sha256':hashlib.sha256(result).hexdigest()},indent=2)+'\n')
print('Own JPEG XL CFA DNG and independent lossless sensor proof generated.')
