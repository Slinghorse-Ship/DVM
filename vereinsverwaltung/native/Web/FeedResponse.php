<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use Kulturhus\Native\Shared\HttpValidators;

/** Only already-authorized immutable public feed representations belong here. */
final class FeedResponse
{
    /** Answer preflight or refused methods before generating a feed body. */
    public static function methodResponse(string $method,bool $json=false):bool
    {
        if($json){
            // Public JSON has the same representation for all origins, without credentials.
            header('Access-Control-Allow-Origin: *');header('Access-Control-Expose-Headers: ETag');
            header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
            header('Access-Control-Allow-Headers: If-None-Match');header('Access-Control-Max-Age: 600');
        }
        if(in_array($method,['GET','HEAD'],true))return false;
        header('Allow: '.($json?'GET, HEAD, OPTIONS':'GET, HEAD'));
        if($json&&$method==='OPTIONS'){
            http_response_code(204);header('Cache-Control: public, max-age=0, must-revalidate');return true;
        }
        http_response_code(405);header('Cache-Control: no-store');return true;
    }

    public static function send(string $body,string $type,string $method,bool $json=false):bool
    {
        if(self::methodResponse($method,$json))return true;
        header('Content-Type: '.$type);
        // Cancellation, withdrawal or a changed public revision must revalidate immediately.
        header('Cache-Control: public, max-age=0, must-revalidate');
        $etag='"'.hash('sha256',$body).'"';header('ETag: '.$etag);
        if(HttpValidators::unchanged($method,$_SERVER['HTTP_IF_NONE_MATCH']??'',$etag)){
            http_response_code(304);return true;
        }
        header('Content-Length: '.strlen($body));if($method!=='HEAD')echo $body;
        return true;
    }

    public static function sendDocument(FeedDocument $document,string $type,string $method,bool $json=false,?string $filename=null):bool
    {
        try{
            if(self::methodResponse($method,$json))return true;
            if($filename!==null){
                if(!preg_match('/\A[a-z0-9][a-z0-9.-]{0,100}\.ics\z/D',$filename))throw new \InvalidArgumentException('Ungültiger Kalenderdateiname.');
                if($document->recordCount()!==1){
                    http_response_code(404);header('Cache-Control: no-store');header('Content-Type: text/plain; charset=utf-8');
                    if($method!=='HEAD')echo 'Veranstaltung nicht verfügbar.';return true;
                }
                header('Content-Disposition: attachment; filename="'.$filename.'"');
            }
            header('Content-Type: '.$type);header('Cache-Control: public, max-age=0, must-revalidate');
            $etag=$document->etag();header('ETag: '.$etag);
            if(HttpValidators::unchanged($method,$_SERVER['HTTP_IF_NONE_MATCH']??'',$etag)){http_response_code(304);return true;}
            header('Content-Length: '.$document->length());if($method!=='HEAD')$document->sendBody();return true;
        }finally{$document->close();}
    }
}
