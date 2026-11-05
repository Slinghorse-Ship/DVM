<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use GdImage;
use RuntimeException;

/** Reads only bounded JPEG metadata; needs GD, not the optional EXIF extension. */
final class ImageOrientation
{
    private const MAX_METADATA_BYTES = 1024 * 1024;
    private const MAX_MARKERS = 256;

    /** Caller has already validated a local JPEG and budgeted two pixel canvases. */
    public static function apply(GdImage $image, string $jpeg): GdImage
    {return self::oriented($image,self::read($jpeg));}

    /** Same bounded metadata parser for an already budgeted in-memory JPEG; no temporary file. */
    public static function applyBytes(GdImage $image, string $jpeg): GdImage
    {
        $stream=@fopen('php://memory','w+b');
        if(!$stream)throw new RuntimeException('Bildmetadaten konnten nicht gelesen werden.');
        try{
            $metadata=substr($jpeg,0,self::MAX_METADATA_BYTES);
            if(fwrite($stream,$metadata)!==strlen($metadata)||!rewind($stream))throw new RuntimeException('Bildmetadaten konnten nicht gelesen werden.');
            return self::oriented($image,self::scan($stream));
        }finally{fclose($stream);}
    }

    private static function oriented(GdImage $image, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            5, 6, 7 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = @imagerotate($image, $angle, 0);
            if (!$rotated) {
                throw new RuntimeException(
                    "Bildausrichtung konnte nicht korrigiert werden.",
                );
            }
            $image = $rotated;
        }
        if (
            in_array($orientation, [2, 5], true) &&
            !imageflip($image, IMG_FLIP_HORIZONTAL)
        ) {
            throw new RuntimeException(
                "Bildspiegelung konnte nicht korrigiert werden.",
            );
        }
        if (
            in_array($orientation, [4, 7], true) &&
            !imageflip($image, IMG_FLIP_VERTICAL)
        ) {
            throw new RuntimeException(
                "Bildspiegelung konnte nicht korrigiert werden.",
            );
        }
        return $image;
    }

    public static function read(string $jpeg): int
    {
        if (
            preg_match("~^[a-z][a-z0-9+.-]*://~i", $jpeg) ||
            is_link($jpeg) ||
            !is_file($jpeg)
        ) {
            throw new RuntimeException(
                "Bildausrichtung benötigt eine lokale Datei.",
            );
        }
        $stream = @fopen($jpeg, "rb");
        if (!$stream) {
            throw new RuntimeException(
                "Bildmetadaten konnten nicht gelesen werden.",
            );
        }
        try {return self::scan($stream);} finally {fclose($stream);}
    }

    /** @param resource $stream */
    private static function scan($stream): int
    {
        if (fread($stream, 2) !== "\xff\xd8") {
            return 1;
        }
        $scanned = 2;
        for ($markers = 0; $markers < self::MAX_MARKERS; $markers++) {
            if (fread($stream, 1) !== "\xff") {
                return 1;
            }
            $scanned++;
            do {
                $byte = fread($stream, 1);
                $scanned++;
                if ($byte === "" || $scanned > self::MAX_METADATA_BYTES) {
                    return 1;
                }
            } while ($byte === "\xff");
            $marker = ord($byte);
            if ($marker === 0xda || $marker === 0xd9 || $marker === 0x00) {
                return 1;
            }
            if ($marker === 0x01 || ($marker >= 0xd0 && $marker <= 0xd7)) {
                continue;
            }
            $header = fread($stream, 2);
            if (strlen($header) !== 2) {
                return 1;
            }
            $length = unpack("n", $header)[1];
            $scanned += $length;
            if ($length < 2 || $scanned > self::MAX_METADATA_BYTES) {
                return 1;
            }
            $payload = $length === 2 ? "" : fread($stream, $length - 2);
            if (strlen($payload) !== $length - 2) {
                return 1;
            }
            if ($marker === 0xe1 && str_starts_with($payload, "Exif\0\0")) {
                return self::tiff(substr($payload, 6));
            }
        }
        return 1;
    }

    private static function tiff(string $bytes): int
    {
        $size = strlen($bytes);
        $order = substr($bytes, 0, 2);
        if ($size < 8 || !in_array($order, ["II", "MM"], true)) {
            return 1;
        }
        $short = static fn(int $at): int => unpack(
            $order === "II" ? "v" : "n",
            substr($bytes, $at, 2),
        )[1];
        $long = static fn(int $at): int => unpack(
            $order === "II" ? "V" : "N",
            substr($bytes, $at, 4),
        )[1];
        if ($short(2) !== 42) {
            return 1;
        }
        $ifd = $long(4);
        if ($ifd < 8 || $ifd > $size - 2) {
            return 1;
        }
        $count = $short($ifd);
        if ($count > 1024 || $count * 12 > $size - $ifd - 2) {
            return 1;
        }
        for ($index = 0; $index < $count; $index++) {
            $at = $ifd + 2 + $index * 12;
            if (
                $short($at) === 0x112 &&
                $short($at + 2) === 3 &&
                $long($at + 4) === 1
            ) {
                $value = $short($at + 8);
                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }
        return 1;
    }
}
