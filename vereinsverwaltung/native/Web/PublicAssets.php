<?php
declare(strict_types=1);

namespace Kulturhus\Native\Web;

/** One fixed registry shared by the native installed website and local router. */
final class PublicAssets
{
    public static function sources(): array
    {
        $site = __DIR__ . "/assets/site/";
        $manager = dirname(__DIR__, 2) . "/public/";
        $assets = [
            "/assets/manager-interface.js" => $manager . "manager-interface.js",
            "/assets/admin-appearance.css" => $manager . "admin-appearance.css",
            "/assets/admin-appearance.js" => $manager . "admin-appearance.js",
            "/assets/form-validation.css" => $manager . "form-validation.css",
            "/assets/form-validation.js" => $manager . "form-validation.js",
            "/assets/meta-webhooks.css" => __DIR__ . "/assets/meta-webhooks.css",
            "/assets/comments.css" => $manager . "comments.css",
            "/assets/comments.js" => $manager . "comments.js",
            "/assets/system-status.css" => $manager . "system-status.css",
            "/assets/newsletter-classic.css" => __DIR__ . "/assets/newsletter-classic.css",
            "/assets/newsletter-view.css" => __DIR__ . "/assets/newsletter-view.css",
            "/assets/newsletter-forms.css" => __DIR__ . "/assets/newsletter-forms.css",
            "/assets/kulturhus-site.css" => $site . "site.css",
            "/assets/kulturhus-themes.css" => $site . "theme-variants.css",
            "/assets/kulturhus-enhancements.css" => $site . "enhancements.css",
            "/assets/website-designs.css" => $site . "designs.css",
            "/assets/saxophone.jpg" => $site . "saxophone.jpg",
            "/assets/saxophone.webp" => $site . "saxophone.webp",
            "/assets/application-form.css" => $manager . "application-form.css",
            "/assets/application-form.js" => $manager . "application-form.js",
            "/favicon.ico" => $site . "favicon.ico",
        ];
        foreach (BootstrapAssets::sources() as $name => $path) {
            $assets["/assets/" . $name] = $path;
        }
        foreach (
            [
                "articles.css",
                "gallery.css",
                "gallery.js",
                "richtext.css",
                "richtext.js",
                "membership.css",
                "public.js",
                "native.css",
                "tickets.css",
                "tickets.js",
            ]
            as $name
        ) {
            $assets["/assets/" . $name] = __DIR__ . "/assets/" . $name;
        }
        $assets['/assets/ticket-qr-decoder.js']=$manager.'voucher-jsqr-1.4.0.js';
        // Existing mail/layout links retain their exact project-owned artwork.
        foreach (
            [
                "saxophone.jpg",
                "saxophone.webp",
                "favicon.svg",
                "favicon.ico",
                "favicon-32.png",
                "apple-touch-icon.png",
            ]
            as $name
        ) {
            $assets["/site/assets/kulturhus-public/" . $name] = $site . $name;
        }
        return $assets;
    }

    public static function mime(string $file): string
    {
        return match (pathinfo($file, PATHINFO_EXTENSION)) {
            "css" => "text/css; charset=utf-8",
            "js" => "text/javascript; charset=utf-8",
            "jpg" => "image/jpeg",
            "webp" => "image/webp",
            "png" => "image/png",
            "svg" => "image/svg+xml",
            "ico" => "image/x-icon",
        };
    }
}
