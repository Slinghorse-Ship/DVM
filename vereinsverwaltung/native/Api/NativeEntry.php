<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;

/** Standalone editor adapter. All owner/storage/session arguments come from its server runtime. */
final class NativeEntry
{
    public static function serve(\PDO $db,string $fixedManagerClub,string $origin,string $mediaRoot,#[\SensitiveParameter]?string $session,string $csrf):bool
    {
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);if(!is_string($path))return false;
        if(\Kulturhus\Native\Integration\ApiRoutes::matches($path)){
            if(!array_key_exists('HTTP_AUTHORIZATION',$_SERVER)&&array_key_exists('REDIRECT_HTTP_AUTHORIZATION',$_SERVER))$_SERVER['HTTP_AUTHORIZATION']=$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            Http::serve($db,$fixedManagerClub,$origin,$mediaRoot,dirname($mediaRoot).'/website-documents',$session,$csrf);return true;
        }
        if(rtrim($path,'/')!=='/vereinsverwaltung'||($_GET['view']??null)!=='settings'||($_GET['tab']??null)!=='api')return false;
        header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
        if(!$session){header('Location: /login',true,303);return true;}
        $locale='de';$messages=\Kulturhus\Native\Language\ApiTokenMessages::messages($locale);
        try{$context=Factory::session($db,$session);$catalog=new \Kulturhus\Native\Language\Catalog($db,$context->access);$locale=$catalog->locale();$messages=$catalog->messages($locale);if($context->club()!==$fixedManagerClub)throw Problem::forbidden();}
        catch(\Throwable $problem){if($problem instanceof Problem&&$problem->status===401)header('Location: /login',true,303);else{http_response_code($problem instanceof Problem?$problem->status:503);if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo TokensView::document(TokensView::unavailable($problem instanceof Problem?$problem->getMessage():'API-Zugänge sind derzeit nicht verfügbar.',$_GET,$messages),$locale,$messages);}return true;}
        TokensController::serve($db,$session,$csrf,true,'settings','api','/account');return true;
    }
}
