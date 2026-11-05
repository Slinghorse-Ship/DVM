# Locally rebuilt browser AAC encoder

Runtime integration uses `@mediabunny/aac-encoder` 1.61.3 under MPL-2.0 and a
fresh build of FFmpeg 8.0's LGPL-2.1-or-later AAC encoder. GPL and nonfree code,
other encoders, shell programs, threads and filesystem support are disabled.
The worker initially uses 18 MiB of WASM memory and is bounded to 64 MiB.
No compiler or FFmpeg executable is required on the production PHP webspace.

The provided source-only AAC archive contains the original preferred TypeScript
and C sources, compiled source modules and the shared AAC/bitstream TypeScript
files from Mediabunny commit `9d36fc5779b254904bc609e66f2cd80ed8390b4b`.
Its bundled module has the upstream precompiled FFmpeg worker removed; none of
that precompiled payload is shipped or executed. Our runtime adaptations use a
fixed same-origin module worker and the existing same-instance Mediabunny import.
Both adapted JavaScript source modules are provided without minification.
`ffmpeg-8.0-source.tar.xz` is the complete corresponding FFmpeg source release.
The full MPL and LGPL texts are alongside these sources.

Upstream AAC package:
https://registry.npmjs.org/@mediabunny/aac-encoder/-/aac-encoder-1.61.3.tgz
SHA-512 (base64):
`SH4DdqqUJRqIErXbuxIze0ACbSyi3Rau90skDwp8YbwopFYm2dgPidLPbwqdmTd/sESHwq7Nf3nDbczAxtcVmA==`

FFmpeg source:
https://ffmpeg.org/releases/ffmpeg-8.0.tar.xz
SHA-256:
`b2751fccb6cc4c77708113cd78b561059b6fa904b24162fa0be2d60273d27b8e`

Rebuild using Emscripten 4.0.10, revision
`b7dc6e5747465580df5984e723b9d1f10d8e804b`, and Node:

```sh
python3 vereinsverwaltung/resources/reel-aac/build.py \
  --sources vereinsverwaltung/resources/reel-aac \
  --toolchain /path/to/emscripten-install \
  --output /tmp/reel-aac-rebuild
```

Pinned Linux toolchain build:
https://storage.googleapis.com/webassembly/emscripten-releases-builds/linux/8103ffedfb0c42d231c6af6859a5a1a832260b43/wasm-binaries.tar.xz
SHA-256:
`0183f887b56c3f8d4b45826cb49856a3324afb66236ad3c13944c0fd2550cbbc`
The compiler is a development dependency and is not included in the PHP bundle.
The build helper uses a private temporary directory and cache, checks pinned
sources and compiler, disables GPL/nonfree paths and produces the runtime files.
The provenance document records the resulting exact hashes.
