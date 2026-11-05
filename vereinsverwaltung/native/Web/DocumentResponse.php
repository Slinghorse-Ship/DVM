<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

final class DocumentResponse
{
    public static function send(?array $file,string $method): void
    {
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");
        if (!in_array($method,['GET','HEAD'],true)) {http_response_code(405);header('Allow: GET, HEAD');return;}
        if (!$file) {http_response_code(404);return;}
        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename=website-dokument.pdf; filename*=UTF-8''".rawurlencode($file['name']));
        header('Content-Length: '.$file['bytes']);
        if ($method!=='HEAD') readfile($file['path']);
    }
}
