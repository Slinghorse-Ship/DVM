<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use Kulturhus\Native\Shared\{Access, HttpValidators, Identity};
use Kulturhus\Native\Cms\WebsiteDesign;
use PDO;

/** Public website shortcut; never includes private manager configuration. */
final class WebsiteManifest
{
    public static function owns(string $path): bool
    {
        return in_array($path, ['/site.webmanifest', '/website-icon.svg', '/website-icon-180.png', '/website-icon-192.png', '/website-icon-512.png'], true);
    }

    public static function theme(array $identity): string
    {
        return match ($identity['website_design'] ?? 'kulturhus') {
            'klar' => '#145c82', 'magazin' => '#913347', 'aktiv' => '#174da7',
            'natur' => '#326148', default => '#245640',
        };
    }

    public static function links(array $identity): string
    {
        return '<link rel="manifest" href="/site.webmanifest"><link rel="icon" type="image/svg+xml" href="/website-icon.svg"><link rel="apple-touch-icon" sizes="180x180" href="/website-icon-180.png"><meta name="theme-color" content="'.self::theme($identity).'">';
    }

    public static function serve(PDO $db, Access $public, string $path, string $method): bool
    {
        if (!self::owns($path)) return false;
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405); header('Allow: GET, HEAD'); return true;
        }
        $public->require('website', 'read');
        $identity = Identity::forClub($db, $public->club());
        $identity['website_design'] = WebsiteDesign::forClub($db, $public->club())['template'];
        $name = trim((string)($identity['name'] ?? '')) ?: 'Mein Verein';
        $color = self::theme($identity);
        $etag = '"'.hash('sha256', hash_file('sha256', __FILE__).'|'.$path.'|'.$name.'|'.$color).'"';
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, no-cache');
        header('ETag: '.$etag);
        $type = $path === '/site.webmanifest' ? 'application/manifest+json; charset=utf-8' : ($path === '/website-icon.svg' ? 'image/svg+xml' : 'image/png');
        header('Content-Type: '.$type);
        if (HttpValidators::unchanged($method, $_SERVER['HTTP_IF_NONE_MATCH'] ?? '', $etag)) {
            http_response_code(304); return true;
        }
        if ($path === '/site.webmanifest') {
            $body = json_encode([
                'id'=>'/', 'name'=>$name, 'short_name'=>mb_substr($name, 0, 24),
                'lang'=>'de', 'start_url'=>'/', 'scope'=>'/', 'display'=>'standalone',
                'background_color'=>'#ffffff', 'theme_color'=>$color,
                'icons'=>[
                    ['src'=>'/website-icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any maskable'],
                    ['src'=>'/website-icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable'],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $mark = mb_strtoupper(mb_substr($name, 0, 1));
            if ($path === '/website-icon.svg') {
                $body = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" fill="'.$color.'"/><text x="256" y="280" text-anchor="middle" dominant-baseline="middle" font-family="sans-serif" font-size="240" font-weight="bold" fill="white">'.htmlspecialchars($mark, ENT_QUOTES | ENT_XML1, 'UTF-8').'</text></svg>';
            } else {
                preg_match('/-(180|192|512)\.png\z/D', $path, $matches);
                $size = (int)$matches[1];
                $image = imagecreatetruecolor($size, $size);
                try {
                    imagefill($image, 0, 0, imagecolorallocate($image, hexdec(substr($color,1,2)), hexdec(substr($color,3,2)), hexdec(substr($color,5,2))));
                    $font = dirname(__DIR__, 2).'/resources/fonts/liberation-serif/LiberationSerif-Bold.ttf';
                    $fontSize = $size * .48;
                    $box = imagettfbbox($fontSize, 0, $font, $mark);
                    if ($box === false) throw new \RuntimeException('Website-Symbol nicht verfügbar.');
                    imagettftext($image, $fontSize, 0, (int)(($size-($box[2]-$box[0]))/2-$box[0]), (int)(($size-($box[1]-$box[7]))/2-$box[7]), imagecolorallocate($image,255,255,255), $font, $mark);
                    ob_start();
                    try { imagepng($image); $body = ob_get_contents(); }
                    finally { ob_end_clean(); }
                } finally { imagedestroy($image); }
            }
        }
        header('Content-Length: '.strlen($body));
        if ($method !== 'HEAD') echo $body;
        return true;
    }
}
