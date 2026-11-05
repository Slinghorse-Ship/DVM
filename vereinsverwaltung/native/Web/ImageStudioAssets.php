<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Ordered, project-owned assets for both manager entry points. */
final class ImageStudioAssets
{
    public static function sources(): array
    {
        return [
            "image-studio.css" => "image-studio.css",
            "image-export.js" => "image-export.js",
            "image-studio-clones.js" => "image-studio/clones.js",
            "image-studio-clone-painter.js" => "image-studio/clone-painter.js",
            "image-studio-media.js" => "image-studio/media.js",
            "image-studio-renderer.js" => "image-studio/renderer.js",
            "image-studio-controls.js" => "image-studio/controls.js",
            "image-studio.js" => "image-studio.js",
        ];
    }

    public static function tags(string $prefix): string
    {
        $html = "";
        foreach (self::sources() as $name => $path) {
            $url = Html::e($prefix . $name);
            $html .= str_ends_with($name, ".css")
                ? '<link rel="stylesheet" href="' . $url . '">'
                : '<script src="' . $url . '" defer></script>';
        }
        return $html;
    }
}
