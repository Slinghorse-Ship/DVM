<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Pinned local Bootstrap assets shared by manager, account and public pages. */
final class BootstrapAssets
{
    public const VERSION = "6.0.0-alpha.1";
    public static function sources(): array
    {
        $vendor = dirname(__DIR__, 2) . "/public/vendor/bootstrap/";
        return [
            "bootstrap-" . self::VERSION . ".min.css" =>
                $vendor . "bootstrap-" . self::VERSION . ".min.css",
            "bootstrap-dvm.css" => __DIR__ . "/assets/bootstrap-dvm.css",
            "manager-controls.js" => __DIR__ . "/assets/manager-controls.js",
            "tickets.css" => __DIR__ . "/assets/tickets.css",
            "tickets.js" => __DIR__ . "/assets/tickets.js",
            "social-chat.css" => __DIR__ . "/assets/social-chat.css",
            "social-chat.js" => __DIR__ . "/assets/social-chat.js",
            "bootstrap-" . self::VERSION . ".bundle.min.js" =>
                $vendor . "bootstrap-" . self::VERSION . ".bundle.min.js",
        ];
    }
    public static function tags(string $prefix = "/assets/"): string
    {
        $html = "";
        foreach (self::sources() as $name => $path) {
            $url = Html::e(
                $prefix .
                    $name .
                    "?v=" .
                    substr(hash_file("sha256", $path), 0, 16),
            );
            $html .= str_ends_with($name, ".css")
                ? '<link rel="stylesheet" href="' . $url . '">'
                : '<script src="' . $url . '" type="module"></script>';
        }
        return $html;
    }
    /** Native public forms need the same local base and adapters without manager scripts. */
    public static function styles(string $prefix = "/assets/"): string
    {
        $html = "";
        foreach (self::sources() as $name => $path) {
            if (!in_array($name, ['bootstrap-' . self::VERSION . '.min.css', 'bootstrap-dvm.css'], true)) {
                continue;
            }
            $url = Html::e($prefix . $name . '?v=' . substr(hash_file('sha256', $path), 0, 16));
            $html .= '<link rel="stylesheet" href="' . $url . '">';
        }
        return $html;
    }
    public static function isModule(string $name): bool
    {
        return $name === "bootstrap-" . self::VERSION . ".bundle.min.js" || in_array($name, ['manager-controls.js', 'social-chat.js'], true);
    }
}
