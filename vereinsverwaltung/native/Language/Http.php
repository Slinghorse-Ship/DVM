<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
use Kulturhus\Native\Shared\Access;
/** Session, workspace and original CSRF are supplied by the authenticated host. */
final class Http
{
    public static function serve(\PDO $db,Access $access,string $csrf,bool $workspace,string $view,string $tab):void
    {
        header('Cache-Control: no-store');$method=$_SERVER['REQUEST_METHOD']??'GET';$json=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')||($_GET['language_json']??null)==='1';$code=422;
        try{
            if(!$workspace||$view!=='settings'||$tab!=='languages'){$code=403;throw new \DomainException('Sprachverwaltung nicht freigegeben.');}
            if(!in_array($method,['GET','HEAD','POST'],true)){$code=405;header('Allow: GET, HEAD, POST');throw new \DomainException('Diese Anfrage ist nicht erlaubt.');}
            if($method==='POST'&&(!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf']))){$code=403;throw new \DomainException('Formular abgelaufen. Bitte neu laden.');}
            $input=$method==='POST'?$_POST:$_GET;
            if(in_array($input['mode']??null,['settings','rights','pack'],true)||in_array($input['action']??null,['language-settings','language-pack','language-rights'],true)){
                try{$access->require('website','manage');}catch(\DomainException $denied){$code=403;throw$denied;}
            }
            $result=(new Controller($db,$access))->handle($method,$input,$csrf);
            if($method==='POST'&&!$json){$query=View::recoveryQuery($input);$_SESSION['notice']=WorkspaceMessages::text('Sprachstand gespeichert.',$result['state']['messages']);header('Location: /vereinsverwaltung/?'.http_build_query($query),true,303);return;}
            if($json){header('Content-Type: application/json; charset=utf-8');if($method!=='HEAD')echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
        }catch(\Throwable $error){
            try{$access->require('website','read');}catch(\Throwable){$code=403;}http_response_code($code);$message=$error instanceof \DomainException||$error instanceof \InvalidArgumentException?$error->getMessage():'Sprachstand nicht verfügbar. Bitte neu laden.';
            $messages=[];$locale='de';try{$catalog=new Catalog($db,$access);$locale=$catalog->locale();$messages=$catalog->messages($locale);}catch(\Throwable){/* Keep the original rejection if its catalogue is unavailable. */}
            if($json){header('Content-Type: application/json; charset=utf-8');if($method!=='HEAD')echo json_encode(['error'=>WorkspaceMessages::text($message,$messages)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return;}
            header('Content-Type: text/html; charset=utf-8');
            if($method!=='HEAD')echo View::failure($message,$messages,$method==='POST'?$_POST:$_GET,true,$locale,$method==='POST');
        }
    }
}
