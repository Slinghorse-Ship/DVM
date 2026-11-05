<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

/** Formats handled by the shared, local camera and raster pipelines. */
final class PhotoInput
{
    public static function accept():string
    {
        return 'image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif,.hif,.'.implode(',.',CameraPhotos::RAW_EXTENSIONS);
    }
}
