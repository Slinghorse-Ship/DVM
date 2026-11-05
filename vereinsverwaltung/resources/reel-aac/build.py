#!/usr/bin/env python3
"""Rebuild the local browser AAC extension from bundled corresponding sources."""
import argparse, hashlib, json, os, pathlib, shutil, subprocess, tarfile, tempfile

AAC_SHA256 = '32b30d5bfd4a99d51e5f99ea2e7f6e59a7018b2e08e10e6585fb4d997e3353c1'
FFMPEG_SHA256 = 'b2751fccb6cc4c77708113cd78b561059b6fa904b24162fa0be2d60273d27b8e'
CONFIGURE = ['--target-os=none', '--arch=x86_32', '--enable-cross-compile', '--disable-asm', '--disable-x86asm', '--disable-inline-asm', '--disable-programs', '--disable-doc', '--disable-debug', '--disable-all', '--disable-everything', '--disable-autodetect', '--disable-pthreads', '--disable-runtime-cpudetect', '--disable-gpl', '--disable-nonfree', '--enable-avcodec', '--enable-avutil', '--enable-encoder=aac', '--cc=emcc', '--cxx=em++', '--ar=emar', '--ranlib=emranlib', '--extra-cflags=-DNDEBUG -Oz -flto -msimd128', '--extra-ldflags=-Oz -flto']
LINK = ['-s', 'MODULARIZE=1', '-s', 'EXPORT_ES6=1', '-s', 'SINGLE_FILE=1', '-s', 'ALLOW_MEMORY_GROWTH=1', '-s', 'MAXIMUM_MEMORY=67108864', '-s', 'INITIAL_MEMORY=18874368', '-s', 'ENVIRONMENT=web,worker', '-s', 'FILESYSTEM=0', '-s', 'MALLOC=emmalloc', '-s', 'SUPPORT_LONGJMP=0', '-s', 'EXPORTED_RUNTIME_METHODS=cwrap,HEAPU8', '-s', 'EXPORTED_FUNCTIONS=_malloc,_free', '-msimd128', '-flto', '-Oz']

def digest(file, algorithm='sha256'):
    h = hashlib.new(algorithm)
    with file.open('rb') as stream:
        while chunk := stream.read(1024 * 1024): h.update(chunk)
    return h.hexdigest()

def extract(file, target):
    target.mkdir(parents=True)
    with tarfile.open(file) as archive: archive.extractall(target, filter='data')

def adapt(package, output):
    source = (package / 'dist/bundles/mediabunny-aac-encoder.mjs').read_text()
    start = source.index('// inline-worker:__inline-worker')
    end = source.index('// packages/aac-encoder/src/encoder.ts', start)
    source = source[:start] + '''// DVM adaptation: fixed same-origin module worker; strict page CSP unchanged.
function Worker2() { return new Worker(new URL('./native-aac-worker.js', import.meta.url), {type: 'module'}); }

''' + source[end:]
    assert source.count('from "mediabunny";') == 2
    source = source.replace('from "mediabunny";', 'from "./native-mediabunny.mjs";')
    (output / 'native-aac-encoder.mjs').write_text(source)
    source = (package / 'dist/modules/src/encode.worker.js').read_text()
    start = source.index('var __importDefault =')
    end = source.index('let module;', start)
    source = source[:start] + '''// DVM adaptation: browser ESM worker and rebuilt local LGPL libavcodec.
import createModule from './native-aac-libav.mjs';
''' + source[end:]
    assert source.count('(0, aac_1.default)()') == 1
    source = source.replace('(0, aac_1.default)()', 'createModule()')
    (output / 'native-aac-worker.js').write_text(source)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sources', type=pathlib.Path, required=True)
    parser.add_argument('--toolchain', type=pathlib.Path, required=True, help='Emscripten 4.0.10 install directory containing bin/ and emscripten/')
    parser.add_argument('--output', type=pathlib.Path, required=True)
    parser.add_argument('--node', default=shutil.which('node'))
    args = parser.parse_args()
    sources, toolchain, output = (p.resolve() for p in [args.sources, args.toolchain, args.output])
    aac = sources / 'aac-encoder-1.61.3-source.tar.xz'
    ffmpeg = sources / 'ffmpeg-8.0-source.tar.xz'
    if digest(aac) != AAC_SHA256 or digest(ffmpeg) != FFMPEG_SHA256:
        raise SystemExit('Pinned source archive differs; no build attempted.')
    output.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='dvm-reel-aac-') as temp:
        work = pathlib.Path(temp)
        extract(aac, work / 'aac')
        extract(ffmpeg, work / 'ffmpeg')
        package = work / 'aac/package'
        source = work / 'ffmpeg/ffmpeg-8.0'
        config = work / 'emscripten.py'
        config.write_text('LLVM_ROOT = ' + repr(str(toolchain / 'bin')) + '\nBINARYEN_ROOT = ' + repr(str(toolchain)) + '\nNODE_JS = ' + repr(args.node) + '\nCACHE = ' + repr(str(work / 'cache')) + '\n')
        env = dict(os.environ, EM_CONFIG=str(config), PATH=str(toolchain / 'emscripten') + os.pathsep + os.environ['PATH'])
        version = subprocess.check_output(['emcc', '--version'], env=env, text=True)
        if '4.0.10-git (b7dc6e5747465580df5984e723b9d1f10d8e804b)' not in version:
            raise SystemExit('Use pinned Emscripten 4.0.10; no codec build attempted.')
        subprocess.run(['emconfigure', './configure', *CONFIGURE], cwd=source, env=env, check=True)
        subprocess.run(['emmake', 'make', '-j2'], cwd=source, env=env, check=True)
        config_text = (source / 'ffbuild/config.mak').read_text()
        assert '!CONFIG_GPL=yes' in config_text and '!CONFIG_NONFREE=yes' in config_text
        generated = work / 'aac.js'
        subprocess.run(['emcc', str(package / 'src/bridge.c'), 'libavcodec/libavcodec.a', 'libavutil/libavutil.a', '-I.', *LINK, '-o', str(generated)], cwd=source, env=env, check=True)
        adapt(package, output)
        # Keep generated JavaScript suitable for a whitespace-clean source patch.
        normalized = '\n'.join(line.rstrip(' \t') for line in generated.read_text().split('\n'))
        (output / 'native-aac-libav.mjs').write_text(normalized)
    print(json.dumps({'files': {p.name: digest(p) for p in sorted(output.iterdir()) if p.is_file()}}))

if __name__ == '__main__': main()
