# Local camera decoders and corresponding sources

These files provide browser-only conversion of a **newly selected** camera photo
to JPEG. No CDN, external photo service, shared memory, or PHP shell is needed.
Ordinary JPEG/PNG/PDF input bytes and existing stored originals are untouched.
The project-owned import and worker adapters live in `public/photo-import.js`
and `native/Web/assets/photo-import-worker.js`.

`provenance.json` lists the SHA-256 and size of every supplied component, its
corresponding source where applicable, and the original npm member hashes. The
exact npm versions, SHA-512 archive integrity, upstream commits and licence
information are recorded in the two `*-npm-package.json` files. The binaries
are unmodified. Only three occurrences of local worker/wasm asset names in the
LibRaw JavaScript are changed; the HEIF bundle is byte-identical to npm.

- LibRaw 0.22.1: choose LGPL-2.1-or-later or CDDL-1.0, copyright and both licence
  texts included. The JavaScript wrapper has its own included ISC licence.
- Little CMS 2.19.1: MIT, source and licence included.
- Emscripten 6.0.10 ports used by the RAW wrapper: libjpeg 9f, libpng 1.6.58,
  zlib 1.3.2. Their exact source archives are verified against upstream port
  SHA-512 hashes; source, licence notices and port definitions are included.
- libheif-js 1.23.5 / libheif at `413e2a87e6a70b3eccc3a3adc5801179dd2d9e00`:
  LGPL-3.0-or-later; JS bindings, C++ decoder, upstream build wrapper and licence
  texts included. The HEVC decoder is libde265 1.0.15, corresponding source
  archive included (LGPL-3.0-or-later, its COPYING files are in the archive).

## Rebuilding or replacing the decoders

LibRaw: unpack `libraw-source.tar.gz` and `lcms-source.tar.gz`. The exact wrapper
and build inputs are the `source-raw-*` files. Restore their original names
(`index.js`, `worker.js`, `build.js`, `libraw_wrapper.cpp`, `compileLibraw.sh`,
`makefile`, `package.json`), use Emscripten 6.0.10 and the included port sources,
and follow `source-raw-readme.md`. `compileLibraw.sh` documents the thread removal,
JPEG/deflate DNG flags, single-threaded LCMS/LibRaw compilation and final link.
The supplied build is non-threaded and therefore does not require
SharedArrayBuffer or cross-origin isolation. `source-raw-build.js` bundles the
three modules; apply the local filename replacements documented above.

HEIF: unpack `libheif-build-source.tar.gz`, place the contents of
`libheif-decoder-source.tar.gz` in its `libheif` submodule directory, use
Emscripten 3.1.61, and follow its `.github/workflows/emscripten.yml`. The decoder's
`build-emscripten.sh` defaults to libde265 1.0.15; its matching source is supplied.
Then unpack `libheif-source.tar.gz` (the JS binding), run its locked dependency
installation and `scripts/install.js` against the local build artefact. The
runtime bundle is `libheif-wasm/libheif-bundle.js` renamed `photo-libheif.js`.

Replacement builds retain the same public decoder API and can replace these
individual locally served files. A maintainer then updates provenance and the
offline source inventory. Users receive the corresponding sources in the
ordinary offline installation package; the asset endpoint serves only the seven
fixed runtime assets, never arbitrary source paths.

The application keeps `script-src 'self'`. The RAW decoder worker alone allows
trusted Embind dynamic call wrappers; the HEIF worker permits only WebAssembly
compilation. Both prohibit child workers and have no DOM. The libraries are
loaded only when an actual HEIF/RAW photo is selected, then terminated after
conversion or cancellation.
