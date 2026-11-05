# Local raster preparation

Unmodified `@imagemagick/magick-wasm` 0.0.44 supplies the JavaScript module and
32-bit WASM ImageMagick 7.1.2-32 decoder. `upstream-package.json` records the npm
SHA-512 archive integrity and immutable upstream commit. `provenance.json` binds
each supplied runtime/licence file. Apache-2.0 applies to the wrapper; the complete
upstream NOTICE preserves ImageMagick and all compiled dependency licences.

The project-owned `tools/media/raster-photo.mjs` accepts only fixed JPEG, PNG and
WebP formats. It disables delegates and all other coders, preserves alpha and
applies EXIF orientation before stripping metadata. PHP supplies a local input
hash and bounded dimensions. The caller's source remains unchanged.

Node.js 22 or later is provided by the hosting system, selected through its PATH
or the private `VEREIN_PHOTO_NODE` operator setting. No npm installation, CDN or
photo service is used at runtime. PHP needs its four process-management functions.
The ordinary raster helper limits WASM memory to 512 MiB and JS old space to 128 MiB;
ImageMagick cache is 384 MiB with disk cache disabled. Sources are bounded to 256 MiB,
64 MP and 12000 px per edge. PHP terminates and reaps its child after 45 seconds.

The configured PHP memory limit and measured Zend memory apply to the PHP
request. Native HTTP buffering and the separate Node/codec process use additional
memory. Small acceptable sources bypass the additional decoder unchanged and
still use the canonical existing media validator.

All 28 corresponding source archives are supplied under `sources/`, including
build inputs and licence files. Their immutable commits, URLs, lengths and SHA256
are in `sources/source-manifest.json`. [Rebuild instructions](REBUILD.md).
