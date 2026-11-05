# Corresponding sources and replacement builds

The distributed JavaScript/WASM files are byte-identical to the npm 0.0.44
package; no local rebuild is claimed. Check its SHA512 against
`upstream-package.json` and the supplied runtime SHA256 in `provenance.json`.

Exact source inputs are supplied in 28 archives:

- magick-wasm `8d346777ce0e172dacdb797a65346569425c43af`;
- Magick.Native `999720f7ebe03d5a0029536f1586a2cb82b500ae`;
- ImageMagick `ad98b244c995d2e3051757fa3b7855f45b550d24`;
- ImageMagick/Dependencies `1c21ff03809da9cc544427fcd04e965d1558d06f`,
  including its exact wasm-subset `clone-dependencies.sh` and 24 pinned codec sources.

Use a separate owned build directory. Extract archives with their single top
component removed. Put ImageMagick in `Magick.Native/src/ImageMagick/ImageMagick`,
the dependency build repository beside it as `Dependencies`, and each codec
under `Dependencies/Dependencies/<name>`, as specified by the included clone script.
These sources include their original upstream licence and build files.

Follow the included Magick.Native `.github/workflows/main.yml` wasm-x86/Q8 job,
Emscripten 6.0.6, `build/wasm-x86/settings.sh`, and dependency build instructions.
Build the static wasm dependency artefact from the supplied source tree instead
of downloading different dependency versions. The workflow names the exact
`build/shared/build.imagemagick.sh`, `build/shared/build.native.sh` and
`build/wasm-x86/copy.native.sh` commands and working directories.

For the TypeScript wrapper use its included package-lock/build configuration,
point the `@dlemstra/magick-native` build input at the newly built native module,
and build its `dist/index.js`. Copy only the resulting 32-bit WASM/module and
complete licences/notices. Update provenance and the source inventory for a
replacement build, then rerun actual 48 MP/alpha/EXIF, cold PHP64M, atomic upload,
installed-WebBundle and browser contracts. Different rebuild outputs must receive
new hashes; do not describe them as the original npm bytes.
