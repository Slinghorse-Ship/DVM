"""Called only after invoice_ocr_sandbox.activate; never decode in the web process."""
import pathlib, sys

def decode(source, config):
    package=config.get('heif_package_path')
    if package:
        package=pathlib.Path(package)
        if not package.is_absolute() or not package.is_dir(): raise ValueError('HEIF runtime missing')
        sys.path.insert(0,str(package))
    import pillow_heif
    from PIL import Image
    if tuple(int(v) for v in pillow_heif.libheif_version().split('.')[:3]) < (1,23,3):
        raise ValueError('Updated HEIF runtime required')
    pillow_heif.options.DECODE_THREADS=1
    pillow_heif.options.THUMBNAILS=False
    pillow_heif.options.DEPTH_IMAGES=False
    pillow_heif.options.AUX_IMAGES=False
    pillow_heif.options.DISABLE_SECURITY_LIMITS=False
    Image.MAX_IMAGE_PIXELS=20000000
    heif=pillow_heif.open_heif(source,convert_hdr_to_8bit=True)
    if len(heif)!=1: raise ValueError('Only one primary photo supported')
    width,height=heif.size
    if width<=0 or height<=0 or width*height>20000000: raise ValueError('Image pixel limit')
    image=heif.to_pillow()
    if image.width*image.height>20000000: raise ValueError('Decoded image pixel limit')
    # Retain pixels only: no EXIF, profiles, thumbnails or auxiliary metadata in derivative.
    rgb=image.convert('RGB');clean=Image.new('RGB',rgb.size);clean.paste(rgb);image.close();rgb.close()
    return clean
