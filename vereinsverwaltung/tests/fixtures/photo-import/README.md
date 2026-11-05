# Eigene Kamera-Testbilder

Beide Dateien enthalten ausschließlich selbst erzeugte Testfarben, keine Kamera-,
Personen- oder Vereinsdaten. Kein Altbestandimport.

- `own-camera.heic`: 96 × 64 Pixel, RGB (35, 140, 210), mit Pillow/pillow-heif
  als echtes HEVC/HEIF encodiert; keine Metadaten.
- `own-camera.dng`: 128 × 96 Pixel, unkomprimierte 16-Bit-Bayerdaten,
  DNG 1.4. `python3 make-own-dng.py` erzeugt die identische Datei erneut.
  LibRaw liefert bei halber Größe 64 × 48 Pixel, sRGB etwa (255, 55, 95).

JPEG-Ergebnisse werden auf echte decodierte Abmessungen und Farbpixel geprüft.
HEIC neu erzeugen (Pillow + pillow-heif erforderlich):

```python
from PIL import Image
import pillow_heif
pillow_heif.register_heif_opener()
Image.new('RGB', (96, 64), (35, 140, 210)).save('own-camera.heic', format='HEIF', quality=90)
```

`own-jxl-preview.dng` is another project-owned synthetic DNG 1.7: lossless
JPEG XL compression 52546 of a 1024×768 16-bit CFA sensor, plus explicitly
declared 128×96 and 640×480 JPEG test previews. The two-color previews are
independent synthetic display fixtures, not a physical camera color claim.
Orientation 6 is declared. `make-own-jxl-dng.py` regenerates it using local
FFmpeg with libjxl and PHP GD; an independent FFmpeg decode must reproduce every
original 16-bit sensor byte. `own-jxl-preview.json` records that byte proof and
the exact DNG hash. No user/Apple photos or historical assets are imported.
The production fallback uses only bounded, declared preview IFDs and checks
actual JPEG geometry. It never searches arbitrary RAW bytes for a JPEG.
