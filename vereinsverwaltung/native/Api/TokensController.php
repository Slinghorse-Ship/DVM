<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
use Kulturhus\Native\Language\{ApiTokenMessages,Catalog};
final class TokensController
{
    public static function serve(\PDO $db,#[\SensitiveParameter]string $session,string $csrf,bool $workspace,string $view,string $tab,string $accountUrl='/account'):void
    {
        header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
        $method=$_SERVER['REQUEST_METHOD']??'GET';$pending=null;$issued=null;$error=null;$code=200;$draft=$method==='POST'?TokenForm::capture($_POST):[];$appearanceAttributes='';$locale='de';$messages=ApiTokenMessages::messages($locale);$phase='access';$acceptedCsrf=false;$action=null;
        try{
            $context=Factory::session($db,$session);$catalog=new Catalog($db,$context->access);$locale=$catalog->locale();$messages=$catalog->messages($locale);$appearanceAttributes=\Kulturhus\Native\Appearance\Chrome::attributes($db,$context->access);
            if(!$workspace||$view!=='settings'||$tab!=='api')throw Problem::forbidden();
            if(!in_array($method,['GET','HEAD','POST'],true)){header('Allow: GET, HEAD, POST');throw new Problem('method_not_allowed',405,'Diese Anfrage ist nicht erlaubt.');}
            if($method==='POST'){
                if($csrf===''||!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf']))throw new Problem('csrf_required',403,'Formular abgelaufen. Bitte neu laden.');
                $acceptedCsrf=true;$phase='validation';TokensView::page($_GET);$action=Input::text($_POST,'action',40);$tokens=Factory::tokens($db);
                if($action==='api-token-create'){
                    Input::keys($_POST,['action','csrf','request_id','label','days','password','scopes']);$days=Input::text($_POST,'days',2);if(!in_array($days,['7','30','90'],true))throw Problem::invalid();$scopes=Scopes::validate($_POST['scopes']??[]);
                    $request=Input::text($_POST,'request_id',32);$label=Input::text($_POST,'label',100);$password=Input::text($_POST,'password',4096);$phase='preparing';
                    $prepared=$tokens->prepare($session,$request,$label,$scopes,(int)$days,$password);$phase='prepared';
                    if($prepared['needs_factor'])$pending=$prepared;
                    else{$phase='issuing';$issued=$tokens->issue($session,$prepared['id']);}
                }elseif($action==='api-token-confirm'){
                    Input::keys($_POST,['action','csrf','request_id','factor']);$request=Input::text($_POST,'request_id',32);$phase='confirming';$issued=$tokens->issue($session,$request,Input::text($_POST,'factor',80));
                }elseif($action==='api-token-revoke'){
                    Input::keys($_POST,['action','csrf','token_id']);$phase='revoking';$tokens->revoke($session,Input::text($_POST,'token_id',32));header('Location: /vereinsverwaltung/?view=settings&tab=api',true,303);return;
                }else throw Problem::invalid();
            }
        }catch(\Throwable $exception){
            $code=$exception instanceof Problem?$exception->status:($exception instanceof \DomainException?403:503);
            $error=$exception instanceof Problem?$exception->getMessage():($exception instanceof \DomainException?'Anmeldung oder Sicherheitscode prüfen. Der API-Zugang wurde nicht bestätigt.':'Ergebnis nicht bestätigt. API-Zugänge prüfen und einen unbekannten Zugang widerrufen.');
            if($exception instanceof Problem&&$exception->key==='invalid_request')$error=match($action){'api-token-create'=>'Bitte Bezeichnung, Gültigkeit und Freigaben prüfen.','api-token-confirm'=>'Sicherheitscode und ursprünglichen Vorgang prüfen.','api-token-revoke'=>'Gewählten API-Zugang prüfen.',default=>'Anfrage nicht verwendbar. Aktuelle API-Zugänge öffnen.'};
            if($exception instanceof \DomainException&&$exception->getMessage()==='Zu viele Versuche. Bitte später erneut versuchen.')$error=$exception->getMessage();
            if($draft!==[])$draft['retry_allowed']=$acceptedCsrf&&$action==='api-token-create'&&(($phase==='validation'&&$exception instanceof Problem&&$code===422)||($phase==='preparing'&&$exception instanceof \DomainException));
            if($acceptedCsrf&&$action==='api-token-confirm'&&$phase==='confirming'&&$code!==503&&is_string($_POST['request_id']??null)){
                try{$state=Factory::tokens($db)->requestState($session,$_POST['request_id']);if($state['needs_factor'])$pending=$state;}catch(\Throwable){}
            }
        }
        if($method==='HEAD'){
            if($code===200)try{TokensView::render($db,$session,$csrf,array_intersect_key($_GET,['api_page'=>true]));}catch(\Throwable $exception){$code=$exception instanceof Problem?$exception->status:($exception instanceof \DomainException?403:503);}
            http_response_code($code);return;
        }
        try{$html=TokensView::render($db,$session,$csrf,array_intersect_key($_GET,['api_page'=>true]),$issued,$pending,$error,$draft,$accountUrl);}
        catch(\Throwable $exception){$renderCode=$exception instanceof Problem?$exception->status:($exception instanceof \DomainException?403:503);if($code===200)$code=$renderCode;$html=TokensView::unavailable($code===$renderCode&&$exception instanceof Problem?$exception->getMessage():($error??'API-Zugänge sind derzeit nicht verfügbar.'),$_GET,$messages,$accountUrl,$draft);}
        http_response_code($code);echo TokensView::document($html,$locale,$messages,$appearanceAttributes);
    }
}
