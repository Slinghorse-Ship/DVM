<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use PDO;
use Kulturhus\Native\Shared\SecretBox;
use Kulturhus\Native\Communication\MetaWebhookInbox;

/** Public signature-protected callback; no login, CSRF bypass of manager forms or provider writes. */
final class MetaWebhookEndpoints
{
    public static function serve(PDO $db,string $club,SecretBox $box,string $path):bool
    {
        if(!str_starts_with($path,'/meta-webhook/'))return false;
        header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
        if(!preg_match('~\A/meta-webhook/([a-f0-9]{32})\z~D',$path,$match)){http_response_code(404);return true;}
        $method=$_SERVER['REQUEST_METHOD']??'GET';$receiver=new MetaWebhookInbox($db,$box);
        if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');return true;}
        try{
            if($method==='GET'){header('Content-Type: text/plain; charset=utf-8');echo$receiver->challenge($club,$match[1],$_GET);return true;}
            if(isset($_SERVER['CONTENT_LENGTH'])&&(!ctype_digit((string)$_SERVER['CONTENT_LENGTH'])||(int)$_SERVER['CONTENT_LENGTH']>MetaWebhookInbox::MAX_BYTES)){http_response_code(413);return true;}
            $body=file_get_contents('php://input',false,null,0,MetaWebhookInbox::MAX_BYTES+1);if(!is_string($body)||strlen($body)>MetaWebhookInbox::MAX_BYTES){http_response_code(413);return true;}
            $signature=$_SERVER['HTTP_X_HUB_SIGNATURE_256']??'';if(!is_string($signature))throw new \DomainException('Nicht angenommen.');
            $accepted=$receiver->receive($club,$match[1],$body,$signature);header('Content-Type: application/json; charset=utf-8');echo json_encode(['accepted'=>$accepted],JSON_THROW_ON_ERROR);return true;
        }catch(\DomainException|\InvalidArgumentException|\JsonException){http_response_code(403);header('Content-Type: application/json; charset=utf-8');echo'{"error":"Eingang nicht angenommen."}';return true;}
        catch(\Throwable){http_response_code(503);header('Retry-After: 60');header('Content-Type: application/json; charset=utf-8');echo'{"error":"Empfang vorübergehend nicht verfügbar."}';return true;}
    }
}
