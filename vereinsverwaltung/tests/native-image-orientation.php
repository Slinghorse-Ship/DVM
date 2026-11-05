<?php
declare(strict_types=1);

ini_set("zend.exception_ignore_args", "1");
require dirname(__DIR__) . "/native/bootstrap.php";

use Kulturhus\Native\Cms\{ImageOrientation, Media, Pages, Schema};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\TestSupport;

$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $checks++;
    echo "PASS " . $label . PHP_EOL;
}

function exifTiff(int $orientation, bool $little = true): string
{
    return ($little
        ? "II" . pack("vVv", 42, 8, 1) . pack("vvVv", 0x112, 3, 1, $orientation)
        : "MM" .
            pack("nNn", 42, 8, 1) .
            pack("nnNn", 0x112, 3, 1, $orientation)) .
        "\0\0" .
        pack("N", 0);
}

function jpegSegment(string $bytes, string $payload, int $marker = 0xe1): string
{
    return substr($bytes, 0, 2) .
        "\xff" .
        chr($marker) .
        pack("n", strlen($payload) + 2) .
        $payload .
        substr($bytes, 2);
}

/** Four distinct large quadrants make rotation and mirroring independently observable. */
function pixelJpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    foreach (
        [[235, 15, 20], [15, 210, 25], [15, 25, 235], [235, 210, 15]]
        as $i => $rgb
    ) {
        $x = ($i % 2) * intdiv($width, 2);
        $y = intdiv($i, 2) * intdiv($height, 2);
        imagefilledrectangle(
            $image,
            $x,
            $y,
            $x + intdiv($width, 2) - 1,
            $y + intdiv($height, 2) - 1,
            imagecolorallocate($image, ...$rgb),
        );
    }
    ob_start();
    try {
        if (!imagejpeg($image, null, 98)) {
            throw new RuntimeException("Synthetic JPEG unavailable.");
        }
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
        unset($image);
    }
}

function corners(string $path): array
{
    $image = imagecreatefromjpeg($path);
    $colors = [[235, 15, 20], [15, 210, 25], [15, 25, 235], [235, 210, 15]];
    $result = [];
    try {
        foreach (
            [[0.25, 0.25], [0.75, 0.25], [0.25, 0.75], [0.75, 0.75]]
            as [$x, $y]
        ) {
            $color = imagecolorsforindex(
                $image,
                imagecolorat(
                    $image,
                    (int) (imagesx($image) * $x),
                    (int) (imagesy($image) * $y),
                ),
            );
            $distance = array_map(
                static fn(array $rgb): int => ($color["red"] - $rgb[0]) ** 2 +
                    ($color["green"] - $rgb[1]) ** 2 +
                    ($color["blue"] - $rgb[2]) ** 2,
                $colors,
            );
            $result[] = array_search(min($distance), $distance, true);
        }
        return $result;
    } finally {
        unset($image);
    }
}

$db = TestSupport::db();
$actors = TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, $actors["admin"]);
$root =
    dirname((string) getenv("KH_NATIVE_TEST_CONFIG")) .
    "/orientation-" .
    bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$media = new Media($db, $access, $root . "/media");
$pages = new Pages($db, $access);
$jpeg = pixelJpeg(80, 48);
$expected = [
    1 => [0, 1, 2, 3],
    2 => [1, 0, 3, 2],
    3 => [3, 2, 1, 0],
    4 => [2, 3, 0, 1],
    5 => [0, 2, 1, 3],
    6 => [2, 0, 3, 1],
    7 => [3, 1, 2, 0],
    8 => [1, 3, 0, 2],
];
$marker = "SYNTHETIC_PRIVATE_CAMERA_METADATA";
$assets = [];
foreach ($expected as $orientation => $pixels) {
    $source = $root . "/camera-" . $orientation . ".jpg";
    $bytes = jpegSegment(
        $jpeg,
        "Exif\0\0" . exifTiff($orientation, $orientation % 2 === 1) . $marker,
    );
    $bytes = jpegSegment(
        $bytes,
        "http://ns.adobe.com/xap/1.0/" . "\0" . "<xmp>unrelated</xmp>",
    );
    file_put_contents($source, $bytes . "SYNTHETIC_TRAILING_UPLOAD_BYTES");
    $originalHash = hash_file("sha256", $source);
    check(
        ImageOrientation::read($source) === $orientation,
        "IFD0 orientation " .
            $orientation .
            " read past unrelated APP1 metadata",
    );
    $asset = $media->upload(
        $source,
        "camera-" . $orientation . ".jpg",
        "Eigenes synthetisches Kamerafoto " . $orientation,
    );
    $assets[$orientation] = $asset;
    $dimensions = $orientation >= 5 ? [48, 80] : [80, 48];
    check(
        [$asset["width"], $asset["height"]] === $dimensions,
        "orientation " . $orientation . " stores upright dimensions",
    );
    foreach (["original", "large", "medium", "thumb"] as $size) {
        $file = $media->variant($asset["id"], $size);
        check(
            corners($file["path"]) === $pixels &&
                [$file["width"], $file["height"]] === $dimensions,
            "orientation " .
                $orientation .
                " correct actual " .
                $size .
                " pixels without upscaling",
        );
        $stored = file_get_contents($file["path"]);
        check(
            !str_contains($stored, "Exif") &&
                !str_contains($stored, $marker) &&
                !str_contains($stored, "SYNTHETIC_TRAILING_UPLOAD_BYTES"),
            "orientation " .
                $orientation .
                " " .
                $size .
                " strips camera metadata and trailing bytes",
        );
    }
    check(
        hash_file("sha256", $source) === $originalHash &&
            $media->publicVariant($asset["id"], "thumb") === null,
        "orientation " .
            $orientation .
            " preserves upload and stays private until publication",
    );
}

$album = $pages->save([
    "title" => "Synthetische Kamerabilder",
    "slug" => "orientation-gallery",
    "template" => "gallery",
    "media_ids" => array_column($assets, "id"),
]);
$album = $pages->publish($album["id"], $album["version"]);
check(
    corners($media->publicVariant($assets[5]["id"], "medium")["path"]) ===
        $expected[5],
    "published gallery serves the same upright original pixels",
);
$pages->unpublish($album["id"], $album["version"]);
check(
    $media->publicVariant($assets[5]["id"], "medium") === null,
    "withdrawal still removes upright public image access",
);

$normal = $root . "/normal.jpg";
file_put_contents($normal, $jpeg);
check(
    ImageOrientation::read($normal) === 1,
    "JPEG without orientation remains unchanged",
);
$bad = [
    "invalid-byte-order" => "ZZ" . substr(exifTiff(6), 2),
    "invalid-magic" => "II" . pack("vV", 43, 8) . substr(exifTiff(6), 8),
    "ifd-before-header" => "II" . pack("vV", 42, 0) . substr(exifTiff(6), 8),
    "ifd-outside-segment" => "MM" . pack("nN", 42, 0xffffffff),
    "truncated-entry" => substr(exifTiff(6), 0, 20),
    "excessive-entries" =>
        substr(exifTiff(6), 0, 8) . pack("v", 65535) . substr(exifTiff(6), 10),
    "unsupported-type" => substr_replace(exifTiff(6), pack("v", 4), 12, 2),
    "non-scalar-value" => substr_replace(exifTiff(6), pack("V", 2), 14, 4),
    "zero-orientation" => exifTiff(0),
    "out-of-range-orientation" => exifTiff(9),
];
foreach ($bad as $name => $tiff) {
    $source = $root . "/" . $name . ".jpg";
    file_put_contents($source, jpegSegment($jpeg, "Exif\0\0" . $tiff));
    check(
        ImageOrientation::read($source) === 1,
        $name . " safely falls back without following private offsets",
    );
    $asset = $media->upload(
        $source,
        $name . ".jpg",
        "Synthetisches Foto mit ungültiger Ausrichtung",
    );
    check(
        [$asset["width"], $asset["height"]] === [80, 48] &&
            corners($media->variant($asset["id"], "original")["path"]) ===
                $expected[1],
        $name .
            " decodable photo survives metadata fallback with original pixels",
    );
}
$truncated = $root . "/truncated-header.jpg";
foreach (
    [
        "\xff\xd8\xff\xe1\0",
        "\xff\xd8\xff\xe1\0\1",
        "\xff\xd8\xff\xe1\x7f\xffExif\0\0",
        "\xff\xd8" . str_repeat("\xff", 1100000),
    ]
    as $i => $bytes
) {
    file_put_contents($truncated, $bytes);
    check(
        ImageOrientation::read($truncated) === 1,
        "bounded truncated or excessive marker metadata " . $i,
    );
}
$large = $root . "/large-camera.jpg";
file_put_contents(
    $large,
    jpegSegment(pixelJpeg(3200, 1800), "Exif\0\0" . exifTiff(6)),
);
$asset = $media->upload(
    $large,
    "large-camera.jpg",
    "Großes synthetisches Kamerafoto",
);
foreach (
    [
        "original" => [1350, 2400],
        "large" => [1080, 1920],
        "medium" => [540, 960],
        "thumb" => [180, 320],
    ]
    as $size => $dimensions
) {
    $file = $media->variant($asset["id"], $size);
    check(
        [$file["width"], $file["height"]] === $dimensions &&
            corners($file["path"]) === $expected[6],
        "rotated " . $size . " scales from corrected dimensions and pixels",
    );
}
$process = proc_open(
    [
        PHP_BINARY,
        "-d",
        "disable_functions=exif_read_data",
        "-r",
        'ini_set("zend.exception_ignore_args","1"); require $argv[1]; if(function_exists("exif_read_data")) exit(1); echo Kulturhus\\Native\\Cms\\ImageOrientation::read($argv[2]);',
        dirname(__DIR__) . "/native/bootstrap.php",
        $large,
    ],
    [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
    $pipes,
);
if (!is_resource($process)) {
    throw new RuntimeException("Orientation test subprocess unavailable.");
}
fclose($pipes[0]);
$withoutExif = stream_get_contents($pipes[1]);
$diagnostic = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
check(
    proc_close($process) === 0 && $withoutExif === "6" && $diagnostic === "",
    "actual PHP process reads orientation with exif_read_data disabled",
);
check(
    !array_filter(
        get_declared_classes(),
        static fn(string $class): bool => str_starts_with(
            $class,
            "ProcessWire\\",
        ),
    ),
    "camera orientation uses no ProcessWire classes",
);
echo $checks .
    " native camera orientation checks passed; synthetic JPEG pixels, no external transport." .
    PHP_EOL;
