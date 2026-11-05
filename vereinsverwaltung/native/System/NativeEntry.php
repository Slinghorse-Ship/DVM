<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Language\{Catalog,SystemMessages};

/** Server-owned native entry; shares all action and original-input handling with the manager. */
final class NativeEntry
{
    public static function serve(\PDO $db,string $club,string $privateRoot,#[\SensitiveParameter]?string $session,string $csrf):bool
    {
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);if(rtrim((string)$path,'/')!=='/vereinsverwaltung'||($_GET['view']??null)!=='settings'||($_GET['tab']??null)!=='system')return false;
        header('Cache-Control: private, no-store');header('Content-Type: text/html; charset=utf-8');$method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!$session){header('Location: /login',true,303);return true;}
        $appearanceAttributes='';$locale='de';$messages=SystemMessages::messages($locale);
        try{$context=\Kulturhus\Native\Api\Factory::session($db,$session);$catalog=new Catalog($db,$context->access);$locale=$catalog->locale();$messages=$catalog->messages($locale);if($context->club()!==$club)throw new RequestRejected('Systemverwaltung nicht verfügbar.',403);$appearanceAttributes=\Kulturhus\Native\Appearance\Chrome::attributes($db,$context->access);}
        catch(\Throwable $failure){$code=$failure instanceof RequestRejected?403:($failure instanceof \Kulturhus\Native\Api\Problem?$failure->status:503);if($code===401)header('Location: /login',true,303);else{http_response_code($code);if($method!=='HEAD')echo View::document(View::unavailable('Systemverwaltung nicht verfügbar.',$_GET,$method==='POST'?SystemForm::capture($_POST):[],$messages,$locale),$messages,$locale);}return true;}
        $state=ManagerEntry::prepare($db,$context->access,$privateRoot.'/media',$csrf,true,'settings','system',$_POST,$_GET,$privateRoot);$content=ManagerEntry::render($state,$_GET);
        if($method!=='HEAD')echo View::document($content,$state['messages'],$state['locale'],$appearanceAttributes);return true;
    }
}
