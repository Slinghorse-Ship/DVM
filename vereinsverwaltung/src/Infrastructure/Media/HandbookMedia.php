<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Only called after the normal identity and handbook access checks. */
final class HandbookMedia
{
    /** @return array{0:int,1:int}|null A single inclusive byte interval. */
    public static function byteRange(string $header, int $size): ?array
    {
        if ($header === '') return null;
        if ($size < 1 || !preg_match('/^bytes=(\d*)-(\d*)$/D', $header, $m) || ($m[1] === '' && $m[2] === '')) {
            throw new \InvalidArgumentException('Unsupported range');
        }
        if ($m[1] === '') {
            $length = (int)$m[2];
            if ($length < 1) throw new \InvalidArgumentException('Empty range');
            return [max(0, $size - $length), $size - 1];
        }
        $start = (int)$m[1];
        $end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
        if ($start >= $size || $end < $start) throw new \InvalidArgumentException('Range outside file');
        return [$start, $end];
    }

    public static function serve(string $name, string $root): never
    {
        $manifest = json_decode((string)file_get_contents($root.'/handbook-media.json'), true, 512, JSON_THROW_ON_ERROR);
        $mime = $manifest[$name] ?? null;
        $allowed = ['mp4'=>'video/mp4', 'vtt'=>'text/vtt; charset=utf-8', 'png'=>'image/png'];
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (!preg_match('/^[a-z0-9-]+\.(mp4|vtt|png)$/D', $name) || !isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
            http_response_code(404); exit('Medium nicht gefunden.');
        }
        $path = $root.'/handbook/'.$name;
        $size = filesize($path);
        if ($size === false || !is_file($path)) { http_response_code(404); exit('Medium nicht gefunden.'); }
        header('Content-Type: '.$mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        header('Cache-Control: private, no-store');
        header('Accept-Ranges: bytes');
        try { $range = self::byteRange((string)($_SERVER['HTTP_RANGE'] ?? ''), $size); }
        catch (\InvalidArgumentException) { http_response_code(416); header('Content-Range: bytes */'.$size); exit; }
        [$start, $end] = $range ?? [0, $size - 1];
        if ($range !== null) { http_response_code(206); header('Content-Range: bytes '.$start.'-'.$end.'/'.$size); }
        header('Content-Length: '.($end - $start + 1));
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
        $file = fopen($path, 'rb');
        if ($file === false) { http_response_code(404); exit; }
        fseek($file, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($file) && !connection_aborted()) {
            $bytes = fread($file, min(65536, $remaining));
            if ($bytes === false || $bytes === '') break;
            echo $bytes; $remaining -= strlen($bytes);
        }
        fclose($file); exit;
    }
}
