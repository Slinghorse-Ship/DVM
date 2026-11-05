<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Language\{Catalog,SystemMessages};

/** Shared manager and standalone preparation, with an explicitly supplied server root. */
final class ManagerEntry
{
    private static function status(\Throwable $failure,?Controller $controller=null):int
    {
        if($failure instanceof OperationUnconfirmed)return 503;
        if($failure instanceof Conflict)return 409;
        if($failure instanceof RequestRejected)return $failure->getCode();
        if($failure instanceof \DomainException){if($controller!==null)try{$controller->authorize();}catch(RequestRejected){return 403;}catch(\Throwable){return 503;}return 422;}
        return 503;
    }
    public static function render(?array $state,array $query):string
    {
        $messages=$state['messages']??[];$locale=$state['locale']??'de';$draft=$state['draft']??[];$message=is_string($state['error']??null)&&$state['error']!==''?$state['error']:'Systemverwaltung nicht verfügbar.';
        if(($state['controller']??null)instanceof Controller){
            try{$state['controller']->authorize();}catch(\Throwable $failure){http_response_code(self::status($failure));return View::unavailable('Systemverwaltung nicht verfügbar.',$query,$draft,$messages,$locale);}
            try{SystemForm::query($query);}catch(\DomainException $failure){if(http_response_code()<400){http_response_code(422);$message=$failure->getMessage();}return View::unavailable($message,$query,$draft,$messages,$locale);}
            try{return $state['controller']->render($query,$state['notice'],$state['error'],$draft,$messages,$locale);}
            catch(\Throwable $failure){$status=$failure instanceof RequestRejected?$failure->getCode():503;if(http_response_code()<400||$status===403||$status===503)http_response_code($status);$message=$status===403?'Systemverwaltung nicht verfügbar.':'Systemverwaltung derzeit nicht verfügbar. Bitte erneut öffnen.';}
        }
        return View::unavailable($message,$query,$draft,$messages,$locale);
    }
    public static function prepare(\PDO $db,Access $access,string $mediaRoot,string $csrf,bool $workspaceAllowed,string $view,string $tab,array $post,?array $query=null,?string $privateRoot=null):?array
    {
        $route=$view==='settings'&&$tab==='system';$action=is_string($post['action']??null)?$post['action']:'';
        if(!$route&&!str_starts_with($action,'system-'))return null;
        $method=$_SERVER['REQUEST_METHOD']??'GET';$query??=$_GET;
        $state=['controller'=>null,'notice'=>'','error'=>'','draft'=>$method==='POST'?SystemForm::capture($post):[],'messages'=>SystemMessages::messages('de'),'locale'=>'de'];header('Cache-Control: private, no-store');
        try{$catalog=new Catalog($db,$access);$state['locale']=$catalog->locale();$state['messages']=$catalog->messages($state['locale']);}catch(\Throwable){}
        if(!$workspaceAllowed||!$route){http_response_code(403);$state['error']='Systemverwaltung nicht verfügbar.';return $state;}
        if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');$state['error']='Diese Systemanfrage wird nicht unterstützt.';return $state;}
        try{$controller=$privateRoot!==null?Factory::controller($db,$access,$privateRoot,$csrf):Factory::manager($db,$access,$mediaRoot,$csrf);$controller->authorize();$state['controller']=$controller;}
        catch(\Throwable $failure){http_response_code($failure instanceof RequestRejected?403:503);$state['error']=$failure instanceof RequestRejected?'Systemverwaltung nicht verfügbar.':'Systemverwaltung derzeit nicht verfügbar. Bitte erneut öffnen.';return $state;}
        try{if($method==='POST')$state['notice']=$controller->action($post,$query);else SystemForm::query($query);}
        catch(\Throwable $failure){$status=self::status($failure,$controller);http_response_code($status);$state['error']=$status===503?($failure instanceof OperationUnconfirmed?$failure->getMessage():'Systemverwaltung derzeit nicht verfügbar. Bitte erneut öffnen.'):$failure->getMessage();}
        return $state;
    }
}
