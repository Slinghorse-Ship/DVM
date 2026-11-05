<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
final class Assets
{
    public static function sources(): array
    {
        $web = dirname(__DIR__) . "/Web/assets/";
        $sources = \Kulturhus\Native\Web\BootstrapAssets::sources() + \Kulturhus\Native\Web\PhotoImportAssets::scripts() + [
            "native-manager.js" => __DIR__ . "/assets/manager.js",
            "native-manager.css" => __DIR__ . "/assets/manager.css",
            "native-website-workspace.css" => $web . "website-workspace.css",
            "native-admin-appearance.css" => dirname(__DIR__,2) . "/public/admin-appearance.css",
            "native-admin-appearance.js" => dirname(__DIR__,2) . "/public/admin-appearance.js",
            "native-cms-transfer.css" => dirname(__DIR__) . "/CmsTransfer/transfer.css",
            "native-comments.css" => dirname(__DIR__, 2) . "/public/comments.css",
            "native-api.css" => dirname(__DIR__) . "/Api/assets/api.css",
            "native-api.js" => dirname(__DIR__) . "/Api/assets/api.js",
            "system.css" => dirname(__DIR__) . "/System/system.css",
            "native-meta-webhooks.css" => $web . "meta-webhooks.css",
            "native-block-editor.js" => $web . "editor.js",
            "native-block-editor.css" => $web . "editor.css",
            "native-richtext.js" => $web . "richtext.js",
            "native-richtext.css" => $web . "richtext.css",
            "native-reference-picker.js" => $web . "reference-picker.js",
            "native-press-recipients.js" => $web . "press-recipients.js",
            "native-newsletter-save.js" => $web . "newsletter-save.js",
            "native-press-recipients.css" => $web . "press-recipients.css",
            "native-compose-touch.css" => $web . "compose-touch.css",
            "native-compose-preview.js" => $web . "compose-preview.js",
            "native-compose-preview.css" => $web . "compose-preview.css",
            "native-social-calendar.css" => $web . "social-calendar.css",
            "native-editorial-hub.css" => $web . "editorial-hub.css",
            "native-social-library.css" => $web . "social-library.css",
            "native-page-live.js" => $web . "page-live.js",
            "native-page-live.css" => $web . "page-live.css",
            "native-page-media-picker.js" => $web . "page-media-picker.js",
            "native-page-media-picker.css" => $web . "page-media-picker.css",
            "native-field-conditions.js" => $web . "field-conditions.js",
            "native-field-groups.js" => $web . "field-groups.js",
            "native-typed-fields.js" => $web . "typed-fields.js",
            "native-typed-fields.css" => $web . "typed-fields.css",
            "native-file-library.css" => dirname(__DIR__) . "/Files/Web/library.css",
            "native-template-settings.css" => $web . "template-settings.css",
            "native-extensions.css" => dirname(__DIR__) . "/Extensions/assets/extensions.css",
            "native-field-groups.css" => $web . "field-groups.css",
            "native-page-versions.css" => $web . "page-versions.css",
            "native-content-lists.css" => $web . "content-lists.css",
            "native-frontend-editor.css" => dirname(__DIR__) . "/Web/FrontendEditor/editor.css",
            "native-frontend-editor.js" => dirname(__DIR__) . "/Web/FrontendEditor/editor.js",
            "native-content-sections.css" => $web . "content-sections.css",
            "native-website-designs.css" => $web . "site/designs.css",
            "native-gallery.css" => $web . "gallery.css",
            "native-media-library.css" => $web . "media-library.css",
            "native-gallery.js" => $web . "gallery.js",
            "native-chat.css" => $web . "chat.css",
            "native-chat.js" => $web . "chat.js",
            "native-articles.css" => $web . "articles.css",
            "native-publications.css" => $web . "publications.css",
            "native-publications.js" => $web . "publications.js",
            "native-communication-contacts.css" =>
                $web . "communication-contacts.css",
        ];
        foreach (
            \Kulturhus\Native\Web\ImageStudioAssets::sources()
            as $name => $path
        ) {
            $sources["native-" . $name] = $web . $path;
        }
        $sources["native-video-recovery.js"] = $web . "video-recovery.js";
        $sources["native-video-reuse.js"] = $web . "video-reuse.js";
        $sources["native-video-file.js"] = $web . "video-file.js";
        $sources["native-video-archive.js"] = $web . "video-archive.js";
        $sources["native-video-policy.js"] = $web . "video-policy.js";
        $sources["native-video-metadata.js"] = $web . "video-metadata.js";
        $sources["native-video-upload.js"] = $web . "video-upload.js";
        $sources["native-video-studio.js"] = $web . "video-studio.js";
        return $sources;
    }
    public static function tags(): string
    {
        $html = "";
        foreach (self::sources() as $name => $path) {
            // The core framework loads once, before the manager's own styles, in its shell.
            if (
                isset(\Kulturhus\Native\Web\BootstrapAssets::sources()[$name]) || isset(\Kulturhus\Native\Web\PhotoImportAssets::scripts()[$name])
            ) {
                continue;
            }
            $attrs =
                $name === "native-chat.js"
                    ? ' data-status-url="' .
                        htmlspecialchars(
                            Routes::endpoint("chat-status"),
                            ENT_QUOTES,
                            "UTF-8",
                        ) .
                        '" data-view-url="' .
                        htmlspecialchars(
                            Routes::endpoint("chat-view"),
                            ENT_QUOTES,
                            "UTF-8",
                        ) .
                        '" data-room-url="/vereinsverwaltung/?view=chat&amp;native_item="'
                    : "";
            $html .= str_ends_with($name, ".css")
                ? '<link rel="stylesheet" href="' . $name . '?v=' . substr(hash_file('sha256',$path),0,16) . '">'
                : '<script src="' . $name . '"' . $attrs . " defer></script>";
        }
        return $html;
    }
    public static function serve(string $name): bool
    {
        if(\Kulturhus\Native\Web\ReelStudioAssets::serve($name))return true;
        if(\Kulturhus\Native\Web\PhotoImportAssets::serve($name))return true;
        $file = self::sources()[$name] ?? null;
        if (!$file) {
            return false;
        }
        return \Kulturhus\Native\Web\AssetResponse::send($file,(str_ends_with($name,'.css')?'text/css':'text/javascript').'; charset=utf-8');
    }
}
