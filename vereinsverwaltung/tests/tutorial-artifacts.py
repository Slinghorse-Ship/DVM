"""Offline decoded media, subtitle content/timing and targeted rebuild validation."""
from pathlib import Path
import array, hashlib, json, os, re, subprocess, wave
import imageio_ffmpeg

ROOT=Path(__file__).resolve().parents[2]
VIDEOS=ROOT/'vereinsverwaltung/resources/handbook'
manifest=json.loads((ROOT/'vereinsverwaltung/resources/handbook-tutorials.json').read_text(encoding='utf-8'))
sources={v['id']:v for v in json.loads((ROOT/'docs/vereinsverwaltung/handbuch/tutorials.json').read_text(encoding='utf-8'))}
changed={'mitglied','nachweise','finanzaufnahme','protokolle','account','chatumfragen'}
ffmpeg=imageio_ffmpeg.get_ffmpeg_exe()
checks=[]
def seconds(value):
    h,m,s=value.split(':'); return int(h)*3600+int(m)*60+float(s)
def normalized(value): return ' '.join(value.split())
for video in manifest:
    assert video['chapter']==sources[video['id']]['chapter']
    if video['id'] not in changed:
        for field in ('file','poster','subtitles'):
            rel=(VIDEOS/video[field]).relative_to(ROOT).as_posix()
            previous=subprocess.run(['git','show','HEAD:'+rel],cwd=ROOT,capture_output=True,check=True).stdout
            if field=='subtitles':
                assert previous.decode('utf-8').splitlines()==(VIDEOS/video[field]).read_text(encoding='utf-8').splitlines(),rel+' subtitles unexpectedly changed'
            else:
                assert hashlib.sha256(previous).digest()==hashlib.sha256((VIDEOS/video[field]).read_bytes()).digest(), rel+' unexpectedly changed'
        checks.append(video['id']+': existing video/poster byte-identical; captions unchanged (Git line endings normalized)')
        continue
    result=subprocess.run([ffmpeg,'-hide_banner','-v','error','-i',str(VIDEOS/video['file']),'-map','0:v:0','-map','0:a:0','-f','null','-'],capture_output=True)
    assert result.returncode==0 and not result.stderr,result.stderr.decode(errors='replace')
    vtt=(VIDEOS/video['subtitles']).read_text(encoding='utf-8');assert vtt.startswith('WEBVTT\n')
    blocks=vtt.strip().split('\n\n')[1:];last=0;caption_text=[]
    for block in blocks:
        time,*lines=block.splitlines();a,b=time.split(' --> ');start,end=seconds(a),seconds(b)
        assert start>=last-.002 and end>start and end<=video['duration']+.002
        assert 1<=len(lines)<=2 and all(len(line)<=60 for line in lines)
        caption_text.append(' '.join(lines));last=end
    assert normalized(' '.join(caption_text))==normalized(' '.join(s['text'] for s in sources[video['id']]['scenes']))
    for index,scene in enumerate(video['scenes'],1):
        with wave.open(str(ROOT/'.local/tutorial-build'/f'{video["id"]}-{index}.wav')) as sound:
            assert sound.getsampwidth()==2 and sound.getnchannels()==1
            values=array.array('h',sound.readframes(sound.getnframes()));assert max(abs(value) for value in values)>1000
        assert scene['start']>=0 and scene['duration']>1 and scene['start']+scene['duration']<=video['duration']+.01
    checks.append(video['id']+': full AV decode, narrated audio, complete two-line German captions and valid timings')
for check in checks: print('PASS '+check)
(ROOT/'.local/tutorial-build/video-artifact-result.json').write_text(json.dumps({'checks':checks,'scope':'Offline generated assets; no website or outbound requests'},indent=2),encoding='utf-8')
print(f'{len(checks)} tutorial artifact checks passed')
