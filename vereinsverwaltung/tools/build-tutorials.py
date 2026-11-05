"""Encode original narrated UI tutorials; generate timed subtitles and chapters.

Requires imageio-ffmpeg, generated WAVs from tutorial-speech.ps1 and scenes
from tutorial-frames.cjs. No live data or external services used for narration.
"""
from pathlib import Path
import json
import os
import re
import subprocess
import wave
import textwrap
import imageio_ffmpeg

ROOT = Path(__file__).resolve().parents[2]
BUILD = ROOT / '.local/tutorial-build'
RESOURCE = ROOT / 'vereinsverwaltung/resources'
FFMPEG = imageio_ffmpeg.get_ffmpeg_exe()


def stamp(seconds):
    millis = round(seconds * 1000)
    return f'{millis // 3600000:02}:{millis // 60000 % 60:02}:{millis // 1000 % 60:02}.{millis % 1000:03}'


def run(args):
    result = subprocess.run([FFMPEG, '-hide_banner', '-loglevel', 'error', '-y'] + args, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr.decode(errors='replace'))


def build():
    tutorials = json.loads((ROOT / 'docs/vereinsverwaltung/handbuch/tutorials.json').read_text(encoding='utf-8'))
    selected = {value.strip() for value in os.environ.get('KULTURHUS_TUTORIAL_IDS', '').split(',') if value.strip()}
    unknown = selected - {video['id'] for video in tutorials}
    if unknown:
        raise ValueError('Unknown tutorials: ' + ', '.join(sorted(unknown)))
    previous_file = RESOURCE / 'handbook-tutorials.json'
    previous = {video['id']: video for video in json.loads(previous_file.read_text(encoding='utf-8'))} if previous_file.exists() else {}
    completed = []
    manifest = {}
    for video in tutorials:
        if selected and video['id'] not in selected:
            if video['id'] not in previous:
                raise ValueError('New tutorial must be built: ' + video['id'])
            saved = previous[video['id']]
            def render_content(item):
                return [item['title'], item['path'], [[scene.get(key) for key in ('title', 'figure', 'text', 'notes')] for scene in item['scenes']]]
            if render_content(saved) != render_content(video):
                raise ValueError('Changed tutorial must be selected for rebuild: ' + video['id'])
            saved['chapter'] = video['chapter']
            for field in ('file', 'poster', 'subtitles'):
                if not (RESOURCE / 'handbook' / saved[field]).is_file():
                    raise ValueError('Existing tutorial artifact missing: ' + saved[field])
            completed.append(saved)
            manifest.update({saved['file']:'video/mp4', saved['poster']:'image/png', saved['subtitles']:'text/vtt; charset=utf-8'})
            continue
        elapsed = 0.0
        subtitles = ['WEBVTT', '']
        segments = []
        for i, scene in enumerate(video['scenes'], 1):
            stem = video['id'] + '-' + str(i)
            audio = BUILD / (stem + '.wav')
            with wave.open(str(audio)) as wav:
                speech_duration = wav.getnframes() / wav.getframerate()
            duration = round(speech_duration + 0.6, 3)
            scene['start'] = round(elapsed, 3)
            scene['duration'] = duration
            sentences = []
            for sentence in re.split(r'(?<=[.!?])\s+', scene['text']):
                lines = textwrap.wrap(sentence, width=54, break_long_words=False, break_on_hyphens=False)
                sentences.extend('\n'.join(lines[n:n+2]) for n in range(0, len(lines), 2))
            weight = sum(len(s) for s in sentences)
            cursor = elapsed
            for sentence in sentences:
                end = cursor + speech_duration * len(sentence) / weight
                subtitles.extend([stamp(cursor) + ' --> ' + stamp(end), sentence, ''])
                cursor = end
            segment = BUILD / (stem + '.mp4')
            run(['-loop', '1', '-framerate', '10', '-i', str(BUILD / (stem + '.png')), '-i', str(audio),
                 '-t', str(duration), '-vf', 'format=yuv420p', '-c:v', 'libx264', '-preset', 'veryfast',
                 '-tune', 'stillimage', '-crf', '24', '-c:a', 'aac', '-ar', '48000', '-ac', '1',
                 '-af', 'apad', '-movflags', '+faststart', str(segment)])
            segments.append("file '" + segment.as_posix() + "'")
            elapsed += duration
        concat = BUILD / (video['id'] + '-concat.txt')
        concat.write_text('\n'.join(segments), encoding='utf-8')
        video['file'] = 'video-' + video['id'] + '.mp4'
        video['poster'] = 'video-' + video['id'] + '.png'
        video['subtitles'] = 'video-' + video['id'] + '.vtt'
        video['duration'] = round(elapsed, 3)
        run(['-f', 'concat', '-safe', '0', '-i', str(concat), '-c', 'copy', '-movflags', '+faststart', str(RESOURCE / 'handbook' / video['file'])])
        (RESOURCE / 'handbook' / video['subtitles']).write_text('\n'.join(subtitles), encoding='utf-8')
        manifest.update({video['file']:'video/mp4', video['poster']:'image/png', video['subtitles']:'text/vtt; charset=utf-8'})
        completed.append(video)
        print(f'Encoded {video["id"]}: {elapsed:.1f}s', flush=True)
    (RESOURCE / 'handbook-tutorials.json').write_text(json.dumps(completed, ensure_ascii=False, indent=2), encoding='utf-8')
    (RESOURCE / 'handbook-media.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding='utf-8')


if __name__ == '__main__':
    build()
