<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use Kulturhus\Native\Shared\HttpValidators;

/** Only called after a fixed public asset has been selected by its owning router. */
final class AssetResponse
{
    public static function send(string $file,string $mime):bool
    {
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return true;}
        if(!is_file($file)){http_response_code(404);return true;}
        $hash=hash_file('sha256',$file);$etag='"'.$hash.'"';
        $version=$_GET['v']??null;$immutable=is_string($version)&&hash_equals(substr($hash,0,16),$version);
        header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('ETag: '.$etag);
        header('Cache-Control: '.($immutable?'public, max-age=31536000, immutable':'public, max-age=0, must-revalidate'));
        if(HttpValidators::unchanged($method,$_SERVER['HTTP_IF_NONE_MATCH']??null,$etag)){http_response_code(304);return true;}
        header('Content-Length: '.filesize($file));if($method!=='HEAD')readfile($file);return true;
    }
}
