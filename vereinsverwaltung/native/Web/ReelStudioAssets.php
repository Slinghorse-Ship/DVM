<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Same-origin media codecs; WASM execution is restricted to the audio worker. */
final class ReelStudioAssets
{
    public static function sources(): array
    {
        $root = dirname(__DIR__, 2) . '/resources/';
        return [
            'video-recovery.js' => __DIR__ . '/assets/video-recovery.js',
            'video-reuse.js' => __DIR__ . '/assets/video-reuse.js',
            'video-file.js' => __DIR__ . '/assets/video-file.js',
            'video-archive.js' => __DIR__ . '/assets/video-archive.js',
            'video-policy.js' => __DIR__ . '/assets/video-policy.js',
            'video-metadata.js' => __DIR__ . '/assets/video-metadata.js',
            'video-upload.js' => __DIR__ . '/assets/video-upload.js',
            'video-studio.js' => __DIR__ . '/assets/video-studio.js',
            'native-mediabunny.mjs' => $root . 'mediabunny/mediabunny-1.61.3.min.mjs',
            'native-aac-encoder.mjs' => $root . 'reel-aac/native-aac-encoder.mjs',
            'native-aac-worker.js' => $root . 'reel-aac/native-aac-worker.js',
            'native-aac-libav.mjs' => $root . 'reel-aac/native-aac-libav.mjs',
        ];
    }

    public static function serve(string $name): bool
    {
        $file = self::sources()[$name] ?? null;
        if ($file === null) return false;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return true;
        }
        if ($name === 'native-aac-worker.js') {
            header("Content-Security-Policy: default-src 'none'; script-src 'self' 'wasm-unsafe-eval'; connect-src 'none'; worker-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        }
        return AssetResponse::send($file,'text/javascript; charset=utf-8');
    }
}
