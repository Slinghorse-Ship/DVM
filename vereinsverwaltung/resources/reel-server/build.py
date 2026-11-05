#!/usr/bin/env python3
"""Build the separate offline encoder from its bundled, hash-pinned sources."""
import hashlib
import json
import os
from pathlib import Path
import platform
import subprocess
import tarfile
import tempfile

root = Path(__file__).resolve().parent
proof = json.loads((root / 'provenance.json').read_text())
if platform.system() != 'Linux' or platform.machine() not in ('x86_64', 'amd64'):
    raise SystemExit('Linux x86-64 build environment required.')
work = Path(tempfile.mkdtemp(prefix='dvm-reel-build-'))
work.chmod(0o700)

def unpack(source, digest, target):
    if hashlib.file_digest(source.open('rb'), 'sha256').hexdigest() != digest:
        raise RuntimeError('Bundled source hash differs: ' + source.name)
    target.mkdir()
    with tarfile.open(source, 'r:xz') as archive:
        archive.extractall(target, filter='data')
    children = list(target.iterdir())
    if len(children) != 1 or not children[0].is_dir():
        raise RuntimeError('Unexpected source layout')
    return children[0]

x264 = unpack(root / proof['x264']['source'], proof['x264']['sha256'], work / 'x264-source')
ffmpeg = unpack(root / proof['ffmpeg']['source'], proof['ffmpeg']['sha256'], work / 'ffmpeg-source')
prefix = work / 'prefix'
subprocess.run(['./configure', '--prefix=' + str(prefix), '--enable-static', '--disable-cli', '--disable-asm', '--disable-opencl', '--enable-pic'], cwd=x264, check=True)
subprocess.run(['make', '-j2'], cwd=x264, check=True)
subprocess.run(['make', 'install'], cwd=x264, check=True)
environment = os.environ.copy()
environment['PKG_CONFIG_PATH'] = str(prefix / 'lib/pkgconfig')
arguments = ['./configure', '--prefix=' + str(work / 'result'), '--pkg-config-flags=--static', '--extra-cflags=-I' + str(prefix / 'include'), '--extra-ldflags=-L' + str(prefix / 'lib') + ' -static', '--disable-shared', '--enable-static', '--disable-doc', '--disable-debug', '--disable-ffplay', '--disable-ffprobe', '--disable-x86asm', '--enable-encoder=libx264', '--enable-swscale', '--enable-avfilter', *proof['build_flags']]
subprocess.run(arguments, cwd=ffmpeg, env=environment, check=True)
subprocess.run(['make', '-j2'], cwd=ffmpeg, check=True)
subprocess.run(['make', 'install'], cwd=ffmpeg, check=True)
binary = work / 'result/bin/ffmpeg'
print(json.dumps({'binary': str(binary), 'bytes': binary.stat().st_size, 'sha256': hashlib.file_digest(binary.open('rb'), 'sha256').hexdigest()}))
